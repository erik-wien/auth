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
