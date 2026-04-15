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
function appendLog(mysqli $con, string $context, string $activity, string $origin = ''): bool {
    if ($origin === '') {
        $origin = defined('APP_SLUG') ? APP_SLUG : 'web';
    }
    $table = AUTH_DB_PREFIX . 'auth_log';
    $stmt = $con->prepare(
        "INSERT INTO {$table} (idUser, context, activity, origin, ipAdress, logTime)
         VALUES (?, ?, ?, ?, INET_ATON(?), CURRENT_TIMESTAMP)"
    );
    if ($stmt === false) {
        return false;
    }
    $id = $_SESSION['id'] ?? 0;
    $ip = getUserIpAddr();
    $stmt->bind_param('issss', $id, $context, $activity, $origin, $ip);
    $result = $stmt->execute();
    $stmt->close();
    return $result;
}

/**
 * Redirect to login.php if the session is not authenticated.
 * Derives the app base path from SCRIPT_NAME.
 * Works for both subpath installs (/energie/index.php → /energie)
 * and vhost-root installs (/index.php → '').
 */
function auth_require(): void {
    if (empty($_SESSION['loggedin'])) {
        $base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
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
