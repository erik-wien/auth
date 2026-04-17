<?php
declare(strict_types=0);

namespace ErikR\Auth\Tests\Unit;

use PHPUnit\Framework\TestCase;

class MailTemplateTest extends TestCase
{
    public function test_parses_frontmatter_subject(): void
    {
        $raw = "---\nsubject: Hello {{name}}\n---\n\nBody here.\n";
        [$fm, $body] = \Erikr\Auth\Mail\parse_frontmatter($raw);
        $this->assertSame('Hello {{name}}', $fm['subject']);
        $this->assertSame('Body here.', trim($body));
    }

    public function test_parses_frontmatter_rejects_missing_opening(): void
    {
        $this->expectException(\Erikr\Auth\Mail\TemplateException::class);
        \Erikr\Auth\Mail\parse_frontmatter("Body without frontmatter.\n");
    }

    public function test_parses_frontmatter_rejects_missing_closing(): void
    {
        $this->expectException(\Erikr\Auth\Mail\TemplateException::class);
        \Erikr\Auth\Mail\parse_frontmatter("---\nsubject: X\nbody without close\n");
    }
}
