<?php

namespace ErikR\Auth\Tests\Unit;

use PHPUnit\Framework\TestCase;

class ImpersonateTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function stubCon(?array $targetRow = null): \mysqli
    {
        $result = $this->createStub(\mysqli_result::class);
        $result->method('fetch_assoc')->willReturn($targetRow);

        $stmt = $this->createStub(\mysqli_stmt::class);
        $stmt->method('bind_param')->willReturn(true);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('get_result')->willReturn($result);
        $stmt->method('close')->willReturn(true);

        $con = $this->createStub(\mysqli::class);
        $con->method('prepare')->willReturn($stmt);
        return $con;
    }

    private function userRow(array $override = []): array
    {
        return array_merge([
            'id'         => 2,
            'username'   => 'bob',
            'email'      => 'bob@example.com',
            'img'        => '',
            'img_type'   => '',
            'disabled'   => '0',
            'rights'     => 'User',
            'theme'      => 'auto',
        ], $override);
    }

    private function setAdminSession(): void
    {
        $_SESSION['id']         = 1;
        $_SESSION['username']   = 'alice';
        $_SESSION['email']      = 'alice@example.com';
        $_SESSION['img']        = '';
        $_SESSION['img_type']   = '';
        $_SESSION['has_avatar'] = false;
        $_SESSION['disabled']   = 0;
        $_SESSION['rights']     = 'Admin';
        $_SESSION['theme']      = 'auto';
        $_SESSION['sId']        = 'abc123admin';
    }

    // ── admin_is_impersonating ────────────────────────────────────────────

    public function test_is_impersonating_returns_false_by_default(): void
    {
        $this->assertFalse(admin_is_impersonating());
    }

    public function test_is_impersonating_returns_true_when_stash_present(): void
    {
        $_SESSION['impersonator'] = ['id' => 1, 'username' => 'alice'];
        $this->assertTrue(admin_is_impersonating());
    }

    // ── admin_impersonator_info ───────────────────────────────────────────

    public function test_impersonator_info_null_when_not_impersonating(): void
    {
        $this->assertNull(admin_impersonator_info());
    }

    public function test_impersonator_info_returns_id_and_username(): void
    {
        $_SESSION['impersonator'] = ['id' => 5, 'username' => 'erik',
            'email' => '', 'img' => '', 'img_type' => '',
            'has_avatar' => false, 'disabled' => 0, 'rights' => 'Admin', 'theme' => 'auto'];
        $info = admin_impersonator_info();
        $this->assertSame(5, $info['id']);
        $this->assertSame('erik', $info['username']);
    }

    // ── admin_impersonate_begin — refusals ────────────────────────────────

    public function test_begin_blocks_self_impersonation(): void
    {
        $_SESSION['id'] = 42;
        $ok = admin_impersonate_begin($this->stubCon(), 42);
        $this->assertFalse($ok);
    }

    public function test_begin_blocks_nested_impersonation(): void
    {
        $_SESSION['id']           = 1;
        $_SESSION['impersonator'] = ['id' => 99, 'username' => 'original',
            'email' => '', 'img' => '', 'img_type' => '',
            'has_avatar' => false, 'disabled' => 0, 'rights' => 'Admin', 'theme' => 'auto'];
        $ok = admin_impersonate_begin($this->stubCon($this->userRow()), 2);
        $this->assertFalse($ok);
    }

    public function test_begin_blocks_missing_target(): void
    {
        $_SESSION['id'] = 1;
        $ok = admin_impersonate_begin($this->stubCon(null), 99);
        $this->assertFalse($ok);
    }

    public function test_begin_blocks_admin_target(): void
    {
        $_SESSION['id'] = 1;
        $ok = admin_impersonate_begin($this->stubCon($this->userRow(['rights' => 'Admin'])), 2);
        $this->assertFalse($ok);
    }

    public function test_begin_blocks_disabled_target(): void
    {
        $_SESSION['id'] = 1;
        $ok = admin_impersonate_begin($this->stubCon($this->userRow(['disabled' => '1'])), 2);
        $this->assertFalse($ok);
    }

    // ── admin_impersonate_begin — success path ────────────────────────────

    public function test_begin_stashes_admin_and_sets_target_fields(): void
    {
        $this->setAdminSession();

        $ok = admin_impersonate_begin($this->stubCon($this->userRow()), 2);

        $this->assertTrue($ok);
        // Target fields now live in session
        $this->assertSame(2, $_SESSION['id']);
        $this->assertSame('bob', $_SESSION['username']);
        $this->assertSame('User', $_SESSION['rights']);
        // sId cleared for impersonated identity
        $this->assertSame('', $_SESSION['sId']);
        // Admin stashed — including sId
        $this->assertSame(1, $_SESSION['impersonator']['id']);
        $this->assertSame('alice', $_SESSION['impersonator']['username']);
        $this->assertSame('Admin', $_SESSION['impersonator']['rights']);
        $this->assertSame('abc123admin', $_SESSION['impersonator']['sId']);
    }

    public function test_begin_marks_is_impersonating_true(): void
    {
        $this->setAdminSession();
        admin_impersonate_begin($this->stubCon($this->userRow()), 2);
        $this->assertTrue(admin_is_impersonating());
    }

    // ── admin_impersonate_end ─────────────────────────────────────────────

    public function test_end_returns_false_when_not_impersonating(): void
    {
        $ok = admin_impersonate_end($this->stubCon());
        $this->assertFalse($ok);
    }

    public function test_end_restores_admin_session_and_clears_stash(): void
    {
        $_SESSION['id']           = 2;
        $_SESSION['username']     = 'bob';
        $_SESSION['email']        = 'bob@example.com';
        $_SESSION['rights']       = 'User';
        $_SESSION['img']          = '';
        $_SESSION['img_type']     = '';
        $_SESSION['has_avatar']   = false;
        $_SESSION['disabled']     = 0;
        $_SESSION['theme']        = 'auto';
        $_SESSION['sId']          = '';
        $_SESSION['impersonator'] = [
            'id' => 1, 'username' => 'alice', 'email' => 'alice@example.com',
            'img' => '', 'img_type' => '', 'has_avatar' => false,
            'disabled' => 0, 'rights' => 'Admin', 'theme' => 'light',
            'sId' => 'abc123admin',
        ];

        $ok = admin_impersonate_end($this->stubCon());

        $this->assertTrue($ok);
        $this->assertSame(1, $_SESSION['id']);
        $this->assertSame('alice', $_SESSION['username']);
        $this->assertSame('Admin', $_SESSION['rights']);
        $this->assertSame('light', $_SESSION['theme']);
        $this->assertSame('abc123admin', $_SESSION['sId']);
        $this->assertArrayNotHasKey('impersonator', $_SESSION);
    }

    public function test_end_clears_is_impersonating(): void
    {
        $this->setAdminSession();
        admin_impersonate_begin($this->stubCon($this->userRow()), 2);
        admin_impersonate_end($this->stubCon());
        $this->assertFalse(admin_is_impersonating());
    }
}
