<?php
declare(strict_types=1);

function initialize_schema(PDO $pdo): void
{
    $queries = [
        "CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            full_name VARCHAR(120) NOT NULL,
            role ENUM('admin', 'staff') NOT NULL,
            status ENUM('pending', 'approved', 'suspended') NOT NULL DEFAULT 'approved',
            approved_by INT DEFAULT NULL,
            approved_at DATETIME DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_users_role_status (role, status),
            INDEX idx_users_status_created (status, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS people (
            id INT AUTO_INCREMENT PRIMARY KEY,
            full_name VARCHAR(150) NOT NULL,
            person_code VARCHAR(80) DEFAULT NULL,
            department VARCHAR(120) DEFAULT NULL,
            role_or_position VARCHAR(120) DEFAULT NULL,
            contact_info VARCHAR(160) DEFAULT NULL,
            status ENUM('pending', 'approved', 'inactive') NOT NULL DEFAULT 'pending',
            created_by INT DEFAULT NULL,
            approved_by INT DEFAULT NULL,
            approved_at DATETIME DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_people_status_name (status, full_name),
            INDEX idx_people_code (person_code),
            INDEX idx_people_department_role (department, role_or_position),
            CONSTRAINT fk_people_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
            CONSTRAINT fk_people_approved_by FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS login_attempts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(120) NOT NULL,
            ip_address VARCHAR(45) NOT NULL,
            was_successful TINYINT(1) NOT NULL DEFAULT 0,
            attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_login_attempts_lookup (username, ip_address, was_successful, attempted_at),
            INDEX idx_login_attempts_cleanup (attempted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS session_logs (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            user_id INT DEFAULT NULL,
            session_id VARCHAR(128) NOT NULL,
            event ENUM('login', 'logout', 'timeout', 'fingerprint_mismatch') NOT NULL,
            ip_address VARCHAR(45) DEFAULT NULL,
            user_agent VARCHAR(255) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_session_user_created (user_id, created_at),
            INDEX idx_session_id (session_id),
            CONSTRAINT fk_session_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS audit_logs (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            user_id INT DEFAULT NULL,
            action VARCHAR(80) NOT NULL,
            module VARCHAR(80) NOT NULL,
            entity_type VARCHAR(80) DEFAULT NULL,
            entity_id VARCHAR(80) DEFAULT NULL,
            details TEXT DEFAULT NULL,
            ip_address VARCHAR(45) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_audit_created_at (created_at),
            INDEX idx_audit_user_module (user_id, module, created_at),
            INDEX idx_audit_module_action_created (module, action, created_at),
            CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS system_error_logs (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            user_id INT DEFAULT NULL,
            severity ENUM('notice', 'warning', 'error', 'critical') NOT NULL DEFAULT 'error',
            message VARCHAR(255) NOT NULL,
            context TEXT DEFAULT NULL,
            ip_address VARCHAR(45) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_error_created_at (created_at),
            INDEX idx_error_severity (severity, created_at),
            CONSTRAINT fk_error_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS archived_records (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            source_table VARCHAR(80) NOT NULL,
            source_id VARCHAR(80) NOT NULL,
            record_data JSON DEFAULT NULL,
            archived_by INT DEFAULT NULL,
            archived_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_archive_source (source_table, source_id),
            CONSTRAINT fk_archive_user FOREIGN KEY (archived_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS products (
            id INT AUTO_INCREMENT PRIMARY KEY,
            sku VARCHAR(100) UNIQUE,
            name VARCHAR(150) NOT NULL,
            unit VARCHAR(40) DEFAULT NULL,
            category ENUM('school_supply', 'id_supplies', 'id_services', 'printing', 'photocopy', 'other') NOT NULL DEFAULT 'school_supply',
            category_name VARCHAR(120) DEFAULT NULL,
            product_group ENUM('product', 'igp', 'service') NOT NULL DEFAULT 'product',
            type ENUM('item', 'service') NOT NULL DEFAULT 'item',
            product_type ENUM('consumable', 'non_consumable') NOT NULL DEFAULT 'non_consumable',
            cost_price DECIMAL(12,2) NOT NULL DEFAULT 0,
            selling_price DECIMAL(12,2) NOT NULL DEFAULT 0,
            stock_qty INT NOT NULL DEFAULT 0,
            low_stock_threshold INT NOT NULL DEFAULT 5,
            is_consumable TINYINT(1) NOT NULL DEFAULT 0,
            requires_expiration TINYINT(1) NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_products_active_name (is_active, name),
            INDEX idx_products_active_category (is_active, category),
            INDEX idx_products_category_name (category_name),
            INDEX idx_products_active_group (is_active, product_group),
            INDEX idx_products_active_sku (is_active, sku)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS sales (
            id INT AUTO_INCREMENT PRIMARY KEY,
            sale_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            product_id INT NOT NULL,
            person_id INT DEFAULT NULL,
            quantity INT NOT NULL,
            unit_price DECIMAL(12,2) NOT NULL,
            unit_cost DECIMAL(12,2) NOT NULL,
            total_amount DECIMAL(12,2) NOT NULL,
            total_cost DECIMAL(12,2) NOT NULL,
            total_profit DECIMAL(12,2) NOT NULL,
            or_number VARCHAR(100) DEFAULT NULL,
            notes VARCHAR(255) DEFAULT NULL,
            created_by INT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_sales_date (sale_date),
            INDEX idx_sales_product (product_id),
            INDEX idx_sales_person (person_id),
            INDEX idx_sales_date_product (sale_date, product_id),
            CONSTRAINT fk_sales_product FOREIGN KEY (product_id) REFERENCES products(id),
            CONSTRAINT fk_sales_person FOREIGN KEY (person_id) REFERENCES people(id) ON DELETE SET NULL,
            CONSTRAINT fk_sales_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS inventory_stock_batches (
            id INT AUTO_INCREMENT PRIMARY KEY,
            product_id INT NOT NULL,
            batch_code VARCHAR(100) DEFAULT NULL,
            received_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expiration_date DATE DEFAULT NULL,
            supplier VARCHAR(150) DEFAULT NULL,
            quantity_received INT NOT NULL,
            quantity_remaining INT NOT NULL,
            unit_cost DECIMAL(12,2) NOT NULL DEFAULT 0,
            source_type VARCHAR(40) NOT NULL DEFAULT 'stock_in',
            reference_no VARCHAR(100) DEFAULT NULL,
            notes VARCHAR(255) DEFAULT NULL,
            created_by INT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_stock_batches_fifo (product_id, quantity_remaining, received_date, id),
            INDEX idx_stock_batches_fefo (product_id, quantity_remaining, expiration_date, received_date, id),
            INDEX idx_stock_batches_product_date (product_id, received_date),
            CONSTRAINT fk_stock_batch_product FOREIGN KEY (product_id) REFERENCES products(id),
            CONSTRAINT fk_stock_batch_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS inventory_stock_movements (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            product_id INT NOT NULL,
            batch_id INT DEFAULT NULL,
            sale_id INT DEFAULT NULL,
            movement_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            movement_type ENUM('opening', 'stock_in', 'stock_out', 'sale', 'adjustment', 'damaged', 'expired', 'disposal', 'consumption', 'refill') NOT NULL,
            quantity_change INT NOT NULL,
            previous_quantity INT DEFAULT NULL,
            new_quantity INT DEFAULT NULL,
            unit_cost DECIMAL(12,2) NOT NULL DEFAULT 0,
            total_cost DECIMAL(12,2) NOT NULL DEFAULT 0,
            reference_no VARCHAR(100) DEFAULT NULL,
            notes VARCHAR(255) DEFAULT NULL,
            created_by INT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_stock_movements_product_date (product_id, movement_date),
            INDEX idx_stock_movements_batch (batch_id),
            INDEX idx_stock_movements_sale (sale_id),
            CONSTRAINT fk_stock_movement_product FOREIGN KEY (product_id) REFERENCES products(id),
            CONSTRAINT fk_stock_movement_batch FOREIGN KEY (batch_id) REFERENCES inventory_stock_batches(id) ON DELETE SET NULL,
            CONSTRAINT fk_stock_movement_sale FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE SET NULL,
            CONSTRAINT fk_stock_movement_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS product_price_history (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            product_id INT NOT NULL,
            changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            cost_price DECIMAL(12,2) NOT NULL DEFAULT 0,
            selling_price DECIMAL(12,2) NOT NULL DEFAULT 0,
            source_type VARCHAR(40) NOT NULL DEFAULT 'manual',
            notes VARCHAR(255) DEFAULT NULL,
            changed_by INT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_price_history_product_date (product_id, changed_at, id),
            CONSTRAINT fk_price_history_product FOREIGN KEY (product_id) REFERENCES products(id),
            CONSTRAINT fk_price_history_user FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS project_categories (
            id INT AUTO_INCREMENT PRIMARY KEY,
            slug VARCHAR(80) NOT NULL UNIQUE,
            name VARCHAR(120) NOT NULL,
            description VARCHAR(255) DEFAULT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS project_accounts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            category_id INT NOT NULL,
            person_id INT DEFAULT NULL,
            account_name VARCHAR(150) NOT NULL,
            code VARCHAR(80) DEFAULT NULL,
            contact_name VARCHAR(120) DEFAULT NULL,
            start_date DATE DEFAULT NULL,
            next_due_date DATE DEFAULT NULL,
            expected_amount DECIMAL(12,2) DEFAULT NULL,
            status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
            notes VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_project_account_due (next_due_date),
            INDEX idx_project_account_person (person_id),
            INDEX idx_project_account_category_status (category_id, status),
            INDEX idx_project_account_name_code (account_name, code),
            CONSTRAINT fk_project_account_category FOREIGN KEY (category_id) REFERENCES project_categories(id),
            CONSTRAINT fk_project_account_person FOREIGN KEY (person_id) REFERENCES people(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS project_account_meta (
            id INT AUTO_INCREMENT PRIMARY KEY,
            account_id INT NOT NULL,
            meta_key VARCHAR(80) NOT NULL,
            meta_value VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_project_account_meta (account_id, meta_key),
            INDEX idx_project_account_meta_key (meta_key),
            CONSTRAINT fk_project_account_meta_account FOREIGN KEY (account_id) REFERENCES project_accounts(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS project_entries (
            id INT AUTO_INCREMENT PRIMARY KEY,
            category_id INT NOT NULL,
            account_id INT DEFAULT NULL,
            entry_datetime DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            entry_type ENUM('income', 'expense', 'production', 'harvest', 'payment', 'monitoring', 'other') NOT NULL DEFAULT 'monitoring',
            quantity DECIMAL(12,2) DEFAULT NULL,
            unit VARCHAR(30) DEFAULT NULL,
            amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            reference_no VARCHAR(100) DEFAULT NULL,
            notes VARCHAR(255) DEFAULT NULL,
            created_by INT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_project_entries_datetime (entry_datetime),
            INDEX idx_project_entries_category (category_id),
            INDEX idx_project_entries_category_datetime (category_id, entry_datetime),
            CONSTRAINT fk_project_entry_category FOREIGN KEY (category_id) REFERENCES project_categories(id),
            CONSTRAINT fk_project_entry_account FOREIGN KEY (account_id) REFERENCES project_accounts(id) ON DELETE SET NULL,
            CONSTRAINT fk_project_entry_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS project_entry_meta (
            id INT AUTO_INCREMENT PRIMARY KEY,
            entry_id INT NOT NULL,
            meta_key VARCHAR(80) NOT NULL,
            meta_value VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_project_entry_meta (entry_id, meta_key),
            INDEX idx_project_entry_meta_key (meta_key),
            CONSTRAINT fk_project_entry_meta_entry FOREIGN KEY (entry_id) REFERENCES project_entries(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS cash_transactions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            txn_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            direction ENUM('in', 'out') NOT NULL,
            source_module VARCHAR(80) NOT NULL DEFAULT 'manual',
            manual_category VARCHAR(100) DEFAULT NULL,
            project_entry_id INT DEFAULT NULL,
            amount DECIMAL(12,2) NOT NULL,
            or_number VARCHAR(100) DEFAULT NULL,
            description VARCHAR(255) DEFAULT NULL,
            created_by INT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_cash_date (txn_date),
            INDEX idx_cash_project_entry (project_entry_id),
            INDEX idx_cash_source_direction_date (source_module, direction, txn_date),
            INDEX idx_cash_or_number (or_number),
            CONSTRAINT fk_cash_project_entry FOREIGN KEY (project_entry_id) REFERENCES project_entries(id) ON DELETE SET NULL,
            CONSTRAINT fk_cash_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS office_logbook (
            id INT AUTO_INCREMENT PRIMARY KEY,
            person_id INT DEFAULT NULL,
            log_date DATE NOT NULL,
            time_in TIME NOT NULL,
            time_out TIME DEFAULT NULL,
            student_name VARCHAR(120) NOT NULL,
            student_id VARCHAR(60) DEFAULT NULL,
            or_number VARCHAR(100) DEFAULT NULL,
            purpose VARCHAR(255) DEFAULT NULL,
            created_by INT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_logbook_date (log_date),
            INDEX idx_logbook_person (person_id),
            INDEX idx_logbook_student_name (student_name),
            INDEX idx_logbook_student_id (student_id),
            INDEX idx_logbook_or_number (or_number),
            INDEX idx_logbook_or_date (or_number, log_date),
            CONSTRAINT fk_logbook_person FOREIGN KEY (person_id) REFERENCES people(id) ON DELETE SET NULL,
            CONSTRAINT fk_logbook_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS proposals (
            id INT AUTO_INCREMENT PRIMARY KEY,
            proposer_id INT DEFAULT NULL,
            title VARCHAR(180) NOT NULL,
            proposer_name VARCHAR(120) NOT NULL,
            department VARCHAR(120) DEFAULT NULL,
            submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            status ENUM('submitted', 'under_review', 'approved', 'rejected', 'needs_revision', 'cancelled', 'implemented') NOT NULL DEFAULT 'submitted',
            estimated_budget DECIMAL(12,2) NOT NULL DEFAULT 0,
            target_date DATE DEFAULT NULL,
            summary TEXT DEFAULT NULL,
            admin_notes TEXT DEFAULT NULL,
            created_by INT DEFAULT NULL,
            reviewed_by INT DEFAULT NULL,
            reviewed_at DATETIME DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_proposals_status (status, submitted_at),
            INDEX idx_proposals_proposer (proposer_id),
            CONSTRAINT fk_proposal_proposer FOREIGN KEY (proposer_id) REFERENCES people(id) ON DELETE SET NULL,
            CONSTRAINT fk_proposal_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
            CONSTRAINT fk_proposal_reviewed_by FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS business_center_content (
            id INT AUTO_INCREMENT PRIMARY KEY,
            section_key VARCHAR(80) NOT NULL UNIQUE,
            title VARCHAR(180) NOT NULL,
            body TEXT DEFAULT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            updated_by INT DEFAULT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_bc_active_section (is_active, section_key),
            CONSTRAINT fk_bc_content_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS system_settings (
            setting_key VARCHAR(100) NOT NULL PRIMARY KEY,
            setting_value TEXT DEFAULT NULL,
            updated_by INT DEFAULT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_system_settings_updated_by (updated_by),
            CONSTRAINT fk_system_settings_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];

    foreach ($queries as $query) {
        $pdo->exec($query);
    }

    ensure_user_role_migration($pdo);
    ensure_people_schema($pdo);
    ensure_proposals_schema($pdo);
    ensure_approval_requests_schema($pdo);
    ensure_inventory_stock_schema($pdo);
    ensure_default_project_categories($pdo);
    ensure_ponds_schema($pdo);
    ensure_normalized_fishpond_schema($pdo);
    ensure_default_business_center_content($pdo);
    ensure_default_system_settings($pdo);
    ensure_cash_transaction_relations($pdo);
    ensure_normalized_pos_schema($pdo);
    migrate_legacy_project_tables($pdo);
    ensure_ponds_schema($pdo);
    ensure_normalized_fishpond_schema($pdo);
    ensure_demo_visibility_data($pdo);
    migrate_legacy_people_references($pdo);
    dedupe_people_by_code($pdo);
    ensure_data_integrity_indexes($pdo);
    ensure_inventory_stock_schema($pdo);
    ensure_database_programmability($pdo);
}

function ensure_user_role_migration(PDO $pdo): void
{
    execute_schema_statement($pdo, "ALTER TABLE users MODIFY role ENUM('admin', 'staff') NOT NULL DEFAULT 'staff'");
    if (!schema_column_exists($pdo, 'users', 'status')) {
        execute_schema_statement($pdo, "ALTER TABLE users ADD status ENUM('pending', 'approved', 'suspended') NOT NULL DEFAULT 'approved' AFTER role");
    }
    if (!schema_column_exists($pdo, 'users', 'approved_by')) {
        execute_schema_statement($pdo, 'ALTER TABLE users ADD approved_by INT DEFAULT NULL AFTER status');
    }
    if (!schema_column_exists($pdo, 'users', 'approved_at')) {
        execute_schema_statement($pdo, 'ALTER TABLE users ADD approved_at DATETIME DEFAULT NULL AFTER approved_by');
    }
    execute_schema_statement($pdo, "UPDATE users SET role = 'staff' WHERE role NOT IN ('admin', 'staff')");
    execute_schema_statement($pdo, "UPDATE users SET status = 'approved' WHERE status IS NULL OR status = ''");
    if (!schema_index_exists($pdo, 'users', 'idx_users_role_status')) {
        execute_schema_statement($pdo, 'ALTER TABLE users ADD INDEX idx_users_role_status (role, status)');
    }
    if (!schema_index_exists($pdo, 'users', 'idx_users_status_created')) {
        execute_schema_statement($pdo, 'ALTER TABLE users ADD INDEX idx_users_status_created (status, created_at)');
    }
}

function ensure_people_schema(PDO $pdo): void
{
    execute_schema_statement($pdo, "ALTER TABLE people MODIFY status ENUM('pending', 'active', 'approved', 'inactive', 'rejected') NOT NULL DEFAULT 'pending'");
    execute_schema_statement($pdo, "UPDATE people SET person_code = NULL WHERE person_code IS NOT NULL AND TRIM(person_code) = ''");
    execute_schema_statement($pdo, "UPDATE people SET person_code = UPPER(TRIM(person_code)) WHERE person_code IS NOT NULL AND TRIM(person_code) <> ''");
    execute_schema_statement($pdo, "UPDATE people SET full_name = TRIM(full_name), department = NULLIF(TRIM(COALESCE(department, '')), ''), role_or_position = NULLIF(TRIM(COALESCE(role_or_position, '')), ''), contact_info = NULLIF(TRIM(COALESCE(contact_info, '')), '')");
    execute_schema_statement($pdo, "UPDATE people SET status = 'approved' WHERE status = 'active'");
    execute_schema_statement($pdo, "UPDATE people SET status = 'inactive' WHERE status = 'rejected'");
    dedupe_people_by_code($pdo);
    execute_schema_statement($pdo, "ALTER TABLE people MODIFY status ENUM('pending', 'approved', 'inactive') NOT NULL DEFAULT 'pending'");

    execute_schema_statement($pdo, "CREATE TABLE IF NOT EXISTS people (
        id INT AUTO_INCREMENT PRIMARY KEY,
        full_name VARCHAR(150) NOT NULL,
        person_code VARCHAR(80) DEFAULT NULL,
        department VARCHAR(120) DEFAULT NULL,
        role_or_position VARCHAR(120) DEFAULT NULL,
        contact_info VARCHAR(160) DEFAULT NULL,
        status ENUM('pending', 'approved', 'inactive') NOT NULL DEFAULT 'pending',
        created_by INT DEFAULT NULL,
        approved_by INT DEFAULT NULL,
        approved_at DATETIME DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_people_status_name (status, full_name),
        INDEX idx_people_code (person_code),
        INDEX idx_people_department_role (department, role_or_position)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    if (!schema_column_exists($pdo, 'project_accounts', 'person_id')) {
        execute_schema_statement($pdo, 'ALTER TABLE project_accounts ADD person_id INT DEFAULT NULL AFTER category_id');
    }
    if (!schema_index_exists($pdo, 'project_accounts', 'idx_project_account_person')) {
        execute_schema_statement($pdo, 'ALTER TABLE project_accounts ADD INDEX idx_project_account_person (person_id)');
    }

    if (!schema_column_exists($pdo, 'office_logbook', 'person_id')) {
        execute_schema_statement($pdo, 'ALTER TABLE office_logbook ADD person_id INT DEFAULT NULL AFTER id');
    }
    if (!schema_index_exists($pdo, 'office_logbook', 'idx_logbook_person')) {
        execute_schema_statement($pdo, 'ALTER TABLE office_logbook ADD INDEX idx_logbook_person (person_id)');
    }
    if (!schema_column_exists($pdo, 'office_logbook', 'or_number')) {
        execute_schema_statement($pdo, 'ALTER TABLE office_logbook ADD or_number VARCHAR(100) DEFAULT NULL AFTER student_id');
    }
    if (!schema_index_exists($pdo, 'office_logbook', 'idx_logbook_or_number')) {
        execute_schema_statement($pdo, 'ALTER TABLE office_logbook ADD INDEX idx_logbook_or_number (or_number)');
    }

    if (!schema_column_exists($pdo, 'proposals', 'proposer_id')) {
        execute_schema_statement($pdo, 'ALTER TABLE proposals ADD proposer_id INT DEFAULT NULL AFTER id');
    }
    if (!schema_index_exists($pdo, 'proposals', 'idx_proposals_proposer')) {
        execute_schema_statement($pdo, 'ALTER TABLE proposals ADD INDEX idx_proposals_proposer (proposer_id)');
    }

    migrate_legacy_people_references($pdo);
    dedupe_people_by_code($pdo);

    if (!schema_index_exists($pdo, 'people', 'uniq_people_person_code')) {
        execute_schema_statement($pdo, 'ALTER TABLE people ADD UNIQUE KEY uniq_people_person_code (person_code)');
    }
}

function ensure_data_integrity_indexes(PDO $pdo): void
{
    $indexStatements = [
        ['audit_logs', 'idx_audit_module_action_created', 'ALTER TABLE audit_logs ADD INDEX idx_audit_module_action_created (module, action, created_at)'],
        ['office_logbook', 'idx_logbook_or_date', 'ALTER TABLE office_logbook ADD INDEX idx_logbook_or_date (or_number, log_date)'],
    ];

    foreach ($indexStatements as [$table, $index, $sql]) {
        if (!schema_index_exists($pdo, $table, $index)) {
            execute_schema_statement($pdo, $sql);
        }
    }

    if (!schema_constraint_exists($pdo, 'project_accounts', 'fk_project_account_person')) {
        execute_schema_statement($pdo, 'ALTER TABLE project_accounts ADD CONSTRAINT fk_project_account_person FOREIGN KEY (person_id) REFERENCES people(id) ON DELETE SET NULL');
    }
    if (!schema_constraint_exists($pdo, 'office_logbook', 'fk_logbook_person')) {
        execute_schema_statement($pdo, 'ALTER TABLE office_logbook ADD CONSTRAINT fk_logbook_person FOREIGN KEY (person_id) REFERENCES people(id) ON DELETE SET NULL');
    }
    if (!schema_constraint_exists($pdo, 'proposals', 'fk_proposal_proposer')) {
        execute_schema_statement($pdo, 'ALTER TABLE proposals ADD CONSTRAINT fk_proposal_proposer FOREIGN KEY (proposer_id) REFERENCES people(id) ON DELETE SET NULL');
    }
}

function ensure_proposals_schema(PDO $pdo): void
{
    execute_schema_statement($pdo, "ALTER TABLE proposals MODIFY status ENUM('submitted', 'under_review', 'approved', 'rejected', 'needs_revision', 'cancelled', 'implemented') NOT NULL DEFAULT 'submitted'");
}

function ensure_approval_requests_schema(PDO $pdo): void
{
    execute_schema_statement($pdo, "CREATE TABLE IF NOT EXISTS approval_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        requester_id INT NOT NULL,
        module VARCHAR(80) NOT NULL,
        action_type VARCHAR(80) NOT NULL,
        entity_type VARCHAR(80) DEFAULT NULL,
        entity_id VARCHAR(80) DEFAULT NULL,
        old_value JSON DEFAULT NULL,
        new_value JSON DEFAULT NULL,
        status ENUM('pending', 'approved', 'rejected', 'needs_revision', 'cancelled') NOT NULL DEFAULT 'pending',
        admin_decision VARCHAR(20) DEFAULT NULL,
        admin_remarks TEXT DEFAULT NULL,
        decision_date DATETIME DEFAULT NULL,
        decided_by_id INT DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_approval_status (status),
        INDEX idx_approval_requester_status (requester_id, status),
        INDEX idx_approval_module_type (module, action_type),
        INDEX idx_approval_created_at (created_at),
        CONSTRAINT fk_approval_requester FOREIGN KEY (requester_id) REFERENCES users(id) ON DELETE CASCADE,
        CONSTRAINT fk_approval_decider FOREIGN KEY (decided_by_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function ensure_inventory_stock_schema(PDO $pdo): void
{
    execute_schema_statement($pdo, "CREATE TABLE IF NOT EXISTS inventory_categories (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(120) NOT NULL,
        section ENUM('products', 'services') NOT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_inventory_categories_section_name (section, name),
        INDEX idx_inventory_categories_active (section, is_active, name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    execute_schema_statement($pdo, "ALTER TABLE products MODIFY category ENUM('school_supply', 'id_supplies', 'id_services', 'printing', 'photocopy', 'other') NOT NULL DEFAULT 'school_supply'");

    if (!schema_column_exists($pdo, 'products', 'category_name')) {
        execute_schema_statement($pdo, 'ALTER TABLE products ADD category_name VARCHAR(120) DEFAULT NULL AFTER category');
    }

    if (!schema_column_exists($pdo, 'products', 'unit')) {
        execute_schema_statement($pdo, 'ALTER TABLE products ADD unit VARCHAR(40) DEFAULT NULL AFTER name');
    }

    if (!schema_column_exists($pdo, 'products', 'product_group')) {
        execute_schema_statement($pdo, "ALTER TABLE products ADD product_group ENUM('product', 'igp', 'service') NOT NULL DEFAULT 'product' AFTER category");
    }

    if (!schema_column_exists($pdo, 'products', 'product_type')) {
        execute_schema_statement($pdo, "ALTER TABLE products ADD product_type ENUM('consumable', 'non_consumable') NOT NULL DEFAULT 'non_consumable' AFTER type");
    }

    if (!schema_column_exists($pdo, 'products', 'is_consumable')) {
        execute_schema_statement($pdo, 'ALTER TABLE products ADD is_consumable TINYINT(1) NOT NULL DEFAULT 0 AFTER low_stock_threshold');
    }

    if (!schema_column_exists($pdo, 'products', 'requires_expiration')) {
        execute_schema_statement($pdo, 'ALTER TABLE products ADD requires_expiration TINYINT(1) NOT NULL DEFAULT 0 AFTER is_consumable');
    }

    execute_schema_statement($pdo, "UPDATE products SET product_type = 'consumable' WHERE type = 'item' AND (is_consumable = 1 OR requires_expiration = 1)");
    execute_schema_statement($pdo, "UPDATE products SET is_consumable = 1, requires_expiration = 1 WHERE product_type = 'consumable'");
    execute_schema_statement($pdo, "UPDATE products SET is_consumable = 0, requires_expiration = 0 WHERE product_type = 'non_consumable'");

    if (!schema_column_exists($pdo, 'products', 'updated_at')) {
        execute_schema_statement($pdo, 'ALTER TABLE products ADD updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at');
    }

    if (!schema_index_exists($pdo, 'products', 'idx_products_category_name')) {
        execute_schema_statement($pdo, 'ALTER TABLE products ADD INDEX idx_products_category_name (category_name)');
    }

    execute_schema_statement($pdo, "UPDATE products SET category_name = NULL WHERE category_name IS NOT NULL AND TRIM(category_name) = ''");
    execute_schema_statement($pdo, "INSERT IGNORE INTO inventory_categories (name, section)
        SELECT DISTINCT TRIM(category_name), CASE WHEN type = 'service' THEN 'services' ELSE 'products' END
        FROM products
        WHERE category_name IS NOT NULL AND TRIM(category_name) <> ''");

    ensure_default_id_items($pdo);

    execute_schema_statement($pdo, "UPDATE products SET product_group = 'service', type = 'service', stock_qty = 0 WHERE category IN ('printing', 'photocopy', 'id_services')");
    execute_schema_statement($pdo, "UPDATE products SET type = 'service', stock_qty = 0 WHERE product_group = 'service'");
    execute_schema_statement($pdo, "UPDATE products SET product_group = 'service' WHERE type = 'service'");

    if (!schema_index_exists($pdo, 'products', 'idx_products_active_group')) {
        execute_schema_statement($pdo, 'ALTER TABLE products ADD INDEX idx_products_active_group (is_active, product_group)');
    }

    if (schema_index_exists($pdo, 'products', 'idx_products_active_barcode')) {
        execute_schema_statement($pdo, 'ALTER TABLE products DROP INDEX idx_products_active_barcode');
    }

    if (schema_column_exists($pdo, 'products', 'barcode')) {
        execute_schema_statement($pdo, 'ALTER TABLE products DROP COLUMN barcode');
    }

    execute_schema_statement($pdo, "CREATE TABLE IF NOT EXISTS inventory_stock_batches (
        id INT AUTO_INCREMENT PRIMARY KEY,
        product_id INT NOT NULL,
        batch_code VARCHAR(100) DEFAULT NULL,
        received_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        expiration_date DATE DEFAULT NULL,
        supplier VARCHAR(150) DEFAULT NULL,
        quantity_received INT NOT NULL,
        quantity_remaining INT NOT NULL,
        unit_cost DECIMAL(12,2) NOT NULL DEFAULT 0,
        source_type VARCHAR(40) NOT NULL DEFAULT 'stock_in',
        reference_no VARCHAR(100) DEFAULT NULL,
        notes VARCHAR(255) DEFAULT NULL,
        created_by INT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_stock_batches_fifo (product_id, quantity_remaining, received_date, id),
        INDEX idx_stock_batches_fefo (product_id, quantity_remaining, expiration_date, received_date, id),
        INDEX idx_stock_batches_product_date (product_id, received_date),
        CONSTRAINT fk_stock_batch_product FOREIGN KEY (product_id) REFERENCES products(id),
        CONSTRAINT fk_stock_batch_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    if (!schema_column_exists($pdo, 'inventory_stock_batches', 'expiration_date')) {
        execute_schema_statement($pdo, 'ALTER TABLE inventory_stock_batches ADD expiration_date DATE DEFAULT NULL AFTER received_date');
    }

    if (!schema_column_exists($pdo, 'inventory_stock_batches', 'supplier')) {
        execute_schema_statement($pdo, 'ALTER TABLE inventory_stock_batches ADD supplier VARCHAR(150) DEFAULT NULL AFTER expiration_date');
    }

    if (!schema_index_exists($pdo, 'inventory_stock_batches', 'idx_stock_batches_fefo')) {
        execute_schema_statement($pdo, 'ALTER TABLE inventory_stock_batches ADD INDEX idx_stock_batches_fefo (product_id, quantity_remaining, expiration_date, received_date, id)');
    }

    execute_schema_statement($pdo, "CREATE TABLE IF NOT EXISTS inventory_stock_movements (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        product_id INT NOT NULL,
        batch_id INT DEFAULT NULL,
        sale_id INT DEFAULT NULL,
        movement_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        movement_type ENUM('opening', 'stock_in', 'stock_out', 'sale', 'adjustment', 'damaged', 'expired', 'disposal', 'consumption', 'refill', 'void_return') NOT NULL,
        quantity_change INT NOT NULL,
        previous_quantity INT DEFAULT NULL,
        new_quantity INT DEFAULT NULL,
        unit_cost DECIMAL(12,2) NOT NULL DEFAULT 0,
        total_cost DECIMAL(12,2) NOT NULL DEFAULT 0,
        reference_no VARCHAR(100) DEFAULT NULL,
        notes VARCHAR(255) DEFAULT NULL,
        created_by INT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_stock_movements_product_date (product_id, movement_date),
        INDEX idx_stock_movements_batch (batch_id),
        INDEX idx_stock_movements_sale (sale_id),
        CONSTRAINT fk_stock_movement_product FOREIGN KEY (product_id) REFERENCES products(id),
        CONSTRAINT fk_stock_movement_batch FOREIGN KEY (batch_id) REFERENCES inventory_stock_batches(id) ON DELETE SET NULL,
        CONSTRAINT fk_stock_movement_sale FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE SET NULL,
        CONSTRAINT fk_stock_movement_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    execute_schema_statement($pdo, "ALTER TABLE inventory_stock_movements MODIFY movement_type ENUM('opening', 'stock_in', 'stock_out', 'sale', 'adjustment', 'damaged', 'expired', 'disposal', 'consumption', 'refill', 'void_return') NOT NULL");

    if (!schema_column_exists($pdo, 'inventory_stock_movements', 'previous_quantity')) {
        execute_schema_statement($pdo, 'ALTER TABLE inventory_stock_movements ADD previous_quantity INT DEFAULT NULL AFTER quantity_change');
    }

    if (!schema_column_exists($pdo, 'inventory_stock_movements', 'new_quantity')) {
        execute_schema_statement($pdo, 'ALTER TABLE inventory_stock_movements ADD new_quantity INT DEFAULT NULL AFTER previous_quantity');
    }

    execute_schema_statement($pdo, "CREATE TABLE IF NOT EXISTS product_price_history (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        product_id INT NOT NULL,
        changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        cost_price DECIMAL(12,2) NOT NULL DEFAULT 0,
        selling_price DECIMAL(12,2) NOT NULL DEFAULT 0,
        source_type VARCHAR(40) NOT NULL DEFAULT 'manual',
        notes VARCHAR(255) DEFAULT NULL,
        changed_by INT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_price_history_product_date (product_id, changed_at, id),
        CONSTRAINT fk_price_history_product FOREIGN KEY (product_id) REFERENCES products(id),
        CONSTRAINT fk_price_history_user FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    execute_schema_statement($pdo, "INSERT INTO product_price_history (product_id, changed_at, cost_price, selling_price, source_type, notes)
        SELECT p.id, COALESCE(p.created_at, NOW()), p.cost_price, p.selling_price, 'opening', 'Opening price record'
        FROM products p
        WHERE NOT EXISTS (
            SELECT 1 FROM product_price_history h WHERE h.product_id = p.id
        )");

    $products = $pdo->query("SELECT id, stock_qty, cost_price, created_at
        FROM products
        WHERE type = 'item' AND is_active = 1 AND stock_qty > 0
        ORDER BY id ASC")->fetchAll();

    $batchStmt = $pdo->prepare('INSERT INTO inventory_stock_batches (product_id, batch_code, received_date, quantity_received, quantity_remaining, unit_cost, source_type, notes)
        VALUES (:product_id, :batch_code, :received_date, :quantity_received, :quantity_remaining, :unit_cost, "opening_balance", "Opening stock migrated from product quantity")');
    $movementStmt = $pdo->prepare('INSERT INTO inventory_stock_movements (product_id, batch_id, movement_date, movement_type, quantity_change, unit_cost, total_cost, notes)
        VALUES (:product_id, :batch_id, :movement_date, "opening", :quantity_change, :unit_cost, :total_cost, "Opening stock migrated from product quantity")');
    $existingStmt = $pdo->prepare('SELECT COUNT(*) FROM inventory_stock_batches WHERE product_id = :product_id');

    foreach ($products as $product) {
        $productId = (int) $product['id'];
        $existingStmt->execute(['product_id' => $productId]);
        if ((int) $existingStmt->fetchColumn() > 0) {
            continue;
        }

        $quantity = (int) $product['stock_qty'];
        $unitCost = (float) $product['cost_price'];
        $receivedDate = (string) ($product['created_at'] ?? date('Y-m-d H:i:s'));
        $batchStmt->execute([
            'product_id' => $productId,
            'batch_code' => 'OPEN-' . $productId,
            'received_date' => $receivedDate,
            'quantity_received' => $quantity,
            'quantity_remaining' => $quantity,
            'unit_cost' => $unitCost,
        ]);
        $batchId = (int) $pdo->lastInsertId();
        $movementStmt->execute([
            'product_id' => $productId,
            'batch_id' => $batchId,
            'movement_date' => $receivedDate,
            'quantity_change' => $quantity,
            'unit_cost' => $unitCost,
            'total_cost' => $unitCost * $quantity,
        ]);
    }
}

function execute_schema_statement(PDO $pdo, string $sql): void
{
    try {
        $pdo->exec($sql);
    } catch (Throwable $e) {
        error_log('Schema programmability warning: ' . $e->getMessage());
    }
}

function ensure_ponds_schema(PDO $pdo): void
{
    execute_schema_statement($pdo, "CREATE TABLE IF NOT EXISTS ponds (
        id INT AUTO_INCREMENT PRIMARY KEY,
        project_account_id INT DEFAULT NULL,
        pond_code VARCHAR(80) DEFAULT NULL,
        pond_name VARCHAR(150) NOT NULL,
        fish_type VARCHAR(120) DEFAULT NULL,
        stock_count INT DEFAULT NULL,
        survival_rate VARCHAR(40) DEFAULT NULL,
        fish_growth_stage VARCHAR(120) DEFAULT NULL,
        caretaker_id INT DEFAULT NULL,
        status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
        notes VARCHAR(255) DEFAULT NULL,
        created_by INT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_ponds_project_account (project_account_id),
        UNIQUE KEY uq_ponds_code (pond_code),
        UNIQUE KEY uq_ponds_name (pond_name),
        INDEX idx_ponds_status_name (status, pond_name),
        INDEX idx_ponds_caretaker (caretaker_id),
        CONSTRAINT fk_ponds_project_account FOREIGN KEY (project_account_id) REFERENCES project_accounts(id) ON DELETE SET NULL,
        CONSTRAINT fk_ponds_caretaker FOREIGN KEY (caretaker_id) REFERENCES people(id) ON DELETE SET NULL,
        CONSTRAINT fk_ponds_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    if (!schema_column_exists($pdo, 'ponds', 'project_account_id')) {
        execute_schema_statement($pdo, 'ALTER TABLE ponds ADD project_account_id INT DEFAULT NULL AFTER id');
    }
    if (!schema_column_exists($pdo, 'ponds', 'pond_code')) {
        execute_schema_statement($pdo, 'ALTER TABLE ponds ADD pond_code VARCHAR(80) DEFAULT NULL AFTER project_account_id');
    }
    if (!schema_column_exists($pdo, 'ponds', 'pond_name')) {
        execute_schema_statement($pdo, 'ALTER TABLE ponds ADD pond_name VARCHAR(150) NOT NULL AFTER pond_code');
    }
    if (!schema_column_exists($pdo, 'ponds', 'fish_type')) {
        execute_schema_statement($pdo, 'ALTER TABLE ponds ADD fish_type VARCHAR(120) DEFAULT NULL AFTER pond_name');
    }
    if (!schema_column_exists($pdo, 'ponds', 'stock_count')) {
        execute_schema_statement($pdo, 'ALTER TABLE ponds ADD stock_count INT DEFAULT NULL AFTER fish_type');
    }
    if (!schema_column_exists($pdo, 'ponds', 'survival_rate')) {
        execute_schema_statement($pdo, 'ALTER TABLE ponds ADD survival_rate VARCHAR(40) DEFAULT NULL AFTER stock_count');
    }
    if (!schema_column_exists($pdo, 'ponds', 'fish_growth_stage')) {
        execute_schema_statement($pdo, 'ALTER TABLE ponds ADD fish_growth_stage VARCHAR(120) DEFAULT NULL AFTER survival_rate');
    }
    if (!schema_column_exists($pdo, 'ponds', 'caretaker_id')) {
        execute_schema_statement($pdo, 'ALTER TABLE ponds ADD caretaker_id INT DEFAULT NULL AFTER fish_growth_stage');
    }
    if (!schema_column_exists($pdo, 'ponds', 'status')) {
        execute_schema_statement($pdo, "ALTER TABLE ponds ADD status ENUM('active', 'inactive') NOT NULL DEFAULT 'active' AFTER caretaker_id");
    }
    if (!schema_column_exists($pdo, 'ponds', 'notes')) {
        execute_schema_statement($pdo, 'ALTER TABLE ponds ADD notes VARCHAR(255) DEFAULT NULL AFTER status');
    }
    if (!schema_column_exists($pdo, 'ponds', 'created_by')) {
        execute_schema_statement($pdo, 'ALTER TABLE ponds ADD created_by INT DEFAULT NULL AFTER notes');
    }

    execute_schema_statement($pdo, "UPDATE ponds
        SET pond_code = NULLIF(TRIM(COALESCE(pond_code, '')), ''),
            pond_name = TRIM(pond_name),
            fish_type = NULLIF(TRIM(COALESCE(fish_type, '')), ''),
            survival_rate = NULLIF(TRIM(COALESCE(survival_rate, '')), ''),
            fish_growth_stage = NULLIF(TRIM(COALESCE(fish_growth_stage, '')), ''),
            notes = NULLIF(TRIM(COALESCE(notes, '')), '')");
    execute_schema_statement($pdo, "UPDATE ponds SET status = 'inactive' WHERE status NOT IN ('active', 'inactive')");
    execute_schema_statement($pdo, "UPDATE ponds SET status = 'inactive' WHERE LOWER(TRIM(pond_name)) = 'general' OR LOWER(TRIM(COALESCE(pond_code, ''))) = 'general'");

    if (!schema_index_exists($pdo, 'ponds', 'uq_ponds_project_account')) {
        execute_schema_statement($pdo, 'ALTER TABLE ponds ADD UNIQUE KEY uq_ponds_project_account (project_account_id)');
    }
    if (!schema_index_exists($pdo, 'ponds', 'uq_ponds_code')) {
        execute_schema_statement($pdo, 'ALTER TABLE ponds ADD UNIQUE KEY uq_ponds_code (pond_code)');
    }
    if (!schema_index_exists($pdo, 'ponds', 'uq_ponds_name')) {
        execute_schema_statement($pdo, 'ALTER TABLE ponds ADD UNIQUE KEY uq_ponds_name (pond_name)');
    }
    if (!schema_index_exists($pdo, 'ponds', 'idx_ponds_status_name')) {
        execute_schema_statement($pdo, 'ALTER TABLE ponds ADD INDEX idx_ponds_status_name (status, pond_name)');
    }
    if (!schema_index_exists($pdo, 'ponds', 'idx_ponds_caretaker')) {
        execute_schema_statement($pdo, 'ALTER TABLE ponds ADD INDEX idx_ponds_caretaker (caretaker_id)');
    }

    if (!schema_constraint_exists($pdo, 'ponds', 'fk_ponds_project_account')) {
        execute_schema_statement($pdo, 'ALTER TABLE ponds ADD CONSTRAINT fk_ponds_project_account FOREIGN KEY (project_account_id) REFERENCES project_accounts(id) ON DELETE SET NULL');
    }
    if (!schema_constraint_exists($pdo, 'ponds', 'fk_ponds_caretaker')) {
        execute_schema_statement($pdo, 'ALTER TABLE ponds ADD CONSTRAINT fk_ponds_caretaker FOREIGN KEY (caretaker_id) REFERENCES people(id) ON DELETE SET NULL');
    }
    if (!schema_constraint_exists($pdo, 'ponds', 'fk_ponds_created_by')) {
        execute_schema_statement($pdo, 'ALTER TABLE ponds ADD CONSTRAINT fk_ponds_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL');
    }

    backfill_ponds_from_project_accounts($pdo);
}

function backfill_ponds_from_project_accounts(PDO $pdo): void
{
    if (!schema_table_exists($pdo, 'ponds')) {
        return;
    }

    $sql = "INSERT IGNORE INTO ponds (
            project_account_id,
            pond_code,
            pond_name,
            fish_type,
            stock_count,
            survival_rate,
            fish_growth_stage,
            caretaker_id,
            status,
            notes
        )
        SELECT
            pa.id,
            NULLIF(TRIM(COALESCE(pa.code, '')), ''),
            TRIM(pa.account_name),
            NULLIF(TRIM(COALESCE(fish_type_meta.meta_value, '')), ''),
            CASE
                WHEN stock_count_meta.meta_value REGEXP '^[0-9]+$' THEN CAST(stock_count_meta.meta_value AS UNSIGNED)
                ELSE NULL
            END,
            NULLIF(TRIM(COALESCE(survival_rate_meta.meta_value, '')), ''),
            NULLIF(TRIM(COALESCE(fish_growth_stage_meta.meta_value, '')), ''),
            pa.person_id,
            CASE WHEN pa.status = 'inactive' THEN 'inactive' ELSE 'active' END,
            NULLIF(TRIM(COALESCE(pa.notes, '')), '')
        FROM project_accounts pa
        INNER JOIN project_categories pc ON pc.id = pa.category_id
        LEFT JOIN project_account_meta fish_type_meta
            ON fish_type_meta.account_id = pa.id AND fish_type_meta.meta_key = 'fish_type'
        LEFT JOIN project_account_meta stock_count_meta
            ON stock_count_meta.account_id = pa.id AND stock_count_meta.meta_key = 'stock_count'
        LEFT JOIN project_account_meta survival_rate_meta
            ON survival_rate_meta.account_id = pa.id AND survival_rate_meta.meta_key = 'survival_rate'
        LEFT JOIN project_account_meta fish_growth_stage_meta
            ON fish_growth_stage_meta.account_id = pa.id AND fish_growth_stage_meta.meta_key = 'fish_growth_stage'
        WHERE pc.slug = 'fishpond'
            AND TRIM(pa.account_name) <> ''
            AND LOWER(TRIM(pa.account_name)) <> 'general'
            AND LOWER(TRIM(COALESCE(pa.code, ''))) <> 'general'
        ORDER BY pa.id ASC";

    execute_schema_statement($pdo, $sql);

    execute_schema_statement($pdo, "UPDATE ponds p
        INNER JOIN project_accounts pa ON pa.id = p.project_account_id
        LEFT JOIN project_account_meta fish_type_meta
            ON fish_type_meta.account_id = pa.id AND fish_type_meta.meta_key = 'fish_type'
        LEFT JOIN project_account_meta stock_count_meta
            ON stock_count_meta.account_id = pa.id AND stock_count_meta.meta_key = 'stock_count'
        LEFT JOIN project_account_meta survival_rate_meta
            ON survival_rate_meta.account_id = pa.id AND survival_rate_meta.meta_key = 'survival_rate'
        LEFT JOIN project_account_meta fish_growth_stage_meta
            ON fish_growth_stage_meta.account_id = pa.id AND fish_growth_stage_meta.meta_key = 'fish_growth_stage'
        SET
            p.pond_code = NULLIF(TRIM(COALESCE(pa.code, '')), ''),
            p.pond_name = TRIM(pa.account_name),
            p.fish_type = NULLIF(TRIM(COALESCE(fish_type_meta.meta_value, '')), ''),
            p.stock_count = CASE
                WHEN stock_count_meta.meta_value REGEXP '^[0-9]+$' THEN CAST(stock_count_meta.meta_value AS UNSIGNED)
                ELSE p.stock_count
            END,
            p.survival_rate = NULLIF(TRIM(COALESCE(survival_rate_meta.meta_value, '')), ''),
            p.fish_growth_stage = NULLIF(TRIM(COALESCE(fish_growth_stage_meta.meta_value, '')), ''),
            p.caretaker_id = pa.person_id,
            p.status = CASE WHEN pa.status = 'inactive' THEN 'inactive' ELSE 'active' END,
            p.notes = NULLIF(TRIM(COALESCE(pa.notes, '')), '')
        WHERE LOWER(TRIM(pa.account_name)) <> 'general'
            AND LOWER(TRIM(COALESCE(pa.code, ''))) <> 'general'");
}

function ensure_normalized_fishpond_schema(PDO $pdo): void
{
    execute_schema_statement($pdo, "CREATE TABLE IF NOT EXISTS fishpond_feeding_records (
        id INT AUTO_INCREMENT PRIMARY KEY,
        pond_id INT NOT NULL,
        legacy_project_entry_id INT DEFAULT NULL,
        fed_at DATETIME NOT NULL,
        feed_type VARCHAR(120) DEFAULT NULL,
        quantity DECIMAL(12,2) DEFAULT NULL,
        unit VARCHAR(40) DEFAULT NULL,
        cost DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        caretaker_id INT DEFAULT NULL,
        remarks TEXT DEFAULT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_by INT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_fishpond_feeding_legacy (legacy_project_entry_id),
        INDEX idx_fishpond_feeding_pond_date (pond_id, fed_at),
        INDEX idx_fishpond_feeding_caretaker (caretaker_id),
        CONSTRAINT fk_fishpond_feeding_pond FOREIGN KEY (pond_id) REFERENCES ponds(id) ON DELETE CASCADE,
        CONSTRAINT fk_fishpond_feeding_legacy FOREIGN KEY (legacy_project_entry_id) REFERENCES project_entries(id) ON DELETE SET NULL,
        CONSTRAINT fk_fishpond_feeding_caretaker FOREIGN KEY (caretaker_id) REFERENCES people(id) ON DELETE SET NULL,
        CONSTRAINT fk_fishpond_feeding_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    execute_schema_statement($pdo, "CREATE TABLE IF NOT EXISTS fishpond_stock_movements (
        id INT AUTO_INCREMENT PRIMARY KEY,
        pond_id INT NOT NULL,
        legacy_project_entry_id INT DEFAULT NULL,
        movement_at DATETIME NOT NULL,
        movement_type VARCHAR(80) NOT NULL,
        quantity INT DEFAULT NULL,
        unit VARCHAR(40) DEFAULT NULL,
        remaining_stock INT DEFAULT NULL,
        mortality_count INT DEFAULT NULL,
        fish_growth_stage VARCHAR(120) DEFAULT NULL,
        caretaker_id INT DEFAULT NULL,
        remarks TEXT DEFAULT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_by INT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_fishpond_stock_legacy (legacy_project_entry_id),
        INDEX idx_fishpond_stock_pond_date (pond_id, movement_at),
        INDEX idx_fishpond_stock_type (movement_type),
        INDEX idx_fishpond_stock_caretaker (caretaker_id),
        CONSTRAINT fk_fishpond_stock_pond FOREIGN KEY (pond_id) REFERENCES ponds(id) ON DELETE CASCADE,
        CONSTRAINT fk_fishpond_stock_legacy FOREIGN KEY (legacy_project_entry_id) REFERENCES project_entries(id) ON DELETE SET NULL,
        CONSTRAINT fk_fishpond_stock_caretaker FOREIGN KEY (caretaker_id) REFERENCES people(id) ON DELETE SET NULL,
        CONSTRAINT fk_fishpond_stock_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    execute_schema_statement($pdo, "CREATE TABLE IF NOT EXISTS fishpond_expenses (
        id INT AUTO_INCREMENT PRIMARY KEY,
        pond_id INT NOT NULL,
        legacy_project_entry_id INT DEFAULT NULL,
        expense_date DATETIME NOT NULL,
        expense_type VARCHAR(120) DEFAULT NULL,
        amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        reference_no VARCHAR(120) DEFAULT NULL,
        remarks TEXT DEFAULT NULL,
        created_by INT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_fishpond_expense_legacy (legacy_project_entry_id),
        INDEX idx_fishpond_expenses_pond_date (pond_id, expense_date),
        INDEX idx_fishpond_expenses_type (expense_type),
        INDEX idx_fishpond_expenses_reference (reference_no),
        CONSTRAINT fk_fishpond_expenses_pond FOREIGN KEY (pond_id) REFERENCES ponds(id) ON DELETE CASCADE,
        CONSTRAINT fk_fishpond_expenses_legacy FOREIGN KEY (legacy_project_entry_id) REFERENCES project_entries(id) ON DELETE SET NULL,
        CONSTRAINT fk_fishpond_expenses_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    execute_schema_statement($pdo, "CREATE TABLE IF NOT EXISTS fishpond_harvest_records (
        id INT AUTO_INCREMENT PRIMARY KEY,
        pond_id INT NOT NULL,
        legacy_project_entry_id INT DEFAULT NULL,
        harvest_date DATETIME NOT NULL,
        kilos DECIMAL(12,2) DEFAULT NULL,
        price_per_kilo DECIMAL(12,2) DEFAULT NULL,
        total_sales DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        buyer VARCHAR(150) DEFAULT NULL,
        remarks TEXT DEFAULT NULL,
        created_by INT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_fishpond_harvest_legacy (legacy_project_entry_id),
        INDEX idx_fishpond_harvest_pond_date (pond_id, harvest_date),
        INDEX idx_fishpond_harvest_buyer (buyer),
        CONSTRAINT fk_fishpond_harvest_pond FOREIGN KEY (pond_id) REFERENCES ponds(id) ON DELETE CASCADE,
        CONSTRAINT fk_fishpond_harvest_legacy FOREIGN KEY (legacy_project_entry_id) REFERENCES project_entries(id) ON DELETE SET NULL,
        CONSTRAINT fk_fishpond_harvest_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    if (!schema_column_exists($pdo, 'fishpond_expenses', 'is_active')) {
        execute_schema_statement($pdo, 'ALTER TABLE fishpond_expenses ADD is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER remarks');
    }

    if (!schema_column_exists($pdo, 'fishpond_harvest_records', 'is_active')) {
        execute_schema_statement($pdo, 'ALTER TABLE fishpond_harvest_records ADD is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER remarks');
    }

    backfill_fishpond_records_from_project_entries($pdo);
}

function backfill_fishpond_records_from_project_entries(PDO $pdo): void
{
    if (!schema_table_exists($pdo, 'ponds')) {
        return;
    }

    execute_schema_statement($pdo, "INSERT IGNORE INTO fishpond_feeding_records (
            pond_id, legacy_project_entry_id, fed_at, feed_type, quantity, unit, cost, caretaker_id, remarks, created_by, created_at
        )
        SELECT
            p.id,
            pe.id,
            pe.entry_datetime,
            NULLIF(TRIM(COALESCE(feed_type_meta.meta_value, '')), ''),
            COALESCE(NULLIF(feed_quantity_meta.meta_value, ''), pe.quantity),
            NULLIF(TRIM(COALESCE(pe.unit, '')), ''),
            COALESCE(pe.amount, 0),
            CASE WHEN caretaker_person_meta.meta_value REGEXP '^[0-9]+$' THEN CAST(caretaker_person_meta.meta_value AS UNSIGNED) ELSE NULL END,
            NULLIF(TRIM(COALESCE(pe.notes, '')), ''),
            pe.created_by,
            COALESCE(pe.created_at, CURRENT_TIMESTAMP)
        FROM project_entries pe
        INNER JOIN project_categories pc ON pc.id = pe.category_id AND pc.slug = 'fishpond'
        INNER JOIN ponds p ON p.project_account_id = pe.account_id
        LEFT JOIN project_entry_meta record_type_meta ON record_type_meta.entry_id = pe.id AND record_type_meta.meta_key = 'fishpond_record_type'
        LEFT JOIN project_entry_meta feed_type_meta ON feed_type_meta.entry_id = pe.id AND feed_type_meta.meta_key = 'feed_type'
        LEFT JOIN project_entry_meta feed_quantity_meta ON feed_quantity_meta.entry_id = pe.id AND feed_quantity_meta.meta_key = 'feed_quantity'
        LEFT JOIN project_entry_meta caretaker_person_meta ON caretaker_person_meta.entry_id = pe.id AND caretaker_person_meta.meta_key = 'caretaker_person_id'
        WHERE COALESCE(record_type_meta.meta_value, CASE WHEN LOWER(COALESCE(pe.notes, '')) LIKE '%feed%' THEN 'feeding' ELSE pe.entry_type END) = 'feeding'");

    execute_schema_statement($pdo, "INSERT IGNORE INTO fishpond_stock_movements (
            pond_id, legacy_project_entry_id, movement_at, movement_type, quantity, unit, remaining_stock, mortality_count, fish_growth_stage, caretaker_id, remarks, created_by, created_at
        )
        SELECT
            p.id,
            pe.id,
            pe.entry_datetime,
            COALESCE(NULLIF(TRIM(movement_type_meta.meta_value), ''), CASE WHEN mortality_count_meta.meta_value IS NOT NULL THEN 'Mortality' ELSE 'Stock Movement' END),
            CASE WHEN pe.quantity IS NOT NULL THEN CAST(pe.quantity AS SIGNED) ELSE NULL END,
            NULLIF(TRIM(COALESCE(pe.unit, '')), ''),
            CASE WHEN remaining_stock_meta.meta_value REGEXP '^[0-9]+$' THEN CAST(remaining_stock_meta.meta_value AS UNSIGNED) ELSE NULL END,
            CASE WHEN mortality_count_meta.meta_value REGEXP '^[0-9]+$' THEN CAST(mortality_count_meta.meta_value AS UNSIGNED) ELSE NULL END,
            NULLIF(TRIM(COALESCE(fish_growth_stage_meta.meta_value, '')), ''),
            CASE WHEN caretaker_person_meta.meta_value REGEXP '^[0-9]+$' THEN CAST(caretaker_person_meta.meta_value AS UNSIGNED) ELSE NULL END,
            NULLIF(TRIM(COALESCE(pe.notes, '')), ''),
            pe.created_by,
            COALESCE(pe.created_at, CURRENT_TIMESTAMP)
        FROM project_entries pe
        INNER JOIN project_categories pc ON pc.id = pe.category_id AND pc.slug = 'fishpond'
        INNER JOIN ponds p ON p.project_account_id = pe.account_id
        LEFT JOIN project_entry_meta record_type_meta ON record_type_meta.entry_id = pe.id AND record_type_meta.meta_key = 'fishpond_record_type'
        LEFT JOIN project_entry_meta movement_type_meta ON movement_type_meta.entry_id = pe.id AND movement_type_meta.meta_key = 'movement_type'
        LEFT JOIN project_entry_meta remaining_stock_meta ON remaining_stock_meta.entry_id = pe.id AND remaining_stock_meta.meta_key = 'remaining_stock'
        LEFT JOIN project_entry_meta mortality_count_meta ON mortality_count_meta.entry_id = pe.id AND mortality_count_meta.meta_key = 'mortality_count'
        LEFT JOIN project_entry_meta fish_growth_stage_meta ON fish_growth_stage_meta.entry_id = pe.id AND fish_growth_stage_meta.meta_key = 'fish_growth_stage'
        LEFT JOIN project_entry_meta caretaker_person_meta ON caretaker_person_meta.entry_id = pe.id AND caretaker_person_meta.meta_key = 'caretaker_person_id'
        WHERE COALESCE(record_type_meta.meta_value, CASE WHEN LOWER(COALESCE(pe.notes, '')) LIKE '%stock%' OR LOWER(COALESCE(pe.notes, '')) LIKE '%mortality%' THEN 'stock_movement' ELSE '' END) IN ('stock_movement', 'mortality')");

    execute_schema_statement($pdo, "INSERT IGNORE INTO fishpond_expenses (
            pond_id, legacy_project_entry_id, expense_date, expense_type, amount, reference_no, remarks, created_by, created_at
        )
        SELECT
            p.id,
            pe.id,
            pe.entry_datetime,
            NULLIF(TRIM(COALESCE(expense_type_meta.meta_value, '')), ''),
            COALESCE(pe.amount, 0),
            NULLIF(TRIM(COALESCE(pe.reference_no, '')), ''),
            NULLIF(TRIM(COALESCE(pe.notes, '')), ''),
            pe.created_by,
            COALESCE(pe.created_at, CURRENT_TIMESTAMP)
        FROM project_entries pe
        INNER JOIN project_categories pc ON pc.id = pe.category_id AND pc.slug = 'fishpond'
        INNER JOIN ponds p ON p.project_account_id = pe.account_id
        LEFT JOIN project_entry_meta record_type_meta ON record_type_meta.entry_id = pe.id AND record_type_meta.meta_key = 'fishpond_record_type'
        LEFT JOIN project_entry_meta expense_type_meta ON expense_type_meta.entry_id = pe.id AND expense_type_meta.meta_key = 'expense_type'
        WHERE COALESCE(record_type_meta.meta_value, pe.entry_type) = 'expense'");

    execute_schema_statement($pdo, "INSERT IGNORE INTO fishpond_harvest_records (
            pond_id, legacy_project_entry_id, harvest_date, kilos, price_per_kilo, total_sales, buyer, remarks, created_by, created_at
        )
        SELECT
            p.id,
            pe.id,
            pe.entry_datetime,
            COALESCE(NULLIF(harvest_kilos_meta.meta_value, ''), pe.quantity),
            CASE WHEN price_per_kilo_meta.meta_value REGEXP '^[0-9]+(\\.[0-9]+)?$' THEN CAST(price_per_kilo_meta.meta_value AS DECIMAL(12,2)) ELSE NULL END,
            COALESCE(pe.amount, 0),
            NULLIF(TRIM(COALESCE(buyer_meta.meta_value, '')), ''),
            NULLIF(TRIM(COALESCE(pe.notes, '')), ''),
            pe.created_by,
            COALESCE(pe.created_at, CURRENT_TIMESTAMP)
        FROM project_entries pe
        INNER JOIN project_categories pc ON pc.id = pe.category_id AND pc.slug = 'fishpond'
        INNER JOIN ponds p ON p.project_account_id = pe.account_id
        LEFT JOIN project_entry_meta record_type_meta ON record_type_meta.entry_id = pe.id AND record_type_meta.meta_key = 'fishpond_record_type'
        LEFT JOIN project_entry_meta harvest_kilos_meta ON harvest_kilos_meta.entry_id = pe.id AND harvest_kilos_meta.meta_key = 'harvest_kilos'
        LEFT JOIN project_entry_meta price_per_kilo_meta ON price_per_kilo_meta.entry_id = pe.id AND price_per_kilo_meta.meta_key = 'price_per_kilo'
        LEFT JOIN project_entry_meta buyer_meta ON buyer_meta.entry_id = pe.id AND buyer_meta.meta_key = 'buyer'
        WHERE COALESCE(record_type_meta.meta_value, pe.entry_type) = 'harvest'");
}

function ensure_default_id_items(PDO $pdo): void
{
    $items = [
        ['sku' => 'ID-LACE-001', 'name' => 'ID Lace', 'category' => 'id_supplies', 'product_group' => 'product', 'type' => 'item', 'cost' => 10, 'selling' => 20, 'stock' => 0, 'threshold' => 10],
        ['sku' => 'ID-CARD-001', 'name' => 'ID Card', 'category' => 'id_supplies', 'product_group' => 'product', 'type' => 'item', 'cost' => 20, 'selling' => 40, 'stock' => 0, 'threshold' => 10],
        ['sku' => 'ID-PRINT-001', 'name' => 'ID Printing', 'category' => 'id_services', 'product_group' => 'service', 'type' => 'service', 'cost' => 15, 'selling' => 35, 'stock' => 0, 'threshold' => 0],
        ['sku' => 'ID-REPLACE-001', 'name' => 'ID Replacement', 'category' => 'id_services', 'product_group' => 'service', 'type' => 'service', 'cost' => 25, 'selling' => 60, 'stock' => 0, 'threshold' => 0],
        ['sku' => 'SEED-PRINT-BW', 'name' => 'Black and White Print', 'category' => 'printing', 'product_group' => 'service', 'type' => 'service', 'cost' => 1, 'selling' => 2, 'stock' => 0, 'threshold' => 0],
        ['sku' => 'SEED-PRINT-COLOR', 'name' => 'Colored Print', 'category' => 'printing', 'product_group' => 'service', 'type' => 'service', 'cost' => 5, 'selling' => 5, 'stock' => 0, 'threshold' => 0],
    ];

    $sql = 'INSERT INTO products (sku, name, category, product_group, type, cost_price, selling_price, stock_qty, low_stock_threshold)
        VALUES (:sku, :name, :category, :product_group, :type, :cost, :selling, :stock, :threshold)
        ON DUPLICATE KEY UPDATE
            name = VALUES(name),
            category = VALUES(category),
            product_group = VALUES(product_group),
            type = VALUES(type),
            stock_qty = CASE WHEN VALUES(type) = "service" THEN 0 ELSE stock_qty END,
            low_stock_threshold = CASE WHEN VALUES(type) = "service" THEN 0 ELSE low_stock_threshold END,
            is_active = 1';

    foreach ($items as $item) {
        try {
            $pdo->prepare($sql)->execute($item);
        } catch (PDOException $e) {
            if ((string) $e->getCode() !== 'HY000') {
                throw $e;
            }
            $pdo->prepare($sql)->execute($item);
        }
    }

    execute_schema_statement($pdo, "UPDATE products SET name = 'ID Printing', sku = COALESCE(NULLIF(sku, ''), 'ID-PRINT-001'), category = 'id_services', product_group = 'service', type = 'service', stock_qty = 0, low_stock_threshold = 0 WHERE sku = 'PRINT-ID' OR LOWER(name) = 'id printing'");
    execute_schema_statement($pdo, "UPDATE products SET name = 'Black and White Print', category = 'printing', product_group = 'service', type = 'service', selling_price = 2.00, stock_qty = 0, low_stock_threshold = 0 WHERE sku IN ('SEED-PRINT-BW', 'PRINT-BW-PAPER') OR LOWER(name) IN ('black and white print paper', 'black and white print')");
    execute_schema_statement($pdo, "UPDATE products SET name = 'Colored Print', category = 'printing', product_group = 'service', type = 'service', selling_price = 5.00, stock_qty = 0, low_stock_threshold = 0 WHERE sku = 'SEED-PRINT-COLOR' OR LOWER(name) IN ('print (colored)', 'colored print')");
    execute_schema_statement($pdo, "UPDATE products SET selling_price = 5.00, stock_qty = 0, low_stock_threshold = 0 WHERE sku IN ('SEED-PHOTO-COLOR', 'PHOTO-COLOR') OR LOWER(name) IN ('photocopy - color', 'photocopy - colored')");
}

function ensure_database_programmability(PDO $pdo): void
{
    $statements = [
        "CREATE OR REPLACE VIEW v_daily_sales_summary AS
            SELECT
                DATE(sh.sale_date) AS report_date,
                COUNT(DISTINCT sh.id) AS total_sales,
                COALESCE(SUM(si.quantity), 0) AS units_sold,
                COALESCE(SUM(si.total_amount), 0) AS revenue,
                COALESCE(SUM(si.total_cost), 0) AS cost,
                COALESCE(SUM(si.profit), 0) AS profit
            FROM sale_headers sh
            INNER JOIN sale_items si ON si.sale_header_id = sh.id
            WHERE sh.payment_status <> 'void'
            GROUP BY DATE(sh.sale_date)",

        "CREATE OR REPLACE VIEW v_cashflow_summary AS
            SELECT
                DATE(ct.txn_date) AS report_date,
                ct.source_module,
                COALESCE(SUM(CASE WHEN ct.direction = 'in' THEN ct.amount ELSE 0 END), 0) AS cash_in,
                COALESCE(SUM(CASE WHEN ct.direction = 'out' THEN ct.amount ELSE 0 END), 0) AS cash_out,
                COALESCE(SUM(CASE WHEN ct.direction = 'in' THEN ct.amount ELSE -ct.amount END), 0) AS net_cash
            FROM cash_transactions ct
            GROUP BY DATE(ct.txn_date), ct.source_module",

        "CREATE OR REPLACE VIEW v_project_category_summary AS
            SELECT
                pc.id AS category_id,
                pc.slug,
                pc.name,
                COALESCE(SUM(CASE WHEN pe.entry_type IN ('income', 'payment', 'harvest') THEN pe.amount ELSE 0 END), 0) AS income,
                COALESCE(SUM(CASE WHEN pe.entry_type = 'expense' THEN pe.amount ELSE 0 END), 0) AS expense,
                COALESCE(SUM(CASE WHEN pe.entry_type IN ('income', 'payment', 'harvest') THEN pe.amount WHEN pe.entry_type = 'expense' THEN -pe.amount ELSE 0 END), 0) AS net_income
            FROM project_categories pc
            LEFT JOIN project_entries pe ON pe.category_id = pc.id
            WHERE pc.is_active = 1
            GROUP BY pc.id, pc.slug, pc.name",

        "CREATE OR REPLACE VIEW v_inventory_category_summary AS
            SELECT
                category,
                COUNT(*) AS product_count,
                COALESCE(SUM(CASE WHEN type = 'item' THEN stock_qty ELSE 0 END), 0) AS stock_units,
                COALESCE(SUM(CASE WHEN type = 'item' THEN stock_qty * selling_price ELSE 0 END), 0) AS stock_value,
                COALESCE(SUM(CASE WHEN type = 'item' AND stock_qty <= low_stock_threshold THEN 1 ELSE 0 END), 0) AS low_stock_count
            FROM products
            WHERE is_active = 1
            GROUP BY category",

        "CREATE OR REPLACE VIEW v_inventory_group_summary AS
            SELECT
                product_group,
                COUNT(*) AS product_count,
                COALESCE(SUM(CASE WHEN type = 'item' THEN stock_qty ELSE 0 END), 0) AS stock_units,
                COALESCE(SUM(CASE WHEN type = 'item' THEN stock_qty * selling_price ELSE 0 END), 0) AS stock_value,
                COALESCE(SUM(CASE WHEN type = 'item' AND stock_qty <= low_stock_threshold THEN 1 ELSE 0 END), 0) AS low_stock_count
            FROM products
            WHERE is_active = 1
            GROUP BY product_group",

        "CREATE OR REPLACE VIEW v_overdue_project_accounts AS
            SELECT
                pa.id,
                pa.account_name,
                pa.code,
                pa.contact_name,
                pa.next_due_date,
                pa.expected_amount,
                pc.slug AS category_slug,
                pc.name AS category_name
            FROM project_accounts pa
            INNER JOIN project_categories pc ON pc.id = pa.category_id
            WHERE pa.status = 'active' AND pa.next_due_date IS NOT NULL AND pa.next_due_date < CURDATE()",

        "DROP PROCEDURE IF EXISTS sp_sales_summary_by_period",

        "CREATE PROCEDURE sp_sales_summary_by_period(IN p_start DATETIME, IN p_end_exclusive DATETIME)
            SELECT
                COUNT(DISTINCT sh.id) AS total_sales,
                COALESCE(SUM(si.total_amount), 0) AS revenue,
                COALESCE(SUM(si.total_cost), 0) AS cost,
                COALESCE(SUM(si.profit), 0) AS profit
            FROM sale_headers sh
            INNER JOIN sale_items si ON si.sale_header_id = sh.id
            WHERE sh.sale_date >= p_start AND sh.sale_date < p_end_exclusive
                AND sh.payment_status <> 'void'",

        "DROP PROCEDURE IF EXISTS sp_cash_summary_by_period",

        "CREATE PROCEDURE sp_cash_summary_by_period(IN p_start DATETIME, IN p_end_exclusive DATETIME)
            SELECT
                COALESCE(SUM(CASE WHEN direction = 'in' THEN amount ELSE 0 END), 0) AS cash_in,
                COALESCE(SUM(CASE WHEN direction = 'out' THEN amount ELSE 0 END), 0) AS cash_out,
                COALESCE(SUM(CASE WHEN direction = 'in' THEN amount ELSE -amount END), 0) AS net_cash
            FROM cash_transactions
            WHERE txn_date >= p_start AND txn_date < p_end_exclusive",

        "DROP PROCEDURE IF EXISTS sp_dashboard_summary",

        "CREATE PROCEDURE sp_dashboard_summary()
            SELECT
                (SELECT COALESCE(SUM(si.total_amount), 0) FROM sale_headers sh INNER JOIN sale_items si ON si.sale_header_id = sh.id WHERE sh.sale_date >= CURDATE() AND sh.sale_date < DATE_ADD(CURDATE(), INTERVAL 1 DAY) AND sh.payment_status <> 'void') AS today_sales,
                (SELECT COALESCE(SUM(si.profit), 0) FROM sale_headers sh INNER JOIN sale_items si ON si.sale_header_id = sh.id WHERE sh.sale_date >= CURDATE() AND sh.sale_date < DATE_ADD(CURDATE(), INTERVAL 1 DAY) AND sh.payment_status <> 'void') AS today_profit,
                (SELECT COALESCE(SUM(CASE WHEN direction = 'in' THEN amount ELSE 0 END), 0) FROM cash_transactions WHERE txn_date >= CURDATE() AND txn_date < DATE_ADD(CURDATE(), INTERVAL 1 DAY)) AS today_cash_in,
                (SELECT COALESCE(SUM(CASE WHEN direction = 'out' THEN amount ELSE 0 END), 0) FROM cash_transactions WHERE txn_date >= CURDATE() AND txn_date < DATE_ADD(CURDATE(), INTERVAL 1 DAY)) AS today_cash_out,
                (SELECT COUNT(*) FROM v_overdue_project_accounts WHERE category_slug = 'rental') AS overdue_rentals,
                (SELECT COALESCE(SUM(low_stock_count), 0) FROM v_inventory_category_summary) AS low_stock_items",

        "DROP TRIGGER IF EXISTS trg_products_validate_insert",

        "CREATE TRIGGER trg_products_validate_insert
            BEFORE INSERT ON products
            FOR EACH ROW
            BEGIN
                IF NEW.cost_price < 0 OR NEW.selling_price < 0 OR NEW.stock_qty < 0 OR NEW.low_stock_threshold < 0 THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Product numeric fields cannot be negative';
                END IF;
                IF NEW.category IN ('printing', 'photocopy', 'id_services') OR NEW.product_group = 'service' THEN
                    SET NEW.type = 'service';
                    SET NEW.product_group = 'service';
                END IF;
                IF NEW.type = 'service' THEN
                    SET NEW.product_group = 'service';
                    SET NEW.stock_qty = 0;
                END IF;
            END",

        "DROP TRIGGER IF EXISTS trg_products_validate_update",

        "CREATE TRIGGER trg_products_validate_update
            BEFORE UPDATE ON products
            FOR EACH ROW
            BEGIN
                IF NEW.cost_price < 0 OR NEW.selling_price < 0 OR NEW.stock_qty < 0 OR NEW.low_stock_threshold < 0 THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Product numeric fields cannot be negative';
                END IF;
                IF NEW.category IN ('printing', 'photocopy', 'id_services') OR NEW.product_group = 'service' THEN
                    SET NEW.type = 'service';
                    SET NEW.product_group = 'service';
                END IF;
                IF NEW.type = 'service' THEN
                    SET NEW.product_group = 'service';
                    SET NEW.stock_qty = 0;
                END IF;
            END",

        "DROP TRIGGER IF EXISTS trg_sales_validate_insert",

        "CREATE TRIGGER trg_sales_validate_insert
            BEFORE INSERT ON sales
            FOR EACH ROW
            BEGIN
                IF NEW.quantity <= 0 OR NEW.unit_price < 0 OR NEW.unit_cost < 0 OR NEW.total_amount < 0 OR NEW.total_cost < 0 THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Sale values must be valid and non-negative';
                END IF;
            END",
    ];

    foreach ($statements as $statement) {
        execute_schema_statement($pdo, $statement);
    }
}

function schema_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*)
        FROM information_schema.tables
        WHERE table_schema = DATABASE() AND table_name = :table');
    $stmt->execute(['table' => $table]);

    return (int) $stmt->fetchColumn() > 0;
}

function schema_column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*)
        FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = :table AND column_name = :column');
    $stmt->execute([
        'table' => $table,
        'column' => $column,
    ]);

    return (int) $stmt->fetchColumn() > 0;
}

function schema_index_exists(PDO $pdo, string $table, string $index): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*)
        FROM information_schema.statistics
        WHERE table_schema = DATABASE() AND table_name = :table AND index_name = :index_name');
    $stmt->execute([
        'table' => $table,
        'index_name' => $index,
    ]);

    return (int) $stmt->fetchColumn() > 0;
}

function dedupe_people_by_code(PDO $pdo): void
{
    try {
        if (!schema_table_exists($pdo, 'people')) {
            return;
        }

        $duplicates = $pdo->query('SELECT person_code
            FROM people
            WHERE person_code IS NOT NULL AND TRIM(person_code) <> ""
            GROUP BY person_code
            HAVING COUNT(*) > 1')->fetchAll(PDO::FETCH_COLUMN);

        foreach ($duplicates as $personCode) {
            $stmt = $pdo->prepare('SELECT id
                FROM people
                WHERE person_code = :person_code
                ORDER BY FIELD(status, "approved", "pending", "inactive"), id');
            $stmt->execute(['person_code' => $personCode]);
            $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

            if (count($ids) < 2) {
                continue;
            }

            $canonicalId = array_shift($ids);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));

            if (schema_column_exists($pdo, 'office_logbook', 'person_id')) {
                $update = $pdo->prepare('UPDATE office_logbook SET person_id = ? WHERE person_id IN (' . $placeholders . ')');
                $update->execute(array_merge([$canonicalId], $ids));
            }
            if (schema_column_exists($pdo, 'project_accounts', 'person_id')) {
                $update = $pdo->prepare('UPDATE project_accounts SET person_id = ? WHERE person_id IN (' . $placeholders . ')');
                $update->execute(array_merge([$canonicalId], $ids));
            }
            if (schema_column_exists($pdo, 'proposals', 'proposer_id')) {
                $update = $pdo->prepare('UPDATE proposals SET proposer_id = ? WHERE proposer_id IN (' . $placeholders . ')');
                $update->execute(array_merge([$canonicalId], $ids));
            }

            $delete = $pdo->prepare('DELETE FROM people WHERE id IN (' . $placeholders . ')');
            $delete->execute($ids);
        }
    } catch (Throwable $e) {
        error_log('People dedupe warning: ' . $e->getMessage());
    }
}

function schema_find_or_create_person(PDO $pdo, string $fullName, ?string $personCode = null, ?string $department = null, ?string $role = null): ?int
{
    $fullName = preg_replace('/\s+/', ' ', trim($fullName)) ?? '';
    $personCode = strtoupper(preg_replace('/\s+/', ' ', trim((string) $personCode)) ?? '');
    $department = preg_replace('/\s+/', ' ', trim((string) $department)) ?? '';
    $role = preg_replace('/\s+/', ' ', trim((string) $role)) ?? '';

    if ($fullName === '') {
        return null;
    }

    if ($personCode !== '') {
        $stmt = $pdo->prepare('SELECT id FROM people WHERE person_code = :person_code ORDER BY FIELD(status, "approved", "pending", "inactive"), id LIMIT 1');
        $stmt->execute(['person_code' => $personCode]);
        $id = $stmt->fetchColumn();
        if ($id) {
            return (int) $id;
        }
    }

    $stmt = $pdo->prepare('SELECT id FROM people WHERE full_name = :full_name AND COALESCE(department, "") = :department ORDER BY FIELD(status, "approved", "pending", "inactive"), id LIMIT 1');
    $stmt->execute([
        'full_name' => $fullName,
        'department' => $department,
    ]);
    $id = $stmt->fetchColumn();
    if ($id) {
        return (int) $id;
    }

    $insert = $pdo->prepare('INSERT INTO people (full_name, person_code, department, role_or_position, status, approved_at)
        VALUES (:full_name, :person_code, :department, :role_or_position, "approved", NOW())');
    $insert->execute([
        'full_name' => $fullName,
        'person_code' => $personCode !== '' ? $personCode : null,
        'department' => $department !== '' ? $department : null,
        'role_or_position' => $role !== '' ? $role : null,
    ]);

    return (int) $pdo->lastInsertId();
}

function migrate_legacy_people_references(PDO $pdo): void
{
    try {
        if (schema_column_exists($pdo, 'proposals', 'proposer_id')) {
            $rows = $pdo->query('SELECT id, proposer_name, department FROM proposals WHERE proposer_id IS NULL AND proposer_name IS NOT NULL AND proposer_name <> ""')->fetchAll();
            $update = $pdo->prepare('UPDATE proposals SET proposer_id = :person_id WHERE id = :id AND proposer_id IS NULL');
            foreach ($rows as $row) {
                $personId = schema_find_or_create_person($pdo, (string) $row['proposer_name'], null, $row['department'] !== null ? (string) $row['department'] : null, 'Proposer');
                if ($personId !== null) {
                    $update->execute(['person_id' => $personId, 'id' => (int) $row['id']]);
                }
            }
        }

        if (schema_column_exists($pdo, 'project_accounts', 'person_id')) {
            $rows = $pdo->query('SELECT pa.id, pa.account_name, pa.code, pa.contact_name, pc.slug
                FROM project_accounts pa
                INNER JOIN project_categories pc ON pc.id = pa.category_id
                WHERE pa.person_id IS NULL AND pc.slug IN ("rental", "toga") AND pa.account_name IS NOT NULL AND pa.account_name <> ""')->fetchAll();
            $update = $pdo->prepare('UPDATE project_accounts SET person_id = :person_id WHERE id = :id AND person_id IS NULL');
            foreach ($rows as $row) {
                $code = (string) $row['slug'] === 'toga' ? (string) ($row['code'] ?? '') : null;
                $department = (string) $row['slug'] === 'toga' ? (string) ($row['contact_name'] ?? '') : null;
                $role = (string) $row['slug'] === 'rental' ? (string) ($row['contact_name'] ?? 'Rental Account') : 'Toga Release';
                $personId = schema_find_or_create_person($pdo, (string) $row['account_name'], $code, $department, $role);
                if ($personId !== null) {
                    $update->execute(['person_id' => $personId, 'id' => (int) $row['id']]);
                }
            }
        }

        if (schema_column_exists($pdo, 'office_logbook', 'person_id')) {
            $rows = $pdo->query('SELECT id, student_name, student_id FROM office_logbook WHERE person_id IS NULL AND student_name IS NOT NULL AND student_name <> ""')->fetchAll();
            $update = $pdo->prepare('UPDATE office_logbook SET person_id = :person_id WHERE id = :id AND person_id IS NULL');
            foreach ($rows as $row) {
                $personId = schema_find_or_create_person($pdo, (string) $row['student_name'], $row['student_id'] !== null ? (string) $row['student_id'] : null, null, 'Student');
                if ($personId !== null) {
                    $update->execute(['person_id' => $personId, 'id' => (int) $row['id']]);
                }
            }
        }
    } catch (Throwable $e) {
        error_log('People reference migration warning: ' . $e->getMessage());
    }
}

function schema_constraint_exists(PDO $pdo, string $table, string $constraint): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*)
        FROM information_schema.table_constraints
        WHERE table_schema = DATABASE() AND table_name = :table AND constraint_name = :constraint_name');
    $stmt->execute([
        'table' => $table,
        'constraint_name' => $constraint,
    ]);

    return (int) $stmt->fetchColumn() > 0;
}

function ensure_cash_transaction_relations(PDO $pdo): void
{
    execute_schema_statement($pdo, "ALTER TABLE cash_transactions MODIFY source_module VARCHAR(80) NOT NULL DEFAULT 'manual'");

    if (!schema_column_exists($pdo, 'cash_transactions', 'manual_category')) {
        execute_schema_statement($pdo, 'ALTER TABLE cash_transactions ADD manual_category VARCHAR(100) DEFAULT NULL AFTER source_module');
    }

    if (!schema_column_exists($pdo, 'sales', 'person_id')) {
        execute_schema_statement($pdo, 'ALTER TABLE sales ADD person_id INT DEFAULT NULL AFTER product_id');
    }

    if (!schema_index_exists($pdo, 'sales', 'idx_sales_person')) {
        execute_schema_statement($pdo, 'ALTER TABLE sales ADD INDEX idx_sales_person (person_id)');
    }

    if (!schema_column_exists($pdo, 'cash_transactions', 'project_entry_id')) {
        execute_schema_statement($pdo, 'ALTER TABLE cash_transactions ADD project_entry_id INT DEFAULT NULL AFTER source_module');
    }

    if (!schema_index_exists($pdo, 'cash_transactions', 'idx_cash_project_entry')) {
        execute_schema_statement($pdo, 'ALTER TABLE cash_transactions ADD INDEX idx_cash_project_entry (project_entry_id)');
    }

    $indexStatements = [
        ['products', 'idx_products_active_name', 'ALTER TABLE products ADD INDEX idx_products_active_name (is_active, name)'],
        ['products', 'idx_products_active_category', 'ALTER TABLE products ADD INDEX idx_products_active_category (is_active, category)'],
        ['products', 'idx_products_active_sku', 'ALTER TABLE products ADD INDEX idx_products_active_sku (is_active, sku)'],
        ['sales', 'idx_sales_date_product', 'ALTER TABLE sales ADD INDEX idx_sales_date_product (sale_date, product_id)'],
        ['project_accounts', 'idx_project_account_category_status', 'ALTER TABLE project_accounts ADD INDEX idx_project_account_category_status (category_id, status)'],
        ['project_accounts', 'idx_project_account_name_code', 'ALTER TABLE project_accounts ADD INDEX idx_project_account_name_code (account_name, code)'],
        ['project_entries', 'idx_project_entries_category_datetime', 'ALTER TABLE project_entries ADD INDEX idx_project_entries_category_datetime (category_id, entry_datetime)'],
        ['cash_transactions', 'idx_cash_source_direction_date', 'ALTER TABLE cash_transactions ADD INDEX idx_cash_source_direction_date (source_module, direction, txn_date)'],
        ['cash_transactions', 'idx_cash_or_number', 'ALTER TABLE cash_transactions ADD INDEX idx_cash_or_number (or_number)'],
        ['office_logbook', 'idx_logbook_student_name', 'ALTER TABLE office_logbook ADD INDEX idx_logbook_student_name (student_name)'],
        ['office_logbook', 'idx_logbook_student_id', 'ALTER TABLE office_logbook ADD INDEX idx_logbook_student_id (student_id)'],
        ['office_logbook', 'idx_logbook_or_number', 'ALTER TABLE office_logbook ADD INDEX idx_logbook_or_number (or_number)'],
        ['business_center_content', 'idx_bc_active_section', 'ALTER TABLE business_center_content ADD INDEX idx_bc_active_section (is_active, section_key)'],
    ];

    foreach ($indexStatements as [$table, $index, $sql]) {
        if (!schema_index_exists($pdo, $table, $index)) {
            execute_schema_statement($pdo, $sql);
        }
    }

    if (!schema_constraint_exists($pdo, 'cash_transactions', 'fk_cash_project_entry')) {
        execute_schema_statement($pdo, 'ALTER TABLE cash_transactions ADD CONSTRAINT fk_cash_project_entry FOREIGN KEY (project_entry_id) REFERENCES project_entries(id) ON DELETE SET NULL');
    }

    if (!schema_constraint_exists($pdo, 'sales', 'fk_sales_person')) {
        execute_schema_statement($pdo, 'ALTER TABLE sales ADD CONSTRAINT fk_sales_person FOREIGN KEY (person_id) REFERENCES people(id) ON DELETE SET NULL');
    }
}

function ensure_normalized_pos_schema(PDO $pdo): void
{
    execute_schema_statement($pdo, "CREATE TABLE IF NOT EXISTS sale_headers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        or_number VARCHAR(100) DEFAULT NULL,
        sale_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        person_id INT DEFAULT NULL,
        total_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        total_cost DECIMAL(12,2) NOT NULL DEFAULT 0,
        total_profit DECIMAL(12,2) NOT NULL DEFAULT 0,
        payment_status ENUM('unpaid', 'partial', 'paid', 'void') NOT NULL DEFAULT 'paid',
        notes VARCHAR(255) DEFAULT NULL,
        created_by INT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_sale_headers_or_number (or_number),
        INDEX idx_sale_headers_date (sale_date),
        INDEX idx_sale_headers_person (person_id),
        INDEX idx_sale_headers_created_by (created_by),
        CONSTRAINT fk_sale_headers_person FOREIGN KEY (person_id) REFERENCES people(id) ON DELETE SET NULL,
        CONSTRAINT fk_sale_headers_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    if (!schema_column_exists($pdo, 'sale_headers', 'voided_by')) {
        execute_schema_statement($pdo, 'ALTER TABLE sale_headers ADD voided_by INT DEFAULT NULL AFTER created_by');
    }
    if (!schema_column_exists($pdo, 'sale_headers', 'voided_at')) {
        execute_schema_statement($pdo, 'ALTER TABLE sale_headers ADD voided_at DATETIME DEFAULT NULL AFTER voided_by');
    }
    if (!schema_column_exists($pdo, 'sale_headers', 'void_reason')) {
        execute_schema_statement($pdo, 'ALTER TABLE sale_headers ADD void_reason TEXT DEFAULT NULL AFTER voided_at');
    }
    if (!schema_index_exists($pdo, 'sale_headers', 'idx_sale_headers_status_date')) {
        execute_schema_statement($pdo, 'ALTER TABLE sale_headers ADD INDEX idx_sale_headers_status_date (payment_status, sale_date)');
    }
    if (!schema_index_exists($pdo, 'sale_headers', 'idx_sale_headers_voided_by')) {
        execute_schema_statement($pdo, 'ALTER TABLE sale_headers ADD INDEX idx_sale_headers_voided_by (voided_by)');
    }
    if (!schema_constraint_exists($pdo, 'sale_headers', 'fk_sale_headers_voided_by')) {
        execute_schema_statement($pdo, 'ALTER TABLE sale_headers ADD CONSTRAINT fk_sale_headers_voided_by FOREIGN KEY (voided_by) REFERENCES users(id) ON DELETE SET NULL');
    }

    execute_schema_statement($pdo, "CREATE TABLE IF NOT EXISTS sale_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        sale_header_id INT NOT NULL,
        legacy_sale_id INT DEFAULT NULL,
        product_id INT NOT NULL,
        quantity INT NOT NULL,
        unit_price DECIMAL(12,2) NOT NULL DEFAULT 0,
        unit_cost DECIMAL(12,2) NOT NULL DEFAULT 0,
        total_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        total_cost DECIMAL(12,2) NOT NULL DEFAULT 0,
        profit DECIMAL(12,2) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_sale_items_legacy_sale (legacy_sale_id),
        INDEX idx_sale_items_header (sale_header_id),
        INDEX idx_sale_items_product (product_id),
        CONSTRAINT fk_sale_items_header FOREIGN KEY (sale_header_id) REFERENCES sale_headers(id) ON DELETE CASCADE,
        CONSTRAINT fk_sale_items_legacy_sale FOREIGN KEY (legacy_sale_id) REFERENCES sales(id) ON DELETE SET NULL,
        CONSTRAINT fk_sale_items_product FOREIGN KEY (product_id) REFERENCES products(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    if (!schema_column_exists($pdo, 'sale_items', 'profit')) {
        $afterTotalCost = schema_column_exists($pdo, 'sale_items', 'total_cost') ? ' AFTER total_cost' : '';
        execute_schema_statement($pdo, 'ALTER TABLE sale_items ADD profit DECIMAL(12,2) NOT NULL DEFAULT 0' . $afterTotalCost);

        if (schema_column_exists($pdo, 'sale_items', 'total_amount') && schema_column_exists($pdo, 'sale_items', 'total_cost')) {
            execute_schema_statement($pdo, 'UPDATE sale_items SET profit = total_amount - total_cost');
        }
    }

    execute_schema_statement($pdo, "CREATE TABLE IF NOT EXISTS sale_payments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        sale_header_id INT NOT NULL,
        payment_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        method VARCHAR(40) NOT NULL DEFAULT 'cash',
        reference_no VARCHAR(100) DEFAULT NULL,
        received_by INT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_sale_payments_header (sale_header_id),
        INDEX idx_sale_payments_date (payment_date),
        INDEX idx_sale_payments_received_by (received_by),
        CONSTRAINT fk_sale_payments_header FOREIGN KEY (sale_header_id) REFERENCES sale_headers(id) ON DELETE CASCADE,
        CONSTRAINT fk_sale_payments_received_by FOREIGN KEY (received_by) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    backfill_normalized_pos_sales($pdo);
}

function backfill_normalized_pos_sales(PDO $pdo): void
{
    if (!schema_table_exists($pdo, 'sale_headers') || !schema_table_exists($pdo, 'sale_items') || !schema_table_exists($pdo, 'sale_payments')) {
        return;
    }

    execute_schema_statement($pdo, "INSERT INTO sale_headers (or_number, sale_date, person_id, total_amount, total_cost, total_profit, payment_status, notes, created_by, created_at)
        SELECT grouped.or_number, grouped.sale_date, grouped.person_id, grouped.total_amount, grouped.total_cost, grouped.total_profit, 'paid', grouped.notes, grouped.created_by, grouped.created_at
        FROM (
            SELECT
                COALESCE(NULLIF(TRIM(or_number), ''), CONCAT('LEGACY-', id)) AS or_number,
                MIN(sale_date) AS sale_date,
                MIN(person_id) AS person_id,
                SUM(total_amount) AS total_amount,
                SUM(total_cost) AS total_cost,
                SUM(total_profit) AS total_profit,
                NULLIF(SUBSTRING(GROUP_CONCAT(NULLIF(notes, '') ORDER BY id SEPARATOR ' | '), 1, 255), '') AS notes,
                MIN(created_by) AS created_by,
                MIN(created_at) AS created_at
            FROM sales
            GROUP BY COALESCE(NULLIF(TRIM(or_number), ''), CONCAT('LEGACY-', id))
        ) grouped
        LEFT JOIN sale_headers existing ON existing.or_number = grouped.or_number
        WHERE existing.id IS NULL");

    execute_schema_statement($pdo, "INSERT IGNORE INTO sale_items (sale_header_id, legacy_sale_id, product_id, quantity, unit_price, unit_cost, total_amount, total_cost, profit, created_at)
        SELECT sh.id, s.id, s.product_id, s.quantity, s.unit_price, s.unit_cost, s.total_amount, s.total_cost, s.total_profit, s.created_at
        FROM sales s
        INNER JOIN sale_headers sh ON sh.or_number = COALESCE(NULLIF(TRIM(s.or_number), ''), CONCAT('LEGACY-', s.id))
        LEFT JOIN sale_items existing ON existing.legacy_sale_id = s.id
        WHERE existing.id IS NULL");

    execute_schema_statement($pdo, "INSERT INTO sale_payments (sale_header_id, payment_date, amount, method, reference_no, received_by, created_at)
        SELECT sh.id, sh.sale_date, sh.total_amount, 'cash', sh.or_number, sh.created_by, sh.created_at
        FROM sale_headers sh
        LEFT JOIN sale_payments existing ON existing.sale_header_id = sh.id
        WHERE existing.id IS NULL");
}

function schema_project_category_id(PDO $pdo, string $slug, string $name, string $description): int
{
    $stmt = $pdo->prepare('INSERT INTO project_categories (slug, name, description, is_active)
        VALUES (:slug, :name, :description, 1)
        ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description), is_active = 1');
    $stmt->execute([
        'slug' => $slug,
        'name' => $name,
        'description' => $description,
    ]);

    $find = $pdo->prepare('SELECT id FROM project_categories WHERE slug = :slug');
    $find->execute(['slug' => $slug]);

    return (int) $find->fetchColumn();
}

function schema_project_account_meta_set(PDO $pdo, int $accountId, string $key, ?string $value): void
{
    $stmt = $pdo->prepare('INSERT INTO project_account_meta (account_id, meta_key, meta_value)
        VALUES (:account_id, :meta_key, :meta_value)
        ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)');
    $stmt->execute([
        'account_id' => $accountId,
        'meta_key' => substr($key, 0, 80),
        'meta_value' => $value,
    ]);
}

function schema_project_entry_meta_set(PDO $pdo, int $entryId, string $key, ?string $value): void
{
    $stmt = $pdo->prepare('INSERT INTO project_entry_meta (entry_id, meta_key, meta_value)
        VALUES (:entry_id, :meta_key, :meta_value)
        ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)');
    $stmt->execute([
        'entry_id' => $entryId,
        'meta_key' => substr($key, 0, 80),
        'meta_value' => $value,
    ]);
}

function schema_find_legacy_account(PDO $pdo, string $source, int $legacyId): ?int
{
    $stmt = $pdo->prepare('SELECT src.account_id
        FROM project_account_meta src
        INNER JOIN project_account_meta legacy_id
            ON legacy_id.account_id = src.account_id
            AND legacy_id.meta_key = "legacy_id"
            AND legacy_id.meta_value = :legacy_id
        WHERE src.meta_key = "legacy_source" AND src.meta_value = :source
        ');
    $stmt->execute([
        'source' => $source,
        'legacy_id' => (string) $legacyId,
    ]);
    $accountId = $stmt->fetchColumn();

    return $accountId !== false ? (int) $accountId : null;
}

function schema_legacy_entry_exists(PDO $pdo, string $source, int $legacyId, ?string $event = null): bool
{
    $sql = 'SELECT src.entry_id
        FROM project_entry_meta src
        INNER JOIN project_entry_meta legacy_id
            ON legacy_id.entry_id = src.entry_id
            AND legacy_id.meta_key = "legacy_id"
            AND legacy_id.meta_value = :legacy_id';
    $params = [
        'source' => $source,
        'legacy_id' => (string) $legacyId,
    ];

    if ($event !== null) {
        $sql .= ' INNER JOIN project_entry_meta event_meta
            ON event_meta.entry_id = src.entry_id
            AND event_meta.meta_key = "entry_event"
            AND event_meta.meta_value = :event';
        $params['event'] = $event;
    }

    $sql .= ' WHERE src.meta_key = "legacy_source" AND src.meta_value = :source';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchColumn() !== false;
}

function schema_insert_project_entry(PDO $pdo, array $data, array $meta = []): int
{
    $stmt = $pdo->prepare('INSERT INTO project_entries (category_id, account_id, entry_datetime, entry_type, quantity, unit, amount, reference_no, notes, created_by)
        VALUES (:category_id, :account_id, :entry_datetime, :entry_type, :quantity, :unit, :amount, :reference_no, :notes, :created_by)');
    $stmt->execute([
        'category_id' => $data['category_id'],
        'account_id' => $data['account_id'] ?? null,
        'entry_datetime' => $data['entry_datetime'],
        'entry_type' => $data['entry_type'],
        'quantity' => $data['quantity'] ?? null,
        'unit' => $data['unit'] ?? null,
        'amount' => $data['amount'] ?? 0,
        'reference_no' => $data['reference_no'] ?? null,
        'notes' => $data['notes'] ?? null,
        'created_by' => $data['created_by'] ?? null,
    ]);
    $entryId = (int) $pdo->lastInsertId();

    foreach ($meta as $key => $value) {
        schema_project_entry_meta_set($pdo, $entryId, (string) $key, $value !== null ? (string) $value : null);
    }

    return $entryId;
}

function migrate_legacy_project_tables(PDO $pdo): void
{
    migrate_legacy_fishpond_records($pdo);
    migrate_legacy_rentals($pdo);
    migrate_legacy_toga_records($pdo);
}

function migrate_legacy_fishpond_records(PDO $pdo): void
{
    if (!schema_table_exists($pdo, 'fishpond_records')) {
        return;
    }

    $categoryId = schema_project_category_id(
        $pdo,
        'fishpond',
        'Fishpond',
        'Fishpond monitoring, harvest tracking, and fishpond expenses/income.'
    );

    $records = $pdo->query('SELECT id, record_date, activity_type, quantity, unit, amount, notes, created_by FROM fishpond_records ORDER BY id ASC')->fetchAll();
    foreach ($records as $record) {
        $legacyId = (int) $record['id'];
        if (schema_legacy_entry_exists($pdo, 'fishpond_records', $legacyId)) {
            continue;
        }

        $activity = (string) $record['activity_type'];
        $entryType = match ($activity) {
            'income' => 'income',
            'expense' => 'expense',
            'harvest' => 'harvest',
            default => 'monitoring',
        };

        schema_insert_project_entry($pdo, [
            'category_id' => $categoryId,
            'entry_datetime' => (string) $record['record_date'] . ' 00:00:00',
            'entry_type' => $entryType,
            'quantity' => $record['quantity'],
            'unit' => $record['unit'],
            'amount' => $record['amount'] ?? 0,
            'notes' => $record['notes'],
            'created_by' => $record['created_by'],
        ], [
            'legacy_source' => 'fishpond_records',
            'legacy_id' => (string) $legacyId,
            'activity_type' => $activity,
        ]);
    }

    $pdo->exec('DROP TABLE IF EXISTS fishpond_records');
}

function migrate_legacy_rentals(PDO $pdo): void
{
    $hasRentals = schema_table_exists($pdo, 'rentals');
    $hasPayments = schema_table_exists($pdo, 'rental_payments');
    if (!$hasRentals && !$hasPayments) {
        return;
    }

    $categoryId = schema_project_category_id(
        $pdo,
        'rental',
        'Rental and Stalls',
        'School stall rentals and renewal payment monitoring.'
    );

    if ($hasRentals) {
        $rentals = $pdo->query('SELECT id, tenant_name, stall_name, category, start_date, next_due_date, monthly_rent, status, notes FROM rentals ORDER BY id ASC')->fetchAll();
        foreach ($rentals as $rental) {
            $legacyId = (int) $rental['id'];
            $accountId = schema_find_legacy_account($pdo, 'rentals', $legacyId);
            if ($accountId !== null) {
                continue;
            }

            $stmt = $pdo->prepare('INSERT INTO project_accounts (category_id, account_name, code, contact_name, start_date, next_due_date, expected_amount, status, notes)
                VALUES (:category_id, :account_name, :code, :contact_name, :start_date, :next_due_date, :expected_amount, :status, :notes)');
            $stmt->execute([
                'category_id' => $categoryId,
                'account_name' => $rental['tenant_name'],
                'code' => $rental['stall_name'],
                'contact_name' => $rental['tenant_name'],
                'start_date' => $rental['start_date'],
                'next_due_date' => $rental['next_due_date'],
                'expected_amount' => $rental['monthly_rent'],
                'status' => $rental['status'] === 'active' ? 'active' : 'inactive',
                'notes' => $rental['notes'],
            ]);
            $accountId = (int) $pdo->lastInsertId();
            schema_project_account_meta_set($pdo, $accountId, 'legacy_source', 'rentals');
            schema_project_account_meta_set($pdo, $accountId, 'legacy_id', (string) $legacyId);
            schema_project_account_meta_set($pdo, $accountId, 'rental_category', (string) $rental['category']);
        }
    }

    if ($hasPayments) {
        $payments = $pdo->query('SELECT id, rental_id, payment_date, period_start, period_end, amount, or_number, notes, created_by FROM rental_payments ORDER BY id ASC')->fetchAll();
        foreach ($payments as $payment) {
            $legacyId = (int) $payment['id'];
            if (schema_legacy_entry_exists($pdo, 'rental_payments', $legacyId)) {
                continue;
            }

            $accountId = schema_find_legacy_account($pdo, 'rentals', (int) $payment['rental_id']);
            $period = trim((string) $payment['period_start'] . ' to ' . (string) $payment['period_end']);
            $notes = trim('Period: ' . $period . (($payment['notes'] ?? '') !== '' ? ' | ' . (string) $payment['notes'] : ''));

            schema_insert_project_entry($pdo, [
                'category_id' => $categoryId,
                'account_id' => $accountId,
                'entry_datetime' => (string) $payment['payment_date'] . ' 00:00:00',
                'entry_type' => 'payment',
                'amount' => $payment['amount'],
                'reference_no' => $payment['or_number'],
                'notes' => $notes,
                'created_by' => $payment['created_by'],
            ], [
                'legacy_source' => 'rental_payments',
                'legacy_id' => (string) $legacyId,
                'period_start' => (string) $payment['period_start'],
                'period_end' => (string) $payment['period_end'],
            ]);
        }
    }

    $pdo->exec('DROP TABLE IF EXISTS rental_payments');
    $pdo->exec('DROP TABLE IF EXISTS rentals');
}

function migrate_legacy_toga_records(PDO $pdo): void
{
    if (!schema_table_exists($pdo, 'toga_records')) {
        return;
    }

    $categoryId = schema_project_category_id(
        $pdo,
        'toga',
        'Toga',
        'Toga release, deposit, return, and forfeiture monitoring.'
    );

    $records = $pdo->query('SELECT id, student_name, student_id, program, release_date, return_date, deposit_amount, fee_amount, status, notes, created_by FROM toga_records ORDER BY id ASC')->fetchAll();
    foreach ($records as $record) {
        $legacyId = (int) $record['id'];
        $accountId = schema_find_legacy_account($pdo, 'toga_records', $legacyId);

        if ($accountId === null) {
            $totalAmount = (float) $record['deposit_amount'] + (float) $record['fee_amount'];
            $stmt = $pdo->prepare('INSERT INTO project_accounts (category_id, account_name, code, contact_name, start_date, expected_amount, status, notes)
                VALUES (:category_id, :account_name, :code, :contact_name, :start_date, :expected_amount, :status, :notes)');
            $stmt->execute([
                'category_id' => $categoryId,
                'account_name' => $record['student_name'],
                'code' => $record['student_id'],
                'contact_name' => $record['program'],
                'start_date' => $record['release_date'],
                'expected_amount' => $totalAmount,
                'status' => $record['status'] === 'released' ? 'active' : 'inactive',
                'notes' => $record['notes'],
            ]);
            $accountId = (int) $pdo->lastInsertId();
            schema_project_account_meta_set($pdo, $accountId, 'legacy_source', 'toga_records');
            schema_project_account_meta_set($pdo, $accountId, 'legacy_id', (string) $legacyId);
            schema_project_account_meta_set($pdo, $accountId, 'toga_status', (string) $record['status']);
            schema_project_account_meta_set($pdo, $accountId, 'return_date', $record['return_date'] !== null ? (string) $record['return_date'] : null);
            schema_project_account_meta_set($pdo, $accountId, 'deposit_amount', (string) $record['deposit_amount']);
            schema_project_account_meta_set($pdo, $accountId, 'fee_amount', (string) $record['fee_amount']);
            schema_project_account_meta_set($pdo, $accountId, 'program', $record['program'] !== null ? (string) $record['program'] : null);
        }

        if (!schema_legacy_entry_exists($pdo, 'toga_records', $legacyId, 'release')) {
            schema_insert_project_entry($pdo, [
                'category_id' => $categoryId,
                'account_id' => $accountId,
                'entry_datetime' => (string) $record['release_date'] . ' 00:00:00',
                'entry_type' => 'payment',
                'amount' => (float) $record['deposit_amount'] + (float) $record['fee_amount'],
                'notes' => 'Toga release fee/deposit',
                'created_by' => $record['created_by'],
            ], [
                'legacy_source' => 'toga_records',
                'legacy_id' => (string) $legacyId,
                'entry_event' => 'release',
            ]);
        }

        if ($record['status'] !== 'released' && !schema_legacy_entry_exists($pdo, 'toga_records', $legacyId, (string) $record['status'])) {
            schema_insert_project_entry($pdo, [
                'category_id' => $categoryId,
                'account_id' => $accountId,
                'entry_datetime' => (string) ($record['return_date'] ?: $record['release_date']) . ' 00:00:00',
                'entry_type' => 'monitoring',
                'amount' => 0,
                'notes' => 'Toga marked as ' . (string) $record['status'],
                'created_by' => $record['created_by'],
            ], [
                'legacy_source' => 'toga_records',
                'legacy_id' => (string) $legacyId,
                'entry_event' => (string) $record['status'],
            ]);
        }
    }

    $pdo->exec('DROP TABLE IF EXISTS toga_records');
}

function ensure_demo_visibility_data(PDO $pdo): void
{
    $projectRows = (int) $pdo->query('SELECT COUNT(*) FROM project_entries')->fetchColumn();
    $salesRows = (int) $pdo->query('SELECT COUNT(*) FROM sales')->fetchColumn();
    $productRows = (int) $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();

    if ($projectRows > 0 && $salesRows > 0 && $productRows > 0) {
        return;
    }

    try {
        $pdo->beginTransaction();

        if ($productRows === 0) {
            seed_demo_products($pdo);
        }

        if ($salesRows === 0) {
            seed_demo_sales($pdo);
        }

        if ($projectRows === 0) {
            seed_demo_project_monitoring($pdo);
        }

        $logbookRows = (int) $pdo->query('SELECT COUNT(*) FROM office_logbook')->fetchColumn();
        if ($logbookRows === 0) {
            seed_demo_logbook($pdo);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Demo visibility seed warning: ' . $e->getMessage());
    }
}

function seed_demo_products(PDO $pdo): void
{
    $products = [
        ['sku' => 'A4-BOND', 'name' => 'A4 Bond Paper Ream', 'category' => 'school_supply', 'product_group' => 'product', 'type' => 'item', 'cost' => 180, 'selling' => 235, 'stock' => 18, 'threshold' => 10],
        ['sku' => 'PEN-BLK', 'name' => 'Black Ballpen', 'category' => 'school_supply', 'product_group' => 'product', 'type' => 'item', 'cost' => 8, 'selling' => 12, 'stock' => 42, 'threshold' => 20],
        ['sku' => 'ID-LACE-001', 'name' => 'ID Lace', 'category' => 'id_supplies', 'product_group' => 'product', 'type' => 'item', 'cost' => 10, 'selling' => 20, 'stock' => 30, 'threshold' => 10],
        ['sku' => 'ID-CARD-001', 'name' => 'ID Card', 'category' => 'id_supplies', 'product_group' => 'product', 'type' => 'item', 'cost' => 20, 'selling' => 40, 'stock' => 25, 'threshold' => 10],
        ['sku' => 'ID-PRINT-001', 'name' => 'ID Printing', 'category' => 'id_services', 'product_group' => 'service', 'type' => 'service', 'cost' => 18, 'selling' => 35, 'stock' => 0, 'threshold' => 0],
        ['sku' => 'ID-REPLACE-001', 'name' => 'ID Replacement', 'category' => 'id_services', 'product_group' => 'service', 'type' => 'service', 'cost' => 25, 'selling' => 60, 'stock' => 0, 'threshold' => 0],
        ['sku' => 'PRINT-BW-PAPER', 'name' => 'Black and White Print', 'category' => 'printing', 'product_group' => 'service', 'type' => 'service', 'cost' => 1, 'selling' => 2, 'stock' => 0, 'threshold' => 0],
        ['sku' => 'PHOTO-BW', 'name' => 'Photocopy - Black and White', 'category' => 'photocopy', 'product_group' => 'service', 'type' => 'service', 'cost' => 0.75, 'selling' => 2, 'stock' => 0, 'threshold' => 0],
        ['sku' => 'PHOTO-COLOR', 'name' => 'Photocopy - Color', 'category' => 'photocopy', 'product_group' => 'service', 'type' => 'service', 'cost' => 3, 'selling' => 5, 'stock' => 0, 'threshold' => 0],
    ];

    $stmt = $pdo->prepare('INSERT INTO products (sku, name, category, product_group, type, cost_price, selling_price, stock_qty, low_stock_threshold)
        VALUES (:sku, :name, :category, :product_group, :type, :cost, :selling, :stock, :threshold)
        ON DUPLICATE KEY UPDATE name = VALUES(name), category = VALUES(category), product_group = VALUES(product_group), type = VALUES(type), cost_price = VALUES(cost_price), selling_price = VALUES(selling_price)');

    foreach ($products as $product) {
        $stmt->execute($product);
    }
}

function seed_demo_sales(PDO $pdo): void
{
    $productStmt = $pdo->query('SELECT id, sku, cost_price, selling_price FROM products WHERE sku IN ("A4-BOND", "PEN-BLK", "ID-PRINT-001", "ID-REPLACE-001", "PHOTO-BW")');
    $products = [];
    foreach ($productStmt->fetchAll() as $product) {
        $products[(string) $product['sku']] = $product;
    }

    if (!$products) {
        return;
    }

    $sales = [
        ['days' => -6, 'sku' => 'A4-BOND', 'qty' => 2],
        ['days' => -5, 'sku' => 'PHOTO-BW', 'qty' => 85],
        ['days' => -4, 'sku' => 'PEN-BLK', 'qty' => 12],
        ['days' => -3, 'sku' => 'ID-PRINT-001', 'qty' => 9],
        ['days' => -2, 'sku' => 'A4-BOND', 'qty' => 3],
        ['days' => -1, 'sku' => 'PHOTO-BW', 'qty' => 140],
        ['days' => 0, 'sku' => 'ID-REPLACE-001', 'qty' => 2],
    ];

    $stmt = $pdo->prepare('INSERT INTO sales (sale_date, product_id, quantity, unit_price, unit_cost, total_amount, total_cost, total_profit, or_number, notes, created_by)
        VALUES (:sale_date, :product_id, :quantity, :unit_price, :unit_cost, :total_amount, :total_cost, :total_profit, :or_number, :notes, NULL)');

    foreach ($sales as $index => $sale) {
        if (!isset($products[$sale['sku']])) {
            continue;
        }

        $product = $products[$sale['sku']];
        $quantity = (int) $sale['qty'];
        $unitPrice = (float) $product['selling_price'];
        $unitCost = (float) $product['cost_price'];
        $totalAmount = $unitPrice * $quantity;
        $totalCost = $unitCost * $quantity;

        $stmt->execute([
            'sale_date' => date('Y-m-d 09:30:00', strtotime((string) $sale['days'] . ' days')),
            'product_id' => (int) $product['id'],
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'unit_cost' => $unitCost,
            'total_amount' => $totalAmount,
            'total_cost' => $totalCost,
            'total_profit' => $totalAmount - $totalCost,
            'or_number' => 'DEMO-S' . str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT),
            'notes' => 'Demo sale for dashboard visibility',
        ]);
    }
}

function seed_demo_project_monitoring(PDO $pdo): void
{
    $categoryStmt = $pdo->query('SELECT id, slug FROM project_categories WHERE slug IN ("fishpond", "rental", "toga")');
    $categories = [];
    foreach ($categoryStmt->fetchAll() as $category) {
        $categories[(string) $category['slug']] = (int) $category['id'];
    }

    if (count($categories) < 3) {
        return;
    }

    $accountStmt = $pdo->prepare('INSERT INTO project_accounts (category_id, account_name, code, contact_name, start_date, next_due_date, expected_amount, status, notes)
        VALUES (:category_id, :account_name, :code, :contact_name, :start_date, :next_due_date, :expected_amount, :status, :notes)');

    $insertAccount = static function (array $data) use ($pdo, $accountStmt): int {
        $accountStmt->execute($data);
        return (int) $pdo->lastInsertId();
    };

    $pondA = $insertAccount([
        'category_id' => $categories['fishpond'],
        'account_name' => 'Pond A',
        'code' => 'FP-A',
        'contact_name' => 'Farm Unit',
        'start_date' => date('Y-m-d', strtotime('-120 days')),
        'next_due_date' => null,
        'expected_amount' => null,
        'status' => 'active',
        'notes' => 'Tilapia production pond',
    ]);
    $pondB = $insertAccount([
        'category_id' => $categories['fishpond'],
        'account_name' => 'Pond B',
        'code' => 'FP-B',
        'contact_name' => 'Farm Unit',
        'start_date' => date('Y-m-d', strtotime('-90 days')),
        'next_due_date' => null,
        'expected_amount' => null,
        'status' => 'active',
        'notes' => 'Fingerling monitoring pond',
    ]);
    $stall = $insertAccount([
        'category_id' => $categories['rental'],
        'account_name' => 'Canteen Stall 1',
        'code' => 'STALL-01',
        'contact_name' => 'Maria Santos',
        'start_date' => date('Y-m-d', strtotime('-180 days')),
        'next_due_date' => date('Y-m-d', strtotime('-3 days')),
        'expected_amount' => 3500,
        'status' => 'active',
        'notes' => 'Monthly rental account',
    ]);
    $kiosk = $insertAccount([
        'category_id' => $categories['rental'],
        'account_name' => 'Printing Kiosk',
        'code' => 'KIOSK-02',
        'contact_name' => 'Juan Dizon',
        'start_date' => date('Y-m-d', strtotime('-150 days')),
        'next_due_date' => date('Y-m-d', strtotime('+18 days')),
        'expected_amount' => 2500,
        'status' => 'active',
        'notes' => 'Monthly rental account',
    ]);
    $togaReleased = $insertAccount([
        'category_id' => $categories['toga'],
        'account_name' => 'Ana Dela Cruz',
        'code' => '2026-001',
        'contact_name' => 'BSIT',
        'start_date' => date('Y-m-d', strtotime('-2 days')),
        'next_due_date' => null,
        'expected_amount' => 650,
        'status' => 'active',
        'notes' => 'Demo toga release',
    ]);
    $togaReturned = $insertAccount([
        'category_id' => $categories['toga'],
        'account_name' => 'Mark Reyes',
        'code' => '2026-002',
        'contact_name' => 'BSED',
        'start_date' => date('Y-m-d', strtotime('-12 days')),
        'next_due_date' => null,
        'expected_amount' => 650,
        'status' => 'inactive',
        'notes' => 'Demo returned toga',
    ]);

    schema_project_account_meta_set($pdo, $togaReleased, 'toga_status', 'released');
    schema_project_account_meta_set($pdo, $togaReleased, 'deposit_amount', '500.00');
    schema_project_account_meta_set($pdo, $togaReleased, 'fee_amount', '150.00');
    schema_project_account_meta_set($pdo, $togaReleased, 'program', 'BSIT');
    schema_project_account_meta_set($pdo, $togaReturned, 'toga_status', 'returned');
    schema_project_account_meta_set($pdo, $togaReturned, 'return_date', date('Y-m-d', strtotime('-4 days')));
    schema_project_account_meta_set($pdo, $togaReturned, 'deposit_amount', '500.00');
    schema_project_account_meta_set($pdo, $togaReturned, 'fee_amount', '150.00');
    schema_project_account_meta_set($pdo, $togaReturned, 'program', 'BSED');

    $entries = [
        ['slug' => 'fishpond', 'account_id' => $pondA, 'days' => -6, 'type' => 'expense', 'quantity' => 12, 'unit' => 'sacks', 'amount' => 2200, 'ref' => 'DEMO-FP-001', 'notes' => 'Commercial feeds'],
        ['slug' => 'fishpond', 'account_id' => $pondB, 'days' => -5, 'type' => 'monitoring', 'quantity' => null, 'unit' => null, 'amount' => 0, 'ref' => null, 'notes' => 'Water level inspection'],
        ['slug' => 'fishpond', 'account_id' => $pondA, 'days' => -3, 'type' => 'harvest', 'quantity' => 85, 'unit' => 'kg', 'amount' => 12750, 'ref' => 'DEMO-FP-002', 'notes' => 'Tilapia harvest'],
        ['slug' => 'rental', 'account_id' => $stall, 'days' => -2, 'type' => 'payment', 'quantity' => null, 'unit' => null, 'amount' => 3500, 'ref' => 'DEMO-R-001', 'notes' => 'Monthly stall rent'],
        ['slug' => 'rental', 'account_id' => $kiosk, 'days' => -1, 'type' => 'payment', 'quantity' => null, 'unit' => null, 'amount' => 2500, 'ref' => 'DEMO-R-002', 'notes' => 'Monthly kiosk rent'],
        ['slug' => 'toga', 'account_id' => $togaReleased, 'days' => -2, 'type' => 'payment', 'quantity' => null, 'unit' => null, 'amount' => 650, 'ref' => 'DEMO-T-001', 'notes' => 'Toga release fee/deposit'],
        ['slug' => 'toga', 'account_id' => $togaReturned, 'days' => -12, 'type' => 'payment', 'quantity' => null, 'unit' => null, 'amount' => 650, 'ref' => 'DEMO-T-002', 'notes' => 'Toga release fee/deposit'],
        ['slug' => 'toga', 'account_id' => $togaReturned, 'days' => -4, 'type' => 'expense', 'quantity' => null, 'unit' => null, 'amount' => 500, 'ref' => null, 'notes' => 'Deposit refund after return'],
    ];

    foreach ($entries as $entry) {
        $entryId = schema_insert_project_entry($pdo, [
            'category_id' => $categories[$entry['slug']],
            'account_id' => $entry['account_id'],
            'entry_datetime' => date('Y-m-d 10:00:00', strtotime((string) $entry['days'] . ' days')),
            'entry_type' => $entry['type'],
            'quantity' => $entry['quantity'],
            'unit' => $entry['unit'],
            'amount' => $entry['amount'],
            'reference_no' => $entry['ref'],
            'notes' => $entry['notes'],
            'created_by' => null,
        ], [
            'demo_seed' => '1',
        ]);

        $direction = null;
        if (in_array($entry['type'], ['income', 'payment', 'harvest'], true) && (float) $entry['amount'] > 0) {
            $direction = 'in';
        } elseif ($entry['type'] === 'expense' && (float) $entry['amount'] > 0) {
            $direction = 'out';
        }

        if ($direction !== null) {
            $cashStmt = $pdo->prepare('INSERT INTO cash_transactions (txn_date, direction, source_module, project_entry_id, amount, or_number, description, created_by)
                VALUES (:txn_date, :direction, :source_module, :project_entry_id, :amount, :or_number, :description, NULL)');
            $cashStmt->execute([
                'txn_date' => date('Y-m-d 10:00:00', strtotime((string) $entry['days'] . ' days')),
                'direction' => $direction,
                'source_module' => $entry['slug'],
                'project_entry_id' => $entryId,
                'amount' => $entry['amount'],
                'or_number' => $entry['ref'],
                'description' => ucwords((string) $entry['slug']) . ' - ' . $entry['type'],
            ]);
        }
    }
}

function seed_demo_logbook(PDO $pdo): void
{
    $rows = [
        ['days' => 0, 'time_in' => '08:10:00', 'time_out' => '09:05:00', 'name' => 'Liza Mendoza', 'id' => '2026-010', 'purpose' => 'Printing request'],
        ['days' => -1, 'time_in' => '10:20:00', 'time_out' => '10:45:00', 'name' => 'Carlo Rivera', 'id' => '2026-011', 'purpose' => 'Photocopy documents'],
        ['days' => -2, 'time_in' => '14:00:00', 'time_out' => '14:40:00', 'name' => 'Nina Santos', 'id' => '2026-012', 'purpose' => 'Toga inquiry'],
    ];

    $stmt = $pdo->prepare('INSERT INTO office_logbook (log_date, time_in, time_out, student_name, student_id, purpose, created_by)
        VALUES (:log_date, :time_in, :time_out, :student_name, :student_id, :purpose, NULL)');

    foreach ($rows as $row) {
        $stmt->execute([
            'log_date' => date('Y-m-d', strtotime((string) $row['days'] . ' days')),
            'time_in' => $row['time_in'],
            'time_out' => $row['time_out'],
            'student_name' => $row['name'],
            'student_id' => $row['id'],
            'purpose' => $row['purpose'],
        ]);
    }
}

function ensure_default_project_categories(PDO $pdo): void
{
    $seedCategories = [
        [
            'slug' => 'fishpond',
            'name' => 'Fishpond',
            'description' => 'Fishpond monitoring, harvest tracking, and fishpond expenses/income.',
        ],
        [
            'slug' => 'rental',
            'name' => 'Rental and Stalls',
            'description' => 'School stall rentals and renewal payment monitoring.',
        ],
        [
            'slug' => 'toga',
            'name' => 'Toga',
            'description' => 'Toga release, deposit, return, and forfeiture monitoring.',
        ],
        [
            'slug' => 'business-center',
            'name' => 'Business Center',
            'description' => 'Business Center operations, services, income, expenses, and monitoring.',
        ],
        [
            'slug' => 'printing',
            'name' => 'Printing Services',
            'description' => 'Printing service activity tracked as a project category.',
        ],
        [
            'slug' => 'photocopy',
            'name' => 'Photocopy Services',
            'description' => 'Photocopy service activity tracked as a project category.',
        ],
        [
            'slug' => 'proposal-management',
            'name' => 'Proposal Management',
            'description' => 'Submitted proposals, approval follow-up, and implementation monitoring.',
        ],
    ];

    $stmt = $pdo->prepare('INSERT INTO project_categories (slug, name, description) VALUES (:slug, :name, :description)
        ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description), is_active = 1');

    foreach ($seedCategories as $category) {
        $stmt->execute($category);
    }
}

function ensure_default_business_center_content(PDO $pdo): void
{
    $rows = [
        [
            'section_key' => 'hero',
            'title' => 'Production and Business Operation Services',
            'body' => 'Sales, inventory, cash flow, rentals, fishpond operations, proposal requests, logbook entries, and official reports in one record management system.',
        ],
        [
            'section_key' => 'mission_vision',
            'title' => 'Mission and Vision',
            'body' => 'Vision: The Mindoro State University is a center of excellence in agriculture and fishery, science, technology, culture and education of globally competitive lifelong learners in a diverse yet cohesive society.' . "\n\n" . 'Mission: The University commits to produce 21st-century skilled lifelong learners and generates and commercializes innovative technologies by providing excellent and relevant services in instruction, research, extension, and production through industry-driven curricula, collaboration, internationalization, and continual organizational growth for sustainable development.',
        ],
        [
            'section_key' => 'services',
            'title' => 'Campus Services',
            'body' => 'Daily operation tools for the campus business center and income-generating projects.',
        ],
        [
            'section_key' => 'features',
            'title' => 'What the System Helps Manage',
            'body' => 'Sales records and POS transactions' . "\n" . 'Cash in, cash out, and net cash monitoring' . "\n" . 'Inventory catalog, low stock alerts, and stock ledger' . "\n" . 'Fishpond monitoring, harvest income, and expense records' . "\n" . 'Stall rentals, toga releases, payments, and overdue records' . "\n" . 'Proposal requests and administrative approval workflow' . "\n" . 'Office logbook entries for visits and service requests' . "\n" . 'Printable official reports for campus operations',
        ],
        [
            'section_key' => 'contact',
            'title' => 'Visit the Business Center',
            'body' => 'Mindoro State University Bongabong Campus Production and Business Operation Office.',
        ],
        [
            'section_key' => 'footer',
            'title' => 'Production and Business Operation Record Management System',
            'body' => 'Official campus operations records for sales, inventory, cash flow, projects, rentals, proposals, logbook, reports, and audit monitoring.',
        ],
    ];

    $stmt = $pdo->prepare('INSERT INTO business_center_content (section_key, title, body)
        VALUES (:section_key, :title, :body)
        ON DUPLICATE KEY UPDATE section_key = section_key');

    foreach ($rows as $row) {
        $stmt->execute($row);
    }
}

function ensure_default_system_settings(PDO $pdo): void
{
    execute_schema_statement($pdo, "CREATE TABLE IF NOT EXISTS system_settings (
        setting_key VARCHAR(100) NOT NULL PRIMARY KEY,
        setting_value TEXT DEFAULT NULL,
        updated_by INT DEFAULT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_system_settings_updated_by (updated_by),
        CONSTRAINT fk_system_settings_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $defaults = [
        'organization.university_name' => 'Mindoro State University',
        'organization.campus_name' => 'Bongabong Campus',
        'organization.office_name' => 'Production and Business Operation',
        'organization.system_name' => 'Production and Business Operation Record Management System',
        'organization.logo_path' => 'assets/images/logo.png',
        'organization.address' => '',
        'organization.contact_information' => '',
        'display.sidebar_default_state' => 'expanded',
        'display.default_table_rows' => '10',
        'display.dashboard_default_range' => 'daily',
        'display.theme_color' => 'green-gold',
        'reports.receipt_header' => 'Mindoro State University Bongabong Campus',
        'reports.or_number_format' => '0000-{0000}',
        'pos.or_next_sequence' => '1',
        'reports.prepared_by_default' => '',
        'reports.reviewed_by_default' => 'Department Head / Supervisor',
        'reports.approved_by_default' => 'System Administrator',
        'reports.footer_notes' => 'Generated by the Production and Business Operation Record Management System.',
        'reports.confidentiality_note' => 'This document contains sensitive institutional information. Handle with appropriate care.',
        'security.maximum_login_attempts' => '5',
        'security.account_lock_duration' => '15',
        'security.session_timeout' => '30',
        'security.password_minimum_length' => '8',
        'security.require_strong_password' => '1',
        'security.enable_session_logs' => '1',
    ];

    $stmt = $pdo->prepare('INSERT INTO system_settings (setting_key, setting_value)
        VALUES (:setting_key, :setting_value)
        ON DUPLICATE KEY UPDATE setting_key = setting_key');

    foreach ($defaults as $key => $value) {
        $stmt->execute([
            'setting_key' => $key,
            'setting_value' => $value,
        ]);
    }
}

function ensure_default_users(PDO $pdo): void
{
    if (!user_exists($pdo, 'staff') && user_exists($pdo, 'director')) {
        execute_schema_statement($pdo, "UPDATE users SET username = 'staff', full_name = 'BPO Staff', role = 'staff', status = 'approved' WHERE username = 'director'");
    }

    $users = [
        [
            'username' => 'admin',
            'password_hash' => password_hash('Admin@123', PASSWORD_DEFAULT),
            'full_name' => 'BPO Admin',
            'role' => 'admin',
        ],
        [
            'username' => 'staff',
            'password_hash' => password_hash('Staff@123', PASSWORD_DEFAULT),
            'full_name' => 'BPO Staff',
            'role' => 'staff',
        ],
    ];

    $stmt = $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role) VALUES (:username, :password_hash, :full_name, :role)');

    foreach ($users as $user) {
        if (user_exists($pdo, (string) $user['username'])) {
            continue;
        }
        $stmt->execute($user);
    }
}

function user_exists(PDO $pdo, string $username): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = :username');
    $stmt->execute(['username' => $username]);

    return (int) $stmt->fetchColumn() > 0;
}
