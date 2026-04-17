<?php
declare(strict_types=0);

namespace ErikR\Auth\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for `auth_hash_password()` and `auth_change_password()`.
 *
 * Both live in src/auth.php. The hash helper centralises the bcrypt cost so
 * consumer apps stop re-encoding `PASSWORD_BCRYPT, ['cost' => 13]` directly
 * (see ~/.claude/rules/auth-rules.md §1 — the rule this test enforces).
 *
 * For auth_change_password we follow the TotpTest pattern: stub execute() to
 * false so the function short-circuits before reading affected_rows (which is
 * a read-only property that PHPUnit's mysqli_stmt stubs cannot expose on PHP
 * 8.5+). Tests verify the UPDATE SQL shape, not the full return-value matrix.
 */
final class PasswordTest extends TestCase
{
    // ── auth_hash_password ────────────────────────────────────────────────────

    public function test_hash_is_verifiable_against_original(): void
    {
        $hash = auth_hash_password('s3cret-passphrase');
        $this->assertTrue(password_verify('s3cret-passphrase', $hash));
        $this->assertFalse(password_verify('wrong', $hash));
    }

    public function test_hash_uses_bcrypt_cost_13(): void
    {
        $hash = auth_hash_password('anything');
        $info = password_get_info($hash);
        $this->assertSame(PASSWORD_BCRYPT, $info['algo']);
        $this->assertSame(13, $info['options']['cost']);
    }

    public function test_hash_does_not_need_rehash_at_library_standard(): void
    {
        $hash = auth_hash_password('anything');
        $this->assertFalse(
            password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => 13]),
            'Fresh hash must match the cost the login path checks for.'
        );
    }

    public function test_hash_differs_across_calls(): void
    {
        // bcrypt uses a per-call salt; identical passwords must not produce identical hashes.
        $a = auth_hash_password('same');
        $b = auth_hash_password('same');
        $this->assertNotSame($a, $b);
    }

    // ── auth_change_password ──────────────────────────────────────────────────

    public function test_change_password_issues_update_with_password_and_invalid_logins_reset(): void
    {
        $captured = '';
        $stmt = $this->createStub(\mysqli_stmt::class);
        $stmt->method('bind_param')->willReturn(true);
        $stmt->method('execute')->willReturn(false);  // short-circuits affected_rows read
        $stmt->method('close')->willReturn(true);

        $con = $this->createStub(\mysqli::class);
        $con->method('prepare')->willReturnCallback(function (string $sql) use (&$captured, $stmt) {
            if (str_contains($sql, 'auth_accounts')) {
                $captured = $sql;
            }
            return $stmt;
        });

        auth_change_password($con, 1, 'new-pw');

        $upper = strtoupper($captured);
        $this->assertStringContainsString('UPDATE', $upper);
        $this->assertStringContainsString('AUTH_ACCOUNTS', $upper);
        $this->assertStringContainsString('PASSWORD = ?', $upper);
        $this->assertStringContainsString('INVALIDLOGINS = 0', $upper);
    }

    public function test_change_password_returns_false_when_execute_fails(): void
    {
        $stmt = $this->createStub(\mysqli_stmt::class);
        $stmt->method('bind_param')->willReturn(true);
        $stmt->method('execute')->willReturn(false);
        $stmt->method('close')->willReturn(true);

        $con = $this->createStub(\mysqli::class);
        $con->method('prepare')->willReturn($stmt);

        $this->assertFalse(auth_change_password($con, 42, 'new-pw'));
    }

}
