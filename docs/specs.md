# erikr/auth — Specification

Shared Composer library providing auth, session bootstrap, CSRF protection, activity logging, and SMTP mail for PHP 8.2 web applications that share a common user database.

## Scope

The library owns:
- Session lifecycle (start, harden, recover via `sId` cookie)
- Security headers (CSP with nonce, HSTS, X-Frame-Options, etc.)
- Login / logout / session fixation prevention
- Password hashing (bcrypt cost-13, transparent rehash on login)
- IP-based login rate limiting (file-locked JSON store)
- General-purpose keyed rate limiting
- CSRF token generation and verification
- Activity logging to `auth_log`
- SMTP email sending

The library does **not** own:
- Application-specific preferences (wl_preferences, en_preferences)
- Avatar upload / storage logic (consumer project handles files; library stores blob + MIME in auth_accounts)
- Password reset UI or email templates
- User registration flows

## Database

### auth_accounts

Owned by the shared auth database (`jardyx_auth` on this host).

| Column          | Type                | Notes                                      |
|-----------------|---------------------|--------------------------------------------|
| id              | INT UNSIGNED PK AI  |                                            |
| username        | VARCHAR             | unique, login identifier                   |
| email           | VARCHAR             |                                            |
| password        | VARCHAR             | bcrypt hash, cost 13                       |
| activation_code | VARCHAR             | must equal `'activated'` to permit login   |
| disabled        | TINYINT             | 1 = account locked                         |
| invalidLogins   | INT                 | incremented on failed attempts             |
| lastLogin       | DATETIME            | updated on successful login                |
| img             | VARCHAR             | avatar filename or identifier (legacy)     |
| img_type        | VARCHAR             | MIME type of stored avatar blob            |
| img_blob        | MEDIUMBLOB          | raw avatar image bytes                     |
| debug           | TINYINT             | enables logDebug() calls for this user     |
| rights          | VARCHAR             | application-defined role string            |
| theme           | VARCHAR             | `'light'`, `'dark'`, or `'auto'`           |

### auth_log

| Column   | Type           | Notes                              |
|----------|----------------|------------------------------------|
| id       | INT UNSIGNED   | PK AI                              |
| idUser   | INT UNSIGNED   | 0 for anonymous/system entries     |
| context  | VARCHAR        | short code, e.g. `'auth'`, `'npw'`|
| activity | VARCHAR        | free-text description              |
| origin   | VARCHAR        | `'web'` or `'api'`                 |
| ipAdress | INT UNSIGNED   | stored via `INET_ATON()`           |
| logTime  | TIMESTAMP      | `CURRENT_TIMESTAMP` on insert      |

## Constants required from the consumer project

These must be defined **before** `vendor/autoload.php` is included, or at latest before any library function is called.

| Constant          | Type   | Description                                                                 |
|-------------------|--------|-----------------------------------------------------------------------------|
| `AUTH_DB_PREFIX`  | string | Schema prefix for auth tables. `'jardyx_auth.'` (cross-DB) or `''` (direct)|
| `RATE_LIMIT_FILE` | string | Absolute path to a writable JSON file for rate-limit state                  |
| `SMTP_HOST`       | string | SMTP server hostname                                                        |
| `SMTP_PORT`       | int    | SMTP port (typically 587 for STARTTLS)                                      |
| `SMTP_USER`       | string | SMTP username                                                               |
| `SMTP_PASS`       | string | SMTP password                                                               |
| `SMTP_FROM`       | string | Sender email address                                                        |
| `SMTP_FROM_NAME`  | string | Sender display name                                                         |

SMTP constants are only required if `send_mail()` is called.

## AUTH_DB_PREFIX convention

| Consumer connects to | AUTH_DB_PREFIX        | Queries look like                     |
|----------------------|-----------------------|---------------------------------------|
| auth DB directly     | `''`                  | `auth_accounts`, `auth_log`           |
| application DB       | `'jardyx_auth.'`      | `jardyx_auth.auth_accounts`           |

The cross-DB form requires that the MySQL user has SELECT/INSERT/UPDATE on `jardyx_auth.*` from the application DB connection.

## Session fields set on login

| Key          | Type    | Source                              |
|--------------|---------|-------------------------------------|
| `loggedin`   | bool    | always `true`                       |
| `sId`        | string  | session ID after `session_regenerate_id` |
| `id`         | int     | auth_accounts.id                    |
| `username`   | string  | auth_accounts.username              |
| `email`      | string  | auth_accounts.email                 |
| `img`        | string  | auth_accounts.img                   |
| `img_type`   | string  | auth_accounts.img_type              |
| `has_avatar` | bool    | `!empty(img_type)`                  |
| `disabled`   | mixed   | auth_accounts.disabled              |
| `debug`      | mixed   | auth_accounts.debug                 |
| `rights`     | string  | auth_accounts.rights                |
| `theme`      | string  | auth_accounts.theme, default `'auto'`|

