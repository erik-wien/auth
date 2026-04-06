-- 03_en_preferences.sql
-- Run against the Energie application database.
-- Creates en_preferences for future Energie-specific user settings.

USE energie;  -- adjust to actual Energie DB name

CREATE TABLE en_preferences (
    id      INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL COMMENT 'references jardyx_auth.auth_accounts.id',
    UNIQUE KEY uk_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
