CREATE TABLE IF NOT EXISTS headless_user_service_cache (
    cacheid BIGSERIAL PRIMARY KEY,
    userid BIGINT REFERENCES headless_users (userid),
    service TEXT,
    serviceid BIGINT,
    cachedata TEXT,
    created TIMESTAMP DEFAULT NOW(),
    modified TIMESTAMP DEFAULT NOW()
);
