<?php
declare(strict_types=1);
namespace Soatok\HeadlessLoungeBot\Splices;


use Soatok\AnthroKit\Splice;

/**
 * Class Channels
 * @package Soatok\HeadlessLoungeBot\Splices
 */
class Channels extends Splice
{
    /** @var array $patreonSupporterCache */
    protected $patreonSupporterCache = [];

    /** @var array $twitchSubCache */
    protected $twitchSubCache = [];

    /**
     * @param int $telegramUserId
     * @return array
     */
    public function getTelegramExclusiveAllowedChannels(int $telegramUserId): array
    {
        $user = $this->db->row(
            "SELECT twitch_user, patreon_user
             FROM headless_users WHERE telegram_user = ?",
            $telegramUserId
        );
        if (!$user) {
            return [];
        }
        return $this->getExclusiveAllowedChannels(
            (int) $user['twitch_user'],
            (int) $user['patreon_user']
        );
    }

    /**
     * @param int $userId
     * @param int|null $twitchUser
     * @param int|null $patreonUser
     * @return array
     */
    public function getExclusiveAllowedChannels(
        ?int $twitchUser = null,
        ?int $patreonUser = null
    ): array {
        $list = [];
        foreach ($this->db->run(
            "SELECT 
                 c.telegram_chat_id
             FROM headless_channels c
             JOIN headless_users u on c.channel_user_id = u.userid
             WHERE (c.twitch_sub_only AND (
                 (SELECT count(*)
                  FROM headless_user_service_cache tc
                  WHERE tc.service = 'Twitch'
                    AND tc.serviceid = u.telegram_user
                    AND (
                        tc.cachedata LIKE '%\"user_id\":{$twitchUser},%'
                            OR 
                        tc.cachedata LIKE '%\"user_id\":\"{$twitchUser}\",%'
                    )
                 ) > 0
               )
             ) /*OR (c.patreon_supporters_only AND (
                 (SELECT count(*)
                  FROM headless_user_service_cache pc
                  WHERE pc.service = 'Patreon'
                    AND pc.serviceid = u.patreon_user
                    AND (
                        pc.cachedata LIKE '%\"user_id\":{$patreonUser},%'
                            OR
                        pc.cachedata LIKE '%\"user_id\":\"{$patreonUser}\",%'
                    )
                 ) > 0
               )
             )*/"
        ) as $li) {
            $list []= $li['telegram_chat_id'];
        }
        return $list;
    }
}
