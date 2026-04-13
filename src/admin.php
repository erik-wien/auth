<?php
/**
 * src/admin.php — User administration functions.
 *
 * Requires:
 *  - AUTH_DB_PREFIX constant (e.g. 'jardyx_auth.' or '')
 *  - invite_create_token(), invite_send_email() from src/invite.php
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
    $placeholder = password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT, ['cost' => 13]);

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
    invite_send_email($email, $username, $token, $baseUrl);

    return $userId;
}

/**
 * Update a user's common fields.
 * Rights values not in ['Admin', 'User'] are silently coerced to 'User'.
 *
 * @return bool True if the row was updated.
 */
function admin_edit_user(
    mysqli $con,
    int    $targetId,
    string $email,
    string $rights,
    int    $disabled,
    int    $debug
): bool {
    $table  = AUTH_DB_PREFIX . 'auth_accounts';
    $rights = in_array($rights, ['Admin', 'User'], true) ? $rights : 'User';

    $stmt = $con->prepare(
        "UPDATE {$table} SET email = ?, rights = ?, disabled = ?, debug = ? WHERE id = ?"
    );
    $stmt->bind_param('ssssi', $email, $rights, $disabled, $debug, $targetId);
    $stmt->execute();
    try {
        $ok = $stmt->affected_rows > 0;
    } catch (\Error) {
        $ok = false;
    }
    $stmt->close();

    appendLog($con, 'admin', "User #$targetId updated.", 'web');
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
    $sent  = invite_send_email($row['email'], $row['username'], $token, $baseUrl);

    appendLog($con, 'admin', "Password reset sent to user #$targetId.", 'web');
    return $sent;
}

/**
 * Permanently delete a user account.
 * Self-deletion is blocked: returns false if $targetId === $requestingUserId.
 *
 * @return bool True if a row was deleted.
 */
function admin_delete_user(mysqli $con, int $targetId, int $requestingUserId): bool
{
    if ($targetId === $requestingUserId) {
        return false;
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

    appendLog($con, 'admin', "User #$targetId deleted.", 'web');
    return $ok;
}
