<?php
/**
 * src/avatar.php — Avatar storage API.
 *
 * Avatars are 205x205 JPEG blobs on auth_accounts.img_blob. This file owns
 * the schema side (store / clear); image processing (GD resize, upload
 * validation) lives in the chrome library. Apps call these through chrome
 * or directly.
 */

/**
 * Store a pre-processed JPEG blob as the user's avatar.
 *
 * The caller is responsible for producing a valid 205x205 image/jpeg byte
 * string — this function does not decode, validate, or re-encode.
 */
function auth_avatar_store(mysqli $con, int $userId, string $jpegBytes): void
{
    $table = AUTH_DB_PREFIX . 'auth_accounts';
    $stmt  = $con->prepare("UPDATE {$table} SET img_blob = ? WHERE id = ?");
    $null  = null;
    $stmt->bind_param('bi', $null, $userId);
    $stmt->send_long_data(0, $jpegBytes);
    $stmt->execute();
    $stmt->close();

    $_SESSION['has_avatar'] = true;
}

/**
 * Remove the user's avatar blob.
 */
function auth_avatar_clear(mysqli $con, int $userId): void
{
    $table = AUTH_DB_PREFIX . 'auth_accounts';
    $stmt  = $con->prepare("UPDATE {$table} SET img_blob = NULL WHERE id = ?");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->close();

    if (($_SESSION['id'] ?? null) === $userId) {
        $_SESSION['has_avatar'] = false;
    }
}
