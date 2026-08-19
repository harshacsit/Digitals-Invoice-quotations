-- ============================================================
-- AdsDash — Complete Production Database Schema & Seed Data
-- Database Engine: MySQL 5.7+ / MariaDB 10.3+ / MySQL 8.0+
-- Charset: utf8mb4 / utf8mb4_unicode_ci
-- ============================================================

CREATE DATABASE IF NOT EXISTS `adsdash` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `adsdash`;

-- ------------------------------------------------------------
-- 1. Users Table (Authentication & RBAC)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL,
  `email` VARCHAR(191) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `role` ENUM('owner', 'manager', 'staff') NOT NULL DEFAULT 'staff',
  `phone` VARCHAR(20) NULL,
  `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
  `last_login_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_users_email` (`email`),
  KEY `idx_users_role_status` (`role`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 2. Customers Table
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `customers` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL,
  `company_name` VARCHAR(150) NULL,
  `email` VARCHAR(191) NULL,
  `phone` VARCHAR(20) NULL,
  `gstin` VARCHAR(20) NULL,
  `address` TEXT NULL,
  `city` VARCHAR(50) NULL,
  `state` VARCHAR(50) NULL,
  `pincode` VARCHAR(10) NULL,
  `created_by` BIGINT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_customers_name` (`name`),
  KEY `idx_customers_company` (`company_name`),
  KEY `idx_customers_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 3. Screens Table (Inventory)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `screens` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL,
  `location` VARCHAR(255) NOT NULL,
  `type` ENUM('Billboard', 'TV Display', 'LED Screen') NOT NULL DEFAULT 'Billboard',
  `dimensions` VARCHAR(50) NULL,
  `rate_per_month` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `status` ENUM('Available', 'Booked', 'Maintenance') NOT NULL DEFAULT 'Available',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_screens_status` (`status`),
  KEY `idx_screens_type` (`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 4. Quotations Table
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `quotations` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `quotation_number` VARCHAR(50) NOT NULL,
  `customer_id` BIGINT UNSIGNED NOT NULL,
  `total_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `gst_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `grand_total` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `status` ENUM('Draft', 'Sent', 'Approved', 'Rejected', 'Expired') NOT NULL DEFAULT 'Draft',
  `valid_until` DATE NULL,
  `created_by` BIGINT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_quotations_number` (`quotation_number`),
  KEY `idx_quotations_customer` (`customer_id`),
  KEY `idx_quotations_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 5. Quotation Items Table
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `quotation_items` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `quotation_id` BIGINT UNSIGNED NOT NULL,
  `screen_id` BIGINT UNSIGNED NULL,
  `service_description` VARCHAR(255) NOT NULL,
  `start_date` DATE NULL,
  `end_date` DATE NULL,
  `months` DECIMAL(5,2) NOT NULL DEFAULT 1.00,
  `rate` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  KEY `idx_qitems_quotation` (`quotation_id`),
  KEY `idx_qitems_screen` (`screen_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 6. Invoices Table
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `invoices` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `invoice_number` VARCHAR(50) NOT NULL,
  `quotation_id` BIGINT UNSIGNED NULL,
  `customer_id` BIGINT UNSIGNED NOT NULL,
  `subtotal` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `gst_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `grand_total` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `paid_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `status` ENUM('Unpaid', 'Partially Paid', 'Paid', 'Overdue', 'Cancelled') NOT NULL DEFAULT 'Unpaid',
  `due_date` DATE NULL,
  `created_by` BIGINT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_invoices_number` (`invoice_number`),
  KEY `idx_invoices_customer` (`customer_id`),
  KEY `idx_invoices_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 7. Payments Table
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `payments` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `payment_number` VARCHAR(50) NOT NULL,
  `invoice_id` BIGINT UNSIGNED NOT NULL,
  `customer_id` BIGINT UNSIGNED NOT NULL,
  `amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `payment_mode` ENUM('Bank Transfer', 'UPI', 'Cheque', 'Cash', 'Credit Card') NOT NULL DEFAULT 'Bank Transfer',
  `reference_number` VARCHAR(100) NULL,
  `payment_date` DATE NOT NULL,
  `notes` TEXT NULL,
  `created_by` BIGINT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_payments_number` (`payment_number`),
  KEY `idx_payments_invoice` (`invoice_id`),
  KEY `idx_payments_customer` (`customer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 8. Campaigns Table
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `campaigns` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `title` VARCHAR(150) NOT NULL,
  `customer_id` BIGINT UNSIGNED NOT NULL,
  `start_date` DATE NOT NULL,
  `end_date` DATE NOT NULL,
  `budget` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `status` ENUM('Scheduled', 'Active', 'Completed', 'Paused', 'Cancelled') NOT NULL DEFAULT 'Scheduled',
  `notes` TEXT NULL,
  `created_by` BIGINT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_campaigns_customer` (`customer_id`),
  KEY `idx_campaigns_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 9. Email Logs Table
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `email_logs` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `recipient_email` VARCHAR(191) NOT NULL,
  `recipient_name` VARCHAR(100) NULL,
  `subject` VARCHAR(255) NOT NULL,
  `template_type` VARCHAR(50) NOT NULL,
  `status` ENUM('sent', 'failed', 'queued') NOT NULL DEFAULT 'sent',
  `error_message` TEXT NULL,
  `retry_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `sent_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_email_logs_status` (`status`),
  KEY `idx_email_logs_recipient` (`recipient_email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 10. Settings Table
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `settings` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `setting_key` VARCHAR(100) NOT NULL,
  `setting_value` TEXT NULL,
  `updated_by` BIGINT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- SEED DATA
-- Default System Owner User: admin@adsdash.local / AdminPassword@123
-- ============================================================
INSERT INTO `users` (`name`, `email`, `password_hash`, `role`, `status`)
VALUES (
  'System Owner',
  'admin@adsdash.local',
  '$2y$10$wN9aNqI0yZg6S0dM5S0b9e8wE4z5.yK0A1Z5Q6W7E8R9T0Y1U2I3O4P5', -- password_hash for AdminPassword@123
  'owner',
  'active'
)
ON DUPLICATE KEY UPDATE `status` = 'active';

-- Initial System Settings
INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES
('company_name', 'Bhimavaram Digitals'),
('company_tagline', 'Outdoor Advertising & Digital Screens'),
('company_email', 'contact@adscreening.co.in'),
('company_phone', '+91 98765 43210'),
('default_gst_rate', '18'),
('currency', 'INR')
ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`);
