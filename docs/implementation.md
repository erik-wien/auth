# erikr/auth — Adding to a New Project

Step-by-step guide for wiring the auth library into a new PHP 8.2 project.

---

## 1. Composer setup

Add the library as a path repository in your project's `composer.json`:

```json
{
    "repositories": [
        {"type": "path", "url": "../auth"}
    ],
    "require": {
        "erikr/auth": "*"
    },
    "minimum-stability": "dev",
    "prefer-stable": true
}
```

The path `../auth` assumes your project sits next to the auth repo in `~/Git/`. Adjust if different. Composer creates `vendor/erikr/auth` as a symlink — edits to the auth source are immediately reflected.

Run:
```bash
composer install
```

---

## 2. Database access

The auth library reads and writes two tables: `auth_accounts` and `auth_log`. Decide which connection model fits your project:

**Option A — project connects directly to the auth database**

Use when your application DB *is* the auth DB, or your project has no separate application DB.
```php
define('AUTH_DB_PREFIX', '');
// $con connects to the jardyx_auth database
```
Queries become: `auth_accounts`, `auth_log`.

**Option B — project connects to its own DB, auth tables are cross-DB**

Use when your application has its own DB and the MySQL user can read `jardyx_auth.*` cross-schema.
```php
define('AUTH_DB_PREFIX', 'jardyx_auth.');
// $con connects to the application database (e.g. wlmonitor)
```
Queries become: `jardyx_auth.auth_accounts`, `jardyx_auth.auth_log`.

The MySQL user for Option B needs:
```sql
GRANT SELECT, INSERT, UPDATE ON jardyx_auth.* TO 'appuser'@'localhost';
```

---

## 3. Rate-limit file

Create a writable JSON file for rate-limit state and a `.htaccess` to block direct HTTP access:

```bash
mkdir -p data
echo '{}' > data/ratelimit.json
chmod 664 data/ratelimit.json
```

```apache
# data/.htaccess
Deny from all
```

Define the constant before autoload:
```php
define('RATE_LIMIT_FILE', __DIR__ . '/../data/ratelimit.json');
```

---

## 4. SMTP constants (if sending email)

```php
define('SMTP_HOST',      'mail.example.com');
define('SMTP_PORT',      587);
define('SMTP_USER',      'noreply@example.com');
define('SMTP_PASS',      'secret');
define('SMTP_FROM',      'noreply@example.com');
define('SMTP_FROM_NAME', 'My App');
```

---

## 5. initialize.php

This file must be the first `require_once` in every PHP page. It opens the DB connection, defines all constants, and calls `auth_bootstrap()`.

```php
<?php
// inc/initialize.php

require_once __DIR__ . '/../vendor/autoload.php';

// --- Constants ---
define('AUTH_DB_PREFIX',  'jardyx_auth.');   // or '' — see Section 2
define('RATE_LIMIT_FILE', __DIR__ . '/../data/ratelimit.json');

// --- DB connection ---
$con = new mysqli('localhost', 'dbuser', 'dbpass', 'myappdb');
if ($con->connect_errno) {
    http_response_code(503); exit('DB unavailable');
}
$con->set_charset('utf8mb4');

// --- Auth bootstrap (headers + session) ---
auth_bootstrap();
// With CDN extras:
// auth_bootstrap(['script-src' => 'https://cdn.example.com']);
```

`auth_bootstrap()` must be called **before any output** (it emits headers) and **after** `$con` is ready (session recovery may need the connection).

---

## 6. Login page

**`web/login.php`** — render the form:

```php
<?php
require_once __DIR__ . '/../inc/initialize.php';
if (!empty($_SESSION['loggedin'])) {
    header('Location: index.php'); exit;
}
?>
<form method="post" action="authentication.php">
    <?= csrf_input() ?>
    <input type="text"     name="username" required autocomplete="username">
    <input type="password" name="password" required autocomplete="current-password">
    <button type="submit">Login</button>
</form>
```

**`web/authentication.php`** — POST handler:

```php
<?php
require_once __DIR__ . '/../inc/initialize.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
    header('Location: login.php'); exit;
}

$username = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';

$result = auth_login($con, $username, $password);

if ($result['ok']) {
    appendLog($con, 'auth', $result['username'] . ' logged in.', 'web');
    header('Location: index.php'); exit;
} else {
    addAlert('danger', $result['error']);
    header('Location: login.php'); exit;
}
```

---

## 7. Logout

**`web/logout.php`**:

```php
<?php
require_once __DIR__ . '/../inc/initialize.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
    header('Location: index.php'); exit;
}

auth_logout($con);
addAlert('info', 'Sie wurden abgemeldet.');
header('Location: login.php'); exit;
```

Logout **must** be triggered by a POST form, never a plain link. A GET-based logout allows CSRF attacks that force users to log out:

```php
<!-- In your navbar template -->
<form method="post" action="logout.php">
    <?= csrf_input() ?>
    <button type="submit">Abmelden</button>
</form>
```

Style the `<button>` to match surrounding links with CSS.

---

## 8. Protecting pages

Add at the top of every authenticated page, after `require_once initialize.php`:

```php
auth_require();
```

This redirects to `login.php` (relative to the app base path, derived from `SCRIPT_NAME`) if the user is not logged in. For JSON/API endpoints return 401 inline instead:

```php
if (empty($_SESSION['loggedin'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}
```

---

## 9. Displaying alerts

