-- db/06_totp.sql
-- Run against the auth database.
-- Adds TOTP secret column for two-factor authentication.

USE auth;

ALTER TABLE auth_accounts
  ADD COLUMN totp_secret VARCHAR(64) NULL DEFAULT NULL
  COMMENT 'Base32-encoded TOTP secret. NULL = 2FA disabled.';
