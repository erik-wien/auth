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
 * @return array ['ok' => true, 'username' => string]
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
    if ($stmt === false) {
        return ['ok' => false, 'error' => 'Datenbankfehler.'];
    }
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
        if ($upd !== false) {
            $upd->bind_param('s', $username);
            $upd->execute();
            $upd->close();
        }
        return ['ok' => false, 'error' => 'Falscher Benutzername oder Kennwort.'];
    }

    // Transparent bcrypt cost upgrade to 13.
    if (password_needs_rehash($row['password'], PASSWORD_BCRYPT, ['cost' => 13])) {
        $newHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 13]);
        $upd = $con->prepare("UPDATE {$table} SET password = ? WHERE id = ?");
        if ($upd !== false) {
            $upd->bind_param('si', $newHash, $row['id']);
            $upd->execute();
            $upd->close();
        }
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
    if ($upd !== false) {
        $upd->bind_param('i', $row['id']);
        $upd->execute();
        $upd->close();
    }

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
