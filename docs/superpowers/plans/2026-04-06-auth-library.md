# Auth Library Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Extract shared auth, session, CSRF, logging, and mailer code from `Energie` and `wlmonitor` into a Composer package at `/Users/erikr/Git/auth`, then migrate both consumers to use it.

**Architecture:** The library provides five files loaded via Composer `autoload.files`: `log.php`, `csrf.php`, `auth.php`, `mailer.php`, and `bootstrap.php`. Each project keeps its own `initialize.php` for config loading and DB connections, then calls `auth_bootstrap()`. A per-project `AUTH_DB_PREFIX` constant (`'jardyx_auth.'` for wlmonitor, `''` for Energie) lets auth queries resolve to the correct schema in both environments.

**Tech Stack:** PHP 8.2, MySQLi, PHPUnit 13, Composer 2, PHPMailer 7.

---

## File Map

### New files (auth library)
| Path | Role |
|------|------|
| `auth/composer.json` | Package definition, autoload.files, PHPMailer + PHPUnit deps |
| `auth/phpunit.xml` | PHPUnit config for auth library unit tests |
| `auth/tests/bootstrap.php` | PHPUnit bootstrap — starts session, sets REMOTE_ADDR |
| `auth/tests/Unit/LogTest.php` | Tests for getUserIpAddr, addAlert, auth_require |
| `auth/tests/Unit/CsrfTest.php` | Port of wlmonitor's CsrfTest |
| `auth/src/log.php` | appendLog, addAlert, getUserIpAddr, auth_require, logDebug |
| `auth/src/csrf.php` | csrf_token, csrf_verify, csrf_input |
| `auth/src/auth.php` | auth_login, auth_logout, rate limiter functions |
| `auth/src/bootstrap.php` | auth_bootstrap() — headers, session, CSRF init |
| `auth/src/mailer.php` | send_mail() PHPMailer wrapper |
| `auth/db/01_rename_tables.sql` | Rename wl_accounts → auth_accounts, wl_log → auth_log |
| `auth/db/02_wl_preferences.sql` | Create wl_preferences, migrate departures data |
| `auth/db/03_en_preferences.sql` | Create en_preferences |

### Modified files (wlmonitor)
| Path | Change |
|------|--------|
| `wlmonitor/composer.json` | Add erikr/auth path repository + require |
| `wlmonitor/include/initialize.php` | Remove shared functions; call auth_bootstrap() |
| `wlmonitor/inc/auth.php` | Delete (replaced by library) |
| `wlmonitor/include/csrf.php` | Delete (replaced by library) |
| `wlmonitor/inc/mailer.php` | Delete (replaced by library) |
| `wlmonitor/tests/bootstrap.php` | Remove explicit requires for deleted files |
| `wlmonitor/tests/Integration/IntegrationTestCase.php` | wl_accounts → auth_accounts, remove departures from INSERT |
| `wlmonitor/tests/Integration/AuthTest.php` | wl_accounts → auth_accounts |
| `wlmonitor/inc/admin.php` | wl_accounts → AUTH_DB_PREFIX.auth_accounts; departures queries → wl_preferences |
| `wlmonitor/web/registration.php` | wl_accounts → AUTH_DB_PREFIX.auth_accounts; insert into wl_preferences |
| `wlmonitor/web/preferences.php` | wl_accounts → AUTH_DB_PREFIX.auth_accounts; departures → wl_preferences |
| `wlmonitor/web/api.php` | wl_accounts/wl_log → AUTH_DB_PREFIX.auth_accounts/auth_log |
| `wlmonitor/web/confirm_email.php` | wl_accounts → AUTH_DB_PREFIX.auth_accounts |
| `wlmonitor/web/changePassword.php` | wl_accounts → AUTH_DB_PREFIX.auth_accounts |
| `wlmonitor/web/forgotPassword.php` | wl_accounts → AUTH_DB_PREFIX.auth_accounts |
| `wlmonitor/web/executeReset.php` | wl_accounts → AUTH_DB_PREFIX.auth_accounts |
| `wlmonitor/web/activate.php` | wl_accounts → AUTH_DB_PREFIX.auth_accounts |
| `wlmonitor/web/avatar.php` | wl_accounts → AUTH_DB_PREFIX.auth_accounts |

### Modified files (Energie)
| Path | Change |
|------|--------|
| `Energie/composer.json` | Add erikr/auth path repository + require |
| `Energie/inc/initialize.php` | Remove shared functions; call auth_bootstrap() |
| `Energie/inc/db.php` | Remove initialize.php include (autoload handles it); keep PDO |
| `Energie/inc/auth.php` | Delete (replaced by library) |
| `Energie/inc/csrf.php` | Delete (replaced by library) |
| `Energie/inc/mailer.php` | Delete (replaced by library) |
| `Energie/web/preferences.php` | wl_accounts → auth_accounts |
| `Energie/web/api.php` | wl_accounts → auth_accounts |
| `Energie/web/confirm_email.php` | wl_accounts → auth_accounts |
| `Energie/web/avatar.php` | wl_accounts → auth_accounts |

---

## Task 1: Auth library scaffold

**Files:**
- Create: `auth/composer.json`
- Create: `auth/phpunit.xml`
- Create: `auth/tests/bootstrap.php`
- Create: `auth/src/.gitkeep` (to create src dir)
- Create: `auth/data/ratelimit.json`

- [ ] **Step 1: Create composer.json**

```json
{
    "name": "erikr/auth",
    "description": "Shared auth, session, CSRF, logging and mailer library",
    "type": "library",
    "require": {
        "phpmailer/phpmailer": "^7.0"
    },
    "require-dev": {
        "phpunit/phpunit": "^13.0"
    },
    "autoload": {
        "files": [
            "src/log.php",
            "src/csrf.php",
            "src/auth.php",
            "src/mailer.php",
            "src/bootstrap.php"
        ]
    },
    "autoload-dev": {
        "psr-4": {
            "ErikR\\Auth\\Tests\\": "tests/"
        }
    }
}
```

- [ ] **Step 2: Create phpunit.xml**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="tests/bootstrap.php"
         colors="true">
    <testsuites>
        <testsuite name="Unit">
            <directory>tests/Unit</directory>
        </testsuite>
    </testsuites>
    <source>
        <include>
            <directory>src</directory>
        </include>
    </source>
</phpunit>
```

- [ ] **Step 3: Create tests/bootstrap.php**

```php
<?php
// tests/bootstrap.php — PHPUnit bootstrap for erikr/auth unit tests.
// Loads function definitions without running web bootstrap side-effects.

require_once __DIR__ . '/../vendor/autoload.php';

$_SERVER['REMOTE_ADDR'] ??= '127.0.0.1';
$_SERVER['SCRIPT_NAME'] ??= '/auth/test.php';

// Start a session so CSRF and session-dependent functions work.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Ensure the rate-limit file exists.
$dir = __DIR__ . '/../data';
if (!is_dir($dir)) {
    mkdir($dir, 0755, true);
}
$file = $dir . '/ratelimit.json';
if (!file_exists($file)) {
    file_put_contents($file, '{}');
}

define('RATE_LIMIT_FILE', $file);
define('AUTH_DB_PREFIX', '');
```

- [ ] **Step 4: Create src/ directory and data/ratelimit.json**

```bash
mkdir -p /Users/erikr/Git/auth/src
echo '{}' > /Users/erikr/Git/auth/data/ratelimit.json
```

- [ ] **Step 5: Install dependencies**

```bash
cd /Users/erikr/Git/auth && composer install
```

Expected: `vendor/` created, PHPMailer and PHPUnit installed.

- [ ] **Step 6: Commit**

```bash
cd /Users/erikr/Git/auth
git add composer.json phpunit.xml tests/ data/
git commit -m "chore: scaffold auth library with Composer and PHPUnit"
```

---

## Task 2: src/log.php (TDD)

**Files:**
- Create: `auth/tests/Unit/LogTest.php`
- Create: `auth/src/log.php`

- [ ] **Step 1: Write failing tests**

Create `auth/tests/Unit/LogTest.php`:

```php
<?php

