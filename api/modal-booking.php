<?php
/* Suppress all PHP notices/warnings BEFORE any require so they never
   appear in the response body and corrupt the JSON output. */
ini_set('display_errors', '0');
error_reporting(E_ALL);
ob_start();   /* safety net — discard any stray output before we send JSON */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/google-config.php';

use Google\Client;
use Google\Service\Calendar;
use Google\Service\Calendar\Event;
use GuzzleHttp\Client as GuzzleClient;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

/* ══════════════════════════════════════════════════════════════════
   CONFIGURATION — edit these values before going live
   ══════════════════════════════════════════════════════════════════ */

/* Notification recipient — change to sales@anicalls.com for production */

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

/* ══════════════════════════════════════════════════════════════════
   LOGGING
   ══════════════════════════════════════════════════════════════════ */

$logDir  = __DIR__ . '/logs';
$logFile = $logDir . '/modal-booking-' . date('Y-m-d') . '.log';

function mbLog($level, $message) {
    global $logFile, $logDir;
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    $line = '[' . date('Y-m-d H:i:s') . '] [' . strtoupper($level) . '] ' . $message . PHP_EOL;
    file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
}

/* ══════════════════════════════════════════════════════════════════
   REQUEST GATE
   ══════════════════════════════════════════════════════════════════ */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

/* ══════════════════════════════════════════════════════════════════
   READ + DECODE PAYLOAD
   ══════════════════════════════════════════════════════════════════ */

$raw = file_get_contents('php://input');
mbLog('info', 'Incoming payload: ' . $raw);

if (empty($raw)) {
    http_response_code(400);
    mbLog('error', 'Empty request body');
    echo json_encode(['success' => false, 'message' => 'Empty request body']);
    exit;
}

$data = json_decode($raw, true);

if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
    http_response_code(400);
    mbLog('error', 'JSON decode failed: ' . json_last_error_msg());
    echo json_encode(['success' => false, 'message' => 'Invalid JSON: ' . json_last_error_msg()]);
    exit;
}

/* ══════════════════════════════════════════════════════════════════
   EXTRACT FIELDS
   ══════════════════════════════════════════════════════════════════ */

$first_name   = trim((string)($data['first_name']   ?? ''));
$last_name    = trim((string)($data['last_name']    ?? ''));
$email        = trim((string)($data['email']        ?? ''));
$phone        = trim((string)($data['phone']        ?? ''));
$company      = trim((string)($data['company']      ?? ''));
$job_title    = trim((string)($data['job_title']    ?? ''));
$topic        = trim((string)($data['topic']        ?? ''));
$message      = trim((string)($data['message']      ?? ''));
$booking_date = trim((string)($data['booking_date'] ?? ''));
$booking_time = trim((string)($data['booking_time'] ?? ''));
$booking_type = trim((string)($data['booking_type'] ?? 'Executive Briefing'));

/* ══════════════════════════════════════════════════════════════════
   VALIDATE
   ══════════════════════════════════════════════════════════════════ */

$requiredFields = [
    'first_name'   => $first_name,
    'last_name'    => $last_name,
    'email'        => $email,
    'company'      => $company,
    'job_title'    => $job_title,
    'topic'        => $topic,
    'booking_date' => $booking_date,
    'booking_time' => $booking_time,
];

$missing = array_keys(array_filter($requiredFields, fn($v) => $v === ''));

if (!empty($missing)) {
    http_response_code(422);
    $msg = 'Missing required fields: ' . implode(', ', $missing);
    mbLog('warn', 'Validation failed — ' . $msg);
    echo json_encode(['success' => false, 'message' => $msg]);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(422);
    mbLog('warn', 'Invalid email address: ' . $email);
    echo json_encode(['success' => false, 'message' => 'Invalid email address']);
    exit;
}

/* ══════════════════════════════════════════════════════════════════
   DATABASE INSERT
   ══════════════════════════════════════════════════════════════════ */

try {
    $conn = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
} catch (\mysqli_sql_exception $e) {
    $conn = null;
    mbLog('error', 'DB connect exception: ' . $e->getMessage());
}

