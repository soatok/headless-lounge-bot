<?php
declare(strict_types=1);
namespace Soatok\HeadlessLoungeBot\Splices;

use Soatok\AnthroKit\Splice;

/**
 * Class Users
 * @package Soatok\HeadlessLoungeBot\Table
 */
class Users extends Splice
{
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
