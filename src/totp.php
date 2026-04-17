<?php
/**
 * src/totp.php — TOTP (RFC 6238) functions for erikr/auth.
 *
 * Public API prefix: auth_totp_
 * Internal helpers:  _auth_totp_
 *
 * Requires AUTH_DB_PREFIX constant and appendLog() from src/log.php.
 */

/**
 * Encode a binary string to Base32 (RFC 4648, no padding).
 * @internal
 */
function _auth_totp_base32_encode(string $input): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $output   = '';
    $buffer   = 0;
    $bits     = 0;

    foreach (str_split($input) as $char) {
        $buffer = ($buffer << 8) | ord($char);
        $bits  += 8;
        while ($bits >= 5) {
            $bits   -= 5;
            $output .= $alphabet[($buffer >> $bits) & 0x1F];
        }
    }
    if ($bits > 0) {
        $output .= $alphabet[($buffer << (5 - $bits)) & 0x1F];
    }
    return $output;
}

/**
 * Decode a Base32 string (RFC 4648, case-insensitive, ignores padding) to binary.
 * @internal
 */
function _auth_totp_base32_decode(string $input): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $input    = strtoupper(rtrim($input, '='));
    $output   = '';
    $buffer   = 0;
    $bits     = 0;

    foreach (str_split($input) as $char) {
        $pos = strpos($alphabet, $char);
        if ($pos === false) continue;
        $buffer = ($buffer << 5) | $pos;
        $bits  += 5;
        if ($bits >= 8) {
            $bits   -= 8;
            $output .= chr(($buffer >> $bits) & 0xFF);
        }
    }
    return $output;
}

/**
 * Compute an HOTP value (RFC 4226) from a Base32 secret and a counter.
 * Returns a zero-padded 6-digit string.
 * @internal
 */
function _auth_totp_hotp(string $secret, int $counter): string
{
    $key  = _auth_totp_base32_decode($secret);
    $msg  = pack('J', $counter);   // 8-byte big-endian unsigned
    $hash = hash_hmac('sha1', $msg, $key, true);
    $off  = ord($hash[19]) & 0x0F;
    $code = (
        ((ord($hash[$off])     & 0x7F) << 24) |
        ((ord($hash[$off + 1]) & 0xFF) << 16) |
        ((ord($hash[$off + 2]) & 0xFF) <<  8) |
         (ord($hash[$off + 3]) & 0xFF)
    ) % 1_000_000;
    return str_pad((string) $code, 6, '0', STR_PAD_LEFT);
}

/**
 * Generate a new cryptographically random 32-character Base32 secret.
 * Does NOT save to DB — caller must call auth_totp_confirm() after QR display.
 */
function auth_totp_generate_secret(): string
{
    return _auth_totp_base32_encode(random_bytes(20));
}

/**
 * Verify a 6-digit TOTP code against a Base32 secret.
 * Accepts ±1 time window (90 s clock-drift tolerance).
 * Returns false immediately if $code is not exactly 6 digits.
 */
function auth_totp_verify(string $secret, string $code): bool
{
    if (!preg_match('/^\d{6}$/', $code)) {
        return false;
    }
    $counter = (int) floor(time() / 30);
    foreach ([-1, 0, 1] as $offset) {
        if (hash_equals(_auth_totp_hotp($secret, $counter + $offset), $code)) {
            return true;
        }
    }
    return false;
}

/**
 * Build an otpauth://totp/... URI for QR code generation.
 *
 * @param string $secret Base32 secret.
 * @param string $label  Shown in the authenticator app (e.g. "alice").
 * @param string $issuer App name shown in the authenticator app.
 */
function auth_totp_uri(string $secret, string $label, string $issuer): string
{
    return 'otpauth://totp/'
        . rawurlencode($label)
        . '?secret=' . rawurlencode($secret)
        . '&issuer=' . rawurlencode($issuer)
        . '&algorithm=SHA1&digits=6&period=30';
}

/**
 * Generate a fresh TOTP secret for $userId and return it for QR display.
 * Does NOT write to DB — call auth_totp_confirm() after the user scans the QR.
 * Returns null if the user does not exist.
 */
function auth_totp_enable(mysqli $con, int $userId): ?string
{
    $table = AUTH_DB_PREFIX . 'auth_accounts';
    // Guard: return null for unknown users to avoid generating QR codes for deleted accounts.
    $stmt  = $con->prepare("SELECT id FROM {$table} WHERE id = ?");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row === null) {
        return null;
    }
    return auth_totp_generate_secret();
}

/**
 * Verify $code against $secret; on success save $secret to auth_accounts.
 * Returns true on success, false on wrong code or DB error.
 */
function auth_totp_confirm(mysqli $con, int $userId, string $secret, string $code): bool
{
    if (!auth_totp_verify($secret, $code)) {
        return false;
    }
    $table = AUTH_DB_PREFIX . 'auth_accounts';
    $stmt  = $con->prepare("UPDATE {$table} SET totp_secret = ? WHERE id = ?");
    $stmt->bind_param('si', $secret, $userId);
    $ok = $stmt->execute() && $stmt->affected_rows > 0;
    $stmt->close();
    return $ok;
}

/**
 * Disable 2FA for $userId by setting totp_secret = NULL.
 */
function auth_totp_disable(mysqli $con, int $userId): void
{
    $table = AUTH_DB_PREFIX . 'auth_accounts';
    $stmt  = $con->prepare("UPDATE {$table} SET totp_secret = NULL WHERE id = ?");
    if ($stmt === false) return;
    $stmt->bind_param('i', $userId);
    try {
        $stmt->execute();
    } catch (\Throwable $e) {
        appendLog($con, 'totp', 'auth_totp_disable failed for user #' . $userId . ': ' . $e->getMessage());
    }
    $stmt->close();
}
