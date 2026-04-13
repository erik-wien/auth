-- db/05_invite_tokens.sql
-- Run against jardyx_auth before deploying.
CREATE TABLE auth_invite_tokens (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  user_id    INT         NOT NULL,
  token      VARCHAR(64) NOT NULL UNIQUE,
  expires_at DATETIME    NOT NULL,
  FOREIGN KEY (user_id) REFERENCES auth_accounts(id) ON DELETE CASCADE
);
