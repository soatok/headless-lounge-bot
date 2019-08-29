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
use Soatok\DholeCrypto\Exceptions\CryptoException;
use Soatok\DholeCrypto\Key\SymmetricKey;
use Soatok\DholeCrypto\Symmetric;

/**
 * Class Twitch
 * @package Soatok\HeadlessLoungeBot
 */
class Twitch
{
    /** @var array $cache */
    protected static $cacheSubs = [];

    /** @var string $clientId */
    protected $clientId;

    /** @var HiddenString $clientSecret */
    private $clientSecret;

    /** @var EasyDB $db */
    protected $db;

    /** @var int|null $forChannel */
    protected $forChannel;

    /** @var Client $http */
    protected $http;

    /** @var SymmetricKey $key */
    protected $key;

    /**
     * Telegram constructor.
     * @param Container $c
     * @param Client|null $http
     * @throws CertaintyException
     * @throws \SodiumException
     */
    public function __construct(Container $c, ?Client $http = null)
    {
        /** @var array $twitch */
        $twitch = $c['settings']['twitch'];

        $this->clientId = $twitch['client-id'] ?? '';
        $this->clientSecret = new HiddenString($twitch['client-secret'] ?? '');

        if (!$http) {
            $http = new Client([
                'verify' =>
                    (new RemoteFetch(APP_ROOT . '/local/'))
                        ->getLatestBundle()
            ]);
        }
        $this->db = $c['db'];
        $this->http = $http;
        $this->key = $c['settings']['encryption-key'];
    }

    /**
     * @param string $username
     * @return self
     * @throws \Exception
     */
    public function forTwitchUser(string $username): self
    {
        return $this->forChannel($this->getBroadcasterId($username));
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
     * @param string $username
     * @return int
     * @throws \Exception
     */
    public function getBroadcasterId(string $username): int
    {
        $response = $this->parseJson(
            $this->http->get(
                'https://api.twitch.tv/helix/users?login=' . $username,
                $this->getRequestOptions()
            )
        );
        $id = $response['data'][0]['id'] ?? null;
        if (!$id) {
            throw new \Exception('Invalid API response');
        }
        return (int) $id;
    }

    /**
     * @return bool
     */
    public function clearCache(): bool
    {
        if (!$this->forChannel) {
            return false;
        }
        if (isset(self::$cacheSubs[$this->forChannel])) {
            unset(self::$cacheSubs[$this->forChannel]);
        }
        $this->db->beginTransaction();
        $this->db->delete(
            'headless_user_service_cache',
            [
                'service' => 'Twitch',
                'serviceid' => $this->forChannel,
            ]
        );
        return $this->db->commit();
    }

    /**
     * @return array
     * @throws CryptoException
     * @throws \Exception
     */
    public function getSubscribers(): array
    {
        if (!$this->forChannel) {
            return [];
        }
        // Was it cached this request already?
        if (empty(self::$cacheSubs[$this->forChannel])) {
            $user_id = $this->db->cell(
                "SELECT userid FROM headless_users_oauth 
                 WHERE service = 'Twitch' AND serviceid = ?",
                 $this->forChannel
            );
            if (empty($user_id)) {
                return [];
            }

            $cached = $this->db->row(
                "SELECT * FROM headless_user_service_cache
             WHERE service = 'Twitch' AND serviceid = ?",
                $this->forChannel
            );

            // Check cache:
            if (!empty($cached)) {
                $cutoff = (new \DateTime($cached['modified']))
                    ->add(new \DateInterval('PT01H'));
                $now = new \DateTime();
                if ($cutoff > $now) {
                    self::$cacheSubs[$this->forChannel] = (array) json_decode(
                        $cached['cachedata'],
                        true
                    );
                    return self::$cacheSubs[$this->forChannel];
                }
                // Expired:
                $this->db->delete(
                    'headless_user_service_cache',
                    ['cacheid' => $cached['cacheid']]
                );
                $cached = [];
            }

            $subs = [];
            foreach ($this->getSubscribersForBroadcaster($this->forChannel) as $sub) {
                $subs[] = [
                    'user_id' => $sub['user_id'],
                    'user_name' => $sub['user_name'],
                    'is_gift' => $sub['is_gift'],
                    'tier' => $sub['tier'] / 1000
                ];
            }

            $this->db->insert(
                'headless_user_service_cache',
                [
                    'service' => 'Twitch',
                    'serviceid' => $this->forChannel,
                    'userid' => $user_id,
                    'cachedata' => json_encode($subs)
                ]
            );

            self::$cacheSubs[$this->forChannel] = $subs;
        }
        return self::$cacheSubs[$this->forChannel];
    }

    /**
     * @param string $username
     * @return array
     * @throws \Exception
     */
    public function getSubscribersForUser(string $username): array
    {
        return $this->getSubscribersForBroadcaster(
            $this->getBroadcasterId($username)
        );
    }

    /**
     * @param int $id
     * @return array
     * @throws \Soatok\DholeCrypto\Exceptions\CryptoException
     * @throws \SodiumException
     */
    public function getSubscribersForBroadcaster(int $id): array
    {
        $response = $this->parseJson(
            $this->http->get(
                'https://api.twitch.tv/helix/subscriptions?' . http_build_query([
                    'broadcaster_id' => $id
                ]),
                $this->getRequestOptions()
            )
        );
        if (empty($response['data'])) {
            return [];
        }
        return $response['data'];
    }

    /**
     * @return bool
     * @throws \Soatok\DholeCrypto\Exceptions\CryptoException
     * @throws \SodiumException
     */
    public function rotateTokens(): bool
    {
        if (empty($this->forChannel)) {
            return false;
        }
        $row = $this->db->row(
            "SELECT * FROM headless_users_oauth WHERE service = 'Twitch' AND serviceid = ?",
            $this->forChannel
        );
        $response = $this->parseJson(
            $this->http->post('https://id.twitch.tv/oauth2/token', [
                'form_params' => [
                    'grant_type' => 'refresh_token',
                    'refresh_token' => Symmetric::decrypt($row['refresh_token'], $this->key),
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret->getString(),
                    'scope' => $row['scope']
                ]
            ])
        );

        $this->db->beginTransaction();
        $this->db->update('headless_users_oauth', [
            'refresh_token' => Symmetric::encrypt($response['refresh_token'], $this->key),
            'access_token' => Symmetric::encrypt($response['access_token'], $this->key)
        ], [
            'oauthid' => $row['oauthid']
        ]);
        return $this->db->commit();
    }

    /**
     * @return array
     * @throws \Soatok\DholeCrypto\Exceptions\CryptoException
     * @throws \SodiumException
     */
    public function getRequestOptions(): array
    {
        if (empty($this->forChannel)) {
            return [];
        }
        $row = $this->db->row(
            "SELECT * FROM headless_users_oauth WHERE service = 'Twitch' AND serviceid = ?",
            $this->forChannel
        );
        if (empty($row)) {
            return [];
        }
        return [
            'headers' => [
                'Authorization' => 'Bearer ' .
                    Symmetric::decrypt($row['access_token'], $this->key)
            ]
        ];
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
