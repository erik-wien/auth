<?php
/**
 * src/mail_helpers.php — Typed email helpers.
 *
 * Each helper loads its Markdown template, auto-fills app_name/app_url/support_email
 * from app-defined constants, substitutes per-call variables, and dispatches via
 * send_templated_mail(). Returns false on any transport failure.
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

/**
 * Send an admin notification template to all active admin accounts.
 *
 * Recipients: auth_accounts WHERE rights='Admin' AND disabled='0' AND activation_code='activated'
 * Each recipient gets an individual send. Returns the count of successful sends.
 *
 * $vars must contain all {{placeholders}} referenced by the template.
 * APP_NAME, APP_BASE_URL, APP_SUPPORT_EMAIL are merged in automatically.
 *
 * @param array<string,string> $vars
 */
function mail_send_admin_notice(mysqli $con, string $template, array $vars): int
{
    $table = AUTH_DB_PREFIX . 'auth_accounts';
    $stmt  = $con->prepare(
        "SELECT email, username FROM {$table}
         WHERE rights = 'Admin' AND disabled = '0' AND activation_code = 'activated'"
    );
    if ($stmt === false) return 0;
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result === false) { $stmt->close(); return 0; }

    $allVars = array_merge([
        'app_name'      => APP_NAME,
        'app_url'       => APP_BASE_URL,
        'support_email' => APP_SUPPORT_EMAIL,
    ], $vars);

    $sent = 0;
    while ($admin = $result->fetch_assoc()) {
        $recipientVars = array_merge($allVars, ['admin_username' => $admin['username']]);
        if (\Erikr\Auth\Mail\send_templated_mail(
            $template,
            (string) $admin['email'],
            (string) $admin['username'],
            $recipientVars
        )) {
            $sent++;
        }
    }
    $stmt->close();
    return $sent;
}
