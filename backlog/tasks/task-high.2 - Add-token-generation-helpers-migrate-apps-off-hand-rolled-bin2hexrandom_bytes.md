---
id: TASK-HIGH.2
title: >-
  Add token-generation helpers; migrate apps off hand-rolled
  bin2hex(random_bytes)
status: Done
assignee: []
created_date: '2026-04-21 05:15'
updated_date: '2026-04-21 06:37'
labels: []
dependencies: []
parent_task_id: TASK-HIGH
priority: medium
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Audit 2026-04-20: every app hand-rolls reset-token generation (bin2hex(random_bytes(32))) in forgotPassword.php, and 3 apps hand-roll email-confirmation codes in preferences.php. This is auth-rules §1 violation (no reimplemented auth). Add library helpers (e.g. auth_reset_token_issue($con,$userId), auth_email_confirmation_issue($con,$userId)) that encapsulate token generation, DB insert, TTL, and single-use rotation. Then migrate all 5 apps off inline generation. Covers: simplechat/web/forgotPassword.php:46, Energie/web/forgotPassword.php:36, wlmonitor/web/forgotPassword.php:38, zeiterfassung/web/forgotPassword.php:43, suche/web/forgotPassword.php:36, plus Energie/wlmonitor/zeiterfassung preferences.php email-confirmation codes.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 Library helper designed and implemented in erikr/auth with tests
- [x] #2 All 5 apps' forgotPassword.php use the helper; no bin2hex(random_bytes) remains
- [x] #3 3 apps' preferences.php email-confirmation flows use the helper
- [x] #4 Old password_resets migration path still works (no schema change required)
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
This task has two distinct phases. Phase 1 lands in erikr/auth; Phase 2 migrates five apps. Gate Phase 2 behind Phase 1 merge.

Phase 1 — Library (erikr/auth):
1. Design helper API in `src/invite.php` (or new `src/tokens.php` if cleaner):
   - `auth_reset_token_issue(mysqli $con, int $userId): array{ok: bool, token?: string, error?: string}`
     - Generates 32-byte random token, inserts into `password_resets` with TTL (reuse current default), returns the plaintext selector.validator (or raw token — follow existing `auth_remember_issue()` selector/validator split for consistency).
     - Invalidates prior unexpired reset tokens for the user (single-use rotation).
   - `auth_email_confirmation_issue(mysqli $con, int $userId, string $newEmail): array{ok: bool, token?: string}`
     - Decide storage: either reuse `auth_invite_tokens` with a new `type` column, or add `auth_email_confirmations` table. Prefer reusing `auth_invite_tokens` (migration: add ENUM type column default 'invite').
   - Optional: `auth_reset_token_consume(mysqli $con, string $token): ?int` returns user_id on success, null on invalid/expired — for the completion side.
2. Write unit tests in `tests/Unit/TokenHelpersTest.php` with mysqli mock; integration tests in `tests/Integration/TokenHelpersIntegrationTest.php` against a seeded test DB (wrap each in a transaction).
3. Migration: if `auth_invite_tokens` needs a `type` column, add `db/NN_token_type.sql` with idempotent `ALTER TABLE … ADD COLUMN IF NOT EXISTS type ENUM('invite','reset','email_confirm') DEFAULT 'invite'`.
4. Document the helpers in `auth/CLAUDE.md` under a new "Token helpers" section.

Phase 2 — Consumer migration (5 apps, parallel possible — independent files):
1. simplechat, Energie, wlmonitor, zeiterfassung, suche — replace `bin2hex(random_bytes(32))` in each `web/forgotPassword.php` with `auth_reset_token_issue($con, $userId)`. Update the email-sending call site to use the returned token.
2. Energie, wlmonitor, zeiterfassung `preferences.php` — replace the inline email-confirmation token generation with `auth_email_confirmation_issue($con, $userId, $newEmail)`.
3. Verify by: forgot-password flow end-to-end on one app, email-confirmation flow on one app.

AC mapping:
- #1 Phase 1
- #2 Phase 2 step 1
- #3 Phase 2 step 2
- #4 If using `auth_invite_tokens` with type column — old `password_resets` rows ignored; if keeping `password_resets` — no schema change.

Gating: **Phase 2 is blocked until Phase 1 is merged into erikr/auth's main branch and `composer update` run in each app's repo.**
<!-- SECTION:PLAN:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
Added `src/tokens.php` with `auth_reset_token_issue()` and `auth_email_confirmation_issue()`. Covered by 11 unit tests. Migrated all 5 apps' `forgotPassword.php` and 3 apps' `preferences.php` off hand-rolled `bin2hex(random_bytes(32))`. No schema changes. Full suite 166/166 green.
<!-- SECTION:FINAL_SUMMARY:END -->
