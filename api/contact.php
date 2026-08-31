<?php
ini_set('display_errors', '0');
error_reporting(E_ALL);

/* PHP 8.1+ makes mysqli throw mysqli_sql_exception on every error by default
   (not just connect failures). This file's error handling below assumes the
   legacy "return false, check it yourself" behaviour, so restore that —
   otherwise a failed query becomes an uncaught fatal error and the browser
   gets a broken response instead of the JSON error this file is built to return. */
mysqli_report(MYSQLI_REPORT_OFF);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

/* ══════════════════════════════════════════════════════════════════
   CONFIGURATION — edit these values before going live
   ══════════════════════════════════════════════════════════════════ */

/* Notification recipient — change to sales@anicalls.com for production */
$notificationEmail = 'sales@anicalls.com';

/* SMTP — Gmail example (create an App Password at myaccount.google.com/apppasswords) */
define('SMTP_HOST',       'smtp.gmail.com');
define('SMTP_PORT',       587);
define('SMTP_USERNAME',   'sales@anicalls.com');
define('SMTP_PASSWORD',   'guov yevu nrft ffdo');
define('SMTP_FROM_EMAIL', 'sales@anicalls.com');
define('SMTP_FROM_NAME',  'Anicalls Booking System');

/* Database */
define('DB_HOST', 'localhost');
define('DB_USER', 'anicalls_ai_user');
define('DB_PASS', 'Aianicalls@2026');
define('DB_NAME', 'anicalls_ai_db');

/* Anti-abuse tuning */
define('RATE_LIMIT_MAX',    5);   // max submissions per IP...
define('RATE_LIMIT_MINUTES', 15); // ...within this many minutes
define('DUPLICATE_WINDOW_MINUTES', 2); // identical submission within this window is treated as a duplicate

/* ══════════════════════════════════════════════════════════════════
   LOGGING
   ══════════════════════════════════════════════════════════════════ */

$logDir  = __DIR__ . '/logs';
$logFile = $logDir . '/contact-' . date('Y-m-d') . '.log';

function mbLog($level, $message) {
    global $logFile, $logDir;
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    $line = '[' . date('Y-m-d H:i:s') . '] [' . strtoupper($level) . '] ' . $message . PHP_EOL;
    file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
}

/* ══════════════════════════════════════════════════════════════════
   GLOBAL SAFETY NET — never leak a raw PHP error to the browser;
   always answer with the same JSON shape the frontend expects.
   ══════════════════════════════════════════════════════════════════ */

function mbFail($code, $message, $logMessage = null) {
    http_response_code($code);
    mbLog($code >= 500 ? 'error' : 'warn', $logMessage ?? $message);
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

set_exception_handler(function (\Throwable $e) {
    mbLog('error', 'Uncaught exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
    }
    echo json_encode(['success' => false, 'message' => 'An unexpected error occurred. Please try again shortly.']);
    exit;
});

register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        mbLog('error', 'Fatal: ' . $err['message'] . ' in ' . $err['file'] . ':' . $err['line']);
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(500);
        }
        echo json_encode(['success' => false, 'message' => 'An unexpected error occurred. Please try again shortly.']);
    }
});

/* ══════════════════════════════════════════════════════════════════
   REQUEST GATE
   ══════════════════════════════════════════════════════════════════ */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

/* Lightweight cross-site submission check. This site has no login/session
   on the public pages, so a token-based CSRF scheme has nothing to bind to —
   validating the request's Origin/Referer against this host is the
   appropriate equivalent here. Fails OPEN when the header is simply absent
   (some HTTP clients legitimately omit it) rather than blocking real users. */
$originHeader = $_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? '';
if ($originHeader !== '') {
    $originHost = parse_url($originHeader, PHP_URL_HOST);
    $requestHost = $_SERVER['HTTP_HOST'] ?? '';
    if ($originHost && $requestHost && strcasecmp($originHost, explode(':', $requestHost)[0]) !== 0) {
        mbFail(403, 'Request blocked for security reasons.', 'Blocked cross-site submission — Origin/Referer host: ' . $originHost);
    }
}

/* ══════════════════════════════════════════════════════════════════
   READ + DECODE PAYLOAD
   Accepts either multipart/form-data (FormData — used when a file is
   attached) or a raw application/json body (legacy callers).
   ══════════════════════════════════════════════════════════════════ */

