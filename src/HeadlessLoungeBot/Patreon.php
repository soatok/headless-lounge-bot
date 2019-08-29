<?php
declare(strict_types=1);
namespace Soatok\HeadlessLoungeBot;

use ParagonIE\EasyDB\EasyDB;
use ParagonIE\HiddenString\HiddenString;
use Patreon\API;
use Patreon\OAuth;
use Slim\Container;
use Soatok\DholeCrypto\Key\SymmetricKey;
use Soatok\DholeCrypto\Symmetric;

/**
 * Class Patreon
 * @package Soatok\HeadlessLoungeBot
 */
class Patreon
{
    /** @var string $clientId */
    protected $clientId;

    /** @var EasyDB $db */
    protected $db;

    /** @var HiddenString $clientSecret */
    private $clientSecret;

    /** @var SymmetricKey $encKey */
    protected $encKey;

    /** @var int $forCreator */
    protected $forCreator;

    /**
     * Patreon constructor.
     * @param Container $c
     */
    public function __construct(Container $c)
    {
        /** @var array $token */
        $token = $c['settings']['patreon'];
        $this->clientId = $token['client-id'] ?? '';
        $this->clientSecret = new HiddenString($token['client-secret'] ?? '');
        $this->db = $c['db'];
        $this->encKey = $c['settings']['encryption-key'];
    }

    /**
     * @return HiddenString|null
     * @throws \Soatok\DholeCrypto\Exceptions\CryptoException
     * @throws \SodiumException
     */
    public function getAccessToken(): ?HiddenString
    {
        if (!$this->forCreator) {
            return null;
        }

        $creds = $this->db->cell(
            "SELECT access_token FROM headless_users_oauth WHERE service = 'Patreon' AND serviceid = ?",
            $this->forCreator
        );
        if (empty($creds)) {
            return null;
        }

        return Symmetric::decrypt($creds, $this->encKey);
    }

    /**
     * @return API|null
     * @throws \Soatok\DholeCrypto\Exceptions\CryptoException
     * @throws \SodiumException
     */
    public function getAPIAdapter(): ?API
    {
        $token = $this->getAccessToken();
        if (!$token) {
            return null;
        }
        $api = new API($token->getString());
        $api->api_return_format = 'array';
        return $api;
    }

    /**
     * @return array
     * @throws \Soatok\DholeCrypto\Exceptions\CryptoException
     * @throws \SodiumException
     */
    public function getCampaigns(): array
    {
        $token = $this->getAccessToken();
        if (!$token) {
            return [];
        }
        $api = new API($token->getString());
        $api->api_return_format = 'array';
        return $api->current_user_campaigns();
    }

    /**
     * @return array
     * @throws \Patreon\Exceptions\APIException
     * @throws \Patreon\Exceptions\CurlException
     * @throws \Soatok\DholeCrypto\Exceptions\CryptoException
     * @throws \SodiumException
     */
    public function getPledges(): array
    {
        $data = $this->getPledgeData();
        if (empty($data)) {
            return [];
        }
        return $data['pledges'];
    }

    /**
     * @return array
     * @throws \Patreon\Exceptions\APIException
     * @throws \Patreon\Exceptions\CurlException
     * @throws \Soatok\DholeCrypto\Exceptions\CryptoException
     * @throws \SodiumException
     */
    public function getPledgeData(): array
    {
        $api = $this->getAPIAdapter();
        if (!$api) {
            return [];
        }
        $campaigns = $api->current_user_campaigns();
        if (empty($campaigns)) {
            return [];
        }

        $allPledges = [];
        $allTiers = [];
        foreach ($campaigns['included'] as $tier) {
            if ($tier['id'] < 1 || $tier['type'] !== 'reward') {
                continue;
            }
            $tier['attributes']['id'] = $tier['id'];
            $allTiers[] = $tier['attributes'];
        }

        foreach ($campaigns['data'] as $camp) {
            $cursor = null;
            $campaign = $camp['id'];
            do {
                $args = [
                    'include' => 'currently_entitled_tiers,user',
                    'page' => [
                        'count' => 25
                    ]
                ];
                if (!empty($cursor)) {
                    $args['page']['cursor'] = $cursor;
                }
                $response = $api->get_data('campaigns/' . $campaign . '/members', $args);
                foreach ($response['data'] as $row) {
                    if (empty($row['id'])) {
                        continue;
                    }
                    $tiers = [];
                    foreach ($row['relationships']['currently_entitled_tiers']['data'] as $tier) {
                        $tiers[] = $tier['id'];
                    }

                    $allPledges[] = [
                        'tiers' => $tiers,
                        'id' => $row['relationships']['user']['data']['id']
                    ];
                }
            } while (!empty($response['links']['next']));
        }
        return ['tiers' => $allTiers, 'pledges' => $allPledges];
    }

    /**
     * @return Patreon
     * @throws \Patreon\Exceptions\CurlException
     * @throws \Soatok\DholeCrypto\Exceptions\CryptoException
     * @throws \SodiumException
     */
    public function rotateTokens(): self
    {
        if (!$this->forCreator) {
            return $this;
        }

        $creds = $this->db->row(
            "SELECT * FROM headless_users_oauth WHERE service = 'Patreon' AND serviceid = ?",
            $this->forCreator
        );
        if (empty($creds)) {
            return $this;
        }

        $oauth = new OAuth($this->clientId, $this->clientSecret->getString());
        $refreshed = $oauth->refresh_token(
            Symmetric::decrypt($creds['refresh_token'], $this->encKey)
                ->getString()
        );
        $this->db->update(
            'headless_users_oauth',
            [
                'access_token' => Symmetric::encrypt(
                    new HiddenString(
                        $refreshed['access_token']
                    ),
                    $this->encKey
                ),
                'refresh_token' => Symmetric::encrypt(
                    new HiddenString(
                        $refreshed['refresh_token']
                    ),
                    $this->encKey
                )
            ],
            [
                'oauthid' => $creds['oauthid']
            ]
        );
        return $this;
    }

    /**
     * @param int|null $creator
     * @return self
     */
    public function forCreator(?string $creator = null): self
    {
        $self = clone $this;
        $self->forCreator = $creator;
        return $self;
    }
}
