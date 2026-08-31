<?php
/* ============================================================
   ANICALLS — Auth backend shared configuration TEMPLATE

   Copy this file to `config.php` (same folder) and fill in the
   real values. `config.php` is git-ignored and is required by
   user/db.php and user/mailer.php.
   ============================================================ */

/* Database */
define('DB_HOST', 'localhost');
define('DB_USER', 'your_db_user');
define('DB_PASS', 'your_db_password');
define('DB_NAME', 'your_db_name');

/* SMTP — Gmail App Password (myaccount.google.com/apppasswords) */
define('SMTP_HOST',       'smtp.gmail.com');
define('SMTP_PORT',       587);
define('SMTP_USERNAME',   'you@example.com');
define('SMTP_PASSWORD',   'your_smtp_app_password');
define('SMTP_FROM_EMAIL', 'you@example.com');
define('SMTP_FROM_NAME',  'Anicalls');

/* Base URL used to build links inside emails (verify/reset).
   Relative to this file's own folder — works regardless of the
   subfolder the site happens to be served under. */
define('APP_BASE_PATH', dirname($_SERVER['SCRIPT_NAME'] ?? '/user') . '/..');
