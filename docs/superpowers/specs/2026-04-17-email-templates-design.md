# Email templates in the auth library

Date: 2026-04-17
Status: design — pending implementation
Scope: `auth/` library + all consumer apps (Energie, wlmonitor, zeiterfassung, simplechat-2.1, suche) + `mcp/` deploy system

## Problem

Every app builds outbound email bodies as inline PHP strings: `forgotPassword.php` in all five apps, `preferences.php` (email-change) in three, and a dead `registration.php` in wlmonitor. The raw transport wrapper `send_mail($to, $name, $subject, $html, $text)` is directly callable from consumer apps, so there is no enforcement that emails follow any shape, wording, or branding. The same "reset your password" copy exists in five slightly-different German variants.

Sender identity is also per-app: each app sets its own `SMTP_FROM` (`energie@jardyx.com`, `wlmonitor@jardyx.com`, …), leading to inconsistent branding when the real identity is a single Jardyx support mailbox.

## Goals

- One source of truth for email subject + body per email type, living in the auth library.
- Apps cannot build email HTML inline. The only way to send email is a library helper.
- Centralised sender identity: `Jardyx Support <noreply@jardyx.com>` for all outbound mail.
- Config contract: apps declare only app identity (`app.name`, `app.base_url`, `app.support_email`). SMTP transport + sender are library-owned.
- No new runtime dependencies.

## Non-goals

- Localisation. All current templates are German; this change stays German-only. Adding i18n is future work.
- Per-user preference for mail format (HTML vs plain-text only). PHPMailer already sends multipart; both variants always go out.
- 2FA enrollment emails. Today's 2FA enrollment is interactive in-app. Adding a template + helper later is trivial.
- Rewriting the `password_resets` / `auth_invite_tokens` flows. This design covers the email layer only — token generation, persistence, and verification stay where they are.

## Public API

Four functions added to the auth library. The generic function is the mechanism; the typed helpers are the consumer-facing surface.

```php
// Generic dispatcher — loads templates/email/{$template}.md, renders, sends.
function send_templated_mail(
    string $template,
    string $toEmail,
    string $toName,
    array  $vars
): bool;

// Typed helpers — required vars visible in the signature, no typo risk.
// Each auto-fills app_name, app_url, support_email from constants.
function mail_send_invite(string $toEmail, string $username, string $link): bool;
function mail_send_password_reset(string $toEmail, string $username, string $link): bool;
function mail_send_email_change_confirmation(string $toEmail, string $username, string $link): bool;
```

All four return `bool` — `true` on successful SMTP dispatch, `false` on mailer exception. Consistent with today's `invite_send_email()` contract.

The current `send_mail()` function in `src/mailer.php` is **renamed** to internal `\Erikr\Auth\Mail\smtp_send()` and is no longer reachable from consumer code. The current `invite_send_email()` in `src/invite.php` is replaced by `mail_send_invite()` and removed.

## Templates

Three template files ship in `auth/templates/email/`:

| File | Sent when |
|---|---|
| `invite.md` | Admin creates a user via `admin_invite_user()` or resets their password via `admin_password_reset()` — user clicks link, sets password. |
| `password_reset.md` | User clicks "forgot password" on login page; app generates a `password_resets` token and calls this helper. |
| `email_change_confirmation.md` | User changes email in preferences; confirmation link is sent to the **new** address. |

### Template file format

One `.md` file per email type. YAML-style frontmatter holds the subject; the body is Markdown. Both the HTML and plain-text mail variants are derived from the same Markdown source at send time — no HTML/text drift is possible. Example (`password_reset.md`):

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

Renders to HTML:

```html
<p>Hallo Alice,</p>
<p>Wir haben eine Anfrage für ein neues Kennwort für Ihr Energie-Konto erhalten.</p>
<p><a href="https://energie.eriks.cloud/executeReset.php?token=…">Kennwort zurücksetzen</a></p>
<p>Dieser Link ist eine Stunde gültig. Wenn Sie keine Zurücksetzung beantragt haben, können Sie diese E-Mail ignorieren.</p>
<p>Bei Fragen antworten Sie nicht auf diese E-Mail, sondern schreiben an <a href="mailto:contact@eriks.cloud">contact@eriks.cloud</a>.</p>
<p>Team Energie</p>
```

And plain text:

```
Hallo Alice,

Wir haben eine Anfrage für ein neues Kennwort für Ihr Energie-Konto erhalten.

Kennwort zurücksetzen: https://energie.eriks.cloud/executeReset.php?token=…

Dieser Link ist eine Stunde gültig. Wenn Sie keine Zurücksetzung beantragt haben, können Sie diese E-Mail ignorieren.

Bei Fragen antworten Sie nicht auf diese E-Mail, sondern schreiben an contact@eriks.cloud.

Team Energie
```

### Available placeholders

Auto-filled by typed helpers from constants:

| Placeholder | Source |
|---|---|
| `{{app_name}}` | `APP_NAME` constant |
| `{{app_url}}` | `APP_BASE_URL` constant |
| `{{support_email}}` | `APP_SUPPORT_EMAIL` constant |

Per-send (passed by caller):

| Placeholder | Source |
|---|---|
| `{{username}}` | Helper's `$username` arg |
| `{{link}}` | Helper's `$link` arg — the full URL the user must click |

A template that references a placeholder not present in the merged `$vars` throws `InvalidArgumentException`. This is a loud caller bug — templates are library-owned, and all helpers control the full var set, so a missing placeholder is always either a template typo or a renamed variable.

### Rendering pipeline

Lives in `src/mail_template.php`, roughly 40–60 LoC. No new Composer dependency — the library ships a small Markdown subset renderer sized to what our templates need.

1. Read `auth/templates/email/{$template}.md`. Missing file → throw.
2. Parse frontmatter: if the file starts with a `---` line, capture everything up to the next `---` line as the header. Extract `subject:` (everything else in the header is ignored for now).
3. Substitute `{{key}}` placeholders:
   - In the **subject line**: replace with the raw value (subject is plain text, not HTML).
   - In the **Markdown body source**: replace with the raw value *before* Markdown parsing, so values containing `[` or `]` or `(` can't accidentally form link syntax. (All current values are short trusted strings — app name, support email, username, URL — so this is defense-in-depth.)
4. Split the body into paragraphs on runs of two or more newlines.
5. For each paragraph:
   - Scan for `[text](url)` link syntax. For each match, record `(text, url)`.
   - **HTML output:** replace each match with `<a href="$urlEscaped">$textEscaped</a>`. Run the remainder of the paragraph text through `htmlspecialchars($text, ENT_QUOTES, 'UTF-8')`. Wrap in `<p>…</p>`.
   - **Text output:** replace each match with `$text: $url` — or just `$url` when `$text === $url` (collapses the "click [https://…](https://…)" case to a single URL).
6. Join HTML paragraphs with `\n`. Join text paragraphs with `\n\n`.
7. Call internal `smtp_send($toEmail, $toName, $subject, $htmlBody, $textBody)`.

### Markdown subset supported

- Paragraphs (blank-line separated).
- Inline links `[text](url)`, including `mailto:` URLs.
- Everything else is passed through verbatim. No headings, lists, code blocks, bold/italic. Our current emails don't use them; if a template grows to need any, extend the converter then — don't pull in a full CommonMark parser speculatively.

## Config contract

Each app's config file gains two `app.*` keys (one of which some apps already have) and loses the entire `smtp:` block:

```yaml
app:
  name: "Energie"                       # required — was missing in wlmonitor / zeiterfassung / simplechat
  base_url: "https://energie.eriks.cloud"
  support_email: "contact@eriks.cloud"  # new
# smtp: REMOVED — library owns transport and sender identity
```

