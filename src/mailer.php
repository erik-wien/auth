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

class MailConfigException extends \RuntimeException {}

const FROM_ADDRESS = 'noreply@jardyx.com';
const FROM_NAME    = 'Jardyx Support';

const MAIL_CONFIG_PATHS = [
    '/opt/homebrew/etc/jardyx-mail.ini',
    '/etc/jardyx/mail.ini',
];

/**
 * Load SMTP config from the first existing path in MAIL_CONFIG_PATHS.
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
 * @throws \PHPMailer\PHPMailer\Exception on send failure
 * @throws MailConfigException            if the host mail config is missing or incomplete
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