if (!$conn) {
    http_response_code(503);
    mbLog('error', 'DB connect failed: ' . mysqli_connect_error());
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

mysqli_set_charset($conn, 'utf8mb4');

/* Create table if it doesn't exist yet (first-run auto-migration) */
mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS `booking_requests` (
        `id`              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
        `first_name`      VARCHAR(100)    NOT NULL,
        `last_name`       VARCHAR(100)    NOT NULL,
        `email`           VARCHAR(255)    NOT NULL,
        `phone`           VARCHAR(50)     DEFAULT NULL,
        `company`         VARCHAR(255)    NOT NULL,
        `job_title`       VARCHAR(255)    NOT NULL,
        `topic`           VARCHAR(500)    NOT NULL,
        `message`         TEXT            DEFAULT NULL,
        `booking_date`    VARCHAR(100)    NOT NULL,
        `booking_time`    VARCHAR(20)     NOT NULL,
        `booking_type`    VARCHAR(100)    NOT NULL DEFAULT 'Executive Briefing',
        `google_event_id` VARCHAR(255)    DEFAULT NULL,
        `meet_link`       VARCHAR(512)    DEFAULT NULL,
        `created_at`      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        INDEX `idx_email`      (`email`),
        INDEX `idx_created_at` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

/* Add new columns if they don't exist yet. Plain MySQL has no "ADD COLUMN IF NOT EXISTS"
   (that's a MariaDB extension), so check information_schema first for portability. */
foreach ([
    ['google_event_id', "VARCHAR(255) DEFAULT NULL AFTER `booking_type`"],
    ['meet_link',       "VARCHAR(512) DEFAULT NULL AFTER `google_event_id`"],
] as [$col, $def]) {
    try {
        $exists = mysqli_query($conn, "SHOW COLUMNS FROM `booking_requests` LIKE '$col'");
        if ($exists && mysqli_num_rows($exists) === 0) {
            mysqli_query($conn, "ALTER TABLE `booking_requests` ADD COLUMN `$col` $def");
        }
    } catch (\Throwable $e) {}
}

/* Check if slot already booked */

$checkSql = "SELECT id
             FROM booking_requests
             WHERE booking_date = ?
             AND booking_time = ?
             LIMIT 1";

$checkStmt = mysqli_prepare($conn, $checkSql);

if (!$checkStmt) {
    http_response_code(500);
    mbLog('error', 'Slot-check prepare failed: ' . mysqli_error($conn));
    echo json_encode(['success' => false, 'message' => 'Database error (slot check): ' . mysqli_error($conn)]);
    mysqli_close($conn);
    exit;
}

mysqli_stmt_bind_param($checkStmt, "ss", $booking_date, $booking_time);
mysqli_stmt_execute($checkStmt);

$result = mysqli_stmt_get_result($checkStmt);

if (mysqli_num_rows($result) > 0) {
    echo json_encode([
        'success' => false,
        'message' => 'This slot is already booked. Please select another time.'
    ]);
    mysqli_stmt_close($checkStmt);
    mysqli_close($conn);
    exit;
}

mysqli_stmt_close($checkStmt);

$sql = "INSERT INTO booking_requests
            (first_name, last_name, email, phone, company, job_title,
             topic, message, booking_date, booking_time, booking_type)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

$stmt = mysqli_prepare($conn, $sql);

if (!$stmt) {
    http_response_code(500);
    mbLog('error', 'mysqli_prepare failed: ' . mysqli_error($conn));
    echo json_encode(['success' => false, 'message' => 'Query preparation failed']);
    mysqli_close($conn);
    exit;
}

mysqli_stmt_bind_param(
    $stmt, 'sssssssssss',
    $first_name, $last_name, $email, $phone, $company, $job_title,
    $topic, $message, $booking_date, $booking_time, $booking_type
);

if (!mysqli_stmt_execute($stmt)) {
    $dbErr = mysqli_stmt_error($stmt);
    http_response_code(500);
    mbLog('error', 'DB insert failed: ' . $dbErr . ' | email=' . $email);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $dbErr]);
    mysqli_stmt_close($stmt);
    mysqli_close($conn);
    exit;
}

$insertedId = mysqli_insert_id($conn);
mysqli_stmt_close($stmt);
mbLog('info', 'Booking saved — id=' . $insertedId . ' | email=' . $email . ' | date=' . $booking_date . ' | time=' . $booking_time);

/* ── Respond to browser immediately after DB insert ──────────────────────
   Google Calendar + email are slow. Flush success JSON NOW so the browser
   never waits and never sees a network error from a timeout.
   $conn stays open so background code can UPDATE meet_link into the row.
   ──────────────────────────────────────────────────────────────────────── */
