<?php
/**
 * src/remember.php — "Keep me logged in" persistent-session tokens.
 *
 * Split-token pattern: the cookie is "<selector>.<validator>". The selector is
 * stored plain for O(1) lookup; the validator is stored as a SHA-256 hash and
 * compared via hash_equals() to avoid timing attacks. Each successful use
 * rotates both halves, so a leaked cookie is valid for exactly one request.
 *
 * Wired in by:
 *   - auth_login()         → auth_remember_issue() when $remember is true
 *   - auth_bootstrap()     → auth_remember_try_restore() when no session exists
 *   - auth_logout()        → auth_remember_delete_current()
 *   - auth_change_password → auth_remember_delete_all()
 *   - admin_delete_user()  → DB CASCADE (FK on auth_accounts.id)
 */

const AUTH_REMEMBER_COOKIE   = 'auth_remember';
const AUTH_REMEMBER_LIFETIME = 60 * 60 * 24 * 8; // 8 days

/**
 * Return the cookie Domain attribute for cross-subdomain SSO, or '' for
 * per-host scope. If the current host is under *.eriks.cloud or *.jardyx.com,
 * the cookie is shared across every app on that registrable domain so that a
 * single login on any app (e.g. suche) silently restores the session on the
 * next app visited (chat, wlmonitor, …). Local dev hosts (.test, localhost)
 * fall back to per-host scope — '.test' is not a usable parent for cookies.
 */
function _auth_remember_cookie_domain(): string
{
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $host = preg_replace('/:\d+$/', '', $host);
    foreach (['.eriks.cloud', '.jardyx.com'] as $parent) {
        if ($host !== '' && str_ends_with($host, $parent)) {
            return $parent;
        }
    }
    return '';
}

/**
 * Set the remember-me cookie with the chosen attributes.
 * Centralised so issue/rotate/delete all agree on flags.
 */
function _auth_remember_setcookie(string $value, int $expires): void
{
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
               || (int) ($_SERVER['SERVER_PORT'] ?? 80) === 443;
    $opts = [
        'expires'  => $expires,
        'path'     => '/',
        'httponly' => true,
        'secure'   => $isHttps,
        'samesite' => 'Lax',
    ];
    $domain = _auth_remember_cookie_domain();
    if ($domain !== '') {
        $opts['domain'] = $domain;
    }
    setcookie(AUTH_REMEMBER_COOKIE, $value, $opts);
}

/**
 * Issue a fresh remember-me cookie for a user.
 * Called from _auth_setup_session() when the login form had "remember" ticked.
 */
function auth_remember_issue(mysqli $con, int $userId): void
{
    $selector  = bin2hex(random_bytes(8));  // 16 chars
    $validator = bin2hex(random_bytes(32)); // 64 chars
    $hash      = hash('sha256', $validator);
    $expires   = time() + AUTH_REMEMBER_LIFETIME;
    $expiresDt = date('Y-m-d H:i:s', $expires);
    $ua        = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    $ip        = function_exists('getUserIpAddr') ? getUserIpAddr() : ($_SERVER['REMOTE_ADDR'] ?? '');

    $table = AUTH_DB_PREFIX . 'auth_remember_tokens';
    $stmt  = $con->prepare(
        "INSERT INTO {$table} (user_id, selector, token_hash, expires_at, user_agent, ip)
         VALUES (?, ?, ?, ?, ?, ?)"
    );
    if ($stmt === false) return;
    $stmt->bind_param('isssss', $userId, $selector, $hash, $expiresDt, $ua, $ip);
    $stmt->execute();
    $stmt->close();

    _auth_remember_setcookie($selector . '.' . $validator, $expires);
}

/**
 * Parse the remember-me cookie and return the associated user row if the
 * validator matches and the token has not expired. On success, the token is
 * rotated (old row deleted, new selector+validator issued).
 *
 * Returns the full auth_accounts row (matching auth_login()'s SELECT) on
 * success, or null on any failure.
 */