## IP Blacklist

Stored in `auth_blacklist` in the auth database.

| Column       | Type         | Notes                                           |
|--------------|--------------|-------------------------------------------------|
| id           | INT UNSIGNED | PK AI                                           |
| ip           | VARCHAR(45)  | IPv4 or IPv6 address, UNIQUE                    |
| reason       | VARCHAR(255) | Human-readable; logged to auth_log on change    |
| auto         | TINYINT(1)   | 0 = manual, 1 = auto-blacklisted                |
| blocked_at   | TIMESTAMP    | Set on insert, updated on re-block              |
| expires_at   | TIMESTAMP    | NULL = permanent; checked against NOW() on read |

**Lookup**: `auth_is_blacklisted()` does a single `SELECT 1` with an expiry check. Expired entries are not deleted — they become inactive silently.

**Manual blacklist**: `auth_blacklist_ip()` — `INSERT … ON DUPLICATE KEY UPDATE`. Overwrites any existing entry including auto-blocked ones. `auth_unblacklist_ip()` — `DELETE`.

**Auto-blacklist**: triggered inside `auth_login()` when an IP's rate-limit strike count reaches `BLACKLIST_AUTO_STRIKES`. Uses `INSERT IGNORE` — does not override an existing manual entry.

**Strike counter**: stored in `RATE_LIMIT_FILE` under key `"rl_strikes:<ip>"`. Incremented each time a rate-limited login attempt is made. Reset to zero on successful login (`auth_clear_rl_strikes()`), alongside the failure counter. The strike counter becomes irrelevant once the IP is in `auth_blacklist`.

**Check order in `auth_login()`**: blacklist → rate limit → DB lookup. A blacklisted IP never reaches the DB.

## Rate limiting

Two layers share `RATE_LIMIT_FILE`:

**Login rate limiter** (IP-keyed, fixed constants):
- Key: raw IP string
- Threshold: 5 failures (`RATE_LIMIT_MAX`) within 900 s (`RATE_LIMIT_WINDOW`)
- Cleared automatically on successful login

**General rate limiter** (namespaced key, caller-supplied thresholds):
- Key: any string, typically `"<context>:<ip>"`
- Threshold and window passed per call
- Used for non-login actions (e.g. password reset requests)

File locking: `flock(LOCK_EX)` on every read-modify-write to prevent race conditions under concurrent requests.

## CSP nonce

`auth_bootstrap()` generates a fresh `$_cspNonce` (16 random bytes, base64-encoded) per request and exposes it as a PHP global. Templates must reference it as `<?= $_cspNonce ?>` on all inline `<script nonce="...">` tags.

Consumer projects extend the base CSP via `$cspExtras`:
```php
auth_bootstrap([
    'script-src' => 'https://cdn.jsdelivr.net',
    'font-src'   => 'https://fonts.gstatic.com https://fonts.googleapis.com',
]);
```
Each key appends sources to the base directive; the base already includes `'self'` and `'nonce-{nonce}'` for `script-src`.

## Constants (full list)

| Constant                | Default | Description                                          |
|-------------------------|---------|------------------------------------------------------|
| `AUTH_DB_PREFIX`        | —       | Required by consumer. `'jardyx_auth.'` or `''`       |
| `RATE_LIMIT_FILE`       | —       | Required by consumer. Path to writable JSON file     |
| `RATE_LIMIT_MAX`        | 5       | Login failures before IP is rate-limited             |
| `RATE_LIMIT_WINDOW`     | 900     | Rate-limit window in seconds (15 min)                |
| `BLACKLIST_AUTO_STRIKES`| 2       | Rate-limit events before IP is auto-blacklisted      |

## Security decisions

- **Session fixation**: `session_regenerate_id(true)` called on every successful login.
- **Timing-safe comparison**: `hash_equals()` used in `csrf_verify()`.
- **Username enumeration**: login always returns the same generic error for bad username or bad password.
- **bcrypt cost**: 13. `password_needs_rehash()` upgrades old hashes transparently at login.
- **IP source**: `REMOTE_ADDR` only — never proxy headers — so rate limits cannot be bypassed by spoofing `X-Forwarded-For`.
- **sId cookie**: `HttpOnly`, `Strict` SameSite, `Secure` on HTTPS, 4-day lifetime.
- **CSRF token**: per-session, 32 random bytes (hex). Checked via POST field or `X-CSRF-TOKEN` header.
