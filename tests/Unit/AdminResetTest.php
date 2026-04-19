<?php

namespace ErikR\Auth\Tests\Unit;

use PHPUnit\Framework\TestCase;

class AdminResetTest extends TestCase
{
    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Build a stub con that returns $userRow from auth_accounts lookups,
     * $failedIps from auth_log DISTINCT query, and routes blacklist SELECT
     * to a stub that returns [1] if IP is in $autoIps.
     *
     * @param array<string,mixed>|null $userRow
     * @param list<string>             $failedIps  IPs returned by auth_log DISTINCT query
     * @param list<string>             $autoIps    IPs returned by auth_blacklist auto=1 check
     */
    private function resetCon(
        ?array $userRow,
        array  $failedIps = [],
        array  $autoIps   = []
    ): \mysqli {
        // auth_accounts lookup
        $userResult = $this->createStub(\mysqli_result::class);
        $userResult->method('fetch_assoc')->willReturn($userRow);
        $userStmt = $this->createStub(\mysqli_stmt::class);
        $userStmt->method('bind_param')->willReturn(true);
        $userStmt->method('execute')->willReturn(true);
        $userStmt->method('get_result')->willReturn($userResult);
        $userStmt->method('close')->willReturn(true);

        // auth_log DISTINCT ip query → return $failedIps row-by-row
        $ipRows    = array_map(static fn(string $ip): array => ['ip' => $ip], $failedIps);
        $ipRows[]  = null;
        $logResult = $this->createStub(\mysqli_result::class);
        $logResult->method('fetch_assoc')->willReturnOnConsecutiveCalls(...$ipRows);
        $logStmt = $this->createStub(\mysqli_stmt::class);
        $logStmt->method('bind_param')->willReturn(true);
        $logStmt->method('execute')->willReturn(true);
        $logStmt->method('get_result')->willReturn($logResult);
        $logStmt->method('close')->willReturn(true);

        // auth_blacklist auto=1 check → returns [1] if ip is in $autoIps
        $blStmt = $this->createStub(\mysqli_stmt::class);
        $blStmt->method('bind_param')->willReturn(true);
        $blStmt->method('execute')->willReturn(true);
        $blStmt->method('close')->willReturn(true);

        // Generic stmt for UPDATE/DELETE/INSERT/appendLog
        $genericResult = $this->createStub(\mysqli_result::class);
        $genericResult->method('fetch_assoc')->willReturn(null);
        $genericResult->method('fetch_row')->willReturn(null);
        $genericStmt = $this->createStub(\mysqli_stmt::class);
        $genericStmt->method('bind_param')->willReturn(true);
        $genericStmt->method('execute')->willReturn(true);
        $genericStmt->method('get_result')->willReturn($genericResult);
        $genericStmt->method('close')->willReturn(true);

        $con = $this->createStub(\mysqli::class);
        $con->method('prepare')->willReturnCallback(
            function (string $sql) use ($userStmt, $logStmt, $genericStmt, $blStmt, $autoIps): \mysqli_stmt {
                if (stripos($sql, 'auth_accounts') !== false)        return $userStmt;
                if (stripos($sql, 'DISTINCT') !== false)             return $logStmt;
                if (stripos($sql, 'auth_blacklist') !== false
                    && stripos($sql, 'SELECT') !== false)            return $blStmt;
                return $genericStmt;
            }
        );
        return $con;
    }

    // ── admin_reset_password — return type ────────────────────────────────────

    public function test_reset_returns_array_ok_false_for_unknown_user(): void
    {
        $result = admin_reset_password($this->resetCon(null), 999, 'http://localhost/app');
        $this->assertIsArray($result);
        $this->assertFalse($result['ok']);
        $this->assertSame([], $result['unblocked_ips']);
    }

    public function test_reset_clears_invalid_logins_sql(): void
    {
        $sqls = [];
        $con  = $this->resetCon(['email' => 'alice@example.com', 'username' => 'alice']);

        // Replace con->prepare with one that captures SQL
        $captureCon = $this->createStub(\mysqli::class);
        $stmt = $this->createStub(\mysqli_stmt::class);
        $stmt->method('bind_param')->willReturn(true);
        $stmt->method('execute')->willReturn(true);
        $nullResult = $this->createStub(\mysqli_result::class);
        $nullResult->method('fetch_assoc')->willReturn(null);
        $stmt->method('get_result')->willReturn($nullResult);
        $stmt->method('close')->willReturn(true);

        $aliceResult = $this->createStub(\mysqli_result::class);
        $aliceResult->method('fetch_assoc')->willReturn(
            ['email' => 'alice@example.com', 'username' => 'alice']
        );
        $aliceStmt = $this->createStub(\mysqli_stmt::class);
        $aliceStmt->method('bind_param')->willReturn(true);
        $aliceStmt->method('execute')->willReturn(true);
        $aliceStmt->method('get_result')->willReturn($aliceResult);
        $aliceStmt->method('close')->willReturn(true);

        $captureCon->method('prepare')->willReturnCallback(
            function (string $s) use (&$sqls, $stmt, $aliceStmt): \mysqli_stmt {
                $sqls[] = $s;
                if (stripos($s, 'auth_accounts') !== false && stripos($s, 'SELECT') !== false) {
                    return $aliceStmt;
                }
                return $stmt;
            }
        );

        admin_reset_password($captureCon, 7, 'http://localhost/app');
        $allSql = implode(' ', $sqls);
        $this->assertMatchesRegularExpression('/invalidLogins\s*=\s*0/i', $allSql);
    }

    // ── admin_user_reset_preview ───────────────────────────────────────────────

    public function test_preview_returns_false_for_unknown_user(): void
    {
        $result = admin_user_reset_preview($this->resetCon(null), 999);
        $this->assertFalse($result['ok']);
    }

    public function test_preview_returns_user_info(): void
    {
        $con    = $this->resetCon(['username' => 'alice', 'email' => 'alice@example.com']);
        $result = admin_user_reset_preview($con, 1);
        $this->assertTrue($result['ok']);
        $this->assertSame('alice', $result['username']);
        $this->assertSame('alice@example.com', $result['email']);
        $this->assertIsArray($result['ips']);
    }
}
