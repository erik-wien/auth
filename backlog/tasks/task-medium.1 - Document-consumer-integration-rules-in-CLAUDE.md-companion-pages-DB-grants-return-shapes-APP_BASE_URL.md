---
id: TASK-MEDIUM.1
title: >-
  Document consumer integration rules in CLAUDE.md (companion pages, DB grants,
  return shapes, APP_BASE_URL)
status: Done
assignee: []
created_date: '2026-04-19 11:57'
updated_date: '2026-04-21 08:12'
labels: []
dependencies: []
parent_task_id: TASK-MEDIUM
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Four integration pitfalls surfaced during zeiterfassung TASK-1 / TASK-HIGH.1 that should be documented in auth/CLAUDE.md so every consumer app knows what to check before first use of the invite/admin flows.

1. Read return shapes — auth_login() returns ok=true with totp_required=true (not ok=false). Document each non-obvious return shape in the function's docblock or a dedicated CLAUDE.md section.
2. Companion pages — admin_create_user() / admin_reset_password() send emails pointing at APP_BASE_URL/setpassword.php. Consumer apps must ship this page. Document it as a prerequisite.
3. DB grants — Users::listExtended() queries auth_invite_tokens; Dispatch::handle() for delete needs DELETE on auth_accounts. Document every table each library function touches so consumers can set grants correctly.
4. APP_BASE_URL — any email-sending flow silently sends broken links if base_url in config is stale. Document the verification step.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 CLAUDE.md lists the return shapes of auth_login, auth_totp_complete, invite_complete, admin_create_user, admin_delete_user
- [x] #2 CLAUDE.md states that setpassword.php must exist in the consumer app before admin_create_user / admin_reset_password can be used
- [x] #3 CLAUDE.md lists every auth.* table each library function (Users::listExtended, Dispatch::handle, invite_complete) queries, so consumers can set DB grants correctly
- [x] #4 CLAUDE.md documents that APP_BASE_URL must be verified before first use of any email-sending flow
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
Pure documentation task in `auth/CLAUDE.md`. Four additions:

1. **Return shapes section** — under "Public API", add a "Return shapes" subsection documenting the non-obvious cases:
   - `auth_login()` → `['ok' => true, 'totp_required' => true]` when credentials are valid AND user has TOTP. `ok` stays true; consumers must branch on `totp_required` before treating as logged-in.
   - `auth_totp_complete()` → add current return shape.
   - `invite_complete()` → add current return shape.
   - `admin_create_user()` → returns int user_id on success; throws/returns ? on failure (match current impl).
   - `admin_delete_user()` → bool, refuses self-delete.

2. **Companion pages section** — add a "Consumer prerequisites" note stating every app that calls `admin_create_user()` / `admin_reset_password()` MUST ship `web/setpassword.php` at `APP_BASE_URL/setpassword.php` since email links point there. Cross-reference the canonical implementation (suche or wlmonitor).

3. **DB tables touched table** — expand the existing "Tables" section with a per-function matrix:
   | Function | Tables read | Tables written |
   |---|---|---|
   | `auth_login` | auth_accounts, auth_blacklist, auth_log | auth_log, auth_blacklist, auth_accounts (invalidLogins) |
   | `Users::listExtended` (chrome) | auth_accounts, auth_invite_tokens, auth_log | — |
   | `Dispatch::handle` (delete) | auth_accounts | auth_accounts (DELETE), auth_log |
   | `invite_complete` | auth_invite_tokens, auth_accounts | auth_accounts, auth_invite_tokens |
   | … fill for all public functions …
   This informs consumer DB grants (rule §8).

4. **APP_BASE_URL verification** — add a "Before first use" checklist:
   - Confirm `APP_BASE_URL` in `config.yaml` matches the live host (stale values silently ship broken email links).
   - Run a test invite on a throwaway account before going live.

Cross-check each addition against `~/.claude/rules/auth-rules.md` — no duplication (auth-rules §8 already lists grants policy; link to it rather than repeating). Keep CLAUDE.md slim per memory note — extended tables can go in `docs/consumer-integration.md` if it gets long.
<!-- SECTION:PLAN:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
Added to auth/CLAUDE.md: (1) return-shapes table for auth_login/auth_totp_complete/invite_complete/admin_create_user/admin_delete_user with explicit TOTP pitfall callout; (2) Consumer prerequisites section documenting setpassword.php requirement and canonical reference; (3) Tables-touched-per-function matrix under Database section; (4) APP_BASE_URL verification step in Consumer prerequisites.
<!-- SECTION:FINAL_SUMMARY:END -->
