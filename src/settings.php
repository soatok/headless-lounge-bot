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
$twitch = [
    'client-id' => '',
    'client-secret' => ''
];
if (file_exists(APP_ROOT . '/local/twitch.php')) {
    $twitch = include APP_ROOT . '/local/twitch.php';
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

        'encryption-key' => $key,

        'database' => [
            'dsn' => 'pgsql:host=localhost;dbname=headlesslounge',
            'username' => 'soatok',
            'password' => 'soatok',
            'options' => []
        ],

        'telegram' => new HiddenString($telegram),
        'tg-bot-username' => $botUsername,
        'twitch' => $twitch,
    ],
];

if (file_exists(APP_ROOT . '/local/settings.php')) {
    $local = include APP_ROOT . '/local/settings.php';
    return $local + $default;
}

return $default;
