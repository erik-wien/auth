# CLAUDE.md — erikr/auth

Shared PHP authentication library consumed as a Composer path dependency by every
app in `~/Git/`. Binding consumer-side rules live in `~/.claude/rules/auth-rules.md`.

## Layout

```
auth/
├── src/           # one file per concern, loaded via composer autoload.files
│   ├── bootstrap.php      # auth_bootstrap()
│   ├── auth.php           # auth_login / auth_logout / auth_require / password change
│   ├── remember.php       # auth_remember_* (8-day persistent sessions + SSO)
│   ├── totp.php           # TOTP 2FA (enroll, verify, revoke)
│   ├── admin.php          # admin_* (list/create/edit/delete/reset/require)
│   ├── invite.php         # invite tokens (create/consume/setpassword)
│   ├── csrf.php           # csrf_token / csrf_input / csrf_verify
│   ├── mailer.php         # mail_send (SMTP dispatch)
│   ├── mail_template.php  # render_template, placeholder substitution
│   ├── mail_helpers.php   # typed senders: mail_send_invite / _reset / _change / mail_send_admin_notice
│   ├── log.php            # appendLog
│   └── avatar.php         # Avatar class (used by ~/Git/chrome too)
├── db/            # migrations (NN_*.sql), manual deploy
├── templates/     # email templates (Markdown source)
└── tests/         # PHPUnit — Unit/ (mocks) + Integration/ (real DB)
```

## Public API

Apps only call the functions below. Everything else is internal.

### Bootstrap (once per request)

```php
auth_bootstrap(array $cspExtras = [], ?mysqli $con = null): void
```

Opens session, sends security headers (CSP with nonce → `$_cspNonce`, HSTS, etc.),
regenerates ID on fixation risk. If `$con` is passed, also attempts silent
restore from a `auth_remember` cookie (see Remember-me).

### Login / logout

```php
auth_login(mysqli $con, string $user, string $pass, bool $remember = false): array
auth_logout(): void
auth_require(): void                   // redirect to login if not authed
admin_require(): void                  // redirect to index if not admin
auth_change_password(mysqli, string $old, string $new): bool
```

`auth_login` returns `['ok' => bool, 'error' => ?string, 'totp_required' => bool]`.
When `$remember` is true and login succeeds without 2FA, a remember-me token is
issued. For 2FA accounts, the flag is carried through `$_SESSION['auth_totp_pending']`
and applied after `auth_totp_complete()`.

Session fields set on login: `loggedin`, `sId`, `id`, `username`, `email`, `img`,
`img_type`, `has_avatar`, `disabled`, `rights`, `theme`. Apps read these;
never invent new top-level keys (see `~/.claude/rules/auth-rules.md` §3).

### Login hardening (constants + layered defence)

Constants in `src/auth.php`:

| Constant | Value | Meaning |
|---|---|---|
| `USER_LOCKOUT_THRESHOLD` | 10 | `invalidLogins >= this` blocks login; clears on admin reset |
| `LOGIN_SCORE_FAIL_EXISTING_USER` | 1 | Score per failed attempt on a known username (60-min window) |
| `LOGIN_SCORE_FAIL_UNKNOWN_USER` | 3 | Score per attempt on a non-existent username |
| `LOGIN_SCORE_RL_STRIKE` | 5 | Score per rate-limit event |
| `LOGIN_SCORE_BLACKLIST_THRESHOLD` | 15 | Score at which `auth_auto_blacklist()` fires |

**Layered model** (applies in order inside `auth_login()`):

1. Blacklist check — immediate rejection if IP is blocked.
2. Rate-limit check — blocks after `RATE_LIMIT_MAX` failures in `RATE_LIMIT_WINDOW` s; logs a +5 RL event.
3. Score check — computed from `auth_log` entries in the last 60 min; triggers auto-blacklist at threshold.
4. User lockout gate — `invalidLogins >= USER_LOCKOUT_THRESHOLD` short-circuits before bcrypt.
5. Password verify — on failure: increment `invalidLogins`, log +1 event, re-score, optionally auto-blacklist, apply progressive delay.

**Progressive delay:** `min(30, 2^(failCount−1))` seconds before every rejected response (except
blacklisted-IP early return). `failCount` counts `Login failed%` entries in `auth_log` within
`RATE_LIMIT_WINDOW` seconds.

**Auto-blacklist notification:** `auth_auto_blacklist($con, $ip, notifyAdmins: true, $scoreContext)`
sends a `blacklist_notice` email to active admins. Only score-triggered paths pass `notifyAdmins = true`.

### Remember-me / cross-app SSO

Cookie is `<selector>.<validator>` — selector stored plain (16 hex, O(1) lookup),
validator SHA-256 hashed (hash_equals compare). Rotated on each use. TOTP-gated
accounts are blocked from silent restore.

Cookie domain picked from `HTTP_HOST`:
- `*.eriks.cloud` → `Domain=.eriks.cloud` (SSO across apps)
- `*.jardyx.com`  → `Domain=.jardyx.com`
- anything else   → per-host (local `.test` dev)

```php
auth_remember_issue(mysqli, int $userId): void
auth_remember_validate(mysqli): ?array          // opportunistic GC
auth_remember_try_restore(mysqli): bool         // called from auth_bootstrap()
auth_remember_delete_current(mysqli): void      // on logout
auth_remember_delete_all(mysqli, int $userId): void  // on password change or theft
auth_remember_revoke_all(mysqli): bool          // user-initiated "log out of all devices"
```

