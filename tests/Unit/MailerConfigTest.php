<?php
declare(strict_types=0);

namespace ErikR\Auth\Tests\Unit;

use PHPUnit\Framework\TestCase;

class MailerConfigTest extends TestCase
{
    public function test_load_reads_smtp_section_from_ini(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'mailcfg');
        file_put_contents($tmp, "[smtp]\nhost = mail.example\nport = 587\nuser = u\npassword = p\n");
        try {
            $cfg = \Erikr\Auth\Mail\load_mail_config_from($tmp);
            $this->assertSame('mail.example', $cfg['host']);
            $this->assertSame(587, $cfg['port']);
            $this->assertSame('u', $cfg['user']);
            $this->assertSame('p', $cfg['password']);
        } finally {
            unlink($tmp);
        }
    }

    public function test_load_throws_when_file_missing(): void
    {
        $this->expectException(\Erikr\Auth\Mail\MailConfigException::class);
        \Erikr\Auth\Mail\load_mail_config_from('/nonexistent/path/to/mail.ini');
    }

    public function test_load_throws_when_smtp_key_missing(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'mailcfg');
        file_put_contents($tmp, "[smtp]\nhost = mail.example\nport = 587\n");
        try {
            $this->expectException(\Erikr\Auth\Mail\MailConfigException::class);
            \Erikr\Auth\Mail\load_mail_config_from($tmp);
        } finally {
            unlink($tmp);
        }
    }
}
