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

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Github\Client;

/**
 * @author Richard Fullmer <richard.fullmer@opensoftdev.com>
 */
class RepoFactory
{
    private $gitWorkingDir;

    private $fs;

    private $twig;

    private $mailer;

    private $cacheFile;

    private $configuration;

    /**
     * @param array $configuration
     * @param \Swift_Mailer $mailer
     * @param \Symfony\Component\Filesystem\Filesystem $fs
     * @param \Twig_Environment $twig
     * @param $gitWorkingDir
     * @param $cacheFile
     */
    public function __construct(array $configuration, \Swift_Mailer $mailer,  Filesystem $fs, \Twig_Environment $twig, $gitWorkingDir, $cacheFile)
    {
        $this->configuration = $configuration;
        $this->mailer = $mailer;
        $this->fs = $fs;
        $this->twig = $twig;
        $this->gitWorkingDir = $gitWorkingDir;
        $this->cacheFile = $cacheFile;
    }

    public function getConfiguration()
    {
        return $this->configuration;
    }

    /**
     * @param string $username
     * @param string $repo
     */
    public function factory(Client $client, $username, $repo)
    {
        // if checkout does not exist
        $targetWorkingDirectory = sprintf($this->gitWorkingDir . '/%s/%s', $username, $repo);
        if (!$this->fs->exists($targetWorkingDirectory)) {
            // clone project
            $process = new Process(sprintf('git clone git@github.com:%s/%s %s/%s', $username, $repo, $username, $repo), $this->gitWorkingDir);
            $process->setTimeout(3600);
            $process->run();

            // add easy fetch mode
            $process = new Process('git config --add remote.origin.fetch "+refs/pull/*/head:refs/remotes/origin/pr/*"', $targetWorkingDirectory);
            $process->run();

            // start us on a known branch name based on where head's pointing
            $process = new Process('git checkout -b rdfbot', $targetWorkingDirectory);
            $process->run();
        }

        return new RepoManager($this->configuration, $client, $this->mailer, $this->twig, $this->fs, $username, $repo, $targetWorkingDirectory, $this->cacheFile);
    }
}
