-- ============================================================
-- Anicalls — bookings table setup / verification
-- Run this in phpMyAdmin or MySQL CLI to verify/create table
-- ============================================================

-- 1. Verify the table exists and check its columns
DESCRIBE bookings;

-- 2. If the table does NOT exist, create it with this exact schema:
CREATE TABLE IF NOT EXISTS `bookings` (
  `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `consultation_type` VARCHAR(100)  NOT NULL DEFAULT '',
  `first_name`        VARCHAR(100)  NOT NULL DEFAULT '',
  `last_name`         VARCHAR(100)  NOT NULL DEFAULT '',
  `email`             VARCHAR(255)  NOT NULL DEFAULT '',
  `job_title`         VARCHAR(150)  NOT NULL DEFAULT '',
  `company`           VARCHAR(150)  NOT NULL DEFAULT '',
  `revenue`           VARCHAR(50)   NOT NULL DEFAULT '',
  `industry`          VARCHAR(100)  NOT NULL DEFAULT '',
  `country`           VARCHAR(100)  NOT NULL DEFAULT '',
  `challenge`         TEXT          NOT NULL,
  `interest`          VARCHAR(150)  NOT NULL DEFAULT '',
  `timeline`          VARCHAR(100)  NOT NULL DEFAULT '',
  `timezone`          VARCHAR(100)  NOT NULL DEFAULT '',
  `slots`             VARCHAR(255)  NOT NULL DEFAULT '',
  `created_at`        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_email` (`email`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Test a manual insert to confirm columns match:
INSERT INTO `bookings`
  (consultation_type, first_name, last_name, email, job_title, company, revenue, industry, country, challenge, interest, timeline, timezone, slots)
VALUES
  ('Test', 'Test', 'User', 'test@test.com', 'CEO', 'Test Co', '$50M+', 'Banking', 'UK', 'Test challenge', 'AI Strategy', '1–3 months', 'GMT', 'Mon AM, Tue PM');

-- 4. Verify the insert worked:
SELECT * FROM bookings ORDER BY created_at DESC LIMIT 5;

-- 5. Cleanup test row:
DELETE FROM bookings WHERE email = 'test@test.com';
