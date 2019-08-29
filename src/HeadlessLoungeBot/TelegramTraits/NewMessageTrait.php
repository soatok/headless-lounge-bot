<?php
declare(strict_types=1);
namespace Soatok\HeadlessLoungeBot\TelegramTraits;

use ParagonIE\ConstantTime\Base32;
use ParagonIE\EasyDB\EasyDB;
use Soatok\DholeCrypto\Exceptions\CryptoException;
use Soatok\HeadlessLoungeBot\Splices\Channels;
use Soatok\HeadlessLoungeBot\Splices\Users;
use Soatok\HeadlessLoungeBot\Twitch;

/**
 * Trait NewMessageTrait
 * @package Soatok\HeadlessLoungeBot\TelegramTraits
 *
 * @property string $baseUrl
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
     * @throws \Exception
     */
    public function newMessagePrivate(array $update): bool
    {
        $this->users->ensureExists($update['from']['id']);
        $user = $this->users->getByTelegramUserId($update['from']['id']);
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
        switch (strtolower(trim($update['text']))) {
            case '/start':
                $this->commandStart($update['chat']['id']);
                break;
            case '/list':
                $this->commandList($update['from']['id'], $update['chat']['id']);
                break;
            case '/link twitch':
                $this->commandLink($update, $user, 'Twitch');
                break;
            case '/link patreon':
                $this->commandLink($update, $user, 'Patreon');
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
     * @param array $update
     * @param array $user
     * @param string $service
     * @return bool
     * @throws \Exception
     */
    protected function commandLink(array $update, array $user, string $service): bool
    {
        $this->db->beginTransaction();
        $exists = $this->db->exists(
            "SELECT * FROM headless_users_oauth 
             WHERE userid = ? AND service = ?",
            $user['userid'],
            $service
        );
        $token = Base32::encodeUpperUnpadded(random_bytes(30));
        $href = $this->baseUrl . '/thirdparty/' . $token;
        if ($exists) {
            $this->db->update(
                'headless_users_oauth',
                [
                    'url_token' => $token
                ],
                [
                    'userid' => $user['userid'],
                    'service' => $service
                ]
            );
        } else {
            $this->db->insert(
                'headless_users_oauth',
                [
                    'url_token' => $token,
                    'userid' => $user['userid'],
                    'service' => $service
                ]
            );
        }
        $this->sendMessage(
            'Please visit this URL to link your ' . $service . ' account:' . $href, [
                'chat_id' => $update['chat']['id']
            ]
        );
        return $this->db->commit();
    }

    /**
     * @param int $telegramUserId
     * @param int $chatId
     * @return array
     */
    protected function commandList(int $telegramUserId, int $chatId): array
    {
        $channels = $this->channels->getTelegramExclusiveAllowedChannels($telegramUserId);
        $message = '*Channels*: ' . PHP_EOL;
        foreach ($channels as $chan) {
            $meta = $this->apiRequest('getChat', ['chat_id' => $chan]);
            if ($meta['ok'] && !empty($meta['result'])) {
                $res = $meta['result'];
                if (isset($res['username'])) {
                    $message .= '- ' . $res['title'] .
                        ' (@' . $res['username'] . ')' . PHP_EOL;
                } else {
                    if (empty($res['invite_link'])) {
                        $this->apiRequest('exportChatInviteLink', ['chat_id' => $chan]);
                        $meta = $this->apiRequest('getChat', ['chat_id' => $chan]);
                        $res = $meta['result'];
                    }
                    $message .= '- ' . $res['title'] .
                        ' (' . $res['invite_link'] . ')' . PHP_EOL;
                }
            }
        }
        return $this->sendMessage($message, ['chat_id' => $chatId]);
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
            '`/list`: Get a list of exclusive groups you can join.' . PHP_EOL . PHP_EOL .
            'To create a group, invite me into your group, ' .
            'make me an administrator, ' .
            'then say `/enforce twitch` or `/enforce patreon`.',
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
            if (empty($user['patreon_user'])) {
                $statusReport .= 'Patreon: _Not authenticated_' . PHP_EOL;
            } else {
                $statusReport .= 'Patreon: *Accounts Linked*'. PHP_EOL;
                // $patreon = $this->users->getPatreonIntegration($user['userid']);
            }
            if (empty($user['twitch_user'])) {
                $statusReport .= 'Twitch: _Not authenticated_' . PHP_EOL;
            } else {
                $statusReport .= 'Twitch: *Accounts Linked*'. PHP_EOL;
                // $twitch = $this->users->getTwitchIntegration($user['userid']);
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
        if (!empty($chat['exceptions'])) {
            $chat['exceptions'] = json_decode($chat['exceptions'], true);
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
        $trimmed = trim($update['text']);
        if (!preg_match('#^/([A-Za-z0-9_]+)[\s]+?([^\s]+)?[\s]+?([^\s]+)?$#', $trimmed, $m)) {
            if (!preg_match('#^/([A-Za-z0-9_]+)[\s]+?([^\s]+)?$#', $trimmed, $m)) {
                if (!preg_match('#^/([A-Za-z0-9_]+)[\s]$#', $trimmed, $m)) {
                    return false;
                }
            }
        }
        $chatUser = $this->db->row(
            "SELECT * FROM headless_users WHERE telegram_user = ?",
            $update['from']['id']
        );
        if (empty($chatUser)) {
            $this->sendMessage(
                'Please talk to me directly to setup and link your accounts.',
                ['chat_id' => $update['chat']['id']]
            );
            return false;
        }
        $administrators = $this->getAdministrators($update['chat']['id']);
        $isAdmin = !empty($update['chat']['all_members_are_administrators']);
        if (!$isAdmin) {
            // Check that $chatUser['id'] belongs to $administrators
            foreach ($administrators as $admin) {
                if ($admin['user']['id'] === $chatUser['telegram_user']) {
                    $isAdmin = true;
                    break;
                }
            }
        }

        if (!$isAdmin) {
            // You are NOT a group admin.
            $this->sendMessage(
                'You are not a group administrator.',
                ['chat_id' => $update['chat']['id']]
            );
            return false;
        }

        if ($m[1] === 'enforce' && !empty($m[2])) {
            return $this->groupEnforceCommand($chat, $chatUser, $update, $m);
        }
        if (empty($chat)) {
            $this->sendMessage(
                'This group is not protected by Headless Lounge Bot.',
                ['chat_id' => $update['chat']['id']]
            );
            return false;
        }
        if ($m[1] === 'permit' && !empty($m[2])) {
            return $this->groupPermitCommand($chat, $chatUser, $update, $m);
        }

        // TODO: Other commands

        return false;
    }

    /**
     * @param array $chat
     * @param array $chatUser
     * @param array $update
     * @param array $m
     * @return bool
     */
    protected function groupEnforceCommand(
        array $chat,
        array $chatUser,
        array $update,
        array $m
    ): bool {
        $fields = [
            'telegram_chat_id' => $update['chat']['id'],
            'channel_user_id' => $chatUser['id']
        ];
        $message = '';
        if (strtolower($m[2]) === 'twitch') {
            if (empty($chatUser['twitch_user'])) {
                $this->sendMessage(
                    'You do not have a linked Twitch account.' . PHP_EOL . PHP_EOL .
                    'Please message the bot directly to link your accounts.',
                    ['chat_id' => $update['chat']['id']]
                );
                return false;
            }
            $message = 'Twitch subscribers';
            if (!empty($m[3])) {
                $fields['twitch_sub_minimum'] = (int) $m[3];
                $message .= ' (Tier ' . $m[3] . '+)';
            }
            $fields['twitch_sub_only'] = true;
        } else if (strtolower($m[2]) === 'patreon') {
            if (empty($chatUser['patreon_user'])) {
                $this->sendMessage(
                    'You do not have a linked Patreon account.' . PHP_EOL . PHP_EOL .
                    'Please message the bot directly to link your accounts.',
                    ['chat_id' => $update['chat']['id']]
                );
                return false;
            }
            $message = 'Patreon supporters';
            if (!empty($m[3])) {
                $fields['patreon_rank_minimum'] = (int) $m[3];
                $message .= ' (' . $m[3] . ')';
            }
            $fields['patreon_supporters_only'] = true;
        }

        $this->db->beginTransaction();
        if (empty($chat)) {
            $fields['channel_user_id'] = $chatUser['userid'];
            $fields['exceptions'] = '[]';
            $this->db->insert('headless_channels', $fields);
        } else {
            if (empty($fields['channel_user_id'])) {
                $fields['channel_user_id'] = $chatUser['userid'];
            }
            $this->db->update(
                'headless_channels',
                $fields,
                ['channelid' => $chat['channelid']]
            );
        }
        if ($this->db->commit()) {
            $this->sendMessage(
                'Understood. ' . $message . ' will be allowed in this group.',
                ['chat_id' => $update['chat']['id']]
            );
            return true;
        }
        return false;
    }

    /**
     * @param array $chat
     * @param array $chatUser
     * @param array $update
     * @param array $m
     * @return bool
     */
    protected function groupPermitCommand(
        array $chat,
        array $chatUser,
        array $update,
        array $m
    ): bool {
        return false;
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
                /*
                $this->sendMessage(
                    'This user is not known to Headless Lounge Bot.',
                    ['chat_id' => $update['chat']['id']]
                );
                */
                $this->kickUser($update['chat']['id'], $new_chat_member['id']);
            } elseif ($this->autoKickUser(
                $chat,
                $update['chat']['id'],
                $new_chat_member['id']
            )) {
                $this->sendMessage(
                    'This user triggered the auto-kick mechanic.',
                    ['chat_id' => $update['chat']['id']]
                );
                $this->kickUser($update['chat']['id'], $new_chat_member['id']);
            } else {
                $this->sendMessage(
                    'Welcome, Twitch subscriber! :3',
                    ['chat_id' => $update['chat']['id']]
                );
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
            $this->sendMessage(
                'This user is not known to Headless Lounge Bot.',
                ['chat_id' => $chatId]
            );
            return true;
        }

        // Allow exceptions to be granted...
        if (!empty($settings['exceptions'])) {
            if (is_string($settings['exceptions'])) {
                $settings['exceptions'] = json_decode($settings['exceptions'], true);
            }
            if (in_array($linkedAccounts['userid'], $settings['exceptions'], true)) {
                // You are an exception!
                return false;
            }
        }

        // This affects our final fallback behavior.
        $autoKick = $settings['twitch_sub_only'] || $settings['patreon_supporters_only'];
        if ($settings['twitch_sub_only'] && !empty($owner['twitch_user'])) {
            $twitch = $this->twitch->forChannel($owner['twitch_user']);
            $subs = $twitch->getSubscribers();
            // Parse $subs, figure out if new user is a sub or not, kick them otherwise...
            foreach ($subs as $sub) {
                if ($sub['user_id'] === $linkedAccounts['serviceid']) {
                    // We found a match!
                    if ($settings['twitch_sub_minimum'] > 0) {
                        // Auto-kick if tier is too low:
                        if ($sub['tier'] < $settings['twitch_sub_minimum']) {
                            $this->sendMessage(
                                'Tier too low.',
                                ['chat_id' => $chatId]
                            );
                            return true;
                        }
                        return false;
                    }
                    // Don't autokick
                    return false;
                }
            }
        }
        $this->sendMessage(
            'This user is not a known Twitch sub.',
            ['chat_id' => $chatId]
        );

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
