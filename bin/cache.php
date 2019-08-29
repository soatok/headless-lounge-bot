<?php
declare(strict_types=1);

use ParagonIE\EasyDB\EasyDB;
use Slim\App;
use Slim\Container;
use Soatok\HeadlessLoungeBot\Twitch;

require_once dirname(__DIR__) . '/autoload-cli.php';
/** @var App $app */
/** @var Container $c */
$c = $app->getContainer();
/** @var EasyDB $db */
$db = $c['db'];

/** @var Twitch $twitch */
$twitch = new Twitch($c);

foreach ($db->run(
    "SELECT twitch_user FROM headless_channels c
    JOIN headless_users hu on c.channel_user_id = hu.userid"
) as $channel) {
    $twitch = $twitch->forChannel((int) $channel['twitch_user']);
    $twitch->clearCache();
    $twitch->getSubscribers();
}
