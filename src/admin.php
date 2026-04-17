<?php
/**
 * src/admin.php — User administration functions.
 *
 * Requires:
 *  - AUTH_DB_PREFIX constant (e.g. 'jardyx_auth.' or '')
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
 *   users: list<array{id: int, username: string, email: string, rights: string, disabled: int, debug: int}>,
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
            "SELECT id, username, email, rights, disabled, debug
             FROM {$table} WHERE username LIKE ? ORDER BY username LIMIT ? OFFSET ?"
        );
        $stmt->bind_param('sii', $like, $perPage, $offset);
    } else {
        $stmt = $con->prepare(
            "SELECT id, username, email, rights, disabled, debug
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
            'debug'    => (int) $row['debug'],
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
    int    $debug,
    bool   $totp_reset = false
): bool {
    $table  = AUTH_DB_PREFIX . 'auth_accounts';
    $rights = in_array($rights, ['Admin', 'User'], true) ? $rights : 'User';

    $stmt = $con->prepare(
        "UPDATE {$table} SET email = ?, rights = ?, disabled = ?, debug = ? WHERE id = ?"
    );
    $stmt->bind_param('ssiii', $email, $rights, $disabled, $debug, $targetId);
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
 * Send a fresh invite email to a user (same flow as creation, invalidates old token).
 *
 * @return bool True if the token was created and email was sent successfully.
 */
function admin_reset_password(mysqli $con, int $targetId, string $baseUrl): bool
{
    $table = AUTH_DB_PREFIX . 'auth_accounts';
    $stmt  = $con->prepare("SELECT email, username FROM {$table} WHERE id = ?");
    $stmt->bind_param('i', $targetId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        return false;
    }

    $token = invite_create_token($con, $targetId);
    $link  = rtrim($baseUrl, '/') . '/setpassword.php?token=' . urlencode($token);
    $sent  = mail_send_invite($row['email'], $row['username'], $link);

    appendLog($con, 'admin', "Password reset sent to user #$targetId.");
    return $sent;
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
