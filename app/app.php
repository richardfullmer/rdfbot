<?php
/*
 * This file is part of rdfbot.
 *
 * Copyright (c) 2012 Opensoft (http://opensoftdev.com)
 *
 * The unauthorized use of this code outside the boundaries of
 * Opensoft is prohibited.
 */

$container = new Pimple();

$container['config'] = \Symfony\Component\Yaml\Yaml::parse(__DIR__ . '/config/config.yml');
$container['template_path'] = __DIR__ . '/templates';
$container['yaml_cache_file'] = __DIR__ . '/cache.yml';
$container['git_working_dir'] = __DIR__ . '/git_working_dir';
$container['swift.mail_from_address'] = 'mail.overnightprints.com';

$container['twig_loader_filesystem'] = $container->share(function($c) {
    return new \Twig_Loader_Filesystem($c['template_path']);
});
$container['twig_environment'] = $container->share(function($c) {
    return new \Twig_Environment($c['twig_loader_filesystem']);
});
$container['swift_smtptransport'] = $container->share(function($c) {
    return Swift_SmtpTransport::newInstance($c['swift.mail_from_address']);
});
$container['mailer'] = $container->share(function($c) {
    return \Swift_Mailer::newInstance($c['swift_smtptransport']);
});
$container['filesystem'] = $container->share(function($c) {
    return new \Symfony\Component\Filesystem\Filesystem();
});
$container['repo_factory'] = $container->share(function($c) {
    return new \RDF\Bot\RepoFactory(
        $c['config'],
        $c['mailer'],
        $c['filesystem'],
        $c['twig_environment'],
        $c['git_working_dir'],
        $c['yaml_cache_file']
    );
});
$container['command.check_command'] = $container->share(function($c) {
    return new \RDF\Bot\Command\CheckCommand($c['repo_factory']);
});


return $container;
