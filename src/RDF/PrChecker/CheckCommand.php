<?php
/*
 * This file is part of ONP.
 *
 * Copyright (c) 2012 Opensoft (http://opensoftdev.com)
 *
 * The unauthorized use of this code outside the boundaries of
 * Opensoft is prohibited.
 */

namespace RDF\PrChecker;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Github\Client;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/**
 * @author Richard Fullmer <richard.fullmer@opensoftdev.com>
 */
class CheckCommand extends Command
{
    public function configure()
    {
        $this->setName('check');
        $this->addOption('password', 'p', InputOption::VALUE_REQUIRED, 'Github Password');
        $this->addOption('username', 'u', InputOption::VALUE_REQUIRED, 'Github Username');
    }

    public function execute(InputInterface $input, OutputInterface $output)
    {
        $yamlCacheFile = __DIR__ . '/../../../status.yml';
        $yamlCache = file_exists($yamlCacheFile) ? Yaml::parse($yamlCacheFile) : array();
//        print_r($yamlCache);

        $client = new Client();
        $client->authenticate($input->getOption('username'), $input->getOption('password'), Client::AUTH_HTTP_PASSWORD);
        $openPullRequests = $client->api('pull_request')->all('opensoft', 'ONP');

        // switch to ONP_auto dir
        $onpDir = '/home/richardfullmer/Projects/ONP_auto';
        $workingPR = null;
        // fetch latest changes
        $this->executeProcess(sprintf('git fetch'), $onpDir, $output);

        // Loop through each PR
        foreach ($openPullRequests as $pullRequest) {
            $workingPR = $pullRequest['number'];
            $output->writeln(sprintf("<comment>=============================================================================</comment>"));
            $output->writeln(sprintf("<comment>=    Pull Request: %d</comment>", $pullRequest['number']));
            $output->writeln(sprintf("<comment>=    Created:      %s</comment>", $pullRequest['created_at']));
            $output->writeln(sprintf("<comment>=    Updated:      %s</comment>", $pullRequest['updated_at']));
            $output->writeln(sprintf("<comment>=    Title:        %s</comment>", $pullRequest['title']));
            $output->writeln(sprintf("<comment>=    From:         %s</comment>", $pullRequest['user']['login']));
            $output->writeln(sprintf("<comment>=    Description:  %s</comment>", str_replace("\n", "\n=                  ", $pullRequest['body'])));
            $output->writeln(sprintf("<comment>=    Latest Sha:   %s</comment>", $pullRequest['head']['sha']));
            $output->writeln(sprintf("<comment>=============================================================================</comment>"));

            if (!isset($yamlCache[$pullRequest['number']])) {
                $yamlCache[$pullRequest['number']] = array(
                    'number' => $pullRequest['number'],
                    'head_sha' => $pullRequest['head']['sha'],
                    'status' => 'pending'
                );
            }

            if ($yamlCache[$pullRequest['number']]['head_sha'] == $pullRequest['head']['sha'] && in_array($yamlCache[$pullRequest['number']]['status'], array('success', 'failure', 'no_tests'))) {
                $output->writeln(sprintf('<info>No work to do... PR %s</info>', $yamlCache[$pullRequest['number']]['status']));
                continue;
            }

            try {
                // fetch latest and pull it in
                $this->executeProcess(sprintf('git checkout develop'), $onpDir, $output);
                $this->executeProcess(sprintf('git checkout -b pr/%d --track origin/pr/%d', $pullRequest['number'], $pullRequest['number']), $onpDir, $output);
                $this->executeProcess(sprintf('git submodule update --init'), $onpDir, $output);

                if (!file_exists($onpDir . '/app/phpunit.xml.dist') && !file_exists($onpDir . '/behat.yml')) {
                    $output->writeln('<error>Skipping because there are no runnable tests...</error>');

                    $client->api('repos')->statuses()->create(
                        $pullRequest['head']['user']['login'],
                        $pullRequest['head']['repo']['name'],
                        $pullRequest['head']['sha'],
                        array(
                            'state' => 'error',
                            'description' => 'rdfbot - no tests found'
                        )
                    );
                    $yamlCache[$pullRequest['number']]['status'] = 'no_tests';

                    // cleanup
                    $this->cleanup($onpDir, $output);
                    continue;
                }

                // Update status to pending
                $client->api('repos')->statuses()->create(
                    $pullRequest['head']['user']['login'],
                    $pullRequest['head']['repo']['name'],
                    $pullRequest['head']['sha'],
                    array(
                        'state' => 'pending',
                        'description' => 'rdfbot - running tests'
                    )
                );

                // composer install
                if (file_exists($onpDir . '/composer.json')) {
                    $this->executeProcess(sprintf('composer -v install --dev'), $onpDir, $output);
                }

                // run unit tests
                if (file_exists($onpDir . '/vendor/bin/phpunit') && file_exists($onpDir . '/app/phpunit.xml.dist')) {
                    $process = $this->executeProcess(sprintf('php vendor/bin/phpunit -c app/'), $onpDir, $output);
                    // Make comment on github
                    if ($process->isSuccessful()) {
                        // mark head sha as passes
                        $client->api('repos')->statuses()->create(
                            $pullRequest['head']['user']['login'],
                            $pullRequest['head']['repo']['name'],
                            $pullRequest['head']['sha'],
                            array(
                                'state' => 'success',
                                'description' => 'rdfbot - All Tests Pass'
                            )
                        );
                        $yamlCache[$pullRequest['number']]['status'] = 'success';
                    } else {
                        // mark head sha as fails
                        $client->api('repos')->statuses()->create(
                            $pullRequest['head']['user']['login'],
                            $pullRequest['head']['repo']['name'],
                            $pullRequest['head']['sha'],
                            array(
                                'state' => 'failure',
                                'description' => 'rdfbot - Tests Failed'
                            )
                        );
                        $yamlCache[$pullRequest['number']]['status'] = 'failure';
                    }
                }

                // run behat tests
                if (file_exists($onpDir . '/behat.yml')) {
                    $process = $this->executeProcess(sprintf('php vendor/bin/behat'), $onpDir, $output);
                    // Make comment on github
                }


            } catch (\Exception $e) {
                $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));

                // Update status to failed
                $client->api('repos')->statuses()->create(
                    $pullRequest['head']['user']['login'],
                    $pullRequest['head']['repo']['name'],
                    $pullRequest['head']['sha'],
                    array(
                        'state' => 'error',
                        'description' => 'rdfbot - error while running tests'
                    )
                );

            }

