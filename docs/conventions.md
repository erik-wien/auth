# Conventions — erikr/auth

Rules for extending the library and for consuming it from apps (login / session / CSRF / admin / invite / reset / remember-me flows, auth-adjacent migrations, anything that touches `auth_accounts`, `auth_log`, `auth_blacklist`, `auth_invite_tokens`, `password_resets`, `auth_remember_tokens`).

## 1. Never reimplement

Login, session fixation prevention, CSRF, rate limiting, bcrypt (cost 13), invite tokens, password reset tokens, TOTP, remember-me — all of it lives in this library. Consumer apps **call** the library; they do not copy its patterns into project code.

Forbidden in consumer code:
- `password_hash($x, PASSWORD_BCRYPT, ['cost' => 13])` — use `auth_login()` / `invite_complete()`.
- Manual `session_regenerate_id()` — handled by `auth_bootstrap()` / `auth_login()`.
- Inline CSRF token generation — use `csrf_token()`, `csrf_input()`, `csrf_verify()`.
- Reading `$_SERVER['REMOTE_ADDR']` for security decisions — use `getUserIpAddr()` (REMOTE_ADDR only, no proxy headers — spoof-resistant).

If you find yourself writing auth logic in a consumer app, stop and add it to the library instead.

## 2. CSRF on every state-changing POST

Every POST that mutates state calls `csrf_verify()` and aborts on failure.

- Forms: redirect with a flash alert.
- XHR / JSON endpoints: return `{ok: false, error: 'csrf'}` with HTTP 403.
- **Logout is POST + CSRF**, never a plain `<a href="logout.php">` — CSRF-logout is a real class of attack.

## 3. Session fields are fixed

The library sets these on login: `loggedin`, `sId`, `id`, `username`, `email`, `img`, `img_type`, `has_avatar`, `disabled`, `rights`, `theme`. Consumer apps **read** them; they do not invent new top-level `$_SESSION` keys for persistent state. If you need to persist user state across requests, it belongs in a DB table, not the session.

Short-lived, request-scoped scratch data (flash alerts, one-shot tokens) is fine.

## 4. Schema extension: never touch `auth_accounts`

Do not add app-specific columns to `auth_accounts`. The table has a fixed shape that every consumer depends on; widening it for one app's preferences creates a change that ripples through every other app's queries and deploys.

Instead: create a scoped table joined on `id`.

- Name it with a 2-letter app prefix (`en_preferences`, `wl_preferences`, `s_feeds`, `s_buttons`).
- Same DB as the app, not the shared auth DB (keeps app DB users scoped to their own DB).
- `UNIQUE KEY uk_user (user_id)` if it's 1:1.
- One app owns one prefix; prefixes don't mix.

## 5. FK cascade discipline

Every table with a `user_id` column pointing at `auth_accounts(id)` must either:

**(a) Same-DB (lives in the shared auth DB):** declare `FOREIGN KEY (user_id) REFERENCES auth_accounts(id) ON DELETE CASCADE` in the creating migration. No exceptions except `auth_log` (audit trail intentionally survives user deletion — document inline when this applies).

**(b) Cross-DB (lives in the app's own DB):** register a cleanup hook with `admin_register_delete_cleanup(callable)` at bootstrap. `admin_delete_user()` invokes all registered cleanups inside the DELETE transaction, before removing the account row. Do **not** use cross-database FKs — they couple `REFERENCES` privileges across DB users and complicate dumps/restores.

A `user_id` column without either mechanism is a bug. Dead reset tokens, orphan sessions, and zombie preferences are security-adjacent — fix them at schema time, not with cron cleanup.

## 6. Migrations

- New files go in `db/NN_*.sql` with sequential numbering, zero-padded to two digits.
- Start with `USE <db>;` — explicit, so copy-paste into the wrong shell doesn't land in the wrong database.
- Idempotent where feasible (`CREATE TABLE IF NOT EXISTS`, `ALTER TABLE ... ADD COLUMN IF NOT EXISTS`). When not feasible (e.g. `ADD CONSTRAINT`), document in a header comment how to re-run safely.
- Include the FK constraint in the creating migration, not as a later retrofit.
- Document which DB the migration targets in the header comment (shared auth DB vs. app DB).

Migrations are deployed manually, not by an automated migrator. Order matters and each file must be self-explanatory a year from now.

## 7. User deletion goes through the library

Consumer apps never run `DELETE FROM auth_accounts WHERE id = ?` directly. Always call `admin_delete_user($con, $targetId, $requestingUserId)`. It:

- Refuses to delete `$requestingUserId` (prevents admin self-delete).
- Invokes cleanup hooks (rule §5(b)) before the DELETE.
- Logs the deletion to `auth_log`.
- Returns `bool` — `true` on success, `false` if the row didn't exist or the delete was refused.

Register a cleanup hook if you need app-specific cleanup at deletion time — don't wrap the call with bespoke pre-delete logic in every app.

## 8. Database user privileges

Each app connects to the shared auth DB with its own DB user. Privileges are enumerated in the deploy-config repo's `scripts/grant-db-users.sql`.

- An app's DB user gets the **minimum** privileges it needs: typically `SELECT, INSERT, UPDATE` on most tables, plus `DELETE` where the app legitimately deletes (e.g. `DELETE` on `password_resets` after consumption).
- No app gets `DELETE` on `auth_accounts` — deletion flows through `admin_delete_user()` using a connection that the library controls via its own grants.
- When adding a new auth-DB table, update `grant-db-users.sql` in the same change that adds the migration. Missing grants produce runtime 1142 errors in production that are hard to attribute.
