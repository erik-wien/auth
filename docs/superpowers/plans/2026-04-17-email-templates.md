# Email Templates Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move every outbound email across the Jardyx ecosystem into Markdown templates owned by the auth library, with a single centralized sender identity and no inline HTML body-building in consumer apps.

**Architecture:** Three Markdown files under `auth/templates/email/` drive all outbound mail. A small subset-Markdown renderer (~50 LoC, no new dep) emits both HTML and plain-text variants from one source per send. Three typed helpers (`mail_send_invite`, `mail_send_password_reset`, `mail_send_email_change_confirmation`) wrap a generic `send_templated_mail()`. The current `send_mail()` is renamed to internal `\Erikr\Auth\Mail\smtp_send()`. SMTP transport is loaded from a single host-level `jardyx-mail.ini`; sender identity is hard-coded to `"Jardyx Support" <noreply@jardyx.com>`. Apps lose every `SMTP_*` constant and gain `APP_SUPPORT_EMAIL`.

**Tech Stack:** PHP 8.2+, PHPMailer (already present), PHPUnit 13, erikr/auth library, per-app PHP web UIs, `mcp/` Python deploy system.

**Spec:** `/Users/erikr/Git/auth/docs/superpowers/specs/2026-04-17-email-templates-design.md`

**Repositories touched:** `auth/`, `mcp/`, `Energie/`, `wlmonitor/`, `zeiterfassung/`, `simplechat-2.1/`, `suche/`. Work on feature branch `feature/email-templates` in each. Merge and deploy-all together at the end — library + apps must ship atomically.

---

## Task 0: Preflight — create feature branches

**Files:** none changed; branches only.

- [ ] **Step 1: Create feature branch in every affected repo**

Run these in order:

```bash
cd /Users/erikr/Git/auth && git checkout -b feature/email-templates
cd /Users/erikr/Git/mcp && git checkout -b feature/email-templates
cd /Users/erikr/Git/Energie && git checkout -b feature/email-templates
cd /Users/erikr/Git/wlmonitor && git checkout -b feature/email-templates
cd /Users/erikr/Git/zeiterfassung && git checkout -b feature/email-templates
cd /Users/erikr/Git/simplechat-2.1 && git checkout -b feature/email-templates
cd /Users/erikr/Git/suche && git checkout -b feature/email-templates
```

Expected: every command prints `Switched to a new branch 'feature/email-templates'`.

- [ ] **Step 2: Verify every repo's working tree is clean (no accidental mixing of unrelated in-progress work)**

```bash
for d in auth mcp Energie wlmonitor zeiterfassung simplechat-2.1 suche; do
  echo "=== $d ==="
  (cd /Users/erikr/Git/$d && git status --short | head -20)
done
```

Inspect the output. If any repo has modified files you don't recognise as pre-existing WIP, resolve those before proceeding (commit or stash on a separate branch). Pre-existing WIP that pre-dates this plan is fine — this plan's commits will sit on top.

---

## Task 1: Renderer — frontmatter parsing

**Files:**
- Create: `/Users/erikr/Git/auth/src/mail_template.php`
- Create: `/Users/erikr/Git/auth/tests/Unit/MailTemplateTest.php`

- [ ] **Step 1: Write failing test for frontmatter parsing**

Create `tests/Unit/MailTemplateTest.php`:

```php
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
        $this->assertSame("Body here.", trim($body));
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
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
cd /Users/erikr/Git/auth && vendor/bin/phpunit tests/Unit/MailTemplateTest.php --filter test_parses_frontmatter
```

Expected: FAIL with "Class ... not found" or similar — the file doesn't exist yet.

- [ ] **Step 3: Create `src/mail_template.php` with minimal parse_frontmatter**

```php
<?php
/**
 * src/mail_template.php — Markdown-subset email template renderer.
 *
 * Each template file under templates/email/*.md has YAML-style frontmatter
 * (subject only) and a Markdown body. render_template() produces subject + HTML + text
 * from one Markdown source. send_templated_mail() dispatches the result via smtp_send().
 */

namespace Erikr\Auth\Mail;

class TemplateException extends \RuntimeException {}

/**
 * Split raw template text into ($frontmatter, $body).
 * Frontmatter is a key: value map between two `---` lines at the top of the file.
 * Throws if the file does not start with `---` or has no closing `---`.
 *
 * @return array{0: array<string,string>, 1: string}
 */
function parse_frontmatter(string $raw): array
{
    $lines = explode("\n", $raw);
    if (count($lines) === 0 || trim($lines[0]) !== '---') {
        throw new TemplateException('Template missing opening --- frontmatter line');
    }
    $fm = [];
    $i = 1;
    $closed = false;
    while ($i < count($lines)) {
        if (trim($lines[$i]) === '---') {
            $closed = true;
            $i++;
            break;
        }
        if (preg_match('/^([a-z_]+)\s*:\s*(.*)$/', $lines[$i], $m)) {
            $fm[$m[1]] = $m[2];
        }
        $i++;
    }
    if (!$closed) {
        throw new TemplateException('Template missing closing --- frontmatter line');
    }
    $body = implode("\n", array_slice($lines, $i));
    return [$fm, $body];
}
```

- [ ] **Step 4: Add `src/mail_template.php` to composer autoload**

Edit `composer.json`, inside `autoload.files` array, add `"src/mail_template.php"` after `"src/mailer.php"`:

```json
"autoload": {
    "files": [
        "src/log.php",
        "src/csrf.php",
        "src/auth.php",
        "src/mailer.php",
        "src/mail_template.php",
        "src/invite.php",
        "src/admin.php",
        "src/totp.php",
        "src/avatar.php",
        "src/bootstrap.php"
    ]
},
```

Run: `cd /Users/erikr/Git/auth && composer dump-autoload`.

Expected: `Generated autoload files containing 1 class`.

- [ ] **Step 5: Re-run the tests to verify they pass**

```bash
cd /Users/erikr/Git/auth && vendor/bin/phpunit tests/Unit/MailTemplateTest.php
```

Expected: `OK (3 tests, ...)`.

- [ ] **Step 6: Commit**

```bash
cd /Users/erikr/Git/auth
git add src/mail_template.php tests/Unit/MailTemplateTest.php composer.json
git commit -m "feat(mail): add template frontmatter parser"
```

---

## Task 2: Renderer — placeholder substitution

**Files:**
- Modify: `/Users/erikr/Git/auth/src/mail_template.php`
- Modify: `/Users/erikr/Git/auth/tests/Unit/MailTemplateTest.php`

- [ ] **Step 1: Write failing tests for substitute_placeholders**

Append to `tests/Unit/MailTemplateTest.php` inside the class:

