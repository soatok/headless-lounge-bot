<?php

use ParagonIE\CSPBuilder\CSPBuilder;
use ParagonIE\EasyDB\Factory;
use Slim\{
    App,
    Container
};
use Soatok\HeadlessLoungeBot\Utility;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

return function (App $app) {
    $container = $app->getContainer();

    $container['csp'] = function (Container $c): CSPBuilder {
        if (file_exists(APP_ROOT . '/local/content_security_policy.json')) {
            return CSPBuilder::fromFile(APP_ROOT . '/local/content_security_policy.json');
        }
        return CSPBuilder::fromFile(__DIR__ . '/content_security_policy.json');
    };

    // database
    $container['db'] = function (Container $c) {
        $settings = $c->get('settings')['database'];
        return Factory::create(
            $settings['dsn'],
            $settings['username'],
            $settings['password'],
            $settings['options'] ?? []
        );
    };
    $container['database'] = $container['db'];

    $container['purifier'] = function (Container $c): HTMLPurifier {
        $config = $c->get('settings')['purifier'] ?? HTMLPurifier_Config::createDefault();
        return new HTMLPurifier($config);
    };

    $container['twig'] = function (Container $c): Environment {
        static $twig = null;
        if (!$twig) {
            $settings = $c->get('settings')['twig'];
            $loader = new FilesystemLoader($settings['template_paths']);
            Utility::setContainer($c);
            $twig = Utility::terraform(new Environment($loader));
        }
        return $twig;
    };

    if (!isset($_SESSION['message_once'])) {
        $_SESSION['message_once'] = [];
    }
    if (empty($_SESSION['anti-csrf'])) {
        $_SESSION['anti-csrf'] = random_bytes(33);
    }
};