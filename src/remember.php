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
                activation_code, disabled, invalidLogins, debug, rights, theme, totp_secret
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
