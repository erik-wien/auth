-- db/12_auth_log_impersonator.sql
-- Target DB: auth (local/akadbrain) or 5279249db19 (world4you)
-- Run as: mariadb -uroot auth < db/12_auth_log_impersonator.sql
-- Idempotent: ADD COLUMN IF NOT EXISTS, CREATE INDEX IF NOT EXISTS
--
-- Adds impersonator_id to auth_log for the admin impersonation feature.
-- NULL on normal rows; set to the real admin's id during impersonated sessions.
-- No FK to auth_accounts(id) — audit trail intentionally survives user deletion.

USE auth;

ALTER TABLE auth_log
    ADD COLUMN IF NOT EXISTS impersonator_id INT UNSIGNED NULL DEFAULT NULL AFTER idUser;

CREATE INDEX IF NOT EXISTS idx_auth_log_impersonator ON auth_log (impersonator_id);