function auth_remember_validate(mysqli $con): ?array
{
    $cookie = $_COOKIE[AUTH_REMEMBER_COOKIE] ?? '';
    if ($cookie === '' || strpos($cookie, '.') === false) {
        return null;
    }
    [$selector, $validator] = explode('.', $cookie, 2);
    if (!preg_match('/^[a-f0-9]{16}$/', $selector) || !preg_match('/^[a-f0-9]{64}$/', $validator)) {
        return null;
    }

    $table = AUTH_DB_PREFIX . 'auth_remember_tokens';
    $stmt  = $con->prepare(
        "SELECT id, user_id, token_hash, expires_at FROM {$table} WHERE selector = ?"
    );
    if ($stmt === false) return null;
    $stmt->bind_param('s', $selector);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row === null) {
        return null;
    }

    // Expired? Delete and fail.
    if (strtotime($row['expires_at']) < time()) {
        _auth_remember_delete_row($con, (int) $row['id']);
        _auth_remember_setcookie('', time() - 3600);
        return null;
    }

    // Constant-time validator compare.
    $expected = hash('sha256', $validator);
    if (!hash_equals($row['token_hash'], $expected)) {
        // Potential token theft — revoke the whole chain for this user as a
        // defensive measure. (A genuine stale cookie would have matched.)
        auth_remember_delete_all($con, (int) $row['user_id']);
        _auth_remember_setcookie('', time() - 3600);
        return null;
    }

    // Load the user row. Keep SELECT columns in sync with auth_login().
    $acctTable = AUTH_DB_PREFIX . 'auth_accounts';
    $userStmt  = $con->prepare(
        "SELECT id, username, password, email,
                (img_blob IS NOT NULL) AS has_avatar,
                activation_code, disabled, invalidLogins, rights, theme, totp_secret
         FROM {$acctTable} WHERE id = ?"
    );
    if ($userStmt === false) return null;
    $userStmt->bind_param('i', $row['user_id']);
    $userStmt->execute();
    $user = $userStmt->get_result()->fetch_assoc();
    $userStmt->close();

    if ($user === null || $user['activation_code'] !== 'activated' || (int) $user['disabled'] === 1) {
        _auth_remember_delete_row($con, (int) $row['id']);
        _auth_remember_setcookie('', time() - 3600);
        return null;
    }

    // Rotate: issue a new selector+validator, delete the old row.
    _auth_remember_delete_row($con, (int) $row['id']);
    auth_remember_issue($con, (int) $row['user_id']);

    // Opportunistic GC of expired tokens (cheap, indexed).
    $gc = $con->prepare("DELETE FROM {$table} WHERE expires_at < NOW()");
    if ($gc !== false) {
        $gc->execute();
        $gc->close();
    }

    return $user;
}

/**
 * Called from auth_bootstrap() after session_start() when there is no active
 * session (empty $_SESSION['loggedin']). If a valid remember cookie is
 * present, rebuild the session and return true. TOTP-protected accounts do
 * NOT auto-restore — users with 2FA must re-enter their TOTP code.
 */
function auth_remember_try_restore(mysqli $con): bool
{
    if (!empty($_SESSION['loggedin'])) return false;
    if (empty($_COOKIE[AUTH_REMEMBER_COOKIE])) return false;

    $user = auth_remember_validate($con);
    if ($user === null) return false;

    if (!empty($user['totp_secret'])) {
        // Don't silently skip 2FA on remember-me restore. The caller should
        // treat the user as still logged out until they complete TOTP.
        // A fresh remember token has been issued by validate(); leave it
        // intact so the user can retry after TOTP.
        return false;
    }

    _auth_setup_session($con, $user);
    return true;
}

/**
 * Delete the single token matching the current cookie (used on logout).
 */