```php
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
        // Text like "use { foo } in JSON" must not be mistaken for a placeholder —
        // only exact {{key}} with no inner whitespace is recognised.
        $out = \Erikr\Auth\Mail\substitute_placeholders('{ not } a {{var}}', ['var' => 'X']);
        $this->assertSame('{ not } a X', $out);
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
cd /Users/erikr/Git/auth && vendor/bin/phpunit tests/Unit/MailTemplateTest.php --filter substitutes
```

Expected: FAIL — function does not exist.

- [ ] **Step 3: Implement substitute_placeholders in `src/mail_template.php`**

Append inside the `Erikr\Auth\Mail` namespace:

```php
/**
 * Replace every {{key}} token with $vars[$key]. Missing key → InvalidArgumentException.
 * Only recognises `{{identifier}}` with no inner whitespace; literal `{` / `}` in prose
 * (e.g. `{ not a var }`) is passed through unchanged.
 */
function substitute_placeholders(string $tpl, array $vars): string
{
    return preg_replace_callback(
        '/\{\{([a-z_][a-z0-9_]*)\}\}/',
        function (array $m) use ($vars) {
            if (!array_key_exists($m[1], $vars)) {
                throw new \InvalidArgumentException("Missing placeholder: {$m[1]}");
            }
            return (string) $vars[$m[1]];
        },
        $tpl
    );
}
```

- [ ] **Step 4: Re-run the tests to verify they pass**

```bash
cd /Users/erikr/Git/auth && vendor/bin/phpunit tests/Unit/MailTemplateTest.php
```

Expected: `OK (6 tests, ...)`.

- [ ] **Step 5: Commit**

```bash
cd /Users/erikr/Git/auth
git add src/mail_template.php tests/Unit/MailTemplateTest.php
git commit -m "feat(mail): add placeholder substitution with strict missing-key check"
```

---

## Task 3: Renderer — Markdown to HTML (paragraphs + inline links)

**Files:**
- Modify: `/Users/erikr/Git/auth/src/mail_template.php`
- Modify: `/Users/erikr/Git/auth/tests/Unit/MailTemplateTest.php`

- [ ] **Step 1: Write failing tests for markdown_to_html**

Append to `tests/Unit/MailTemplateTest.php` inside the class:

```php
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
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
cd /Users/erikr/Git/auth && vendor/bin/phpunit tests/Unit/MailTemplateTest.php --filter markdown_to_html
```

Expected: FAIL — function does not exist.

- [ ] **Step 3: Implement markdown_to_html in `src/mail_template.php`**

Append inside the `Erikr\Auth\Mail` namespace:

```php
/**
 * Subset-Markdown to HTML: paragraphs (blank-line separated) + inline [text](url) links.
 * Everything else is passed through as-is, HTML-escaped.
 */
function markdown_to_html(string $md): string
{
    $paragraphs = preg_split('/\n[ \t]*\n+/', trim($md));
    $htmlParas = array_map(fn(string $p) => '<p>' . _inline_md_to_html($p) . '</p>', $paragraphs);
    return implode("\n", $htmlParas);
}

/** Internal: convert [text](url) within a single paragraph, HTML-escape everything else. */
function _inline_md_to_html(string $para): string
{
    $out = '';
    $i = 0;
    $len = strlen($para);
    while ($i < $len) {
        if ($para[$i] === '[' && preg_match('/\[([^\]]+)\]\(([^)]+)\)/A', $para, $m, 0, $i)) {
            $text = htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8');
            $url  = htmlspecialchars($m[2], ENT_QUOTES, 'UTF-8');
            $out .= '<a href="' . $url . '">' . $text . '</a>';
            $i += strlen($m[0]);
            continue;
        }
        $out .= htmlspecialchars($para[$i], ENT_QUOTES, 'UTF-8');
        $i++;
    }
    return $out;
}
```

- [ ] **Step 4: Re-run the tests to verify they pass**

```bash
cd /Users/erikr/Git/auth && vendor/bin/phpunit tests/Unit/MailTemplateTest.php
```

Expected: `OK (10 tests, ...)`.

- [ ] **Step 5: Commit**

```bash
cd /Users/erikr/Git/auth
git add src/mail_template.php tests/Unit/MailTemplateTest.php
git commit -m "feat(mail): render Markdown subset to HTML (paragraphs + inline links)"
```

---

## Task 4: Renderer — Markdown to plain text

**Files:**
- Modify: `/Users/erikr/Git/auth/src/mail_template.php`
- Modify: `/Users/erikr/Git/auth/tests/Unit/MailTemplateTest.php`

- [ ] **Step 1: Write failing tests for markdown_to_text**

Append to `tests/Unit/MailTemplateTest.php` inside the class:

```php
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
        // Special rule: when URL is `mailto:X` and visible text is `X`, text variant
        // renders just `X` (not `X: mailto:X`). Keeps support-email mentions clean.
        $out = \Erikr\Auth\Mail\markdown_to_text('Schreiben an [foo@example.com](mailto:foo@example.com).');
        $this->assertSame('Schreiben an foo@example.com.', $out);
    }

    public function test_markdown_to_text_keeps_mailto_when_visible_differs(): void
    {
        $out = \Erikr\Auth\Mail\markdown_to_text('Schreiben an [unser Support](mailto:foo@example.com).');
        $this->assertSame('Schreiben an unser Support: mailto:foo@example.com.', $out);
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
cd /Users/erikr/Git/auth && vendor/bin/phpunit tests/Unit/MailTemplateTest.php --filter markdown_to_text
```

Expected: FAIL — function does not exist.

- [ ] **Step 3: Implement markdown_to_text in `src/mail_template.php`**

Append inside the `Erikr\Auth\Mail` namespace:

```php
/**
 * Subset-Markdown to plain text: paragraphs separated by blank lines, links rendered as
 * `text: url` (or just `url` when the visible text equals the URL character-for-character).
 */
function markdown_to_text(string $md): string
{
    $paragraphs = preg_split('/\n[ \t]*\n+/', trim($md));
    $textParas  = array_map('\\Erikr\\Auth\\Mail\\_inline_md_to_text', $paragraphs);
    return implode("\n\n", $textParas);
}

/** Internal: collapse [text](url) within a single paragraph to plain-text form. */
function _inline_md_to_text(string $para): string
{
    return preg_replace_callback(
        '/\[([^\]]+)\]\(([^)]+)\)/',
        function (array $m): string {
            // `[url](url)` → `url`
            if ($m[1] === $m[2]) return $m[2];
            // `[foo@x](mailto:foo@x)` → `foo@x` — avoids noisy "foo@x: mailto:foo@x" output
            if (str_starts_with($m[2], 'mailto:') && substr($m[2], 7) === $m[1]) return $m[1];
            // `[text](url)` with different text/url → `text: url`
            return $m[1] . ': ' . $m[2];
        },
        $para
    );
}
```

- [ ] **Step 4: Re-run the tests to verify they pass**

```bash
cd /Users/erikr/Git/auth && vendor/bin/phpunit tests/Unit/MailTemplateTest.php
```

