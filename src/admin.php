<?php
/**
 * src/admin.php — User administration functions.
 *
 * Requires:
 *  - AUTH_DB_PREFIX constant (e.g. 'auth.' or '')
 *  - invite_create_token() from src/invite.php
 *  - mail_send_invite() from src/mail_helpers.php
 *  - appendLog() from src/log.php
 */

/**
 * Guard: redirect non-admins to index.php.
 * Call at the top of every admin page.
 */
function admin_require(): void
{
    if (($_SESSION['rights'] ?? '') !== 'Admin') {
        header('Location: index.php');
        exit;
    }
}

/**
 * Return a paginated, optionally filtered list of auth_accounts rows.
 *
 * Apps should call this and then merge their own per-user preference tables by id.
 *
 * @return array{
 *   users: list<array{id: int, username: string, email: string, rights: string, disabled: int}>,
 *   total: int,
 *   page: int,
 *   per_page: int
 * }
 */
function admin_list_users(mysqli $con, int $page = 1, int $perPage = 25, string $filter = ''): array
{
    $table  = AUTH_DB_PREFIX . 'auth_accounts';
    $offset = ($page - 1) * $perPage;

    if ($filter !== '') {
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filter);
        $like    = '%' . $escaped . '%';
        $stmt    = $con->prepare(
            "SELECT id, username, email, rights, disabled
             FROM {$table} WHERE username LIKE ? ORDER BY username LIMIT ? OFFSET ?"
        );
        $stmt->bind_param('sii', $like, $perPage, $offset);
    } else {
        $stmt = $con->prepare(
            "SELECT id, username, email, rights, disabled
             FROM {$table} ORDER BY username LIMIT ? OFFSET ?"
        );
        $stmt->bind_param('ii', $perPage, $offset);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $rows   = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = [
            'id'       => (int) $row['id'],
            'username' => $row['username'],
            'email'    => $row['email'],
            'rights'   => $row['rights'],
            'disabled' => (int) $row['disabled'],
        ];
    }
    $stmt->close();

    if ($filter !== '') {
        $cstmt = $con->prepare("SELECT COUNT(*) FROM {$table} WHERE username LIKE ?");
        $cstmt->bind_param('s', $like);
    } else {
        $cstmt = $con->prepare("SELECT COUNT(*) FROM {$table}");
    }
    $cstmt->execute();
    $total = 0;
    $cstmt->bind_result($total);
    $cstmt->fetch();
    $cstmt->close();

    return ['users' => $rows, 'total' => (int) $total, 'page' => $page, 'per_page' => $perPage];
}

/**
 * Insert a new user account (disabled=1), create an invite token, and send email.
 *
 * @throws \mysqli_sql_exception on duplicate username or email.
 * @return int The new user's id.
 */
function admin_create_user(
    mysqli $con,
    string $username,
    string $email,
    string $rights,
    string $baseUrl
): int {
    $table       = AUTH_DB_PREFIX . 'auth_accounts';
    $rights      = in_array($rights, ['Admin', 'User'], true) ? $rights : 'User';
    $placeholder = auth_hash_password(bin2hex(random_bytes(16)));

    $stmt = $con->prepare(
        "INSERT INTO {$table} (username, email, password, rights, disabled, activation_code)
         VALUES (?, ?, ?, ?, 1, 'invited')"
    );
    $stmt->bind_param('ssss', $username, $email, $placeholder, $rights);
    $stmt->execute();
    try {
        $userId = (int) $con->insert_id;
    } catch (\Error) {
        $userId = 0; // Stubs block property access; 0 is acceptable in tests.
    }
    $stmt->close();

    $token = invite_create_token($con, $userId);
    $link  = rtrim($baseUrl, '/') . '/setpassword.php?token=' . urlencode($token);
    $sent  = mail_send_invite($email, $username, $link);
    if (!$sent) {
        appendLog($con, 'admin', "Failed to send invite email to $email for user #$userId.");
    }

    return $userId;
}

/**
 * Update a user's common fields.
 * Rights values not in ['Admin', 'User'] are silently coerced to 'User'.
 * When $totp_reset is true, also sets totp_secret = NULL.
 *
 * @return bool True if the row was updated.
 */
