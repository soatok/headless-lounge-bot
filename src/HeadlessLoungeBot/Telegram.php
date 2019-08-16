<?php
declare(strict_types=1);
namespace Soatok\HeadlessLoungeBot;

use GuzzleHttp\Client;
use ParagonIE\Certainty\Exception\CertaintyException;
use ParagonIE\Certainty\RemoteFetch;
use ParagonIE\HiddenString\HiddenString;
use Slim\Container;

/**
 * Class Telegram
 * @package Soatok\HeadlessLoungeBot
 */
class Telegram
{
    /** @var Client $http */
    protected $http;

    /** @var HiddenString $token */
    protected $token;

    /**
     * Telegram constructor.
     * @param Container $c
     * @param Client|null $http
     * @throws CertaintyException
     * @throws \SodiumException
     */
    public function __construct(Container $c, ?Client $http = null)
    {
        /** @var HiddenString|string $token */
        $token = $c['settings']['telegram'];
        if (is_string($token)) {
            $token = new HiddenString($token);
        }
        if (!($token instanceof HiddenString)) {
            throw new \TypeError('Token must be an instance of HiddenString');
        }
        $this->token = $token;
        if (!$http) {
            $http = new Client([
                'verify' =>
                    (new RemoteFetch(APP_ROOT . '/local/'))
                        ->getLatestBundle()
            ]);
        }
        $this->http = $http;
    }

    /**
     * @param string $method
     * @return string
     */
    public function getRequestUri(string $method): string
    {
        return 'https://api.telegram.org/bot' . $this->token->getString() . '/' . $method;
    }
}
