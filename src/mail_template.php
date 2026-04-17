<?php
/**
 * src/mail_template.php — Markdown-subset email template renderer.
 *
 * Each template file under templates/email/*.md has YAML-style frontmatter
 * (subject only) and a Markdown body. render_template() produces subject +
 * HTML + text from one Markdown source. send_templated_mail() dispatches
 * the result via smtp_send().
 */

namespace Erikr\Auth\Mail;

class TemplateException extends \RuntimeException {}

/**
 * Split raw template text into ($frontmatter, $body).
 * Frontmatter is a key: value map between two `---` lines at the top of the file.
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

/** Internal: convert [text](url) within a single paragraph, HTML-escape everything else.
 *  Splits on link spans so non-link chunks stay intact multibyte strings — escaping
 *  byte-by-byte would drop UTF-8 continuation bytes (e.g. "Für" → "Fr"). */
function _inline_md_to_html(string $para): string
{
    $pattern = '/\[([^\]]+)\]\(([^)]+)\)/';
    $out = '';
    $offset = 0;
    if (preg_match_all($pattern, $para, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
        foreach ($matches as $m) {
            [$full, $pos] = $m[0];
            $text = $m[1][0];
            $url  = $m[2][0];
            if ($pos > $offset) {
                $out .= htmlspecialchars(substr($para, $offset, $pos - $offset), ENT_QUOTES, 'UTF-8');
            }
            $out .= '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">'
                  . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</a>';
            $offset = $pos + strlen($full);
        }
    }
    if ($offset < strlen($para)) {
        $out .= htmlspecialchars(substr($para, $offset), ENT_QUOTES, 'UTF-8');
    }
    return $out;
}

/**
 * Subset-Markdown to plain text: paragraphs separated by blank lines, links rendered as
 * `text: url` (or just `url` when the visible text equals the URL character-for-character,
 * and similarly for `[addr](mailto:addr)`).
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
            if ($m[1] === $m[2]) return $m[2];
            if (str_starts_with($m[2], 'mailto:') && substr($m[2], 7) === $m[1]) return $m[1];
            return $m[1] . ': ' . $m[2];
        },
        $para
    );
}

/**
 * Default directory for shipping templates.
 * Overridable via $templatesDir argument (used by tests pointing at fixtures).
 */
const TEMPLATES_DIR = __DIR__ . '/../templates/email';

/**
 * Load a template file, substitute {{vars}}, produce subject + HTML + text.
 *
 * @return array{subject: string, html: string, text: string}
 */
function render_template(string $template, array $vars, ?string $templatesDir = null): array
{
    $dir  = $templatesDir ?? TEMPLATES_DIR;
    $path = $dir . '/' . $template . '.md';
    if (!is_file($path)) {
        throw new TemplateException("Template not found: $path");
    }
    [$fm, $body]    = parse_frontmatter((string) file_get_contents($path));
    $subject        = substitute_placeholders($fm['subject'] ?? '', $vars);
    $bodyFilled     = substitute_placeholders($body, $vars);
    return [
        'subject' => $subject,
        'html'    => markdown_to_html($bodyFilled),
        'text'    => markdown_to_text($bodyFilled),
    ];
}

/**
 * Render a template and dispatch via smtp_send. Returns true on success, false on any
 * transport or config failure (same contract as the old invite_send_email).
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