$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
$isMultipart = stripos($contentType, 'multipart/form-data') !== false;

if ($isMultipart) {
    $data = $_POST;
    mbLog('info', 'Incoming payload (multipart): ' . json_encode($data));

    if (empty($data)) {
        mbFail(400, 'Empty request body', 'Empty multipart body');
    }
} else {
    $raw = file_get_contents('php://input');
    mbLog('info', 'Incoming payload: ' . $raw);

    if (empty($raw)) {
        mbFail(400, 'Empty request body', 'Empty request body');
    }

    $data = json_decode($raw, true);

    if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
        mbFail(400, 'Invalid JSON: ' . json_last_error_msg(), 'JSON decode failed: ' . json_last_error_msg());
    }
}

/* ══════════════════════════════════════════════════════════════════
   EXTRACT + SANITIZE FIELDS
   ══════════════════════════════════════════════════════════════════ */

/* Strips control characters (including CR/LF, which prevents email-header
   injection via addReplyTo/addAddress) from single-line fields. */
function mbCleanLine($value) {
    $value = trim((string)$value);
    return preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '';
}

/* Same idea for the free-text message, but newlines are kept and CRLF is
   normalised so the stored/emailed text stays readable. */
function mbCleanMultiline($value) {
    $value = trim((string)$value);
    $value = str_replace(["\r\n", "\r"], "\n", $value);
    return preg_replace('/[\x00-\x09\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';
}

$first_name = mbCleanLine($data['first_name'] ?? '');
$last_name  = mbCleanLine($data['last_name'] ?? '');
$email      = mbCleanLine($data['email'] ?? '');
$job_title  = mbCleanLine($data['job_title'] ?? '');
$company    = mbCleanLine($data['company'] ?? '');
$country    = mbCleanLine($data['country'] ?? '');
$topic      = mbCleanLine($data['topic'] ?? '');
$message    = mbCleanMultiline($data['message'] ?? '');

$ipAddress = mbCleanLine($_SERVER['REMOTE_ADDR'] ?? '');
$userAgent = mb_substr(mbCleanLine($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

/* ══════════════════════════════════════════════════════════════════
   VALIDATE
   ══════════════════════════════════════════════════════════════════ */

/* Every tab requires name + email. Company is required everywhere except
   the "AI Workforce Assessment" tab, which has no company field to fill
   (mirrors the "required" attributes already present in index.html/contact.html). */
$requiredFields = [
    'first_name' => $first_name,
    'last_name'  => $last_name,
    'email'      => $email,
];
if ($topic !== 'AI Workforce Assessment') {
    $requiredFields['company'] = $company;
}

$missing = array_keys(array_filter($requiredFields, fn($v) => $v === ''));

if (!empty($missing)) {
    $msg = 'Missing required fields: ' . implode(', ', $missing);
    mbFail(422, $msg, 'Validation failed — ' . $msg);
}

$lengthLimits = [
    'first_name' => 100, 'last_name' => 100, 'email' => 255, 'job_title' => 255,
    'company' => 255, 'country' => 100, 'topic' => 500, 'message' => 5000,
];
$fieldValues = compact('first_name', 'last_name', 'email', 'job_title', 'company', 'country', 'topic', 'message');
foreach ($lengthLimits as $field => $max) {
    if (mb_strlen($fieldValues[$field]) > $max) {
        mbFail(422, ucfirst(str_replace('_', ' ', $field)) . " is too long (max {$max} characters).", "Validation failed — {$field} exceeds {$max} chars");
    }
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    mbFail(422, 'Invalid email address', 'Invalid email address: ' . $email);
}

/* MX/DNS deliverability check — catches typo'd or non-existent domains
   that still pass basic format validation (e.g. user@gmial.con). */
function mbEmailDomainAcceptsMail($email) {
    $at = strrpos($email, '@');
    if ($at === false) {
        return false;
    }
    $domain = substr($email, $at + 1);
    if ($domain === '') {
        return false;
    }
    if (!function_exists('checkdnsrr')) {
        return true; // fail open if the environment doesn't support DNS lookups
    }
    return checkdnsrr($domain, 'MX') || checkdnsrr($domain, 'A') || checkdnsrr($domain, 'AAAA');
}

if (!mbEmailDomainAcceptsMail($email)) {
    mbFail(422, 'This email address does not appear to be deliverable. Please check the domain and try again.', 'MX/DNS check failed for: ' . $email);
}

/* ══════════════════════════════════════════════════════════════════
   FILE UPLOAD (optional — sent as multipart field "attachment")
   ══════════════════════════════════════════════════════════════════ */
$attachmentPath     = null; // absolute filesystem path, for the email attachment
$attachmentUrlPath  = null; // relative path stored in the DB

if ($isMultipart && !empty($_FILES['attachment']) && $_FILES['attachment']['error'] !== UPLOAD_ERR_NO_FILE) {
    $file = $_FILES['attachment'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        mbFail(422, 'File upload failed. Please try a smaller file.', 'Upload error code: ' . $file['error']);
    }

    $maxBytes = 10 * 1024 * 1024; // 10MB
    if ($file['size'] > $maxBytes) {
        mbFail(422, 'File is too large. Maximum size is 10MB.', 'Upload rejected — file too large: ' . $file['size']);
    }

    $allowedExt = ['pdf', 'doc', 'docx', 'png', 'jpg', 'jpeg', 'txt', 'rtf', 'xls', 'xlsx'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExt, true)) {
        mbFail(422, 'Unsupported file type. Allowed: ' . implode(', ', $allowedExt), 'Upload rejected — extension not allowed: ' . $ext);
    }

    $uploadDir = __DIR__ . '/uploads';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $safeName = bin2hex(random_bytes(16)) . '.' . $ext;
    $destination = $uploadDir . '/' . $safeName;

    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        mbFail(500, 'Could not save the uploaded file. Please try again.', 'move_uploaded_file failed for ' . $file['name']);
    }

    $attachmentPath    = $destination;
    $attachmentUrlPath = 'api/uploads/' . $safeName;
    mbLog('info', 'File uploaded: ' . $file['name'] . ' -> ' . $safeName);
}

/* ══════════════════════════════════════════════════════════════════
   DATABASE
   ══════════════════════════════════════════════════════════════════ */

try {
    $conn = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
} catch (\mysqli_sql_exception $e) {
    $conn = null;
    mbLog('error', 'DB connect exception: ' . $e->getMessage());
}

if (!$conn) {
    mbFail(503, 'Database connection failed', 'DB connect failed: ' . mysqli_connect_error());
}

mysqli_set_charset($conn, 'utf8mb4');

/* Auto-create table — safe on first run and re-runs */
mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS `contact_inquiries` (
        `id`         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
        `first_name` VARCHAR(100)  NOT NULL,
        `last_name`  VARCHAR(100)  NOT NULL,
        `email`      VARCHAR(255)  NOT NULL,
        `job_title`  VARCHAR(255)  DEFAULT NULL,
        `company`    VARCHAR(255)  DEFAULT NULL,
        `country`    VARCHAR(100)  DEFAULT NULL,
        `topic`      VARCHAR(500)  DEFAULT NULL,
        `message`    TEXT          DEFAULT NULL,
        `attachment_path` VARCHAR(255) DEFAULT NULL,
        `created_at` TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        INDEX `idx_email`      (`email`),
        INDEX `idx_created_at` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
if (mysqli_error($conn)) {
    mbLog('error', 'Auto-migration failed: ' . mysqli_error($conn));
}

/* Add columns for installs that already had this table before they existed.
   MySQL (unlike MariaDB) has no "ADD COLUMN IF NOT EXISTS" — check information_schema instead. */
foreach ([
    ['attachment_path', "VARCHAR(255) DEFAULT NULL AFTER `message`"],
    ['ip_address',       "VARCHAR(45)  DEFAULT NULL AFTER `attachment_path`"],
    ['user_agent',       "VARCHAR(255) DEFAULT NULL AFTER `ip_address`"],
] as [$col, $def]) {
    $colCheck = mysqli_query($conn, "SHOW COLUMNS FROM `contact_inquiries` LIKE '$col'");
    if ($colCheck && mysqli_num_rows($colCheck) === 0) {
        mysqli_query($conn, "ALTER TABLE `contact_inquiries` ADD COLUMN `$col` $def");
        if (mysqli_error($conn)) {
            mbLog('error', "{$col} column migration failed: " . mysqli_error($conn));
        }
    }
}

/* ── Rate limiting: cap submissions per IP in a rolling window ── */
if ($ipAddress !== '') {
    $rlStmt = mysqli_prepare($conn, "SELECT COUNT(*) AS c FROM contact_inquiries WHERE ip_address = ? AND created_at > (NOW() - INTERVAL ? MINUTE)");
    if ($rlStmt) {
        $rlMinutes = RATE_LIMIT_MINUTES;
        mysqli_stmt_bind_param($rlStmt, 'si', $ipAddress, $rlMinutes);
        mysqli_stmt_execute($rlStmt);
        $rlResult = mysqli_stmt_get_result($rlStmt);
        $rlRow = $rlResult ? mysqli_fetch_assoc($rlResult) : null;
        mysqli_stmt_close($rlStmt);
        if ($rlRow && (int)$rlRow['c'] >= RATE_LIMIT_MAX) {
            mysqli_close($conn);
            mbFail(429, 'Too many submissions from this network. Please try again in a few minutes.', 'Rate limit hit for IP ' . $ipAddress);
        }
    }
}

/* ── Duplicate prevention: same person, same topic, same message, submitted moments ago ── */
$dupStmt = mysqli_prepare($conn, "SELECT id FROM contact_inquiries WHERE email = ? AND topic = ? AND message = ? AND created_at > (NOW() - INTERVAL ? MINUTE) LIMIT 1");
if ($dupStmt) {
    $dupMinutes = DUPLICATE_WINDOW_MINUTES;
    mysqli_stmt_bind_param($dupStmt, 'sssi', $email, $topic, $message, $dupMinutes);
    mysqli_stmt_execute($dupStmt);
    $dupResult = mysqli_stmt_get_result($dupStmt);
    $dupRow = $dupResult ? mysqli_fetch_assoc($dupResult) : null;
    mysqli_stmt_close($dupStmt);
    if ($dupRow) {
        mysqli_close($conn);
        mbFail(409, "You've already submitted this request. Our team has received it and will be in touch shortly.", 'Duplicate submission blocked — email=' . $email . ' | existing id=' . $dupRow['id']);
    }
}

$sql = "INSERT INTO contact_inquiries
(
 first_name, last_name, email, job_title, company, country, topic, message,
 attachment_path, ip_address, user_agent
)
VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
$stmt = mysqli_prepare($conn, $sql);

if (!$stmt) {
    mysqli_close($conn);
    mbFail(500, 'Query preparation failed', 'mysqli_prepare failed: ' . mysqli_error($conn));
}
mysqli_stmt_bind_param(
    $stmt,
    'sssssssssss',
    $first_name,
    $last_name,
    $email,
    $job_title,
    $company,
    $country,
    $topic,
    $message,
    $attachmentUrlPath,
    $ipAddress,
    $userAgent
);
if (!mysqli_stmt_execute($stmt)) {
    $dbErr = mysqli_stmt_error($stmt);
    mysqli_stmt_close($stmt);
    mysqli_close($conn);
    mbFail(500, 'Database error. Please try again shortly.', 'DB insert failed: ' . $dbErr . ' | email=' . $email);
}

$insertedId = mysqli_insert_id($conn);

mysqli_stmt_close($stmt);
mysqli_close($conn);

/* ══════════════════════════════════════════════════════════════════
   SEND EMAILS VIA PHPMAILER
   ══════════════════════════════════════════════════════════════════ */

require __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

$messageDisplay = $message ?: 'No additional message provided.';
$topicDisplay   = $topic ?: 'General Enquiry';
$submittedAt    = date('Y-m-d H:i:s T');
$ipDisplay      = $ipAddress ?: 'Unknown';
$userAgentDisplay = $userAgent ?: 'Not available';

function mbNewMailer() {
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
    return $mail;
}

/* ── 1) Notification email to sales ── */
try {
    $mail = mbNewMailer();

    $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
    $mail->addAddress($notificationEmail);
    $mail->addReplyTo($email, $first_name . ' ' . $last_name);

    if ($attachmentPath && is_file($attachmentPath)) {
        $mail->addAttachment($attachmentPath);
    }

    $mail->isHTML(true);
    $mail->Subject = 'New Submission: ' . $topicDisplay . ' — ' . $first_name . ' ' . $last_name;

    $mail->Body = '
<div style="font-family:Arial,sans-serif;max-width:620px;margin:0 auto;background:#fff;border:1px solid #e5e7eb;border-radius:8px;">
  <div style="background:#0f172a;padding:24px 32px">
    <h1 style="color:#fff;margin:0;font-size:20px">New Contact Form Request</h1>
    <p style="color:#94a3b8;margin:4px 0 0;font-size:13px">' . htmlspecialchars($topicDisplay) . '</p>
  </div>

  <div style="padding:32px">
    <table style="width:100%;border-collapse:collapse;font-size:15px">

      <tr>
        <td style="padding:10px 0;border-bottom:1px solid #eee;">Form</td>
        <td style="padding:10px 0;border-bottom:1px solid #eee;">' . htmlspecialchars($topicDisplay) . '</td>
      </tr>

      <tr>
        <td style="padding:10px 0;border-bottom:1px solid #eee;">Submitted</td>
        <td style="padding:10px 0;border-bottom:1px solid #eee;">' . htmlspecialchars($submittedAt) . '</td>
      </tr>

      <tr>
        <td style="padding:10px 0;border-bottom:1px solid #eee;">First Name</td>
        <td style="padding:10px 0;border-bottom:1px solid #eee;">' . htmlspecialchars($first_name) . '</td>
      </tr>

      <tr>
        <td style="padding:10px 0;border-bottom:1px solid #eee;">Last Name</td>
        <td style="padding:10px 0;border-bottom:1px solid #eee;">' . htmlspecialchars($last_name) . '</td>
      </tr>

      <tr>
        <td style="padding:10px 0;border-bottom:1px solid #eee;">Email</td>
        <td style="padding:10px 0;border-bottom:1px solid #eee;">' . htmlspecialchars($email) . '</td>
      </tr>

      <tr>
        <td style="padding:10px 0;border-bottom:1px solid #eee;">Company</td>
        <td style="padding:10px 0;border-bottom:1px solid #eee;">' . htmlspecialchars($company) . '</td>
      </tr>

      <tr>
        <td style="padding:10px 0;border-bottom:1px solid #eee;">Job Title</td>
        <td style="padding:10px 0;border-bottom:1px solid #eee;">' . htmlspecialchars($job_title) . '</td>
      </tr>

      <tr>
        <td style="padding:10px 0;border-bottom:1px solid #eee;">Country</td>
        <td style="padding:10px 0;border-bottom:1px solid #eee;">' . htmlspecialchars($country) . '</td>
      </tr>

      <tr>
        <td style="padding:10px 0;border-bottom:1px solid #eee;">IP Address</td>
        <td style="padding:10px 0;border-bottom:1px solid #eee;">' . htmlspecialchars($ipDisplay) . '</td>
      </tr>

      <tr>
        <td style="padding:10px 0;border-bottom:1px solid #eee;">Browser</td>
        <td style="padding:10px 0;border-bottom:1px solid #eee;font-size:12px;color:#475569">' . htmlspecialchars($userAgentDisplay) . '</td>
      </tr>

      <tr>
        <td style="padding:10px 0;vertical-align:top;">Message</td>
        <td style="padding:10px 0;">' . nl2br(htmlspecialchars($message)) . '</td>
      </tr>

    </table>
  </div>
  <div style="background:#f8fafc;padding:14px 32px;border-top:1px solid #e5e7eb">
    <p style="margin:0;color:#94a3b8;font-size:12px">Inquiry #' . $insertedId . '</p>
  </div>
</div>';

    $mail->AltBody =
        "New Contact Form Request\n" .
        "========================\n\n" .
        "Form:        {$topicDisplay}\n" .
        "Submitted:   {$submittedAt}\n" .
        "First Name:  {$first_name}\n" .
        "Last Name:   {$last_name}\n" .
        "Email:       {$email}\n" .
        "Company:     {$company}\n" .
        "Job Title:   {$job_title}\n" .
        "Country:     {$country}\n" .
        "IP Address:  {$ipDisplay}\n" .
        "Browser:     {$userAgentDisplay}\n\n" .
        "Message:\n{$messageDisplay}\n\n" .
        "Inquiry #{$insertedId}";

    $mail->send();
    mbLog('info', 'Notification email sent to ' . $notificationEmail . ' for contact#' . $insertedId);

} catch (PHPMailerException $e) {
    mbLog('error', 'Notification email failed: ' . $e->getMessage() . ' | contact#' . $insertedId);
    /* DB insert already succeeded — the lead is not lost even if this email fails.
       Check logs/contact-*.log if notifications stop arriving. */
}

/* ── 2) Confirmation email to the submitter ── */
try {
    $confirmMail = mbNewMailer();
    $confirmMail->setFrom(SMTP_FROM_EMAIL, 'Anicalls');
    $confirmMail->addAddress($email, trim($first_name . ' ' . $last_name));
    $confirmMail->isHTML(true);
    $confirmMail->Subject = 'Thank you for contacting ANICALLS';

    $confirmMail->Body = '
<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;background:#ffffff;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden">
  <div style="background:#0f172a;padding:24px 32px">
    <h1 style="color:#ffffff;margin:0;font-size:20px;letter-spacing:-0.02em">Anicalls</h1>
    <p style="color:#94a3b8;margin:4px 0 0;font-size:14px">Thank you for reaching out</p>
  </div>
  <div style="padding:36px 32px">
    <p style="color:#374151;margin:0 0 16px;font-size:15px;line-height:1.6">
      Hi ' . htmlspecialchars($first_name) . ',
    </p>
    <p style="color:#374151;margin:0 0 24px;font-size:15px;line-height:1.6">
      Thank you for contacting Anicalls. We have received your request and a member of our team will review it shortly.
    </p>
    <table style="width:100%;border-collapse:collapse;font-size:14px;margin:0 0 28px">
      <tr>
        <td style="padding:10px 0;border-bottom:1px solid #f1f5f9;color:#64748b;width:140px">Request Type</td>
        <td style="padding:10px 0;border-bottom:1px solid #f1f5f9;font-weight:600">' . htmlspecialchars($topicDisplay) . '</td>
      </tr>' .
      ($company !== '' ? '
      <tr>
        <td style="padding:10px 0;border-bottom:1px solid #f1f5f9;color:#64748b">Company</td>
        <td style="padding:10px 0;border-bottom:1px solid #f1f5f9;font-weight:600">' . htmlspecialchars($company) . '</td>
      </tr>' : '') . '
      <tr>
        <td style="padding:10px 0;color:#64748b">Submitted</td>
        <td style="padding:10px 0">' . htmlspecialchars($submittedAt) . '</td>
      </tr>
    </table>
    <p style="color:#64748b;font-size:13px;line-height:1.6;margin:0">
      <strong>Next steps:</strong> An Anicalls specialist will review your submission and respond within <strong>24 hours</strong> to confirm next steps and tailor our recommendation to your requirements.
    </p>
  </div>
  <div style="background:#f8fafc;padding:14px 32px;border-top:1px solid #e5e7eb">
    <p style="margin:0;color:#94a3b8;font-size:12px">
      Reference #' . $insertedId . ' &nbsp;·&nbsp; &copy; ' . date('Y') . ' Anicalls (Pty) Ltd. All rights reserved.
    </p>
  </div>
</div>';

    $confirmMail->AltBody =
        "Thank you for contacting ANICALLS\n\n" .
        "Hi {$first_name},\n\n" .
        "Thank you for contacting Anicalls. We have received your request and a member of our team will review it shortly.\n\n" .
        "Request Type: {$topicDisplay}\n" .
        ($company !== '' ? "Company: {$company}\n" : '') .
        "Submitted: {$submittedAt}\n\n" .
        "Next steps: An Anicalls specialist will review your submission and respond within 24 hours to confirm next steps and tailor our recommendation to your requirements.\n\n" .
        "Reference #{$insertedId} · (c) " . date('Y') . " Anicalls (Pty) Ltd. All rights reserved.";

    $confirmMail->send();
    mbLog('info', 'Confirmation email sent to ' . $email . ' for contact#' . $insertedId);

} catch (PHPMailerException $e) {
    mbLog('error', 'Confirmation email failed: ' . $e->getMessage() . ' | contact#' . $insertedId);
    /* Non-fatal — the submission itself already succeeded. */
}

/* ══════════════════════════════════════════════════════════════════
   SUCCESS RESPONSE
   ══════════════════════════════════════════════════════════════════ */

http_response_code(200);
echo json_encode([
    'success' => true,
    'message' => 'Contact request saved successfully',
    'id'      => $insertedId
]);
