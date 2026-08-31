<?php
/* Shared mysqli connection helper for the user/ auth backend. */

require_once __DIR__ . '/config.php';

function anicalls_db() {
    static $conn = null;
    if ($conn !== null) {
        return $conn;
    }
    try {
        $conn = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    } catch (\mysqli_sql_exception $e) {
        $conn = null;
        error_log('[user/db.php] DB connect exception: ' . $e->getMessage());
    }
    if (!$conn) {
        http_response_code(503);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Database connection failed']);
        exit;
    }
    mysqli_set_charset($conn, 'utf8mb4');

    /* First-run auto-migration, mirroring the pattern already used by api/modal-booking.php */
    mysqli_query($conn, "
        CREATE TABLE IF NOT EXISTS `users` (
            `id`                     INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name`                   VARCHAR(150) NOT NULL,
            `email`                  VARCHAR(255) NOT NULL,
            `password`               VARCHAR(255) NOT NULL,
            `email_verified_at`      DATETIME     DEFAULT NULL,
            `verification_token`     VARCHAR(64)  DEFAULT NULL,
            `reset_token`            VARCHAR(64)  DEFAULT NULL,
            `reset_token_expires_at` DATETIME     DEFAULT NULL,
            `created_at`             TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_email` (`email`),
            INDEX `idx_verification_token` (`verification_token`),
            INDEX `idx_reset_token` (`reset_token`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    return $conn;
}
