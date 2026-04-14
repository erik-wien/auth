<?php

namespace ErikR\Auth\Tests\Unit;

use PHPUnit\Framework\TestCase;

class TotpTest extends TestCase
{
    // ── Helpers ───────────────────────────────────────────────────────────────

    private function stubCon(?\mysqli_stmt $stmt = null): \mysqli
    {
        if ($stmt === null) {
            $stmt = $this->createStub(\mysqli_stmt::class);
            $stmt->method('bind_param')->willReturn(true);
            $stmt->method('execute')->willReturn(true);
            $stmt->method('close')->willReturn(true);
        }
        $con = $this->createStub(\mysqli::class);
        $con->method('prepare')->willReturn($stmt);
        return $con;
    }

    private function captureCon(array &$sqls, array &$params, ?\mysqli_stmt $stmt = null): \mysqli
    {
        if ($stmt === null) {
            $stmt = $this->createStub(\mysqli_stmt::class);
            $stmt->method('execute')->willReturn(true);
            $stmt->method('close')->willReturn(true);
        }
        // Capture bind_param args
        $stmt->method('bind_param')
            ->willReturnCallback(function () use (&$params) {
                $params[] = func_get_args();
                return true;
            });

        $con = $this->createStub(\mysqli::class);
        $con->method('prepare')
            ->willReturnCallback(function (string $sql) use (&$sqls, $stmt) {
                $sqls[] = $sql;
                return $stmt;
            });
        return $con;
    }

    // ── Base32 round-trip ─────────────────────────────────────────────────────

    public function test_base32_encode_decode_roundtrip(): void
    {
        $binary = random_bytes(20);
        $encoded = _auth_totp_base32_encode($binary);
        $decoded = _auth_totp_base32_decode($encoded);
        $this->assertSame($binary, $decoded);
    }

    // ── generate_secret ───────────────────────────────────────────────────────

    public function test_generate_secret_returns_32_char_base32(): void
    {
        $secret = auth_totp_generate_secret();
        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $secret);
    }

    public function test_generate_secret_is_unique(): void
    {
        $this->assertNotSame(auth_totp_generate_secret(), auth_totp_generate_secret());
    }

    // ── verify ────────────────────────────────────────────────────────────────

    public function test_verify_accepts_valid_code(): void
    {
        $secret  = auth_totp_generate_secret();
        $counter = (int) floor(time() / 30);
        $code    = _auth_totp_hotp($secret, $counter);
        $this->assertTrue(auth_totp_verify($secret, $code));
    }

    public function test_verify_rejects_wrong_code(): void
    {
        $secret = auth_totp_generate_secret();
        $this->assertFalse(auth_totp_verify($secret, '000000'));
    }

    public function test_verify_rejects_non_numeric(): void
    {
        $secret = auth_totp_generate_secret();
        $this->assertFalse(auth_totp_verify($secret, 'abcdef'));
    }

    public function test_verify_rejects_wrong_length(): void
    {
        $secret = auth_totp_generate_secret();
        $this->assertFalse(auth_totp_verify($secret, '12345'));   // 5 digits
        $this->assertFalse(auth_totp_verify($secret, '1234567')); // 7 digits
    }

    // ── uri ───────────────────────────────────────────────────────────────────

    public function test_uri_contains_secret_and_issuer(): void
    {
        $uri = auth_totp_uri('MYSECRET', 'alice', 'MyApp');
        $this->assertStringContainsString('otpauth://totp/', $uri);
        $this->assertStringContainsString('secret=MYSECRET', $uri);
        $this->assertStringContainsString('issuer=MyApp', $uri);
    }

    // ── enable ────────────────────────────────────────────────────────────────

    public function test_enable_returns_null_for_unknown_user(): void
    {
        // prepare() returns a stmt whose get_result()->fetch_assoc() returns null
        $result = $this->createStub(\mysqli_result::class);
        $result->method('fetch_assoc')->willReturn(null);
        $stmt = $this->createStub(\mysqli_stmt::class);
        $stmt->method('bind_param')->willReturn(true);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('get_result')->willReturn($result);
        $stmt->method('close')->willReturn(true);
        $con = $this->stubCon($stmt);

        $this->assertNull(auth_totp_enable($con, 999));
    }

    public function test_enable_returns_secret_for_known_user(): void
    {
        $result = $this->createStub(\mysqli_result::class);
        $result->method('fetch_assoc')->willReturn(['id' => 1]);
        $stmt = $this->createStub(\mysqli_stmt::class);
        $stmt->method('bind_param')->willReturn(true);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('get_result')->willReturn($result);
        $stmt->method('close')->willReturn(true);
        $con = $this->stubCon($stmt);

        $secret = auth_totp_enable($con, 1);
        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $secret);
    }

    // ── confirm ───────────────────────────────────────────────────────────────

    public function test_confirm_saves_secret_on_valid_code(): void
    {
        $secret  = auth_totp_generate_secret();
        $counter = (int) floor(time() / 30);
        $code    = _auth_totp_hotp($secret, $counter);

        $sqls   = [];
        $params = [];
        // execute() returns false so the && short-circuits before affected_rows
        // is read — PHPUnit stubs on PHP 8.5 cannot expose internal properties.
        // The test only verifies that the correct UPDATE SQL was issued.
        $stmt = $this->createStub(\mysqli_stmt::class);
        $stmt->method('execute')->willReturn(false);
        $stmt->method('close')->willReturn(true);
        $con = $this->captureCon($sqls, $params, $stmt);

        auth_totp_confirm($con, 1, $secret, $code);
        $this->assertCount(1, $sqls);
        $this->assertMatchesRegularExpression('/UPDATE.*auth_accounts.*SET totp_secret/i', $sqls[0]);
    }

    public function test_confirm_returns_false_on_wrong_code(): void
    {
        $secret = auth_totp_generate_secret();
        $sqls   = [];
        $params = [];
        $con    = $this->captureCon($sqls, $params);

        $ok = auth_totp_confirm($con, 1, $secret, '000000');
        $this->assertFalse($ok);
        // No DB call should have been made
        $this->assertCount(0, $sqls);
    }

    // ── disable ───────────────────────────────────────────────────────────────

    public function test_disable_sets_null(): void
    {
        $sqls   = [];
        $params = [];
        $con    = $this->captureCon($sqls, $params);

        auth_totp_disable($con, 5);
        $this->assertCount(1, $sqls);
        $this->assertMatchesRegularExpression('/UPDATE.*auth_accounts.*SET totp_secret = NULL/i', $sqls[0]);
    }
}
