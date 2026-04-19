-- 01_rename_tables.sql
-- Run against the auth database.
-- Renames auth tables from wl_ prefix to auth_ prefix.

USE auth;

RENAME TABLE wl_accounts TO auth_accounts;
RENAME TABLE wl_log      TO auth_log;
