# erikr/auth — API Reference

All functions are global (loaded via Composer `autoload.files`). No classes, no namespaces.

---

## Bootstrap

### `auth_bootstrap(array $cspExtras = []): void`

Call once per request, after defining constants and opening `$con`, before any output.

- Emits security headers: `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy`, `Content-Security-Policy`, `Strict-Transport-Security` (HTTPS only).
- Sets `global $_cspNonce` — a base64-encoded 16-byte random value for use in `<script nonce="...">` tags.
- Starts PHP session with hardened cookie options (`HttpOnly`, `Strict` SameSite, `Secure` on HTTPS).
- Restores a previous session from the `sId` cookie if the current session has no `sId` set.

**`$cspExtras`** — optional per-project CSP source additions, keyed by directive name:
```php
auth_bootstrap([
    'script-src' => 'https://cdn.jsdelivr.net',
    'font-src'   => 'https://fonts.gstatic.com',
]);
```
Sources are appended to the base directive. Multiple sources: space-separate within the value string.

---

## Authentication

### `auth_login(mysqli $con, string $username, string $password): array`

Authenticates a user. Returns:
- `['ok' => true, 'username' => string]` on success
- `['ok' => false, 'error' => string]` on failure (German-language user-facing message)

**On success:**
- Regenerates session ID (prevents session fixation)
- Populates `$_SESSION` (see specs.md for full field list)
- Sets `sId` cookie (4-day, HttpOnly, Strict SameSite)
- Sets `theme` cookie (1-year, readable by JS)
- Updates `lastLogin`, resets `invalidLogins` in DB
- Clears IP rate-limit counter
- Writes login entry to auth_log

**On failure:**
- Increments rate-limit counter for the IP
- Increments `invalidLogins` in DB (wrong password only)
- Returns a generic error (same message for bad username vs bad password)

**Rate limit:** blocks after 5 failures within 15 minutes. Returns an error without a DB query when blocked.

**Rehash:** transparently upgrades password to bcrypt cost-13 if needed.

---

### `auth_logout(mysqli $con): void`

Logs the logout to auth_log, calls `session_destroy()`, expires the `sId` cookie.

---

## Auth gate

### `auth_require(): void`

Redirects to `login.php` (relative to the app base path) if `$_SESSION['loggedin']` is not set. Call at the top of any protected page.

The base path is derived from `SCRIPT_NAME` — e.g. a script at `/energie/daily.php` redirects to `/energie/login.php`.

---

## CSRF

### `csrf_token(): string`

Returns the session CSRF token, generating it (32 random bytes as hex) on first call.

### `csrf_input(): string`

Returns a complete `<input type="hidden" name="csrf_token" value="...">` element, HTML-escaped. Embed in every POST form:
```php
<form method="post" action="logout.php">
    <?= csrf_input() ?>
    <button type="submit">Logout</button>
</form>
```

### `csrf_verify(): bool`

Returns `true` if the submitted token matches the session token (via `hash_equals()`). Checks `$_POST['csrf_token']` first, then `$_SERVER['HTTP_X_CSRF_TOKEN']`. Returns `false` if no session token exists.

Use at the top of every POST handler:
```php
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
    header('Location: index.php'); exit;
}
```

---

## Logging

### `appendLog(mysqli $con, string $context, string $activity, string $origin = 'web'): bool`

Inserts a row into `auth_log`. Uses `$_SESSION['id']` (defaults to 0 for unauthenticated requests). IP stored via `INET_ATON()`.

- `$context` — short code identifying the action type, e.g. `'auth'`, `'npw'`, `'reg'`
- `$activity` — free-text description
- `$origin` — `'web'` or `'api'`

Returns `true` on success, `false` if `prepare()` failed or `execute()` failed.

### `addAlert(string $type, string $message): void`

Queues a UI alert in `$_SESSION['alerts']` as `[$type, htmlentities($message)]`. Consumer templates iterate this array to render Bootstrap-style alerts. Standard types: `'success'`, `'danger'`, `'info'`, `'notice'`.

### `logDebug(string $label, string $message): void`

Calls `appendLog()` only when `$_SESSION['debug']` is truthy. Uses the global `$con`. No-op otherwise.

### `getUserIpAddr(): string`

Returns `$_SERVER['REMOTE_ADDR']`. Used internally by rate limiting; exposed for consumer use.

---

## IP Blacklist

### `auth_is_blacklisted(mysqli $con, string $ip): bool`

Returns `true` if the IP exists in `auth_blacklist` with no expiry or a future expiry. Returns `false` on DB error (fail open — don't block on DB failure). Called automatically at the start of `auth_login()`.

### `auth_blacklist_ip(mysqli $con, string $ip, string $reason = '', ?int $expiresIn = null): void`

Manually blacklist an IP. `$expiresIn` is seconds until expiry; `null` = permanent. Overwrites any existing entry (including auto-blocked ones) via `INSERT … ON DUPLICATE KEY UPDATE`. Writes to auth_log.

### `auth_unblacklist_ip(mysqli $con, string $ip): void`

Remove an IP from the blacklist. Writes to auth_log.

### `auth_auto_blacklist(mysqli $con, string $ip): void`

Called internally by `auth_login()` when the IP's strike count reaches `BLACKLIST_AUTO_STRIKES`. Uses `INSERT IGNORE` — does not override a manual entry. Writes to auth_log. Not intended for direct consumer use.

### `auth_record_rl_strike(string $ip): int`

Increments the rate-limit strike counter for an IP in `RATE_LIMIT_FILE` (key: `"rl_strikes:<ip>"`). Returns the new strike count. Called internally by `auth_login()` on each rate-limited attempt. Not reset by `auth_clear_failures()` — use `auth_clear_rl_strikes()` to reset.

### `auth_clear_rl_strikes(string $ip): void`

Resets the strike counter for an IP to zero. Called by `auth_login()` on successful login, alongside `auth_clear_failures()`. This means a successful login resets both the per-window failure counter and the permanent strike counter.

---

## Rate limiting

### `auth_is_rate_limited(string $ip): bool`

Returns `true` if the IP has reached `RATE_LIMIT_MAX` (5) failures within `RATE_LIMIT_WINDOW` (900 s). Used internally by `auth_login()`.

### `auth_record_failure(string $ip): void`

Increments the failure counter for an IP. Used internally by `auth_login()`.

### `auth_clear_failures(string $ip): void`

Removes the IP entry from the rate-limit store. Called by `auth_login()` on success.

### `rate_limit_check(string $key, int $max = 3, int $window = 900): bool`

General-purpose rate limit check. Key is any string — use namespaced keys like `"reset:{$ip}"` to avoid collisions with login rate limiting.

### `rate_limit_record(string $key, int $window = 900): void`

Record an attempt for a general-purpose key.

### `rate_limit_clear(string $key): void`

Clear a general-purpose rate-limit key.

---

## Mail

### `send_mail(string $toAddress, string $toName, string $subject, string $bodyHtml, string $bodyText): void`

Sends an email via SMTP (STARTTLS). Requires `SMTP_HOST`, `SMTP_PORT`, `SMTP_USER`, `SMTP_PASS`, `SMTP_FROM`, `SMTP_FROM_NAME` constants.

Throws `PHPMailer\PHPMailer\Exception` on failure — catch it in the caller.
