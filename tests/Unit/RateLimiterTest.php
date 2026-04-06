<?php

namespace ErikR\Auth\Tests\Unit;

use PHPUnit\Framework\TestCase;

class RateLimiterTest extends TestCase
{
    private string $testFile;
    private string $testIp = '10.0.0.1';
    private string $testKey = 'test:10.0.0.1';

    protected function setUp(): void
    {
        $this->testFile = sys_get_temp_dir() . '/ratelimit_test_' . uniqid() . '.json';
        file_put_contents($this->testFile, '{}');
    }

    protected function tearDown(): void
    {
        if (file_exists($this->testFile)) {
            unlink($this->testFile);
        }
    }

    private function withFile(): void
    {
        // Swap the RATE_LIMIT_FILE constant's effective path for testing.
        // Since constants can't be redefined, tests use a workaround:
        // they write directly to the file that RATE_LIMIT_FILE points to.
        // For isolation, each test copies the temp file to the RATE_LIMIT_FILE location.
        file_put_contents(RATE_LIMIT_FILE, file_get_contents($this->testFile));
    }

    private function readFile(): void
    {
        file_put_contents($this->testFile, file_get_contents(RATE_LIMIT_FILE));
    }

    // ── auth_is_rate_limited / auth_record_failure / auth_clear_failures ──────

    public function test_ip_is_not_limited_initially(): void
    {
        $this->withFile();
        $this->assertFalse(auth_is_rate_limited($this->testIp));
    }

    public function test_ip_is_limited_after_max_failures(): void
    {
        $this->withFile();
        for ($i = 0; $i < RATE_LIMIT_MAX; $i++) {
            auth_record_failure($this->testIp);
        }
        $this->assertTrue(auth_is_rate_limited($this->testIp));
    }

    public function test_ip_is_not_limited_below_max(): void
    {
        $this->withFile();
        for ($i = 0; $i < RATE_LIMIT_MAX - 1; $i++) {
            auth_record_failure($this->testIp);
        }
        $this->assertFalse(auth_is_rate_limited($this->testIp));
    }

    public function test_clear_failures_removes_ip(): void
    {
        $this->withFile();
        for ($i = 0; $i < RATE_LIMIT_MAX; $i++) {
            auth_record_failure($this->testIp);
        }
        auth_clear_failures($this->testIp);
        $this->assertFalse(auth_is_rate_limited($this->testIp));
    }

    // ── General-purpose rate_limit_check / rate_limit_record / rate_limit_clear

    public function test_key_not_limited_initially(): void
    {
        $this->withFile();
        $this->assertFalse(rate_limit_check($this->testKey, 3, 900));
    }

    public function test_key_limited_after_max_records(): void
    {
        $this->withFile();
        for ($i = 0; $i < 3; $i++) {
            rate_limit_record($this->testKey, 900);
        }
        $this->assertTrue(rate_limit_check($this->testKey, 3, 900));
    }

    public function test_key_cleared(): void
    {
        $this->withFile();
        for ($i = 0; $i < 3; $i++) {
            rate_limit_record($this->testKey, 900);
        }
        rate_limit_clear($this->testKey);
        $this->assertFalse(rate_limit_check($this->testKey, 3, 900));
    }
}
