-- Migration 11: drop the unused debug column from auth_accounts
-- Run AFTER deploying app code that no longer SELECTs or UPDATEs this column.
-- Idempotency: check before running:
--   SELECT COUNT(*) FROM information_schema.COLUMNS
--   WHERE TABLE_SCHEMA='auth' AND TABLE_NAME='auth_accounts' AND COLUMN_NAME='debug';
-- Returns 0 → already dropped, skip. Returns 1 → safe to run.
USE auth;
ALTER TABLE auth_accounts DROP COLUMN IF EXISTS debug;
