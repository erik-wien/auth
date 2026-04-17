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

    public function test_substitutes_all_placeholders(): void
    {
        $out = \Erikr\Auth\Mail\substitute_placeholders(
            'Hello {{name}}, welcome to {{app}}',
            ['name' => 'Alice', 'app' => 'Energie']
        );
        $this->assertSame('Hello Alice, welcome to Energie', $out);
    }

    public function test_substitutes_throws_on_missing_placeholder(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('foo');
        \Erikr\Auth\Mail\substitute_placeholders('Hello {{foo}}', ['bar' => 'baz']);
    }

    public function test_substitutes_leaves_unmatched_braces_alone(): void
    {
        $out = \Erikr\Auth\Mail\substitute_placeholders('{ not } a {{var}}', ['var' => 'X']);
        $this->assertSame('{ not } a X', $out);
    }

    public function test_markdown_to_html_paragraphs(): void
    {
        $out = \Erikr\Auth\Mail\markdown_to_html("One.\n\nTwo.\n\nThree.");
        $this->assertSame("<p>One.</p>\n<p>Two.</p>\n<p>Three.</p>", $out);
    }

    public function test_markdown_to_html_renders_inline_link(): void
    {
        $out = \Erikr\Auth\Mail\markdown_to_html('Click [here](https://example.com/x).');
        $this->assertSame('<p>Click <a href="https://example.com/x">here</a>.</p>', $out);
    }

    public function test_markdown_to_html_escapes_user_data(): void
    {
        $out = \Erikr\Auth\Mail\markdown_to_html('Hello <script>alert(1)</script>.');
        $this->assertSame('<p>Hello &lt;script&gt;alert(1)&lt;/script&gt;.</p>', $out);
    }

    public function test_markdown_to_html_escapes_ampersand_in_href(): void
    {
        $out = \Erikr\Auth\Mail\markdown_to_html('[link](https://example.com/?a=1&b=2)');
        $this->assertSame('<p><a href="https://example.com/?a=1&amp;b=2">link</a></p>', $out);
    }

    public function test_markdown_to_text_keeps_paragraphs(): void
    {
        $out = \Erikr\Auth\Mail\markdown_to_text("One.\n\nTwo.\n\nThree.");
        $this->assertSame("One.\n\nTwo.\n\nThree.", $out);
    }

    public function test_markdown_to_text_renders_link_with_distinct_text(): void
    {
        $out = \Erikr\Auth\Mail\markdown_to_text('Click [here](https://example.com/x).');
        $this->assertSame('Click here: https://example.com/x.', $out);
    }

    public function test_markdown_to_text_collapses_link_with_same_text_and_url(): void
    {
        $out = \Erikr\Auth\Mail\markdown_to_text('[https://example.com](https://example.com)');
        $this->assertSame('https://example.com', $out);
    }

    public function test_markdown_to_text_collapses_mailto_equal_to_visible(): void
    {
        $out = \Erikr\Auth\Mail\markdown_to_text('Schreiben an [foo@example.com](mailto:foo@example.com).');
        $this->assertSame('Schreiben an foo@example.com.', $out);
    }

    public function test_markdown_to_text_keeps_mailto_when_visible_differs(): void
    {
        $out = \Erikr\Auth\Mail\markdown_to_text('Schreiben an [unser Support](mailto:foo@example.com).');
        $this->assertSame('Schreiben an unser Support: mailto:foo@example.com.', $out);
    }

    public function test_render_template_produces_subject_html_and_text(): void
    {
        $out = \Erikr\Auth\Mail\render_template(
            'fixture_simple',
            ['who' => 'Alice'],
            __DIR__ . '/../fixtures/email'
        );
        $this->assertSame('Test subject for Alice', $out['subject']);
        $this->assertStringContainsString('<p>Hello Alice, this is a test.</p>', $out['html']);
        $this->assertStringContainsString('<p>Second paragraph.</p>', $out['html']);
        $this->assertStringContainsString("Hello Alice, this is a test.\n\nSecond paragraph.", $out['text']);
    }

    public function test_render_template_substitutes_inside_link(): void
    {
        $out = \Erikr\Auth\Mail\render_template(
            'fixture_with_link',
            ['app_name' => 'Energie', 'link' => 'https://example.com/x'],
            __DIR__ . '/../fixtures/email'
        );
        $this->assertSame('Click here Energie', $out['subject']);
        $this->assertStringContainsString('<a href="https://example.com/x">Click me</a>', $out['html']);
        $this->assertSame('Click me: https://example.com/x', $out['text']);
    }

    public function test_render_template_throws_on_unknown_template(): void
    {
        $this->expectException(\Erikr\Auth\Mail\TemplateException::class);
        \Erikr\Auth\Mail\render_template(
            'this_template_does_not_exist',
            [],
            __DIR__ . '/../fixtures/email'
        );
    }

    public function test_render_template_throws_on_missing_placeholder(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        \Erikr\Auth\Mail\render_template(
            'fixture_simple',
            [],
            __DIR__ . '/../fixtures/email'
        );
    }

}
