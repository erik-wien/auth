# erikr/auth

Shared PHP authentication library for the Jardyx / eriks.cloud app ecosystem. Every app in the `~/Git` tree consumes it as a Composer path dependency — login, session, CSRF, invite tokens, password reset, TOTP 2FA, remember-me / cross-app SSO, admin user management, and an audit log all live here.

## Scope

One library, one set of patterns, for every app:

- **Session & bootstrap** — security headers (CSP with nonce, HSTS), session fixation prevention, silent remember-me restore.
- **Login & logout** — `auth_login()`, `auth_logout()`, `auth_require()`, password change.
- **Remember-me / SSO** — 8-day split-token cookie (selector plain, validator SHA-256 hashed), rotated on each use, scoped to `*.eriks.cloud` / `*.jardyx.com` for cross-app SSO.
- **CSRF** — `csrf_token()`, `csrf_input()`, `csrf_verify()`. Every state-changing POST must call `csrf_verify()`.
- **Admin** — list / create / edit / delete / reset-password, with a `register_delete_cleanup()` hook for cross-DB cleanup.
- **Invite tokens & password reset** — single-use tokens delivered via e-mail.
- **TOTP 2FA** — enrol / verify / revoke, enforced at login.
- **Mailer** — SMTP dispatch plus a small Markdown-subset template renderer (`src/mail_template.php`) used by invite / reset / change-password mails.
- **Logging** — shared `auth_log` table; every app writes through `appendLog($con, $context, $activity, $origin)`.

Apps never reimplement any of the above. They call the library.

## Requirements

- PHP ≥ 8.2 with `mysqli`, `gd`, `openssl`, `mbstring`
- MariaDB / MySQL (see `db/` for schema migrations)
- Composer

Runtime dependencies: `phpmailer/phpmailer ^7.0`, `chillerlan/php-qrcode ^5.0`.

## Installation

Composer path repository. From a consumer app's `composer.json`:

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

## Quick start

```php
require 'vendor/autoload.php';

define('AUTH_DB_PREFIX', 'auth.');   // or '' if connected directly
define('APP_CODE', 'myapp');                 // used by appendLog() as origin

$con = new mysqli($host, $user, $pass, $db);

auth_bootstrap([], $con);   // headers + session + silent remember-me restore

// Protected pages:
auth_require();

// Forms:
echo csrf_input();

// On POST:
csrf_verify();
```

See [`docs/conventions.md`](docs/conventions.md) for the full contract that consumer apps must follow.

## Public API (summary)

Full reference lives in `docs/conventions.md`. The high-traffic entry points:

| Function | Purpose |
|---|---|
| `auth_bootstrap(array $cspExtras = [], ?mysqli $con = null)` | Session + security headers + silent remember-me. Call once per request. |
| `auth_login($con, $user, $pass, $remember = false)` | Returns `['ok', 'error', 'totp_required']`. |
| `auth_logout()` | Clears session + remember cookie. |
| `auth_require()` / `admin_require()` | Redirect guards. |
| `csrf_token()` / `csrf_input()` / `csrf_verify()` | CSRF helpers. |
| `admin_list_users / _create / _edit / _delete / _reset` | Admin CRUD. |
| `admin_register_delete_cleanup(callable)` | Cross-DB cleanup hook for app-scoped tables. |
| `appendLog($con, $context, $activity, $origin = null)` | Write to shared audit log. |

## Database

Two deployment shapes are supported:

- **Shared auth DB** (`auth` locally / on akadbrain, `5279249db19` on world4you): every app connects to its own DB for app data and prefixes auth queries with `AUTH_DB_PREFIX = 'auth.'`.
- **Direct connection:** `AUTH_DB_PREFIX = ''` when the app connects directly to the auth DB.

Tables:

| Table | Purpose |
|---|---|
| `auth_accounts` | Users — fixed shape. App-specific preferences go in separate `<prefix>_preferences` tables joined on `id`. |
| `auth_log` | Audit trail — no `CASCADE`; survives user deletion by design. |
| `auth_blacklist` | Rate-limit / lockout state. |
| `auth_invite_tokens` | Single-use invite / reset tokens. |
| `password_resets` | Legacy reset tokens (still referenced by some apps). |
| `auth_remember_tokens` | 8-day remember-me sessions (`CASCADE` on user delete). |

Migrations live in `db/NN_*.sql`, applied manually in order:

```bash
mysql -u root auth < db/01_rename_tables.sql
# …continue through 10_remember_tokens.sql
```

Each migration starts with an explicit `USE <db>;` and is idempotent where feasible.

## Testing

```bash
composer install
vendor/bin/phpunit
```

PHPUnit 13. Unit tests (`tests/Unit/`) use mocks and need no DB. Integration fixtures live in `tests/fixtures/`. Integration tests target a real `auth` DB and wrap each test in a transaction.

## Email templates

Markdown-subset source in `templates/email/` — invite, password reset, password change confirmation. The renderer supports `**bold**`, `*italic*`, paragraphs, `---` horizontal rules, and trailing-whitespace `<br />` soft breaks.

## Conventions

- Consumer apps never run `DELETE FROM auth_accounts` directly — always go through `admin_delete_user()`, which invokes registered cleanup hooks inside the delete transaction.
- App-specific tables carrying `user_id` must either FK-cascade (same-DB) or register a cleanup hook (cross-DB).
- `auth_accounts` has a fixed shape. App-specific columns go in `<prefix>_preferences` tables (`wl_preferences`, `en_preferences`, …).
- Deployment is manual — schema migrations and grant updates (`~/Git/mcp/scripts/grant-db-users.sql`) are human-reviewed, not auto-applied.

Full contract: [`docs/conventions.md`](docs/conventions.md).

## Related repositories

- [`erikr/chrome`](https://github.com/erik-wien/chrome) — shared UI shell (header / footer / admin tabs) built on top of this library.
- `~/Git/css_library` — shared CSS foundation; the design rules chrome implements live in `css_library/docs/design-rules.md`.