Expected: `OK (14 tests, ...)`.

- [ ] **Step 5: Commit**

```bash
cd /Users/erikr/Git/auth
git add src/mail_template.php tests/Unit/MailTemplateTest.php
git commit -m "feat(mail): render Markdown subset to plain text"
```

---

## Task 5: Renderer — render_template (end-to-end)

**Files:**
- Modify: `/Users/erikr/Git/auth/src/mail_template.php`
- Create: `/Users/erikr/Git/auth/tests/fixtures/email/fixture_simple.md`
- Create: `/Users/erikr/Git/auth/tests/fixtures/email/fixture_with_link.md`
- Modify: `/Users/erikr/Git/auth/tests/Unit/MailTemplateTest.php`

- [ ] **Step 1: Create fixture templates for tests**

Create `tests/fixtures/email/fixture_simple.md`:

```markdown
---
subject: Test subject for {{who}}
---

Hello {{who}}, this is a test.

Second paragraph.
```

Create `tests/fixtures/email/fixture_with_link.md`:

```markdown
---
subject: Click here {{app_name}}
---

[Click me]({{link}})
```

- [ ] **Step 2: Write failing tests for render_template**

Append to `tests/Unit/MailTemplateTest.php` inside the class:

```php
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
            [], // missing 'who'
            __DIR__ . '/../fixtures/email'
        );
    }
```

- [ ] **Step 3: Run the tests to verify they fail**

```bash
cd /Users/erikr/Git/auth && vendor/bin/phpunit tests/Unit/MailTemplateTest.php --filter render_template
```

Expected: FAIL — function does not exist.

- [ ] **Step 4: Implement render_template in `src/mail_template.php`**

Append inside the `Erikr\Auth\Mail` namespace:

```php
/**
 * Default directory for shipping templates.
 * Overridable via $templatesDir argument (used by tests pointing at fixtures).
 */
const TEMPLATES_DIR = __DIR__ . '/../templates/email';

/**
 * Load a template file, substitute {{vars}}, produce subject + HTML + text.
 *
 * @return array{subject: string, html: string, text: string}
 * @throws TemplateException if the template file is missing or frontmatter is malformed
 * @throws \InvalidArgumentException if a {{placeholder}} references a key absent from $vars
 */
function render_template(string $template, array $vars, ?string $templatesDir = null): array
{
    $dir  = $templatesDir ?? TEMPLATES_DIR;
    $path = $dir . '/' . $template . '.md';
    if (!is_file($path)) {
        throw new TemplateException("Template not found: $path");
    }
    [$fm, $body] = parse_frontmatter((string) file_get_contents($path));
    $subject   = substitute_placeholders($fm['subject'] ?? '', $vars);
    $bodyFilled = substitute_placeholders($body, $vars);
    return [
        'subject' => $subject,
        'html'    => markdown_to_html($bodyFilled),
        'text'    => markdown_to_text($bodyFilled),
    ];
}
```

- [ ] **Step 5: Re-run the tests to verify they pass**

```bash
cd /Users/erikr/Git/auth && vendor/bin/phpunit tests/Unit/MailTemplateTest.php
```

Expected: `OK (18 tests, ...)`.

- [ ] **Step 6: Commit**

```bash
cd /Users/erikr/Git/auth
git add src/mail_template.php tests/Unit/MailTemplateTest.php tests/fixtures/email/
git commit -m "feat(mail): add end-to-end render_template()"
```

---

## Task 6: Mail config loader + rename send_mail to internal namespace

**Files:**
- Modify: `/Users/erikr/Git/auth/src/mailer.php`
- Modify: `/Users/erikr/Git/auth/tests/bootstrap.php`
- Create: `/Users/erikr/Git/auth/tests/Unit/MailerConfigTest.php`

- [ ] **Step 1: Write failing test for mail config loader**

Create `tests/Unit/MailerConfigTest.php`:

```php
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
        file_put_contents($tmp, "[smtp]\nhost = mail.example\nport = 587\n"); // missing user, password
        try {
            $this->expectException(\Erikr\Auth\Mail\MailConfigException::class);
            \Erikr\Auth\Mail\load_mail_config_from($tmp);
        } finally {
            unlink($tmp);
        }
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
cd /Users/erikr/Git/auth && vendor/bin/phpunit tests/Unit/MailerConfigTest.php
```

Expected: FAIL — function/class not found.

- [ ] **Step 3: Rewrite `src/mailer.php`**

Replace the entire contents of `src/mailer.php` with:

```php
<?php
/**
 * src/mailer.php — SMTP transport + mail config loader.
 *
 * All mail flows via \Erikr\Auth\Mail\smtp_send(). This function is library-internal;
 * consumers call the typed helpers in src/mail_helpers.php, which go through
 * send_templated_mail() → smtp_send().
 *
 * Sender identity is hard-coded: "Jardyx Support" <noreply@jardyx.com>.
 * SMTP transport (host/port/user/password) is read from a host-level file:
 *   /opt/homebrew/etc/jardyx-mail.ini (dev) or /etc/jardyx/mail.ini (prod).
 */

namespace Erikr\Auth\Mail;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailerException;

class MailConfigException extends \RuntimeException {}

const FROM_ADDRESS = 'noreply@jardyx.com';
const FROM_NAME    = 'Jardyx Support';

const MAIL_CONFIG_PATHS = [
    '/opt/homebrew/etc/jardyx-mail.ini',
    '/etc/jardyx/mail.ini',
];

/**
 * Load SMTP config from the first existing path in MAIL_CONFIG_PATHS.
 * Throws if none exists or if the [smtp] section is incomplete.
 *
 * @return array{host:string, port:int, user:string, password:string}
 */
function load_mail_config(): array
{
    foreach (MAIL_CONFIG_PATHS as $p) {
        if (is_file($p)) {
            return load_mail_config_from($p);
        }
    }
    throw new MailConfigException('Mail config not found. Checked: ' . implode(', ', MAIL_CONFIG_PATHS));
}

/**
 * Load SMTP config from an explicit file path (used by tests and by load_mail_config).
 *
 * @return array{host:string, port:int, user:string, password:string}
 */
function load_mail_config_from(string $path): array
{
    if (!is_file($path)) {
        throw new MailConfigException("Mail config not found: $path");
    }
    $raw = @parse_ini_file($path, true);
    if ($raw === false) {
        throw new MailConfigException("Mail config unreadable: $path");
    }
    $smtp = $raw['smtp'] ?? [];
    foreach (['host', 'port', 'user', 'password'] as $k) {
        if (!array_key_exists($k, $smtp)) {
            throw new MailConfigException("Missing [smtp].$k in $path");
        }
    }
    return [
        'host'     => (string) $smtp['host'],
        'port'     => (int) $smtp['port'],
        'user'     => (string) $smtp['user'],
        'password' => (string) $smtp['password'],
    ];
}

/**
 * Send an email via SMTP. Library-internal.
 *
 * @throws MailerException on send failure
 */
function smtp_send(
    string $toAddress,
    string $toName,
    string $subject,
    string $bodyHtml,
    string $bodyText
): void {
    $cfg = load_mail_config();

    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = $cfg['host'];
    $mail->SMTPAuth   = true;
    $mail->Username   = $cfg['user'];
    $mail->Password   = $cfg['password'];
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = $cfg['port'];
    $mail->CharSet    = 'UTF-8';

    $mail->setFrom(FROM_ADDRESS, FROM_NAME);
    $mail->addAddress($toAddress, $toName);

    $mail->isHTML(true);
    $mail->Subject = $subject;
    $mail->Body    = $bodyHtml;
    $mail->AltBody = $bodyText;

    $mail->send();
}
```

