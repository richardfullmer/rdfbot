<?php
/*
 * This file is part of ONP.
 *
 * Copyright (c) 2012 Opensoft (http://opensoftdev.com)
 *
 * The unauthorized use of this code outside the boundaries of
 * Opensoft is prohibited.
 */

namespace RDF\Bot\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Github\Client;
use RDF\Bot\RepoFactory;

/**
 * @author Richard Fullmer <richard.fullmer@opensoftdev.com>
 */
class CheckCommand extends Command
{

    /**
     * @var \RDF\Bot\RepoFactory
     */
    private $repoFactory;

    /**
     * @param \RDF\Bot\RepoFactory $repoFactory
     */
    public function __construct(RepoFactory $repoFactory)
    {
        parent::__construct();
        $this->repoFactory = $repoFactory;
    }


    public function configure()
    {
        $this->setName('check');
        $this->addArgument('repository', InputArgument::OPTIONAL, "The repository to test PR's for (username/repo format)");
        $this->addArgument('pull_request_id', InputArgument::OPTIONAL, "A specific PR to test");
        $this->addOption('password', 'p', InputOption::VALUE_REQUIRED, 'Github Password');
        $this->addOption('username', 'u', InputOption::VALUE_REQUIRED, 'Github Username');
        $this->addOption('force', 'f', InputOption::VALUE_NONE, 'Force the test to run, ignoring the cache');
    }

    public function execute(InputInterface $input, OutputInterface $output)
    {
        $client = new Client();
        $client->authenticate($input->getOption('username'), $input->getOption('password'), Client::AUTH_HTTP_PASSWORD);

        if ($input->getArgument('repository')) {
            list($username, $repo) = explode('/', $input->getArgument('repository'));

            $repository = $this->repoFactory->factory($client, $username, $repo);

            if ($input->getArgument('pull_request_id')) {
                $repository->testPullRequest($input->getArgument('pull_request_id'), $output, $input->getOption('force'));
            } else {
                $repository->testAllPullRequests($output);
                $repository->testBranches($output);
            }
        } else {
            $config = $this->repoFactory->getConfiguration();

            foreach ($config['repositories'] as $username => $repos) {
                foreach ($repos as $repo => $repoConfig) {
                    $repository = $this->repoFactory->factory($client, $username, $repo);
                    $repository->testAllPullRequests($output);
                    $repository->testBranches($output);
                }
            }
        }

        $output->writeln('<comment>Finished checking pull request(s)</comment>');
    }
}
