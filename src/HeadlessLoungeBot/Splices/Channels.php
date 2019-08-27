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
        $user = $this->db->cell(
            "SELECT userid FROM headless_users WHERE telegram_user = ?",
            $telegramUserId
        );
        if (!$user) {
            return [];
        }
        return $this->getExclusiveAllowedChannels($user);
    }

    /**
     * @param int $userId
     * @return array
     */
    public function getExclusiveAllowedChannels(int $userId): array
    {
        $list = [];
        foreach ($this->db->run(
            "SELECT 
                 c.*, u.telegram_user, u.patreon_user, u.twitch_user
             FROM headless_channels c
             JOIN headless_users u on c.channel_user_id = u.userid
             WHERE (c.twitch_sub_only AND (
                 (SELECT count(*)
                  FROM headless_user_service_cache tc
                  WHERE tc.service = 'Twitch'
                    AND tc.serviceid = u.telegram_user
                    AND tc.cachedata LIKE '%\"user_id\":{$userId},%'
                 ) > 0
               )
             ) /*OR (c.patreon_supporters_only AND (
                 (SELECT count(*)
                  FROM headless_user_service_cache pc
                  WHERE pc.service = 'Patreon'
                    AND pc.serviceid = u.patreon_user
                    AND pc.cachedata LIKE '%\"user_id\":{$userId},%'
                 ) > 0
               )
             )*/"
        ) as $li) {
            $list []= $li['telegram_chat_id'];
        }
        return $list;
    }
}
