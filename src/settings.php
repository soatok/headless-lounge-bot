<?php
declare(strict_types=1);
use ParagonIE\HiddenString\HiddenString;

$token = '';
if (file_exists(APP_ROOT . '/local/telegram-token.php')) {
    $token = include APP_ROOT . '/local/telegram-token.php';
}
$default = [
    'settings' => [
        'displayErrorDetails' => true, // set to false in production
        'addContentLengthHeader' => false, // Allow the web server to send the content-length header

        'telegram' => new HiddenString($token),

        'database' => [
            'dsn' => 'pgsql:host=localhost;dbname=headlesslounge',
            'username' => 'soatok',
            'password' => 'soatok',
            'options' => []
        ],

        // Monolog settings
        'logger' => [
            'name' => 'slim-app',
            'path' => isset($_ENV['docker']) ? 'php://stdout' : __DIR__ . '/../logs/app.log',
            'level' => \Monolog\Logger::DEBUG,
        ],
    ],
];

if (file_exists(APP_ROOT . '/local/settings.php')) {
    $local = include APP_ROOT . '/local/settings.php';
    return $local + $default;
}

return $default;