function auth_remember_delete_current(mysqli $con): void
{
    $cookie = $_COOKIE[AUTH_REMEMBER_COOKIE] ?? '';
    if ($cookie !== '' && strpos($cookie, '.') !== false) {
        [$selector] = explode('.', $cookie, 2);
        if (preg_match('/^[a-f0-9]{16}$/', $selector)) {
            $table = AUTH_DB_PREFIX . 'auth_remember_tokens';
            $stmt  = $con->prepare("DELETE FROM {$table} WHERE selector = ?");
            if ($stmt !== false) {
                $stmt->bind_param('s', $selector);
                $stmt->execute();
                $stmt->close();
            }
        }
    }
    _auth_remember_setcookie('', time() - 3600);
}

/**
 * Delete every remember token for a user. Called on password change and on
 * detected token reuse. Does NOT clear the current request's cookie — the
 * caller's cookie may still decode but any lookup will return nothing.
 */
function auth_remember_delete_all(mysqli $con, int $userId): void
{
    $table = AUTH_DB_PREFIX . 'auth_remember_tokens';
    $stmt  = $con->prepare("DELETE FROM {$table} WHERE user_id = ?");
    if ($stmt === false) return;
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->close();
}

/**
 * User-initiated "log out of all devices": revoke every remember-me token for
 * the currently logged-in user and clear the current request's cookie so the
 * browser stops sending the (now invalid) selector. Does NOT destroy the local
 * PHP session — the caller keeps their active login on the current app.
 *
 * Returns false if there is no active session (defensive: never deletes
 * anonymously). Writes an audit entry with context 'sec'.
 */
function auth_remember_revoke_all(mysqli $con): bool
{
    $userId = (int) ($_SESSION['id'] ?? 0);
    if ($userId <= 0) return false;

    auth_remember_delete_all($con, $userId);
    _auth_remember_setcookie('', time() - 3600);
    appendLog($con, 'sec', 'Revoked all remember-me tokens.');
    return true;
}

/**
 * Return all active remember-me token rows for a user, ordered most-recent first.
 * Each row carries: selector, created_at, expires_at, user_agent (raw, truncated
 * at 255 by the schema), ip (raw), browser_os (parsed UA), is_current (whether
 * this row matches the request's cookie selector).
 *
 * Only non-expired rows are returned; the caller is safe to render the list
 * without re-checking expires_at for visibility. Does not touch the DB beyond
 * the SELECT.
 */
function auth_remember_list_for_user(mysqli $con, int $userId): array
{
    if ($userId <= 0) return [];

    $table = AUTH_DB_PREFIX . 'auth_remember_tokens';
    $stmt  = $con->prepare(
        "SELECT selector, created_at, expires_at, user_agent, ip
         FROM {$table}
         WHERE user_id = ? AND expires_at > NOW()
         ORDER BY created_at DESC"
    );
    if ($stmt === false) return [];
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $res = $stmt->get_result();

    $currentSelector = '';
    $cookie = $_COOKIE[AUTH_REMEMBER_COOKIE] ?? '';
    if ($cookie !== '' && strpos($cookie, '.') !== false) {
        [$candidate] = explode('.', $cookie, 2);
        if (preg_match('/^[a-f0-9]{16}$/', $candidate)) {
            $currentSelector = $candidate;
        }
    }

    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $rows[] = [
            'selector'   => (string) $row['selector'],
            'created_at' => (string) $row['created_at'],
            'expires_at' => (string) $row['expires_at'],
            'user_agent' => (string) ($row['user_agent'] ?? ''),
            'ip'         => (string) ($row['ip'] ?? ''),
            'browser_os' => auth_remember_parse_ua((string) ($row['user_agent'] ?? '')),
            'is_current' => $currentSelector !== '' && $currentSelector === (string) $row['selector'],
        ];
    }
    $stmt->close();
    return $rows;
}

