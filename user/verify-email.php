<?php
/* ============================================================
   ANICALLS — Email verification link handler
   GET /user/verify-email.php?token=...
   Redirects to ../index.html?verified=1 (login-modal.js watches for this)
   ============================================================ */

require_once __DIR__ . '/db.php';

$token = trim((string)($_GET['token'] ?? ''));
$scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/user/verify-email.php')), '/');
$homeUrl   = $scriptDir . '/../index.html';

if ($token === '') {
    header('Location: ' . $homeUrl);
    exit;
}

$conn = anicalls_db();

$stmt = mysqli_prepare($conn, 'SELECT id FROM users WHERE verification_token = ? LIMIT 1');
mysqli_stmt_bind_param($stmt, 's', $token);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$user   = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);

if ($user) {
    $upd = mysqli_prepare($conn,
        'UPDATE users SET email_verified_at = NOW(), verification_token = NULL WHERE id = ?');
    mysqli_stmt_bind_param($upd, 'i', $user['id']);
    mysqli_stmt_execute($upd);
    mysqli_stmt_close($upd);
    header('Location: ' . $homeUrl . '?verified=1');
} else {
    /* Unknown/already-used token — still send them home to sign in */
    header('Location: ' . $homeUrl);
}
exit;
