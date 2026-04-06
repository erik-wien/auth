-- 02_wl_preferences.sql
-- Run against the wlmonitor application database.
-- Creates wl_preferences and migrates departures from auth_accounts.

USE wlmonitor;  -- adjust to actual wlmonitor DB name

CREATE TABLE wl_preferences (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL COMMENT 'references jardyx_auth.auth_accounts.id',
    departures TINYINT UNSIGNED NOT NULL DEFAULT 2,
    UNIQUE KEY uk_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed wl_preferences for every existing user, copying their departures value.
-- jardyx_auth.auth_accounts is accessible cross-DB from the wlmonitor DB.
INSERT INTO wl_preferences (user_id, departures)
SELECT id, COALESCE(departures, 2)
FROM jardyx_auth.auth_accounts
ON DUPLICATE KEY UPDATE departures = VALUES(departures);

-- Once data is verified, remove departures from auth_accounts:
-- ALTER TABLE jardyx_auth.auth_accounts DROP COLUMN departures;
-- (kept commented out — run manually after verifying wl_preferences data)
