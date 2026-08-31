-- Run this in phpMyAdmin or MySQL CLI against the `anicalls` database
-- mysql -u root anicalls < user/setup-users-table.sql

CREATE DATABASE IF NOT EXISTS `anicalls` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE `anicalls`;

CREATE TABLE IF NOT EXISTS `users` (
  `id`                       INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `name`                     VARCHAR(150)    NOT NULL,
  `email`                    VARCHAR(255)    NOT NULL,
  `password`                 VARCHAR(255)    NOT NULL,
  `email_verified_at`        DATETIME        DEFAULT NULL,
  `verification_token`       VARCHAR(64)     DEFAULT NULL,
  `reset_token`              VARCHAR(64)     DEFAULT NULL,
  `reset_token_expires_at`   DATETIME        DEFAULT NULL,
  `created_at`               TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_email` (`email`),
  INDEX `idx_verification_token` (`verification_token`),
  INDEX `idx_reset_token` (`reset_token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
