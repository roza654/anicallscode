<?php
/* ============================================================
   ANICALLS — Forgot password
   Called by assests/js/login-modal.js (submitForgot)
   Expects: email (multipart/form-data)
   Returns JSON: { success, message } — always a generic success
   message regardless of whether the email exists, to avoid
   leaking which addresses have accounts.
   ============================================================ */

ini_set('display_errors', '0');
error_reporting(E_ALL);
ob_start();   /* safety net — discard any stray output before we send JSON */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mailer.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$email = trim((string)($_POST['email'] ?? ''));
$generic = 'If an account exists with this email, a reset link has been sent.';

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => true, 'message' => $generic]);
    exit;
}

$conn = anicalls_db();

$stmt = mysqli_prepare($conn, 'SELECT id, name FROM users WHERE email = ? LIMIT 1');
mysqli_stmt_bind_param($stmt, 's', $email);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$user   = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);

$resetLink = null;
if ($user) {
    $token = bin2hex(random_bytes(32));

    $upd = mysqli_prepare($conn,
        'UPDATE users SET reset_token = ?, reset_token_expires_at = DATE_ADD(NOW(), INTERVAL 1 HOUR) WHERE id = ?');
    mysqli_stmt_bind_param($upd, 'si', $token, $user['id']);
    mysqli_stmt_execute($upd);
    mysqli_stmt_close($upd);

    $scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/user/forgot-password.php')), '/');
    $resetLink = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://')
               . ($_SERVER['HTTP_HOST'] ?? 'localhost')
               . $scriptDir . '/reset-password.php?token=' . $token;
}

/* ── Respond to the browser immediately — sending email over SMTP can take
   several seconds (or hang). Flush the generic JSON now so the browser
   never waits on (or times out because of) the slow outbound mail step. ── */
$jsonResponse = json_encode(['success' => true, 'message' => $generic]);
http_response_code(200);
header('Content-Length: ' . strlen($jsonResponse));
header('Connection: close');

if (ob_get_level() > 0) ob_end_clean();
echo $jsonResponse;

if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} else {
    flush();
}

ignore_user_abort(true);
set_time_limit(120);

/* ── Background: send the reset email now that the browser already has its response ── */
if ($user && $resetLink) {
    $body = anicalls_mail_shell(
        'Reset Your Password',
        'Anicalls Account',
        '<p style="color:#374151;margin:0 0 20px;font-size:15px;line-height:1.6">Hi ' . htmlspecialchars($user['name']) . ', we received a request to reset your password. This link expires in 1 hour.</p>'
        . '<p style="text-align:center;margin:0 0 20px"><a href="' . $resetLink . '" style="display:inline-block;background:#2E5BEB;color:#fff;text-decoration:none;padding:14px 32px;border-radius:8px;font-weight:700">Reset Password</a></p>'
        . '<p style="color:#94a3b8;font-size:12px;word-break:break-all">Or paste this link into your browser: ' . htmlspecialchars($resetLink) . '</p>'
        . '<p style="color:#94a3b8;font-size:12px">If you did not request this, you can safely ignore this email.</p>'
    );

    anicalls_send_mail($email, $user['name'], 'Reset Your Password — Anicalls', $body,
        "Hi {$user['name']},\n\nReset your password (link expires in 1 hour):\n{$resetLink}\n\nIf you did not request this, ignore this email.");
}