$jsonResponse = json_encode([
    'success' => true,
    'message' => 'Booking saved successfully',
    'id'      => $insertedId,
]);
http_response_code(200);
header('Content-Length: ' . strlen($jsonResponse));
header('Connection: close');

/* Discard any stray notices/warnings captured since ob_start() at the top */
if (ob_get_level() > 0) ob_end_clean();

echo $jsonResponse;

if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} else {
    flush();
}

ignore_user_abort(true);
set_time_limit(120);

/* ── Background: Google Calendar, DB update, emails ─────────────────── */
$meetLink      = null;
$googleEventId = null;
try {
    $client = new Google\Client();

    $client->setHttpClient(
        new GuzzleHttp\Client([
            'verify' => false
        ])
    );

    $client->setClientId(GOOGLE_CLIENT_ID);
    $client->setClientSecret(GOOGLE_CLIENT_SECRET);
    $client->refreshToken(GOOGLE_REFRESH_TOKEN);

    $service = new Google\Service\Calendar($client);

    $event = new Google\Service\Calendar\Event([
        'summary' => 'Anicalls Executive Briefing - ' . $first_name . ' ' . $last_name,

        'start' => [
            'dateTime' => date('c', strtotime($booking_date . ' ' . $booking_time)),
            'timeZone' => 'Asia/Kolkata'
        ],

        'end' => [
            'dateTime' => date('c', strtotime($booking_date . ' ' . $booking_time . ' +1 hour')),
            'timeZone' => 'Asia/Kolkata'
        ],

        'conferenceData' => [
            'createRequest' => [
                'requestId' => uniqid(),
                'conferenceSolutionKey' => [
                    'type' => 'hangoutsMeet'
                ]
            ]
        ]
    ]);

    $createdEvent  = $service->events->insert(
        'primary',
        $event,
        ['conferenceDataVersion' => 1]
    );

    $googleEventId = $createdEvent->getId();
    $meetLink      = $createdEvent
        ->getConferenceData()
        ->getEntryPoints()[0]
        ->getUri();

    mbLog('info', 'Google Meet created: ' . $meetLink . ' | event=' . $googleEventId . ' | booking #' . $insertedId);
} catch (\Throwable $e) {
    mbLog('error', 'Google Calendar failed (booking still saved): ' . $e->getMessage() . ' | booking #' . $insertedId);
}

/* Save meet_link and google_event_id back to the row we just inserted */
if ($meetLink !== null || $googleEventId !== null) {
    $upd = mysqli_prepare($conn,
        'UPDATE booking_requests SET meet_link = ?, google_event_id = ? WHERE id = ?');
    if ($upd) {
        mysqli_stmt_bind_param($upd, 'ssi', $meetLink, $googleEventId, $insertedId);
        if (mysqli_stmt_execute($upd)) {
            mbLog('info', 'meet_link + google_event_id saved for booking #' . $insertedId);
        } else {
            mbLog('error', 'Failed to save meet_link: ' . mysqli_stmt_error($upd) . ' | booking #' . $insertedId);
        }
        mysqli_stmt_close($upd);
    }
}

mysqli_close($conn);

/* ══════════════════════════════════════════════════════════════════
   SEND EMAILS IN BACKGROUND (browser already got the response)
   ══════════════════════════════════════════════════════════════════ */

$phoneDisplay   = $phone   ?: 'Not provided';
$messageDisplay = $message ?: 'No additional message provided.';

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
    $mail->addAddress($notificationEmail);
   
    $mail->addReplyTo($email, $first_name . ' ' . $last_name);

    $mail->isHTML(true);
    $mail->Subject = 'New Executive Briefing Request — ' . $first_name . ' ' . $last_name;

    $mail->Body = '
