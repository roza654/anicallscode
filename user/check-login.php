<?php
/* ============================================================
   ANICALLS — Session check
   Called by assests/js/booking.js before opening the booking modal
   Returns JSON: { loggedIn: bool }
   ============================================================ */

session_start();
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

echo json_encode(['loggedIn' => isset($_SESSION['user_id'])]);
