<?php

namespace ErikR\Auth\Tests\Unit;

use PHPUnit\Framework\TestCase;

class RememberListRevokeOneTest extends TestCase
{
    protected function setUp(): void
    {
        unset($_COOKIE[AUTH_REMEMBER_COOKIE]);
    }

    // ── auth_remember_list_for_user ───────────────────────────────────────────

    public function test_list_returns_empty_for_zero_user(): void
    {
        $con = $this->createMock(\mysqli::class);
        $con->expects($this->never())->method('prepare');

        $this->assertSame([], auth_remember_list_for_user($con, 0));
    }

    public function test_list_returns_empty_for_negative_user(): void
    {
        $con = $this->createMock(\mysqli::class);
        $con->expects($this->never())->method('prepare');

        $this->assertSame([], auth_remember_list_for_user($con, -5));
    }

    public function test_list_maps_rows_and_flags_current_selector(): void
    {
        $rows = [
            [
                'selector'   => str_repeat('a', 16),
                'created_at' => '2026-04-18 10:00:00',
                'expires_at' => '2026-04-26 10:00:00',
                'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15) Chrome/120.0',
                'ip'         => '10.0.0.5',
            ],
            [
                'selector'   => str_repeat('b', 16),
                'created_at' => '2026-04-17 09:00:00',
                'expires_at' => '2026-04-25 09:00:00',
                'user_agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0) Safari/605',
                'ip'         => '192.168.1.1',
            ],
        ];

        $_COOKIE[AUTH_REMEMBER_COOKIE] = str_repeat('a', 16) . '.' . str_repeat('c', 64);

        $con = $this->con_listing($rows);
        $result = auth_remember_list_for_user($con, 42);

        $this->assertCount(2, $result);
        $this->assertTrue($result[0]['is_current']);
        $this->assertFalse($result[1]['is_current']);
        $this->assertSame('Chrome auf macOS', $result[0]['browser_os']);
        $this->assertSame('Safari auf iOS', $result[1]['browser_os']);
        $this->assertSame('10.0.0.5', $result[0]['ip']);
    }

    public function test_list_without_cookie_has_no_current_row(): void
    {
        $rows = [[
            'selector'   => str_repeat('a', 16),
            'created_at' => '2026-04-18 10:00:00',
            'expires_at' => '2026-04-26 10:00:00',
            'user_agent' => 'Mozilla/5.0',
            'ip'         => '10.0.0.5',
        ]];

        $con = $this->con_listing($rows);
        $result = auth_remember_list_for_user($con, 42);

        $this->assertFalse($result[0]['is_current']);
    }

    // ── auth_remember_revoke_one ──────────────────────────────────────────────

    public function test_revoke_one_rejects_zero_user(): void
    {
        $con = $this->createMock(\mysqli::class);
        $con->expects($this->never())->method('prepare');

        $this->assertFalse(auth_remember_revoke_one($con, 0, str_repeat('a', 16)));
    }

    public function test_revoke_one_rejects_malformed_selector(): void
    {
        $con = $this->createMock(\mysqli::class);
        $con->expects($this->never())->method('prepare');

        $this->assertFalse(auth_remember_revoke_one($con, 1, 'not-a-selector'));
        $this->assertFalse(auth_remember_revoke_one($con, 1, ''));
        $this->assertFalse(auth_remember_revoke_one($con, 1, str_repeat('z', 16))); // non-hex
    }

    public function test_revoke_one_binds_both_user_id_and_selector(): void
    {
        $capturedSql  = null;
        $capturedArgs = null;

        $stmt = $this->createStub(\mysqli_stmt::class);
        $stmt->method('bind_param')->willReturnCallback(
            function (string $types, ...$args) use (&$capturedArgs): bool {
                $capturedArgs = array_merge([$types], $args);
                return true;
            }
        );
        $stmt->method('execute')->willReturn(false); // short-circuits affected_rows read

        $con = $this->createStub(\mysqli::class);
        $con->method('prepare')->willReturnCallback(
            function (string $sql) use (&$capturedSql, $stmt): \mysqli_stmt {
                $capturedSql = $sql;
                return $stmt;
            }
        );

        auth_remember_revoke_one($con, 42, str_repeat('a', 16));

        $this->assertStringContainsString('user_id = ?', $capturedSql);
        $this->assertStringContainsString('selector = ?', $capturedSql);
        $this->assertSame('is', $capturedArgs[0]);
        $this->assertSame(42, $capturedArgs[1]);
        $this->assertSame(str_repeat('a', 16), $capturedArgs[2]);
    }

    // ── auth_remember_parse_ua ────────────────────────────────────────────────

    public function test_parse_ua_chrome_macos(): void
    {
        $ua = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
        $this->assertSame('Chrome auf macOS', auth_remember_parse_ua($ua));
    }

    public function test_parse_ua_firefox_linux(): void
    {
        $ua = 'Mozilla/5.0 (X11; Linux x86_64; rv:121.0) Gecko/20100101 Firefox/121.0';
        $this->assertSame('Firefox auf Linux', auth_remember_parse_ua($ua));
    }

    public function test_parse_ua_safari_ios(): void
    {
        $ua = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605 Version/17.0 Mobile/15E148 Safari/604.1';
        $this->assertSame('Safari auf iOS', auth_remember_parse_ua($ua));
    }

    public function test_parse_ua_edge_windows(): void
    {
        $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 Edg/120.0.0.0';
        $this->assertSame('Edge auf Windows', auth_remember_parse_ua($ua));
    }

    public function test_parse_ua_empty_returns_unknown(): void
    {
        $this->assertSame('Unbekannt', auth_remember_parse_ua(''));
    }

    public function test_parse_ua_unknown_truncates_long_raw(): void
    {
        $ua = str_repeat('x', 200);
        $parsed = auth_remember_parse_ua($ua);
        $this->assertStringEndsWith('…', $parsed);
        $this->assertLessThanOrEqual(70, mb_strlen($parsed));
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function con_listing(array $rows): \mysqli
    {
        $calls = $rows;
        $calls[] = null;
        $result = $this->createStub(\mysqli_result::class);
        $result->method('fetch_assoc')->willReturnOnConsecutiveCalls(...$calls);

        $stmt = $this->createStub(\mysqli_stmt::class);
        $stmt->method('bind_param')->willReturn(true);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('get_result')->willReturn($result);
        $stmt->method('close')->willReturn(true);

        $con = $this->createStub(\mysqli::class);
        $con->method('prepare')->willReturn($stmt);
        return $con;
    }
}
