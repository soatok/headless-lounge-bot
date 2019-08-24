<?php
require __DIR__ . '/vendor/autoload.php';
define('APP_ROOT', __DIR__);

// Instantiate the app
$settings = require __DIR__ . '/src/settings.php';
$app = new \Slim\App($settings);

// Set up dependencies
$dependencies = require __DIR__ . '/src/dependencies.php';
$dependencies($app);

/** @var \Slim\Container $container */
$container = $app->getContainer();

/** @var \ParagonIE\EasyDB\EasyDB $db */
$db = $container['db'];
