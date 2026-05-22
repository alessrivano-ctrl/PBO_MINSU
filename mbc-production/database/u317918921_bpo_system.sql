-- Cleanup script for fresh import of u317918921_bcenter.sql
-- Run this first only when you want to reset the existing BPO database before importing the dump.
USE `u317918921_bcenter`;
SET FOREIGN_KEY_CHECKS = 0;

DROP PROCEDURE IF EXISTS `sp_cash_summary_by_period`;
DROP PROCEDURE IF EXISTS `sp_dashboard_summary`;
DROP PROCEDURE IF EXISTS `sp_sales_summary_by_period`;

DROP TRIGGER IF EXISTS `trg_products_validate_insert`;
DROP TRIGGER IF EXISTS `trg_products_validate_update`;
DROP TRIGGER IF EXISTS `trg_sales_validate_insert`;

DROP VIEW IF EXISTS `v_cashflow_summary`;
DROP VIEW IF EXISTS `v_daily_sales_summary`;
DROP VIEW IF EXISTS `v_inventory_category_summary`;
DROP VIEW IF EXISTS `v_inventory_group_summary`;
DROP VIEW IF EXISTS `v_overdue_project_accounts`;
DROP VIEW IF EXISTS `v_project_category_summary`;

DROP TABLE IF EXISTS `approval_requests`;
DROP TABLE IF EXISTS `archived_records`;
DROP TABLE IF EXISTS `audit_logs`;
DROP TABLE IF EXISTS `business_center_content`;
DROP TABLE IF EXISTS `cash_transactions`;
DROP TABLE IF EXISTS `inventory_stock_batches`;
DROP TABLE IF EXISTS `inventory_stock_movements`;
DROP TABLE IF EXISTS `login_attempts`;
DROP TABLE IF EXISTS `office_logbook`;
DROP TABLE IF EXISTS `people`;
DROP TABLE IF EXISTS `products`;
DROP TABLE IF EXISTS `project_accounts`;
DROP TABLE IF EXISTS `project_account_meta`;
DROP TABLE IF EXISTS `project_categories`;
DROP TABLE IF EXISTS `project_entries`;
DROP TABLE IF EXISTS `project_entry_meta`;
DROP TABLE IF EXISTS `proposals`;
DROP TABLE IF EXISTS `sales`;
DROP TABLE IF EXISTS `session_logs`;
DROP TABLE IF EXISTS `system_error_logs`;
DROP TABLE IF EXISTS `system_settings`;
DROP TABLE IF EXISTS `users`;
DROP TABLE IF EXISTS `v_cashflow_summary`;
DROP TABLE IF EXISTS `v_daily_sales_summary`;
DROP TABLE IF EXISTS `v_inventory_category_summary`;
DROP TABLE IF EXISTS `v_inventory_group_summary`;
DROP TABLE IF EXISTS `v_overdue_project_accounts`;
DROP TABLE IF EXISTS `v_project_category_summary`;

SET FOREIGN_KEY_CHECKS = 1;
