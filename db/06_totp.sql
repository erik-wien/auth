-- db/06_totp.sql
-- Run against the jardyx_auth database.
-- Adds TOTP secret column for two-factor authentication.

USE jardyx_auth;

ALTER TABLE auth_accounts
  ADD COLUMN totp_secret VARCHAR(64) NULL DEFAULT NULL
  COMMENT 'Base32-encoded TOTP secret. NULL = 2FA disabled.';