App `inc/initialize.php` materialises these as `APP_NAME`, `APP_BASE_URL`, `APP_SUPPORT_EMAIL` constants. Every `SMTP_*` define in per-app bootstrap is removed.

**Library-side constants** (defined in `auth/src/mailer.php`, compile-time):

```php
const MAIL_FROM_ADDRESS = 'noreply@jardyx.com';
const MAIL_FROM_NAME    = 'Jardyx Support';
```

**Library-side transport config** (read at bootstrap from a single host-level file):

- Path: `/opt/homebrew/etc/jardyx-mail.ini` (dev) / `/etc/jardyx/mail.ini` (prod). Detection follows the same dev/prod heuristic as existing per-app configs (`$_SERVER['SCRIPT_NAME']` check).
- Shape: `[smtp]` section with `host`, `port`, `user`, `password`. No `from` / `from_name` — library constants own those.
- Read by a new bootstrap helper `auth_load_mail_config()` called from `auth_bootstrap()`. Sets library-internal `SMTP_HOST` / `SMTP_PORT` / `SMTP_USER` / `SMTP_PASS` constants in the `\Erikr\Auth\Mail\` namespace so consumer code can't see them.
- Missing file at runtime → first call to `send_templated_mail()` throws `RuntimeException("auth mail config not found at …")`. No silent-fail.

### mcp / deploy changes

- `mcp/config.yaml`: drop per-app `smtp:` sections. Keep the top-level `smtp:` block (it becomes the source for the shared file). Add `support_email` to each app's `app:` section.
- `mcp/generate.py`: stop emitting `smtp:` into per-app configs. Emit `app.support_email`.
- `mcp/deploy.py`: new step writes `/opt/homebrew/etc/jardyx-mail.ini` (dev) / `/etc/jardyx/mail.ini` (prod) from `mcp/config.yaml`'s top-level `smtp:`. Same file content on every host; rewritten on every deploy. World-readable is fine — the file is already root-installed and matches how per-app configs are deployed today.

## Migration

One coordinated change across six repos. Library change can't ship without app changes (public `send_mail()` disappears); app changes can't ship without library change (helpers don't exist yet). Deploy all together.

### auth library

1. Add `src/mail_template.php` — frontmatter parser, placeholder substitution, Markdown-subset renderer (emits HTML + plain text from one source), generic `send_templated_mail()`.
2. Add typed helpers `mail_send_invite`, `mail_send_password_reset`, `mail_send_email_change_confirmation` in `src/mailer.php`.
3. Move SMTP host/port/user/pass into library-internal namespace. `auth_bootstrap()` reads `/opt/homebrew/etc/jardyx-mail.ini`.
4. Rename public `send_mail()` → internal `\Erikr\Auth\Mail\smtp_send()`.
5. Replace `invite_send_email()` call sites in `src/admin.php` with `mail_send_invite()`. Remove `invite_send_email()`.
6. Add `templates/email/invite.md`, `password_reset.md`, `email_change_confirmation.md`. Content is Markdown, generalised via `{{app_name}}` / `{{support_email}}` placeholders; wording matches today's hand-written German copy.

### Per-app changes

Same pattern each app. Files touched:

| App | Files |
|---|---|
| Energie | `web/forgotPassword.php`, `web/preferences.php`, `inc/initialize.php`, `config.example.yaml`, `config.yaml` |
| wlmonitor | `web/forgotPassword.php`, `web/preferences.php`, **delete `web/registration.php`** (dead code, not linked from UI, refers to a nonexistent `register.php`), `inc/initialize.php`, `config.example.yaml`, `config.yaml` |
| zeiterfassung | `web/forgotPassword.php`, `web/preferences.php`, `inc/initialize.php`, `config.example.yaml`, `config.yaml` |
| simplechat-2.1 | `web/forgotPassword.php`, `inc/initialize.php`, `config.example.yaml`, `config.yaml` |
| suche | `web/forgotPassword.php`, `inc/initialize.php`, `config.example.yaml`, `config.yaml` |

For each app:

- Replace the inline `$htmlBody = '<p>…</p>…'; $textBody = "Hallo,…"; send_mail(…);` block with a single `mail_send_password_reset($email, $username, $resetUrl);` (or the `_email_change_confirmation` variant in `preferences.php`).
- Drop every `define('SMTP_*', …)` from `inc/initialize.php`.
- Add `define('APP_SUPPORT_EMAIL', $_cfg['app']['support_email'])`. Ensure `APP_NAME` is defined (add where missing — wlmonitor / zeiterfassung / simplechat).
- Remove the `smtp:` block from `config.yaml` and `config.example.yaml`. Add `app.name` and `app.support_email`.

### mcp

- Edit `mcp/config.yaml` (drop per-app `smtp`, add per-app `app.support_email`).
- Edit `mcp/generate.py` (stop emitting `smtp`, emit `app.support_email`).
- Edit `mcp/deploy.py` (new step: write shared mail ini).

### Deploy discipline

Merge all repo PRs in the same window. Run `mcp/deploy.py deploy-all` once at the end — this pushes the library, every app, and the shared mail ini atomically per host. Half-deployed state breaks mail.

### Rollback

Revert the six commits as a set. No schema changes. `password_resets` and `auth_invite_tokens` tables are untouched.

## Tests

New PHPUnit tests in `auth/tests/Unit/`.

### `MailTemplateTest.php` — renderer unit tests

Uses fixture templates under `tests/fixtures/email/*.md` so renderer tests don't couple to shipping-template wording.

- `testParsesFrontmatterSubject`
- `testSubstitutesPlaceholdersInSubjectAndBody`
- `testHtmlEscapesSubstitutedValuesInHtmlOutput` — e.g. `$vars['username'] = '<script>'` renders as `&lt;script&gt;` in the HTML variant; unchanged in the text variant.
- `testThrowsOnMissingPlaceholder`
- `testThrowsOnUnknownTemplate`
- `testRendersMarkdownParagraphsToHtmlAndText`
- `testRendersLinkWithDistinctText` — `[Kennwort zurücksetzen](https://…)` → HTML `<a>`, text `Kennwort zurücksetzen: https://…`
- `testCollapsesLinkWithSameTextAndUrl` — `[https://…](https://…)` → text `https://…` (no duplicated URL)
- `testPassesThroughNonLinkCharacters` — literal `*`, `_`, `#` in Markdown body survive unchanged (no accidental formatting).

### `ShippingTemplatesTest.php` — smoke test the real templates

For each of the three shipping templates, render with a full set of vars and assert:

- No exception thrown.
- Subject non-empty.
- Body contains the rendered `{{link}}` URL.
- Body contains no unsubstituted `{{…}}` fragment.

### Existing tests

`InviteTest.php` and `AdminTest.php` currently exercise `invite_send_email()`. Switch them to the `mail_send_invite()` path. SMTP dispatch stays mocked via the existing `tests/bootstrap.php` pattern — the renderer runs for real, but nothing hits a network.

### Out of scope for automated tests

- Real SMTP delivery: covered by the existing manual smoke script `wlmonitor/scripts/test_mail.php`. Adapt it to call `send_templated_mail('invite', …)` against a real recipient.
- End-to-end (submit forgot-password form → receive email): manual QA after migration.

Test command unchanged: `composer test` in `auth/`.

## Open questions

None blocking. Deferred items (may become follow-ups):

- If Jardyx later gains a white-label app with a different brand, `MAIL_FROM_ADDRESS` / `MAIL_FROM_NAME` become per-app again. Trivial to refactor from constants to config at that point.
- If a template grows richer Markdown (lists, headings, emphasis, embedded images), extend the subset renderer at that point — or switch to a full CommonMark parser (`league/commonmark`) if the subset gets unwieldy. Don't pull the dependency in speculatively.
