<?php
declare(strict_types=1);

use Slim\Container;
use Soatok\HeadlessLoungeBot\Telegram;
if ($argc < 1) {
    echo 'Usage: ', PHP_EOL;
    echo 'php setup-webhook.php [subdomain.example.com/subpath]', PHP_EOL;
    exit(1);
}

require_once dirname(__DIR__) . '/autoload-cli.php';

/** @var Container $container */
$telegram = new Telegram($container);
$telegram->setupWebook(
    'https://' . $argv[1] . '/' . $telegram->getToken()->getString()
);
