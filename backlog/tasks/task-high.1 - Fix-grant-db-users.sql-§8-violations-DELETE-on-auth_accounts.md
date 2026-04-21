---
id: TASK-HIGH.1
title: Fix grant-db-users.sql §8 violations (DELETE on auth_accounts)
status: To Do
assignee: []
created_date: '2026-04-21 05:15'
updated_date: '2026-04-21 06:13'
labels: []
dependencies: []
parent_task_id: TASK-HIGH
priority: medium
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Audit 2026-04-20 found suche DB user had DELETE ON auth.auth_accounts and wlmonitor had GRANT ALL ON auth.* (implicit DELETE) — both violations of auth-rules §8 which requires deletion to flow through admin_delete_user(). mcp/scripts/grant-db-users.sql has been edited: wlmonitor grant narrowed to explicit per-table, suche DELETE removed, REVOKE IF EXISTS statements prepended to clean up already-applied grants. This task tracks (a) deployment to akadbrain and world4you, (b) verification the apps still work without DELETE.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [ ] #1 grant-db-users.sql applied on akadbrain jardyx_auth
- [ ] #2 grant-db-users.sql applied on world4you via temp PHP script
- [ ] #3 Post-deploy SHOW GRANTS confirms no DELETE on auth_accounts for any app user
- [ ] #4 wlmonitor and suche still functional after narrower grants
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
Pre-check: the file edits to `mcp/scripts/grant-db-users.sql` are already done per the task description. This task is deployment + verification only.

1. akadbrain (jardyx_auth):
   - SSH to akadbrain. Cat `grant-db-users.sql` on the server (or rsync latest) so the server copy matches.
   - Apply: `mariadb -uroot jardyx_auth < grant-db-users.sql`
   - Verify: `SHOW GRANTS FOR 'wlmonitor'@'localhost';` and `… 'suche'@'localhost';` — expect no `DELETE` on `auth_accounts` (explicit per-table grants only).
2. world4you (5279249db19):
   - Write a single-purpose PHP script `scripts/one-off/grant_db_users_apply.php` that reads `config.yaml` for root-equivalent credentials (use the db user with GRANT privilege), executes each line of `grant-db-users.sql` in order, prints OK/ERROR per statement, then refuses to run a second time (sentinel check).
   - Upload via `scripts/ftp_deploy.php`, curl once, capture output, delete via FTP in the same session. (See auth-rules §6.1.)
   - Verify via a follow-up one-off `grant_db_users_verify.php` that runs `SHOW GRANTS` and prints result — delete after use.
3. Smoke-test wlmonitor and suche on both envs:
   - wlmonitor: log in, add/edit/delete a favorite (ensures app still writes auth_log without DELETE on auth_accounts).
   - suche: log in, change theme, add a button, change password → verify password_resets DELETE still works (that's on `password_resets`, not `auth_accounts`).
4. Update `~/.claude/projects/-Users-erikr-Git/memory/MEMORY.md` reference if deploy steps differ from what's already documented.

Safety: do NOT run on world4you production without user go-ahead per `~/.claude/projects/-Users-erikr-Git/memory/feedback_no_akadbrain_deploy.md`.
<!-- SECTION:PLAN:END -->
