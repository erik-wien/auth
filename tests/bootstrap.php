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

// SMTP constants required by invite_send_email → send_mail.
// Connection to 127.0.0.1:1025 will fail; invite_send_email() catches the exception.
define('SMTP_HOST',      '127.0.0.1');
define('SMTP_PORT',      1025);
define('SMTP_USER',      'test@example.com');
define('SMTP_PASS',      'test');
define('SMTP_FROM',      'test@example.com');
define('SMTP_FROM_NAME', 'Test');
