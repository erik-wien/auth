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
