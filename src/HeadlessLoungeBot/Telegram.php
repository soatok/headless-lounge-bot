<?php
declare(strict_types=1);
namespace Soatok\HeadlessLoungeBot;

use GuzzleHttp\Client;
use ParagonIE\Certainty\Exception\CertaintyException;
use ParagonIE\Certainty\RemoteFetch;
use ParagonIE\EasyDB\EasyDB;
use ParagonIE\HiddenString\HiddenString;
use Psr\Http\Message\ResponseInterface;
use Slim\Container;

/**
 * Class Telegram
 * @package Soatok\HeadlessLoungeBot
 */
class Telegram
{
    /** @var string $botUsername */
    protected $botUsername;

    /** @var EasyDB $db */
    protected $db;

    /** @var int|null $forChannel */
    protected $forChannel;

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
        /** @var string $botName */
        $botName = $c['settings']['tg-bot-username'];
        $this->botUsername = $botName;
        $this->db = $c['db'];
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
     * @param string $url
     * @return array
     */
    public function setupWebook(string $url)
    {
        return $this->apiRequest('setWebhook', ['url' => $url]);
    }

    /**
     * @return HiddenString
     */
    public function getToken(): HiddenString
    {
        return $this->token;
    }

    /**
     * @param int|null $channel
     * @return self
     */
    public function forChannel(?int $channel = null): self
    {
        $self = clone $this;
        $self->forChannel = $channel;
        return $self;
    }

    /**
     * Process an update
     *
     * @param array $update
     * @return self
     */
    public function processUpdate(array $update): self
    {
        if (!is_dir(APP_ROOT . '/updates')) {
            mkdir(APP_ROOT . '/updates', 0777);
        }
        file_put_contents(
            APP_ROOT . '/' . time() . '.json',
            json_encode($update, JSON_PRETTY_PRINT)
        );
        return $this;
    }

    /**
     * Implements the getUpdates strategy for getting updates from Telegram.
     *
     * @return array
     */
    public function getUpdates(): array
    {
        if (is_readable(APP_ROOT . '/local/last_update_id.txt')) {
            $update_id = (int)file_get_contents(APP_ROOT . '/local/last_update_id.txt');
            $response = $this->apiRequest('getUpdates', ['offset' => $update_id]);
        } else {
            $update_id = 0;
            $response = $this->apiRequest('getUpdates');
        }
        $max_update_id = $update_id;
        foreach ($response['result'] as $row) {
            $max_update_id = max($row['update_id'], $max_update_id);
        }
        file_put_contents(APP_ROOT . '/local/last_update_id.txt', $max_update_id);
        return $response['result'];
    }

    /**
     * @param string $method
     * @param array $params
     * @return array
     */
    public function apiRequest(string $method, array $params = []): array
    {
        return $this->parseJson(
            $this->http->post(
                $this->getRequestUri($method),
                ['json' => $params]
            )
        );
    }

    /**
     * @param string $method
     * @return string
     */
    public function getRequestUri(string $method): string
    {
        return 'https://api.telegram.org/bot' . $this->token->getString() . '/' . $method;
    }

    /**
     * @param ResponseInterface $response
     * @return array
     */
    public function parseJson(ResponseInterface $response): array
    {
        $body = (string) $response->getBody()->getContents();
        return json_decode($body, true);
    }
}
