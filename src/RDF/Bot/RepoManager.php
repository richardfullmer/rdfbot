<?php
/*
 * This file is part of ONP.
 *
 * Copyright (c) 2012 Opensoft (http://opensoftdev.com)
 *
 * The unauthorized use of this code outside the boundaries of
 * Opensoft is prohibited.
 */

namespace RDF\Bot;

use Github\Client;
use RDF\Bot\Process\Runner as ProcessRunner;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Filesystem\Filesystem;

/**
 * @author Richard Fullmer <richard.fullmer@opensoftdev.com>
 */
class RepoManager
{
    const STATE_PENDING = 'pending';
    const STATE_SUCCESS = 'success';
    const STATE_ERROR   = 'error';
    const STATE_FAILURE = 'failure';
    const STATE_INTERNAL_NO_TESTS = 'no_tests';

    private $username;

    private $repo;

    private $client;

    private $twig;

    private $mailer;

    private $workingDirectory;

    private $fs;

    /**
     * @param array $configuration
     * @param \Github\Client $client
     * @param \Swift_Mailer $mailer
     * @param \Twig_Environment $twig
     * @param \Symfony\Component\Filesystem\Filesystem $fs
     * @param $username
     * @param $repo
     * @param $workingDirectory
     * @param $cacheFile
     * @param $host
     */
    public function __construct(array $configuration, Client $client, \Swift_Mailer $mailer, \Twig_Environment $twig, Filesystem $fs, $username, $repo, $workingDirectory, $cacheFile, $host)
    {
        $this->configuration = $configuration;
        $this->client = $client;
        $this->mailer = $mailer;
        $this->twig = $twig;
        $this->fs = $fs;
        $this->username = $username;
        $this->repo = $repo;
        $this->workingDirectory = $workingDirectory;
        $this->cacheFile = $cacheFile;
        $this->host = $host;

    }

    public function testBranches(OutputInterface $output = null)
    {
        $branches = $this->client->api('repos')->branches($this->username, $this->repo);

        $statusImager = new \RDF\Bot\Status\ImageGenerator($this->fs, '/usr/share/fonts/truetype/ubuntu-font-family/Ubuntu-B.ttf');

        foreach ($branches as $branch) {
            $yamlCache = file_exists($this->cacheFile) ? Yaml::parse($this->cacheFile) : array();
            $runner = new ProcessRunner($this->workingDirectory, $output);

            if (!isset($yamlCache[$this->username][$this->repo][$branch['name']])) {
                $yamlCache[$this->username][$this->repo][$branch['name']] = array(
                    'head_sha' => $branch['commit']['sha'],
                    'status' => 'pending'
                );
            }
            if ($yamlCache[$this->username][$this->repo][$branch['name']]['head_sha'] == $branch['commit']['sha']
                && in_array($yamlCache[$this->username][$this->repo][$branch['name']]['status'], array(self::STATE_SUCCESS, self::STATE_FAILURE, self::STATE_INTERNAL_NO_TESTS))) {

                $output->writeln(sprintf('<info>No work to do... PR %s</info>', $yamlCache[$this->username][$this->repo][$branch['name']]['status']));
                return;
            }

            $output->writeln(sprintf("<comment>Testing %s</comment>", $branch['name']));
            try {
                // fetch latest and pull it in
                // fetch refs in case there are unknown remote PR's to the local repo
                $runner->run('git fetch');
                $this->cleanup($runner);
                $runner->run('git ls-files --other --exclude-standard | xargs rm -rf'); // cleans untracked files if there are any
                $runner->run(sprintf('git checkout -f %s', $branch['name']));
                $runner->run('git pull');

                $state = $this->runConfiguration($runner);

                $yamlCache[$this->username][$this->repo][$branch['name']]['status'] = $state;
                $yamlCache[$this->username][$this->repo][$branch['name']]['head_sha'] = $branch['commit']['sha'];

                // Generate an image
                $statusImager->generate($this->username, $this->repo, $state, $branch['commit']['sha']);
            } catch (\Exception $e) {
                $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));
            }

