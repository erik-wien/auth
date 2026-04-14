<?php
/**
 * src/auth.php — Authentication, rate limiting, and IP blacklisting.
 *
 * Requires:
 *  - RATE_LIMIT_FILE constant (path to the JSON rate-limit file, writable by web server)
 *  - AUTH_DB_PREFIX constant (e.g. 'jardyx_auth.' or '') defined by the consumer project
 *  - getUserIpAddr(), appendLog() from src/log.php
 */

define('RATE_LIMIT_MAX',        5);
define('RATE_LIMIT_WINDOW',     900);
define('BLACKLIST_AUTO_STRIKES', 2);   // RL events before an IP is auto-blacklisted

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

// ── Rate-limit strike counter (persistent across RL windows) ─────────────────

/**
 * Increment the rate-limit strike counter for an IP.
 * Strikes use key "rl_strikes:<ip>" in RATE_LIMIT_FILE and never expire —
 * they are a long-term reputation signal independent of the RL window.
 *
 * @return int New strike count.
 */
function auth_record_rl_strike(string $ip): int {
    $key = 'rl_strikes:' . $ip;
    $fp  = fopen(RATE_LIMIT_FILE, 'c+');
    if (!$fp) return 0;
    flock($fp, LOCK_EX);
    $data       = json_decode(stream_get_contents($fp), true) ?? [];
    $data[$key] = ($data[$key] ?? 0) + 1;
    $strikes    = $data[$key];
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($data));
    flock($fp, LOCK_UN);
    fclose($fp);
    return $strikes;
}

/** Clear the rate-limit strike counter for an IP on successful login. */
function auth_clear_rl_strikes(string $ip): void {
    $key = 'rl_strikes:' . $ip;
    $fp  = fopen(RATE_LIMIT_FILE, 'c+');
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

// ── IP blacklist ──────────────────────────────────────────────────────────────

/**
 * Check whether an IP is currently blacklisted (manual or auto).
 * Expired entries are ignored without deletion.
 */
function auth_is_blacklisted(mysqli $con, string $ip): bool {
    $table = AUTH_DB_PREFIX . 'auth_blacklist';
    $stmt  = $con->prepare(
        "SELECT 1 FROM {$table}
         WHERE ip = ? AND (expires_at IS NULL OR expires_at > NOW())
         LIMIT 1"
    );
    if ($stmt === false) return false;
    $stmt->bind_param('s', $ip);
    $stmt->execute();
    $row   = $stmt->get_result()->fetch_row();
    $stmt->close();
    return $row !== null;
}

/**
 * Manually blacklist an IP address.
 * Overwrites any existing entry for the same IP.
 *
 * @param mysqli   $con       DB connection.
 * @param string   $ip        IPv4 or IPv6 address.
 * @param string   $reason    Human-readable reason, stored in auth_blacklist.
 * @param int|null $expiresIn Seconds until the block expires, or null for permanent.
 */
function auth_blacklist_ip(mysqli $con, string $ip, string $reason = '', ?int $expiresIn = null): void {
    $table   = AUTH_DB_PREFIX . 'auth_blacklist';
    $expires = $expiresIn !== null ? date('Y-m-d H:i:s', time() + $expiresIn) : null;
    $stmt    = $con->prepare(
        "INSERT INTO {$table} (ip, reason, auto, expires_at)
         VALUES (?, ?, 0, ?)
         ON DUPLICATE KEY UPDATE
             reason     = VALUES(reason),
             auto       = 0,
             blocked_at = NOW(),
             expires_at = VALUES(expires_at)"
    );
    if ($stmt === false) return;
    $stmt->bind_param('sss', $ip, $reason, $expires);
    $stmt->execute();
    $stmt->close();
    appendLog($con, 'blacklist', "Manually blacklisted IP: {$ip} — {$reason}", 'web');
}

/**
 * Remove an IP from the blacklist.
 */
function auth_unblacklist_ip(mysqli $con, string $ip): void {
    $table = AUTH_DB_PREFIX . 'auth_blacklist';
    $stmt  = $con->prepare("DELETE FROM {$table} WHERE ip = ?");
    if ($stmt === false) return;
    $stmt->bind_param('s', $ip);
    $stmt->execute();
    $stmt->close();
    appendLog($con, 'blacklist', "Unblacklisted IP: {$ip}", 'web');
}

/**
 * Auto-blacklist an IP after it has triggered the rate limit BLACKLIST_AUTO_STRIKES times.
 * Uses INSERT IGNORE — does not override an existing manual entry.
 */
function auth_auto_blacklist(mysqli $con, string $ip): void {
    $table  = AUTH_DB_PREFIX . 'auth_blacklist';
    $reason = 'Auto-blacklisted: rate limit triggered ' . BLACKLIST_AUTO_STRIKES . ' times';
    $stmt   = $con->prepare(
        "INSERT IGNORE INTO {$table} (ip, reason, auto) VALUES (?, ?, 1)"
    );
    if ($stmt === false) return;
    $stmt->bind_param('ss', $ip, $reason);
    $stmt->execute();
    $stmt->close();
    appendLog($con, 'blacklist', "Auto-blacklisted IP: {$ip}", 'web');
}

// ── Login / logout ────────────────────────────────────────────────────────────

/**
 * Authenticate a user against auth_accounts.
 *
 * Steps:
 *  1. Blacklist check — immediate rejection for blocked IPs.
 *  2. Rate-limit check — blocks after RATE_LIMIT_MAX failures in RATE_LIMIT_WINDOW seconds.
 *     Each rate-limit hit records a strike; BLACKLIST_AUTO_STRIKES hits triggers auto-blacklist.
 *  3. User lookup — generic error to prevent username enumeration.
 *  4. Activation check (activation_code must equal 'activated').
 *  5. Disabled check.
 *  6. Password verify (bcrypt via password_verify).
 *  7. Transparent bcrypt-13 rehash on successful login.
 *  8. Session fixation prevention (session_regenerate_id).
 *  9. Theme cookie sync.
 *
 * @return array ['ok' => true, 'username' => string]
 *            or ['ok' => false, 'error' => string]
 */
function auth_login(mysqli $con, string $username, string $password): array {
    $ip    = getUserIpAddr();
    $table = AUTH_DB_PREFIX . 'auth_accounts';

    if (auth_is_blacklisted($con, $ip)) {
        return ['ok' => false, 'error' => 'Ihre IP-Adresse wurde gesperrt.'];
    }

    if (auth_is_rate_limited($ip)) {
        $strikes = auth_record_rl_strike($ip);
        if ($strikes >= BLACKLIST_AUTO_STRIKES) {
            auth_auto_blacklist($con, $ip);
        }
        return ['ok' => false, 'error' => 'Zu viele Fehlversuche. Bitte warten Sie 15 Minuten.'];
    }

    $stmt = $con->prepare(
        "SELECT id, username, password, email, img, img_type,
                activation_code, disabled, invalidLogins, debug, rights, theme, totp_secret
         FROM {$table} WHERE username = ?"
    );
    if ($stmt === false) {
        return ['ok' => false, 'error' => 'Datenbankfehler.'];
    }
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $result = $stmt->get_result();
    $row    = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    if ($row === null) {
        auth_record_failure($ip);
        return ['ok' => false, 'error' => 'Benutzername und Kennwort stimmen nicht überein.'];
    }

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
        return ['ok' => false, 'error' => 'Benutzername und Kennwort stimmen nicht überein.'];
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
    auth_clear_rl_strikes($ip);

    // TOTP branch — if user has 2FA enabled, defer session setup.
    $totpSecret = $row['totp_secret'] ?? null;
    if ($totpSecret !== null) {
        $_SESSION['auth_totp_pending'] = [
            'user_data' => $row,
            'until'     => time() + 300,
            'attempts'  => 0,
        ];
        return ['ok' => true, 'totp_required' => true];
    }

    _auth_setup_session($con, $row);
    return ['ok' => true, 'username' => $row['username']];
}

/**
 * Extract session setup into a private helper so both auth_login() (non-2FA path)
 * and auth_totp_complete() (2FA path) can call it.
 *
 * @internal
 */
function _auth_setup_session(mysqli $con, array $row): void
{
    $table = AUTH_DB_PREFIX . 'auth_accounts';

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
        'samesite' => 'Lax',
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
        'samesite' => 'Lax',
    ]);

    $upd = $con->prepare("UPDATE {$table} SET lastLogin = NOW(), invalidLogins = 0 WHERE id = ?");
    if ($upd !== false) {
        $upd->bind_param('i', $row['id']);
        $upd->execute();
        $upd->close();
    }

    appendLog($con, 'auth', $row['username'] . ' logged in.', 'web');
}

