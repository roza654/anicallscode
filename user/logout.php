<?php
/* ============================================================
   ANICALLS — Logout
   Called by assests/js/booking.js (Sign Out button on confirmation panel)
   Returns JSON: { success: true }
   ============================================================ */

session_start();
$_SESSION = [];
session_unset();
session_destroy();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
echo json_encode(['success' => true]);
