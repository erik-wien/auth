---
id: TASK-1
title: 'Admin: "Login as another user" (impersonation)'
status: Done
assignee: []
created_date: '2026-04-18 09:56'
updated_date: '2026-04-21 05:41'
labels: []
dependencies: []
priority: high
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Add an admin-only impersonation feature so a user with `rights = Admin` can temporarily act as another account (diagnose a user's view, reproduce a bug against their data, walk someone through an unfamiliar screen) without knowing their password. A one-click "Return to admin" restores the original session.

## Why it belongs in the library, not per-app

Session shape, CSRF, admin-guarding and `auth_log` are all library-owned (see `~/.claude/rules/auth-rules.md` §1, §3, §7). Impersonation reads and writes every one of those — it must ship in `erikr/auth` and all consuming apps get it for free.

## Library API (add to `src/admin.php`)

```php
/**
 * Start impersonating $targetId. Caller must be an Admin (enforce via admin_require()
 * at the consumer endpoint — the lib function assumes it).
 *
 * - Refuses if $targetId === current $_SESSION['id'] (no-op).
 * - Refuses if target row missing or disabled.
 * - Refuses when target has rights='Admin' (admin→admin is always blocked).
 * - Stashes original session fields under $_SESSION['impersonator'].
 * - Replaces user-identity session fields with the target's (id, username, email,
 *   img, img_type, has_avatar, disabled, debug, rights, theme).
 * - session_regenerate_id(true) (fixation prevention, mirrors auth_login).
 * - Does NOT issue a remember-me cookie.
 * - Inherits the admin's 2FA-complete state (no re-prompt).
 * - Logs to auth_log context='admin' with message
 *   "Admin #A (alice) began impersonating user #T (bob)."
 */
function admin_impersonate_begin(mysqli $con, int $targetId): bool;

/**
 * End impersonation: restore stashed fields, clear $_SESSION['impersonator'],
 * session_regenerate_id(true), log the exit. No-op + returns false if no stash.
 */
function admin_impersonate_end(mysqli $con): bool;

/** True iff the current session is an impersonated session. */
function admin_is_impersonating(): bool;

/** When impersonating, return the original admin's id/username for display. */
function admin_impersonator_info(): ?array; // ['id' => int, 'username' => string] | null
```

## appendLog() behaviour during impersonation — composite "Admin:User" display

Log entries written during impersonation must attribute to **both** identities so the audit trail is unambiguous. Required display in the admin Log tab's **Benutzer** column:

```
Erik:Ossi
```

— where `Erik` is the real admin's username (impersonator) and `Ossi` is the impersonated target's username. Regular (non-impersonated) entries keep displaying the single username as today.

### Schema change

Add a nullable column to `auth_log`:

```sql
ALTER TABLE auth_log
  ADD COLUMN impersonator_id INT UNSIGNED NULL AFTER idUser,
  ADD INDEX idx_auth_log_impersonator (impersonator_id);
```

- `NULL` for normal log rows; filled with the real admin's id when `admin_is_impersonating()`.
- No FK to `auth_accounts(id)` — audit trail must survive admin deletion (same reasoning as `idUser` on this table per auth-rules §5).
- Ships as `db/NN_auth_log_impersonator.sql` (`USE auth;`, `ADD COLUMN IF NOT EXISTS`, header comment documenting target DB).
- Grants update: `grant-db-users.sql` — no change needed (existing `SELECT, INSERT` on `auth_log` covers the new column).

### appendLog() change

```php
function appendLog(mysqli $con, string $context, string $activity, string $origin = ''): bool
{
    // ...
    $id            = $_SESSION['id'] ?? 0;
    $impersonator  = $_SESSION['impersonator']['id'] ?? null;   // NULL when not impersonating
    // bind + insert including impersonator_id
}
```

### Log-list rendering

`admin_log_list()` (Energie's `inc/admin_log.php` is the canonical shape) JOINs `auth_accounts` twice: once on `idUser` (target), once on `impersonator_id` (admin). The **Benutzer** column renders:

- `impersonator_id IS NULL` → `target.username` (unchanged)
- `impersonator_id IS NOT NULL` → `admin.username . ':' . target.username` (e.g. `Erik:Ossi`)

Filter box "Benutzer" matches against either side of the composite so searching `Erik` finds impersonated rows and searching `Ossi` also finds them.

## Consumer wiring (admin screens in every app)

Per `~/.claude/rules/ui-design-rules.md` §15, admin user-row actions are modals/buttons calling `api.php?action=admin_<verb>`. Add:

- New row action button **"Anmelden als"** (neutral `.btn.btn-sm`, not destructive) — only rendered when target id ≠ current admin id AND target rights ≠ 'Admin' AND target not disabled.
- Endpoint `api.php?action=admin_impersonate_begin` (POST + CSRF) → calls lib, returns `{ok: true, redirect: '/'}`, JS redirects.
- New top-level endpoint `api.php?action=admin_impersonate_end` (POST + CSRF) so the "Return to admin" link in the chrome header works everywhere.

## Chrome header integration (`~/Git/chrome`)

- When `admin_is_impersonating()` is true, the header shows a persistent banner strip under the fixed header: `.impersonation-banner` with text `"Angemeldet als <target> (Admin: <admin>) — Zurück zum Admin"` where the last segment is a POST form button.
- Colour: warning amber (`--color-warning` or literal `#b45309` on dark) — visible enough that an admin doesn't forget they're impersonating.
- Banner height added to the body padding-top calc (so content doesn't slide under it). Shell pages (§12a) add the banner height to `inset top`.

## Security notes

- Admin→Admin is always blocked (hard refusal, no override).
- No remember-me issued during impersonation; existing admin remember cookie untouched. If the PHP session expires mid-impersonation and remember-me auto-restores, the user comes back as themselves (admin) — acceptable fail-safe.
- CSRF on both begin and end endpoints — without it, a phishing admin could be tricked into impersonating a target of the attacker's choice.
- `admin_is_impersonating()` check belongs in any future sensitive operation that should be locked during impersonation (e.g. password change, invite creation, user deletion). For v1, don't block these — the `impersonator_id` in `auth_log` makes them traceable.

## What's NOT in scope

- Nested impersonation (admin A → user B → user C) — refuse in v1.
- Time-limit on impersonation sessions — none for v1; ends on explicit exit or session expiry.
- Making non-admin roles ("HelpDesk" etc.) impersonate — only 'Admin' in v1.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [ ] #1 `admin_impersonate_begin(mysqli, int)`, `admin_impersonate_end(mysqli)`, `admin_is_impersonating()`, `admin_impersonator_info()` implemented in src/admin.php
- [ ] #2 Self-impersonation, disabled target, missing target, and admin→admin all refused with a falsy return (v1 policy)
- [ ] #3 Session regenerated on both begin and end (mirrors auth_login session-fixation behaviour)
- [ ] #4 Original admin identity stashed under $_SESSION['impersonator'] and fully restored on end
- [ ] #5 No remember-me cookie is issued during impersonation
- [ ] #6 Migration `db/NN_auth_log_impersonator.sql` adds nullable `impersonator_id` column (+ index) to `auth_log`, starts with `USE auth;`, idempotent via `ADD COLUMN IF NOT EXISTS`
- [ ] #7 appendLog() populates `impersonator_id` from `$_SESSION['impersonator']['id']` when present, NULL otherwise
- [ ] #8 admin Log tab renders the Benutzer column as `admin:target` (e.g. `Erik:Ossi`) when `impersonator_id` is set, and plain `target` otherwise; the Benutzer filter matches either side of the composite
- [ ] #9 Both begin and end endpoints on at least one consumer app (wlmonitor as reference) are POST + CSRF and log entries appear in the Log tab
- [ ] #10 Chrome header renders warning banner with "Zurück zum Admin" POST form when impersonating, in every consuming app
- [ ] #11 Admin user-list row shows "Anmelden als" button only for non-self, non-admin, non-disabled rows
- [ ] #12 Unit tests cover: self-block, admin→admin block, disabled-target block, begin+end roundtrip restores original session, appendLog binds `impersonator_id` while impersonating
- [ ] #13 Integration test covers the full DB-backed begin/end roundtrip and asserts `auth_log.impersonator_id` is populated on impersonated entries and NULL otherwise
<!-- AC:END -->
