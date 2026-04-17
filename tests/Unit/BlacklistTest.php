<?php

namespace ErikR\Auth\Tests\Unit;

use PHPUnit\Framework\TestCase;

class BlacklistTest extends TestCase
{
    private string $testIp = '10.0.0.99';

    protected function setUp(): void
    {
        file_put_contents(RATE_LIMIT_FILE, '{}');
    }

    protected function tearDown(): void
    {
        file_put_contents(RATE_LIMIT_FILE, '{}');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Build a stub mysqli + stmt where the blacklist SELECT returns $found.
     * Uses createStub() — no call expectations, only configured return values.
     */
    private function mockCon(bool $found): \mysqli
    {
        $row    = $found ? [1] : null;
        $result = $this->createStub(\mysqli_result::class);
        $result->method('fetch_row')->willReturn($row);

        $stmt = $this->createStub(\mysqli_stmt::class);
        $stmt->method('bind_param')->willReturn(true);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('get_result')->willReturn($result);
        $stmt->method('close')->willReturn(true);

        $con = $this->createStub(\mysqli::class);
        $con->method('prepare')->willReturn($stmt);

        return $con;
    }

    /**
     * Build a mock mysqli that expects prepare() to be called exactly $times times.
     * Uses createMock() on $con for the expectation; stubs for stmt/result.
     */
    private function mockConExpectingPrepare(int $times): \mysqli
    {
        $result = $this->createStub(\mysqli_result::class);
        $result->method('fetch_row')->willReturn(null);

        $stmt = $this->createStub(\mysqli_stmt::class);
        $stmt->method('bind_param')->willReturn(true);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('get_result')->willReturn($result);
        $stmt->method('close')->willReturn(true);

        $con = $this->createMock(\mysqli::class);
        $con->expects($this->exactly($times))->method('prepare')->willReturn($stmt);

        return $con;
    }

    // ── auth_is_blacklisted ───────────────────────────────────────────────────

    public function test_ip_not_blacklisted_when_db_returns_no_row(): void
    {
        $this->assertFalse(auth_is_blacklisted($this->mockCon(false), $this->testIp));
    }

    public function test_ip_is_blacklisted_when_db_returns_row(): void
    {
        $this->assertTrue(auth_is_blacklisted($this->mockCon(true), $this->testIp));
    }

    public function test_prepare_failure_treated_as_not_blacklisted(): void
    {
        $con = $this->createStub(\mysqli::class);
        $con->method('prepare')->willReturn(false);
        $this->assertFalse(auth_is_blacklisted($con, $this->testIp));
    }

    // ── auth_record_rl_strike ─────────────────────────────────────────────────

    public function test_first_strike_returns_one(): void
    {
        $this->assertSame(1, auth_record_rl_strike($this->testIp));
    }

    public function test_strikes_accumulate(): void
    {
        auth_record_rl_strike($this->testIp);
        auth_record_rl_strike($this->testIp);
        $this->assertSame(3, auth_record_rl_strike($this->testIp));
    }

    public function test_strikes_are_independent_per_ip(): void
    {
        auth_record_rl_strike($this->testIp);
        auth_record_rl_strike($this->testIp);

        $this->assertSame(1, auth_record_rl_strike('192.168.0.1'));
    }

    public function test_strikes_cleared_on_successful_login(): void
    {
        auth_record_rl_strike($this->testIp);
        auth_record_rl_strike($this->testIp);
        auth_clear_rl_strikes($this->testIp);

        $this->assertSame(1, auth_record_rl_strike($this->testIp));
    }

    public function test_clear_strikes_does_not_affect_failure_counter(): void
    {
        for ($i = 0; $i < RATE_LIMIT_MAX; $i++) {
            auth_record_failure($this->testIp);
        }
        auth_clear_rl_strikes($this->testIp);

        $this->assertTrue(auth_is_rate_limited($this->testIp));
    }

    // ── auto-blacklist threshold ──────────────────────────────────────────────

    public function test_auto_blacklist_fires_at_threshold(): void
    {
        // Hit the threshold exactly — expect prepare() called for auth_auto_blacklist
        // (blacklist INSERT) + once per appendLog call = 2 prepare calls.
        // We only care that blacklist+log are attempted, so just count prepares.
        $con = $this->mockConExpectingPrepare(2);

        for ($i = 1; $i < BLACKLIST_AUTO_STRIKES; $i++) {
            auth_record_rl_strike($this->testIp);
        }
        // This call reaches the threshold.
        $strikes = auth_record_rl_strike($this->testIp);
        $this->assertSame(BLACKLIST_AUTO_STRIKES, $strikes);

        if ($strikes >= BLACKLIST_AUTO_STRIKES) {
            auth_auto_blacklist($con, $this->testIp);
        }
    }

    public function test_auto_blacklist_not_fired_below_threshold(): void
    {
        $con = $this->createMock(\mysqli::class);  // createMock: we assert never()
        $con->expects($this->never())->method('prepare');

        $strikes = auth_record_rl_strike($this->testIp); // 1 strike, threshold is 2
        if ($strikes >= BLACKLIST_AUTO_STRIKES) {
            auth_auto_blacklist($con, $this->testIp);
        }
    }

    // ── auth_blacklist_ip / auth_unblacklist_ip (smoke tests) ────────────────

    public function test_blacklist_ip_calls_prepare(): void
    {
        // prepare called once for INSERT, once for appendLog INSERT
        $con = $this->mockConExpectingPrepare(2);
        auth_blacklist_ip($con, $this->testIp, 'unit test');
    }

    public function test_unblacklist_ip_calls_prepare(): void
    {
        // prepare called once for DELETE, once for appendLog INSERT
        $con = $this->mockConExpectingPrepare(2);
        auth_unblacklist_ip($con, $this->testIp);
    }
}
