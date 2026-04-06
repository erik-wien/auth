# Auth Library Design

**Date:** 2026-04-06  
**Scope:** Extract shared auth, session, CSRF, logging, and mailer code from `Energie` and `wlmonitor` into a standalone Composer package at `/Users/erikr/Git/auth`.

---

## Problem

`Energie` and `wlmonitor` share a login database (`auth_accounts`) but maintain duplicate copies of `auth.php`, `csrf.php`, `initialize.php` (partial), and `mailer.php`. The two copies have already diverged — wlmonitor is ahead on bcrypt cost upgrade, general-purpose rate limiting, and session fields. Any future fix must be applied in two places.

---

## Library Structure

```
auth/
  composer.json
  src/
    bootstrap.php    — security headers, session hardening, CSRF init
    auth.php         — auth_login(), auth_logout(), rate limiter
    csrf.php         — csrf_token(), csrf_verify(), csrf_input()
    log.php          — appendLog(), addAlert(), getUserIpAddr(), auth_require(), logDebug()
    mailer.php       — sendMail() PHPMailer wrapper
```

### `bootstrap.php`

Single entry point called by each project after `$con` is open. Responsibilities:

1. Emit security headers: `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy`, HSTS (HTTPS only), CSP nonce.
2. Accept an optional `array $cspExtras` parameter for per-project CDN additions to the CSP (e.g. wlmonitor needs fontawesome/googleapis, Energie does not).
3. Start session with hardened cookie options (`httponly`, `secure`, `samesite=Strict`, 4-day lifetime).
4. Handle session recovery from the `sId` cookie.
5. Set the `sId` cookie on new sessions.

All library files are loaded via Composer `autoload.files` before `auth_bootstrap()` is called — no internal `require_once` needed.

Signature: `auth_bootstrap(array $cspExtras = []): void`

### `auth.php`

- `auth_login(mysqli $con, string $username, string $password): array` — rate-limit check, user lookup against `auth_accounts`, activation/disabled check, `password_verify`, transparent bcrypt-13 rehash, session fixation prevention, session fields, theme cookie, `lastLogin` update, log entry.
- `auth_logout(mysqli $con): void` — log entry, `session_destroy()`, expire `sId` cookie.
- `auth_is_rate_limited(string $ip): bool`
- `auth_record_failure(string $ip): void`
- `auth_clear_failures(string $ip): void`
- `rate_limit_check(string $key, int $max, int $window): bool` — general-purpose, namespaced
- `rate_limit_record(string $key, int $window): void`
- `rate_limit_clear(string $key): void`

Requires project to define `RATE_LIMIT_FILE` constant before including this file.

`auth_login()` selects: `id`, `username`, `password`, `email`, `img`, `img_type`, `activation_code`, `disabled`, `invalidLogins`, `debug`, `rights`, `theme` from `auth_accounts`. `departures` is NOT selected here — it lives in `wl_preferences` and is loaded by wlmonitor separately after login.

Session fields set on login: `sId`, `loggedin`, `id`, `username`, `email`, `img`, `img_type`, `has_avatar` (`!empty(img_type)`), `disabled`, `debug`, `rights`, `theme`.

### `csrf.php`

Unchanged from wlmonitor version: `csrf_token()`, `csrf_verify()`, `csrf_input()`.

### `log.php`

- `appendLog(mysqli $con, string $context, string $activity, string $origin = 'web'): bool` — inserts into `auth_log`.
- `addAlert(string $type, string $message): void` — queues a UI alert in `$_SESSION['alerts']`.
- `getUserIpAddr(): string` — returns `REMOTE_ADDR` (no proxy headers; safe for rate limiting).
- `auth_require(): void` — redirects to `login.php` if not logged in.
- `logDebug(string $label, string $message): void` — calls `appendLog` when `$_SESSION['debug']` is set. Uses global `$con`.

### `mailer.php`

- `sendMail(string $toAddress, string $toName, string $subject, string $bodyHtml, string $bodyText): void`

