<?php
/**
 * src/invite.php — Invite token management (create, verify, complete).
 *
 * Sending the invite email lives in src/mail_helpers.php (`mail_send_invite`).
 *
 * Requires:
 *  - AUTH_DB_PREFIX constant  (e.g. 'jardyx_auth.' or '')
 *  - appendLog() from src/log.php
 */

/**
 * Generate and persist a 48-hour invite token for $userId.
 * Uses REPLACE INTO so re-inviting a user invalidates any existing token.
 *
 * @return string 64-char hex token (256-bit entropy).
 */
function invite_create_token(mysqli $con, int $userId): string
{
    $table   = AUTH_DB_PREFIX . 'auth_invite_tokens';
    $token   = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', time() + 48 * 3600);

    $stmt = $con->prepare(
        "REPLACE INTO {$table} (user_id, token, expires_at) VALUES (?, ?, ?)"
    );
    $stmt->bind_param('iss', $userId, $token, $expires);
    $stmt->execute();
    $stmt->close();

    return $token;
}

/**
 * Verify an invite token.
 *
 * @return int|null user_id if token exists and has not expired; null otherwise.
 */
function invite_verify_token(mysqli $con, string $token): ?int
{
    $table = AUTH_DB_PREFIX . 'auth_invite_tokens';
    $stmt  = $con->prepare(
        "SELECT user_id FROM {$table} WHERE token = ? AND expires_at > NOW()"
    );
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ? (int) $row['user_id'] : null;
}

/**
 * Complete the invitation: hash password, enable account, delete token.
 *
 * @return bool True if the account row was updated.
 */
function invite_complete(mysqli $con, int $userId, string $password): bool
{
    $table      = AUTH_DB_PREFIX . 'auth_accounts';
    $tokenTable = AUTH_DB_PREFIX . 'auth_invite_tokens';
    $hash       = password_hash($password, PASSWORD_BCRYPT, ['cost' => 13]);

    $stmt = $con->prepare(
        "UPDATE {$table} SET password = ?, disabled = 0, activation_code = 'activated' WHERE id = ?"
    );
    $stmt->bind_param('si', $hash, $userId);
    $stmt->execute();
    // affected_rows is a readonly property on real mysqli_stmt.
    // PHPUnit 13 stubs on PHP 8.5 block direct property access — catch defensively.
    try {
        $ok = $stmt->affected_rows > 0;
    } catch (\Error) {
        $ok = false;
    }
    $stmt->close();

    $stmt = $con->prepare("DELETE FROM {$tokenTable} WHERE user_id = ?");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->close();

    return $ok;
}
