<?php
namespace Soatok\HeadlessLoungeBot\Endpoints;

use ParagonIE\HiddenString\HiddenString;
use Slim\App;
use Slim\Container;
use Slim\Http\Request;
use Slim\Http\Response;

return function (App $app) {
    $container = $app->getContainer();
    /** @var HiddenString|string $token */
    $token = $container['settings']['telegram'];
    if ($token instanceof HiddenString) {
        $token = $token->getString();
    }
    $app->any('/' . $token, 'telegram-updates');
    sodium_memzero($token);
    $app->any('/authorize/{service}', 'authorize');
    $app->any('/thirdparty/{token}', 'thirdparty');
    $app->any('/', 'homepage');
    $app->any('', 'homepage');

    $container['authorize'] = function (Container $c) {
        return new Authorize($c);
    };
    $container['homepage'] = function (Container $c) {
        return new HomePage($c);
    };
    $container['telegram-updates'] = function (Container $c) {
        return new TelegramUpdates($c);
    };
    $container['thirdparty'] = function (Container $c) {
        return new ThirdParty($c);
    };
};