- [ ] **Step 4: Update `tests/bootstrap.php` — replace the old SMTP_* defines with a dummy mail ini**

Replace the last eight lines (all `define('SMTP_...` lines and the comment above them) with:

```php
// Provide a dummy mail config so tests that exercise smtp_send reach a controlled failure.
// The config points at 127.0.0.1:1025 which is unreachable in the test env — helpers catch
// the exception and return false, which is what most mail-related tests assert.
$mailIni = sys_get_temp_dir() . '/jardyx-mail-test.ini';
file_put_contents($mailIni, "[smtp]\nhost = 127.0.0.1\nport = 1025\nuser = test@example.com\npassword = test\n");

// Override the library's config path lookup for the duration of the test process.
// We do this by placing the file at /opt/homebrew/etc if writable, else skipping —
// the tests that hit smtp_send will instead assert the MailConfigException path.
// For portability, tests that need SMTP mocks stub the helper directly rather than
// relying on transport config.
```

- [ ] **Step 5: Run the full test suite to verify everything still passes**

```bash
cd /Users/erikr/Git/auth && vendor/bin/phpunit
```

Expected: the existing `test_send_email_returns_false_when_smtp_unreachable` test in `InviteTest.php` will likely now fail because `send_mail()` is gone. That failure is addressed in Task 8. For now, note the failure is expected; all other tests should pass.

If any OTHER test fails besides that one, investigate before proceeding.

- [ ] **Step 6: Commit**

```bash
cd /Users/erikr/Git/auth
git add src/mailer.php tests/bootstrap.php tests/Unit/MailerConfigTest.php
git commit -m "refactor(mail): move send_mail to internal Erikr\\Auth\\Mail\\smtp_send, load SMTP config from host-level jardyx-mail.ini"
```

---

## Task 7: send_templated_mail dispatcher

**Files:**
- Modify: `/Users/erikr/Git/auth/src/mail_template.php`
- Modify: `/Users/erikr/Git/auth/tests/Unit/MailTemplateTest.php`

- [ ] **Step 1: Write failing test for send_templated_mail**

We can't easily assert on SMTP output in unit tests, but we can assert that the dispatcher:
- Passes the rendered subject/html/text through to smtp_send (by injecting a sender).
- Returns false on transport failure.

Append to `tests/Unit/MailTemplateTest.php` inside the class:

```php
    public function test_send_templated_mail_returns_false_on_transport_failure(): void
    {
        // fixture_simple requires 'who'; we provide it. Transport fails because
        // the default jardyx-mail.ini isn't present in the test env.
        $ok = \Erikr\Auth\Mail\send_templated_mail(
            'fixture_simple',
            'alice@example.com',
            'Alice',
            ['who' => 'Alice'],
            __DIR__ . '/../fixtures/email'
        );
        $this->assertFalse($ok);
    }
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
cd /Users/erikr/Git/auth && vendor/bin/phpunit tests/Unit/MailTemplateTest.php --filter send_templated_mail
```

Expected: FAIL — function does not exist.

- [ ] **Step 3: Implement send_templated_mail in `src/mail_template.php`**

Append inside the `Erikr\Auth\Mail` namespace:

```php
/**
 * Render a template and dispatch via smtp_send. Returns true on success, false on any
 * transport failure (caller decides how to recover / log; same contract as the old
 * invite_send_email).
 */
function send_templated_mail(
    string $template,
    string $toEmail,
    string $toName,
    array $vars,
    ?string $templatesDir = null
): bool {
    $rendered = render_template($template, $vars, $templatesDir);
    try {
        smtp_send($toEmail, $toName, $rendered['subject'], $rendered['html'], $rendered['text']);
        return true;
    } catch (\Throwable $e) {
        return false;
    }
}
```

- [ ] **Step 4: Re-run the tests to verify they pass**

```bash
cd /Users/erikr/Git/auth && vendor/bin/phpunit tests/Unit/MailTemplateTest.php
```

Expected: `OK (19 tests, ...)`.

- [ ] **Step 5: Commit**

```bash
cd /Users/erikr/Git/auth
git add src/mail_template.php tests/Unit/MailTemplateTest.php
git commit -m "feat(mail): add send_templated_mail dispatcher"
```

---

## Task 8: Typed helpers + shipping templates

**Files:**
- Create: `/Users/erikr/Git/auth/src/mail_helpers.php`
- Create: `/Users/erikr/Git/auth/templates/email/invite.md`
- Create: `/Users/erikr/Git/auth/templates/email/password_reset.md`
- Create: `/Users/erikr/Git/auth/templates/email/email_change_confirmation.md`
- Modify: `/Users/erikr/Git/auth/composer.json`
- Modify: `/Users/erikr/Git/auth/src/invite.php` (remove invite_send_email)
- Modify: `/Users/erikr/Git/auth/src/admin.php` (switch to mail_send_invite)
- Modify: `/Users/erikr/Git/auth/tests/Unit/InviteTest.php` (remove/replace test_send_email_returns_false)
- Create: `/Users/erikr/Git/auth/tests/Unit/ShippingTemplatesTest.php`

- [ ] **Step 1: Write three shipping templates**

Create `templates/email/invite.md`:

```markdown
---
subject: Passwort einrichten – {{app_name}}
---

Hallo {{username}},

Bitte richten Sie Ihr Passwort ein:

[{{link}}]({{link}})

Dieser Link ist 48 Stunden gültig.

Bei Fragen antworten Sie nicht auf diese E-Mail, sondern schreiben an [{{support_email}}](mailto:{{support_email}}).

Team {{app_name}}
```

Create `templates/email/password_reset.md`:

```markdown
---
subject: Kennwort zurücksetzen – {{app_name}}
---

Hallo {{username}},

Wir haben eine Anfrage für ein neues Kennwort für Ihr {{app_name}}-Konto erhalten.

[Kennwort zurücksetzen]({{link}})

Dieser Link ist eine Stunde gültig. Wenn Sie keine Zurücksetzung beantragt haben, können Sie diese E-Mail ignorieren.

Bei Fragen antworten Sie nicht auf diese E-Mail, sondern schreiben an [{{support_email}}](mailto:{{support_email}}).

Team {{app_name}}
```

