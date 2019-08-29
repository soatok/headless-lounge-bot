<?php
declare(strict_types=1);
namespace Soatok\HeadlessLoungeBot\Splices;

use ParagonIE\ConstantTime\Base32;
use Soatok\AnthroKit\Splice;
use Soatok\HeadlessLoungeBot\Exceptions\UserNotFoundException;

/**
 * Class Users
 * @package Soatok\HeadlessLoungeBot\Table
 */
class Users extends Splice
{
    /**
     * @param string $service
     * @param int $userId
     * @return array
     */
    public function getGenericIntegration(string $service, int $userId): array
    {
        $row = $this->db->row(
            "SELECT * FROM headless_users_oauth WHERE service = ? AND userid = ?",
            $service,
            $userId
        );
        if (empty($row)) {
            return [];
        }
        return [];
    }

    /**
     * @param int $userId
     * @return array
     */
    public function getPatreonIntegration(int $userId): array
    {
        return $this->getGenericIntegration('Patreon', $userId);
    }
    /**
     * @param int $userId
     * @return array
     */
    public function getTwitchIntegration(int $userId): array
    {
        return $this->getGenericIntegration('Twitch', $userId);
    }

    /**
     * @param int $telegramUser
     * @param int|null $twitchUser
     * @param int|null $patreonUser
     * @return bool
     */
    public function ensureExists(
        int $telegramUser,
        ?int $twitchUser = null,
        ?int $patreonUser = null
    ): bool {
        $this->db->beginTransaction();
        if ($this->db->exists(
            "SELECT count(*) FROM headless_users WHERE telegram_user = ?",
            $telegramUser
        )) {
            return $this->db->rollBack();
        }
        $this->db->insert('headless_users', [
            'telegram_user' => $telegramUser,
            'twitch_user' => $twitchUser,
            'patreon_user' => $patreonUser
        ]);
        return $this->db->commit();
    }
    /**
     * @param array $tokens
     * @param array $oauth
     * @return bool
     * @throws \Exception
     */
    public function linkPatreon(array $tokens, array $oauth): bool
    {
        $this->db->beginTransaction();
        try {
            $expires = (new \DateTime('now'))
                ->add(new \DateInterval('PT' . $tokens['expires_in'] . 'S'))
                ->format(\DateTime::ATOM);
        } catch (\Exception $ex) {
            $expires = date(DATE_ATOM);
        }
        $this->db->update(
            'headless_users_oauth',
            [
                'serviceid' => $tokens['account_id'],
                'refresh_token' => $tokens['refresh_token'],
                'access_token' => $tokens['access_token'],
                'access_expires' => $expires,
                'scope' => json_encode($tokens['scope'] ?? ''),
                'url_token' => Base32::encodeUpperUnpadded(random_bytes(30))
            ],
            [
                'userid' => $oauth['userid'],
                'service' => 'Patreon'
            ]
        );
        $this->db->update(
            'headless_users',
            [
                'patreon_user' => $tokens['account_id']
            ],
            [
                'userid' => $oauth['userid']
            ]
        );
        return $this->db->commit();
    }

    /**
     * @param array $tokens
     * @param array $oauth
     * @return bool
     * @throws \Exception
     */
    public function linkTwitch(array $tokens, array $oauth): bool
    {
        $this->db->beginTransaction();
        try {
            $expires = (new \DateTime('now'))
                ->add(new \DateInterval('PT' . $tokens['expires_in'] . 'S'))
                ->format(\DateTime::ATOM);
        } catch (\Exception $ex) {
            $expires = date(DATE_ATOM);
        }
        $this->db->update(
            'headless_users_oauth',
            [
                'serviceid' => $tokens['account_id'],
                'refresh_token' => $tokens['refresh_token'],
                'access_token' => $tokens['access_token'],
                'access_expires' => $expires,
                'scope' => json_encode($tokens['scope'] ?? ''),
                'url_token' => Base32::encodeUpperUnpadded(random_bytes(30))
            ],
            [
                'userid' => $oauth['userid'],
                'service' => 'Twitch'
            ]
        );
        $this->db->update(
            'headless_users',
            [
                'twitch_user' => $tokens['account_id']
            ],
            [
                'userid' => $oauth['userid']
            ]
        );
        return $this->db->commit();
    }

    /**
     * @param string $token
     * @return array
     * @throws UserNotFoundException
     */
    public function getByThirdPartyUrl(string $token): array
    {
        $oauth = $this->db->row(
            "SELECT * FROM headless_users_oauth WHERE url_token = ?",
            $token
        );
        if (empty($oauth)) {
            throw new UserNotFoundException('Invalid URL token');
        }
        $oauth['user_row'] = $this->db->row(
            "SELECT * FROM headless_users WHERE userid = ?",
            $oauth['userid']
        );
        return $oauth;
    }

    /**
     * @param int $telegramUser
     * @param int|null $twitchUser
     * @param int|null $patreonUser
     * @return bool
     */
    public function upsert(
        int $telegramUser,
        ?int $twitchUser = null,
        ?int $patreonUser = null
    ): bool {
        $this->db->beginTransaction();
        if ($this->db->exists(
            "SELECT count(*) FROM headless_users WHERE telegram_user = ?",
            $telegramUser
        )) {
            $this->db->update('headless_users', [
                'twitch_user' => $twitchUser,
                'patreon_user' => $patreonUser
            ], [
                'telegram_user' => $telegramUser
            ]);
        } else {
            $this->db->insert('headless_users', [
                'telegram_user' => $telegramUser,
                'twitch_user' => $twitchUser,
                'patreon_user' => $patreonUser
            ]);
        }
        return $this->db->commit();
    }

    /**
     * @param int $telegramUser
     * @param int $twitchUser
     * @return bool
     */
    public function tieToTwitchAccount(int $telegramUser, int $twitchUser): bool
    {
        $this->db->beginTransaction();
        $this->db->update('headless_users', [
            'twitch_user' => $twitchUser,
        ], [
            'telegram_user' => $telegramUser
        ]);
        return $this->db->commit();
    }

    /**
     * @param int $telegramUser
     * @param int $patreonUser
     * @return bool
     */
    public function tieToPatreonAccount(int $telegramUser, int $patreonUser): bool
    {
        $this->db->beginTransaction();
        $this->db->update('headless_users', [
            'patreon_user' => $patreonUser,
        ], [
            'telegram_user' => $telegramUser
        ]);
        return $this->db->commit();
    }

    /**
     * @param int $telegramUser
     * @return array
     */
    public function getByTelegramUserId(int $telegramUser): array
    {
        $row = $this->db->row(
            "SELECT * FROM headless_users WHERE telegram_user = ?",
            $telegramUser
        );
        if (!$row) {
            return [];
        }
        return $row;
    }
}
