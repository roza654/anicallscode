<?php
/* ============================================================
   ANICALLS — Login
   Called by assests/js/login-modal.js (submitLogin)
   Expects: email, password (multipart/form-data)
   Returns JSON: { success, message }
   ============================================================ */

ini_set('display_errors', '0');
error_reporting(E_ALL);
session_start();

require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$email    = trim((string)($_POST['email']    ?? ''));
$password = (string)($_POST['password'] ?? '');

if ($email === '' || $password === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Please enter your email and password.']);
    exit;
}

$conn = anicalls_db();

$stmt = mysqli_prepare($conn,
    'SELECT id, name, email, password, email_verified_at FROM users WHERE email = ? LIMIT 1');
mysqli_stmt_bind_param($stmt, 's', $email);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$user   = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);

if (!$user || !password_verify($password, $user['password'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Incorrect email or password.']);
    exit;
}

if (empty($user['email_verified_at'])) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Your account is not yet verified. Please check your inbox for the verification email.',
    ]);
    exit;
}

/* Regenerate session id on privilege change to prevent session fixation */
session_regenerate_id(true);
$_SESSION['user_id']    = (int)$user['id'];
$_SESSION['user_name']  = $user['name'];
$_SESSION['user_email'] = $user['email'];

echo json_encode(['success' => true, 'message' => 'Login successful']);
