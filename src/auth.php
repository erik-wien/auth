<?php
/**
 * src/auth.php — Authentication, rate limiting, and IP blacklisting.
 *
 * Requires:
 *  - RATE_LIMIT_FILE constant (path to the JSON rate-limit file, writable by web server)
 *  - AUTH_DB_PREFIX constant (e.g. 'auth.' or '') defined by the consumer project
 *  - getUserIpAddr(), appendLog() from src/log.php
 */

define('RATE_LIMIT_MAX',        5);
define('RATE_LIMIT_WINDOW',     900);
define('BLACKLIST_AUTO_STRIKES', 2);   // RL events before an IP is auto-blacklisted

// ── Login hardening constants ─────────────────────────────────────────────────

define('USER_LOCKOUT_THRESHOLD',          10); // invalidLogins >= this → account locked
define('LOGIN_SCORE_FAIL_EXISTING_USER',   1); // scored from auth_log 60-min window
define('LOGIN_SCORE_FAIL_UNKNOWN_USER',    3);
define('LOGIN_SCORE_RL_STRIKE',            5); // rate-limit event
define('LOGIN_SCORE_BLACKLIST_THRESHOLD', 15);

/**
 * Compute the progressive response delay in seconds for a given fail count.
 * Pure function — no DB access, safe to call from tests.
 *
 * delay = min(30, 2^(failCount−1)) for failCount ≥ 1, else 0.
 */
function auth_compute_progressive_delay(int $failCount): int
{
    if ($failCount < 1) return 0;
    // Cap the exponent before computing to avoid int overflow on large failCounts.
    $exp = min($failCount - 1, 5); // 2^5 = 32 > 30; beyond that min() clamps to 30
    return min(30, (int) (2 ** $exp));
}

/**
 * Count failed login attempts from $ip within the RATE_LIMIT_WINDOW (seconds).
 * Queries auth_log for entries matching 'Login failed%' from this IP.
 */
function auth_count_recent_ip_fails(mysqli $con, string $ip): int
{
    $table = AUTH_DB_PREFIX . 'auth_log';
    $stmt  = $con->prepare(
        "SELECT COUNT(*) FROM {$table}
         WHERE ipAdress = INET_ATON(?)
           AND context = 'login'
           AND activity LIKE 'Login failed%'
           AND logTime >= DATE_SUB(NOW(), INTERVAL " . RATE_LIMIT_WINDOW . " SECOND)"
    );
    if ($stmt === false) return 0;
    $stmt->bind_param('s', $ip);
    $stmt->execute();
    $result = $stmt->get_result();
    $count  = 0;
    if ($result !== false) {
        $row   = $result->fetch_row();
        $count = (int) ($row[0] ?? 0);
    }
    $stmt->close();
    return $count;
}

/**
 * Compute a shenanigans score for an IP based on its auth_log activity in the
 * last 60 minutes. Also returns unique usernames seen in failed attempts.
 *
 * Score mapping:
 *  - 'Login failed for unknown user: …' → +LOGIN_SCORE_FAIL_UNKNOWN_USER (3)
 *  - 'Login failed for …'               → +LOGIN_SCORE_FAIL_EXISTING_USER (1)
 *  - 'Rate limit triggered…'            → +LOGIN_SCORE_RL_STRIKE (5)
 *
 * @return array{score: int, usernames: list<string>}
 */
