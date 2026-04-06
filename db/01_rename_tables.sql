-- 01_rename_tables.sql
-- Run against the jardyx_auth database.
-- Renames auth tables from wl_ prefix to auth_ prefix.

USE jardyx_auth;

RENAME TABLE wl_accounts TO auth_accounts;
RENAME TABLE wl_log      TO auth_log;
