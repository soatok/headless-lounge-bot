<?php
declare(strict_types=1);
namespace Soatok\HeadlessLoungeBot\TelegramTraits;

use ParagonIE\EasyDB\EasyDB;
use Soatok\HeadlessLoungeBot\Splices\Users;
use Soatok\HeadlessLoungeBot\Twitch;

/**
 * Trait NewMessageTrait
 * @package Soatok\HeadlessLoungeBot\TelegramTraits
 *
 * @property EasyDB $db
 * @property Twitch $twitch
 * @property Users $users
 *
 * @method array apiRequest(string $method, array $params = [])
 * @method array sendMessage(string $message, array $params = [])
 */
trait NewMessageTrait
{
    /**
     * @param int $telegramUserId
     * @return array
     */
    protected function getStateForUser(int $telegramUserId): array
    {
        $state = $this->db->cell(
            "SELECT status FROM headless_user_private WHERE telegram_user_id = ?",
            $telegramUserId
        );
        if (empty($state)) {
            $this->db->insert('headless_user_private', [
                'telegram_user_id' => $telegramUserId,
                'status' => '[]'
            ]);
            return [];
        }
        return json_decode($state, true);
    }

    /**
     * @param array $state
     * @param int $telegramUserId
     * @return bool
     */
    protected function updateState(array $state, int $telegramUserId): bool
    {
        $this->db->beginTransaction();
        $this->db->update(
            'headless_user_private',
            ['status' => json_encode($state)],
            ['telegram_user_id' => $telegramUserId]
        );
        return $this->db->commit();
    }

    /**
     * @param array $update
     * @return bool
     */
    public function newMessagePrivate(array $update): bool
    {
        $chat = $this->db->row(
            "SELECT * FROM headless_channels WHERE telegram_chat_id = ?",
            $update['chat']['id']
        );
        $state = $this->getStateForUser($update['from']['id']);
        if (empty($state)) {
            if ($update['text'] !== '/start') {
                $this->sendMessage('Please send `/start` begin messaging.', [
                    'chat_id' => $update['chat']['id']
                ]);
                return true;
            }
            $this->sendMessage(
                'Welcome to the **Headless Lounge.**' . PHP_EOL . PHP_EOL .
                'Please type `/`',
                ['reply_to_message_id' => $update['chat']['id']]
            );
            $state['greeted'] = true;
            $this->updateState($state, $update['from']['id']);
            return true;
            // Do more here
        }
        $this->sendMessage('DEBUG: State was not empty.', [
            'chat_id' => $update['chat']['id']
        ]);
        return false;
    }

    /**
     * @param array $update
     * @return bool
     */
    public function newMessageGroup(array $update): bool
    {
        $chat = $this->db->row(
            "SELECT * FROM headless_channels WHERE telegram_chat_id = ?",
            $update['chat']['id']
        );

        if (!empty($update['new_chat_members'])) {
            foreach ($update['new_chat_members'] as $new_chat_member) {
                // Are they found?
                $found = $this->db->cell(
                    "SELECT * FROM headless_users WHERE telegram_user = ?",
                    $new_chat_member['id']
                );
                if (!$found) {
                    $this->kickUser($update['chat']['id'], $new_chat_member['id']);
                } elseif ($this->autoKickUser(
                    $chat,
                    $update['chat']['id'],
                    $new_chat_member['id']
                )) {
                    $this->kickUser($update['chat']['id'], $new_chat_member['id']);
                }
                $state = $this->getStateForUser($new_chat_member['id']);
            }
            return true;
        }
        return false;
    }

    /**
     * @param array $settings
     * @param int $chatId
     * @param int $userId
     * @return bool
     */
    protected function autoKickUser(array $settings, int $chatId, int $userId): bool
    {
        if (!$settings['twitch_sub_only'] && !$settings['patreon_supporters_only']) {
            // We aren't enforcing either so... no.
            return false;
        }
        $owner = $this->db->row(
            "SELECT * FROM headless_users WHERE userid = ?",
            $settings['channel_user_id']
        );

        if ($settings['twitch_sub_only']) {
            $oauth = $this->db->row(
                "SELECT * FROM headless_users_oauth 
                 WHERE service = 'Twitch' AND userid = ?",
                $owner['userid']
            );
            if (!empty($oauth)) {
                $this->twitch->forChannel($oauth['serviceid']);
            }
        }


        return false;
    }

    /**
     * @param int $chatId
     * @param int $userId
     * @return array
     */
    protected function kickUser(int $chatId, int $userId): array
    {
        return $this->apiRequest('kickChatMember', [
            'chat_id' =>
                $chatId,
            'user_id' =>
                $userId,
            'until_date' => time() + 31
        ]);
    }
}
