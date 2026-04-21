<?php
declare(strict_types=0);

namespace ErikR\Auth\Tests\Unit;

use PHPUnit\Framework\TestCase;

class TokenHelpersTest extends TestCase
{
    // ── helpers ───────────────────────────────────────────────────────────────

    private function stubStmt(): \mysqli_stmt
    {
        $stmt = $this->createStub(\mysqli_stmt::class);
        $stmt->method('bind_param')->willReturn(true);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('close')->willReturn(true);
        return $stmt;
    }

    /** Con that records every SQL string passed to prepare(). */
    private function recordingCon(array &$sqls): \mysqli
    {
        $stmt = $this->stubStmt();
        $con  = $this->createStub(\mysqli::class);
        $con->method('prepare')->willReturnCallback(
            function (string $sql) use (&$sqls, $stmt) {
                $sqls[] = $sql;
                return $stmt;
            }
        );
        return $con;
    }

    // ── auth_reset_token_issue ────────────────────────────────────────────────

    public function test_reset_token_issue_returns_ok_true(): void
    {
        $sqls = [];
        $result = auth_reset_token_issue($this->recordingCon($sqls), 1);
        $this->assertTrue($result['ok']);
    }

    public function test_reset_token_issue_returns_64_hex_token(): void
    {
        $sqls = [];
        $result = auth_reset_token_issue($this->recordingCon($sqls), 1);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $result['token']);
    }

    public function test_reset_token_issue_deletes_old_before_insert(): void
    {
        $sqls = [];
        auth_reset_token_issue($this->recordingCon($sqls), 1);

        $this->assertCount(2, $sqls, 'Expected DELETE then INSERT');
        $this->assertMatchesRegularExpression('/DELETE/i', $sqls[0]);
        $this->assertMatchesRegularExpression('/INSERT/i', $sqls[1]);
    }

    public function test_reset_token_issue_targets_password_resets_table(): void
    {
        $sqls = [];
        auth_reset_token_issue($this->recordingCon($sqls), 1);

        foreach ($sqls as $sql) {
            $this->assertStringContainsString('password_resets', $sql);
        }
    }

    public function test_reset_token_issue_tokens_differ_across_calls(): void
    {
        $sqls = [];
        $con  = $this->recordingCon($sqls);
        $t1   = auth_reset_token_issue($con, 1)['token'];
        $t2   = auth_reset_token_issue($con, 1)['token'];
        $this->assertNotSame($t1, $t2);
    }

    // ── auth_email_confirmation_issue ─────────────────────────────────────────

    public function test_email_confirmation_issue_returns_ok_true(): void
    {
        $sqls = [];
        $result = auth_email_confirmation_issue($this->recordingCon($sqls), 1, 'new@example.com');
        $this->assertTrue($result['ok']);
    }

    public function test_email_confirmation_issue_returns_64_hex_token(): void
    {
        $sqls = [];
        $result = auth_email_confirmation_issue($this->recordingCon($sqls), 1, 'new@example.com');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $result['token']);
    }

    public function test_email_confirmation_issue_issues_single_update(): void
    {
        $sqls = [];
        auth_email_confirmation_issue($this->recordingCon($sqls), 5, 'new@example.com');

        $this->assertCount(1, $sqls, 'Expected exactly one UPDATE');
        $this->assertMatchesRegularExpression('/UPDATE/i', $sqls[0]);
    }

    public function test_email_confirmation_issue_updates_auth_accounts(): void
    {
        $sqls = [];
        auth_email_confirmation_issue($this->recordingCon($sqls), 5, 'new@example.com');

        $this->assertStringContainsString('auth_accounts', $sqls[0]);
    }

    public function test_email_confirmation_issue_sets_pending_email_and_code(): void
    {
        $sqls = [];
        auth_email_confirmation_issue($this->recordingCon($sqls), 5, 'new@example.com');

        $sql = $sqls[0];
        $this->assertStringContainsString('pending_email', $sql);
        $this->assertStringContainsString('email_change_code', $sql);
    }

    public function test_email_confirmation_issue_tokens_differ_across_calls(): void
    {
        $sqls = [];
        $con  = $this->recordingCon($sqls);
        $t1   = auth_email_confirmation_issue($con, 1, 'a@example.com')['token'];
        $t2   = auth_email_confirmation_issue($con, 1, 'b@example.com')['token'];
        $this->assertNotSame($t1, $t2);
    }
}