function auth_compute_ip_score(mysqli $con, string $ip): array
{
    $table = AUTH_DB_PREFIX . 'auth_log';
    $stmt  = $con->prepare(
        "SELECT activity FROM {$table}
         WHERE ipAdress = INET_ATON(?)
           AND context = 'login'
           AND logTime >= DATE_SUB(NOW(), INTERVAL 60 MINUTE)"
    );
    if ($stmt === false) return ['score' => 0, 'usernames' => []];
    $stmt->bind_param('s', $ip);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result === false) { $stmt->close(); return ['score' => 0, 'usernames' => []]; }

    $score     = 0;
    $usernames = [];
    $prefix_unknown  = 'Login failed for unknown user: ';
    $prefix_existing = 'Login failed for ';
    while ($row = $result->fetch_row()) {
        $activity = (string) ($row[0] ?? '');
        if (str_starts_with($activity, $prefix_unknown)) {
            $score      += LOGIN_SCORE_FAIL_UNKNOWN_USER;
            $username    = rtrim(substr($activity, strlen($prefix_unknown)), '.');
            if ($username !== '') $usernames[] = $username;
        } elseif (str_starts_with($activity, $prefix_existing)) {
            $score      += LOGIN_SCORE_FAIL_EXISTING_USER;
            $username    = rtrim(substr($activity, strlen($prefix_existing)), '.');
            if ($username !== '') $usernames[] = $username;
        } elseif (str_starts_with($activity, 'Rate limit triggered')) {
            $score += LOGIN_SCORE_RL_STRIKE;
        }
    }
    $stmt->close();
    return ['score' => $score, 'usernames' => array_values(array_unique($usernames))];
}

/**
 * Compute the progressive delay for the current IP and sleep if > 0.
 */
function auth_apply_progressive_delay(mysqli $con, string $ip): void
{
    $count = auth_count_recent_ip_fails($con, $ip);
    $delay = auth_compute_progressive_delay($count);
    if ($delay > 0) sleep($delay);
}

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
    appendLog($con, 'blacklist', "Manually blacklisted IP: {$ip} — {$reason}");
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
    appendLog($con, 'blacklist', "Unblacklisted IP: {$ip}");
}

/**
 * Auto-blacklist an IP using INSERT IGNORE (does not override a manual entry).
 *
 * When $notifyAdmins is true, sends a blacklist_notice email to all active admins.
 * Only pass $notifyAdmins = true from score-triggered paths.
 *
 * @param array{score: int, usernames: list<string>} $scoreContext
 */
function auth_auto_blacklist(
    mysqli $con,
    string $ip,
    bool   $notifyAdmins = false,
    array  $scoreContext = []
): void {
    $table  = AUTH_DB_PREFIX . 'auth_blacklist';
    $score  = (int) ($scoreContext['score'] ?? 0);
    $reason = $score > 0
        ? "Auto-blacklisted: shenanigans score {$score}"
        : 'Auto-blacklisted: rate limit triggered ' . BLACKLIST_AUTO_STRIKES . ' times';

    $stmt = $con->prepare(
        "INSERT IGNORE INTO {$table} (ip, reason, auto) VALUES (?, ?, 1)"
    );
    if ($stmt === false) return;
    $stmt->bind_param('ss', $ip, $reason);
    $stmt->execute();
    $stmt->close();
    appendLog($con, 'blacklist', "Auto-blacklisted IP: {$ip}");

    if ($notifyAdmins && function_exists('mail_send_admin_notice')) {
        $usernames = implode(', ', $scoreContext['usernames'] ?? []) ?: '—';
        mail_send_admin_notice($con, 'blacklist_notice', [
            'ip'        => $ip,
            'reason'    => $reason,
            'score'     => (string) $score,
            'usernames' => $usernames,
            'admin_url' => defined('APP_BASE_URL')
                ? rtrim(APP_BASE_URL, '/') . '/admin.php#log'
                : '#',
        ]);
    }
}

// ── Login / logout ────────────────────────────────────────────────────────────

/**
 * Authenticate a user against auth_accounts.
 *
 * Checks the IP blacklist and rate limiter first, then looks up the user row,
 * validates activation/disabled/lockout state, verifies the bcrypt password,
 * transparently rehashes to cost 13 if needed, and sets up the session.
 * For TOTP-enrolled accounts, returns totp_required instead of completing login.
 *
 * @return array ['ok' => true, 'username' => string]
 *            or ['ok' => false, 'error' => string]
 *            or ['ok' => true, 'totp_required' => true]
 */
