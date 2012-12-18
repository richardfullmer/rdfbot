<?php
/*
 * This file is part of rdfbot.
 *
 * Copyright (c) 2012 Opensoft (http://opensoftdev.com)
 *
 * The unauthorized use of this code outside the boundaries of
 * Opensoft is prohibited.
 */

use Silex\Application;
use Silex\Provider\TwigServiceProvider;
use Silex\Provider\SwiftmailerServiceProvider;
use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Filesystem\Filesystem;
use RDF\Bot\RepoFactory;

$app = new Application();
$configuration = Yaml::parse(__DIR__ . '/config/config.yml');

// Swiftmailer
$app['swiftmailer.options'] = array('host' => $configuration['parameters']['mailer_host']);
$app->register(new SwiftmailerServiceProvider());

// Twig
$app->register(new TwigServiceProvider(), array(
    'twig.path' => array(__DIR__ . '/templates'),
    'twig.options' => array('cache' => __DIR__ . '/cache')
));

// Parameters
$app['config'] = $configuration;
$app['git_working_dir'] = __DIR__ . '/git_working_dir';
$app['yaml_cache_file'] = __DIR__ . '/cache.yml';

// Other Services
$app['filesystem'] = $app->share(function($c) {
    return new Filesystem();
});
$app['repo_factory'] = $app->share(function($c) {
    return new RepoFactory(
        $c['config'],
        $c['mailer'],
        $c['filesystem'],
        $c['twig'],
        $c['git_working_dir'],
        $c['yaml_cache_file'],
        $c['config']['parameters']['host']
    );
});
$app['command.check_command'] = $app->share(function($c) {
    return new \RDF\Bot\Command\CheckCommand($c['repo_factory']);
});


return $app;
