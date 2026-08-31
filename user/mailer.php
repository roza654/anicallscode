<?php
/* Shared PHPMailer helper for the user/ auth backend.
   Mirrors the SMTP setup pattern already used in api/modal-booking.php. */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

/**
 * Send a transactional auth email. Returns true/false; never throws —
 * callers should treat email failure as non-fatal (log it, still
 * respond success to the browser) so a flaky SMTP connection never
 * blocks registration/reset from completing.
 */
function anicalls_send_mail($toEmail, $toName, $subject, $htmlBody, $altBody) {
    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host        = SMTP_HOST;
        $mail->SMTPAuth    = true;
        $mail->Username    = SMTP_USERNAME;
        $mail->Password    = SMTP_PASSWORD;
        $mail->SMTPSecure  = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port        = SMTP_PORT;
        $mail->CharSet     = 'UTF-8';
        $mail->Timeout     = 10;
        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
            ],
        ];

        $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
        $mail->addAddress($toEmail, $toName);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;
        $mail->AltBody = $altBody;

        $mail->send();
        return true;
    } catch (PHPMailerException $e) {
        error_log('[anicalls-auth] Mail send failed to ' . $toEmail . ': ' . $e->getMessage());
        return false;
    }
}

/** Shared HTML email shell matching the site's ink/navy branding used in modal-booking.php's emails. */
function anicalls_mail_shell($titleLine, $subLine, $bodyHtml) {
    return '
<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;background:#ffffff;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden">
  <div style="background:#0E1530;padding:24px 32px">
    <h1 style="color:#ffffff;margin:0;font-size:20px;letter-spacing:-0.02em">' . htmlspecialchars($titleLine) . '</h1>
    <p style="color:#9BA6C9;margin:4px 0 0;font-size:14px">' . htmlspecialchars($subLine) . '</p>
  </div>
  <div style="padding:32px">' . $bodyHtml . '</div>
  <div style="background:#f8fafc;padding:14px 32px;border-top:1px solid #e5e7eb">
    <p style="margin:0;color:#94a3b8;font-size:12px">&copy; ' . date('Y') . ' Anicalls (Pty) Ltd. All rights reserved.</p>
  </div>
</div>';
}
