<?php
/**
 * src/invite.php — User invitation and password-set flow.
 *
 * Requires:
 *  - AUTH_DB_PREFIX constant  (e.g. 'jardyx_auth.' or '')
 *  - SMTP_* constants + send_mail() from src/mailer.php
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
 * Send "Set your password" email to the user.
 * Link: {baseUrl}/setpassword.php?token={token}
 *
 * @return bool True on success; false if the mailer throws.
 */
function invite_send_email(string $email, string $username, string $token, string $baseUrl): bool
{
    $link    = rtrim($baseUrl, '/') . '/setpassword.php?token=' . urlencode($token);
    $subject = 'Passwort einrichten';
    $bodyHtml = '<p>Hallo ' . htmlspecialchars($username, ENT_QUOTES, 'UTF-8') . ',</p>'
              . '<p>Bitte richten Sie Ihr Passwort ein:</p>'
              . '<p><a href="' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '">'
              . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '</a></p>'
              . '<p>Dieser Link ist 48&nbsp;Stunden g&uuml;ltig.</p>';
    $bodyText = "Hallo $username,\n\nBitte richten Sie Ihr Passwort ein:\n$link\n\n"
              . "Dieser Link ist 48 Stunden gültig.";

    try {
        send_mail($email, $username, $subject, $bodyHtml, $bodyText);
        return true;
    } catch (\Throwable $e) {
        return false;
    }
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
    // Check affected_rows safely (accounts for test doubles/stubs that may not expose the property)
    $ok = false;
    try {
        $ok = $stmt->affected_rows > 0;
    } catch (\Error) {
        // Property access failed (e.g., on a test stub), assume 0 rows affected
        $ok = false;
    }
    $stmt->close();

    $stmt = $con->prepare("DELETE FROM {$tokenTable} WHERE user_id = ?");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->close();

    return $ok;
}
