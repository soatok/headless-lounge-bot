CREATE TABLE IF NOT EXISTS headless_users (
    userid BIGSERIAL PRIMARY KEY,
    telegram_user BIGINT UNIQUE,
    twitch_user BIGINT NULL UNIQUE,
    patreon_user BIGINT NULL UNIQUE,
    created TIMESTAMP DEFAULT NOW(),
    modified TIMESTAMP DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS headless_users_oauth (
    oauthid BIGSERIAL PRIMARY KEY,
    userid BIGINT REFERENCES headless_users (userid),
    service TEXT, -- Twitch, Patreon, etc.
    serviceid TEXT, -- Twitch broadcaster ID, Patreon page ID, etc.
    url_token TEXT UNIQUE,
    refresh_token TEXT,
    access_token TEXT,
    access_expires TIMESTAMP,
    scope TEXT
);
CREATE UNIQUE INDEX ON headless_users_oauth (userid, service);

CREATE TABLE IF NOT EXISTS headless_channels (
    channelid BIGSERIAL PRIMARY KEY,
    channel_user_id BIGINT NULL REFERENCES headless_users(userid),
    telegram_chat_id TEXT,
    twitch_sub_only BOOLEAN DEFAULT FALSE,
    twitch_sub_minimum INTEGER DEFAULT 0,
    patreon_supporters_only BOOLEAN DEFAULT FALSE,
    patreon_rank_minimum INTEGER DEFAULT 0

);

CREATE TABLE IF NOT EXISTS headless_channel_users (
    userid BIGINT REFERENCES headless_users (userid),
    channelid BIGINT REFERENCES headless_channels (channelid)
);
