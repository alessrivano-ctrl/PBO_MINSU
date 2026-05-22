CREATE DATABASE IF NOT EXISTS `u317918921_bcenter` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `u317918921_bcenter`;

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `business_center_content`;
DROP TABLE IF EXISTS `system_settings`;
DROP TABLE IF EXISTS `proposals`;
DROP TABLE IF EXISTS `office_logbook`;
DROP TABLE IF EXISTS `cash_transactions`;
DROP TABLE IF EXISTS `inventory_stock_movements`;
DROP TABLE IF EXISTS `inventory_stock_batches`;
DROP TABLE IF EXISTS `project_entry_meta`;
DROP TABLE IF EXISTS `project_entries`;
DROP TABLE IF EXISTS `project_account_meta`;
DROP TABLE IF EXISTS `project_accounts`;
DROP TABLE IF EXISTS `project_categories`;
DROP TABLE IF EXISTS `sales`;
DROP TABLE IF EXISTS `products`;
DROP TABLE IF EXISTS `archived_records`;
DROP TABLE IF EXISTS `system_error_logs`;
DROP TABLE IF EXISTS `audit_logs`;
DROP TABLE IF EXISTS `session_logs`;
DROP TABLE IF EXISTS `login_attempts`;
DROP TABLE IF EXISTS `people`;
DROP TABLE IF EXISTS `users`;

SET FOREIGN_KEY_CHECKS = 1;

DROP PROCEDURE IF EXISTS `sp_sales_summary_by_period`;
DROP PROCEDURE IF EXISTS `sp_cash_summary_by_period`;
DROP PROCEDURE IF EXISTS `sp_dashboard_summary`;