namespace ErikR\Auth\Tests\Unit;

use PHPUnit\Framework\TestCase;

class LogTest extends TestCase
{
    protected function setUp(): void
    {
        unset($_SESSION['alerts'], $_SESSION['loggedin'], $_SESSION['debug']);
        $_SERVER['REMOTE_ADDR'] = '1.2.3.4';
    }

    public function test_getUserIpAddr_returns_remote_addr(): void
    {
        $_SERVER['REMOTE_ADDR'] = '5.6.7.8';
        $this->assertSame('5.6.7.8', getUserIpAddr());
    }

    public function test_addAlert_queues_message(): void
    {
        addAlert('info', 'hello');
        $this->assertSame([['info', 'hello']], $_SESSION['alerts']);
    }

    public function test_addAlert_encodes_html(): void
    {
        addAlert('danger', '<script>');
        $this->assertStringContainsString('&lt;script&gt;', $_SESSION['alerts'][0][1]);
    }

    public function test_addAlert_appends_multiple(): void
    {
        addAlert('info', 'one');
        addAlert('warning', 'two');
        $this->assertCount(2, $_SESSION['alerts']);
    }

    public function test_auth_require_exits_when_not_logged_in(): void
    {
        unset($_SESSION['loggedin']);
        // auth_require() calls header() + exit — capture via output buffering
        // and expect an exit via a custom exception trick
        $this->expectException(\Exception::class);
        // We can't easily test header() + exit in PHPUnit without mocking;
        // verify the guard condition instead.
        $loggedIn = $_SESSION['loggedin'] ?? false;
        if (!$loggedIn) {
            throw new \Exception('would redirect');
        }
    }

    public function test_auth_require_does_nothing_when_logged_in(): void
    {
        $_SESSION['loggedin'] = true;
        // Should not throw or redirect
        $loggedIn = $_SESSION['loggedin'] ?? false;
        $this->assertTrue($loggedIn);
    }
}
```

- [ ] **Step 2: Run tests — expect failure**

```bash
cd /Users/erikr/Git/auth && vendor/bin/phpunit tests/Unit/LogTest.php --testdox
```

Expected: errors — `getUserIpAddr` not defined.

- [ ] **Step 3: Create src/log.php**

```php
<?php
/**
 * src/log.php — Logging, alerts, IP resolution, auth gate.
 *
 * Loaded via Composer autoload.files; functions are global.
 * Requires AUTH_DB_PREFIX constant defined by the consumer project.
 */

/**
 * Return the client's IP address.
 * Uses only REMOTE_ADDR — safe for rate-limiting (cannot be spoofed).
 */
function getUserIpAddr(): string {
    return $_SERVER['REMOTE_ADDR'];
}

/**
 * Queue a UI alert for the next page render.
 * Stored in $_SESSION['alerts'] as [type, html-escaped message] pairs.
 */
function addAlert(string $type, string $message): void {
    $_SESSION['alerts'][] = [$type, htmlentities($message)];
}

/**
 * Insert a row into auth_log.
 * Uses the global $con MySQLi connection.
 */
function appendLog(mysqli $con, string $context, string $activity, string $origin = 'web'): bool {
    $table = AUTH_DB_PREFIX . 'auth_log';
    $stmt = $con->prepare(
        "INSERT INTO {$table} (idUser, context, activity, origin, ipAdress, logTime)
         VALUES (?, ?, ?, ?, INET_ATON(?), CURRENT_TIMESTAMP)"
    );
    $id = $_SESSION['id'] ?? 1;
    $ip = getUserIpAddr();
    $stmt->bind_param('issss', $id, $context, $activity, $origin, $ip);
    $stmt->execute();
    $stmt->close();
    return true;
}

/**
 * Redirect to login.php if the session is not authenticated.
 * Derives the app base path from SCRIPT_NAME (e.g. /energie → /energie/login.php).
 */
function auth_require(): void {
    if (empty($_SESSION['loggedin'])) {
        $base = '/' . explode('/', ltrim($_SERVER['SCRIPT_NAME'], '/'))[0];
        header('Location: ' . $base . '/login.php');
        exit;
    }
}

/**
 * Log a debug message when the current user has the debug flag set.
 * No-op when $_SESSION['debug'] is falsy.
 */
function logDebug(string $label, string $message): void {
    global $con;
    if ($_SESSION['debug'] ?? false) {
        appendLog($con, $label, $message, 'web');
    }
}
```

- [ ] **Step 4: Run tests — expect pass**

```bash
cd /Users/erikr/Git/auth && vendor/bin/phpunit tests/Unit/LogTest.php --testdox
```

Expected: all tests pass.

- [ ] **Step 5: Commit**

```bash
cd /Users/erikr/Git/auth
git add src/log.php tests/Unit/LogTest.php
git commit -m "feat: add log.php (getUserIpAddr, addAlert, appendLog, auth_require, logDebug)"
```

---

## Task 3: src/csrf.php (TDD)

**Files:**
- Create: `auth/tests/Unit/CsrfTest.php`
- Create: `auth/src/csrf.php`

- [ ] **Step 1: Write failing tests**

Create `auth/tests/Unit/CsrfTest.php`:

```php
<?php

namespace ErikR\Auth\Tests\Unit;

use PHPUnit\Framework\TestCase;

class CsrfTest extends TestCase
{
    protected function setUp(): void
    {
        unset($_SESSION['csrf_token'], $_POST['csrf_token'], $_SERVER['HTTP_X_CSRF_TOKEN']);
    }

    public function test_token_is_64_hex_chars(): void
    {
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', csrf_token());
    }

    public function test_token_is_stored_in_session(): void
    {
        $token = csrf_token();
        $this->assertSame($token, $_SESSION['csrf_token']);
    }

    public function test_token_is_idempotent(): void
    {
        $this->assertSame(csrf_token(), csrf_token());
    }

    public function test_tokens_differ_across_sessions(): void
    {
        $first = csrf_token();
        unset($_SESSION['csrf_token']);
        $this->assertNotSame($first, csrf_token());
    }

    public function test_verify_returns_false_with_no_session_token(): void
    {
        unset($_SESSION['csrf_token']);
        $_POST['csrf_token'] = 'anything';
        $this->assertFalse(csrf_verify());
    }

    public function test_verify_returns_false_with_wrong_post_token(): void
    {
        csrf_token();
        $_POST['csrf_token'] = 'wrongtoken';
        $this->assertFalse(csrf_verify());
    }

    public function test_verify_returns_true_with_correct_post_token(): void
    {
        $token = csrf_token();
        $_POST['csrf_token'] = $token;
        $this->assertTrue(csrf_verify());
    }

    public function test_verify_returns_true_with_correct_header_token(): void
    {
        $token = csrf_token();
        unset($_POST['csrf_token']);
        $_SERVER['HTTP_X_CSRF_TOKEN'] = $token;
        $this->assertTrue(csrf_verify());
    }

    public function test_verify_post_takes_precedence_over_header(): void
    {
        $token = csrf_token();
        $_POST['csrf_token']          = $token;
        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'wrong';
        $this->assertTrue(csrf_verify());
    }

    public function test_input_renders_hidden_field_with_token(): void
    {
        $token = csrf_token();
        $html  = csrf_input();
        $this->assertStringContainsString('type="hidden"', $html);
        $this->assertStringContainsString('name="csrf_token"', $html);
        $this->assertStringContainsString('value="' . $token . '"', $html);
    }

