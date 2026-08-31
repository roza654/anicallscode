<?php
/**
 * Anicalls — Booking form handler
 * Receives JSON POST from book-consultation.html
 * Inserts into MySQL `anicalls`.`bookings` table
 */

/* ── 0. Prevent any PHP output before JSON ── */
ini_set('display_errors', 0);
error_reporting(E_ALL);

/* ── 1. CORS headers (same-origin assumed; add origin if needed) ── */
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

/* ── 2. Only accept POST ── */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

/* ── 3. Database connection ── */
try {
    $conn = mysqli_connect('localhost', 'anicalls_ai_user', 'Aianicalls@2026', 'anicalls_ai_db');
} catch (\mysqli_sql_exception $e) {
    $conn = null;
    error_log('[booking.php] DB connect exception: ' . $e->getMessage());
}

if (!$conn) {
    http_response_code(503);
    error_log('[booking.php] DB connect failed: ' . mysqli_connect_error());
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

mysqli_set_charset($conn, 'utf8mb4');

/* ── 4. Read and decode JSON body ── */
$raw = file_get_contents('php://input');

if (empty($raw)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Empty request body']);
    exit;
}

$data = json_decode($raw, true);
file_put_contents(
    __DIR__ . '/debug.txt',
    date('Y-m-d H:i:s') . PHP_EOL .
    $raw . PHP_EOL . PHP_EOL,
    FILE_APPEND
);

if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
    http_response_code(400);
    error_log('[booking.php] JSON decode error: ' . json_last_error_msg() . ' | Raw: ' . substr($raw, 0, 500));
    echo json_encode(['success' => false, 'message' => 'Invalid JSON payload: ' . json_last_error_msg()]);
    exit;
}

/* ── 5. Extract and sanitise fields ── */
/* All fields are sent as strings from the form */
$consultation_type = isset($data['consultation_type']) ? trim((string)$data['consultation_type']) : '';
$first_name        = isset($data['first_name'])        ? trim((string)$data['first_name'])        : '';
$last_name         = isset($data['last_name'])         ? trim((string)$data['last_name'])         : '';
$email             = isset($data['email'])             ? trim((string)$data['email'])             : '';
$job_title         = isset($data['title'])             ? trim((string)$data['title'])             : '';
$company           = isset($data['company'])           ? trim((string)$data['company'])           : '';
$revenue           = isset($data['revenue'])           ? trim((string)$data['revenue'])           : '';
$industry          = isset($data['industry'])          ? trim((string)$data['industry'])          : '';
$country           = isset($data['country'])           ? trim((string)$data['country'])           : '';
$challenge         = isset($data['challenge'])         ? trim((string)$data['challenge'])         : '';
$interest          = isset($data['interest'])          ? trim((string)$data['interest'])          : '';
$timeline          = isset($data['timeline'])          ? trim((string)$data['timeline'])          : '';
$timezone          = isset($data['timezone'])          ? trim((string)$data['timezone'])          : '';
$slots             = isset($data['slots'])             ? trim((string)$data['slots'])             : '';

/* ── 6. Basic validation ── */
if (empty($first_name) || empty($last_name) || empty($email) || empty($consultation_type)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Required fields missing: first_name, last_name, email, consultation_type']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Invalid email address']);
    exit;
}

/* ── 7. Prepared statement INSERT ── */
$sql = "INSERT INTO bookings (
    consultation_type,
    first_name,
    last_name,
    email,
    job_title,
    company,
    revenue,
    industry,
    country,
    challenge,
    interest,
    timeline,
    timezone,
    slots
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

$stmt = mysqli_prepare($conn, $sql);

if (!$stmt) {
    http_response_code(500);
    error_log('[booking.php] mysqli_prepare failed: ' . mysqli_error($conn));
    echo json_encode(['success' => false, 'message' => 'Query preparation failed: ' . mysqli_error($conn)]);
    mysqli_close($conn);
    exit;
}

/* Bind named variables (not array elements) — required for strict by-ref binding */
mysqli_stmt_bind_param(
    $stmt,
    'ssssssssssssss',
    $consultation_type,
    $first_name,
    $last_name,
    $email,
    $job_title,
    $company,
    $revenue,
    $industry,
    $country,
    $challenge,
    $interest,
    $timeline,
    $timezone,
    $slots
);

$executed = mysqli_stmt_execute($stmt);

if ($executed) {
    $inserted_id = mysqli_insert_id($conn);
    error_log('[booking.php] Booking saved OK — id=' . $inserted_id . ' email=' . $email);
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Booking saved successfully',
        'id'      => $inserted_id
    ]);
} else {
    $err = mysqli_stmt_error($stmt);
    error_log('[booking.php] mysqli_stmt_execute failed: ' . $err . ' | email=' . $email);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $err
    ]);
}

mysqli_stmt_close($stmt);
mysqli_close($conn);