            // cleanup
            $this->cleanup($onpDir, $output);

        }


        file_put_contents($yamlCacheFile, Yaml::dump($yamlCache));

        $output->writeln(sprintf('<comment>Finished checking %d ONP pull requests</comment>', count($openPullRequests)));
    }



    /**
     * @param string $cmd
     * @param string $cwd
     * @return Process
     * @throws \RuntimeException
     */
    private function executeProcess($cmd, $cwd, OutputInterface $output)
    {
        $output->writeln(sprintf("<info>%s</info>", $cmd));
        $process = new Process($cmd, $cwd);
        $process->setTimeout(3600);
        $process->run(function($type, $buffer) use ($output) {
            if ('err' == $type) {
                $output->write($buffer);
            } else {
                $output->write($buffer);
            }
        });
        if (!$process->isSuccessful()) {
            $output->writeln(sprintf("<error>Error executing '%s': %s</error>", $cmd, $process->getErrorOutput()));
            throw new \RuntimeException($process->getErrorOutput());
        }

        return $process;
    }

    /**
     * @param $onpDir
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     */
    private function cleanup($onpDir, OutputInterface $output)
    {
        // cleanup
        $this->executeProcess(sprintf('git checkout develop'), $onpDir, $output);
        $process = $this->executeProcess('git branch', $onpDir, $output);
        foreach (explode("\n", $process->getOutput()) as $line) {
            $line = trim(str_replace('*', '', $line));
            if (strpos($line, 'pr/') !== false) {
                $this->executeProcess(sprintf('git branch -D %s', $line), $onpDir, $output);
            }
        }

    }
}
