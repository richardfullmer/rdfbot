<?php
/*
 * This file is part of rdfbot.
 *
 * Copyright (c) 2012 Opensoft (http://opensoftdev.com)
 *
 * The unauthorized use of this code outside the boundaries of
 * Opensoft is prohibited.
 */

namespace RDF\Bot\Process;

use Symfony\Component\Process\Process;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @author Richard Fullmer <richard.fullmer@opensoftdev.com>
 */
class Runner
{

    /**
     * @var string
     */
    private $cwd;

    /**
     * @var OutputInterface
     */
    private $output;

    /**
     * @var array
     */
    private $recorder;

    /**
     * @param string $cwd
     * @param OutputInterface $output
     */
    public function __construct($cwd, OutputInterface $output = null)
    {
        $this->cwd = $cwd;
        $this->output = $output;
    }

    /**
     * @param string $cmd
     * @param string $cwd
     * @return Process
     * @throws \RuntimeException
     */
    public function run($cmd)
    {
        if (null !== $this->output) {
            $this->output->writeln(sprintf("<info>%s</info>", $cmd));
        }
        $this->recorder[] = "$ " . $cmd . "\n";
        $process = new Process($cmd, $this->cwd);
        $process->setTimeout(3600);

        $output = $this->output;
        $process->run(function($type, $buffer) use ($output) {
            if (null !== $output) {
                if ('err' == $type) {
                    $output->write($buffer);
                } else {
                    $output->write($buffer);
                }
            }
        });

        $this->recorder[] = $process->getOutput();

        if (!$process->isSuccessful()) {
            $this->recorder[] = $process->getErrorOutput();
            if (null !== $this->output) {
                $this->output->writeln(sprintf("<error>Error executing '%s': %s</error>", $cmd, $process->getErrorOutput()));
            }
        }

        return $process;
    }

    /**
     * @return array
     */
    public function getRecordedOutput()
    {
        return $this->recorder;
    }
}