Reads SMTP constants: `SMTP_HOST`, `SMTP_PORT`, `SMTP_USER`, `SMTP_PASS`, `SMTP_FROM`, `SMTP_FROM_NAME`. These are defined per-project before the library is included.

---

## Auth Database Schema Changes

### Table renames

| Old name | New name |
|----------|----------|
| `wl_accounts` | `auth_accounts` |
| `wl_log` | `auth_log` |

### Field migration out of `auth_accounts`

| Field | Moves to |
|-------|----------|
| `departures` | `wl_preferences` (wlmonitor) |

All other existing fields stay in `auth_accounts`, including `debug`, `theme`, `img`, `img_type`, `rights`.

### App preferences tables

**`wl_preferences`** (wlmonitor DB):
```sql
CREATE TABLE wl_preferences (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,  -- references auth_accounts.id
    departures TINYINT UNSIGNED NOT NULL DEFAULT 2,
    -- future wlmonitor-specific prefs here
    UNIQUE KEY uk_user (user_id)
);
```

**`en_preferences`** (Energie DB):
```sql
CREATE TABLE en_preferences (
    id      INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,  -- references auth_accounts.id
    -- future Energie-specific prefs here
    UNIQUE KEY uk_user (user_id)
);
```

No foreign key constraints (cross-DB); application-level integrity only.

---

## Composer Integration

**`auth/composer.json`:**
```json
{
    "name": "erikr/auth",
    "description": "Shared auth, session, CSRF, logging and mailer library",
    "type": "library",
    "require": {
        "phpmailer/phpmailer": "^7.0"
    },
    "autoload": {
        "files": [
            "src/log.php",
            "src/csrf.php",
            "src/auth.php",
            "src/mailer.php",
            "src/bootstrap.php"
        ]
    }
}
```

**Both consumer `composer.json` files** add:
```json
"repositories": [
    {"type": "path", "url": "../../auth"}
],
"require": {
    "erikr/auth": "*"
}
```

Because the library uses `autoload.files`, all functions are available after `require_once vendor/autoload.php`. Each project still explicitly calls `auth_bootstrap()` from its own `initialize.php` — autoload only loads the function definitions, not side effects.

---

## Per-Project Bootstrap Pattern

### wlmonitor `include/initialize.php` (after migration)

```php
require_once __DIR__ . '/../vendor/autoload.php';  // loads library functions

// 1. Load config/db.json, define DB/SMTP/app constants
// 2. define('RATE_LIMIT_FILE', __DIR__ . '/../data/ratelimit.json')
// 3. $con = createDBConnection()  — opens auth DB (stays per-project)
// 4. auth_bootstrap([...wlmonitor CSP extras...])  — headers + session + CSRF
// 5. App-specific: sanitizeDivaInput(), logDebug() wrappers, $loggedIn etc.
```

`sanitizeDivaInput()` and `sanitizeRblInput()` are wlmonitor-specific and stay in wlmonitor's own `include/` directory.

### Energie `inc/initialize.php` (after migration)

```php
require_once __DIR__ . '/../vendor/autoload.php';

// 1. Load energie-config.ini, define SMTP/app constants
// 2. define('RATE_LIMIT_FILE', __DIR__ . '/../data/ratelimit.json')
// 3. $con = new mysqli(...)  — opens auth DB (stays per-project)
// 4. auth_bootstrap()  — headers + session + CSRF (no CDN extras needed)
// 5. $pdo = ...  — opens Energie data DB (stays per-project)
```

---

## Migration Steps (high level)

1. Create `auth/` repo and library files (canonical = wlmonitor versions, which are ahead).
2. Rename `wl_accounts` → `auth_accounts`, `wl_log` → `auth_log` in DB; update all query strings in both projects.
3. Create `wl_preferences` with `departures`; migrate data; update wlmonitor queries.
4. Create `en_preferences` (empty for now).
5. Add `erikr/auth` dependency to both `composer.json` files; `composer update`.
6. Replace each project's `auth.php`, `csrf.php`, and the shared parts of `initialize.php` with calls to the library.
7. Verify tests pass in both projects.
