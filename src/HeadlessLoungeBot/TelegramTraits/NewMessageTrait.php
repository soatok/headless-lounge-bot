<?php
declare(strict_types=1);
namespace Soatok\HeadlessLoungeBot\TelegramTraits;

use ParagonIE\EasyDB\EasyDB;
use Soatok\DholeCrypto\Exceptions\CryptoException;
use Soatok\HeadlessLoungeBot\Splices\Channels;
use Soatok\HeadlessLoungeBot\Splices\Users;
use Soatok\HeadlessLoungeBot\Twitch;

/**
 * Trait NewMessageTrait
 * @package Soatok\HeadlessLoungeBot\TelegramTraits
 *
 * @property Channels $channels
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
            // Do more here
        }
        switch ($update['text']) {
            case '/start':
                $this->commandStart($update['chat']['id']);
                break;
            case '/list':
                $this->commandList($update['from']['id']);
                break;
            case '/status':
                $this->commandStatus($update);
                break;
        }
        $state['greeted'] = true;
        $this->updateState($state, $update['from']['id']);
        return true;
    }

    /**
     * @param int $telegramUserId
     * @return array
     */
    protected function commandList(int $telegramUserId): array
    {
        return $this->channels->getTelegramExclusiveAllowedChannels($telegramUserId);
    }

    /**
     * @param int $chatId
     * @return array
     */
    protected function commandStart(int $chatId): array
    {
        return $this->sendMessage(
            'Welcome to the **Headless Lounge.**' . PHP_EOL . PHP_EOL .
            '`/status`: Authentication status' . PHP_EOL .
            '`/link Patreon`: Link your Patreon account with your Telegram account.' . PHP_EOL .
            '`/link Twitch`: Link your Twitch.tv account with your Telegram account.' . PHP_EOL .
            '`/list`: Get a list of exclusive groups you can join.' . PHP_EOL .
            '`/creategroup`: Create a new group (may require special permissions)',
            ['chat_id' => $chatId]
        );
    }

    /**
     * @param array $update
     * @return array
     */
    protected function commandStatus(array $update): array
    {
        $statusReport = '**Third-Party authentication status...**' . PHP_EOL . PHP_EOL;
        $user = $this->users->getByTelegramUserId($update['from']['id']);
        if (empty($user)) {
            // Ensure row exists next time...
            $statusReport .= 'Patreon: _Not authenticated_' . PHP_EOL;
            $statusReport .= 'Twitch: _Not authenticated_' . PHP_EOL;
            $this->users->upsert($update['from']['id']);
        } else {
            $patreon = $this->users->getPatreonIntegration($user['userid']);
            if (empty($patreon)) {
                $statusReport .= 'Patreon: _Not authenticated_' . PHP_EOL;
            } else {
                $statusReport .= 'Patreon: Unknown'. PHP_EOL;
            }

            $twitch = $this->users->getTwitchIntegration($user['userid']);
            if (empty($twitch)) {
                $statusReport .= 'Twitch: _Not authenticated_' . PHP_EOL;
            } else {
                $statusReport .= 'Twitch: Unknown'. PHP_EOL;
            }
        }

        return $this->sendMessage(
            $statusReport,
            ['chat_id' => $update['from']['id']]
        );
    }

    /**
     * @param array $update
     * @return bool
     * @throws CryptoException
     * @throws \SodiumException
     */
    public function newMessageGroup(array $update): bool
    {
        $chat = $this->db->row(
            "SELECT * FROM headless_channels WHERE telegram_chat_id = ?",
            $update['chat']['id']
        );

        if (!empty($update['new_chat_members'])) {
            foreach ($update['new_chat_members'] as $new_chat_member) {
                if ($new_chat_member['id'] === 917008939) {
                    continue;
                }
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
     * @throws CryptoException
     * @throws \SodiumException
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
        if ($userId === $owner['telegram_user']) {
            return false;
        }
        $linkedAccounts = $this->db->row(
            "SELECT * FROM headless_users WHERE telegram_user = ?",
            $userId
        );
        if (empty($linkedAccounts)) {
            // User does not exist in our system!
            return true;
        }

        if ($settings['twitch_sub_only']) {
            $oauth = $this->db->row(
                "SELECT * FROM headless_users_oauth 
                 WHERE service = 'Twitch' AND userid = ?",
                $owner['userid']
            );
            if (!empty($oauth)) {
                $twitch = $this->twitch->forChannel($oauth['serviceid']);
            } else {
                $twitch = $this->twitch;
            }
            $subs = $twitch->getSubscribers();
            // Parse $subs, figure out if new user is a sub or not, kick them otherwise...
            foreach ($subs as $sub) {
                if ($sub['user_id'] === $linkedAccounts['serviceid']) {

                }
            }
        }

        // @TODO Patreon-only subs


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
