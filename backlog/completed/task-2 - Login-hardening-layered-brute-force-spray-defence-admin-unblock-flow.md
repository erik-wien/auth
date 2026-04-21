---
id: TASK-2
title: 'Login hardening: layered brute-force / spray defence + admin unblock flow'
status: Done
assignee: []
created_date: '2026-04-18 11:06'
updated_date: '2026-04-20 04:51'
labels: []
dependencies: []
priority: high
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Harden the login flow against brute-force and credential-spraying attacks while keeping legitimate-but-forgetful users from being collaterally IP-blocked. Introduces a layered model (progressive delay → per-IP rate limit → per-user lockout → score-based IP blacklist), admin email notifications on the two unattended-action triggers (user lockout, IP blacklist), and an admin-driven unblock path (Reset-Password bundles invalidLogins clear + auto-unblacklist of IPs tied to this user).

## Layered model

| Layer | Trigger | Scope | Recovery |
|---|---|---|---|
| Progressive delay | fail count ≥ 2 from IP in 15 min | per IP, delays response | auto-clears with window |
| Per-IP rate limit (existing) | 5 fails / 15 min / IP | per IP, 15 min | auto-clears |
| Per-user lockout (new) | invalidLogins ≥ 10 | per user | admin reset-password OR reset-Fehlversuche |
| IP blacklist, score-based (replaces current rule) | shenanigans score ≥ 15 / 60 min | per IP, permanent until admin | admin un-blacklist OR auto via reset-password |

## Progressive delay

Applied in `auth_login()` before every rejected response, starting at the 2nd fail from this IP:

```
delay_s = min(30, 2 ** (fails_from_ip_in_window - 1))
```

- Counted from `auth_log` entries in the rate-limit window (`context='login'`, `activity LIKE 'Login failed%'`, ip match).
- Cap at 30s.
- Do **not** delay successful logins.

## Shenanigans score (replaces BLACKLIST_AUTO_STRIKES rule)

Scored on-the-fly from `auth_log` rows in the last 60 min for the requesting IP. Weights as constants:

- `LOGIN_SCORE_FAIL_EXISTING_USER = 1` — fail against a real username
- `LOGIN_SCORE_FAIL_UNKNOWN_USER = 3` — fail against a non-existent username
- `LOGIN_SCORE_RL_STRIKE = 5` — rate-limit event recorded
- `LOGIN_SCORE_CSRF_FAIL = 2` — reserved for future; add to csrf_verify() log path in a follow-up

Threshold: **`LOGIN_SCORE_BLACKLIST_THRESHOLD = 15`** → `auth_auto_blacklist($con, $ip)`.

New log call for unknown-user fails (auth.php, lookup miss path):
```
appendLog($con, 'login', "Login failed for unknown user: {$user}");
```
This is what makes unknown-user fails scorable. Response text stays generic.

## Per-user lockout

- New constant `USER_LOCKOUT_THRESHOLD = 10`.
- In `auth_login()`, after the user lookup but before bcrypt verify: if `invalidLogins >= 10`, log the refusal and return `['ok' => false, 'error' => 'Konto gesperrt. Bitte wenden Sie sich an einen Administrator.']`.
- Trigger the admin notification email **once** at the transition (i.e. on the increment that crosses the threshold, not on every attempt after).
- Does NOT set `disabled=1` — that flag stays admin-driven.

## Admin notifications

Two new mail templates in `auth/templates/email/` (Markdown, same renderer as invite/reset):

1. **`user_lockout_notice.md`** — fired on invalidLogins crossing the threshold. Contents: username, email, threshold reached, last-IP, time, link to admin users tab.
2. **`blacklist_notice.md`** — fired from `auth_auto_blacklist()` when triggered by the scoring rule (not on manual or library-direct calls). Contents: IP, reason, score snapshot, distinct usernames touched, time, link to admin log tab filtered by IP.

Recipients for both:
```sql
SELECT email, username FROM auth_accounts
WHERE rights = 'Admin' AND disabled = 0 AND activation_code = 'activated'
```

Helper: `mail_send_admin_notice(string $template, array $vars): int` in `auth/src/mail_helpers.php`, returns count of successful sends.

## admin_reset_password bundling

Extend `admin_reset_password()` (`auth/src/admin.php:176`) to, inside the same flow:

1. Issue invite token + send reset mail (existing behaviour).
2. Clear `invalidLogins` for the target user (un-lock).
3. Collect distinct IPs from `auth_log` login-fail entries for the target user in the last 24 h.
4. For each such IP present in `auth_blacklist` with `auto = 1`, DELETE the blacklist row (never touch `auto = 0` manual entries).
5. Log the composite action: `"Password reset + invalidLogins cleared + unblocked N IPs for user #$id"`.
6. Return `['ok' => bool, 'unblocked_ips' => list<string>]` so the admin UI can show which IPs were lifted.