/**
 * Complete a TOTP-gated login.
 *
 * Reads $_SESSION['auth_totp_pending'], validates TTL and attempt count (max 5),
 * verifies the code, then calls _auth_setup_session() to finish login.
 *
 * @return array ['ok' => true] on success, ['ok' => false, 'error' => string] on failure.
 */
function auth_totp_complete(mysqli $con, string $code): array
{
    $pending = $_SESSION['auth_totp_pending'] ?? null;

    if ($pending === null) {
        return ['ok' => false, 'error' => 'Keine ausstehende Anmeldung.'];
    }

    if (time() > $pending['until']) {
        unset($_SESSION['auth_totp_pending']);
        return ['ok' => false, 'error' => 'Sitzung abgelaufen.'];
    }

    if ($pending['attempts'] >= 5) {
        unset($_SESSION['auth_totp_pending']);
        return ['ok' => false, 'error' => 'Zu viele Fehlversuche.'];
    }

    $row    = $pending['user_data'];
    $secret = $row['totp_secret'];

    if (!auth_totp_verify($secret, $code)) {
        $pending['attempts']++;
        if ($pending['attempts'] >= 5) {
            unset($_SESSION['auth_totp_pending']);
            return ['ok' => false, 'error' => 'Zu viele Fehlversuche.'];
        }
        $_SESSION['auth_totp_pending'] = $pending;
        return ['ok' => false, 'error' => 'Ungültiger Code.'];
    }

    unset($_SESSION['auth_totp_pending']);
    _auth_setup_session($con, $row);
    return ['ok' => true];
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
        'samesite' => 'Lax',
    ]);
}
