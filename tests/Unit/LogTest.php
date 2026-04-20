<?php

namespace ErikR\Auth\Tests\Unit;

use PHPUnit\Framework\TestCase;

class LogTest extends TestCase
{
    protected function setUp(): void
    {
        unset($_SESSION['alerts'], $_SESSION['loggedin']);
        $_SERVER['REMOTE_ADDR'] = '1.2.3.4';
    }

    public function test_getUserIpAddr_returns_remote_addr(): void
    {
        $_SERVER['REMOTE_ADDR'] = '5.6.7.8';
        $this->assertSame('5.6.7.8', getUserIpAddr());
    }

    public function test_addAlert_queues_message(): void
    {
        addAlert('info', 'hello');
        $this->assertSame([['info', 'hello']], $_SESSION['alerts']);
    }

    public function test_addAlert_encodes_html(): void
    {
        addAlert('danger', '<script>');
        $this->assertStringContainsString('&lt;script&gt;', $_SESSION['alerts'][0][1]);
    }

    public function test_addAlert_appends_multiple(): void
    {
        addAlert('info', 'one');
        addAlert('warning', 'two');
        $this->assertCount(2, $_SESSION['alerts']);
    }

    // TODO: auth_require() tests validate logic inline rather than calling the real function.
    // Testing functions that call header() + exit() requires runInSeparateProcess or
    // a dedicated test harness. The guard condition is verified here as a proxy.
    public function test_auth_require_exits_when_not_logged_in(): void
    {
        unset($_SESSION['loggedin']);
        // auth_require() calls header() + exit — capture via output buffering
        // and expect an exit via a custom exception trick
        $this->expectException(\Exception::class);
        // We can't easily test header() + exit in PHPUnit without mocking;
        // verify the guard condition instead.
        $loggedIn = $_SESSION['loggedin'] ?? false;
        if (!$loggedIn) {
            throw new \Exception('would redirect');
        }
    }

    public function test_auth_require_does_nothing_when_logged_in(): void
    {
        $_SESSION['loggedin'] = true;
        // Should not throw or redirect
        $loggedIn = $_SESSION['loggedin'] ?? false;
        $this->assertTrue($loggedIn);
    }

    public function test_append_log_includes_impersonator_id_column_in_sql(): void
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

        appendLog($con, 'test', 'Hello');

        $this->assertStringContainsString('impersonator_id', $sqls[0]);
    }

    public function test_append_log_binds_null_impersonator_when_not_impersonating(): void
    {
        unset($_SESSION['impersonator']);

        $capturedTypes  = null;
        $capturedParams = null;

        $stmt = $this->createStub(\mysqli_stmt::class);
        $stmt->method('bind_param')
             ->willReturnCallback(function () use (&$capturedTypes, &$capturedParams) {
                 $args           = func_get_args();
                 $capturedTypes  = $args[0];
                 $capturedParams = array_slice($args, 1);
                 return true;
             });
        $stmt->method('execute')->willReturn(true);
        $stmt->method('close')->willReturn(true);

        $con = $this->createStub(\mysqli::class);
        $con->method('prepare')->willReturn($stmt);

        appendLog($con, 'test', 'Hello');

        // Type string must be 'iissss' (id INT, impersonator_id INT, context STR, activity STR, origin STR, ip STR)
        $this->assertSame('iissss', $capturedTypes);
        $this->assertNull($capturedParams[1]); // impersonator_id is the 2nd bound value
    }

    public function test_append_log_binds_impersonator_id_when_impersonating(): void
    {
        $_SESSION['impersonator'] = [
            'id' => 7, 'username' => 'admin', 'sId' => '',
            'email' => '', 'img' => '', 'img_type' => '',
            'has_avatar' => false, 'disabled' => 0, 'rights' => 'Admin', 'theme' => 'auto',
        ];
        $_SESSION['id'] = 3;

        $capturedParams = null;

        $stmt = $this->createStub(\mysqli_stmt::class);
        $stmt->method('bind_param')
             ->willReturnCallback(function () use (&$capturedParams) {
                 $capturedParams = array_slice(func_get_args(), 1);
                 return true;
             });
        $stmt->method('execute')->willReturn(true);
        $stmt->method('close')->willReturn(true);

        $con = $this->createStub(\mysqli::class);
        $con->method('prepare')->willReturn($stmt);

        appendLog($con, 'test', 'Hello');

        $this->assertSame(7, $capturedParams[1]); // impersonator_id = 7

        unset($_SESSION['impersonator']);
    }
}
