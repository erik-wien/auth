<?php

namespace ErikR\Auth\Tests\Unit;

use PHPUnit\Framework\TestCase;

class RememberRevokeAllTest extends TestCase
{
    protected function setUp(): void
    {
        unset($_SESSION['id']);
    }

    public function test_returns_false_without_session(): void
    {
        $con = $this->createMock(\mysqli::class);
        $con->expects($this->never())->method('prepare');

        $this->assertFalse(auth_remember_revoke_all($con));
    }

    public function test_returns_false_for_zero_session_id(): void
    {
        $_SESSION['id'] = 0;
        $con = $this->createMock(\mysqli::class);
        $con->expects($this->never())->method('prepare');

        $this->assertFalse(auth_remember_revoke_all($con));
    }

    public function test_returns_false_for_negative_session_id(): void
    {
        $_SESSION['id'] = -1;
        $con = $this->createMock(\mysqli::class);
        $con->expects($this->never())->method('prepare');

        $this->assertFalse(auth_remember_revoke_all($con));
    }
}