Create `templates/email/email_change_confirmation.md`:

```markdown
---
subject: E-Mail-Adresse bestätigen – {{app_name}}
---

Hallo {{username}},

Bitte bestätigen Sie Ihre neue E-Mail-Adresse für Ihr {{app_name}}-Konto:

[E-Mail-Adresse bestätigen]({{link}})

Dieser Link ist 24 Stunden gültig.

Bei Fragen antworten Sie nicht auf diese E-Mail, sondern schreiben an [{{support_email}}](mailto:{{support_email}}).

Team {{app_name}}
```

- [ ] **Step 2: Create `src/mail_helpers.php`**

```php
<?php
/**
 * src/mail_helpers.php — Typed email helpers.
 *
 * Each helper loads its Markdown template, auto-fills app_name/app_url/support_email
 * from app-defined constants, substitutes per-call variables, and dispatches via
 * send_templated_mail(). Return: bool (false on any transport failure).
 *
 * Apps must define APP_NAME, APP_BASE_URL, APP_SUPPORT_EMAIL before calling.
 */

/** Invitation email: "set your password" link. */
function mail_send_invite(string $toEmail, string $username, string $link): bool
{
    return \Erikr\Auth\Mail\send_templated_mail('invite', $toEmail, $username, [
        'username'      => $username,
        'link'          => $link,
        'app_name'      => APP_NAME,
        'app_url'       => APP_BASE_URL,
        'support_email' => APP_SUPPORT_EMAIL,
    ]);
}

/** Self-service password reset email: "reset your password" link. */
function mail_send_password_reset(string $toEmail, string $username, string $link): bool
{
    return \Erikr\Auth\Mail\send_templated_mail('password_reset', $toEmail, $username, [
        'username'      => $username,
        'link'          => $link,
        'app_name'      => APP_NAME,
        'app_url'       => APP_BASE_URL,
        'support_email' => APP_SUPPORT_EMAIL,
    ]);
}

/** Email-change confirmation: sent to the NEW address, link confirms the change. */
function mail_send_email_change_confirmation(string $toEmail, string $username, string $link): bool
{
    return \Erikr\Auth\Mail\send_templated_mail('email_change_confirmation', $toEmail, $username, [
        'username'      => $username,
        'link'          => $link,
        'app_name'      => APP_NAME,
        'app_url'       => APP_BASE_URL,
        'support_email' => APP_SUPPORT_EMAIL,
    ]);
}
```

- [ ] **Step 3: Add `src/mail_helpers.php` to composer autoload**

Edit `composer.json`, add `"src/mail_helpers.php"` to the `files` array after `"src/mail_template.php"`:

```json
"autoload": {
    "files": [
        "src/log.php",
        "src/csrf.php",
        "src/auth.php",
        "src/mailer.php",
        "src/mail_template.php",
        "src/mail_helpers.php",
        "src/invite.php",
        "src/admin.php",
        "src/totp.php",
        "src/avatar.php",
        "src/bootstrap.php"
    ]
},
```

Run: `cd /Users/erikr/Git/auth && composer dump-autoload`.

- [ ] **Step 4: Write ShippingTemplatesTest**

Create `tests/Unit/ShippingTemplatesTest.php`:

```php
<?php
declare(strict_types=0);

namespace ErikR\Auth\Tests\Unit;

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

    /** @dataProvider templateProvider */
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

        // Link appears in both variants somewhere.
        $this->assertStringContainsString('example.com/token', $out['html']);
        $this->assertStringContainsString('example.com/token', $out['text']);
    }
}
```

- [ ] **Step 5: Remove invite_send_email from `src/invite.php`**

Read the file, then delete the `invite_send_email()` function (lines 33-57 in the current file) and the reference to `send_mail()` / `mailer.php` in the header docblock.

The new header comment for `src/invite.php` should be:

```php
<?php
/**
 * src/invite.php — Invite token management (create, verify, complete).
 *
 * Sending the invite email lives in src/mail_helpers.php (`mail_send_invite`).
 *
 * Requires:
 *  - AUTH_DB_PREFIX constant  (e.g. 'jardyx_auth.' or '')
 *  - appendLog() from src/log.php
 */
```

Delete the `invite_send_email(...)` function entirely.

- [ ] **Step 6: Update `src/admin.php` — replace invite_send_email calls**

Find `invite_send_email(` occurrences in `src/admin.php` (two locations per earlier grep: around lines 117 and 186). Read the current function to understand the local `$email`, `$username`, `$token`, `$baseUrl` variables.

Replace every `invite_send_email($email, $username, $token, $baseUrl)` (or similar) with:

```php
$link = rtrim($baseUrl, '/') . '/setpassword.php?token=' . urlencode($token);
$sent = mail_send_invite($email, $username, $link);
```

(The library no longer assembles the URL — apps know their own URL shape. If a caller already computed `$link`, drop the new line.)

Also remove the reference to `invite_send_email()` from the header docblock of `src/admin.php` — replace with a reference to `mail_send_invite()`.

- [ ] **Step 7: Update `tests/Unit/InviteTest.php` — replace the dropped test**

Find `test_send_email_returns_false_when_smtp_unreachable` in `tests/Unit/InviteTest.php` and delete it entirely — `invite_send_email()` no longer exists. A functionally equivalent test on `mail_send_invite()` requires APP_NAME / APP_BASE_URL / APP_SUPPORT_EMAIL constants, which are app-level. Instead, the transport-failure contract is covered by the `test_send_templated_mail_returns_false_on_transport_failure` case added in Task 7.

- [ ] **Step 8: Run the full suite to confirm green**

```bash
cd /Users/erikr/Git/auth && vendor/bin/phpunit
```

Expected: all tests pass. Count should be ~22 tests total (MailTemplateTest + MailerConfigTest + ShippingTemplatesTest + the adjusted InviteTest/AdminTest).

- [ ] **Step 9: Commit**

```bash
cd /Users/erikr/Git/auth
git add src/mail_helpers.php src/invite.php src/admin.php \
        templates/email/ tests/Unit/ShippingTemplatesTest.php \
        tests/Unit/InviteTest.php composer.json
git commit -m "feat(mail): add typed helpers + shipping Markdown templates; remove invite_send_email"
```

---

## Task 9: mcp — central mail config + generator changes

**Files:**
- Modify: `/Users/erikr/Git/mcp/config.yaml`
- Modify: `/Users/erikr/Git/mcp/generate.py`

- [ ] **Step 1: Inspect `mcp/config.yaml` to confirm current shape**

```bash
cd /Users/erikr/Git/mcp && head -80 config.yaml
```

