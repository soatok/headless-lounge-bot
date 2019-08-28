# Headless Lounge Bot

[![Support on Patreon](https://img.shields.io/endpoint.svg?url=https%3A%2F%2Fshieldsio-patreon.herokuapp.com%2Fsoatok&style=flat)](https://patreon.com/soatok)
[![Linux Build Status](https://travis-ci.org/soatok/headless-lounge-bot.svg?branch=master)](https://travis-ci.org/soatok/headless-lounge-bot)
[![Latest Stable Version](https://poser.pugx.org/soatok/headless-lounge-bot/v/stable)](https://packagist.org/packages/soatok/headless-lounge-bot)
[![Latest Unstable Version](https://poser.pugx.org/soatok/headless-lounge-bot/v/unstable)](https://packagist.org/packages/soatok/headless-lounge-bot)
[![License](https://poser.pugx.org/soatok/headless-lounge-bot/license)](https://packagist.org/packages/soatok/headless-lounge-bot)

Telegram bot for ensuring group access is limited to e.g. Twitch
subscribers and/or Patreon supporters.

## Using the Bot to Protect Your Groups

> The bot operated by Soatok (`@HeadlessLounge_Bot`) is only available
> if you're [one of Soatok's patrons](https://www.patreon.com/soatok)
> at the **Dhole's Delight ($3/month)** tier or higher.

Setup is straightforward:

1. Talk to [`@HeadlessLounge_Bot`](https://t.me/headlesslounge_bot).
   Make sure to link your own third-party accounts.
   (Twitch, Patreon, etc.)
2. Invite `@HeadlessLounge_Bot` to your group. 
   (Make sure you're an admin.)
3. Type `/enforce [service] [minimum]`
   * `/enforce Twitch` for Tier 1+ Twitch subs.
   * `/enforce Twitch 2` for Tier 2+ Twitch subs.

Anyone who joins the group will be auto-kicked unless...

1. They have linked their own third-party accounts by talking to
   the bot directly.
2. They have met your enforcement requirements.

Administrators can allow exceptions to this rule on a case-by-case basis by
typing `/permit @Telegram_Username`.

Note: If you choose to add multiple enforcements (i.e. Twitch and Patreon), 
satisfying **any** of the requirements will avoid being auto-kicked. 
(Logically: It's an `OR` not an `AND` operation.)

## Setting up and Deploying Your own Bot

After cloning this repository and setting up your webserver with HTTPS, get an
API key from BotFather. Stick that in `local/telegram-token.php` like so:

```php
<?php
return 'your-token-here';
``` 

Do the same with your bot's Telegram username (`local/telegram-username.php`)
and User ID  (`local/telegram-user-id.php`).

You can also configure your own local settings inside `local/settings.php` (see
`src/settings.php` for more details).

Once your configuration is complete, make sure you run  `composer install` in
the project's root directory.
 
Next, run `bin/keygen.php` and `bin/setup-webhook.php`.

Finally, run the `.sql` files inside the `sql/` directory to setup the database
tables.
