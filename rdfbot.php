<?php
/*
 * This file is part of ONP.
 *
 * Copyright (c) 2012 Opensoft (http://opensoftdev.com)
 *
 * The unauthorized use of this code outside the boundaries of
 * Opensoft is prohibited.
 */

require_once __DIR__ . '/vendor/autoload.php';

use Symfony\Component\Console\Application;
use RDF\Bot\RepoFactory;
use Symfony\Component\Filesystem\Filesystem;
use RDF\Bot\Command\CheckCommand;
use Symfony\Component\Yaml\Yaml;

$application = new Application();

$loader = new \Twig_Loader_Filesystem(__DIR__ . '/templates');
$twig = new \Twig_Environment($loader);
$yamlCacheFile = __DIR__ . '/cache.yml';
$configuration = Yaml::parse(__DIR__ . '/config/config.yml');

$repoFactory = new RepoFactory($configuration, new Filesystem(), $twig, __DIR__ . '/git_working_dir', $yamlCacheFile);

$application->add(new CheckCommand($repoFactory));
$application->run();