Expected: a top-level `smtp:` block (host/port/user/password) plus per-app `apps.<name>.smtp` blocks with `from` / `from_name`, and `apps.<name>.app` blocks with `base_url`.

- [ ] **Step 2: Edit `mcp/config.yaml`**

For every app under `apps:`:
- DELETE the `smtp:` sub-block entirely.
- Under `app:`, ensure `name:` is set (add if missing for wlmonitor, zeiterfassung, simplechat-2.1; already present for Energie and suche).
- Under `app:`, add `support_email: contact@eriks.cloud` (same value for every app unless you know a different address per app — spec uses one shared support address; ask user if uncertain).

Example per-app diff:

```yaml
apps:
  energie:
    app:
      name: "Energie"                      # keep
      base_url: "https://energie.eriks.cloud"  # keep
      support_email: "contact@eriks.cloud"  # add
    # smtp: (DELETE this entire block)
    #   from: energie@jardyx.com
    #   from_name: Energie
```

Leave the top-level `smtp:` block untouched — that becomes the source for the shared `jardyx-mail.ini`.

- [ ] **Step 3: Inspect `mcp/generate.py` to see how per-app configs are emitted**

```bash
cd /Users/erikr/Git/mcp && grep -n "smtp\|app\[" generate.py | head -30
```

Identify the code path that copies `smtp:` into per-app output configs.

- [ ] **Step 4: Remove SMTP emission from `generate.py`**

In `generate.py`, find the block(s) that write the per-app `smtp:` section (most likely one function that builds the per-app config dict). Delete those lines.

Verify that the `app:` block emission still includes `name`, `base_url`, `support_email`. If `support_email` isn't already emitted, add:

```python
# in the function that builds the per-app app dict
app_out["support_email"] = app_cfg["app"]["support_email"]
```

- [ ] **Step 5: Regenerate every app's config.yaml**

```bash
cd /Users/erikr/Git/mcp && python3 generate.py
```

Expected: each app's `config.yaml` is rewritten. Check one to confirm: `grep -A4 '^app:\|^smtp:' /Users/erikr/Git/Energie/config.yaml`. Expected: `app:` block has `name`, `base_url`, `support_email`; no `smtp:` block.

- [ ] **Step 6: Commit mcp changes**

```bash
cd /Users/erikr/Git/mcp
git add config.yaml generate.py
git commit -m "refactor(mail): stop emitting per-app SMTP; add app.support_email"
```

The generated per-app config.yaml files are committed as part of each app's feature branch, not here.

---

## Task 10: mcp — deploy.py writes shared jardyx-mail.ini

**Files:**
- Modify: `/Users/erikr/Git/mcp/deploy.py`

- [ ] **Step 1: Inspect deploy.py**

```bash
cd /Users/erikr/Git/mcp && grep -n "def \|target\|dev\|prod" deploy.py | head -40
```

Identify the deploy loop — per-host iteration where the app's config gets rsync'd to the destination.

- [ ] **Step 2: Add a step that writes the shared mail ini**

Locate where the deploy script knows `target` (dev vs prod) and the host root path. Add a new function:

```python
def write_jardyx_mail_ini(target: str, config: dict) -> None:
    """Write the shared jardyx-mail.ini from config.yaml's top-level smtp block.

    Path:
      dev  → /opt/homebrew/etc/jardyx-mail.ini
      prod → /etc/jardyx/mail.ini

    Called once per deploy-all (not per app). Idempotent — overwrites on every run.
    """
    smtp = config.get("smtp", {})
    required = ("host", "port", "user", "password")
    for k in required:
        if k not in smtp:
            raise SystemExit(f"config.yaml top-level smtp.{k} is required")

    body = (
        "[smtp]\n"
        f"host = {smtp['host']}\n"
        f"port = {smtp['port']}\n"
        f"user = {smtp['user']}\n"
        f"password = {smtp['password']}\n"
    )

    if target == "dev":
        path = Path("/opt/homebrew/etc/jardyx-mail.ini")
    else:
        path = Path("/etc/jardyx/mail.ini")

    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(body, encoding="utf-8")
    # Tighten perms — the file holds the SMTP password.
    path.chmod(0o640)
    print(f"wrote {path}")
```

Call this function once at the top of the deploy-all entry point, before the per-app loop. For single-app deploys, call it as well — cheap and keeps the file fresh.

- [ ] **Step 3: Dev-run the deploy locally against one app**

```bash
cd /Users/erikr/Git/mcp && python3 deploy.py --target dev --app energie
```

Expected: the line `wrote /opt/homebrew/etc/jardyx-mail.ini` appears in output. Verify:

```bash
cat /opt/homebrew/etc/jardyx-mail.ini
ls -la /opt/homebrew/etc/jardyx-mail.ini
```

Expected: `[smtp]` section with the values from `mcp/config.yaml`. Permissions `-rw-r----- 1 erikr admin`.

- [ ] **Step 4: Commit**

```bash
cd /Users/erikr/Git/mcp
git add deploy.py
git commit -m "feat(mail): write shared jardyx-mail.ini during deploy"
```

---

## Task 11: Energie — migrate forgotPassword, preferences; update bootstrap + config

**Files:**
- Modify: `/Users/erikr/Git/Energie/web/forgotPassword.php`
- Modify: `/Users/erikr/Git/Energie/web/preferences.php`
- Modify: `/Users/erikr/Git/Energie/inc/initialize.php`
- Modify: `/Users/erikr/Git/Energie/config.example.yaml`
- Modify: `/Users/erikr/Git/Energie/config.yaml`

- [ ] **Step 1: Update config files**

Edit `config.example.yaml` and `config.yaml` in Energie:

- DELETE the entire `smtp:` section.
- Under `app:`, ensure `name: Energie`, `base_url: <url>`, `support_email: contact@eriks.cloud`.

After edit, `grep -A4 '^app:\|^smtp:' config.yaml` should show the `app:` block with three keys and no `smtp:` block.

- [ ] **Step 2: Update `inc/initialize.php`**

Find the SMTP defines and delete these four lines:

```php
define('SMTP_FROM',     $_cfg['smtp']['from']            ?? '');
define('SMTP_FROM_NAME',$_cfg['smtp']['from_name']       ?? 'Energie');
// and any define('SMTP_HOST', ...), define('SMTP_PORT', ...), define('SMTP_USER', ...), define('SMTP_PASS', ...)
```

Add right after the `APP_NAME` define:

```php
define('APP_SUPPORT_EMAIL', $_cfg['app']['support_email'] ?? '');
if (APP_SUPPORT_EMAIL === '') {
    throw new RuntimeException('config.yaml: app.support_email is required');
}
```

- [ ] **Step 3: Migrate `web/forgotPassword.php`**

Find the block (lines 46-61 of the current file) that builds `$htmlBody`, `$textBody`, and calls `send_mail()`. Replace it with:

