# Bot notes

1. Channel list table.
    * Telegram group ID
    * Telegram owner ID
    * Twitch channel ID
    * Patreon page ID

2. User map
    * Telegram ID
    * Twitch ID
    * Patreon ID

3. Invite codes
    * Tie to channels, users.
    * One per user.
    * Used to associate TG with Twitch/Patreon/etc.
```
Channel owners:

1. Invite the bot to a channel, make admin.
2. Authenticate with [third party proviers, e.g. Twitch]
   - {channel - oauth refresh/access token pair}
3. 










[ channel ]
    [ Third-Party: Twitch, Patreon, ... ]
    [ Telegram chat ]
    [ Owner information (user ID, etc.) ] <- internal

[ user map ]
    [ Telegram user ID ]
    [ Twitch user ID ]
    [ Patreon user ID ]

Bot -> User: Click [here] to authenticate.
Web page: [ Login with Twitch ]
```
