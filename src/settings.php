<?php
declare(strict_types=1);
use ParagonIE\HiddenString\HiddenString;
use Soatok\DholeCrypto\Keyring;

$telegram = '';
if (file_exists(APP_ROOT . '/local/telegram-token.php')) {
    $telegram = include APP_ROOT . '/local/telegram-token.php';
}
$botUsername = '';
if (file_exists(APP_ROOT . '/local/telegram-username.php')) {
    $botUsername = include APP_ROOT . '/local/telegram-username.php';
}
$botUserId = '';
if (file_exists(APP_ROOT . '/local/telegram-user-id.php')) {
    $botUserId = include APP_ROOT . '/local/telegram-user-id.php';
}
$twitch = [
    'client-id' => '',
    'client-secret' => ''
];
if (file_exists(APP_ROOT . '/local/twitch.php')) {
    $twitch = include APP_ROOT . '/local/twitch.php';
};
$patreon = [
    'client-id' => '',
    'client-secret' => ''
];
if (file_exists(APP_ROOT . '/local/patreon.php')) {
    $patreon = include APP_ROOT . '/local/patreon.php';
};

if (file_exists(APP_ROOT . '/local/key.php')) {
    $key = include APP_ROOT . '/local/key.php';
} else {
    $key = (new Keyring())->load(
        'symmetricSKuo8nJ58ytvHWGL3ooy0q_ANFk-LKoi8whQGv0gKHxRRpFf-Ayr0WUbyqhhYNcD'
    );
}

$default = [
    'settings' => [
        'displayErrorDetails' => true, // set to false in production
        'addContentLengthHeader' => false, // Allow the web server to send the content-length header

        'base-url' => 'https://headless.soatok.com',

            'encryption-key' => $key,

        'database' => [
            'dsn' => 'pgsql:host=localhost;dbname=headlesslounge',
            'username' => 'soatok',
            'password' => 'soatok',
            'options' => []
        ],

        'telegram' => new HiddenString($telegram),
        'tg-bot-username' => $botUsername,
        'tg-bot-user-id' => $botUserId,
        'patreon' => $patreon,
        'twitch' => $twitch,
    ],
];

if (file_exists(APP_ROOT . '/local/settings.php')) {
    $local = include APP_ROOT . '/local/settings.php';
    return $local + $default;
}

return $default;
