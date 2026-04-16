# CLAUDE.md — erikr/auth

Shared PHP authentication library. Consumed by all apps via Composer (`"erikr/auth": "*"`
with a path repository pointing at this directory). Changes here affect every app immediately
on the next `composer update` in each consumer.

Do not add app-specific logic here. If a behaviour is needed in only one app, it belongs
in that app.

---

## Consumer setup (what every app must define before autoload)

```php
define('AUTH_DB_PREFIX', 'jardyx_auth.');   // or '' when connected directly to the auth DB
define('RATE_LIMIT_FILE', __DIR__ . '/../data/ratelimit.json');  // writable by web server
// SMTP_HOST, SMTP_PORT, SMTP_USER, SMTP_PASS, SMTP_FROM, SMTP_FROM_NAME
```

`$con` must be an open `mysqli` connection to the auth database, available as a global when
`appendLog()` / `auth_require()` are called without an explicit connection argument.

---

## Public API

### bootstrap.php — call once per request, before any output

| Function | Signature | Effect |
|---|---|---|
| `auth_bootstrap` | `(array $cspExtras = []): void` | Emits security headers (CSP with nonce, HSTS, X-Frame-Options, …), starts hardened session, handles `sId` cookie recovery. Sets global `$_cspNonce`. |

`$cspExtras` keys are CSP directives; values are extra sources appended to the base policy.
Example: `['script-src' => 'https://cdn.jsdelivr.net']`.

### auth.php — login, logout, rate limiting, blacklist

| Function | Signature | Returns |
|---|---|---|
| `auth_login` | `(mysqli $con, string $username, string $password): array` | `['ok'=>true, 'username'=>…]` or `['ok'=>false, 'error'=>…]` |
| `auth_logout` | `(mysqli $con): void` | Logs out, destroys session, expires `sId` cookie |
| `rate_limit_check` | `(string $key, int $max=3, int $window=900): bool` | `true` if over limit |
| `rate_limit_record` | `(string $key, int $window=900): void` | Increments counter |
| `rate_limit_clear` | `(string $key): void` | Resets counter |
| `auth_is_blacklisted` | `(mysqli $con, string $ip): bool` | Checks active blacklist entry |
| `auth_blacklist_ip` | `(mysqli $con, string $ip, string $reason='', ?int $expiresIn=null): void` | Manual blacklist |
| `auth_unblacklist_ip` | `(mysqli $con, string $ip): void` | Remove from blacklist |

`auth_login` handles: blacklist check → rate-limit check → bcrypt verify → cost-13 rehash →
session fixation prevention → theme cookie sync → `auth_log` entry. All in one call.

### log.php — logging, alerts, auth gate

| Function | Signature | Notes |
|---|---|---|
| `auth_require` | `(): void` | Redirects to `login.php` if not logged in. Derives base path from `SCRIPT_NAME`. |
| `appendLog` | `(mysqli $con, string $context, string $activity, string $origin='web'): bool` | Inserts into `auth_log`. |
| `addAlert` | `(string $type, string $message): void` | Queues a UI alert in `$_SESSION['alerts']`. |
| `getUserIpAddr` | `(): string` | Returns `REMOTE_ADDR` only (no proxy headers — prevents spoofing). |
| `logDebug` | `(string $label, string $message): void` | Logs only when `$_SESSION['debug']` is set. |

### csrf.php — CSRF protection

| Function | Signature | Notes |
|---|---|---|
| `csrf_token` | `(): string` | Generates once per session, returns same token thereafter. |
| `csrf_input` | `(): string` | Renders `<input type="hidden" name="csrf_token" value="…">`. |
| `csrf_verify` | `(): bool` | Checks `$_POST['csrf_token']` or `X-CSRF-TOKEN` header against session token. |

Every state-changing POST endpoint must call `csrf_verify()` and abort if false.
Logout must be POST + CSRF — never a plain `<a>` link.

### invite.php — invitation flow

