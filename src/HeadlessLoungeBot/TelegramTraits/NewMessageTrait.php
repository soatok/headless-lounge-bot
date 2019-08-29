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
        if (empty($chat)) {
            $chat = [];
        }

        // Process new members: Are they allowed?
        if (!empty($update['new_chat_members'])) {
            return $this->handleNewMembers($chat, $update);
        }
        if (!empty($update['text'])) {
            return $this->handleGroupMessage($chat, $update);
        }

        return false;
    }

    /**
     * @param array $chat
     * @param array $update
     * @return bool
     */
    protected function handleGroupMessage(array $chat, array $update): bool
    {
        if (!preg_match('#^/([A-Za-z0-9_]+)[\s]+?([^\s]+)?#', $update['text'], $m)) {
            return false;
        }
        $chatUser = $this->db->row(
            "SELECT * FROM headless_users WHERE telegram_user = ?",
            $update['from']['id']
        );
        $administrators = $this->getAdministrators($update['chat']['id']);
        $isAdmin = !empty($update['chat']['all_members_are_administrators']);
        if (!$isAdmin) {
            // Check that $chatUser['id'] belongs to $administrators
            foreach ($administrators as $admin) {
                if ($admin['user']['id'] === $chatUser['userid']) {
                    $isAdmin = true;
                    break;
                }
            }
        }

        if (!$isAdmin) {
            // You are NOT a group admin.
            return false;
        }

        if ($m[1] === 'enforce' && !empty($m[2])) {
            $fields = [
                'telegram_chat_id' => $update['chat']['id'],
                'channel_user_id' => $chatUser['id']
            ];
            if (strtolower($m[2]) === 'twitch') {
                if (!empty($m[3])) {
                    $fields['twitch_sub_minimum'] = (int) $m[3];
                }
                $fields['twitch_sub_only'] = true;
            }
            $this->db->beginTransaction();
            if (empty($chat)) {
                $this->db->insert('headless_channels', $fields);
            } else {
                $this->db->update('headless_channels', $fields, ['id' => $chat['channelid']]);
            }
            return $this->db->commit();
        }
    }

    /**
     * @param array $chat
     * @param array $update
     * @return bool
     * @throws CryptoException
     * @throws \SodiumException
     */
    protected function handleNewMembers(array $chat, array $update): bool
    {
        if (empty($chat)) {
            return false;
        }
        foreach ($update['new_chat_members'] as $new_chat_member) {
            if ($new_chat_member['id'] === $this->botUserId) {
                continue;
            }
            // Are they found?
            $found = $this->db->cell(
                "SELECT count(*) FROM headless_users WHERE telegram_user = ?",
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

    /**
     * Should we auto-kick this user? (TRUE = Kick, FALSE = Do not)
     *
     * - If they are not associated with an account: Kick them!
     * - If they are in the "exceptions" list (i.e. they were whitelisted by an
     *   admin): Do not kick
     * - If this is a Twitch subscriber only group...
     *   - If they are a subscriber, and >= the minimum tier: Do not kick
     * - If this is a Patreon supporter only group...
     *   - If they are a Patreon supporter, and >= the minimum tier: Do not kick
     * - If all else fails:
     *   - If this room has no restrictions, do not kick
     *   - If this room has restrictions, kick
     *
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
        if ($userId === $owner['telegram_user'] || $userId === $this->botUserId) {
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

        // This affects our final fallback behavior.
        $autoKick = $settings['twitch_sub_only'] || $settings['patreon_supporters_only'];
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
                    // We found a match!
                    if ($settings['twitch_sub_minimum'] > 0) {
                        // Auto-kick if tier is too low:
                        return $sub['tier'] < $settings['twitch_sub_minimum'];
                    }
                    // Don't autokick
                    return false;
                }
            }
        }

        // @TODO Patreon-only subs

        return $autoKick;
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
