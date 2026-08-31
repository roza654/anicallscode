<?php
/* ============================================================
   ANICALLS — Account registration
   Called by assests/js/login-modal.js (submitRegister)
   Expects: name, email, password (multipart/form-data)
   Returns JSON: { success, message }
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

$name     = trim((string)($_POST['name']     ?? ''));
$email    = trim((string)($_POST['email']    ?? ''));
$password = (string)($_POST['password'] ?? '');

if ($name === '' || $email === '' || $password === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Please fill in your name, email and password.']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Please enter a valid email address.']);
    exit;
}

if (strlen($password) < 8) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Password must be at least 8 characters.']);
    exit;
}

$conn = anicalls_db();

/* Reject duplicate email */
$check = mysqli_prepare($conn, 'SELECT id FROM users WHERE email = ? LIMIT 1');
mysqli_stmt_bind_param($check, 's', $email);
mysqli_stmt_execute($check);
mysqli_stmt_store_result($check);
if (mysqli_stmt_num_rows($check) > 0) {
    mysqli_stmt_close($check);
    http_response_code(409);
    echo json_encode(['success' => false, 'message' => 'An account with this email already exists. Try signing in instead.']);
    exit;
}
mysqli_stmt_close($check);

$passwordHash = password_hash($password, PASSWORD_DEFAULT);
$token        = bin2hex(random_bytes(32));

$insert = mysqli_prepare($conn,
    'INSERT INTO users (name, email, password, verification_token) VALUES (?, ?, ?, ?)');
mysqli_stmt_bind_param($insert, 'ssss', $name, $email, $passwordHash, $token);

if (!mysqli_stmt_execute($insert)) {
    http_response_code(500);
    error_log('[anicalls-auth] register insert failed: ' . mysqli_stmt_error($insert));
    echo json_encode(['success' => false, 'message' => 'Could not create your account. Please try again.']);
    mysqli_stmt_close($insert);
    exit;
}
mysqli_stmt_close($insert);

/* Build verify link relative to this file's own folder — works under any serving path */
$scriptDir  = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/user/register.php')), '/');
$verifyLink = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://')
            . ($_SERVER['HTTP_HOST'] ?? 'localhost')
            . $scriptDir . '/verify-email.php?token=' . $token;

/* ── Respond to the browser immediately — sending email over SMTP can take
   several seconds (or hang), and the registration itself already succeeded
   once the INSERT above committed. Flush the JSON now so the browser never
   waits on (or times out because of) the slow outbound mail step. ── */
$jsonResponse = json_encode([
    'success' => true,
    'message' => 'Registration successful. Please check your email to verify your account.',
]);
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

/* ── Background: send the verification email now that the browser already has its response ── */
$body = anicalls_mail_shell(
    'Verify Your Email',
    'Anicalls Account',
    '<p style="color:#374151;margin:0 0 20px;font-size:15px;line-height:1.6">Hi ' . htmlspecialchars($name) . ', welcome to Anicalls. Please confirm your email address to activate your account.</p>'
    . '<p style="text-align:center;margin:0 0 20px"><a href="' . $verifyLink . '" style="display:inline-block;background:#2E5BEB;color:#fff;text-decoration:none;padding:14px 32px;border-radius:8px;font-weight:700">Verify Email Address</a></p>'
    . '<p style="color:#94a3b8;font-size:12px;word-break:break-all">Or paste this link into your browser: ' . htmlspecialchars($verifyLink) . '</p>'
);

anicalls_send_mail($email, $name, 'Verify Your Email — Anicalls', $body,
    "Hi {$name},\n\nPlease verify your email address:\n{$verifyLink}\n\n— Anicalls");