<div style="font-family:Arial,sans-serif;max-width:620px;margin:0 auto;background:#fff;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden">
  <div style="background:#0f172a;padding:24px 32px">
    <h1 style="color:#fff;margin:0;font-size:20px">New Booking Request</h1>
    <p style="color:#94a3b8;margin:4px 0 0;font-size:14px">Anicalls Executive Briefing System</p>
  </div>
  <div style="padding:32px">
    <table style="width:100%;border-collapse:collapse;font-size:15px">
      <tr>
        <td style="padding:10px 0;border-bottom:1px solid #f1f5f9;color:#64748b;width:140px">Booking Type</td>
        <td style="padding:10px 0;border-bottom:1px solid #f1f5f9;font-weight:600">' . htmlspecialchars($booking_type) . '</td>
      </tr>
      <tr>
        <td style="padding:10px 0;border-bottom:1px solid #f1f5f9;color:#64748b">First Name</td>
        <td style="padding:10px 0;border-bottom:1px solid #f1f5f9">' . htmlspecialchars($first_name) . '</td>
      </tr>
      <tr>
        <td style="padding:10px 0;border-bottom:1px solid #f1f5f9;color:#64748b">Last Name</td>
        <td style="padding:10px 0;border-bottom:1px solid #f1f5f9">' . htmlspecialchars($last_name) . '</td>
      </tr>
      <tr>
        <td style="padding:10px 0;border-bottom:1px solid #f1f5f9;color:#64748b">Email</td>
        <td style="padding:10px 0;border-bottom:1px solid #f1f5f9">
          <a href="mailto:' . htmlspecialchars($email) . '" style="color:#4f46e5">' . htmlspecialchars($email) . '</a>
        </td>
      </tr>
      <tr>
        <td style="padding:10px 0;border-bottom:1px solid #f1f5f9;color:#64748b">Phone</td>
        <td style="padding:10px 0;border-bottom:1px solid #f1f5f9">' . htmlspecialchars($phoneDisplay) . '</td>
      </tr>
      <tr>
        <td style="padding:10px 0;border-bottom:1px solid #f1f5f9;color:#64748b">Company</td>
        <td style="padding:10px 0;border-bottom:1px solid #f1f5f9">' . htmlspecialchars($company) . '</td>
      </tr>
      <tr>
        <td style="padding:10px 0;border-bottom:1px solid #f1f5f9;color:#64748b">Job Title</td>
        <td style="padding:10px 0;border-bottom:1px solid #f1f5f9">' . htmlspecialchars($job_title) . '</td>
      </tr>
      <tr>
        <td style="padding:10px 0;border-bottom:1px solid #f1f5f9;color:#64748b">Topic</td>
        <td style="padding:10px 0;border-bottom:1px solid #f1f5f9">' . htmlspecialchars($topic) . '</td>
      </tr>
      <tr>
        <td style="padding:10px 0;border-bottom:1px solid #f1f5f9;color:#64748b">Selected Date</td>
        <td style="padding:10px 0;border-bottom:1px solid #f1f5f9;font-weight:600;color:#4f46e5">' . htmlspecialchars($booking_date) . '</td>
      </tr>
      <tr>
        <td style="padding:10px 0;border-bottom:1px solid #f1f5f9;color:#64748b">Selected Time</td>
        <td style="padding:10px 0;border-bottom:1px solid #f1f5f9;font-weight:600;color:#4f46e5">' . htmlspecialchars($booking_time) . '</td>
     
        </tr>
        <tr>
  <td style="padding:10px 0;border-bottom:1px solid #f1f5f9;color:#64748b">
    Google Meet
  </td>
  <td style="padding:10px 0;border-bottom:1px solid #f1f5f9">
    ' . ($meetLink ? '<a href="' . $meetLink . '">' . $meetLink . '</a>' : 'Will be sent separately') . '
  </td>
</tr>
      <tr>
        <td style="padding:10px 0;color:#64748b;vertical-align:top">Message</td>
        <td style="padding:10px 0">' . nl2br(htmlspecialchars($messageDisplay)) . '</td>
      </tr>
    </table>
  </div>
  <div style="background:#f8fafc;padding:16px 32px;border-top:1px solid #e5e7eb">
    <p style="margin:0;color:#94a3b8;font-size:12px">
      Booking ID #' . $insertedId . ' &nbsp;·&nbsp; Submitted ' . date('Y-m-d H:i:s T') . '
    </p>
  </div>
