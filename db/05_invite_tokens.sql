-- db/05_invite_tokens.sql
-- Run against jardyx_auth before deploying.

USE jardyx_auth;

CREATE TABLE auth_invite_tokens (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id    INT          NOT NULL COMMENT 'references auth_accounts.id',
  token      VARCHAR(64)  NOT NULL UNIQUE,
  expires_at DATETIME     NOT NULL,
  INDEX idx_expires (expires_at),
  FOREIGN KEY (user_id) REFERENCES auth_accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