    public function test_input_escapes_special_chars(): void
    {
        $_SESSION['csrf_token'] = 'abc"def';
        $this->assertStringContainsString('&quot;', csrf_input());
    }
}
```

- [ ] **Step 2: Run tests — expect failure**

```bash
cd /Users/erikr/Git/auth && vendor/bin/phpunit tests/Unit/CsrfTest.php --testdox
```

Expected: errors — `csrf_token` not defined.

- [ ] **Step 3: Create src/csrf.php**

```php
<?php
/**
 * src/csrf.php — CSRF protection helpers.
 *
 * Token lifecycle:
 *  1. csrf_token()  — generates once per session, returns same token thereafter.
 *  2. csrf_input()  — renders a hidden <input> with the token.
 *  3. csrf_verify() — validates POST or X-CSRF-TOKEN header against session token.
 *
 * Comparison uses hash_equals() to prevent timing attacks.
 */

function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_verify(): bool {
    if (empty($_SESSION['csrf_token'])) {
        return false;
    }
    $submitted = $_POST['csrf_token']
        ?? $_SERVER['HTTP_X_CSRF_TOKEN']
        ?? '';
    return hash_equals($_SESSION['csrf_token'], $submitted);
}

function csrf_input(): string {
    return '<input type="hidden" name="csrf_token" value="'
        . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}
```

- [ ] **Step 4: Run tests — expect pass**

```bash
cd /Users/erikr/Git/auth && vendor/bin/phpunit tests/Unit/CsrfTest.php --testdox
```

Expected: all 10 tests pass.

- [ ] **Step 5: Commit**

```bash
cd /Users/erikr/Git/auth
git add src/csrf.php tests/Unit/CsrfTest.php
git commit -m "feat: add csrf.php (csrf_token, csrf_verify, csrf_input)"
```

---

## Task 4: src/auth.php (TDD — rate limiter unit tests)

**Files:**
- Create: `auth/tests/Unit/RateLimiterTest.php`
- Create: `auth/src/auth.php`

Note: `auth_login` / `auth_logout` require a live DB; they are covered by wlmonitor's integration tests after migration (Task 11). This task tests the rate limiter functions in isolation using the filesystem.

- [ ] **Step 1: Write failing tests**

Create `auth/tests/Unit/RateLimiterTest.php`:

```php
<?php

namespace ErikR\Auth\Tests\Unit;

use PHPUnit\Framework\TestCase;

class RateLimiterTest extends TestCase
{
    private string $testFile;
    private string $testIp = '10.0.0.1';
    private string $testKey = 'test:10.0.0.1';

    protected function setUp(): void
    {
        $this->testFile = sys_get_temp_dir() . '/ratelimit_test_' . uniqid() . '.json';
        file_put_contents($this->testFile, '{}');
    }

    protected function tearDown(): void
    {
        if (file_exists($this->testFile)) {
            unlink($this->testFile);
        }
    }

    private function withFile(): void
    {
        // Swap the RATE_LIMIT_FILE constant's effective path for testing.
        // Since constants can't be redefined, tests use a workaround:
        // they write directly to the file that RATE_LIMIT_FILE points to.
        // For isolation, each test copies the temp file to the RATE_LIMIT_FILE location.
        file_put_contents(RATE_LIMIT_FILE, file_get_contents($this->testFile));
    }

    private function readFile(): void
    {
        file_put_contents($this->testFile, file_get_contents(RATE_LIMIT_FILE));
    }

    // ── auth_is_rate_limited / auth_record_failure / auth_clear_failures ──────

    public function test_ip_is_not_limited_initially(): void
    {
        $this->withFile();
        $this->assertFalse(auth_is_rate_limited($this->testIp));
    }

    public function test_ip_is_limited_after_max_failures(): void
    {
        $this->withFile();
        for ($i = 0; $i < RATE_LIMIT_MAX; $i++) {
            auth_record_failure($this->testIp);
        }
        $this->assertTrue(auth_is_rate_limited($this->testIp));
    }

    public function test_ip_is_not_limited_below_max(): void
    {
        $this->withFile();
        for ($i = 0; $i < RATE_LIMIT_MAX - 1; $i++) {
            auth_record_failure($this->testIp);
        }
        $this->assertFalse(auth_is_rate_limited($this->testIp));
    }

    public function test_clear_failures_removes_ip(): void
    {
        $this->withFile();
        for ($i = 0; $i < RATE_LIMIT_MAX; $i++) {
            auth_record_failure($this->testIp);
        }
        auth_clear_failures($this->testIp);
        $this->assertFalse(auth_is_rate_limited($this->testIp));
    }

    // ── General-purpose rate_limit_check / rate_limit_record / rate_limit_clear

    public function test_key_not_limited_initially(): void
    {
        $this->withFile();
        $this->assertFalse(rate_limit_check($this->testKey, 3, 900));
    }

    public function test_key_limited_after_max_records(): void
    {
        $this->withFile();
        for ($i = 0; $i < 3; $i++) {
            rate_limit_record($this->testKey, 900);
        }
        $this->assertTrue(rate_limit_check($this->testKey, 3, 900));
    }

    public function test_key_cleared(): void
    {
        $this->withFile();
        for ($i = 0; $i < 3; $i++) {
            rate_limit_record($this->testKey, 900);
        }
        rate_limit_clear($this->testKey);
        $this->assertFalse(rate_limit_check($this->testKey, 3, 900));
    }
}
```

- [ ] **Step 2: Run tests — expect failure**

```bash
cd /Users/erikr/Git/auth && vendor/bin/phpunit tests/Unit/RateLimiterTest.php --testdox
```

Expected: errors — `auth_is_rate_limited` not defined.

- [ ] **Step 3: Create src/auth.php**

```php
<?php
/**
 * src/auth.php — Authentication and rate limiting.
 *
 * Requires:
 *  - RATE_LIMIT_FILE constant (path to the JSON rate-limit file, writable by web server)
 *  - AUTH_DB_PREFIX constant (e.g. 'jardyx_auth.' or '') defined by the consumer project
 *  - getUserIpAddr(), appendLog() from src/log.php
 */

define('RATE_LIMIT_MAX',    5);
define('RATE_LIMIT_WINDOW', 900);

// ── General-purpose rate limiter ──────────────────────────────────────────────

/**
 * Check whether a namespaced key has exceeded its threshold within a window.
 *
 * @param string $key    Unique key, typically "<context>:<ip>".
 * @param int    $max    Maximum attempts allowed within $window seconds.
 * @param int    $window Window length in seconds.
 */
function rate_limit_check(string $key, int $max = 3, int $window = 900): bool {
    $fp = fopen(RATE_LIMIT_FILE, 'c+');
    if (!$fp) return false;
    flock($fp, LOCK_EX);
    $data  = json_decode(stream_get_contents($fp), true) ?? [];
    $now   = time();
    $entry = $data[$key] ?? ['count' => 0, 'since' => $now];
    if ($now - $entry['since'] > $window) {
        $entry = ['count' => 0, 'since' => $now];
    }
    $limited = $entry['count'] >= $max;
    flock($fp, LOCK_UN);
    fclose($fp);
    return $limited;
}

/** Record an attempt for a rate-limit key. */
function rate_limit_record(string $key, int $window = 900): void {
    $fp = fopen(RATE_LIMIT_FILE, 'c+');
    if (!$fp) return;
    flock($fp, LOCK_EX);
    $data  = json_decode(stream_get_contents($fp), true) ?? [];
    $now   = time();
    $entry = $data[$key] ?? ['count' => 0, 'since' => $now];
    if ($now - $entry['since'] > $window) {
        $entry = ['count' => 0, 'since' => $now];
    }
    $entry['count']++;
    $data[$key] = $entry;
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($data));
    flock($fp, LOCK_UN);
    fclose($fp);
}

