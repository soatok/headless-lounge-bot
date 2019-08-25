<?php
namespace Soatok\HeadlessLoungeBot\Endpoints;

use Slim\App;
use Slim\Container;
use Slim\Http\Request;
use Slim\Http\Response;

return function (App $app) {
    $container = $app->getContainer();
    $app->any('/telegram-updates', 'telegram-updates');
    $app->any('/', 'homepage');
    $app->any('', 'homepage');

    $container['homepage'] = function (Container $c) {
        return new HomePage($c);
    };
    $container['telegram-updates'] = function (Container $c) {
        return new TelegramUpdates($c);
    };
};
