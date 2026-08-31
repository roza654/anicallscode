<?php
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'GET' || empty($_GET['date'])) {
    http_response_code(400);
    echo json_encode(['booked' => []]);
    exit;
}

define('DB_HOST', 'localhost');
define('DB_USER', 'anicalls_ai_user');
define('DB_PASS', 'Aianicalls@2026');
define('DB_NAME', 'anicalls_ai_db');

$date = trim($_GET['date']);

try {
    $conn = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
} catch (\mysqli_sql_exception $e) {
    $conn = null;
    error_log('[booked-slots.php] DB connect exception: ' . $e->getMessage());
}
if (!$conn) {
    echo json_encode(['booked' => []]);
    exit;
}

mysqli_set_charset($conn, 'utf8mb4');

$stmt = mysqli_prepare($conn, 'SELECT booking_time FROM booking_requests WHERE booking_date = ?');
mysqli_stmt_bind_param($stmt, 's', $date);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$booked = [];
while ($row = mysqli_fetch_assoc($result)) {
    $booked[] = $row['booking_time'];
}

mysqli_stmt_close($stmt);
mysqli_close($conn);

echo json_encode(['booked' => $booked]);
