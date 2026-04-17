<?php
declare(strict_types=0);

namespace ErikR\Auth\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ShippingTemplatesTest extends TestCase
{
    public static function templateProvider(): array
    {
        return [
            ['invite'],
            ['password_reset'],
            ['email_change_confirmation'],
        ];
    }

    #[DataProvider('templateProvider')]
    public function test_renders_without_unsubstituted_placeholders(string $template): void
    {
        $vars = [
            'username'      => 'Alice',
            'link'          => 'https://example.com/token?a=1&b=2',
            'app_name'      => 'Energie',
            'app_url'       => 'https://energie.example',
            'support_email' => 'contact@example.com',
        ];
        $out = \Erikr\Auth\Mail\render_template($template, $vars);

        $this->assertNotEmpty($out['subject']);
        $this->assertStringNotContainsString('{{', $out['subject']);
        $this->assertStringNotContainsString('{{', $out['html']);
        $this->assertStringNotContainsString('{{', $out['text']);

        $this->assertStringContainsString('example.com/token', $out['html']);
        $this->assertStringContainsString('example.com/token', $out['text']);
    }
}