Alerts queued via `addAlert()` are stored in `$_SESSION['alerts']` as `[$type, $html]` pairs. Render them in your page template:

```php
<?php foreach ($_SESSION['alerts'] ?? [] as [$type, $msg]): ?>
    <div class="alert alert-<?= htmlspecialchars($type) ?>"><?= $msg ?></div>
<?php endforeach; ?>
<?php $_SESSION['alerts'] = []; ?>
```

---

## 10. CSP nonce for inline scripts

`auth_bootstrap()` sets the global `$_cspNonce`. Use it on every inline `<script>` tag:

```html
<script nonce="<?= $_cspNonce ?>">
    // your inline JS
</script>
```

Scripts without a nonce will be blocked by the CSP. External script hosts must be added via `$cspExtras` in `auth_bootstrap()`.

---

## 11. Serving avatars

Avatars are stored as blobs in `auth_accounts.img_blob` with MIME type in `img_blob_type`. Create a `web/avatar.php` that:

1. Requires authentication (or allows public access — your choice)
2. Fetches `img_blob` and `img_type` from `auth_accounts`
3. **Whitelists the MIME type** before setting `Content-Type`

```php
<?php
require_once __DIR__ . '/../inc/initialize.php';
if (empty($_SESSION['loggedin'])) { http_response_code(403); exit; }

$stmt = $con->prepare(
    'SELECT img_blob, img_type FROM ' . AUTH_DB_PREFIX . 'auth_accounts WHERE id = ? AND img_blob IS NOT NULL'
);
$stmt->bind_param('i', $_SESSION['id']);
$stmt->execute();
$stmt->store_result();

if ($stmt->num_rows === 0) {
    http_response_code(404); exit;
}

$stmt->bind_result($blob, $type);
$stmt->fetch();
$stmt->close();

$allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
$type = in_array($type, $allowed, true) ? $type : 'image/jpeg';

header('Content-Type: ' . $type);
header('Cache-Control: private, max-age=3600');
echo $blob;
```

Never use the raw DB value in `Content-Type` without whitelisting — a stored `text/html` type would cause XSS.

---

## 12. IP blacklist

The blacklist table lives in the auth database. Run the migration once:

```bash
mysql -u root -p jardyx_auth < /Users/erikr/Git/auth/db/04_auth_blacklist.sql
```

**Auto-blacklist** is built into `auth_login()` — no extra code needed. After an IP triggers the rate limit `BLACKLIST_AUTO_STRIKES` (2) times, it is permanently blocked and all future login attempts return `'Zugang gesperrt.'` immediately.

**Manual blacklist** — run from any PHP context with a DB connection:

```php
// Block permanently
auth_blacklist_ip($con, '1.2.3.4', 'Known scanner');

// Block for 7 days
auth_blacklist_ip($con, '1.2.3.4', 'Brute force attempt', 7 * 24 * 3600);

// Unblock
auth_unblacklist_ip($con, '1.2.3.4');
```

Or manage directly in SQL:

```sql
-- List active blocks
SELECT ip, reason, auto, blocked_at, expires_at
FROM jardyx_auth.auth_blacklist
WHERE expires_at IS NULL OR expires_at > NOW()
ORDER BY blocked_at DESC;

-- Remove a block
DELETE FROM jardyx_auth.auth_blacklist WHERE ip = '1.2.3.4';

-- Expire all auto-blocks older than 30 days (optional cleanup)
UPDATE jardyx_auth.auth_blacklist
SET expires_at = NOW()
WHERE auto = 1 AND blocked_at < NOW() - INTERVAL 30 DAY;
```

## 13. App-specific preferences table

If your project has per-user settings, create a dedicated preferences table in your application DB. Do not add columns to `auth_accounts` — that table is shared across projects.

```sql
CREATE TABLE myapp_preferences (
    id      INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL COMMENT 'references jardyx_auth.auth_accounts.id',
    -- your columns here
    UNIQUE KEY uk_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

Load preferences after login in `authentication.php` and store them in `$_SESSION`.

---

## 13. Deploy

The `vendor/erikr/auth` directory is a symlink on dev. Use `rsync --copy-links` when deploying so the auth library files are copied to the server as real files rather than a broken symlink:

```bash
rsync -av --delete --copy-links \
  --exclude='.git/' --exclude='tests/' --exclude='data/' --exclude='config/' \
  /path/to/myapp/ /var/www/myapp/
```

---

## Checklist

- [ ] `composer.json` has path repository pointing to `../auth`
- [ ] `AUTH_DB_PREFIX` defined correctly (Option A or B)
- [ ] `RATE_LIMIT_FILE` points to a writable JSON file outside web root
- [ ] `data/.htaccess` blocks HTTP access to `ratelimit.json`
- [ ] `auth_bootstrap()` called before any output in `initialize.php`
- [ ] `$_cspNonce` used on all inline `<script nonce="...">` tags
- [ ] Login form has `<?= csrf_input() ?>`
- [ ] `authentication.php` calls `csrf_verify()` before processing
- [ ] Logout is POST-only with `csrf_verify()`; navbar uses `<form>` not `<a>`
- [ ] All protected pages call `auth_require()`
- [ ] `avatar.php` whitelists MIME type before `Content-Type` header
- [ ] `db/04_auth_blacklist.sql` run against `jardyx_auth`
- [ ] App-specific preferences in a separate table, not in `auth_accounts`
- [ ] Deploy script uses `rsync --copy-links`
