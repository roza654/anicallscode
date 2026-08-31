<?php
/* ============================================================
   ANICALLS — Reset password
   GET  ?token=...  → renders a minimal reset form
   POST token+password → updates the password, redirects to
   ../index.html?passwordReset=1 (login-modal.js watches for this)
   ============================================================ */

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/db.php';

$scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/user/reset-password.php')), '/');
$homeUrl   = $scriptDir . '/../index.html';
$conn      = anicalls_db();

function find_valid_token($conn, $token) {
    if ($token === '') return null;
    $stmt = mysqli_prepare($conn,
        'SELECT id FROM users WHERE reset_token = ? AND reset_token_expires_at > NOW() LIMIT 1');
    mysqli_stmt_bind_param($stmt, 's', $token);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $user = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);
    return $user;
}

$error      = '';
$tokenValid = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token           = trim((string)($_POST['token'] ?? ''));
    $password        = (string)($_POST['password'] ?? '');
    $confirmPassword = (string)($_POST['confirm_password'] ?? '');
    $user            = find_valid_token($conn, $token);
    $tokenValid      = (bool)$user;

    if (!$user) {
        $error = 'This reset link is invalid or has expired. Please request a new one.';
    } elseif (strlen($password) < 8) {
        /* Token is still good — just re-show the form so the user can
           correct the password without needing a brand new reset email. */
        $error = 'Password must be at least 8 characters — please try again.';
    } elseif ($password !== $confirmPassword) {
        $error = 'Passwords do not match — please try again.';
    } else {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $upd = mysqli_prepare($conn,
            'UPDATE users SET password = ?, reset_token = NULL, reset_token_expires_at = NULL WHERE id = ?');
        mysqli_stmt_bind_param($upd, 'si', $hash, $user['id']);
        mysqli_stmt_execute($upd);
        mysqli_stmt_close($upd);
        header('Location: ' . $homeUrl . '?passwordReset=1');
        exit;
    }
} else {
    $token = trim((string)($_GET['token'] ?? ''));
    $user  = find_valid_token($conn, $token);
    $tokenValid = (bool)$user;
    if (!$user) {
        $error = 'This reset link is invalid or has expired. Please request a new one from the sign-in form.';
    }
}
?>
<!DOCTYPE html>
<html lang="en-GB">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reset Your Password | Anicalls</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../styles.css">
</head>
<body>
<div style="min-height:100vh;display:flex;align-items:center;justify-content:center;padding:28px">
  <div style="width:100%;max-width:420px;background:var(--surface);border:1px solid var(--line);border-radius:var(--radius);padding:40px;box-shadow:var(--shadow)">
    <a href="<?php echo htmlspecialchars($homeUrl); ?>" class="logo" style="display:inline-block;margin-bottom:28px">Anicalls<span>.</span></a>
    <h2 style="margin-bottom:10px">Reset your password</h2>
    <?php if ($error && !$tokenValid): ?>
      <p class="lede" style="margin-bottom:22px;font-size:14px"><?php echo htmlspecialchars($error); ?></p>
      <a href="<?php echo htmlspecialchars($homeUrl); ?>" class="btn btn-primary">Back to Anicalls <span class="arr">→</span></a>
    <?php else: ?>
      <?php if ($error): ?>
        <p style="margin-bottom:18px;padding:12px 16px;border-radius:10px;background:rgba(211,47,47,.08);border:1px solid rgba(211,47,47,.25);color:#B3261E;font-size:13px"><?php echo htmlspecialchars($error); ?></p>
      <?php else: ?>
        <p class="lede" style="margin-bottom:22px;font-size:14px">Choose a new password (minimum 8 characters).</p>
      <?php endif; ?>
      <form method="post" class="form-panel on" style="padding:0" id="resetForm">
        <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
        <div style="margin-bottom:18px">
          <label>New password</label>
          <input type="password" name="password" id="rpPassword" minlength="8" required placeholder="Min. 8 characters">
        </div>
        <div style="margin-bottom:8px">
          <label>Confirm new password</label>
          <input type="password" name="confirm_password" id="rpConfirm" minlength="8" required placeholder="Re-enter your new password">
        </div>
        <p id="rpMismatch" style="display:none;margin:0 0 18px;font-size:12.5px;color:#B3261E">Passwords do not match.</p>
        <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center">Update Password <span class="arr">→</span></button>
      </form>
      <script>
        (function () {
          var form    = document.getElementById('resetForm');
          var pass    = document.getElementById('rpPassword');
          var confirm = document.getElementById('rpConfirm');
          var warn    = document.getElementById('rpMismatch');
          form.addEventListener('submit', function (e) {
            if (pass.value !== confirm.value) {
              e.preventDefault();
              warn.style.display = 'block';
              confirm.focus();
            }
          });
          confirm.addEventListener('input', function () {
            warn.style.display = 'none';
          });
        }());
      </script>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