function auth_login(mysqli $con, string $username, string $password, bool $remember = false): array {
    $ip    = getUserIpAddr();
    $table = AUTH_DB_PREFIX . 'auth_accounts';

    // Blacklist — immediate rejection, no delay.
    if (auth_is_blacklisted($con, $ip)) {
        return ['ok' => false, 'error' => 'Ihre IP-Adresse wurde gesperrt.'];
    }

    // Rate-limit — log the event (scorable as +5) then check score.
    if (auth_is_rate_limited($ip)) {
        appendLog($con, 'login', 'Rate limit triggered.');
        $scored = auth_compute_ip_score($con, $ip);
        if ($scored['score'] >= LOGIN_SCORE_BLACKLIST_THRESHOLD) {
            auth_auto_blacklist($con, $ip, true, $scored);
        }
        auth_apply_progressive_delay($con, $ip);
        return ['ok' => false, 'error' => 'Zu viele Fehlversuche. Bitte warten Sie 15 Minuten.'];
    }

    // User lookup.
    $stmt = $con->prepare(
        "SELECT id, username, password, email,
                (img_blob IS NOT NULL) AS has_avatar,
                activation_code, disabled, invalidLogins, rights, theme, totp_secret
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
        appendLog($con, 'login', "Login failed for unknown user: {$username}.");
        auth_record_failure($ip);
        $scored = auth_compute_ip_score($con, $ip);
        if ($scored['score'] >= LOGIN_SCORE_BLACKLIST_THRESHOLD) {
            auth_auto_blacklist($con, $ip, true, $scored);
        }
        auth_apply_progressive_delay($con, $ip);
        return ['ok' => false, 'error' => 'Benutzername und Kennwort stimmen nicht überein.'];
    }

    if ($row['activation_code'] !== 'activated') {
        auth_apply_progressive_delay($con, $ip);
        return ['ok' => false, 'error' => 'Benutzer ist noch nicht aktiviert.'];
    }
    if ((int) $row['disabled'] === 1) {
        auth_apply_progressive_delay($con, $ip);
        return ['ok' => false, 'error' => 'Benutzer ist gesperrt.'];
    }

    // Per-user lockout gate (before bcrypt — no expensive hash needed).
    if ((int) $row['invalidLogins'] >= USER_LOCKOUT_THRESHOLD) {
        appendLog($con, 'login', "Login rejected: account locked ({$username}).");
        auth_apply_progressive_delay($con, $ip);
        return ['ok' => false, 'error' => 'Konto gesperrt. Bitte wenden Sie sich an einen Administrator.'];
    }

    // Password verify.
    if (!password_verify($password, $row['password'])) {
        auth_record_failure($ip);
        $upd = $con->prepare("UPDATE {$table} SET invalidLogins = invalidLogins + 1 WHERE username = ?");
        if ($upd !== false) {
            $upd->bind_param('s', $username);
            $upd->execute();
            $upd->close();
        }

        // Notify admins once when crossing the lockout threshold.
        $newInvalidLogins = (int) $row['invalidLogins'] + 1;
        if ($newInvalidLogins === USER_LOCKOUT_THRESHOLD) {
            appendLog($con, 'login', "Account locked: {$username} reached lockout threshold.");
            if (function_exists('mail_send_admin_notice')) {
                mail_send_admin_notice($con, 'user_lockout_notice', [
                    'username'  => $username,
                    'email'     => $row['email'],
                    'threshold' => (string) USER_LOCKOUT_THRESHOLD,
                    'last_ip'   => $ip,
                    'admin_url' => defined('APP_BASE_URL')
                        ? rtrim(APP_BASE_URL, '/') . '/admin.php#users'
                        : '#',
                ]);
            }
        }

        appendLog($con, 'login', "Login failed for {$username}.");
        $scored = auth_compute_ip_score($con, $ip);
        if ($scored['score'] >= LOGIN_SCORE_BLACKLIST_THRESHOLD) {
            auth_auto_blacklist($con, $ip, true, $scored);
        }
        auth_apply_progressive_delay($con, $ip);
        return ['ok' => false, 'error' => 'Benutzername und Kennwort stimmen nicht überein.'];
    }

    // Transparent bcrypt cost upgrade to 13.
    if (password_needs_rehash($row['password'], PASSWORD_BCRYPT, ['cost' => 13])) {
        $newHash = auth_hash_password($password);
        $upd     = $con->prepare("UPDATE {$table} SET password = ? WHERE id = ?");
        if ($upd !== false) {
            $upd->bind_param('si', $newHash, $row['id']);
            $upd->execute();
            $upd->close();
        }
    }

    auth_clear_failures($ip);

    // TOTP branch.
    $totpSecret = $row['totp_secret'] ?? null;
    if ($totpSecret !== null) {
        $_SESSION['auth_totp_pending'] = [
            'user_data' => $row,
            'until'     => time() + 300,
            'attempts'  => 0,
            'remember'  => $remember,
        ];
        return ['ok' => true, 'totp_required' => true];
    }

    _auth_setup_session($con, $row, $remember);
    return ['ok' => true, 'username' => $row['username']];
}