Constants: `AUTH_REMEMBER_COOKIE = 'auth_remember'`, `AUTH_REMEMBER_LIFETIME = 691200` (8 days).

### CSRF

```php
csrf_token(): string
csrf_input(): string        // <input type="hidden" name="_csrf" value="…">
csrf_verify(): void         // throws / aborts on mismatch
```

### Admin (super-user)

```php
admin_list_users(mysqli, int $page = 1, int $perPage = 25, string $filter = ''): array
admin_create_user(mysqli, string $user, string $email, string $rights, string $baseUrl): int
admin_edit_user(mysqli, int $id, string $email, string $rights, int $disabled, bool $totpReset = false): bool
admin_reset_password(mysqli, int $id, string $baseUrl): array{ok: bool, unblocked_ips: list<string>}
admin_user_reset_preview(mysqli, int $id): array{ok: bool, username: string, email: string, ips: list<string>}
admin_delete_user(mysqli, int $id, int $requestingId): bool
admin_register_delete_cleanup(callable $fn): void              // for cross-DB tables
```

`admin_reset_password` sends a password-reset email, clears `invalidLogins`, and deletes
`auto=1` blacklist rows for IPs that failed against this user in the last 24 h. Returns
`unblocked_ips` — the list of IPs actually removed.

`admin_user_reset_preview` is read-only: returns the same IP list without mutating anything,
for use in a confirmation modal before committing the reset.

`admin_delete_user` invokes registered cleanup hooks inside the DELETE transaction.
Use for cross-DB tables that can't FK to `auth_accounts(id)` — see auth-rules §5.

### Logging

```php
appendLog(mysqli, string $context, string $activity, ?string $origin = null): void
```

`$origin` defaults to `APP_CODE`. Writes to shared `auth_log` (every app shares this
audit trail — origin column distinguishes them).

## Database

- **Shared DB:** `auth` (local / akadbrain) or `5279249db19` (world4you).
- **Constant `AUTH_DB_PREFIX`** — either `''` (when connected directly) or
  `'auth.'` (when an app connects to its own DB and prefixes auth queries).
  All library SQL uses `AUTH_DB_PREFIX . 'table_name'`.

### Tables

| Table | Purpose |
|---|---|
| `auth_accounts` | Users (fixed shape — never extend; use app-scoped tables) |
| `auth_log` | Audit trail (no CASCADE — survives user deletion) |
| `auth_blacklist` | Rate-limit / lockout state |
| `auth_invite_tokens` | Single-use invite/reset tokens |
| `password_resets` | Legacy reset tokens (still used by some apps) |
| `auth_remember_tokens` | 8-day remember-me sessions (CASCADE on delete) |

## Migrations

- Numbered `db/NN_*.sql`, zero-padded two digits.
- Start with explicit `USE <db>;`.
- `CREATE TABLE IF NOT EXISTS` / `ALTER ... IF NOT EXISTS` where feasible.
- Include FK CASCADE in the creating migration (see auth-rules §5, §7).
- Deployed manually: `mariadb -uroot auth < db/NN_foo.sql` locally, or
  via SSH on akadbrain. Grants in `~/Git/mcp/scripts/grant-db-users.sql` — update
  alongside the migration that adds a new table.

## Dev & testing

```bash
composer install
vendor/bin/phpunit                    # Unit (mocks) + Integration (real DB)
```

Integration tests use `auth` directly and wrap each test in a transaction
(rolled back in tearDown). STRICT_TRANS_TABLES is the MariaDB default — ENUM
columns (`disabled`) must be bound as strings, not ints.

## Consumer wiring

Every app includes a `Composer path dependency` on `../auth` and calls:

1. `auth_bootstrap([], $con)` early in `inc/initialize.php` (pass `$con` to
   enable silent remember-me restore).
2. `auth_require()` at the top of protected pages.
3. `csrf_input()` / `csrf_verify()` on every state-changing form.
4. `auth_logout()` via POST + CSRF form (never a plain `<a>` link).

Apps **never** reimplement login, session handling, CSRF, bcrypt, rate limiting,
or token patterns. Extend the library instead. See `~/.claude/rules/auth-rules.md`
for the binding consumer rules.

<!-- BACKLOG.MD MCP GUIDELINES START -->

<CRITICAL_INSTRUCTION>

## BACKLOG WORKFLOW INSTRUCTIONS

This project uses Backlog.md MCP for all task and project management activities.

**CRITICAL GUIDANCE**

- If your client supports MCP resources, read `backlog://workflow/overview` to understand when and how to use Backlog for this project.
- If your client only supports tools or the above request fails, call `backlog.get_backlog_instructions()` to load the tool-oriented overview. Use the `instruction` selector when you need `task-creation`, `task-execution`, or `task-finalization`.

- **First time working here?** Read the overview resource IMMEDIATELY to learn the workflow
- **Already familiar?** You should have the overview cached ("## Backlog.md Overview (MCP)")
- **When to read it**: BEFORE creating tasks, or when you're unsure whether to track work

These guides cover:
- Decision framework for when to create tasks
- Search-first workflow to avoid duplicates
- Links to detailed guides for task creation, execution, and finalization
- MCP tools reference

You MUST read the overview resource to understand the complete workflow. The information is NOT summarized here.

</CRITICAL_INSTRUCTION>

<!-- BACKLOG.MD MCP GUIDELINES END -->