            // cleanup
            $this->cleanup($runner);

            file_put_contents($this->cacheFile, Yaml::dump($yamlCache));

        }
    }

    public function testAllPullRequests(OutputInterface $output = null)
    {
        $openPullRequests = $this->client->api('pull_request')->all($this->username, $this->repo);

        foreach ($openPullRequests as $pullRequest) {
            $this->testPullRequest($pullRequest, $output);
        }
    }

    public function testPullRequest($pullRequest, OutputInterface $output = null, $forceRun = false)
    {
        if (!is_array($pullRequest)) {
            $pullRequest = $this->client->api('pull_request')->show($this->username, $this->repo, $pullRequest);
        }
//        die(print_r($pullRequest));

        $yamlCache = file_exists($this->cacheFile) ? Yaml::parse($this->cacheFile) : array();

        $runner = new ProcessRunner($this->workingDirectory, $output);


        $this->writeOutputHeader($output, $pullRequest);

        if (!isset($yamlCache[$this->username][$this->repo][$pullRequest['number']])) {
            $yamlCache[$this->username][$this->repo][$pullRequest['number']] = array(
                'number' => $pullRequest['number'],
                'head_sha' => $pullRequest['head']['sha'],
                'status' => 'pending'
            );
        }

        if (!$forceRun
            && $yamlCache[$this->username][$this->repo][$pullRequest['number']]['head_sha'] == $pullRequest['head']['sha']
            && in_array($yamlCache[$this->username][$this->repo][$pullRequest['number']]['status'], array(self::STATE_SUCCESS, self::STATE_FAILURE, self::STATE_INTERNAL_NO_TESTS))) {

            $output->writeln(sprintf('<info>No work to do... PR %s</info>', $yamlCache[$this->username][$this->repo][$pullRequest['number']]['status']));
            return;
        }

        try {
            // fetch latest and pull it in
            // fetch refs in case there are unknown remote PR's to the local repo
            $runner->run('git fetch');
            $this->cleanup($runner);
            $runner->run('git ls-files --other --exclude-standard | xargs rm -rf'); // cleans untracked files if there are any
            $runner->run(sprintf('git checkout -f -b pr/%d --track origin/pr/%d', $pullRequest['number'], $pullRequest['number']));

            $this->updateRemoteProgress(self::STATE_PENDING, 'rdfbot - running build', $runner->getRecordedOutput(), $pullRequest);
            $state = $this->runConfiguration($runner);

            $yamlCache[$this->username][$this->repo][$pullRequest['number']]['status'] = $state;
            $yamlCache[$this->username][$this->repo][$pullRequest['number']]['head_sha'] = $pullRequest['head']['sha'];
            $this->updateRemoteProgress($state, 'rdfbot - ' . $state, $runner->getRecordedOutput(), $pullRequest);
        } catch (\Exception $e) {
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));
            $this->updateRemoteProgress(self::STATE_ERROR, 'rdfbot - error while running tests', $runner->getRecordedOutput(), $pullRequest);
        }

        // cleanup
        $this->cleanup($runner);

        file_put_contents($this->cacheFile, Yaml::dump($yamlCache));
    }

    private function runConfiguration(ProcessRunner $runner)
    {
        // Allow repo's to override standard config
        if (file_exists($this->workingDirectory . '/.rdfbot.yml')) {
            $config = Yaml::parse($this->workingDirectory . '/.rdfbot.yml');
            $config = $config['script'];
        } else {
            $config = $this->configuration['repositories'][$this->username][$this->repo]['scripts'];
        }

        foreach ($config as $command) {
            $process = $runner->run($command, $this->workingDirectory);

            if (!$process->isSuccessful()) {
                return self::STATE_FAILURE;
            }
        }

        return self::STATE_SUCCESS;
    }

    private function updateRemoteProgress($state, $description, $output, $pullRequest)
    {
        $url = $this->writeResults($pullRequest, $output, $state);
        // Update status to failed
        $this->client->api('repos')->statuses()->create(
            $pullRequest['head']['repo']['owner']['login'],
            $pullRequest['head']['repo']['name'],
            $pullRequest['head']['sha'],
            array(
                'state' => $state,
                'description' => $description . ' at ' . date('m/d/y G:i:s T'),
                'target_url' => $url
            )
        );

        // Send email
        if (in_array($state, array(self::STATE_SUCCESS, self::STATE_FAILURE, self::STATE_ERROR))) {
            $owner = $this->client->api('users')->show($pullRequest['user']['login']);
            $to = array('richard.fullmer@opensoftdev.com');
            if (isset($owner['email']) && !empty($owner['email'])) {
                $to[] = $owner['email'];
            }
            $message = \Swift_Message::newInstance()
                ->setSubject(sprintf('[rdfbot] %s #%d - %s', $pullRequest['base']['repo']['full_name'], $pullRequest['number'], $state))
                ->setFrom(array('richard.fullmer@opensoftdev.com' => 'rdfbot'))
                ->setTo($to)
                ->setBody(sprintf('<p>%s Pull Request %d - build %s.</p><br><a href="%s">%s</a>', $pullRequest['base']['repo']['full_name'], $pullRequest['number'], $state, $url, $url), 'text/html')
            ;
            $this->mailer->send($message);
        }
    }

    /**
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     * @param array $pullRequest
     */
    private function writeOutputHeader(OutputInterface $output, $pullRequest)
    {
        if ($output) {
            $output->writeln(sprintf("<comment>=============================================================================</comment>"));
            $output->writeln(sprintf("<comment>=    Pull Request: %d</comment>", $pullRequest['number']));
            $output->writeln(sprintf("<comment>=    Created:      %s</comment>", $pullRequest['created_at']));
            $output->writeln(sprintf("<comment>=    Updated:      %s</comment>", $pullRequest['updated_at']));
            $output->writeln(sprintf("<comment>=    Title:        %s</comment>", $pullRequest['title']));
            $output->writeln(sprintf("<comment>=    From:         %s</comment>", $pullRequest['user']['login']));
            $output->writeln(sprintf("<comment>=    Description:  %s</comment>", str_replace("\n", "\n=                  ", $pullRequest['body'])));
            $output->writeln(sprintf("<comment>=    Latest Sha:   %s</comment>", $pullRequest['head']['sha']));
            $output->writeln(sprintf("<comment>=============================================================================</comment>"));
        }
    }

    /**
     * @param ProcessRunner $processRunner
     */
    private function cleanup(ProcessRunner $processRunner)
    {
        // cleanup
        $processRunner->run('git checkout -f rdfbot');
        $process = $processRunner->run('git branch');
        foreach (explode("\n", $process->getOutput()) as $line) {
            $line = trim(str_replace('*', '', $line));
            if (strpos($line, 'pr/') !== false) {
                $processRunner->run(sprintf('git branch -D %s', $line));
            }
        }
    }

    /**
     * @param array $pullRequest
     * @param array $output
     * @param string $status
     * @return string
     */
    private function writeResults($pullRequest, $output, $status)
    {
        // write the results to the web filesystem
        $filepath = sprintf(__DIR__ . '/../../../web/%s/%s', $pullRequest['head']['repo']['owner']['login'], $pullRequest['head']['repo']['name']);
        $this->fs->mkdir($filepath);

        $template = $this->twig->render('build.html.twig', array(
            'pr' => $pullRequest,
            'output' => $output,
            'status' => $status,
        ));

        $file = $filepath . '/' . $pullRequest['head']['sha'] . '.html';
        file_put_contents($file, $template);

        return sprintf('http://%s/%s/%s/%s.html',
            $this->host,
            $pullRequest['head']['repo']['owner']['login'],
            $pullRequest['head']['repo']['name'],
            $pullRequest['head']['sha']
        );
    }
}