/**
 * Extract session setup into a private helper so both auth_login() (non-2FA path)
 * and auth_totp_complete() (2FA path) can call it.
 *
 * @internal
 */
function _auth_setup_session(mysqli $con, array $row, bool $remember = false): void
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
    $_SESSION['has_avatar'] = (bool) ($row['has_avatar'] ?? false);
    $_SESSION['disabled']   = $row['disabled'];
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

    if ($remember) {
        auth_remember_issue($con, (int) $row['id']);
    }

    appendLog($con, 'auth', $row['username'] . ' logged in.');
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

    $remember = !empty($pending['remember']);
    unset($_SESSION['auth_totp_pending']);
    _auth_setup_session($con, $row, $remember);
    return ['ok' => true];
}

/**
 * Hash a password using the library's bcrypt cost standard.
 *
 * Use for account-creation INSERTs (registration, admin-created accounts) where
 * there is no existing row yet — auth_change_password() covers the UPDATE path.
 * The cost constant lives here so consumer code never hardcodes it; see
 * ~/.claude/rules/auth-rules.md §1.
 */
function auth_hash_password(string $password): string
{
    return password_hash($password, PASSWORD_BCRYPT, ['cost' => 13]);
}

/**
 * Change a user's password.
 *
 * Hashes with bcrypt cost 13 (the library standard — never reimplement in
 * consumer apps; see ~/.claude/rules/auth-rules.md §1), updates auth_accounts,
 * resets invalidLogins to 0, and logs the change to auth_log. Returns true on
 * a successful UPDATE, false if the row didn't exist.
 *
 * Use from password-change forms and reset-completion flows. The caller is
 * responsible for verifying the old password (or the reset token) before
 * calling — this function unconditionally rewrites the hash.
 */
function auth_change_password(mysqli $con, int $userId, string $newPassword): bool
{
    $hash  = auth_hash_password($newPassword);
    $table = AUTH_DB_PREFIX . 'auth_accounts';
    $stmt  = $con->prepare("UPDATE {$table} SET password = ?, invalidLogins = 0 WHERE id = ?");
    $stmt->bind_param('si', $hash, $userId);
    // Short-circuit on execute()==false so the read of affected_rows is skipped
    // when the prepared statement failed — also keeps the function unit-testable
    // under PHPUnit's mysqli_stmt stubs (which cannot expose internal properties
    // on PHP 8.5+). See TotpTest for the same pattern.
    $changed = $stmt->execute() && $stmt->affected_rows > 0;
    $stmt->close();

    if ($changed) {
        // Invalidate every remember-me token: a password change must log out
        // every other device, including the one that owned a "keep me logged
        // in" cookie.
        auth_remember_delete_all($con, $userId);
        appendLog($con, 'npw', "Password changed for user #$userId.");
    }
    return $changed;
}

function auth_logout(mysqli $con): void {
    if (!empty($_SESSION['username'])) {
        appendLog($con, 'log', $_SESSION['username'] . ' logged out.');
    }
    auth_remember_delete_current($con);
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
