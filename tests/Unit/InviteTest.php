<?php
declare(strict_types=0);

namespace ErikR\Auth\Tests\Unit;

use PHPUnit\Framework\TestCase;

class InviteTest extends TestCase
{
    // ── Helpers ───────────────────────────────────────────────────────────────

    /** Build a stub mysqli where all prepare() calls return the same generic stmt. */
    private function stubCon(?callable $onPrepare = null): \mysqli
    {
        $stmt = $this->createStub(\mysqli_stmt::class);
        $stmt->method('bind_param')->willReturn(true);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('close')->willReturn(true);

        $con = $this->createStub(\mysqli::class);
        if ($onPrepare) {
            $con->method('prepare')->willReturnCallback(
                fn(string $sql) => ($onPrepare($sql) ?? $stmt)
            );
        } else {
            $con->method('prepare')->willReturn($stmt);
        }

        return $con;
    }


    /** Build a stub mysqli_result that returns $row on the first fetch_assoc() call. */
    private function stubResult(?array $row): \mysqli_result
    {
        $result = $this->createStub(\mysqli_result::class);
        $result->method('fetch_assoc')->willReturn($row);
        return $result;
    }

    /** Build a stub con where prepare() returns a stmt whose get_result() returns $result. */
    private function stubConWithResult(\mysqli_result $result): \mysqli
    {
        $stmt = $this->createStub(\mysqli_stmt::class);
        $stmt->method('bind_param')->willReturn(true);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('get_result')->willReturn($result);
        $stmt->method('close')->willReturn(true);

        $con = $this->createStub(\mysqli::class);
        $con->method('prepare')->willReturn($stmt);
        return $con;
    }

    // ── invite_create_token ───────────────────────────────────────────────────

    public function test_create_token_returns_64_hex_chars(): void
    {
        $token = invite_create_token($this->stubCon(), 1);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
    }

    public function test_create_token_uses_replace_into(): void
    {
        $sql = '';
        $stmt = $this->createStub(\mysqli_stmt::class);
        $stmt->method('bind_param')->willReturn(true);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('close')->willReturn(true);

        $con = $this->createStub(\mysqli::class);
        $con->method('prepare')
            ->willReturnCallback(function (string $s) use (&$sql, $stmt) {
                $sql = $s;
                return $stmt;
            });

        invite_create_token($con, 7);
        $this->assertStringContainsString('REPLACE INTO', strtoupper($sql));
    }

    // ── invite_send_email ─────────────────────────────────────────────────────

    public function test_send_email_returns_false_when_smtp_unreachable(): void
    {
        // SMTP_HOST is 127.0.0.1:1025 (defined in bootstrap.php) — connection will fail.
        // invite_send_email() must catch the exception and return false.
        $result = invite_send_email('user@example.com', 'Alice', 'token123', 'http://localhost/app');
        $this->assertFalse($result);
    }

    // ── invite_verify_token ───────────────────────────────────────────────────

    public function test_verify_token_returns_user_id_for_valid_token(): void
    {
        $con = $this->stubConWithResult($this->stubResult(['user_id' => 42]));
        $this->assertSame(42, invite_verify_token($con, 'validtoken'));
    }

    public function test_verify_token_returns_null_for_expired_token(): void
    {
        // DB returns no row because expires_at > NOW() filters it out.
        $con = $this->stubConWithResult($this->stubResult(null));
        $this->assertNull(invite_verify_token($con, 'expiredtoken'));
    }

    public function test_verify_token_returns_null_for_unknown_token(): void
    {
        $con = $this->stubConWithResult($this->stubResult(null));
        $this->assertNull(invite_verify_token($con, 'unknowntoken'));
    }

    // ── invite_complete ───────────────────────────────────────────────────────

    public function test_complete_prepares_update_then_delete(): void
    {
        $sqls = [];
        $stmt = $this->createStub(\mysqli_stmt::class);
        $stmt->method('bind_param')->willReturn(true);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('close')->willReturn(true);

        $con = $this->createStub(\mysqli::class);
        $con->method('prepare')
            ->willReturnCallback(function (string $s) use (&$sqls, $stmt) {
                $sqls[] = $s;
                return $stmt;
            });

        invite_complete($con, 5, 'newpassword123');

        $this->assertCount(2, $sqls);
        $this->assertMatchesRegularExpression('/UPDATE/i', $sqls[0]);
        $this->assertMatchesRegularExpression('/DELETE/i', $sqls[1]);
    }
}