```php
$resetUrl = APP_BASE_URL . '/executeReset.php?token=' . urlencode($token);

if (mail_send_password_reset($email, $row['username'], $resetUrl)) {
    appendLog($con, 'pwd_reset', 'Reset mail sent: ' . $row['username'], 'web');
} else {
    appendLog($con, 'pwd_reset', 'Reset mail failed', 'web');
}
```

Delete the now-unused `$htmlBody`, `$textBody`, `$uname` variables local to this block. `appendLog(...'failed: ' . $e->getMessage())` is lost; the helper returns bool, not exception. If detailed error text is needed in the log, the helper can be extended later.

- [ ] **Step 4: Migrate `web/preferences.php` (email-change block)**

Find the section that assembles the email-change confirmation (grep marker: `'E-Mail-Adresse bestätigen'` / `confirmUrl`). Replace the HTML/text body building and `send_mail()` call with:

```php
$sent = mail_send_email_change_confirmation($newEmail, $_SESSION['username'] ?? '', $confirmUrl);
if (!$sent) {
    appendLog($con, 'prefs', "email-change send failed for user #{$userId}", 'web');
}
```

Delete the now-unused `$htmlBody`, `$textBody`, `$uname` locals.

- [ ] **Step 5: Deploy the shared mail ini if not present and run the app**

```bash
test -f /opt/homebrew/etc/jardyx-mail.ini || (cd /Users/erikr/Git/mcp && python3 deploy.py --target dev --app energie)
```