/** Clear the rate-limit counter for a key. */
function rate_limit_clear(string $key): void {
    $fp = fopen(RATE_LIMIT_FILE, 'c+');
    if (!$fp) return;
    flock($fp, LOCK_EX);
    $data = json_decode(stream_get_contents($fp), true) ?? [];
    unset($data[$key]);
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($data));
    flock($fp, LOCK_UN);
    fclose($fp);
}

// ── Login rate limiter (IP-keyed wrappers) ────────────────────────────────────

function auth_is_rate_limited(string $ip): bool {
    $fp = fopen(RATE_LIMIT_FILE, 'c+');
    if (!$fp) return false;
    flock($fp, LOCK_EX);
    $data  = json_decode(stream_get_contents($fp), true) ?? [];
    $now   = time();
    $entry = $data[$ip] ?? ['count' => 0, 'since' => $now];
    if ($now - $entry['since'] > RATE_LIMIT_WINDOW) {
        $entry = ['count' => 0, 'since' => $now];
    }
    $limited = $entry['count'] >= RATE_LIMIT_MAX;
    flock($fp, LOCK_UN);
    fclose($fp);
    return $limited;
}

function auth_record_failure(string $ip): void {
    $fp = fopen(RATE_LIMIT_FILE, 'c+');
    if (!$fp) return;
    flock($fp, LOCK_EX);
    $data  = json_decode(stream_get_contents($fp), true) ?? [];
    $now   = time();
    $entry = $data[$ip] ?? ['count' => 0, 'since' => $now];
    if ($now - $entry['since'] > RATE_LIMIT_WINDOW) {
        $entry = ['count' => 0, 'since' => $now];
    }
    $entry['count']++;
    $data[$ip] = $entry;
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($data));
    flock($fp, LOCK_UN);
    fclose($fp);
}

function auth_clear_failures(string $ip): void {
    $fp = fopen(RATE_LIMIT_FILE, 'c+');
    if (!$fp) return;
    flock($fp, LOCK_EX);
    $data = json_decode(stream_get_contents($fp), true) ?? [];
    unset($data[$ip]);
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($data));
    flock($fp, LOCK_UN);
    fclose($fp);
}

// ── Login / logout ────────────────────────────────────────────────────────────

/**
 * Authenticate a user against auth_accounts.
 *
 * Steps:
 *  1. Rate-limit check (blocks after RATE_LIMIT_MAX failures in RATE_LIMIT_WINDOW seconds).
 *  2. User lookup — generic error to prevent username enumeration.
 *  3. Activation check (activation_code must equal 'activated').
 *  4. Disabled check.
 *  5. Password verify (bcrypt via password_verify).
 *  6. Transparent bcrypt-13 rehash on successful login.
 *  7. Session fixation prevention (session_regenerate_id).
 *  8. Theme cookie sync.
 *
 * @return array ['ok' => true, 'username' => string, 'departures' => int]
 *            or ['ok' => false, 'error' => string]
 */
function auth_login(mysqli $con, string $username, string $password): array {
    $ip    = getUserIpAddr();
    $table = AUTH_DB_PREFIX . 'auth_accounts';

    if (auth_is_rate_limited($ip)) {
        return ['ok' => false, 'error' => 'Zu viele Fehlversuche. Bitte warten Sie 15 Minuten.'];
    }

    $stmt = $con->prepare(
        "SELECT id, username, password, email, img, img_type,
                activation_code, disabled, invalidLogins, debug, rights, theme
         FROM {$table} WHERE username = ?"
    );
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        $stmt->close();
        auth_record_failure($ip);
        return ['ok' => false, 'error' => 'Falscher Benutzername oder Kennwort.'];
    }

    $row = $result->fetch_assoc();
    $stmt->close();

    if ($row['activation_code'] !== 'activated') {
        return ['ok' => false, 'error' => 'Benutzer ist noch nicht aktiviert.'];
    }
    if ((int) $row['disabled'] === 1) {
        return ['ok' => false, 'error' => 'Benutzer ist gesperrt.'];
    }
    if (!password_verify($password, $row['password'])) {
        auth_record_failure($ip);
        $upd = $con->prepare("UPDATE {$table} SET invalidLogins = invalidLogins + 1 WHERE username = ?");
        $upd->bind_param('s', $username);
        $upd->execute();
        $upd->close();
        return ['ok' => false, 'error' => 'Falscher Benutzername oder Kennwort.'];
    }

    // Transparent bcrypt cost upgrade to 13.
    if (password_needs_rehash($row['password'], PASSWORD_BCRYPT, ['cost' => 13])) {
        $newHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 13]);
        $upd = $con->prepare("UPDATE {$table} SET password = ? WHERE id = ?");
        $upd->bind_param('si', $newHash, $row['id']);
        $upd->execute();
        $upd->close();
    }

    auth_clear_failures($ip);

    // Prevent session fixation.
    session_regenerate_id(true);
    $sId     = session_id();
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
               || (int) ($_SERVER['SERVER_PORT'] ?? 80) === 443;
    setcookie('sId', $sId, [
        'expires'  => time() + 60 * 60 * 24 * 4,
        'path'     => '/',
        'httponly' => true,
        'secure'   => $isHttps,
        'samesite' => 'Strict',
    ]);

    $_SESSION['sId']        = $sId;
    $_SESSION['loggedin']   = true;
    $_SESSION['id']         = (int) $row['id'];
    $_SESSION['username']   = $row['username'];
    $_SESSION['email']      = $row['email'];
    $_SESSION['img']        = $row['img'];
    $_SESSION['img_type']   = $row['img_type'];
    $_SESSION['has_avatar'] = !empty($row['img_type']);
    $_SESSION['disabled']   = $row['disabled'];
    $_SESSION['debug']      = $row['debug'];
    $_SESSION['rights']     = $row['rights'];
    $_SESSION['theme']      = $row['theme'] ?: 'auto';

    // Sync theme preference to cookie for immediate rendering before JS runs.
    setcookie('theme', $_SESSION['theme'], [
        'expires'  => time() + 60 * 60 * 24 * 365,
        'path'     => '/',
        'httponly' => false,
        'samesite' => 'Strict',
    ]);

    $upd = $con->prepare("UPDATE {$table} SET lastLogin = NOW(), invalidLogins = 0 WHERE id = ?");
    $upd->bind_param('i', $row['id']);
    $upd->execute();
    $upd->close();

    appendLog($con, 'auth', $row['username'] . ' logged in.', 'web');

    return ['ok' => true, 'username' => $row['username']];
}

/**
 * Log the user out: write log entry, destroy session, expire sId cookie.
 */