## Reset-password confirmation modal

Chrome-side change (`erikr/chrome`) to the Users tab: Reset-Password button opens a confirmation modal before firing. Modal body shows:

- Target username + email
- "Hiermit wird: 1) Eine neue Passwort-Reset-E-Mail versendet 2) Der Fehlversuche-Zähler auf 0 gesetzt 3) Folgende IPs aus der Blacklist entfernt: {list or 'keine'}"
- Buttons: "Bestätigen" (`.btn-outline-success`) / "Abbrechen" (neutral `.btn`)

The IP list is computed by a new read-only action `admin_user_reset_preview` returning the same IP set the mutation would unblock, so the modal isn't stale.

## Schema / migrations

None required — `auth_blacklist` and `auth_log` already support the new logic.

## Files expected to change

**auth/**
- `src/auth.php` — new constants, progressive delay, score computation, unknown-user logging, per-user lockout gate, integration with auth_auto_blacklist
- `src/admin.php` — bundled `admin_reset_password()`, new `admin_user_reset_preview()` data helper, new `admin_user_unblacklist_ip()` helper if needed
- `src/mail_helpers.php` — new `mail_send_admin_notice()`
- `templates/email/blacklist_notice.md` (new)
- `templates/email/user_lockout_notice.md` (new)
- `tests/Unit/` — new unit tests for score computation, threshold edge cases, unlock resolver
- `CLAUDE.md` — document the new constants and layered model

**chrome/** (companion commit — can be a separate task if preferred)
- `src/Admin/UsersTab.php` — Reset-Password button triggers modal instead of firing immediately
- `src/Admin/UserModals.php` — new `resetPasswordModal` rendered alongside create/edit
- `src/Admin/Dispatch.php` — new `admin_user_reset_preview` action; existing `admin_user_reset` accepts the confirmed POST and returns `unblocked_ips` for a toast
- `css_library/js/admin.js` — wire the preview → modal → confirm flow

## Out of scope

- Rewriting the per-IP rate-limit file-lock mechanism. It continues to generate `+5` strike entries; that's the integration point.
- CAPTCHA or similar human-verification fallbacks.
- Automated admin-notification batching / de-duplication (one email per trigger is fine at current scale).
- CSRF-fail scoring wiring — the constant is reserved but the `csrf_verify()` log hook lands in a follow-up task to keep this one bounded.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 Progressive per-IP delay (capped at 30s) applied from 2nd fail onwards; successful logins are not delayed
- [x] #2 auth_login gates on invalidLogins >= USER_LOCKOUT_THRESHOLD and returns 'Konto gesperrt…' without running bcrypt
- [x] #3 Shenanigans score computed on-the-fly from auth_log (existing-user +1, unknown-user +3, rate-limit +5) and triggers auth_auto_blacklist at threshold 15
- [x] #4 Unknown-username fail is logged to auth_log so it becomes scorable; response remains generic
- [x] #5 admin_reset_password clears invalidLogins, deletes auto=1 blacklist rows for IPs the target user failed from in last 24h, leaves auto=0 rows alone
- [x] #6 admin_reset_password returns unblocked_ips so the UI can surface them
- [x] #7 New admin_user_reset_preview action returns the same IP set without mutating state
- [x] #8 Chrome Reset-Password button opens confirmation modal listing username/email/IPs-to-be-unblocked before firing
- [x] #9 mail_send_admin_notice() resolves recipients from auth_accounts WHERE rights='Admin' AND disabled=0 AND activation_code='activated' and sends the specified template
- [x] #10 user_lockout_notice.md fires exactly once per threshold crossing (not on subsequent locked attempts)
- [x] #11 blacklist_notice.md fires from auth_auto_blacklist() only for score-triggered blocks, not manual or library-direct calls
- [x] #12 New constants documented in auth/CLAUDE.md; tuneable without touching call sites
- [x] #13 PHPUnit: score computation, threshold edge cases (9/10/11 invalidLogins), unblock resolver scope (24h window, auto=1 only), mail recipient query
<!-- AC:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
All 6 plan tasks implemented and reviewed via subagent-driven development. 138 unit tests passing.

Post-completion regression fix: TASK-2's auto-IP-blacklisting caused users whose IP was blocked during a brute-force attempt to be unable to log in even after resetting their password via the email link. Fixed by adding `auth_clear_auto_blacklist_ip(mysqli $con, string $ip): void` to the library (removes `auto=1` rows only) and calling it from `executeReset.php` in all 5 consumer apps (wlmonitor, Energie, suche, zeiterfassung, simplechat). Email link access proves identity — symmetric with what `admin_reset_password()` already does via `_admin_unblock_ips_for_user()`.

Also fixed stale `assertFalse($bool)` assertion in wlmonitor's `AdminTest.php` (admin_reset_password now returns array).
<!-- SECTION:FINAL_SUMMARY:END -->
