# erikr/auth

Shared PHP 8.2 Composer library providing authentication, session management, CSRF protection, IP rate limiting, IP blacklisting, activity logging, and SMTP mail for web applications that share a common user database.

## Features

- **Session lifecycle** — start, harden, recover via `sId` cookie
- **Security headers** — CSP with per-request nonce, HSTS, X-Frame-Options, etc.
- **Login / logout** — bcrypt cost-13, transparent rehash, session fixation prevention
- **Rate limiting** — IP-based login throttling (5 failures / 15 min) + general-purpose keyed limiter
- **IP blacklisting** — manual and auto (after 2 rate-limit strikes), with optional expiry
- **CSRF** — per-session token, POST field or `X-CSRF-TOKEN` header
- **Activity logging** — all auth events to `auth_log` table
- **SMTP mail** — via PHPMailer (STARTTLS)

## Installation

Add as a Composer path repository in your project:

```json
{
    "repositories": [
        {"type": "path", "url": "../auth"}
    ],
    "require": {
        "erikr/auth": "*"
    }
}
```

```bash
composer install
```

## Required Constants

Define before calling any library function:

| Constant | Description |
|----------|-------------|
| `AUTH_DB_PREFIX` | `'jardyx_auth.'` (cross-DB) or `''` (direct connection) |
| `RATE_LIMIT_FILE` | Absolute path to a writable JSON file for rate-limit state |

SMTP constants (`SMTP_HOST`, `SMTP_PORT`, `SMTP_USER`, `SMTP_PASS`, `SMTP_FROM`, `SMTP_FROM_NAME`) are only required if `send_mail()` is called.

## Basic Usage

```php
require 'vendor/autoload.php';

define('AUTH_DB_PREFIX', 'jardyx_auth.');
define('RATE_LIMIT_FILE', __DIR__ . '/data/ratelimit.json');

$con = new mysqli('localhost', 'user', 'pass', 'myapp');
auth_bootstrap();   // security headers, session, CSP nonce

// Login
$result = auth_login($con, $_POST['username'], $_POST['password']);

// Protect a page
auth_require();  // redirects to login.php if not logged in

// CSRF in forms
echo csrf_input();           // hidden input field
if (!csrf_verify()) { ... }  // validate POST
```

## Consumer Projects

| Project | `AUTH_DB_PREFIX` | Connection |
|---------|-----------------|------------|
| wlmonitor | `'jardyx_auth.'` | Cross-DB (app DB is `wlmonitor`) |
| Energie | `''` | Direct (connects to `jardyx_auth`) |

## Documentation

| Document | Contents |
|----------|----------|
| [docs/specs.md](docs/specs.md) | Full specification — DB schema, constants, session fields, rate limiting, blacklist, security decisions |
| [docs/api.md](docs/api.md) | Function reference for all public functions |
| [docs/implementation.md](docs/implementation.md) | Step-by-step guide for adding auth to a new project |

## Database

Requires two tables in the auth database (`jardyx_auth`):

- `auth_accounts` — user accounts, credentials, avatar, preferences
- `auth_log` — activity logging
- `auth_blacklist` — IP blacklist (manual + auto)

Schema: `db/01_auth_accounts.sql`, `db/02_auth_log.sql`, `db/04_auth_blacklist.sql`.

## Testing

```bash
vendor/bin/phpunit
```

Tests use `createStub()` for objects without expectations and `createMock()` only when asserting with `expects()` (PHPUnit 13 convention).