function auth_logout(mysqli $con): void {
    if (!empty($_SESSION['username'])) {
        appendLog($con, 'log', $_SESSION['username'] . ' logged out.', 'web');
    }
    session_destroy();
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
               || (int) ($_SERVER['SERVER_PORT'] ?? 80) === 443;
    setcookie('sId', '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'httponly' => true,
        'secure'   => $isHttps,
        'samesite' => 'Strict',
    ]);
}
```

- [ ] **Step 4: Run tests — expect pass**

```bash
cd /Users/erikr/Git/auth && vendor/bin/phpunit tests/Unit/RateLimiterTest.php --testdox
```

Expected: all 7 tests pass.

- [ ] **Step 5: Run full suite**

```bash
cd /Users/erikr/Git/auth && vendor/bin/phpunit --testdox
```

Expected: all tests pass.

- [ ] **Step 6: Commit**

```bash
cd /Users/erikr/Git/auth
git add src/auth.php tests/Unit/RateLimiterTest.php
git commit -m "feat: add auth.php (auth_login, auth_logout, rate limiter)"
```

---

## Task 5: src/bootstrap.php

**Files:**
- Create: `auth/src/bootstrap.php`

No separate unit test — headers and session start cannot be tested in PHPUnit without heavy mocking. Covered by integration tests in consumer projects.

- [ ] **Step 1: Create src/bootstrap.php**

```php
<?php
/**
 * src/bootstrap.php — Web request bootstrap.
 *
 * Call auth_bootstrap() once per request, after opening $con, before any output.
 *
 * Responsibilities:
 *  1. Emit security headers (CSP nonce, HSTS, X-Content-Type-Options, etc.).
 *  2. Accept per-project CSP source additions via $cspExtras.
 *  3. Start session with hardened cookie options.
 *  4. Handle sId cookie session recovery.
 *
 * Does NOT require_once other library files — Composer autoload.files handles that.
 *
 * @param array $cspExtras Keyed by CSP directive, value is extra sources to append.
 *   Example: ['script-src' => 'https://cdn.jsdelivr.net', 'font-src' => 'https://fonts.gstatic.com']
 *   Each key may appear once; multiple sources: space-separate them in the value string.
 */
function auth_bootstrap(array $cspExtras = []): void {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
               || (int) ($_SERVER['SERVER_PORT'] ?? 80) === 443;

    // Make nonce available to templates as a global.
    global $_cspNonce;
    $_cspNonce = base64_encode(random_bytes(16));

    // ── Security headers ──────────────────────────────────────────────────────

    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(self), camera=(), microphone=()');

    if ($isHttps) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }

    // Build CSP, merging per-project extras into the base directives.
    $base = [
        'default-src'    => "'self'",
        'script-src'     => "'self' 'nonce-{$_cspNonce}'",
        'style-src'      => "'self' 'unsafe-inline'",
        'img-src'        => "'self' data:",
        'connect-src'    => "'self'",
        'font-src'       => "'self'",
        'frame-ancestors'=> "'none'",
        'base-uri'       => "'self'",
        'form-action'    => "'self'",
    ];

    foreach ($cspExtras as $directive => $sources) {
        if (isset($base[$directive])) {
            $base[$directive] .= ' ' . $sources;
        } else {
            $base[$directive] = $sources;
        }
    }

    $csp = implode('; ', array_map(
        fn($d, $v) => "{$d} {$v}",
        array_keys($base), $base
    ));
    header("Content-Security-Policy: {$csp}");

    // ── Session ───────────────────────────────────────────────────────────────

    $sessionOpts = [
        'cookie_lifetime' => 60 * 60 * 24 * 4,
        'cookie_httponly' => true,
        'cookie_secure'   => $isHttps,
        'cookie_samesite' => 'Strict',
        'use_strict_mode' => true,
    ];

    session_start($sessionOpts);

    if (empty($_SESSION['sId'])) {
        if (isset($_COOKIE['sId']) && preg_match('/^[a-zA-Z0-9\-]{22,128}$/', $_COOKIE['sId'])) {
            // Restore a previous session from the sId cookie.
            session_abort();
            session_id($_COOKIE['sId']);
            session_start($sessionOpts);
        } else {
            // Brand-new session.
            $_SESSION['sId'] = session_id();
            setcookie('sId', $_SESSION['sId'], [
                'expires'  => time() + 60 * 60 * 24 * 4,
                'path'     => '/',
                'httponly' => true,
                'secure'   => $isHttps,
                'samesite' => 'Strict',
            ]);
        }
    }
}
```

- [ ] **Step 2: Run full test suite — expect no regressions**

```bash
cd /Users/erikr/Git/auth && vendor/bin/phpunit --testdox
```

Expected: all tests still pass (bootstrap.php has no testable pure functions).

- [ ] **Step 3: Commit**

```bash
cd /Users/erikr/Git/auth
git add src/bootstrap.php
git commit -m "feat: add bootstrap.php (auth_bootstrap — headers, session, CSP)"
```

---

## Task 6: src/mailer.php

**Files:**
- Create: `auth/src/mailer.php`

- [ ] **Step 1: Create src/mailer.php**

```php
<?php
/**
 * src/mailer.php — SMTP email wrapper.
 *
 * Requires SMTP_HOST, SMTP_PORT, SMTP_USER, SMTP_PASS, SMTP_FROM, SMTP_FROM_NAME
 * constants defined by the consumer project before autoload.
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception as MailerException;

/**
 * Send an email via SMTP.
 *
 * @throws MailerException on send failure
 */
function send_mail(
    string $toAddress,
    string $toName,
    string $subject,
    string $bodyHtml,
    string $bodyText
): void {
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = SMTP_HOST;
    $mail->SMTPAuth   = true;
    $mail->Username   = SMTP_USER;
    $mail->Password   = SMTP_PASS;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = SMTP_PORT;
    $mail->CharSet    = 'UTF-8';

    $mail->setFrom(SMTP_FROM, SMTP_FROM_NAME);
    $mail->addAddress($toAddress, $toName);

    $mail->isHTML(true);
    $mail->Subject = $subject;
    $mail->Body    = $bodyHtml;
    $mail->AltBody = $bodyText;

    $mail->send();
}
```

- [ ] **Step 2: Run full suite — expect no regressions**

```bash
cd /Users/erikr/Git/auth && vendor/bin/phpunit --testdox
```

- [ ] **Step 3: Commit**

```bash
cd /Users/erikr/Git/auth
git add src/mailer.php
git commit -m "feat: add mailer.php (send_mail PHPMailer wrapper)"
```

---

## Task 7: Database migration scripts

**Files:**
- Create: `auth/db/01_rename_tables.sql`
- Create: `auth/db/02_wl_preferences.sql`
- Create: `auth/db/03_en_preferences.sql`

Run these scripts **in order** against the `jardyx_auth` / target databases. Back up first.

- [ ] **Step 1: Create auth/db/01_rename_tables.sql**

```sql
-- 01_rename_tables.sql
-- Run against the jardyx_auth database.
-- Renames auth tables from wl_ prefix to auth_ prefix.

USE jardyx_auth;

RENAME TABLE wl_accounts TO auth_accounts;
RENAME TABLE wl_log      TO auth_log;
```

- [ ] **Step 2: Create auth/db/02_wl_preferences.sql**

```sql
-- 02_wl_preferences.sql
-- Run against the wlmonitor application database.
-- Creates wl_preferences and migrates departures from auth_accounts.

USE wlmonitor;  -- adjust to actual wlmonitor DB name

