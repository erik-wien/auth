<?php

namespace ErikR\Auth\Tests\Unit;

use PHPUnit\Framework\TestCase;

class TotpAuthTest extends TestCase
{
    private function makeRow(array $overrides = []): array
    {
        return array_merge([
            'id'              => 1,
            'username'        => 'alice',
            'password'        => password_hash('secret', PASSWORD_BCRYPT, ['cost' => 4]),
            'email'           => 'test@jardyx.com',
            'has_avatar'      => 0,
            'activation_code' => 'activated',
            'disabled'        => 0,
            'invalidLogins'   => 0,
            'rights'          => 'User',
            'theme'           => 'auto',
            'totp_secret'     => null,
        ], $overrides);
    }

    /**
     * Build a generic success stmt (for UPDATEs, INSERTs, blacklist SELECT, etc.)
     */
    private function makeSuccessStmt(): \mysqli_stmt
    {
        $stmt = $this->createStub(\mysqli_stmt::class);
        $stmt->method('bind_param')->willReturn(true);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('close')->willReturn(true);
        return $stmt;
    }

    /**
     * Build a blacklist-check stmt.
     * auth_is_blacklisted() calls $stmt->get_result()->fetch_row() — returns null
     * (no blacklisted row) so the login is allowed to proceed.
     */
    private function makeBlacklistStmt(): \mysqli_stmt
    {
        $result = $this->createStub(\mysqli_result::class);
        $result->method('fetch_row')->willReturn(null);

        $stmt = $this->createStub(\mysqli_stmt::class);
        $stmt->method('bind_param')->willReturn(true);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('get_result')->willReturn($result);
        $stmt->method('close')->willReturn(true);
        return $stmt;
    }

    /**
     * Build the user-SELECT stmt for auth_login().
     *
     * auth_login() was updated to use fetch_assoc() === null instead of num_rows,
     * so we only need to stub fetch_assoc() here.
     */
    private function makeSelectStmt(array $row): \mysqli_stmt
    {
        $result = $this->createStub(\mysqli_result::class);
        $result->method('fetch_assoc')->willReturn($row);

        $stmt = $this->createStub(\mysqli_stmt::class);
        $stmt->method('bind_param')->willReturn(true);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('get_result')->willReturn($result);
        $stmt->method('close')->willReturn(true);
        return $stmt;
    }

    /**
     * Build a $con stub for auth_login().
     *
     * prepare() call sequence inside auth_login():
     *  1. auth_is_blacklisted() SELECT
     *  2. User SELECT
     *  3. Rehash UPDATE (password_needs_rehash() returns true: cost 4 → 13)
     *
     * For the non-TOTP path, _auth_setup_session() adds:
     *  4. lastLogin UPDATE
     *  5. appendLog() INSERT
     */
    private function stubConForLogin(array $row): \mysqli
    {
        $blacklistStmt = $this->makeBlacklistStmt();
        $selectStmt    = $this->makeSelectStmt($row);
        $updateStmt    = $this->makeSuccessStmt();

        $con = $this->createStub(\mysqli::class);
        $con->method('prepare')->willReturnOnConsecutiveCalls(
            $blacklistStmt, // 1: auth_is_blacklisted SELECT
            $selectStmt,    // 2: user SELECT
            $updateStmt,    // 3: rehash UPDATE
            $updateStmt,    // 4: lastLogin UPDATE (non-TOTP path only)
            $updateStmt     // 5: appendLog INSERT  (non-TOTP path only)
        );
        return $con;
    }

    // ── auth_login with TOTP ──────────────────────────────────────────────────

    public function test_login_returns_totp_required_when_secret_set(): void
    {
        $secret = auth_totp_generate_secret();
        $row    = $this->makeRow(['totp_secret' => $secret]);
        $con    = $this->stubConForLogin($row);

        $_SESSION = [];
        $result = auth_login($con, 'alice', 'secret');

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['totp_required'] ?? false);
        $this->assertArrayHasKey('auth_totp_pending', $_SESSION);
        $this->assertSame($row, $_SESSION['auth_totp_pending']['user_data']);
        $this->assertSame(0, $_SESSION['auth_totp_pending']['attempts']);
        $this->assertGreaterThan(time(), $_SESSION['auth_totp_pending']['until']);
    }

    public function test_login_does_not_set_pending_for_non_totp_user(): void
    {
        $row = $this->makeRow(['totp_secret' => null]);
        $con = $this->stubConForLogin($row);

        $_SESSION = [];
        $result = auth_login($con, 'alice', 'secret');

        $this->assertTrue($result['ok']);
        $this->assertArrayNotHasKey('totp_required', $result);
        $this->assertArrayNotHasKey('auth_totp_pending', $_SESSION);
    }

    // ── auth_totp_complete ────────────────────────────────────────────────────

    private function setupPendingSession(?string $secret = null): string
    {
        $secret = $secret ?? auth_totp_generate_secret();
        $row    = $this->makeRow(['totp_secret' => $secret]);
        $_SESSION['auth_totp_pending'] = [
            'user_data' => $row,
            'until'     => time() + 300,
            'attempts'  => 0,
        ];
        return $secret;
    }

    /**
     * Build a $con stub for auth_totp_complete().
     *
     * _auth_setup_session() calls prepare() for:
     *  1. lastLogin UPDATE
     *  2. appendLog() INSERT
     */
    private function stubConForComplete(): \mysqli
    {
        $stmt = $this->makeSuccessStmt();
        $con  = $this->createStub(\mysqli::class);
        $con->method('prepare')->willReturn($stmt);
        return $con;
    }

    public function test_totp_complete_completes_session_on_valid_code(): void
    {
        $secret  = $this->setupPendingSession();
        $counter = (int) floor(time() / 30);
        $code    = _auth_totp_hotp($secret, $counter);
        $con     = $this->stubConForComplete();

        $result = auth_totp_complete($con, $code);

        $this->assertTrue($result['ok']);
        $this->assertArrayNotHasKey('auth_totp_pending', $_SESSION);
        $this->assertTrue($_SESSION['loggedin'] ?? false);
    }

    public function test_totp_complete_fails_on_wrong_code(): void
    {
        $this->setupPendingSession();
        $con = $this->stubConForComplete();

        $result = auth_totp_complete($con, '000000');

        $this->assertFalse($result['ok']);
        $this->assertArrayHasKey('error', $result);
        $this->assertArrayHasKey('auth_totp_pending', $_SESSION);
        $this->assertSame(1, $_SESSION['auth_totp_pending']['attempts']);
    }

    public function test_totp_complete_fails_after_five_attempts(): void
    {
        $this->setupPendingSession();
        $con = $this->stubConForComplete();

        $result = null;
        for ($i = 0; $i < 5; $i++) {
            $result = auth_totp_complete($con, '000000');
        }

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('viele', $result['error']);
        $this->assertArrayNotHasKey('auth_totp_pending', $_SESSION);
    }

    public function test_totp_complete_fails_when_ttl_expired(): void
    {
        $this->setupPendingSession();
        $_SESSION['auth_totp_pending']['until'] = time() - 1;
        $con = $this->stubConForComplete();

        $result = auth_totp_complete($con, '123456');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('abgelaufen', $result['error']);
        $this->assertArrayNotHasKey('auth_totp_pending', $_SESSION);
    }

    public function test_totp_complete_fails_with_no_pending_session(): void
    {
        unset($_SESSION['auth_totp_pending']);
        $con = $this->stubConForComplete();

        $result = auth_totp_complete($con, '123456');

        $this->assertFalse($result['ok']);
    }
}