| Function | Signature | Notes |
|---|---|---|
| `invite_create_token` | `(mysqli $con, int $userId): string` | 48-hour token, REPLACE INTO (re-invite invalidates old token). |
| `invite_send_email` | `(string $email, string $username, string $token, string $baseUrl): bool` | Sends "Set your password" email via `send_mail()`. |
| `invite_verify_token` | `(mysqli $con, string $token): ?int` | Returns `user_id` or `null` if expired/invalid. |
| `invite_complete` | `(mysqli $con, int $userId, string $password): bool` | Sets bcrypt-13 hash, enables account, deletes token. |

### admin.php — user administration

| Function | Signature | Notes |
|---|---|---|
| `admin_require` | `(): void` | Redirects non-admins to `index.php`. Checks `$_SESSION['rights'] === 'Admin'`. |
| `admin_list_users` | `(mysqli $con, int $page=1, int $perPage=25, string $filter=''): array` | Paginated user list. Apps join their own preference tables by `id`. |
| `admin_toggle_rights` | `(mysqli $con, int $userId, string $rights): void` | Sets `rights` to `'Admin'` or `'User'`. |
| `admin_toggle_disabled` | `(mysqli $con, int $userId, bool $disabled): void` | Enables/disables account. |
| `admin_reset_password` | `(mysqli $con, int $userId, string $baseUrl): bool` | Creates invite token and sends email. |
| `admin_delete_user` | `(mysqli $con, int $targetId, int $requestingUserId): bool` | Permanently deletes an account. Invokes registered cleanup hooks first. Refuses self-delete. |
| `admin_register_delete_cleanup` | `(callable $fn): void` | Register `fn($con, $userId): void` to run before `admin_delete_user()`'s DELETE. For cross-DB tables that cannot use ON DELETE CASCADE. Hook failures are logged, not raised. |

### avatar.php — avatar storage

| Function | Signature | Notes |
|---|---|---|
| `auth_avatar_store` | `(mysqli $con, int $userId, string $jpegBytes): void` | Stores a pre-processed 205×205 JPEG blob. Caller is responsible for image processing — this function does not decode or resize. Updates `$_SESSION['has_avatar']`. |
| `auth_avatar_clear` | `(mysqli $con, int $userId): void` | Sets `img_blob` to NULL. Updates `$_SESSION['has_avatar']` if clearing the current user. |

Avatars live on `auth_accounts.img_blob` (MEDIUMBLOB). The schema canonicalises
to 205×205 JPEG — there is no MIME column, no filename, no size tracking. The
chrome library owns upload processing (GD resize, validation); this file owns
the schema side.

### mailer.php — SMTP

| Function | Signature | Notes |
|---|---|---|
| `send_mail` | `(string $toAddress, string $toName, string $subject, string $bodyHtml, string $bodyText): void` | Throws `MailerException` on failure. Requires SMTP_* constants. |

---

## DB tables

All table references use `AUTH_DB_PREFIX` (e.g. `jardyx_auth.auth_accounts`).

| Table | Used by | Purpose |
|---|---|---|
| `auth_accounts` | auth, admin, invite, avatar | Users — id, username, email, password (bcrypt-13), rights (Admin/User), disabled, debug, theme, img_blob (205×205 JPEG) |
| `auth_log` | log | Activity log — context, activity, origin, IP (INET_ATON), logTime |
| `auth_blacklist` | auth | IP blocks — ip, reason, auto, blocked_at, expires_at (null = permanent) |
| `auth_invite_tokens` | invite | Pending invitations — user_id, token, expires_at |
| `password_resets` | consumer apps | Forgot-password tokens — user_id, token, expires_at, used |

`wl_sessions` exists in the DB but is not used by the library.

---

## Session fields set on login

`loggedin`, `sId`, `id`, `username`, `email`, `has_avatar`,
`disabled`, `debug`, `rights`, `theme`.

`has_avatar` is derived from `img_blob IS NOT NULL` at login time. The blob
itself is never loaded into the session — serve it via `Avatar::serve()` in
chrome, or fetch on demand.

---

## What NOT to do in consumer apps

- Do not reimplement login, session, CSRF, or rate-limiting logic.
- Do not read `REMOTE_ADDR` directly for security checks — use `getUserIpAddr()`.
- Do not modify auth table schema without updating every consumer and this CLAUDE.md.
- Do not add app-specific columns to `auth_accounts` — join a separate app table on `id`.
