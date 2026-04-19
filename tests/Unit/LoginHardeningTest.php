<?php

namespace ErikR\Auth\Tests\Unit;

use PHPUnit\Framework\TestCase;

class LoginHardeningTest extends TestCase
{
    protected function setUp(): void
    {
        $_SERVER['REMOTE_ADDR'] = '10.0.0.1';
        file_put_contents(RATE_LIMIT_FILE, '{}');
    }

    protected function tearDown(): void
    {
        file_put_contents(RATE_LIMIT_FILE, '{}');
    }

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
        $this->assertSame(30, auth_compute_progressive_delay(7));
        $this->assertSame(30, auth_compute_progressive_delay(100));
    }
}