/**
 * Revoke a single remember-me token by selector. Requires $userId to match the
 * token's owner — the selector is client-supplied, so the WHERE clause binds
 * both user_id and selector to prevent IDOR. Returns true iff a row was
 * deleted. Logs an audit entry with context 'sec' on success.
 *
 * If the deleted token matches the caller's current cookie, the request cookie
 * is cleared so the browser stops sending the (now invalid) selector.
 */
function auth_remember_revoke_one(mysqli $con, int $userId, string $selector): bool
{
    if ($userId <= 0) return false;
    if (!preg_match('/^[a-f0-9]{16}$/', $selector)) return false;

    $table = AUTH_DB_PREFIX . 'auth_remember_tokens';
    $stmt  = $con->prepare("DELETE FROM {$table} WHERE user_id = ? AND selector = ?");
    if ($stmt === false) return false;
    $stmt->bind_param('is', $userId, $selector);
    // Short-circuit on execute()==false; matches the pattern used in
    // auth_change_password() so PHPUnit stubs don't need to mock affected_rows.
    $deleted = $stmt->execute() && $stmt->affected_rows > 0;
    $stmt->close();
    if (!$deleted) return false;

    $cookie = $_COOKIE[AUTH_REMEMBER_COOKIE] ?? '';
    if ($cookie !== '' && strpos($cookie, '.') !== false) {
        [$cookieSelector] = explode('.', $cookie, 2);
        if ($cookieSelector === $selector) {
            _auth_remember_setcookie('', time() - 3600);
        }
    }

    appendLog($con, 'sec', 'Revoked remember-me token ' . $selector . '.');
    return true;
}

/**
 * Best-effort User-Agent parser: returns a short "Browser on OS" string for
 * display, falling back to a truncated raw UA when nothing matches. Plain
 * string matching — deliberately no external library — covers the common
 * desktop/mobile combinations; anything exotic lands in the fallback branch.
 */
function auth_remember_parse_ua(string $ua): string
{
    if ($ua === '') return 'Unbekannt';

    $os = 'Unbekannt';
    if (stripos($ua, 'iPhone') !== false || stripos($ua, 'iPad') !== false || stripos($ua, 'iOS') !== false) {
        $os = 'iOS';
    } elseif (stripos($ua, 'Android') !== false) {
        $os = 'Android';
    } elseif (stripos($ua, 'Mac OS X') !== false || stripos($ua, 'Macintosh') !== false) {
        $os = 'macOS';
    } elseif (stripos($ua, 'Windows') !== false) {
        $os = 'Windows';
    } elseif (stripos($ua, 'Linux') !== false) {
        $os = 'Linux';
    }

    $browser = '';
    if (stripos($ua, 'Edg/') !== false || stripos($ua, 'Edge/') !== false) {
        $browser = 'Edge';
    } elseif (stripos($ua, 'Firefox/') !== false) {
        $browser = 'Firefox';
    } elseif (stripos($ua, 'Chrome/') !== false && stripos($ua, 'Chromium') === false) {
        $browser = 'Chrome';
    } elseif (stripos($ua, 'Chromium/') !== false) {
        $browser = 'Chromium';
    } elseif (stripos($ua, 'Safari/') !== false && stripos($ua, 'Chrome') === false) {
        $browser = 'Safari';
    }

    if ($browser !== '' && $os !== 'Unbekannt') {
        return $browser . ' auf ' . $os;
    }
    if ($browser !== '') {
        return $browser;
    }
    if ($os !== 'Unbekannt') {
        return $os;
    }
    return mb_strlen($ua) > 60 ? mb_substr($ua, 0, 60) . '…' : $ua;
}

/** @internal */
function _auth_remember_delete_row(mysqli $con, int $rowId): void
{
    $table = AUTH_DB_PREFIX . 'auth_remember_tokens';
    $stmt  = $con->prepare("DELETE FROM {$table} WHERE id = ?");
    if ($stmt === false) return;
    $stmt->bind_param('i', $rowId);
    $stmt->execute();
    $stmt->close();
}
