-- db/07_auth_accounts_defaults.sql
-- Run against the jardyx_auth database.
-- admin_create_user() (src/admin.php) only inserts username, email, password,
-- rights, disabled, activation_code. Legacy NOT NULL columns inherited from the
-- original schema then fail the INSERT with "Field 'uuid' doesn't have a default
-- value". This migration gives the legacy columns safe defaults so the modern
-- admin_create_user() works without touching them.

USE jardyx_auth;

ALTER TABLE auth_accounts
  MODIFY uuid      CHAR(36)     NOT NULL DEFAULT (UUID()),
  MODIFY img_type  VARCHAR(50)  NOT NULL DEFAULT '',
  MODIFY img_size  INT(11)      NOT NULL DEFAULT 0,
  MODIFY newMail   VARCHAR(50)  NOT NULL DEFAULT '',
  MODIFY lastLogin DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP;
