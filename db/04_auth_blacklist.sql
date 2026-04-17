-- 04_auth_blacklist.sql
-- Run against the jardyx_auth database.
-- Creates auth_blacklist for both manual and automatic IP blocking.

USE jardyx_auth;

CREATE TABLE auth_blacklist (
    id         INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ip         VARCHAR(45)   NOT NULL                     COMMENT 'IPv4 or IPv6 address',
    reason     VARCHAR(255)  NOT NULL DEFAULT '',
    auto       TINYINT(1)    NOT NULL DEFAULT 0           COMMENT '0 = manual, 1 = auto-blacklisted',
    blocked_at TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP     NULL     DEFAULT NULL        COMMENT 'NULL = permanent block',
    UNIQUE KEY uk_ip      (ip),
    INDEX      idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
