-- =============================================
-- Smm Elite Panel - MySQL Database Schema
-- =============================================

-- -------------------------------------------
-- 1. Users Table
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `uid`         VARCHAR(64)  NOT NULL UNIQUE,
  `username`    VARCHAR(100) NOT NULL DEFAULT 'User',
  `email`       VARCHAR(150) NOT NULL UNIQUE,
  `phone`       VARCHAR(20)  DEFAULT NULL,
  `password`    VARCHAR(255) NOT NULL,
  `balance`     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `profile_pic` TEXT         DEFAULT NULL,
  `blocked`     TINYINT(1)   NOT NULL DEFAULT 0,
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_email` (`email`),
  INDEX `idx_uid`   (`uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------
-- 2. Categories Table
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `categories` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name`       VARCHAR(100) NOT NULL,
  `logo`       TEXT         DEFAULT NULL,
  `sort_order` INT          NOT NULL DEFAULT 99,
  `hidden`     TINYINT(1)   NOT NULL DEFAULT 0,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------
-- 3. Services Table
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `services` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `service_key` VARCHAR(20)  NOT NULL UNIQUE,
  `cat`         VARCHAR(100) NOT NULL,
  `name`        VARCHAR(255) NOT NULL,
  `rate`        DECIMAL(10,4) NOT NULL DEFAULT 0.0000,
  `min_order`   INT          NOT NULL DEFAULT 10,
  `max_order`   INT          NOT NULL DEFAULT 10000,
  `provider_id` VARCHAR(50)  DEFAULT NULL COMMENT 'API Service ID from SMMGen',
  `description` TEXT         DEFAULT NULL,
  `sort_order`  INT          NOT NULL DEFAULT 99,
  `hidden`      TINYINT(1)   NOT NULL DEFAULT 0,
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_cat` (`cat`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------
-- 4. Orders Table
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `orders` (
  `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `uid`           VARCHAR(64)   NOT NULL,
  `order_id`      VARCHAR(20)   NOT NULL UNIQUE,
  `api_order_id`  VARCHAR(50)   DEFAULT NULL,
  `service_name`  VARCHAR(255)  NOT NULL,
  `service_id`    VARCHAR(50)   DEFAULT NULL,
  `link`          TEXT          NOT NULL,
  `quantity`      INT           NOT NULL,
  `amount`        DECIMAL(12,2) NOT NULL,
  `status`        ENUM('Pending','Completed','Cancelled') NOT NULL DEFAULT 'Pending',
  `created_at`    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_uid`    (`uid`),
  INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------
-- 5. Deposits Table
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `deposits` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `uid`        VARCHAR(64)   NOT NULL,
  `method`     VARCHAR(50)   NOT NULL,
  `trx_id`     VARCHAR(30)   NOT NULL,
  `amount`     DECIMAL(12,2) NOT NULL,
  `status`     ENUM('Pending','Completed','Cancelled') NOT NULL DEFAULT 'Pending',
  `created_at` DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_uid`   (`uid`),
  INDEX `idx_trxid` (`trx_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------
-- 6. Payment Methods Table
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `payment_methods` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name`        VARCHAR(100)  NOT NULL,
  `number`      VARCHAR(30)   NOT NULL,
  `type`        VARCHAR(60)   NOT NULL DEFAULT 'Personal (Send Money)',
  `min_deposit` DECIMAL(10,2) NOT NULL DEFAULT 10.00,
  `logo`        TEXT          DEFAULT NULL,
  `hidden`      TINYINT(1)    NOT NULL DEFAULT 0,
  `sort_order`  INT           NOT NULL DEFAULT 99,
  `created_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------
-- 7. Admin Settings Table
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `admin_settings` (
  `id`                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `api_url`             VARCHAR(255) NOT NULL DEFAULT 'https://smmgen.com/api/v2',
  `api_key`             VARCHAR(255) DEFAULT NULL,
  `auto_order_enabled`  TINYINT(1)   NOT NULL DEFAULT 1,
  `min_deposit`         DECIMAL(10,2) NOT NULL DEFAULT 10.00,
  `app_logo`            TEXT         DEFAULT NULL,
  `notice`              TEXT         DEFAULT NULL,
  `updated_at`          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------
-- 8. Admin Users Table
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `admin_users` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `email`      VARCHAR(150) NOT NULL UNIQUE,
  `password`   VARCHAR(255) NOT NULL,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------
-- Default Admin Settings Row
-- -------------------------------------------
INSERT INTO `admin_settings` (`id`, `api_url`, `api_key`, `auto_order_enabled`, `min_deposit`, `app_logo`, `notice`)
VALUES (1, 'https://smmgen.com/api/v2', '', 1, 10.00, 'https://i.postimg.cc/L4Yqd4Pv/smm-elite-panel.png', 'Welcome to Smm Elite Panel! Auto-Order System Active 🔥')
ON DUPLICATE KEY UPDATE `id` = `id`;

-- -------------------------------------------
-- Default Admin User (Email: admin@smmelite.com | Password: Admin@1234)
-- Change password after first login!
-- -------------------------------------------
INSERT INTO `admin_users` (`email`, `password`)
VALUES ('admin@smmelite.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi')
ON DUPLICATE KEY UPDATE `id` = `id`;
-- Default password above is: password
-- To set your own: use PHP password_hash('YourPass', PASSWORD_BCRYPT)