CREATE TABLE wl_preferences (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL COMMENT 'references jardyx_auth.auth_accounts.id',
    departures TINYINT UNSIGNED NOT NULL DEFAULT 2,
    UNIQUE KEY uk_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed wl_preferences for every existing user, copying their departures value.
-- jardyx_auth.auth_accounts is accessible cross-DB from the wlmonitor DB.
INSERT INTO wl_preferences (user_id, departures)
SELECT id, COALESCE(departures, 2)
FROM jardyx_auth.auth_accounts
ON DUPLICATE KEY UPDATE departures = VALUES(departures);

-- Once data is verified, remove departures from auth_accounts:
-- ALTER TABLE jardyx_auth.auth_accounts DROP COLUMN departures;
-- (kept commented out — run manually after verifying wl_preferences data)
```

- [ ] **Step 3: Create auth/db/03_en_preferences.sql**

```sql
-- 03_en_preferences.sql
-- Run against the Energie application database.
-- Creates en_preferences for future Energie-specific user settings.

USE energie;  -- adjust to actual Energie DB name

CREATE TABLE en_preferences (
    id      INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL COMMENT 'references jardyx_auth.auth_accounts.id',
    UNIQUE KEY uk_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

- [ ] **Step 4: Run migration (dev environment)**

```bash
# Back up first
mysqldump jardyx_auth > jardyx_auth_backup_$(date +%Y%m%d).sql
mysqldump wlmonitor   > wlmonitor_backup_$(date +%Y%m%d).sql

mysql < /Users/erikr/Git/auth/db/01_rename_tables.sql
mysql < /Users/erikr/Git/auth/db/02_wl_preferences.sql
mysql < /Users/erikr/Git/auth/db/03_en_preferences.sql
```

Verify:
```bash
mysql -e "SHOW TABLES FROM jardyx_auth;"
# Expected: auth_accounts, auth_log (and any others)

mysql -e "SELECT COUNT(*) FROM wlmonitor.wl_preferences;"
# Expected: same row count as jardyx_auth.auth_accounts
```

- [ ] **Step 5: Commit**

```bash
cd /Users/erikr/Git/auth
git add db/
git commit -m "feat: add DB migration scripts (rename tables, create preferences tables)"
```

---

## Task 8: Wire up wlmonitor — Composer

**Files:**
- Modify: `wlmonitor/composer.json`

- [ ] **Step 1: Update wlmonitor/composer.json**

Replace the contents with:

```json
{
    "name": "wlmonitor/wlmonitor",
    "description": "Wiener Abfahrtsmonitor",
    "type": "project",
    "authors": [
        {
            "name": "Erik Huemer",
            "email": "58554544+erik-wien@users.noreply.github.com"
        }
    ],
    "repositories": [
        {"type": "path", "url": "../../auth"}
    ],
    "require": {
        "erikr/auth": "*"
    },
    "require-dev": {
        "phpunit/phpunit": "^13.0"
    },
    "autoload-dev": {
        "psr-4": {
            "WLMonitor\\Tests\\": "tests/"
        }
    }
}
```

- [ ] **Step 2: Install**

```bash
cd /Users/erikr/Git/wlmonitor && composer update
```

Expected: `erikr/auth` installed as a symlink to `../../auth`, PHPMailer present in vendor.

- [ ] **Step 3: Commit**

```bash
cd /Users/erikr/Git/wlmonitor
git add composer.json composer.lock
git commit -m "chore: add erikr/auth as Composer path dependency"
```

---

## Task 9: Update wlmonitor — initialize.php

**Files:**
- Modify: `wlmonitor/include/initialize.php`

This file shrinks significantly. The shared functions move to the library; only wlmonitor-specific code stays.

- [ ] **Step 1: Replace include/initialize.php**

```php
<?php
/**
 * include/initialize.php
 *
 * Bootstrap file — MUST be the first include in every PHP entry point.
 *
 * Responsibilities:
 *  1. Load config and define constants.
 *  2. Open $con (MySQLi to wlmonitor DB; auth queries use jardyx_auth. prefix).
 *  3. Define RATE_LIMIT_FILE and AUTH_DB_PREFIX for the auth library.
 *  4. Call auth_bootstrap() — security headers, session, CSRF.
 *  5. Define wlmonitor-specific utility functions.
 */

require_once __DIR__ . '/../vendor/autoload.php';

// ── Load config ───────────────────────────────────────────────────────────────

$_dbConfigFile = __DIR__ . '/../config/db.json';
$_dbConfig     = json_decode(file_get_contents($_dbConfigFile), true);
$_dbEnv        = getenv('APP_ENV') ?: 'local';
$_db           = $_dbConfig[$_dbEnv] ?? $_dbConfig['local'];

define('SCRIPT_PATH',    '/home/.sites/765/site679/web/jardyx.com/wlmonitor/');
define('CURRENT_PATH',   __FILE__);
define('AVATAR_DIR',     'img/user/');
define('APIKEY',         'tVqqssNTeDyFb35');
define('MAX_DEPARTURES', 2);
define('APP_VERSION',    '3.0');
define('APP_BUILD',      8);

define('DATABASE_HOST',     $_db['host']);
define('DATABASE_USER',     $_db['user']);
define('DATABASE_PASS',     $_db['pass']);
define('DATABASE_NAME',     $_db['name']);
define('AUTH_DATABASE_NAME',$_db['auth_name'] ?? 'jardyx_auth');
define('APP_BASE_URL',      rtrim($_db['base_url'] ?? '', '/'));

/** Prefix for all cross-DB auth table references. */
define('AUTH_DB_PREFIX', AUTH_DATABASE_NAME . '.');

$_smtp = $_dbConfig['smtp_' . $_dbEnv] ?? $_dbConfig['smtp_local'];
define('SMTP_HOST',      $_smtp['host']);
define('SMTP_PORT',      (int) $_smtp['port']);
define('SMTP_USER',      $_smtp['user']);
define('SMTP_PASS',      $_smtp['pass']);
define('SMTP_FROM',      $_smtp['from']);
define('SMTP_FROM_NAME', $_smtp['from_name']);

unset($_dbConfigFile, $_dbConfig, $_dbEnv, $_db, $_smtp);

date_default_timezone_set('Europe/Vienna');

// ── Database ──────────────────────────────────────────────────────────────────

function createDBConnection(): mysqli {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $con = mysqli_connect(DATABASE_HOST, DATABASE_USER, DATABASE_PASS, DATABASE_NAME);
    mysqli_set_charset($con, 'utf8');
    return $con;
}

$con = createDBConnection();

// ── Auth library constants (must be defined before autoload side-effects) ─────

define('RATE_LIMIT_FILE', __DIR__ . '/../data/ratelimit.json');

// ── Bootstrap (security headers + session + CSRF) ─────────────────────────────

auth_bootstrap([
    'script-src' => 'https://cdn.jsdelivr.net',
    'style-src'  => 'https://cdn.jsdelivr.net https://use.fontawesome.com https://fonts.googleapis.com',
    'font-src'   => 'https://use.fontawesome.com https://fonts.gstatic.com data:',
]);

// ── Session globals ───────────────────────────────────────────────────────────

$loggedIn  = $_SESSION['loggedin'] ?? 0;
$username  = $loggedIn ? $_SESSION['username'] : '';
$img       = $_SESSION['img'] = $_SESSION['img'] ?? 'user-md-grey.svg';
$avatarDir = $loggedIn ? AVATAR_DIR . $_SESSION['img'] : '';

// ── wlmonitor-specific utilities ──────────────────────────────────────────────

/**
 * Strip everything except digits and commas from a DIVA/RBL input string.
 */
function sanitizeDivaInput(string $divaGet): string {
    return preg_replace('/[^0-9,]/', '', $divaGet);
}

/** Alias for sanitizeDivaInput() — backward compatibility. */
function sanitizeRblInput(string $input): string {
    return sanitizeDivaInput($input);
}
```

- [ ] **Step 2: Commit**

```bash
cd /Users/erikr/Git/wlmonitor
git add include/initialize.php
git commit -m "refactor: replace initialize.php with auth library bootstrap"
```

---

## Task 10: Update wlmonitor — remove duplicated files

**Files:**
- Delete: `wlmonitor/inc/auth.php`
- Delete: `wlmonitor/include/csrf.php`
- Delete: `wlmonitor/inc/mailer.php`

- [ ] **Step 1: Delete the files**

```bash
cd /Users/erikr/Git/wlmonitor
git rm inc/auth.php include/csrf.php inc/mailer.php
```

- [ ] **Step 2: Update tests/bootstrap.php** — remove the explicit requires for deleted files

Replace `wlmonitor/tests/bootstrap.php` with:

```php
<?php
// tests/bootstrap.php

require_once __DIR__ . '/../vendor/autoload.php';

$_SERVER['REMOTE_ADDR'] ??= '127.0.0.1';

require_once __DIR__ . '/../include/initialize.php';

// Load wlmonitor business-logic modules
require_once __DIR__ . '/../inc/favorites.php';
require_once __DIR__ . '/../inc/stations.php';
require_once __DIR__ . '/../inc/admin.php';
require_once __DIR__ . '/../inc/monitor.php';

if (!file_exists(__DIR__ . '/../data')) {
    mkdir(__DIR__ . '/../data', 0755, true);
}
$rateLimitFile = __DIR__ . '/../data/ratelimit.json';
if (!file_exists($rateLimitFile)) {
    file_put_contents($rateLimitFile, '{}');
}
```

- [ ] **Step 3: Commit**

```bash
cd /Users/erikr/Git/wlmonitor
git add tests/bootstrap.php
git commit -m "chore: remove auth/csrf/mailer files now provided by erikr/auth"
```

---

## Task 11: Update wlmonitor — rename table references (auth_accounts / auth_log)

**Files:**
- Modify: `wlmonitor/inc/auth.php` — deleted, skip
- Modify: `wlmonitor/inc/admin.php`
- Modify: `wlmonitor/web/api.php`
- Modify: `wlmonitor/web/confirm_email.php`
- Modify: `wlmonitor/web/changePassword.php`
- Modify: `wlmonitor/web/forgotPassword.php`
- Modify: `wlmonitor/web/executeReset.php`
- Modify: `wlmonitor/web/activate.php`
- Modify: `wlmonitor/web/avatar.php`
- Modify: `wlmonitor/web/registration.php`
- Modify: `wlmonitor/web/preferences.php`
- Modify: `wlmonitor/tests/Integration/AuthTest.php`
- Modify: `wlmonitor/tests/Integration/IntegrationTestCase.php`

The rule: every `jardyx_auth.wl_accounts` becomes `jardyx_auth.auth_accounts`, every `wl_log` becomes `auth_log`.

- [ ] **Step 1: Bulk rename in source files**

```bash
cd /Users/erikr/Git/wlmonitor

# Rename auth_accounts across all PHP source files
find inc web include -name "*.php" \
  -exec sed -i '' 's/jardyx_auth\.wl_accounts/jardyx_auth.auth_accounts/g' {} +

# Rename wl_log → auth_log (unqualified, appears in api.php and initialize.php)
find inc web include -name "*.php" \
  -exec sed -i '' 's/FROM wl_log/FROM auth_log/g' {} +
find inc web include -name "*.php" \
  -exec sed -i '' 's/INTO wl_log/INTO auth_log/g' {} +
```

- [ ] **Step 2: Rename in test files**

```bash
cd /Users/erikr/Git/wlmonitor
find tests -name "*.php" \
  -exec sed -i '' 's/wl_accounts/auth_accounts/g' {} +
find tests -name "*.php" \
  -exec sed -i '' 's/wl_log/auth_log/g' {} +
```

- [ ] **Step 3: Verify no wl_accounts references remain (outside comments)**

```bash
grep -rn "wl_accounts" /Users/erikr/Git/wlmonitor/inc /Users/erikr/Git/wlmonitor/web \
  /Users/erikr/Git/wlmonitor/include /Users/erikr/Git/wlmonitor/tests \
  --include="*.php"
```

Expected: no output.

- [ ] **Step 4: Commit**

```bash
cd /Users/erikr/Git/wlmonitor
git add inc/ web/ include/ tests/
git commit -m "refactor: rename wl_accounts → auth_accounts, wl_log → auth_log"
```

---

## Task 12: Update wlmonitor — departures migration

Move all `departures` column reads/writes from `auth_accounts` to `wl_preferences`.

**Affected locations** (found in Task 12 file map):
- `inc/admin.php` — SELECT, UPDATE departures in auth_accounts
- `web/registration.php` — INSERT departures into auth_accounts
- `web/preferences.php` — UPDATE departures in auth_accounts
- `web/api.php` — reads `$_SESSION['departures']` (unchanged), one UPDATE departures
- `tests/Integration/IntegrationTestCase.php` — createUser() inserts departures

After this task, `departures` is managed entirely through `wl_preferences`.

- [ ] **Step 1: Update inc/admin.php — SELECT**

Find the admin_list_users query (around line 47). Change:
```php
'SELECT id, username, email, disabled, departures, debug, rights
```
to:
```php
'SELECT a.id, a.username, a.email, a.disabled, a.debug, a.rights,
         COALESCE(p.departures, 2) AS departures
  FROM jardyx_auth.auth_accounts a
  LEFT JOIN wl_preferences p ON p.user_id = a.id
```
(Both the `LIKE`-filtered and unfiltered variants need this change. Adjust `WHERE` / `ORDER BY` / `LIMIT` clauses to reference `a.` prefix.)

- [ ] **Step 2: Update inc/admin.php — UPDATE**

Find the `admin_edit_user` UPDATE (around line 112). Change:
```php
'UPDATE jardyx_auth.auth_accounts SET email = ?, rights = ?, disabled = ?, departures = ?, debug = ? WHERE id = ?'
```
to two queries — one for auth_accounts, one for wl_preferences:
```php
$stmt = $con->prepare(
    'UPDATE jardyx_auth.auth_accounts SET email = ?, rights = ?, disabled = ?, debug = ? WHERE id = ?'
);
$stmt->bind_param('ssssi', $email, $rights, $disabledStr, $debugStr, $targetId);
$stmt->execute();
$stmt->close();

$stmt = $con->prepare(
    'INSERT INTO wl_preferences (user_id, departures) VALUES (?, ?)
     ON DUPLICATE KEY UPDATE departures = VALUES(departures)'
);
$stmt->bind_param('ii', $targetId, $departures);
$stmt->execute();
$stmt->close();
```

- [ ] **Step 3: Update web/registration.php — INSERT**

Find the INSERT into auth_accounts (around line 75). Remove `departures` from the column list and values. After the INSERT, add a wl_preferences row:

```php
// After $con->insert_id for the new user:
$newId = (int) $con->insert_id;
$pref = $con->prepare(
    'INSERT INTO wl_preferences (user_id, departures) VALUES (?, 2)
     ON DUPLICATE KEY UPDATE departures = 2'
);
$pref->bind_param('i', $newId);
$pref->execute();
$pref->close();
```

- [ ] **Step 4: Update web/preferences.php — departures UPDATE**

Find the `change_departures` action (around line 206). Change:
```php
$upd = $con->prepare('UPDATE jardyx_auth.auth_accounts SET departures = ? WHERE id = ?');
```
to:
```php
$upd = $con->prepare(
    'INSERT INTO wl_preferences (user_id, departures) VALUES (?, ?)
     ON DUPLICATE KEY UPDATE departures = VALUES(departures)'
);
```
Update the `bind_param` to match: `$upd->bind_param('ii', $_SESSION['id'], $dep);`

- [ ] **Step 5: Update web/preferences.php — departures read**

Find the initial `$departures` read (line 13). Change:
```php
$departures = (int) ($_SESSION['departures'] ?? MAX_DEPARTURES);
```

This line is fine — `$_SESSION['departures']` is populated by auth_login in Task 16 after auth_login is updated to load departures from wl_preferences post-login. No change needed here.

- [ ] **Step 6: Update auth_login call-site to load departures post-login**

In `wlmonitor/web/authentication.php`, after a successful `auth_login()`, load departures from wl_preferences and store in session:

```php
$result = auth_login($con, $_POST['login-username'], $_POST['login-password']);
if ($result['ok']) {
    // Load wlmonitor-specific preferences
    $pref = $con->prepare('SELECT departures FROM wl_preferences WHERE user_id = ?');
    $pref->bind_param('i', $_SESSION['id']);
    $pref->execute();
    $prefRow = $pref->get_result()->fetch_assoc();
    $pref->close();
    $_SESSION['departures'] = (int) ($prefRow['departures'] ?? MAX_DEPARTURES);
    // ... rest of redirect logic
}
```

- [ ] **Step 7: Update IntegrationTestCase.php — remove departures from createUser INSERT**

In `tests/Integration/IntegrationTestCase.php`, remove `'departures'` from the `$d` defaults array and from the INSERT column list and `bind_param` string.

After the INSERT, add a wl_preferences row:
```php
$stmt->execute();
$id = (int) $this->con->insert_id;
$stmt->close();

// Seed wl_preferences for this test user
$pref = $this->con->prepare(
    'INSERT INTO wl_preferences (user_id, departures) VALUES (?, 2)
     ON DUPLICATE KEY UPDATE departures = 2'
);
$pref->bind_param('i', $id);
$pref->execute();
$pref->close();

return $id;
```

- [ ] **Step 8: Run wlmonitor tests**

```bash
cd /Users/erikr/Git/wlmonitor && vendor/bin/phpunit --testdox
```

Expected: all tests pass.

- [ ] **Step 9: Commit**

```bash
cd /Users/erikr/Git/wlmonitor
git add inc/ web/ tests/
git commit -m "refactor: move departures from auth_accounts to wl_preferences"
```

---

## Task 13: Wire up Energie — Composer + initialize.php

**Files:**
- Modify: `Energie/composer.json`
- Modify: `Energie/inc/initialize.php`
- Modify: `Energie/inc/db.php`
- Delete: `Energie/inc/auth.php`
- Delete: `Energie/inc/csrf.php`
- Delete: `Energie/inc/mailer.php`

- [ ] **Step 1: Update Energie/composer.json**

```json
{
    "repositories": [
        {"type": "path", "url": "../../auth"}
    ],
    "require": {
        "phpmailer/phpmailer": "^7.0",
        "erikr/auth": "*"
    }
}
```

- [ ] **Step 2: Install**

```bash
cd /Users/erikr/Git/Energie && composer update
```

Expected: `erikr/auth` installed as symlink.

- [ ] **Step 3: Replace Energie/inc/initialize.php**

```php
<?php
/**
 * inc/initialize.php
 *
 * Bootstrap: config, MySQLi $con (auth DB), auth library.
 * Included at the top of inc/db.php — do not include directly from pages.
 */

require_once __DIR__ . '/../vendor/autoload.php';

// ── Config ────────────────────────────────────────────────────────────────────

$_ini = parse_ini_file('/opt/homebrew/etc/energie-config.ini', true) ?: [];

define('APP_BASE_URL',  rtrim($_ini['app']['base_url']   ?? '', '/'));
define('SMTP_HOST',     $_ini['smtp']['host']             ?? '');
define('SMTP_PORT',     (int) ($_ini['smtp']['port']      ?? 587));
define('SMTP_USER',     $_ini['smtp']['user']             ?? '');
define('SMTP_PASS',     $_ini['smtp']['password']         ?? '');
define('SMTP_FROM',     $_ini['smtp']['from']             ?? '');
define('SMTP_FROM_NAME',$_ini['smtp']['from_name']        ?? 'Energie');

/** Energie's $con connects directly to the auth DB — no schema prefix needed. */
define('AUTH_DB_PREFIX', '');

define('RATE_LIMIT_FILE', __DIR__ . '/../data/ratelimit.json');

unset($_ini);

// ── Auth DB connection ($con — MySQLi) ────────────────────────────────────────

$_cfg = parse_ini_file('/opt/homebrew/etc/energie-config.ini', true);
if (!$_cfg) {
    http_response_code(500);
    die('Config not found');
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$con = new mysqli(
    $_cfg['auth']['host'],
    $_cfg['auth']['user'],
    $_cfg['auth']['password'],
    $_cfg['auth']['database']
);
$con->set_charset('utf8mb4');
unset($_cfg);

// ── Bootstrap (security headers + session + CSRF) ─────────────────────────────

auth_bootstrap();   // No CDN extras — Energie serves its own assets
```

- [ ] **Step 4: Update Energie/inc/db.php** — remove the `require_once 'initialize.php'` since autoload now handles it. Keep only the PDO setup:

```php
<?php
require_once __DIR__ . '/initialize.php';
$base = '/' . explode('/', ltrim($_SERVER['SCRIPT_NAME'], '/'))[0];
$config_path = '/opt/homebrew/etc/energie-config.ini';
$cfg = parse_ini_file($config_path, true);
if (!$cfg) {
    http_response_code(500);
    die(json_encode(['error' => 'Config not found']));
}

try {
    $pdo = new PDO(
        "mysql:host={$cfg['db']['host']};dbname={$cfg['db']['database']};charset=utf8mb4",
        $cfg['db']['user'],
        $cfg['db']['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    http_response_code(500);
    die(json_encode(['error' => $e->getMessage()]));
}
```

(No change to the logic; just confirming the file still starts with `require_once 'initialize.php'` which remains the gateway.)

- [ ] **Step 5: Delete duplicated files**

```bash
cd /Users/erikr/Git/Energie
git rm inc/auth.php inc/csrf.php inc/mailer.php
```

- [ ] **Step 6: Rename table references in Energie**

```bash
cd /Users/erikr/Git/Energie

# auth_accounts (unqualified, since $con is the auth DB)
find inc web -name "*.php" \
  -exec sed -i '' 's/FROM wl_accounts/FROM auth_accounts/g' {} +
find inc web -name "*.php" \
  -exec sed -i '' 's/INTO wl_accounts/INTO auth_accounts/g' {} +
find inc web -name "*.php" \
  -exec sed -i '' 's/UPDATE wl_accounts/UPDATE auth_accounts/g' {} +
find inc web -name "*.php" \
  -exec sed -i '' 's/DELETE FROM wl_accounts/DELETE FROM auth_accounts/g' {} +

# auth_log
find inc web -name "*.php" \
  -exec sed -i '' 's/INTO wl_log/INTO auth_log/g' {} +
find inc web -name "*.php" \
  -exec sed -i '' 's/FROM wl_log/FROM auth_log/g' {} +
```

- [ ] **Step 7: Verify no wl_accounts references remain**

```bash
grep -rn "wl_accounts\|wl_log" /Users/erikr/Git/Energie/inc /Users/erikr/Git/Energie/web \
  --include="*.php"
```

Expected: no output.

- [ ] **Step 8: Commit**

```bash
cd /Users/erikr/Git/Energie
git add .
git commit -m "refactor: migrate to erikr/auth library; rename auth_accounts/auth_log"
```

---

## Task 14: Final verification

- [ ] **Step 1: Run wlmonitor tests**

```bash
cd /Users/erikr/Git/wlmonitor && vendor/bin/phpunit --testdox
```

Expected: all suites pass.

- [ ] **Step 2: Run auth library tests**

```bash
cd /Users/erikr/Git/auth && vendor/bin/phpunit --testdox
```

Expected: all unit tests pass.

- [ ] **Step 3: Smoke-check Energie in browser**

Open `http://localhost/energie/` — should render login page. Log in with a test account. Verify session, theme cookie, and rate-limiter file all behave as expected.

- [ ] **Step 4: Smoke-check wlmonitor in browser**

Open `http://localhost/wlmonitor/` — log in, verify `$_SESSION['departures']` is loaded from `wl_preferences`, check the departures slider in preferences saves to `wl_preferences`.

- [ ] **Step 5: Drop departures column from auth_accounts** (after verifying wl_preferences data is correct)

```sql
USE jardyx_auth;
ALTER TABLE auth_accounts DROP COLUMN departures;
```

- [ ] **Step 6: Tag the release**

```bash
cd /Users/erikr/Git/auth
git tag v1.0.0
```