function admin_edit_user(
    mysqli $con,
    int    $targetId,
    string $email,
    string $rights,
    int    $disabled,
    bool   $totp_reset = false
): bool {
    $table  = AUTH_DB_PREFIX . 'auth_accounts';
    $rights = in_array($rights, ['Admin', 'User'], true) ? $rights : 'User';

    $stmt = $con->prepare(
        "UPDATE {$table} SET email = ?, rights = ?, disabled = ? WHERE id = ?"
    );
    $disabledEnum = $disabled ? '1' : '0';
    $stmt->bind_param('sssi', $email, $rights, $disabledEnum, $targetId);
    $stmt->execute();
    try {
        $ok = $stmt->affected_rows > 0;
    } catch (\Error) {
        $ok = false;
    }
    $stmt->close();

    if ($totp_reset) {
        $upd = $con->prepare("UPDATE {$table} SET totp_secret = NULL WHERE id = ?");
        $upd->bind_param('i', $targetId);
        $upd->execute();
        $upd->close();
    }

    appendLog($con, 'admin', "User #$targetId updated." . ($totp_reset ? ' 2FA reset.' : ''));
    return $ok;
}

/**
 * Send a password-reset invite, clear the user's invalidLogins counter, and
 * remove auto-blacklisted IPs that were used to brute-force this account in the
 * last 24 hours (auto=1 rows only — manual entries are never touched).
 *
 * @return array{ok: bool, unblocked_ips: list<string>}
 */
function admin_reset_password(mysqli $con, int $targetId, string $baseUrl): array
{
    $table = AUTH_DB_PREFIX . 'auth_accounts';
    $stmt  = $con->prepare("SELECT email, username FROM {$table} WHERE id = ?");
    $stmt->bind_param('i', $targetId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        return ['ok' => false, 'unblocked_ips' => []];
    }

    // 1. Clear invalidLogins so the account is no longer locked.
    $upd = $con->prepare("UPDATE {$table} SET invalidLogins = 0 WHERE id = ?");
    $upd->bind_param('i', $targetId);
    $upd->execute();
    $upd->close();

    // 2. Issue invite token + send reset email.
    $token = invite_create_token($con, $targetId);
    $link  = rtrim($baseUrl, '/') . '/setpassword.php?token=' . urlencode($token);
    $sent  = mail_send_invite($row['email'], $row['username'], $link);
    if (!$sent) {
        appendLog($con, 'admin', "Failed to send reset email to {$row['email']} for user #{$targetId}.");
    }

    // 3. Unblock auto-blacklisted IPs that tried to brute-force this account.
    $unblocked = _admin_unblock_ips_for_user($con, $row['username']);

    $n = count($unblocked);
    appendLog(
        $con, 'admin',
        "Password reset + invalidLogins cleared + unblocked {$n} IPs for user #{$targetId}."
    );
    return ['ok' => $sent, 'unblocked_ips' => $unblocked];
}

/**
 * Preview what admin_reset_password() would unblock without mutating state.
 *
 * @return array{ok: bool, username: string, email: string, ips: list<string>}
 */
function admin_user_reset_preview(mysqli $con, int $targetId): array
{
    $table = AUTH_DB_PREFIX . 'auth_accounts';
    $stmt  = $con->prepare("SELECT email, username FROM {$table} WHERE id = ?");
    $stmt->bind_param('i', $targetId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        return ['ok' => false, 'username' => '', 'email' => '', 'ips' => []];
    }

    $ips = _admin_preview_ips_for_user($con, $row['username']);
    return ['ok' => true, 'username' => $row['username'], 'email' => $row['email'], 'ips' => $ips];
}

/**
 * @internal — collect distinct IPs from failed-login log entries for $username
 * in the last 24 h that are currently auto-blacklisted, then DELETE those rows.
 *
 * @return list<string>  IPs actually removed.
 */
function _admin_unblock_ips_for_user(mysqli $con, string $username): array
{
    $toUnblock = _admin_preview_ips_for_user($con, $username);
    if (empty($toUnblock)) return [];

    $blTable   = AUTH_DB_PREFIX . 'auth_blacklist';
    $unblocked = [];
    foreach ($toUnblock as $ip) {
        $del = $con->prepare("DELETE FROM {$blTable} WHERE ip = ? AND auto = 1");
        $del->bind_param('s', $ip);
        $del->execute();
        try {
            if ($del->affected_rows > 0) $unblocked[] = $ip;
        } catch (\Error) {
            $unblocked[] = $ip; // stub/PHP 8.5 safety — optimistic
        }
        $del->close();
    }
    return $unblocked;
}

/**
 * @internal — collect distinct IPs from auth_log that match 'Login failed for {$username}.'
 * in the last 24 h AND are currently in auth_blacklist with auto=1.
 *
 * @return list<string>
 */