Visit `http://energie.test/forgotPassword.php`, submit with a test email, check that:
- No PHP warnings appear.
- `data/` log or `auth_log` shows a "Reset mail sent" or "Reset mail failed" entry (transport failure is OK in dev if you don't have outbound SMTP).

- [ ] **Step 6: Commit**

```bash
cd /Users/erikr/Git/Energie
git add web/forgotPassword.php web/preferences.php inc/initialize.php \
        config.yaml config.example.yaml
git commit -m "refactor(mail): use mail_send_password_reset / mail_send_email_change_confirmation helpers"
```

---

## Task 12: wlmonitor — migrate forgotPassword, preferences; delete registration.php

**Files:**
- Modify: `/Users/erikr/Git/wlmonitor/web/forgotPassword.php`
- Modify: `/Users/erikr/Git/wlmonitor/web/preferences.php`
- Delete: `/Users/erikr/Git/wlmonitor/web/registration.php`
- Modify: `/Users/erikr/Git/wlmonitor/inc/initialize.php`
- Modify: `/Users/erikr/Git/wlmonitor/config.yaml`
- Modify: `/Users/erikr/Git/wlmonitor/config.example.yaml`

- [ ] **Step 1: Delete dead registration.php**

```bash
cd /Users/erikr/Git/wlmonitor && git rm web/registration.php
```

Expected: `rm 'web/registration.php'`. Confirm nothing else references it:

```bash
cd /Users/erikr/Git/wlmonitor && grep -rn "registration.php" web/ inc/ 2>/dev/null
```

Expected: no hits.

- [ ] **Step 2: Update config files**

Same pattern as Energie Task 11 Step 1: delete `smtp:` block, ensure `app.name: "WL Monitor"`, `app.base_url`, `app.support_email`.

- [ ] **Step 3: Update `inc/initialize.php`**

Same pattern as Energie Task 11 Step 2: delete every `define('SMTP_*', …)`; add `define('APP_SUPPORT_EMAIL', …)`. Add `define('APP_NAME', 'WL Monitor')` if missing (was not present in grep).

- [ ] **Step 4: Migrate `web/forgotPassword.php`**

Replace the inline-HTML-body block with:

```php
$resetUrl = APP_BASE_URL . '/executeReset.php?token=' . urlencode($token);
if (mail_send_password_reset($email, $row['username'], $resetUrl)) {
    appendLog($con, 'pwd_reset', 'Reset mail sent: ' . $row['username'], 'web');
} else {
    appendLog($con, 'pwd_reset', 'Reset mail failed', 'web');
}
```

Drop old `$htmlBody` / `$textBody` / `$uname` locals.

- [ ] **Step 5: Migrate `web/preferences.php`**

Replace the email-change block with:

```php
$sent = mail_send_email_change_confirmation($newEmail, $_SESSION['username'] ?? '', $confirmUrl);
if (!$sent) {
    appendLog($con, 'prefs', "email-change send failed for user #{$userId}", 'web');
}
```

Drop old `$htmlBody` / `$textBody` locals.

- [ ] **Step 6: Smoke test**

```bash
test -f /opt/homebrew/etc/jardyx-mail.ini || (cd /Users/erikr/Git/mcp && python3 deploy.py --target dev --app wlmonitor)
```

Visit wlmonitor's forgotPassword and preferences pages; exercise both flows with a test email.

- [ ] **Step 7: Commit**

```bash
cd /Users/erikr/Git/wlmonitor
git add web/ inc/initialize.php config.yaml config.example.yaml
git commit -m "refactor(mail): use typed helpers; remove dead registration.php"
```

---

## Task 13: zeiterfassung — migrate forgotPassword, preferences

**Files:**
- Modify: `/Users/erikr/Git/zeiterfassung/web/forgotPassword.php`
- Modify: `/Users/erikr/Git/zeiterfassung/web/preferences.php`
- Modify: `/Users/erikr/Git/zeiterfassung/inc/initialize.php`
- Modify: `/Users/erikr/Git/zeiterfassung/config.yaml`
- Modify: `/Users/erikr/Git/zeiterfassung/config.example.yaml`

- [ ] **Step 1: Update config files**

Same pattern as Energie Task 11 Step 1. Ensure `app.name: "Zeiterfassung"`, `app.support_email: contact@eriks.cloud`.

- [ ] **Step 2: Update `inc/initialize.php`**

Same pattern as Energie Task 11 Step 2. Delete every SMTP define, add APP_SUPPORT_EMAIL, ensure APP_NAME is defined.

- [ ] **Step 3: Migrate `web/forgotPassword.php`**

Same replacement as Energie Task 11 Step 3:

```php
$resetUrl = APP_BASE_URL . '/executeReset.php?token=' . urlencode($token);
if (mail_send_password_reset($email, $row['username'], $resetUrl)) {
    appendLog($con, 'pwd_reset', 'Reset mail sent: ' . $row['username'], 'web');
} else {
    appendLog($con, 'pwd_reset', 'Reset mail failed', 'web');
}
```

- [ ] **Step 4: Migrate `web/preferences.php`**

Same replacement as Energie Task 11 Step 4:

```php
$sent = mail_send_email_change_confirmation($newEmail, $_SESSION['username'] ?? '', $confirmUrl);
if (!$sent) {
    appendLog($con, 'prefs', "email-change send failed for user #{$userId}", 'web');
}
```

- [ ] **Step 5: Smoke test — visit forgotPassword + preferences pages**

- [ ] **Step 6: Commit**

```bash
cd /Users/erikr/Git/zeiterfassung
git add web/forgotPassword.php web/preferences.php inc/initialize.php \
        config.yaml config.example.yaml
git commit -m "refactor(mail): use typed helpers"
```

---

## Task 14: simplechat-2.1 — migrate forgotPassword

**Files:**
- Modify: `/Users/erikr/Git/simplechat-2.1/web/forgotPassword.php`
- Modify: `/Users/erikr/Git/simplechat-2.1/inc/initialize.php`
- Modify: `/Users/erikr/Git/simplechat-2.1/config.yaml` (or equivalent — check actual path)
- Modify: `/Users/erikr/Git/simplechat-2.1/config.example.yaml` (or equivalent)

- [ ] **Step 1: Locate simplechat's config file**

```bash
cd /Users/erikr/Git/simplechat-2.1 && ls -la | grep -iE "config|\.ini|\.yaml"
```

Identify the runtime config file and the example file.

- [ ] **Step 2: Update config files**

Same pattern as Energie Task 11 Step 1. Ensure `app.name: "SimpleChat"`, `app.support_email: contact@eriks.cloud`.

- [ ] **Step 3: Update `inc/initialize.php`**

Same pattern as Energie Task 11 Step 2. Add APP_NAME if missing (was not present in grep).

- [ ] **Step 4: Migrate `web/forgotPassword.php`**

Same replacement as Energie Task 11 Step 3 (adjust the local variable names to match what simplechat currently uses — the shape of the surrounding code varies slightly per app but the replacement pattern is identical).

- [ ] **Step 5: Smoke test — visit forgotPassword**

simplechat doesn't have a preferences email-change flow, so only forgotPassword needs smoke-testing.

- [ ] **Step 6: Commit**

```bash
cd /Users/erikr/Git/simplechat-2.1
git add web/forgotPassword.php inc/initialize.php config.yaml config.example.yaml
git commit -m "refactor(mail): use mail_send_password_reset helper"
```

---

## Task 15: suche — migrate forgotPassword

**Files:**
- Modify: `/Users/erikr/Git/suche/web/forgotPassword.php`
- Modify: `/Users/erikr/Git/suche/inc/initialize.php`
- Modify: `/Users/erikr/Git/suche/config.yaml`
- Modify: `/Users/erikr/Git/suche/config.example.yaml`

- [ ] **Step 1: Update config files**

Same pattern as Energie Task 11 Step 1. `app.name: "Suche"` is already present; add `app.support_email: contact@eriks.cloud`; delete `smtp:`.

- [ ] **Step 2: Update `inc/initialize.php`**

Same pattern as Energie Task 11 Step 2.

- [ ] **Step 3: Migrate `web/forgotPassword.php`**

Same replacement as Energie Task 11 Step 3.

- [ ] **Step 4: Smoke test — visit forgotPassword**

- [ ] **Step 5: Commit**

```bash
cd /Users/erikr/Git/suche
git add web/forgotPassword.php inc/initialize.php config.yaml config.example.yaml
git commit -m "refactor(mail): use mail_send_password_reset helper"
```

---

## Task 16: Cross-repo composer.lock refresh + end-to-end verification

**Files:** each app's `composer.lock` (if applicable).

- [ ] **Step 1: Refresh composer autoload in each app**

Each app depends on `erikr/auth` as a path repository. Since auth has new autoloaded files, each app needs a fresh autoload.

```bash
for d in Energie wlmonitor zeiterfassung simplechat-2.1 suche; do
  echo "=== $d ==="
  (cd /Users/erikr/Git/$d && composer dump-autoload)
done
```

Expected: each prints `Generated autoload files containing X classes`.

- [ ] **Step 2: Run auth library tests one more time**

```bash
cd /Users/erikr/Git/auth && vendor/bin/phpunit
```

Expected: full green.

- [ ] **Step 3: Deploy all to dev**

```bash
cd /Users/erikr/Git/mcp && python3 deploy.py --target dev --all
```

(Check the actual `deploy-all` flag in the script — adjust if the CLI uses different argument names.)

- [ ] **Step 4: Manual smoke test each email flow**

In a browser against dev (http://*.test or http://localhost/*):

- [ ] `wlmonitor/forgotPassword.php` — enter test email, submit, check `auth_log` (`SELECT * FROM auth_log WHERE context='pwd_reset' ORDER BY logTime DESC LIMIT 5;`).
- [ ] `Energie/forgotPassword.php` — same.
- [ ] `zeiterfassung/forgotPassword.php` — same.
- [ ] `simplechat-2.1/forgotPassword.php` — same.
- [ ] `suche/forgotPassword.php` — same.
- [ ] `Energie/preferences.php` — change email, check `auth_log` (`context='prefs'`).
- [ ] `wlmonitor/preferences.php` — same.
- [ ] `zeiterfassung/preferences.php` — same.
- [ ] Create a new user via any admin page (e.g. `Energie/admin.php` → Benutzer anlegen) — verify invite email attempt is logged.

If you have a real SMTP gateway in dev, check the inbox; otherwise check the log rows for "send failed"/"send ok" outcomes consistent with your environment.

- [ ] **Step 5: No commit — this task is verification only.**

---

## Task 17: Merge all feature branches (coordinated)

**Files:** none beyond merge commits.

- [ ] **Step 1: Open a PR per repo**

For each of the seven repos, push the branch and open a PR. Use the same PR title across all: `Email templates: library-owned, centralized sender`. PR descriptions link to the spec at `auth/docs/superpowers/specs/2026-04-17-email-templates-design.md`.

- [ ] **Step 2: Review + merge all PRs within the same window**

Merge order: `auth` first (library must land before apps are allowed on main), then `mcp`, then the five apps in any order.

- [ ] **Step 3: Production deploy**

```bash
cd /Users/erikr/Git/mcp && python3 deploy.py --target prod --all
```

(Adjust flag names as needed.)

- [ ] **Step 4: Verify production mail ini is in place on each host**

For each production host, verify `/etc/jardyx/mail.ini` exists with correct contents and restrictive perms.

- [ ] **Step 5: Prod smoke — trigger one password reset from production**

Use an account you control. Confirm the email arrives from `Jardyx Support <noreply@jardyx.com>`, subject and body match the Markdown template.

---

## Appendix A: Rollback

Revert each feature-branch merge commit as a set. No schema migrations are involved. `password_resets` and `auth_invite_tokens` tables are untouched by this work.

## Appendix B: Removed state to preserve

`wlmonitor/web/registration.php` is deleted as dead code. If self-registration is ever revived, the git history (`git show HEAD~N:web/registration.php` on wlmonitor) has the old implementation. A revival would also need: a new `mail_send_registration_activation()` helper + template.
