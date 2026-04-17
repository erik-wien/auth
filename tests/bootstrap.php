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

// Mail transport is library-owned and reads /opt/homebrew/etc/jardyx-mail.ini at
// runtime. Unit tests don't hit a live SMTP server — helpers return false on transport
// failure, which is what tests assert. No test fixtures needed here.