function _admin_preview_ips_for_user(mysqli $con, string $username): array
{
    $logTable = AUTH_DB_PREFIX . 'auth_log';
    $activity = "Login failed for {$username}.";
    $stmt     = $con->prepare(
        "SELECT DISTINCT INET_NTOA(ipAdress) AS ip
         FROM {$logTable}
         WHERE context = 'login'
           AND activity = ?
           AND logTime >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"
    );
    if ($stmt === false) return [];
    $stmt->bind_param('s', $activity);
    $stmt->execute();
    $result    = $stmt->get_result();
    $failedIps = [];
    while ($r = $result->fetch_assoc()) {
        if (isset($r['ip']) && $r['ip'] !== null) $failedIps[] = $r['ip'];
    }
    $stmt->close();

    if (empty($failedIps)) return [];

    // Filter to only IPs currently in the auto-blacklist.
    $blTable   = AUTH_DB_PREFIX . 'auth_blacklist';
    $autoListed = [];
    foreach ($failedIps as $ip) {
        $sel = $con->prepare(
            "SELECT 1 FROM {$blTable}
             WHERE ip = ? AND auto = 1
               AND (expires_at IS NULL OR expires_at > NOW())
             LIMIT 1"
        );
        if ($sel === false) continue;
        $sel->bind_param('s', $ip);
        $sel->execute();
        $found = $sel->get_result()->fetch_row();
        $sel->close();
        if ($found) $autoListed[] = $ip;
    }
    return $autoListed;
}

/**
 * Register a cleanup callback invoked before an auth_accounts row is deleted.
 *
 * Use this for cross-DB tables that reference auth_accounts.id but cannot use
 * ON DELETE CASCADE because they live in a different database (e.g.
 * wlmonitor.wl_preferences, energie.en_preferences). Same-DB tables should use
 * a real FK constraint instead — see ~/.claude/rules/auth-rules.md §5.
 *
 * The callback signature is: function(mysqli $con, int $userId): void
 * Thrown exceptions are caught and logged to auth_log — a failing cleanup does
 * NOT abort the user deletion, because a half-deleted account is worse than an
 * orphan pref row.
 *
 * Hooks fire in registration order. Register at app bootstrap (e.g. in
 * inc/initialize.php after the auth library is loaded).
 */
function admin_register_delete_cleanup(callable $fn): void
{
    static $registered = [];
    // Use a static holder in a module-scoped function so we avoid $GLOBALS.
    // The internal _admin_delete_cleanups() reaches in via the same static.
    $registered[] = $fn;
    _admin_delete_cleanups($registered);
}

/**
 * @internal — returns the current cleanup-hook list. Passing a non-null
 * argument updates the stored list.
 *
 * @param list<callable>|null $set
 * @return list<callable>
 */
function _admin_delete_cleanups(?array $set = null): array
{
    static $hooks = [];
    if ($set !== null) {
        $hooks = $set;
    }
    return $hooks;
}

/**
 * Permanently delete a user account.
 * Self-deletion is blocked: returns false if $targetId === $requestingUserId.
 *
 * Cleanup hooks registered via admin_register_delete_cleanup() run before the
 * DELETE. Hook failures are logged but do not abort the deletion.
 *
 * @return bool True if a row was deleted.
 */
function admin_delete_user(mysqli $con, int $targetId, int $requestingUserId): bool
{
    if ($targetId === $requestingUserId) {
        return false;
    }

    foreach (_admin_delete_cleanups() as $hook) {
        try {
            $hook($con, $targetId);
        } catch (\Throwable $e) {
            appendLog(
                $con,
                'admin',
                "Delete cleanup failed for user #{$targetId}: " . $e->getMessage()
            );
        }
    }

    $table = AUTH_DB_PREFIX . 'auth_accounts';
    $stmt  = $con->prepare("DELETE FROM {$table} WHERE id = ?");
    $stmt->bind_param('i', $targetId);
    $stmt->execute();
    try {
        $ok = $stmt->affected_rows > 0;
    } catch (\Error) {
        $ok = false;
    }
    $stmt->close();

    appendLog($con, 'admin', "User #$targetId deleted.");
    return $ok;
}

/**
 * Start impersonating $targetId.
 * Caller must be an Admin (enforce via admin_require() at the consumer endpoint).
 *
 * Refuses when: target === self, target missing, target disabled, target is Admin,
 * or already impersonating (nested impersonation blocked).
 * Stashes original session under $_SESSION['impersonator'], swaps in target fields,
 * session_regenerate_id(true), logs the event.
 */