</div>';

    $mail->AltBody =
        "New Executive Briefing Request\n" .
        "================================\n\n" .
        "Booking Type:  {$booking_type}\n" .
        "First Name:    {$first_name}\n" .
        "Last Name:     {$last_name}\n" .
        "Email:         {$email}\n" .
        "Phone:         {$phoneDisplay}\n" .
        "Company:       {$company}\n" .
        "Job Title:     {$job_title}\n" .
        "Topic:         {$topic}\n" .
        "Selected Date: {$booking_date}\n" .
        "Selected Time: {$booking_time}\n\n" .
        "Message:\n{$messageDisplay}\n\n" .
        "Booking ID: #{$insertedId}";

    $mail->send();
    mbLog('info', 'Admin notification sent for booking #' . $insertedId);

    /* ── Meet-link email → sent to the booker only ── */
    if ($meetLink) {
        $recipients = [
            [$email, $first_name . ' ' . $last_name],
        ];

        foreach ($recipients as [$toAddr, $toName]) {
            $userMail = new PHPMailer(true);
            $userMail->isSMTP();
            $userMail->Host        = SMTP_HOST;
            $userMail->SMTPAuth    = true;
            $userMail->Username    = SMTP_USERNAME;
            $userMail->Password    = SMTP_PASSWORD;
            $userMail->SMTPSecure  = PHPMailer::ENCRYPTION_STARTTLS;
            $userMail->Port        = SMTP_PORT;
            $userMail->CharSet     = 'UTF-8';
            $userMail->Timeout     = 10;
            $userMail->SMTPOptions = [
                'ssl' => [
                    'verify_peer'       => false,
                    'verify_peer_name'  => false,
                    'allow_self_signed' => true,
                ],
            ];

            $userMail->setFrom(SMTP_FROM_EMAIL, 'Anicalls');
            $userMail->addAddress($toAddr, $toName);

            $userMail->isHTML(true);
            $userMail->Subject = 'Your Google Meet Link — ' . $booking_date . ' at ' . $booking_time;

            $userMail->Body = '
<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;background:#ffffff;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden">
  <div style="background:#0f172a;padding:24px 32px">
    <h1 style="color:#ffffff;margin:0;font-size:20px;letter-spacing:-0.02em">Anicalls</h1>
    <p style="color:#94a3b8;margin:4px 0 0;font-size:14px">Executive Briefing · Google Meet</p>
  </div>
  <div style="padding:36px 32px;text-align:center">
    <p style="color:#374151;margin:0 0 6px;font-size:15px;line-height:1.6">
      Hi ' . htmlspecialchars($first_name) . ', here is your Google Meet link for the briefing on
      <strong>' . htmlspecialchars($booking_date) . '</strong> at <strong>' . htmlspecialchars($booking_time) . '</strong>.
    </p>
    <p style="color:#64748b;font-size:13px;margin:0 0 28px">Click the button below to join at the scheduled time.</p>
    <a href="' . $meetLink . '"
       style="display:inline-block;background:#d10014;color:#ffffff;text-decoration:none;
              padding:14px 36px;border-radius:8px;font-size:16px;font-weight:700;letter-spacing:-0.01em">
      Join Google Meet
    </a>
    <p style="margin:24px 0 0;font-size:13px;color:#94a3b8;word-break:break-all">
      ' . htmlspecialchars($meetLink) . '
    </p>
  </div>
  <div style="background:#f8fafc;padding:14px 32px;border-top:1px solid #e5e7eb">
    <p style="margin:0;color:#94a3b8;font-size:12px">
      Booking #' . $insertedId . ' &nbsp;·&nbsp; &copy; ' . date('Y') . ' Anicalls India Pvt. Ltd.
    </p>
  </div>
</div>';

            $userMail->AltBody =
                "Google Meet Link — Anicalls Executive Briefing\n" .
                "================================================\n\n" .
                "Hi {$first_name},\n\n" .
                "Your Google Meet link for the briefing on {$booking_date} at {$booking_time}:\n\n" .
                "{$meetLink}\n\n" .
                "Click the link above to join at the scheduled time.\n\n" .
                "Booking #{$insertedId} · © " . date('Y') . " Anicalls India Pvt. Ltd.";

            $userMail->send();
            mbLog('info', 'Meet-link email sent to ' . $toAddr . ' | booking #' . $insertedId);
        }
    } else {
        /* Google Calendar failed — send a plain booking-confirmed email so the user isn't left silent */
        mbLog('warn', 'No meet_link — sending booking-confirmed fallback email | booking #' . $insertedId);
        try {
            $fallbackMail = new PHPMailer(true);
            $fallbackMail->isSMTP();
            $fallbackMail->Host        = SMTP_HOST;
            $fallbackMail->SMTPAuth    = true;
            $fallbackMail->Username    = SMTP_USERNAME;
            $fallbackMail->Password    = SMTP_PASSWORD;
            $fallbackMail->SMTPSecure  = PHPMailer::ENCRYPTION_STARTTLS;
            $fallbackMail->Port        = SMTP_PORT;
            $fallbackMail->CharSet     = 'UTF-8';
            $fallbackMail->Timeout     = 10;
            $fallbackMail->SMTPOptions = [
                'ssl' => [
                    'verify_peer'       => false,
                    'verify_peer_name'  => false,
                    'allow_self_signed' => true,
                ],
            ];

            $fallbackMail->setFrom(SMTP_FROM_EMAIL, 'Anicalls');
            $fallbackMail->addAddress($email, $first_name . ' ' . $last_name);
            $fallbackMail->isHTML(true);
            $fallbackMail->Subject = 'Booking Confirmed — Anicalls Executive Briefing';

            $fallbackMail->Body = '
<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;background:#ffffff;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden">
  <div style="background:#0f172a;padding:24px 32px">
    <h1 style="color:#ffffff;margin:0;font-size:20px;letter-spacing:-0.02em">Anicalls</h1>
    <p style="color:#94a3b8;margin:4px 0 0;font-size:14px">Executive Briefing · Booking Confirmed</p>
  </div>
  <div style="padding:36px 32px">
    <p style="color:#374151;margin:0 0 16px;font-size:15px;line-height:1.6">
      Hi ' . htmlspecialchars($first_name) . ',
    </p>
    <p style="color:#374151;margin:0 0 24px;font-size:15px;line-height:1.6">
      Your executive briefing request has been received and is being reviewed. Here are the details:
    </p>
    <table style="width:100%;border-collapse:collapse;font-size:14px;margin:0 0 28px">
      <tr>
        <td style="padding:10px 0;border-bottom:1px solid #f1f5f9;color:#64748b;width:120px">Date</td>
        <td style="padding:10px 0;border-bottom:1px solid #f1f5f9;font-weight:600">' . htmlspecialchars($booking_date) . '</td>
      </tr>
      <tr>
        <td style="padding:10px 0;border-bottom:1px solid #f1f5f9;color:#64748b">Time</td>
        <td style="padding:10px 0;border-bottom:1px solid #f1f5f9;font-weight:600">' . htmlspecialchars($booking_time) . '</td>
      </tr>
      <tr>
        <td style="padding:10px 0;color:#64748b">Topic</td>
        <td style="padding:10px 0">' . htmlspecialchars($topic) . '</td>
      </tr>
    </table>
    <p style="color:#64748b;font-size:13px;line-height:1.6;margin:0">
      A Google Meet link will be sent to you shortly. An Anicalls specialist will confirm your briefing within <strong>24 hours</strong>.
    </p>
  </div>
  <div style="background:#f8fafc;padding:14px 32px;border-top:1px solid #e5e7eb">
    <p style="margin:0;color:#94a3b8;font-size:12px">
      Booking #' . $insertedId . ' &nbsp;·&nbsp; &copy; ' . date('Y') . ' Anicalls India Pvt. Ltd.
    </p>
  </div>
</div>';

            $fallbackMail->AltBody =
                "Booking Confirmed — Anicalls Executive Briefing\n\n" .
                "Hi {$first_name},\n\n" .
                "Your briefing request has been received:\n" .
                "Date:  {$booking_date}\n" .
                "Time:  {$booking_time}\n" .
                "Topic: {$topic}\n\n" .
                "A Google Meet link will be sent shortly. An Anicalls specialist will confirm within 24 hours.\n\n" .
                "Booking #{$insertedId} · © " . date('Y') . " Anicalls India Pvt. Ltd.";

            $fallbackMail->send();
            mbLog('info', 'Booking-confirmed fallback email sent to ' . $email . ' | booking #' . $insertedId);
        } catch (PHPMailerException $fe) {
            mbLog('error', 'Fallback email failed: ' . $fallbackMail->ErrorInfo . ' | booking #' . $insertedId);
        }
    }
} catch (PHPMailerException $e) {
    $failedInfo = isset($userMail) ? $userMail->ErrorInfo : $mail->ErrorInfo;
    mbLog('error', 'PHPMailer failed: ' . $failedInfo . ' | booking #' . $insertedId);
    /* DB insert already succeeded — return success so the lead is not lost.
       Check logs/modal-booking-*.log if email notifications stop arriving. */
}

mbLog('info', 'Background processing complete for booking #' . $insertedId);
