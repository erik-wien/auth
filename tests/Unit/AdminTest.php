<?php

namespace ErikR\Auth\Tests\Unit;

use PHPUnit\Framework\TestCase;

class AdminTest extends TestCase
{
    // ── Helpers ───────────────────────────────────────────────────────────────

    /** Build a generic stub con where all prepare() calls return $stmt. */
    private function stubCon(\mysqli_stmt $stmt = null): \mysqli
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

    /** Build a stub con that records all SQL strings passed to prepare(). */
    private function captureCon(array &$sqls, \mysqli_stmt $stmt = null): \mysqli
    {
        if ($stmt === null) {
            $stmt = $this->createStub(\mysqli_stmt::class);
            $stmt->method('bind_param')->willReturn(true);
            $stmt->method('execute')->willReturn(true);
            $stmt->method('close')->willReturn(true);
        }
        $con = $this->createStub(\mysqli::class);
        $con->method('prepare')
            ->willReturnCallback(function (string $s) use (&$sqls, $stmt) {
                $sqls[] = $s;
                return $stmt;
            });
        return $con;
    }

    // ── admin_create_user ─────────────────────────────────────────────────────

    public function test_create_user_inserts_with_disabled_flag(): void
    {
        $sqls = [];
        $con  = $this->captureCon($sqls);

        // invite_send_email will try send_mail → connection refused → caught internally.
        admin_create_user($con, 'alice', 'alice@example.com', 'User', 'http://localhost/app');

        $this->assertMatchesRegularExpression('/INSERT INTO.*auth_accounts/i', $sqls[0]);
        $this->assertStringContainsString('disabled', $sqls[0]);
    }

    public function test_create_user_throws_on_duplicate_username(): void
    {
        $this->expectException(\mysqli_sql_exception::class);

        $stmt = $this->createStub(\mysqli_stmt::class);
        $stmt->method('bind_param')->willReturn(true);
        $stmt->method('execute')->willThrowException(
            new \mysqli_sql_exception("Duplicate entry 'alice' for key 'username'", 1062)
        );
        $stmt->method('close')->willReturn(true);

        admin_create_user($this->stubCon($stmt), 'alice', 'alice@example.com', 'User', 'http://localhost/app');
    }

    public function test_create_user_throws_on_duplicate_email(): void
    {
        $this->expectException(\mysqli_sql_exception::class);

        $stmt = $this->createStub(\mysqli_stmt::class);
        $stmt->method('bind_param')->willReturn(true);
        $stmt->method('execute')->willThrowException(
            new \mysqli_sql_exception("Duplicate entry 'alice@example.com' for key 'email'", 1062)
        );
        $stmt->method('close')->willReturn(true);

        admin_create_user($this->stubCon($stmt), 'bob', 'alice@example.com', 'User', 'http://localhost/app');
    }

    // ── admin_delete_user ─────────────────────────────────────────────────────

    public function test_delete_user_blocks_self_deletion(): void
    {
        $con = $this->createMock(\mysqli::class);
        $con->expects($this->never())->method('prepare');

        $result = admin_delete_user($con, 42, 42);
        $this->assertFalse($result);
    }

    public function test_delete_user_prepares_delete_sql(): void
    {
        $sqls = [];
        $con  = $this->captureCon($sqls);

        admin_delete_user($con, 7, 1);

        $this->assertMatchesRegularExpression('/DELETE FROM.*auth_accounts/i', $sqls[0]);
    }

    // ── admin_edit_user ───────────────────────────────────────────────────────

    public function test_edit_user_coerces_invalid_rights_to_user(): void
    {
        $capturedParams = null;
        $stmt = $this->createMock(\mysqli_stmt::class);
        $stmt->method('bind_param')
             ->willReturnCallback(function () use (&$capturedParams) {
                 $capturedParams = func_get_args();
                 return true;
             });
        $stmt->method('execute')->willReturn(true);
        $stmt->method('close')->willReturn(true);

        admin_edit_user($this->stubCon($stmt), 1, 'user@example.com', 'SuperAdmin', 0, 0);

        // bind_param signature: ('ssssi', $email, $rights, $disabled, $debug, $id)
        // Index 0: type string, Index 2: rights value
        $this->assertSame('User', $capturedParams[2]);
    }

    public function test_edit_user_prepares_update_sql(): void
    {
        $sqls = [];
        $con  = $this->captureCon($sqls);

        admin_edit_user($con, 1, 'user@example.com', 'Admin', 0, 0);

        $this->assertMatchesRegularExpression('/UPDATE.*auth_accounts/i', $sqls[0]);
    }
}
