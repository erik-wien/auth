<?php
/**
 * src/tokens.php — Library helpers for token generation.
 *
 * Centralises bin2hex(random_bytes(32)) so consumer apps never inline it.
 *
 * Requires:
 *  - AUTH_DB_PREFIX constant
 */

/**
 * Generate and persist a 1-hour password-reset token for $userId.
 * Invalidates any existing unexpired tokens for the user before inserting.
 *
 * @return array{ok: true, token: string}
 */
function auth_reset_token_issue(mysqli $con, int $userId): array
{
    $table = AUTH_DB_PREFIX . 'password_resets';
    $token = bin2hex(random_bytes(32));

    $del = $con->prepare("DELETE FROM {$table} WHERE user_id = ?");
    $del->bind_param('i', $userId);
    $del->execute();
    $del->close();

    $expires = date('Y-m-d H:i:s', time() + 3600);
    $ins = $con->prepare(
        "INSERT INTO {$table} (user_id, token, expires_at) VALUES (?, ?, ?)"
    );
    $ins->bind_param('iss', $userId, $token, $expires);
    $ins->execute();
    $ins->close();

    return ['ok' => true, 'token' => $token];
}

/**
 * Store a pending e-mail change code on the user's account row.
 * The code is written to auth_accounts.email_change_code; the new address
 * to auth_accounts.pending_email.
 *
 * @return array{ok: true, token: string}
 */
function auth_email_confirmation_issue(mysqli $con, int $userId, string $newEmail): array
{
    $table = AUTH_DB_PREFIX . 'auth_accounts';
    $code  = bin2hex(random_bytes(32));

    $upd = $con->prepare(
        "UPDATE {$table} SET pending_email = ?, email_change_code = ? WHERE id = ?"
    );
    $upd->bind_param('ssi', $newEmail, $code, $userId);
    $upd->execute();
    $upd->close();

    return ['ok' => true, 'token' => $code];
}
