<?php
declare(strict_types=1);
use Soatok\DholeCrypto\{
    Keyring,
    Key\SymmetricKey
};

require_once dirname(__DIR__) . '/autoload-cli.php';
$keyring = new Keyring();
$key = SymmetricKey::generate();
$stored = $keyring->save($key);

$contents = <<<EOBLOB
<?php
declare(strict_types=1);
use Soatok\DholeCrypto\Keyring;

return (new Keyring())->load('{$stored}');

EOBLOB;

file_put_contents(APP_ROOT . '/local/key.php', $contents);