CREATE TABLE `users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(50) NOT NULL UNIQUE,
    `password_hash` VARCHAR(255) NOT NULL,
    `full_name` VARCHAR(120) NOT NULL,
    `role` ENUM('admin', 'staff') NOT NULL DEFAULT 'staff',
    `status` ENUM('pending', 'approved', 'suspended') NOT NULL DEFAULT 'approved',
    `approved_by` INT DEFAULT NULL,
    `approved_at` DATETIME DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `people` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `full_name` VARCHAR(150) NOT NULL,
    `person_code` VARCHAR(80) DEFAULT NULL,
    `department` VARCHAR(120) DEFAULT NULL,
    `role_or_position` VARCHAR(120) DEFAULT NULL,
    `contact_info` VARCHAR(160) DEFAULT NULL,
    `status` ENUM('pending', 'approved', 'inactive') NOT NULL DEFAULT 'pending',
    `created_by` INT DEFAULT NULL,
    `approved_by` INT DEFAULT NULL,
    `approved_at` DATETIME DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_people_status_name` (`status`, `full_name`),
    INDEX `idx_people_code` (`person_code`),
    INDEX `idx_people_department_role` (`department`, `role_or_position`),
    CONSTRAINT `fk_people_created_by` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_people_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `login_attempts` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(120) NOT NULL,
    `ip_address` VARCHAR(45) NOT NULL,
    `was_successful` TINYINT(1) NOT NULL DEFAULT 0,
    `attempted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_login_attempts_lookup` (`username`, `ip_address`, `was_successful`, `attempted_at`),
    INDEX `idx_login_attempts_cleanup` (`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `session_logs` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT DEFAULT NULL,
    `session_id` VARCHAR(128) NOT NULL,
    `event` ENUM('login', 'logout', 'timeout', 'fingerprint_mismatch') NOT NULL,
    `ip_address` VARCHAR(45) DEFAULT NULL,
    `user_agent` VARCHAR(255) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_session_user_created` (`user_id`, `created_at`),
    INDEX `idx_session_id` (`session_id`),
    CONSTRAINT `fk_session_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `audit_logs` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT DEFAULT NULL,
    `action` VARCHAR(80) NOT NULL,
    `module` VARCHAR(80) NOT NULL,
    `entity_type` VARCHAR(80) DEFAULT NULL,
    `entity_id` VARCHAR(80) DEFAULT NULL,
    `details` TEXT DEFAULT NULL,
    `ip_address` VARCHAR(45) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_audit_created_at` (`created_at`),
    INDEX `idx_audit_user_module` (`user_id`, `module`, `created_at`),
    CONSTRAINT `fk_audit_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `system_error_logs` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT DEFAULT NULL,
    `severity` ENUM('notice', 'warning', 'error', 'critical') NOT NULL DEFAULT 'error',
    `message` VARCHAR(255) NOT NULL,
    `context` TEXT DEFAULT NULL,
    `ip_address` VARCHAR(45) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_error_created_at` (`created_at`),
    INDEX `idx_error_severity` (`severity`, `created_at`),
    CONSTRAINT `fk_error_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `archived_records` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `source_table` VARCHAR(80) NOT NULL,
    `source_id` VARCHAR(80) NOT NULL,
    `record_data` JSON DEFAULT NULL,
    `archived_by` INT DEFAULT NULL,
    `archived_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_archive_source` (`source_table`, `source_id`),
    CONSTRAINT `fk_archive_user` FOREIGN KEY (`archived_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `products` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `sku` VARCHAR(100) UNIQUE,
    `name` VARCHAR(150) NOT NULL,
    `unit` VARCHAR(40) DEFAULT NULL,
    `category` ENUM('school_supply', 'id_supplies', 'id_services', 'printing', 'photocopy', 'other') NOT NULL DEFAULT 'school_supply',
    `category_name` VARCHAR(120) DEFAULT NULL,
    `product_group` ENUM('product', 'igp', 'service') NOT NULL DEFAULT 'product',
    `type` ENUM('item', 'service') NOT NULL DEFAULT 'item',
    `cost_price` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `selling_price` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `stock_qty` INT NOT NULL DEFAULT 0,
    `low_stock_threshold` INT NOT NULL DEFAULT 5,
    `is_consumable` TINYINT(1) NOT NULL DEFAULT 0,
    `requires_expiration` TINYINT(1) NOT NULL DEFAULT 0,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_products_active_name` (`is_active`, `name`),
    INDEX `idx_products_active_category` (`is_active`, `category`),
    INDEX `idx_products_category_name` (`category_name`),
    INDEX `idx_products_active_group` (`is_active`, `product_group`),
    INDEX `idx_products_active_sku` (`is_active`, `sku`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `sales` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `sale_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `product_id` INT NOT NULL,
    `quantity` INT NOT NULL,
    `unit_price` DECIMAL(12,2) NOT NULL,
    `unit_cost` DECIMAL(12,2) NOT NULL,
    `total_amount` DECIMAL(12,2) NOT NULL,
    `total_cost` DECIMAL(12,2) NOT NULL,
    `total_profit` DECIMAL(12,2) NOT NULL,
    `or_number` VARCHAR(100) DEFAULT NULL,
    `notes` VARCHAR(255) DEFAULT NULL,
    `created_by` INT DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_sales_date` (`sale_date`),
    INDEX `idx_sales_product` (`product_id`),
    INDEX `idx_sales_date_product` (`sale_date`, `product_id`),
    CONSTRAINT `fk_sales_product` FOREIGN KEY (`product_id`) REFERENCES `products`(`id`),
    CONSTRAINT `fk_sales_user` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `inventory_stock_batches` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `product_id` INT NOT NULL,
    `batch_code` VARCHAR(100) DEFAULT NULL,
    `received_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `expiration_date` DATE DEFAULT NULL,
    `quantity_received` INT NOT NULL,
    `quantity_remaining` INT NOT NULL,
    `unit_cost` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `source_type` VARCHAR(40) NOT NULL DEFAULT 'stock_in',
    `reference_no` VARCHAR(100) DEFAULT NULL,
    `notes` VARCHAR(255) DEFAULT NULL,
    `created_by` INT DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_stock_batches_fifo` (`product_id`, `quantity_remaining`, `received_date`, `id`),
    INDEX `idx_stock_batches_product_date` (`product_id`, `received_date`),
    CONSTRAINT `fk_stock_batch_product` FOREIGN KEY (`product_id`) REFERENCES `products`(`id`),
    CONSTRAINT `fk_stock_batch_user` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `inventory_stock_movements` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `product_id` INT NOT NULL,
    `batch_id` INT DEFAULT NULL,
    `sale_id` INT DEFAULT NULL,
    `movement_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `movement_type` ENUM('opening', 'stock_in', 'stock_out', 'sale', 'adjustment', 'damaged', 'expired', 'disposal', 'consumption', 'refill') NOT NULL,
    `quantity_change` INT NOT NULL,
    `unit_cost` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `total_cost` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `reference_no` VARCHAR(100) DEFAULT NULL,
    `notes` VARCHAR(255) DEFAULT NULL,
    `created_by` INT DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_stock_movements_product_date` (`product_id`, `movement_date`),
    INDEX `idx_stock_movements_batch` (`batch_id`),
    INDEX `idx_stock_movements_sale` (`sale_id`),
    CONSTRAINT `fk_stock_movement_product` FOREIGN KEY (`product_id`) REFERENCES `products`(`id`),
    CONSTRAINT `fk_stock_movement_batch` FOREIGN KEY (`batch_id`) REFERENCES `inventory_stock_batches`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_stock_movement_sale` FOREIGN KEY (`sale_id`) REFERENCES `sales`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_stock_movement_user` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `project_categories` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `slug` VARCHAR(80) NOT NULL UNIQUE,
    `name` VARCHAR(120) NOT NULL,
    `description` VARCHAR(255) DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `project_accounts` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `category_id` INT NOT NULL,
    `person_id` INT DEFAULT NULL,
    `account_name` VARCHAR(150) NOT NULL,
    `code` VARCHAR(80) DEFAULT NULL,
    `contact_name` VARCHAR(120) DEFAULT NULL,
    `start_date` DATE DEFAULT NULL,
    `next_due_date` DATE DEFAULT NULL,
    `expected_amount` DECIMAL(12,2) DEFAULT NULL,
    `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    `notes` VARCHAR(255) DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_project_account_due` (`next_due_date`),
    INDEX `idx_project_account_person` (`person_id`),
    INDEX `idx_project_account_category_status` (`category_id`, `status`),
    INDEX `idx_project_account_name_code` (`account_name`, `code`),
    CONSTRAINT `fk_project_account_category` FOREIGN KEY (`category_id`) REFERENCES `project_categories`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `project_account_meta` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `account_id` INT NOT NULL,
    `meta_key` VARCHAR(80) NOT NULL,
    `meta_value` VARCHAR(255) DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_project_account_meta` (`account_id`, `meta_key`),
    INDEX `idx_project_account_meta_key` (`meta_key`),
    CONSTRAINT `fk_project_account_meta_account` FOREIGN KEY (`account_id`) REFERENCES `project_accounts`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `project_entries` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `category_id` INT NOT NULL,
    `account_id` INT DEFAULT NULL,
    `entry_datetime` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `entry_type` ENUM('income', 'expense', 'production', 'harvest', 'payment', 'monitoring', 'other') NOT NULL DEFAULT 'monitoring',
    `quantity` DECIMAL(12,2) DEFAULT NULL,
    `unit` VARCHAR(30) DEFAULT NULL,
    `amount` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `reference_no` VARCHAR(100) DEFAULT NULL,
    `notes` VARCHAR(255) DEFAULT NULL,
    `created_by` INT DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_project_entries_datetime` (`entry_datetime`),
    INDEX `idx_project_entries_category` (`category_id`),
    INDEX `idx_project_entries_category_datetime` (`category_id`, `entry_datetime`),
    CONSTRAINT `fk_project_entry_category` FOREIGN KEY (`category_id`) REFERENCES `project_categories`(`id`),
    CONSTRAINT `fk_project_entry_account` FOREIGN KEY (`account_id`) REFERENCES `project_accounts`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_project_entry_user` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `project_entry_meta` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `entry_id` INT NOT NULL,
    `meta_key` VARCHAR(80) NOT NULL,
    `meta_value` VARCHAR(255) DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_project_entry_meta` (`entry_id`, `meta_key`),
    INDEX `idx_project_entry_meta_key` (`meta_key`),
    CONSTRAINT `fk_project_entry_meta_entry` FOREIGN KEY (`entry_id`) REFERENCES `project_entries`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `cash_transactions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `txn_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `direction` ENUM('in', 'out') NOT NULL,
    `source_module` VARCHAR(80) NOT NULL DEFAULT 'manual',
    `project_entry_id` INT DEFAULT NULL,
    `amount` DECIMAL(12,2) NOT NULL,
    `or_number` VARCHAR(100) DEFAULT NULL,
    `description` VARCHAR(255) DEFAULT NULL,
    `created_by` INT DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_cash_date` (`txn_date`),
    INDEX `idx_cash_project_entry` (`project_entry_id`),
    INDEX `idx_cash_source_direction_date` (`source_module`, `direction`, `txn_date`),
    INDEX `idx_cash_or_number` (`or_number`),
    CONSTRAINT `fk_cash_project_entry` FOREIGN KEY (`project_entry_id`) REFERENCES `project_entries`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_cash_user` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `office_logbook` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `person_id` INT DEFAULT NULL,
    `log_date` DATE NOT NULL,
    `time_in` TIME NOT NULL,
    `time_out` TIME DEFAULT NULL,
    `student_name` VARCHAR(120) NOT NULL,
    `student_id` VARCHAR(60) DEFAULT NULL,
    `purpose` VARCHAR(255) DEFAULT NULL,
    `created_by` INT DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_logbook_date` (`log_date`),
    INDEX `idx_logbook_person` (`person_id`),
    INDEX `idx_logbook_student_name` (`student_name`),
    INDEX `idx_logbook_student_id` (`student_id`),
    CONSTRAINT `fk_logbook_user` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `proposals` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `proposer_id` INT DEFAULT NULL,
    `title` VARCHAR(180) NOT NULL,
    `proposer_name` VARCHAR(120) NOT NULL,
    `department` VARCHAR(120) DEFAULT NULL,
    `submitted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `status` ENUM('submitted', 'under_review', 'approved', 'rejected', 'implemented') NOT NULL DEFAULT 'submitted',
    `estimated_budget` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `target_date` DATE DEFAULT NULL,
    `summary` TEXT DEFAULT NULL,
    `admin_notes` TEXT DEFAULT NULL,
    `created_by` INT DEFAULT NULL,
    `reviewed_by` INT DEFAULT NULL,
    `reviewed_at` DATETIME DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_proposals_status` (`status`, `submitted_at`),
    INDEX `idx_proposals_proposer` (`proposer_id`),
    CONSTRAINT `fk_proposal_created_by` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_proposal_reviewed_by` FOREIGN KEY (`reviewed_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `business_center_content` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `section_key` VARCHAR(80) NOT NULL UNIQUE,
    `title` VARCHAR(180) NOT NULL,
    `body` TEXT DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `updated_by` INT DEFAULT NULL,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_bc_active_section` (`is_active`, `section_key`),
    CONSTRAINT `fk_bc_content_user` FOREIGN KEY (`updated_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `system_settings` (
    `setting_key` VARCHAR(100) NOT NULL PRIMARY KEY,
    `setting_value` TEXT DEFAULT NULL,
    `updated_by` INT DEFAULT NULL,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_system_settings_updated_by` (`updated_by`),
    CONSTRAINT `fk_system_settings_user` FOREIGN KEY (`updated_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `users` (`username`, `password_hash`, `full_name`, `role`, `status`, `approved_at`) VALUES
('admin', '$2y$10$n4fI2lQObVZvEcLJdgU8KewwqvVj3a9ipDVKFL09aYBJ5jAfJ1UrS', 'BPO Admin', 'admin', 'approved', NOW()),
('staff', '$2y$10$xWFpb0iGIFWHD/4BK.EDPuHuZSGxetWWHoRLpnlwRZGzdBzrLTR1a', 'BPO Staff', 'staff', 'approved', NOW());

INSERT INTO `project_categories` (`slug`, `name`, `description`) VALUES
('business-center', 'Business Center', 'Business Center operations, services, income, expenses, and monitoring.'),
('fishpond', 'Fishpond', 'Fishpond monitoring, harvest tracking, and fishpond expenses/income.'),
('photocopy', 'Photocopy Services', 'Photocopy service activity tracked as a project category.'),
('printing', 'Printing Services', 'Printing service activity tracked as a project category.'),
('proposal-management', 'Proposal Management', 'Submitted proposals, approval follow-up, and implementation monitoring.'),
('rental', 'Rental and Stalls', 'School stall rentals and renewal payment monitoring.'),
('toga', 'Toga', 'Toga release, deposit, return, and forfeiture monitoring.');

INSERT INTO `products` (`sku`, `name`, `category`, `category_name`, `product_group`, `type`, `cost_price`, `selling_price`, `stock_qty`, `low_stock_threshold`, `is_consumable`, `requires_expiration`) VALUES
('A4-BOND', 'A4 Bond Paper Ream', 'school_supply', NULL, 'product', 'item', 180.00, 235.00, 12, 10, 1, 0),
('PEN-BLK', 'Black Ballpen', 'school_supply', NULL, 'product', 'item', 8.00, 12.00, 37, 20, 0, 0),
('ID-LACE-001', 'ID Lace', 'id_supplies', NULL, 'product', 'item', 10.00, 20.00, 0, 10, 0, 0),
('ID-CARD-001', 'ID Card', 'id_supplies', NULL, 'product', 'item', 20.00, 40.00, 0, 10, 0, 0),
('ID-PRINT-001', 'ID Printing', 'id_services', NULL, 'service', 'service', 15.00, 35.00, 0, 0, 0, 0),
('ID-REPLACE-001', 'ID Replacement', 'id_services', NULL, 'service', 'service', 25.00, 60.00, 0, 0, 0, 0),
('PRINT-BW', 'Black and White Printing', 'printing', NULL, 'service', 'service', 1.00, 3.00, 0, 0, 0, 0),
('PRINT-COLOR', 'Color Printing', 'printing', NULL, 'service', 'service', 5.00, 10.00, 0, 0, 0, 0),
('PHOTO-BW', 'Photocopy - Black and White', 'photocopy', NULL, 'service', 'service', 0.75, 2.00, 0, 0, 0, 0),
('FOOD-BAGOONG-250G', 'Bagoong 250g Jar', 'other', 'Food', 'product', 'item', 55.00, 75.00, 18, 6, 1, 1),
('FOOD-BISCUIT-PACK', 'Assorted Biscuit Pack', 'other', 'Food', 'product', 'item', 12.00, 18.00, 31, 10, 1, 1),
('INK-BLK-REFILL', 'Xerox Black Refill Ink', 'other', 'Ink', 'product', 'item', 260.00, 350.00, 10, 3, 1, 1),
('INK-CYAN-REFILL', 'Xerox Cyan Refill Ink', 'other', 'Ink', 'product', 'item', 285.00, 375.00, 6, 2, 1, 1),
('COUPON-A4-500', 'A4 Coupon Bond 500 Sheets', 'school_supply', 'Paper', 'product', 'item', 145.00, 190.00, 420, 80, 1, 0);

INSERT INTO `inventory_stock_batches` (`product_id`, `batch_code`, `received_date`, `expiration_date`, `quantity_received`, `quantity_remaining`, `unit_cost`, `source_type`, `reference_no`, `notes`)
SELECT `id`, 'REAL-A4-20260215', '2026-02-15 08:00:00', NULL, 20, 12, 180.00, 'opening_balance', 'REAL-OPEN-A4-20260215', 'Opening batch with later paper consumption history' FROM `products` WHERE `sku` = 'A4-BOND'
UNION ALL
SELECT `id`, 'REAL-PEN-20260302', '2026-03-02 08:00:00', NULL, 42, 37, 8.00, 'opening_balance', 'REAL-OPEN-PEN-20260302', 'Opening batch with damaged pen removal history' FROM `products` WHERE `sku` = 'PEN-BLK'
UNION ALL
SELECT `id`, 'REAL-BAGOONG-20260501', '2026-05-01 09:00:00', '2026-05-25', 21, 18, 55.00, 'stock_in', 'PO-FOOD-20260501', 'Food jar batch used by expiring-soon and disposal records' FROM `products` WHERE `sku` = 'FOOD-BAGOONG-250G'
UNION ALL
SELECT `id`, 'REAL-BISCUIT-20260420', '2026-04-20 09:30:00', '2026-05-20', 35, 31, 12.00, 'stock_in', 'PO-FOOD-20260420', 'Snack batch with expired item removal history' FROM `products` WHERE `sku` = 'FOOD-BISCUIT-PACK'
UNION ALL
SELECT `id`, 'REAL-INK-BLK-20260501', '2026-05-01 10:00:00', '2026-06-10', 12, 10, 260.00, 'stock_in', 'PO-INK-20260501', 'Ink stock with Xerox refill history' FROM `products` WHERE `sku` = 'INK-BLK-REFILL'
UNION ALL
SELECT `id`, 'REAL-INK-CYAN-20260501', '2026-05-01 10:00:00', '2026-07-15', 7, 6, 285.00, 'stock_in', 'PO-INK-20260501', 'Color ink stock with Xerox refill history' FROM `products` WHERE `sku` = 'INK-CYAN-REFILL'
UNION ALL
SELECT `id`, 'REAL-COUPON-20260412', '2026-04-12 08:30:00', NULL, 500, 420, 145.00, 'stock_in', 'PO-PAPER-20260412', 'Coupon bond stock with service consumption history' FROM `products` WHERE `sku` = 'COUPON-A4-500';

INSERT INTO `inventory_stock_movements` (`product_id`, `batch_id`, `movement_date`, `movement_type`, `quantity_change`, `unit_cost`, `total_cost`, `reference_no`, `notes`)
SELECT `b`.`product_id`, `b`.`id`, `b`.`received_date`, 'opening', `b`.`quantity_received`, `b`.`unit_cost`, `b`.`quantity_received` * `b`.`unit_cost`, `b`.`reference_no`, CONCAT('Opening stock for ', `b`.`batch_code`)
FROM `inventory_stock_batches` `b`
WHERE `b`.`batch_code` LIKE 'REAL-%';

INSERT INTO `inventory_stock_movements` (`product_id`, `batch_id`, `sale_id`, `movement_date`, `movement_type`, `quantity_change`, `unit_cost`, `total_cost`, `reference_no`, `notes`)
SELECT `product_id`, `id`, NULL, '2026-05-07 10:15:00', 'consumption', -8, `unit_cost`, `unit_cost` * 8, 'USE-PRINT-20260507', 'Consumed A4 bond paper for business center print services' FROM `inventory_stock_batches` WHERE `batch_code` = 'REAL-A4-20260215'
UNION ALL
SELECT `product_id`, `id`, NULL, '2026-05-09 14:20:00', 'damaged', -5, `unit_cost`, `unit_cost` * 5, 'DMG-PEN-20260509', 'Leaking or broken pens removed from sellable stock' FROM `inventory_stock_batches` WHERE `batch_code` = 'REAL-PEN-20260302'
UNION ALL
SELECT `product_id`, `id`, NULL, '2026-05-14 16:10:00', 'disposal', -3, `unit_cost`, `unit_cost` * 3, 'DISP-FOOD-20260514', 'Expired or cracked bagoong jars disposed and no longer sellable' FROM `inventory_stock_batches` WHERE `batch_code` = 'REAL-BAGOONG-20260501'
UNION ALL
SELECT `product_id`, `id`, NULL, '2026-05-10 15:45:00', 'expired', -4, `unit_cost`, `unit_cost` * 4, 'EXP-SNACK-20260510', 'Expired biscuit packs removed before sale' FROM `inventory_stock_batches` WHERE `batch_code` = 'REAL-BISCUIT-20260420'
UNION ALL
SELECT `product_id`, `id`, NULL, '2026-05-11 09:30:00', 'refill', -2, `unit_cost`, `unit_cost` * 2, 'XRX-INK-20260511', 'Installed black ink refill in Xerox machine XRX-01' FROM `inventory_stock_batches` WHERE `batch_code` = 'REAL-INK-BLK-20260501'
UNION ALL
SELECT `product_id`, `id`, NULL, '2026-05-12 09:45:00', 'refill', -1, `unit_cost`, `unit_cost`, 'XRX-INK-20260512', 'Installed cyan ink refill in Xerox machine XRX-01' FROM `inventory_stock_batches` WHERE `batch_code` = 'REAL-INK-CYAN-20260501'
UNION ALL
SELECT `product_id`, `id`, NULL, '2026-05-13 13:00:00', 'consumption', -80, `unit_cost`, `unit_cost` * 80, 'USE-COUPON-20260513', 'Coupon bond consumed for print service and office forms' FROM `inventory_stock_batches` WHERE `batch_code` = 'REAL-COUPON-20260412';

INSERT INTO `business_center_content` (`section_key`, `title`, `body`) VALUES
('hero', 'Production and Business Operation Services', 'Sales, inventory, cash flow, rentals, fishpond operations, proposal requests, logbook entries, and official reports in one record management system.'),
('mission_vision', 'Mission and Vision', 'Vision: The Mindoro State University is a center of excellence in agriculture and fishery, science, technology, culture and education of globally competitive lifelong learners in a diverse yet cohesive society.\n\nMission: The University commits to produce 21st-century skilled lifelong learners and generates and commercializes innovative technologies by providing excellent and relevant services in instruction, research, extension, and production through industry-driven curricula, collaboration, internationalization, and continual organizational growth for sustainable development.'),
('services', 'Campus Services', 'Daily operation tools for the campus business center and income-generating projects.'),
('features', 'What the System Helps Manage', 'Sales records and POS transactions\nCash in, cash out, and net cash monitoring\nInventory catalog, low stock alerts, and stock ledger\nFishpond monitoring, harvest income, and expense records\nStall rentals, toga releases, payments, and overdue records\nProposal requests and administrative approval workflow\nOffice logbook entries for visits and service requests\nPrintable official reports for campus operations'),
('contact', 'Visit the Business Center', 'Mindoro State University Bongabong Campus Production and Business Operation Office.'),
('footer', 'Production and Business Operation Record Management System', 'Official campus operations records for sales, inventory, cash flow, projects, rentals, proposals, logbook, reports, and audit monitoring.');

INSERT INTO `system_settings` (`setting_key`, `setting_value`) VALUES
('organization.university_name', 'Mindoro State University'),
('organization.campus_name', 'Bongabong Campus'),
('organization.office_name', 'Production and Business Operation'),
('organization.system_name', 'Production and Business Operation Record Management System'),
('organization.logo_path', 'assets/images/logo.png'),
('organization.address', ''),
('organization.contact_information', ''),
('display.sidebar_default_state', 'expanded'),
('display.default_table_rows', '10'),
('display.dashboard_default_range', 'daily'),
('display.theme_color', 'green-gold'),
('reports.receipt_header', 'Mindoro State University Bongabong Campus'),
('reports.or_number_format', 'OR-{YYYY}-{0000}'),
('reports.prepared_by_default', ''),
('reports.reviewed_by_default', 'Department Head / Supervisor'),
('reports.approved_by_default', 'System Administrator'),
('reports.footer_notes', 'Generated by the Production and Business Operation Record Management System.'),
('reports.confidentiality_note', 'This document contains sensitive institutional information. Handle with appropriate care.'),
('security.maximum_login_attempts', '3'),
('security.account_lock_duration', '15'),
('security.session_timeout', '30'),
('security.password_minimum_length', '8'),
('security.require_strong_password', '1'),
('security.enable_session_logs', '1');

CREATE OR REPLACE VIEW `v_daily_sales_summary` AS
SELECT DATE(`s`.`sale_date`) AS `report_date`, COUNT(*) AS `total_sales`, COALESCE(SUM(`s`.`quantity`), 0) AS `units_sold`, COALESCE(SUM(`s`.`total_amount`), 0) AS `revenue`, COALESCE(SUM(`s`.`total_cost`), 0) AS `cost`, COALESCE(SUM(`s`.`total_profit`), 0) AS `profit`
FROM `sales` `s`
GROUP BY DATE(`s`.`sale_date`);

CREATE OR REPLACE VIEW `v_cashflow_summary` AS
SELECT DATE(`ct`.`txn_date`) AS `report_date`, `ct`.`source_module`, COALESCE(SUM(CASE WHEN `ct`.`direction` = 'in' THEN `ct`.`amount` ELSE 0 END), 0) AS `cash_in`, COALESCE(SUM(CASE WHEN `ct`.`direction` = 'out' THEN `ct`.`amount` ELSE 0 END), 0) AS `cash_out`, COALESCE(SUM(CASE WHEN `ct`.`direction` = 'in' THEN `ct`.`amount` ELSE -`ct`.`amount` END), 0) AS `net_cash`
FROM `cash_transactions` `ct`
GROUP BY DATE(`ct`.`txn_date`), `ct`.`source_module`;

CREATE OR REPLACE VIEW `v_project_category_summary` AS
SELECT `pc`.`id` AS `category_id`, `pc`.`slug`, `pc`.`name`, COALESCE(SUM(CASE WHEN `pe`.`entry_type` IN ('income', 'payment', 'harvest') THEN `pe`.`amount` ELSE 0 END), 0) AS `income`, COALESCE(SUM(CASE WHEN `pe`.`entry_type` = 'expense' THEN `pe`.`amount` ELSE 0 END), 0) AS `expense`, COALESCE(SUM(CASE WHEN `pe`.`entry_type` IN ('income', 'payment', 'harvest') THEN `pe`.`amount` WHEN `pe`.`entry_type` = 'expense' THEN -`pe`.`amount` ELSE 0 END), 0) AS `net_income`
FROM `project_categories` `pc`
LEFT JOIN `project_entries` `pe` ON `pe`.`category_id` = `pc`.`id`
WHERE `pc`.`is_active` = 1
GROUP BY `pc`.`id`, `pc`.`slug`, `pc`.`name`;

CREATE OR REPLACE VIEW `v_inventory_category_summary` AS
SELECT `category`, COUNT(*) AS `product_count`, COALESCE(SUM(CASE WHEN `type` = 'item' THEN `stock_qty` ELSE 0 END), 0) AS `stock_units`, COALESCE(SUM(CASE WHEN `type` = 'item' THEN `stock_qty` * `selling_price` ELSE 0 END), 0) AS `stock_value`, COALESCE(SUM(CASE WHEN `type` = 'item' AND `stock_qty` <= `low_stock_threshold` THEN 1 ELSE 0 END), 0) AS `low_stock_count`
FROM `products`
WHERE `is_active` = 1
GROUP BY `category`;

CREATE OR REPLACE VIEW `v_inventory_group_summary` AS
SELECT `product_group`, COUNT(*) AS `product_count`, COALESCE(SUM(CASE WHEN `type` = 'item' THEN `stock_qty` ELSE 0 END), 0) AS `stock_units`, COALESCE(SUM(CASE WHEN `type` = 'item' THEN `stock_qty` * `selling_price` ELSE 0 END), 0) AS `stock_value`, COALESCE(SUM(CASE WHEN `type` = 'item' AND `stock_qty` <= `low_stock_threshold` THEN 1 ELSE 0 END), 0) AS `low_stock_count`
FROM `products`
WHERE `is_active` = 1
GROUP BY `product_group`;

CREATE OR REPLACE VIEW `v_overdue_project_accounts` AS
SELECT `pa`.`id`, `pa`.`account_name`, `pa`.`code`, `pa`.`contact_name`, `pa`.`next_due_date`, `pa`.`expected_amount`, `pc`.`slug` AS `category_slug`, `pc`.`name` AS `category_name`
FROM `project_accounts` `pa`
INNER JOIN `project_categories` `pc` ON `pc`.`id` = `pa`.`category_id`
WHERE `pa`.`status` = 'active' AND `pa`.`next_due_date` IS NOT NULL AND `pa`.`next_due_date` < CURDATE();

DELIMITER //

CREATE PROCEDURE `sp_sales_summary_by_period`(IN `p_start` DATETIME, IN `p_end_exclusive` DATETIME)
BEGIN
    SELECT COUNT(*) AS `total_sales`, COALESCE(SUM(`total_amount`), 0) AS `revenue`, COALESCE(SUM(`total_cost`), 0) AS `cost`, COALESCE(SUM(`total_profit`), 0) AS `profit`
    FROM `sales`
    WHERE `sale_date` >= `p_start` AND `sale_date` < `p_end_exclusive`;
END//

CREATE PROCEDURE `sp_cash_summary_by_period`(IN `p_start` DATETIME, IN `p_end_exclusive` DATETIME)
BEGIN
    SELECT COALESCE(SUM(CASE WHEN `direction` = 'in' THEN `amount` ELSE 0 END), 0) AS `cash_in`, COALESCE(SUM(CASE WHEN `direction` = 'out' THEN `amount` ELSE 0 END), 0) AS `cash_out`, COALESCE(SUM(CASE WHEN `direction` = 'in' THEN `amount` ELSE -`amount` END), 0) AS `net_cash`
    FROM `cash_transactions`
    WHERE `txn_date` >= `p_start` AND `txn_date` < `p_end_exclusive`;
END//

CREATE PROCEDURE `sp_dashboard_summary`()
BEGIN
    SELECT
        (SELECT COALESCE(SUM(`total_amount`), 0) FROM `sales` WHERE `sale_date` >= CURDATE() AND `sale_date` < DATE_ADD(CURDATE(), INTERVAL 1 DAY)) AS `today_sales`,
        (SELECT COALESCE(SUM(`total_profit`), 0) FROM `sales` WHERE `sale_date` >= CURDATE() AND `sale_date` < DATE_ADD(CURDATE(), INTERVAL 1 DAY)) AS `today_profit`,
        (SELECT COALESCE(SUM(CASE WHEN `direction` = 'in' THEN `amount` ELSE 0 END), 0) FROM `cash_transactions` WHERE `txn_date` >= CURDATE() AND `txn_date` < DATE_ADD(CURDATE(), INTERVAL 1 DAY)) AS `today_cash_in`,
        (SELECT COALESCE(SUM(CASE WHEN `direction` = 'out' THEN `amount` ELSE 0 END), 0) FROM `cash_transactions` WHERE `txn_date` >= CURDATE() AND `txn_date` < DATE_ADD(CURDATE(), INTERVAL 1 DAY)) AS `today_cash_out`,
        (SELECT COUNT(*) FROM `v_overdue_project_accounts` WHERE `category_slug` = 'rental') AS `overdue_rentals`,
        (SELECT COALESCE(SUM(`low_stock_count`), 0) FROM `v_inventory_category_summary`) AS `low_stock_items`;
END//

CREATE TRIGGER `trg_products_validate_insert`
BEFORE INSERT ON `products`
FOR EACH ROW
BEGIN
    IF NEW.`cost_price` < 0 OR NEW.`selling_price` < 0 OR NEW.`stock_qty` < 0 OR NEW.`low_stock_threshold` < 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Product numeric fields cannot be negative';
    END IF;
    IF NEW.`category` IN ('printing', 'photocopy', 'id_services') OR NEW.`product_group` = 'service' THEN
        SET NEW.`type` = 'service';
        SET NEW.`product_group` = 'service';
    END IF;
    IF NEW.`type` = 'service' THEN
        SET NEW.`product_group` = 'service';
        SET NEW.`stock_qty` = 0;
    END IF;
END//

CREATE TRIGGER `trg_products_validate_update`
BEFORE UPDATE ON `products`
FOR EACH ROW
BEGIN
    IF NEW.`cost_price` < 0 OR NEW.`selling_price` < 0 OR NEW.`stock_qty` < 0 OR NEW.`low_stock_threshold` < 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Product numeric fields cannot be negative';
    END IF;
    IF NEW.`category` IN ('printing', 'photocopy', 'id_services') OR NEW.`product_group` = 'service' THEN
        SET NEW.`type` = 'service';
        SET NEW.`product_group` = 'service';
    END IF;
    IF NEW.`type` = 'service' THEN
        SET NEW.`product_group` = 'service';
        SET NEW.`stock_qty` = 0;
    END IF;
END//

CREATE TRIGGER `trg_sales_validate_insert`
BEFORE INSERT ON `sales`
FOR EACH ROW
BEGIN
    IF NEW.`quantity` <= 0 OR NEW.`unit_price` < 0 OR NEW.`unit_cost` < 0 OR NEW.`total_amount` < 0 OR NEW.`total_cost` < 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Sale values must be valid and non-negative';
    END IF;
END//

DELIMITER ;
