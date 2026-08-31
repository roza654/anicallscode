-- Run this in phpMyAdmin or MySQL CLI against the `anicalls` database
-- mysql -u root anicalls < api/setup-booking-table.sql

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
  INDEX `idx_email`        (`email`),
  INDEX `idx_created_at`   (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Run these if the table already exists (safe to run even if columns are present):
ALTER TABLE `booking_requests`
  ADD COLUMN IF NOT EXISTS `google_event_id` VARCHAR(255) DEFAULT NULL AFTER `booking_type`,
  ADD COLUMN IF NOT EXISTS `meet_link`       VARCHAR(512) DEFAULT NULL AFTER `google_event_id`;
