<?php

declare(strict_types=1);

$package = dirname(__DIR__);
$autoload = $package.'/vendor/autoload.php';
if (!is_file($autoload)) {
    $autoload = is_file(dirname($package, 2).'/autoload.php')
        ? dirname($package, 2).'/autoload.php'
        : dirname($package, 2).'/vendor/autoload.php';
}
$loader = require $autoload;
$loader->addPsr4('Wizhippo\\IbexaContentScheduler\\', $package.'/src');
$loader->addPsr4('Wizhippo\\IbexaContentScheduler\\Tests\\', __DIR__);
