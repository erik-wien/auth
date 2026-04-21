-- 01_rename_tables.sql
-- Run against the auth database.
-- Renames auth tables from wl_ prefix to auth_ prefix.
--
-- NOT idempotent. Re-run procedure:
--   1. Check state: SELECT TABLE_NAME FROM information_schema.TABLES
--                   WHERE TABLE_SCHEMA='auth'
--                   AND TABLE_NAME IN ('wl_accounts','auth_accounts','wl_log','auth_log');
--   2. If only auth_accounts and auth_log exist — migration already ran, skip.
--   3. If only wl_accounts and wl_log exist — run file as-is.
--   4. If both old and new names appear — partial rename; investigate before re-running.

USE auth;

RENAME TABLE wl_accounts TO auth_accounts;
RENAME TABLE wl_log      TO auth_log;