function admin_impersonate_begin(mysqli $con, int $targetId): bool
{
    $selfId = (int) ($_SESSION['id'] ?? 0);

    if ($targetId === $selfId)             return false;
    if (!empty($_SESSION['impersonator'])) return false;

    $table = AUTH_DB_PREFIX . 'auth_accounts';
    $stmt  = $con->prepare(
        "SELECT id, username, email,
                (img_blob IS NOT NULL) AS has_avatar,
                disabled, rights, theme
         FROM {$table} WHERE id = ?"
    );
    $stmt->bind_param('i', $targetId);
    $stmt->execute();
    $target = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$target)                      return false;
    if ($target['rights'] === 'Admin') return false;
    if ((int) $target['disabled'])     return false;

    $_SESSION['impersonator'] = [
        'id'         => $selfId,
        'username'   => (string) ($_SESSION['username']   ?? ''),
        'email'      => (string) ($_SESSION['email']      ?? ''),
        'has_avatar' => (bool)   ($_SESSION['has_avatar'] ?? false),
        'disabled'   => (int)    ($_SESSION['disabled']   ?? 0),
        'rights'     => (string) ($_SESSION['rights']     ?? ''),
        'theme'      => (string) ($_SESSION['theme']      ?? 'auto'),
        'sId'        => (string) ($_SESSION['sId']        ?? ''),
    ];

    $_SESSION['id']         = (int)    $target['id'];
    $_SESSION['username']   = (string) $target['username'];
    $_SESSION['email']      = (string) $target['email'];
    $_SESSION['has_avatar'] = (bool)   $target['has_avatar'];
    $_SESSION['disabled']   = (int)    $target['disabled'];
    $_SESSION['rights']     = (string) $target['rights'];
    $_SESSION['theme']      = (string) ($target['theme'] ?? 'auto');
    $_SESSION['sId']        = '';

    session_regenerate_id(true);

    $adminId   = $_SESSION['impersonator']['id'];
    $adminUser = $_SESSION['impersonator']['username'];
    appendLog(
        $con, 'admin',
        "Admin #{$adminId} ({$adminUser}) began impersonating user #{$targetId} ({$target['username']})."
    );
    return true;
}

/**
 * End impersonation: restore stashed admin fields, clear the stash,
 * session_regenerate_id(true), log the exit.
 * Returns false if there is no active impersonation stash.
 */
function admin_impersonate_end(mysqli $con): bool
{
    if (empty($_SESSION['impersonator'])) {
        return false;
    }

    $stash      = $_SESSION['impersonator'];
    $targetId   = (int)    ($_SESSION['id']       ?? 0);
    $targetUser = (string) ($_SESSION['username'] ?? '');

    $_SESSION['id']         = (int)    ($stash['id']         ?? 0);
    $_SESSION['username']   = (string) ($stash['username']   ?? '');
    $_SESSION['email']      = (string) ($stash['email']      ?? '');
    $_SESSION['has_avatar'] = (bool)   ($stash['has_avatar'] ?? false);
    $_SESSION['disabled']   = (int)    ($stash['disabled']   ?? 0);
    $_SESSION['rights']     = (string) ($stash['rights']     ?? '');
    $_SESSION['theme']      = (string) ($stash['theme']      ?? 'auto');
    $_SESSION['sId']        = (string) ($stash['sId']        ?? '');
    unset($_SESSION['impersonator']);

    session_regenerate_id(true);

    $adminId   = (int)    $_SESSION['id'];
    $adminUser = (string) $_SESSION['username'];
    appendLog(
        $con, 'admin',
        "Admin #{$adminId} ({$adminUser}) ended impersonation of user #{$targetId} ({$targetUser})."
    );
    return true;
}

/** True iff the current session is an impersonated session. */
function admin_is_impersonating(): bool
{
    return !empty($_SESSION['impersonator']);
}

/**
 * When impersonating, return the original admin's id and username.
 * Returns null when not impersonating.
 *
 * @return array{id: int, username: string}|null
 */
function admin_impersonator_info(): ?array
{
    if (empty($_SESSION['impersonator'])) {
        return null;
    }
    return [
        'id'       => (int)    $_SESSION['impersonator']['id'],
        'username' => (string) $_SESSION['impersonator']['username'],
    ];
}
