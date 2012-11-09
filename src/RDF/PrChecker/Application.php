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

use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Input\InputInterface;

/**
 * @author Richard Fullmer <richard.fullmer@opensoftdev.com>
 */
class Application extends ConsoleApplication
{
    protected function getCommandName(InputInterface $input)
    {
        return 'check';
    }

    protected function getDefaultCommands()
    {
        $defaultCommands = parent::getDefaultCommands();
        $defaultCommands[] = new CheckCommand();

        return $defaultCommands;
    }
}
