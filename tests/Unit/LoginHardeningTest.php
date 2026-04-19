<?php

namespace ErikR\Auth\Tests\Unit;

use PHPUnit\Framework\TestCase;

class LoginHardeningTest extends TestCase
{
    // ── auth_compute_progressive_delay ────────────────────────────────────────

    public function test_delay_zero_fails_is_zero(): void
    {
        $this->assertSame(0, auth_compute_progressive_delay(0));
    }

    public function test_delay_one_fail_is_one_second(): void
    {
        $this->assertSame(1, auth_compute_progressive_delay(1));
    }

    public function test_delay_two_fails_is_two_seconds(): void
    {
        $this->assertSame(2, auth_compute_progressive_delay(2));
    }

    public function test_delay_three_fails_is_four_seconds(): void
    {
        $this->assertSame(4, auth_compute_progressive_delay(3));
    }

    public function test_delay_capped_at_thirty_seconds(): void
    {
        $this->assertSame(30, auth_compute_progressive_delay(6));
        $this->assertSame(30, auth_compute_progressive_delay(7));
        $this->assertSame(30, auth_compute_progressive_delay(100));
    }

    // ── auth_compute_ip_score ─────────────────────────────────────────────────

    private function scoreConFromRows(array $activityRows): \mysqli
    {
        $calls  = array_map(static fn(string $a) => [$a], $activityRows);
        $calls[] = null;
        $result = $this->createStub(\mysqli_result::class);
        $result->method('fetch_row')->willReturnOnConsecutiveCalls(...$calls);

        $stmt = $this->createStub(\mysqli_stmt::class);
        $stmt->method('bind_param')->willReturn(true);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('get_result')->willReturn($result);
        $stmt->method('close')->willReturn(true);

        $con = $this->createStub(\mysqli::class);
        $con->method('prepare')->willReturn($stmt);
        return $con;
    }

    public function test_score_zero_for_empty_log(): void
    {
        $con = $this->scoreConFromRows([]);
        $this->assertSame(0, auth_compute_ip_score($con, '10.0.0.1')['score']);
    }

    public function test_score_existing_user_fail_adds_one(): void
    {
        $con = $this->scoreConFromRows(['Login failed for alice.']);
        $this->assertSame(1, auth_compute_ip_score($con, '10.0.0.1')['score']);
    }

    public function test_score_unknown_user_fail_adds_three(): void
    {
        $con = $this->scoreConFromRows(['Login failed for unknown user: ghost.']);
        $this->assertSame(3, auth_compute_ip_score($con, '10.0.0.1')['score']);
    }

    public function test_score_rl_event_adds_five(): void
    {
        $con = $this->scoreConFromRows(['Rate limit triggered.']);
        $this->assertSame(5, auth_compute_ip_score($con, '10.0.0.1')['score']);
    }

    public function test_score_accumulates_mixed_rows(): void
    {
        $con = $this->scoreConFromRows([
            'Login failed for alice.',
            'Login failed for unknown user: bob.',
            'Rate limit triggered.',
        ]);
        $this->assertSame(9, auth_compute_ip_score($con, '10.0.0.1')['score']);
    }

    public function test_score_usernames_collected_deduped(): void
    {
        $con = $this->scoreConFromRows([
            'Login failed for alice.',
            'Login failed for alice.',
            'Login failed for unknown user: bob.',
        ]);
        $result    = auth_compute_ip_score($con, '10.0.0.1');
        $usernames = $result['usernames'];
        sort($usernames);
        $this->assertSame(['alice', 'bob'], $usernames);
    }

    // ── lockout threshold in auth_login ───────────────────────────────────────

    private function loginCon(?array $userRow): \mysqli
    {
        $blResult = $this->createStub(\mysqli_result::class);
        $blResult->method('fetch_row')->willReturn(null);
        $blStmt = $this->createStub(\mysqli_stmt::class);
        $blStmt->method('bind_param')->willReturn(true);
        $blStmt->method('execute')->willReturn(true);
        $blStmt->method('get_result')->willReturn($blResult);
        $blStmt->method('close')->willReturn(true);

        $userResult = $this->createStub(\mysqli_result::class);
        $userResult->method('fetch_assoc')->willReturn($userRow);
        $userStmt = $this->createStub(\mysqli_stmt::class);
        $userStmt->method('bind_param')->willReturn(true);
        $userStmt->method('execute')->willReturn(true);
        $userStmt->method('get_result')->willReturn($userResult);
        $userStmt->method('close')->willReturn(true);

        $genericResult = $this->createStub(\mysqli_result::class);
        $genericResult->method('fetch_row')->willReturn(null);
        $genericResult->method('fetch_assoc')->willReturn(null);
        $genericStmt = $this->createStub(\mysqli_stmt::class);
        $genericStmt->method('bind_param')->willReturn(true);
        $genericStmt->method('execute')->willReturn(true);
        $genericStmt->method('get_result')->willReturn($genericResult);
        $genericStmt->method('close')->willReturn(true);

        $con = $this->createStub(\mysqli::class);
        $con->method('prepare')->willReturnCallback(
            function (string $sql) use ($blStmt, $userStmt, $genericStmt): \mysqli_stmt {
                if (stripos($sql, 'auth_blacklist') !== false) return $blStmt;
                if (stripos($sql, 'auth_accounts')  !== false) return $userStmt;
                return $genericStmt;
            }
        );
        return $con;
    }

    private function activeRow(int $invalidLogins): array
    {
        return [
            'id'              => 1,
            'username'        => 'alice',
            'email'           => 'alice@example.com',
            'password'        => auth_hash_password('correct'),
            'activation_code' => 'activated',
            'disabled'        => '0',
            'invalidLogins'   => $invalidLogins,
            'rights'          => 'User',
            'theme'           => 'auto',
            'totp_secret'     => null,
            'has_avatar'      => 0,
        ];
    }

    public function test_lockout_at_exactly_threshold(): void
    {
        $result = auth_login($this->loginCon($this->activeRow(10)), 'alice', 'correct');
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('gesperrt', $result['error']);
    }

    public function test_lockout_above_threshold(): void
    {
        $result = auth_login($this->loginCon($this->activeRow(11)), 'alice', 'correct');
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('gesperrt', $result['error']);
    }

    public function test_not_locked_below_threshold(): void
    {
        // 9 invalidLogins → proceeds past lockout gate, fails on wrong password
        $result = auth_login($this->loginCon($this->activeRow(9)), 'alice', 'wrong');
        $this->assertFalse($result['ok']);
        $this->assertStringNotContainsString('gesperrt', $result['error']);
    }
}
