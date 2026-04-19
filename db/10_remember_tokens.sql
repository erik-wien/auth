-- db/10_remember_tokens.sql
-- Run against auth.
-- Adds the remember-me token table for "Keep me logged in" (8-day persistent sessions).
--
-- Token format: cookie value is "<selector>.<validator>" where:
--   - selector  = 16 hex chars, stored plain, used for O(1) row lookup
--   - validator = 64 hex chars, stored as SHA-256 hash, compared via hash_equals()
-- This split-token pattern prevents timing attacks on lookup and prevents a DB
-- dump alone from yielding usable cookies.
--
-- Rotation: each successful cookie use issues a fresh selector+validator and
-- deletes the old row, so a leaked cookie is valid for at most one request.
--
-- Cleanup: CASCADE on auth_accounts deletion; expired rows are garbage-collected
-- opportunistically by auth_remember_validate() on each request.

USE auth;

CREATE TABLE IF NOT EXISTS auth_remember_tokens (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id      INT          NOT NULL,
  selector     CHAR(16)     NOT NULL,
  token_hash   CHAR(64)     NOT NULL,
  expires_at   DATETIME     NOT NULL,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_used_at DATETIME     NULL,
  user_agent   VARCHAR(255) NULL,
  ip           VARCHAR(45)  NULL,
  UNIQUE KEY uk_selector (selector),
  INDEX idx_expires (expires_at),
  INDEX idx_user (user_id),
  CONSTRAINT fk_remember_user
    FOREIGN KEY (user_id) REFERENCES auth_accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
