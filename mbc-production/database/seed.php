<?php
declare(strict_types=1);

/**
 * Database seed file - adds rich sample data for development and testing.
 *
 * Run via:
 *   - Command line: php database/seed.php
 */

$is_cli = php_sapi_name() === 'cli';

error_reporting(E_ALL);
ini_set('display_errors', $is_cli ? '1' : '0');
set_time_limit(300);
date_default_timezone_set('Asia/Manila');
mt_srand(20260511);

try {
    require_once __DIR__ . '/../config.php';
    require_once __DIR__ . '/db.php';
    require_once __DIR__ . '/schema.php';
    require_once __DIR__ . '/../utilities/helpers.php';

    $pdo = db();
    seed_normalize_people_status($pdo);
    initialize_schema($pdo);
    seed_normalize_people_status($pdo);
} catch (Throwable $e) {
    $error_msg = 'Initialization Error: ' . $e->getMessage();
    if ($is_cli) {
        echo $error_msg . PHP_EOL;
    } else {
        echo "<pre style='color: red;'>" . htmlspecialchars($error_msg) . '</pre>';
    }
    exit(1);
}

function log_message(string $msg, bool $is_cli): void
{
    if ($is_cli) {
        echo $msg . PHP_EOL;
        return;
    }

    echo htmlspecialchars($msg) . "<br>\n";
    flush();
    if (ob_get_level() > 0) {
        ob_flush();
    }
}

function seed_insert(PDO $pdo, string $table, array $data): int
{
    $columns = array_keys($data);
    $quotedColumns = array_map(static fn (string $column): string => '`' . str_replace('`', '', $column) . '`', $columns);
    $placeholders = implode(', ', array_fill(0, count($columns), '?'));
    $tableName = '`' . str_replace('`', '', $table) . '`';

    $stmt = $pdo->prepare('INSERT INTO ' . $tableName . ' (' . implode(', ', $quotedColumns) . ') VALUES (' . $placeholders . ')');
    $stmt->execute(array_values($data));

    return (int) $pdo->lastInsertId();
}

function seed_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table_name');
    $stmt->execute(['table_name' => $table]);

    return (int) $stmt->fetchColumn() > 0;
}

function seed_user_id(PDO $pdo, string $username, int $fallback): int
{
    $stmt = $pdo->prepare('SELECT id FROM users WHERE username = :username LIMIT 1');
    $stmt->execute(['username' => $username]);
    $id = (int) $stmt->fetchColumn();

    return $id > 0 ? $id : $fallback;
}

function seed_normalize_people_status(PDO $pdo): void
{
    try {
        $pdo->exec("ALTER TABLE people MODIFY status ENUM('pending', 'active', 'approved', 'inactive', 'rejected') NOT NULL DEFAULT 'pending'");
        $pdo->exec("UPDATE people SET status = 'approved' WHERE status = 'active'");
        $pdo->exec("UPDATE people SET status = 'inactive' WHERE status = 'rejected'");
        $pdo->exec("ALTER TABLE people MODIFY status ENUM('pending', 'approved', 'inactive') NOT NULL DEFAULT 'pending'");
    } catch (Throwable $e) {
        // The canonical database.sql status values are used below. If an older local
        // schema cannot be migrated here, the insert will surface the real error.
    }
}

function cleanup_seed_rows(PDO $pdo): void
{
    $pdo->exec("DELETE FROM cash_transactions WHERE or_number LIKE 'SEED-%' OR description LIKE 'Seed sample:%'");
    if (seed_table_exists($pdo, 'product_price_history')) {
        $pdo->exec("DELETE FROM product_price_history WHERE notes LIKE 'Seed sample:%' OR source_type = 'seed'");
    }
    $pdo->exec("DELETE FROM inventory_stock_movements WHERE reference_no LIKE 'SEED-%' OR notes LIKE 'Seed sample:%'");
    $pdo->exec("DELETE FROM inventory_stock_batches WHERE batch_code LIKE 'SEED-%' OR reference_no LIKE 'SEED-%' OR notes LIKE 'Seed sample:%'");
    $pdo->exec("DELETE FROM project_entries WHERE reference_no LIKE 'SEED-%' OR notes LIKE 'Seed sample:%'");
    $pdo->exec("DELETE FROM project_accounts WHERE code LIKE 'SEED-%' OR notes LIKE 'Seed sample:%'");
    $pdo->exec("DELETE FROM office_logbook WHERE student_id LIKE 'SEED-STU-%' OR purpose LIKE 'Seed sample:%'");
    $pdo->exec("DELETE FROM proposals WHERE admin_notes LIKE 'Seed sample:%'");
    $pdo->exec("DELETE FROM sales WHERE or_number LIKE 'SEED-OR-%'");
    $pdo->exec("DELETE FROM audit_logs WHERE details LIKE '%Seed sample:%'");
    $pdo->exec("DELETE FROM session_logs WHERE session_id LIKE 'seed-session-%'");
    $pdo->exec("DELETE FROM login_attempts WHERE ip_address LIKE '10.90.%'");
    $pdo->exec("DELETE FROM system_error_logs WHERE context LIKE '%Seed sample:%'");
    $pdo->exec("DELETE FROM archived_records WHERE source_id LIKE 'SEED-%'");

    if (seed_table_exists($pdo, 'approval_requests')) {
        $pdo->exec("DELETE FROM approval_requests WHERE entity_id LIKE 'SEED-%' OR action_type LIKE 'seed_%'");
    }
}

function seed_people(PDO $pdo, int $adminId): array
{
    $rows = [
        ['SEED-EMP-001', 'Dr. Helena Magbanua', 'Office of the Campus Director', 'Campus Director', 'helena.magbanua@minsu.edu.ph', 'approved'],
        ['SEED-EMP-002', 'Maria Lourdes Santos', 'Production and Business Operation', 'BPO Director', 'mlsantos@minsu.edu.ph', 'approved'],
        ['SEED-EMP-003', 'Antonio Cruz', 'Finance Department', 'Accountant', 'acruz@minsu.edu.ph', 'approved'],
        ['SEED-EMP-004', 'Rosa Garcia', 'Inventory Management', 'Inventory Manager', 'rgarcia@minsu.edu.ph', 'approved'],
        ['SEED-EMP-005', 'Pedro Lopez', 'Fishpond Operations', 'Farm Supervisor', 'plopez@minsu.edu.ph', 'approved'],
        ['SEED-EMP-006', 'Carmen Reyes', 'Business Center', 'Cashier', 'creyes@minsu.edu.ph', 'approved'],
        ['SEED-EMP-007', 'Juan Fernandez', 'IT Support', 'Systems Technician', 'jfernandez@minsu.edu.ph', 'approved'],
        ['SEED-EMP-008', 'Isabel Torres', 'Student Services', 'Coordinator', 'itorres@minsu.edu.ph', 'approved'],
        ['SEED-EMP-009', 'Ricardo Mendoza', 'Maintenance', 'Maintenance Specialist', 'rmendoza@minsu.edu.ph', 'approved'],
        ['SEED-EMP-010', 'Sofia Diaz', 'Administration', 'Administrative Assistant', 'sdiaz@minsu.edu.ph', 'approved'],
        ['SEED-EMP-011', 'Mark Anthony Villanueva', 'Procurement', 'Supply Officer', 'mvillanueva@minsu.edu.ph', 'approved'],
        ['SEED-EMP-012', 'Liza Manalo', 'Records', 'Records Clerk', 'lmanalo@minsu.edu.ph', 'pending'],
        ['SEED-EMP-013', 'Nestor Ramos', 'Auxiliary Services', 'Project Aide', 'nramos@minsu.edu.ph', 'inactive'],
        ['SEED-STU-001', 'Ana Dela Cruz', 'BSIT', 'Student', 'ana.delacruz@student.minsu.edu.ph', 'approved'],
        ['SEED-STU-002', 'Mark Reyes', 'BSED', 'Student', 'mark.reyes@student.minsu.edu.ph', 'approved'],
        ['SEED-STU-003', 'Liza Mendoza', 'BSBA', 'Student', 'liza.mendoza@student.minsu.edu.ph', 'approved'],
        ['SEED-STU-004', 'Carlo Rivera', 'BSA', 'Student', 'carlo.rivera@student.minsu.edu.ph', 'approved'],
        ['SEED-STU-005', 'Nina Santos', 'BEED', 'Student', 'nina.santos@student.minsu.edu.ph', 'approved'],
        ['SEED-STU-006', 'Jerome Aquino', 'BSCRIM', 'Student', 'jerome.aquino@student.minsu.edu.ph', 'approved'],
        ['SEED-STU-007', 'Patricia Gomez', 'BSHM', 'Student', 'patricia.gomez@student.minsu.edu.ph', 'approved'],
        ['SEED-STU-008', 'Rafael Lim', 'BSCS', 'Student', 'rafael.lim@student.minsu.edu.ph', 'approved'],
        ['SEED-STU-009', 'Camille Navarro', 'BSED', 'Student', 'camille.navarro@student.minsu.edu.ph', 'approved'],
        ['SEED-STU-010', 'Miguel Ortega', 'BSIT', 'Student', 'miguel.ortega@student.minsu.edu.ph', 'approved'],
        ['SEED-STU-011', 'Grace Bautista', 'BSBA', 'Student', 'grace.bautista@student.minsu.edu.ph', 'pending'],
        ['SEED-VEN-001', 'Dela Paz Canteen Services', 'External Partner', 'Stall Tenant', '0917-100-0101', 'approved'],
        ['SEED-VEN-002', 'Bongabong Printing Kiosk', 'External Partner', 'Kiosk Tenant', '0917-100-0102', 'approved'],
        ['SEED-VEN-003', 'Green Harvest Supplies', 'External Supplier', 'Feed Supplier', '0917-100-0103', 'approved'],
        ['SEED-VEN-004', 'Campus Laundry Cooperative', 'External Partner', 'Laundry Operator', '0917-100-0104', 'approved'],
    ];

    $find = $pdo->prepare('SELECT id FROM people WHERE person_code = :person_code ORDER BY id LIMIT 1');
    $insert = $pdo->prepare('INSERT INTO people (full_name, person_code, department, role_or_position, contact_info, status, created_by, approved_by, approved_at)
        VALUES (:full_name, :person_code, :department, :role_or_position, :contact_info, :status, :created_by, :approved_by, :approved_at)');
    $update = $pdo->prepare('UPDATE people
        SET full_name = :full_name,
            department = :department,
            role_or_position = :role_or_position,
            contact_info = :contact_info,
            status = :status,
            created_by = COALESCE(created_by, :created_by),
            approved_by = :approved_by,
            approved_at = :approved_at
        WHERE id = :id');

    $people = [];
    foreach ($rows as $row) {
        [$code, $name, $department, $role, $contact, $status] = $row;
        $approvedBy = $status === 'approved' ? $adminId : null;
        $approvedAt = $status === 'approved' ? date('Y-m-d H:i:s') : null;

        $find->execute(['person_code' => $code]);
        $id = (int) $find->fetchColumn();
        if ($id > 0) {
            $update->execute([
                'id' => $id,
                'full_name' => $name,
                'department' => $department,
                'role_or_position' => $role,
                'contact_info' => $contact,
                'status' => $status,
                'created_by' => $adminId,
                'approved_by' => $approvedBy,
                'approved_at' => $approvedAt,
            ]);
        } else {
            $insert->execute([
                'full_name' => $name,
                'person_code' => $code,
                'department' => $department,
                'role_or_position' => $role,
                'contact_info' => $contact,
                'status' => $status,
                'created_by' => $adminId,
                'approved_by' => $approvedBy,
                'approved_at' => $approvedAt,
            ]);
            $id = (int) $pdo->lastInsertId();
        }

        $people[$code] = [
            'id' => $id,
            'full_name' => $name,
            'department' => $department,
            'role_or_position' => $role,
        ];
    }

    return $people;
}

function seed_products(PDO $pdo): array
{
    $rows = [
        ['SEED-A4-REAM', 'A4 Bond Paper Ream', 'school_supply', 'product', 'item', 185.00, 240.00, 42, 12],
        ['SEED-SHORT-REAM', 'Short Bond Paper Ream', 'school_supply', 'product', 'item', 175.00, 225.00, 38, 10],
        ['SEED-YELLOW-PAD', 'Yellow Pad Paper', 'school_supply', 'product', 'item', 24.00, 38.00, 96, 25],
        ['SEED-INTER-PAD', 'Intermediate Pad Paper', 'school_supply', 'product', 'item', 18.00, 30.00, 72, 20],
        ['SEED-NOTE-A4', 'A4 Spiral Notebook', 'school_supply', 'product', 'item', 22.00, 42.00, 64, 20],
        ['SEED-PEN-BLK', 'Black Ballpen', 'school_supply', 'product', 'item', 7.50, 12.00, 180, 35],
        ['SEED-PEN-BLU', 'Blue Ballpen', 'school_supply', 'product', 'item', 7.50, 12.00, 160, 35],
        ['SEED-PENCIL-HB', 'HB Pencil', 'school_supply', 'product', 'item', 4.00, 8.00, 120, 30],
        ['SEED-FOLDER-LONG', 'Long Folder', 'school_supply', 'product', 'item', 5.00, 10.00, 88, 20],
        ['SEED-ENVELOPE-BRN', 'Brown Envelope', 'school_supply', 'product', 'item', 4.50, 9.00, 104, 20],
        ['SEED-CORR-TAPE', 'Correction Tape', 'school_supply', 'product', 'item', 22.00, 38.00, 14, 15],
        ['SEED-ID-LACE', 'ID Lace', 'id_supplies', 'product', 'item', 9.00, 20.00, 8, 20],
        ['SEED-ID-CARD', 'PVC ID Card', 'id_supplies', 'product', 'item', 18.00, 40.00, 16, 15],
        ['SEED-ID-JACKET', 'ID Jacket', 'id_supplies', 'product', 'item', 6.00, 15.00, 58, 15],
        ['SEED-ID-HOLDER', 'Hard ID Holder', 'id_supplies', 'product', 'item', 12.00, 28.00, 22, 15],
        ['SEED-ID-PRINT', 'ID Printing', 'id_services', 'service', 'service', 18.00, 40.00, 0, 0],
        ['SEED-ID-REPLACE', 'ID Replacement', 'id_services', 'service', 'service', 28.00, 65.00, 0, 0],
        ['SEED-ID-PHOTO', 'ID Photo Capture', 'id_services', 'service', 'service', 5.00, 25.00, 0, 0],
        ['SEED-PRINT-BW', 'Black and White Print', 'printing', 'service', 'service', 1.00, 2.00, 0, 0],
        ['SEED-PRINT-COLOR', 'Colored Print', 'printing', 'service', 'service', 5.00, 5.00, 0, 0],
        ['SEED-LAMINATE', 'Document Lamination', 'printing', 'service', 'service', 12.00, 30.00, 0, 0],
        ['SEED-PHOTO-BW', 'Photocopy - Black and White', 'photocopy', 'service', 'service', 0.75, 2.00, 0, 0],
        ['SEED-PHOTO-COLOR', 'Photocopy - Color', 'photocopy', 'service', 'service', 3.00, 5.00, 0, 0],
        ['SEED-TILAPIA-KG', 'Fresh Tilapia per kg', 'other', 'igp', 'item', 95.00, 150.00, 45, 10],
        ['SEED-CATFISH-KG', 'Fresh Catfish per kg', 'other', 'igp', 'item', 90.00, 145.00, 18, 10],
        ['SEED-VEG-SEEDLING', 'Vegetable Seedling Tray', 'other', 'igp', 'item', 45.00, 85.00, 9, 15],
        ['SEED-NOTEBOOKS', 'Notebooks', 'school_supply', 'product', 'item', 20.00, 35.00, 100, 20],
        ['SEED-WRITING-PADS', 'Writing pads', 'school_supply', 'product', 'item', 15.00, 25.00, 100, 20],
        ['SEED-YELLOW-LEGAL', 'Yellow legal pads', 'school_supply', 'product', 'item', 25.00, 40.00, 80, 20],
        ['SEED-LOOSE-BOND', 'Loose bond paper', 'school_supply', 'product', 'item', 0.50, 1.00, 500, 100],
        ['SEED-COLOR-PAPER', 'Multi-colored paper', 'school_supply', 'product', 'item', 1.00, 2.00, 400, 50],
        ['SEED-FOLDER-ENV', 'Folder envelopes', 'school_supply', 'product', 'item', 8.00, 15.00, 150, 30],
        ['SEED-PLASTIC-FOLDER', 'Plastic folders', 'school_supply', 'product', 'item', 10.00, 20.00, 100, 20],
        ['SEED-CLEAR-ENV', 'Clear plastic envelopes', 'school_supply', 'product', 'item', 12.00, 25.00, 100, 20],
        ['SEED-EXP-FOLDER', 'Expanding file folders', 'school_supply', 'product', 'item', 30.00, 55.00, 50, 10],
        ['SEED-DOC-SLEEVES', 'Colored document sleeves', 'school_supply', 'product', 'item', 5.00, 10.00, 200, 40],
        ['SEED-RING-BINDER', 'Ring binders', 'school_supply', 'product', 'item', 80.00, 150.00, 40, 10],
        ['SEED-RECORD-BOOK', 'Record books', 'school_supply', 'product', 'item', 45.00, 80.00, 60, 15],
        ['SEED-INDEX-CARDS', 'Index cards', 'school_supply', 'product', 'item', 10.00, 20.00, 100, 20],
        ['SEED-STICKY-NOTES', 'Sticky notes', 'school_supply', 'product', 'item', 15.00, 30.00, 150, 30],
        ['SEED-HIGHLIGHTER', 'Highlighters', 'school_supply', 'product', 'item', 18.00, 35.00, 120, 20],
        ['SEED-MARKERS', 'Markers', 'school_supply', 'product', 'item', 25.00, 50.00, 100, 20],
        ['SEED-GLUE-STICK', 'Glue sticks', 'school_supply', 'product', 'item', 15.00, 28.00, 100, 20],
        ['SEED-BINDER-CLIP', 'Binder clips', 'school_supply', 'product', 'item', 2.00, 5.00, 300, 50],
        ['SEED-PAPER-CLIP', 'Paper clips', 'school_supply', 'product', 'item', 0.50, 1.50, 500, 100],
        ['SEED-CHALK', 'Chalk', 'school_supply', 'product', 'item', 10.00, 20.00, 100, 20],
        ['SEED-PRINT-PACK', 'Printer paper packs', 'school_supply', 'product', 'item', 180.00, 250.00, 50, 10],
        ['SEED-STICKER-PPR', 'Glossy sticker paper', 'school_supply', 'product', 'item', 30.00, 60.00, 80, 20],
        ['SEED-LONG-BOND', 'Long bond paper', 'school_supply', 'product', 'item', 185.00, 240.00, 40, 10],
        ['SEED-PLASTIC-COVER', 'Colored plastic covers', 'school_supply', 'product', 'item', 15.00, 30.00, 100, 20],
        ['SEED-BASKET', 'Storage baskets', 'school_supply', 'product', 'item', 50.00, 95.00, 30, 5],
        ['SEED-PLASTIC-ORG', 'Plastic organizers', 'school_supply', 'product', 'item', 120.00, 200.00, 20, 5],
        ['SEED-STORAGE-BOX', 'Carton storage boxes', 'school_supply', 'product', 'item', 40.00, 75.00, 40, 10],
        ['SEED-FACE-MASK', 'Face masks', 'other', 'product', 'item', 25.00, 50.00, 100, 20],
        ['SEED-WET-WIPES', 'Wet wipes', 'other', 'product', 'item', 20.00, 40.00, 100, 20],
        ['SEED-ALCOHOL', 'Alcohol bottle', 'other', 'product', 'item', 35.00, 65.00, 80, 20],
        ['SEED-PACK-BAG', 'Plastic packaging bags', 'school_supply', 'product', 'item', 2.00, 5.00, 500, 100],
    ];

    $stressItems = [
        ['Graphing Notebook', 'school_supply', 32.00, 58.00],
        ['Composition Notebook', 'school_supply', 18.00, 35.00],
        ['Laboratory Notebook', 'school_supply', 42.00, 80.00],
        ['Quiz Notebook', 'school_supply', 12.00, 25.00],
        ['Drawing Pad', 'school_supply', 35.00, 65.00],
        ['Illustration Board', 'school_supply', 28.00, 55.00],
        ['Manila Paper', 'school_supply', 5.00, 10.00],
        ['Cartolina', 'school_supply', 7.00, 15.00],
        ['Construction Paper Pack', 'school_supply', 18.00, 35.00],
        ['Oslo Paper Pack', 'school_supply', 22.00, 42.00],
        ['Certificate Paper', 'school_supply', 4.00, 8.00],
        ['Whiteboard Marker Black', 'school_supply', 28.00, 55.00],
        ['Whiteboard Marker Blue', 'school_supply', 28.00, 55.00],
        ['Permanent Marker Black', 'school_supply', 20.00, 40.00],
        ['Permanent Marker Red', 'school_supply', 20.00, 40.00],
        ['Eraser', 'school_supply', 6.00, 12.00],
        ['Pencil Sharpener', 'school_supply', 8.00, 18.00],
        ['Ruler 12 inch', 'school_supply', 9.00, 20.00],
        ['Protractor', 'school_supply', 8.00, 18.00],
        ['Compass', 'school_supply', 25.00, 50.00],
        ['Scientific Calculator Battery', 'other', 35.00, 65.00],
        ['USB Flash Drive 16GB', 'other', 150.00, 260.00],
        ['USB Flash Drive 32GB', 'other', 220.00, 380.00],
        ['Printer Ink Black Refill', 'other', 165.00, 260.00],
        ['Printer Ink Cyan Refill', 'other', 165.00, 260.00],
        ['Printer Ink Magenta Refill', 'other', 165.00, 260.00],
        ['Printer Ink Yellow Refill', 'other', 165.00, 260.00],
        ['Thermal Receipt Roll', 'other', 28.00, 50.00],
        ['Inventory Label Roll', 'other', 95.00, 160.00],
        ['ID Clip', 'id_supplies', 3.00, 8.00],
        ['ID Strap Hook', 'id_supplies', 4.00, 10.00],
        ['ID Card Sleeve', 'id_supplies', 5.00, 12.00],
        ['Lanyard Buckle', 'id_supplies', 4.00, 10.00],
        ['PVC Card Pack', 'id_supplies', 180.00, 350.00],
        ['Toga Name Tag', 'id_supplies', 6.00, 15.00],
        ['Tilapia Fingerlings Pack', 'other', 320.00, 520.00],
        ['Fish Feed Starter Sack', 'other', 980.00, 1180.00],
        ['Fish Feed Grower Sack', 'other', 1120.00, 1350.00],
        ['Fish Feed Finisher Sack', 'other', 1160.00, 1400.00],
        ['Pond Lime Sack', 'other', 210.00, 320.00],
        ['Fish Net Small', 'other', 180.00, 300.00],
        ['Harvest Crate', 'other', 95.00, 150.00],
        ['Vegetable Seeds Pechay', 'other', 35.00, 70.00],
        ['Vegetable Seeds Mustasa', 'other', 35.00, 70.00],
        ['Vegetable Seeds Okra', 'other', 42.00, 85.00],
        ['Seedling Pot Pack', 'other', 45.00, 90.00],
        ['Organic Fertilizer Sack', 'other', 280.00, 420.00],
        ['Garden Soil Sack', 'other', 75.00, 125.00],
        ['Disinfectant Bottle', 'other', 55.00, 95.00],
        ['Hand Soap Refill', 'other', 48.00, 85.00],
        ['Trash Bag Roll', 'other', 65.00, 110.00],
        ['Packaging Tape', 'school_supply', 18.00, 35.00],
        ['Masking Tape', 'school_supply', 15.00, 30.00],
        ['Double Sided Tape', 'school_supply', 22.00, 45.00],
        ['Stapler', 'school_supply', 75.00, 135.00],
        ['Staple Wire', 'school_supply', 14.00, 28.00],
        ['Scissors', 'school_supply', 35.00, 70.00],
        ['Cutter Knife', 'school_supply', 25.00, 50.00],
        ['Clearbook 20 Pockets', 'school_supply', 55.00, 95.00],
        ['Clearbook 40 Pockets', 'school_supply', 85.00, 150.00],
    ];

    foreach ($stressItems as $index => $item) {
        [$name, $category, $cost, $selling] = $item;
        $rows[] = [
            'SEED-STRESS-' . str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT),
            $name,
            $category,
            in_array($name, ['Tilapia Fingerlings Pack', 'Fish Feed Starter Sack', 'Fish Feed Grower Sack', 'Fish Feed Finisher Sack', 'Pond Lime Sack', 'Fish Net Small', 'Harvest Crate', 'Vegetable Seeds Pechay', 'Vegetable Seeds Mustasa', 'Vegetable Seeds Okra', 'Seedling Pot Pack', 'Organic Fertilizer Sack', 'Garden Soil Sack'], true) ? 'igp' : 'product',
            'item',
            $cost,
            $selling,
            mt_rand(18, 220),
            mt_rand(8, 35),
        ];
    }

    $stmt = $pdo->prepare('INSERT INTO products (sku, name, category, product_group, type, cost_price, selling_price, stock_qty, low_stock_threshold, is_active)
        VALUES (:sku, :name, :category, :product_group, :type, :cost_price, :selling_price, :stock_qty, :low_stock_threshold, 1)
        ON DUPLICATE KEY UPDATE
            name = VALUES(name),
            category = VALUES(category),
            product_group = VALUES(product_group),
            type = VALUES(type),
            cost_price = VALUES(cost_price),
            selling_price = VALUES(selling_price),
            stock_qty = VALUES(stock_qty),
            low_stock_threshold = VALUES(low_stock_threshold),
            is_active = 1');

    foreach ($rows as $row) {
        $stmt->execute([
            'sku' => $row[0],
            'name' => $row[1],
            'category' => $row[2],
            'product_group' => $row[3],
            'type' => $row[4],
            'cost_price' => $row[5],
            'selling_price' => $row[6],
            'stock_qty' => $row[7],
            'low_stock_threshold' => $row[8],
        ]);
    }

    $skuList = array_column($rows, 0);
    $placeholders = implode(', ', array_fill(0, count($skuList), '?'));
    $productStmt = $pdo->prepare('SELECT id, sku, name, type, stock_qty, cost_price, selling_price FROM products WHERE sku IN (' . $placeholders . ')');
    $productStmt->execute($skuList);

    $products = [];
    foreach ($productStmt->fetchAll() as $product) {
        $products[(string) $product['sku']] = $product;
    }

    $historyStmt = $pdo->prepare('INSERT INTO product_price_history (product_id, changed_at, cost_price, selling_price, source_type, notes)
        VALUES (:product_id, :changed_at, :cost_price, :selling_price, "seed", :notes)');
    foreach ($products as $sku => $product) {
        $cost = (float) $product['cost_price'];
        $selling = (float) $product['selling_price'];
        $historyStmt->execute([
            'product_id' => (int) $product['id'],
            'changed_at' => date('Y-m-d H:i:s', strtotime('-60 days')),
            'cost_price' => round($cost * 0.92, 2),
            'selling_price' => round($selling * 0.94, 2),
            'notes' => 'Seed sample: previous product price',
        ]);
        $historyStmt->execute([
            'product_id' => (int) $product['id'],
            'changed_at' => date('Y-m-d H:i:s', strtotime('-20 days')),
            'cost_price' => $cost,
            'selling_price' => $selling,
            'notes' => 'Seed sample: current product price',
        ]);
    }

    return $products;
}

function seed_project_categories(PDO $pdo): array
{
    $rows = [
        ['business-center', 'Business Center', 'Business Center operations, services, income, expenses, and monitoring.'],
        ['fishpond', 'Fishpond', 'Fishpond monitoring, harvest tracking, and fishpond expenses/income.'],
        ['photocopy', 'Photocopy Services', 'Photocopy service activity tracked as a project category.'],
        ['printing', 'Printing Services', 'Printing service activity tracked as a project category.'],
        ['proposal-management', 'Proposal Management', 'Submitted proposals, approval follow-up, and implementation monitoring.'],
        ['rental', 'Rental and Stalls', 'School stall rentals and renewal payment monitoring.'],
        ['toga', 'Toga', 'Toga release, deposit, return, and forfeiture monitoring.'],
        ['catering', 'Catering Services', 'Campus event catering requests and income tracking.'],
        ['laundry', 'Laundry Services', 'Uniform and toga laundry service tracking.'],
    ];

    $stmt = $pdo->prepare('INSERT INTO project_categories (slug, name, description, is_active)
        VALUES (:slug, :name, :description, 1)
        ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description), is_active = 1');

    foreach ($rows as $row) {
        $stmt->execute([
            'slug' => $row[0],
            'name' => $row[1],
            'description' => $row[2],
        ]);
    }

    $categoryStmt = $pdo->query('SELECT id, slug FROM project_categories WHERE is_active = 1');
    $categories = [];
    foreach ($categoryStmt->fetchAll() as $category) {
        $categories[(string) $category['slug']] = (int) $category['id'];
    }

    return $categories;
}

function seed_inventory(PDO $pdo, array $products, int $adminId): int
{
    $count = 0;
    foreach ($products as $sku => $product) {
        if ((string) $product['type'] !== 'item') {
            continue;
        }

        $stock = (int) $product['stock_qty'];
        $openingQty = $stock + mt_rand(35, 90);
        $receivedDate = date('Y-m-d H:i:s', strtotime('-55 days'));
        $batchId = seed_insert($pdo, 'inventory_stock_batches', [
            'product_id' => (int) $product['id'],
            'batch_code' => 'SEED-OPEN-' . $sku,
            'received_date' => $receivedDate,
            'quantity_received' => $openingQty,
            'quantity_remaining' => max(0, $stock - mt_rand(0, min(12, $stock))),
            'unit_cost' => (float) $product['cost_price'],
            'source_type' => 'opening',
            'reference_no' => 'SEED-INV-OPEN-' . $sku,
            'notes' => 'Seed sample: opening inventory balance',
            'created_by' => $adminId,
        ]);
        $count++;

        seed_insert($pdo, 'inventory_stock_movements', [
            'product_id' => (int) $product['id'],
            'batch_id' => $batchId,
            'movement_date' => $receivedDate,
            'movement_type' => 'opening',
            'quantity_change' => $openingQty,
            'unit_cost' => (float) $product['cost_price'],
            'total_cost' => $openingQty * (float) $product['cost_price'],
            'reference_no' => 'SEED-INV-OPEN-' . $sku,
            'notes' => 'Seed sample: opening inventory movement',
            'created_by' => $adminId,
        ]);

        if ($stock < 25 || mt_rand(1, 3) === 1) {
            $adjustQty = mt_rand(5, 25);
            seed_insert($pdo, 'inventory_stock_movements', [
                'product_id' => (int) $product['id'],
                'batch_id' => $batchId,
                'movement_date' => date('Y-m-d H:i:s', strtotime('-10 days')),
                'movement_type' => 'adjustment',
                'quantity_change' => -$adjustQty,
                'unit_cost' => (float) $product['cost_price'],
                'total_cost' => $adjustQty * (float) $product['cost_price'],
                'reference_no' => 'SEED-INV-ADJ-' . $sku,
                'notes' => 'Seed sample: inventory count adjustment',
                'created_by' => $adminId,
            ]);
        }
    }

    return $count;
}

function seed_sales(PDO $pdo, array $products, int $adminId, int $staffId): array
{
    $saleSkus = array_values(array_intersect([
        'SEED-A4-REAM',
        'SEED-SHORT-REAM',
        'SEED-YELLOW-PAD',
        'SEED-NOTE-A4',
        'SEED-PEN-BLK',
        'SEED-PEN-BLU',
        'SEED-ID-LACE',
        'SEED-ID-CARD',
        'SEED-ID-PRINT',
        'SEED-ID-REPLACE',
        'SEED-PRINT-BW',
        'SEED-PRINT-COLOR',
        'SEED-PHOTO-BW',
        'SEED-PHOTO-COLOR',
        'SEED-LAMINATE',
        'SEED-TILAPIA-KG',
        'SEED-CATFISH-KG',
    ], array_keys($products)));

    $count = 0;
    $dailyTotals = [];

    for ($day = -45; $day <= 0; $day++) {
        $date = date('Y-m-d', strtotime($day . ' days'));
        $records = $day === 0 ? 7 : mt_rand(4, 9);
        $dailyTotals[$date] = 0.0;

        for ($i = 1; $i <= $records; $i++) {
            $sku = $saleSkus[array_rand($saleSkus)];
            $product = $products[$sku];
            $isService = (string) $product['type'] === 'service';
            $quantity = $isService ? mt_rand(5, 90) : mt_rand(1, 8);
            if (in_array($sku, ['SEED-PHOTO-BW', 'SEED-PHOTO-COLOR', 'SEED-PRINT-BW'], true)) {
                $quantity = mt_rand(20, 180);
            }

            $unitPrice = (float) $product['selling_price'];
            $unitCost = (float) $product['cost_price'];
            $totalAmount = $quantity * $unitPrice;
            $totalCost = $quantity * $unitCost;
            $orNumber = 'SEED-OR-' . date('Ymd', strtotime($date)) . '-' . str_pad((string) $i, 3, '0', STR_PAD_LEFT);

            $saleId = seed_insert($pdo, 'sales', [
                'sale_date' => $date . ' ' . sprintf('%02d:%02d:00', mt_rand(8, 16), mt_rand(0, 59)),
                'product_id' => (int) $product['id'],
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'unit_cost' => $unitCost,
                'total_amount' => $totalAmount,
                'total_cost' => $totalCost,
                'total_profit' => $totalAmount - $totalCost,
                'or_number' => $orNumber,
                'notes' => 'Seed sample: POS transaction',
                'created_by' => mt_rand(0, 1) === 1 ? $staffId : $adminId,
            ]);
            $count++;
            $dailyTotals[$date] += $totalAmount;

            if (!$isService) {
                seed_insert($pdo, 'inventory_stock_movements', [
                    'product_id' => (int) $product['id'],
                    'batch_id' => null,
                    'sale_id' => $saleId,
                    'movement_date' => $date . ' 17:00:00',
                    'movement_type' => 'sale',
                    'quantity_change' => -$quantity,
                    'unit_cost' => $unitCost,
                    'total_cost' => $totalCost,
                    'reference_no' => $orNumber,
                    'notes' => 'Seed sample: stock issued for POS sale',
                    'created_by' => $staffId,
                ]);
            }
        }
    }

    return ['count' => $count, 'daily_totals' => $dailyTotals];
}

function seed_cash_transactions(PDO $pdo, array $dailySales, int $adminId): int
{
    $count = 0;
    foreach ($dailySales as $date => $amount) {
        if ($amount <= 0) {
            continue;
        }

        seed_insert($pdo, 'cash_transactions', [
            'txn_date' => $date . ' 16:30:00',
            'direction' => 'in',
            'source_module' => 'sales',
            'project_entry_id' => null,
            'amount' => $amount,
            'or_number' => 'SEED-CASH-SALES-' . date('Ymd', strtotime((string) $date)),
            'description' => 'Seed sample: daily POS cash collection',
            'created_by' => $adminId,
        ]);
        $count++;

        if ((int) date('j', strtotime((string) $date)) % 4 === 0) {
            $expense = mt_rand(450, 2400);
            seed_insert($pdo, 'cash_transactions', [
                'txn_date' => $date . ' 13:20:00',
                'direction' => 'out',
                'source_module' => 'manual',
                'project_entry_id' => null,
                'amount' => $expense,
                'or_number' => 'SEED-CASH-EXP-' . date('Ymd', strtotime((string) $date)),
                'description' => 'Seed sample: office supplies and minor maintenance',
                'created_by' => $adminId,
            ]);
            $count++;
        }
    }

    return $count;
}

function seed_project_account_meta(PDO $pdo, int $accountId, string $key, ?string $value): void
{
    $stmt = $pdo->prepare('INSERT INTO project_account_meta (account_id, meta_key, meta_value)
        VALUES (:account_id, :meta_key, :meta_value)
        ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)');
    $stmt->execute([
        'account_id' => $accountId,
        'meta_key' => $key,
        'meta_value' => $value,
    ]);
}

function seed_project_accounts(PDO $pdo, array $categories, array $people): array
{
    $accounts = [];
    $rows = [
        ['fishpond', 'SEED-FP-A', 'Tilapia Pond A', 'SEED-EMP-005', 'Farm Unit', '-180 days', null, 60000.00, 'active', 'Seed sample: tilapia production pond'],
        ['fishpond', 'SEED-FP-B', 'Catfish Pond B', 'SEED-EMP-005', 'Farm Unit', '-150 days', null, 52000.00, 'active', 'Seed sample: catfish grow-out pond'],
        ['fishpond', 'SEED-FP-N', 'Fingerling Nursery', 'SEED-EMP-005', 'Farm Unit', '-120 days', null, 28000.00, 'active', 'Seed sample: fingerling nursery pond'],
        ['rental', 'SEED-STALL-A1', 'Canteen Stall A1', 'SEED-VEN-001', 'Dela Paz Canteen Services', '-240 days', '-7 days', 3500.00, 'active', 'Seed sample: overdue monthly stall rental'],
        ['rental', 'SEED-STALL-B2', 'Printing Kiosk B2', 'SEED-VEN-002', 'Bongabong Printing Kiosk', '-220 days', '+8 days', 2800.00, 'active', 'Seed sample: active monthly kiosk rental'],
        ['rental', 'SEED-STALL-C3', 'Laundry Corner C3', 'SEED-VEN-004', 'Campus Laundry Cooperative', '-200 days', '+14 days', 2200.00, 'active', 'Seed sample: active laundry rental'],
        ['rental', 'SEED-STALL-D4', 'Snack Booth D4', 'SEED-VEN-001', 'Dela Paz Canteen Services', '-210 days', '-2 days', 2500.00, 'active', 'Seed sample: overdue snack booth rental'],
        ['business-center', 'SEED-BC-POS', 'Business Center POS', 'SEED-EMP-006', 'Business Center', '-365 days', null, null, 'active', 'Seed sample: business center income tracker'],
        ['printing', 'SEED-PRINT-UNIT', 'Printing Service Unit', 'SEED-EMP-007', 'Business Center', '-365 days', null, null, 'active', 'Seed sample: printing service tracker'],
        ['photocopy', 'SEED-PHOTO-UNIT', 'Photocopy Service Unit', 'SEED-EMP-006', 'Business Center', '-365 days', null, null, 'active', 'Seed sample: photocopy service tracker'],
        ['catering', 'SEED-CATER-UNIT', 'Campus Catering Unit', 'SEED-EMP-010', 'Administration', '-90 days', null, null, 'active', 'Seed sample: event catering tracker'],
        ['laundry', 'SEED-LAUNDRY-UNIT', 'Toga Laundry Unit', 'SEED-VEN-004', 'External Partner', '-90 days', null, null, 'active', 'Seed sample: toga laundry tracker'],
    ];

    foreach ($rows as $row) {
        [$slug, $code, $name, $personCode, $contactName, $startOffset, $dueOffset, $expected, $status, $notes] = $row;
        if (!isset($categories[$slug])) {
            continue;
        }

        $id = seed_insert($pdo, 'project_accounts', [
            'category_id' => $categories[$slug],
            'person_id' => $people[$personCode]['id'] ?? null,
            'account_name' => $name,
            'code' => $code,
            'contact_name' => $contactName,
            'start_date' => date('Y-m-d', strtotime($startOffset)),
            'next_due_date' => $dueOffset !== null ? date('Y-m-d', strtotime($dueOffset)) : null,
            'expected_amount' => $expected,
            'status' => $status,
            'notes' => $notes,
        ]);
        $accounts[$code] = $id;
    }

    $togaRows = [
        ['SEED-STU-001', '-4 days', null, 'released', 500.00, 150.00],
        ['SEED-STU-002', '-18 days', '-3 days', 'returned', 500.00, 150.00],
        ['SEED-STU-003', '-12 days', null, 'released', 500.00, 150.00],
        ['SEED-STU-004', '-24 days', '-10 days', 'returned', 500.00, 150.00],
        ['SEED-STU-005', '-7 days', null, 'released', 500.00, 150.00],
        ['SEED-STU-006', '-30 days', '-14 days', 'forfeited', 500.00, 150.00],
        ['SEED-STU-007', '-2 days', null, 'released', 500.00, 150.00],
        ['SEED-STU-008', '-15 days', '-1 days', 'returned', 500.00, 150.00],
        ['SEED-STU-009', '-9 days', null, 'released', 500.00, 150.00],
        ['SEED-STU-010', '-6 days', null, 'released', 500.00, 150.00],
    ];

    foreach ($togaRows as $row) {
        [$studentCode, $releaseOffset, $returnOffset, $togaStatus, $deposit, $fee] = $row;
        if (!isset($categories['toga'], $people[$studentCode])) {
            continue;
        }

        $person = $people[$studentCode];
        $accountStatus = $togaStatus === 'released' ? 'active' : 'inactive';
        $accountCode = 'SEED-TG-' . substr($studentCode, -3);
        $id = seed_insert($pdo, 'project_accounts', [
            'category_id' => $categories['toga'],
            'person_id' => $person['id'],
            'account_name' => $person['full_name'],
            'code' => $accountCode,
            'contact_name' => $person['department'],
            'start_date' => date('Y-m-d', strtotime($releaseOffset)),
            'next_due_date' => null,
            'expected_amount' => $deposit + $fee,
            'status' => $accountStatus,
            'notes' => 'Seed sample: toga rental release',
        ]);
        $accounts[$accountCode] = $id;

        seed_project_account_meta($pdo, $id, 'toga_status', $togaStatus);
        seed_project_account_meta($pdo, $id, 'deposit_amount', number_format($deposit, 2, '.', ''));
        seed_project_account_meta($pdo, $id, 'fee_amount', number_format($fee, 2, '.', ''));
        seed_project_account_meta($pdo, $id, 'program', $person['department']);
        seed_project_account_meta($pdo, $id, 'return_date', $returnOffset !== null ? date('Y-m-d', strtotime($returnOffset)) : null);
    }

    return $accounts;
}

function seed_project_entry_with_cash(PDO $pdo, array $categories, string $slug, ?int $accountId, string $datetime, string $type, ?float $quantity, ?string $unit, float $amount, string $referenceNo, string $notes, int $userId): int
{
    $entryId = seed_insert($pdo, 'project_entries', [
        'category_id' => $categories[$slug],
        'account_id' => $accountId,
        'entry_datetime' => $datetime,
        'entry_type' => $type,
        'quantity' => $quantity,
        'unit' => $unit,
        'amount' => $amount,
        'reference_no' => $referenceNo,
        'notes' => $notes,
        'created_by' => $userId,
    ]);

    $direction = null;
    if (in_array($type, ['income', 'payment', 'harvest'], true) && $amount > 0) {
        $direction = 'in';
    } elseif ($type === 'expense' && $amount > 0) {
        $direction = 'out';
    }

    if ($direction !== null) {
        seed_insert($pdo, 'cash_transactions', [
            'txn_date' => $datetime,
            'direction' => $direction,
            'source_module' => $slug,
            'project_entry_id' => $entryId,
            'amount' => $amount,
            'or_number' => $referenceNo,
            'description' => 'Seed sample: ' . ucwords(str_replace('-', ' ', $slug)) . ' ' . $type,
            'created_by' => $userId,
        ]);
    }

    return $entryId;
}

function seed_project_entries(PDO $pdo, array $categories, array $accounts, int $adminId, int $staffId): int
{
    $count = 0;

    for ($day = -45; $day <= 0; $day += 2) {
        $date = date('Y-m-d', strtotime($day . ' days'));
        $accountCode = $day % 4 === 0 ? 'SEED-FP-A' : 'SEED-FP-B';
        seed_project_entry_with_cash($pdo, $categories, 'fishpond', $accounts[$accountCode] ?? null, $date . ' 07:45:00', 'monitoring', mt_rand(78, 96) / 10, 'pH', 0.00, 'SEED-FP-MON-' . date('Ymd', strtotime($date)), 'Seed sample: pond water quality and feeding observation', $staffId);
        $count++;

        if ((int) date('j', strtotime($date)) % 7 === 0) {
            seed_project_entry_with_cash($pdo, $categories, 'fishpond', $accounts[$accountCode] ?? null, $date . ' 09:30:00', 'expense', mt_rand(4, 12), 'sacks', (float) mt_rand(1800, 5200), 'SEED-FP-EXP-' . date('Ymd', strtotime($date)), 'Seed sample: fish feeds and pond maintenance supplies', $adminId);
            $count++;
        }
    }

    foreach ([-35, -21, -7, 0] as $offset) {
        $date = date('Y-m-d', strtotime($offset . ' days'));
        $quantity = (float) mt_rand(65, 145);
        seed_project_entry_with_cash($pdo, $categories, 'fishpond', $accounts['SEED-FP-A'] ?? null, $date . ' 10:15:00', 'harvest', $quantity, 'kg', $quantity * mt_rand(135, 155), 'SEED-FP-HARV-' . date('Ymd', strtotime($date)), 'Seed sample: tilapia harvest income', $adminId);
        $count++;
    }

    $rentalAccountCodes = ['SEED-STALL-A1', 'SEED-STALL-B2', 'SEED-STALL-C3', 'SEED-STALL-D4'];
    foreach ($rentalAccountCodes as $index => $accountCode) {
        for ($monthOffset = -2; $monthOffset <= 0; $monthOffset++) {
            if ($accountCode === 'SEED-STALL-A1' && $monthOffset === 0) {
                continue;
            }
            $date = date('Y-m-' . str_pad((string) (5 + $index), 2, '0', STR_PAD_LEFT), strtotime($monthOffset . ' month'));
            $amount = $accountCode === 'SEED-STALL-C3' ? 2200.00 : ($accountCode === 'SEED-STALL-B2' ? 2800.00 : ($accountCode === 'SEED-STALL-D4' ? 2500.00 : 3500.00));
            seed_project_entry_with_cash($pdo, $categories, 'rental', $accounts[$accountCode] ?? null, $date . ' 14:00:00', 'payment', null, null, $amount, 'SEED-RENT-PAY-' . substr($accountCode, 5) . '-' . date('Ym', strtotime($date)), 'Seed sample: monthly rental collection', $adminId);
            $count++;
        }
    }

    foreach ($accounts as $code => $accountId) {
        if (!str_starts_with($code, 'SEED-TG-')) {
            continue;
        }
        $releaseDate = date('Y-m-d', strtotime('-' . mt_rand(2, 30) . ' days'));
        seed_project_entry_with_cash($pdo, $categories, 'toga', $accountId, $releaseDate . ' 11:00:00', 'payment', null, null, 650.00, 'SEED-TOGA-PAY-' . $code, 'Seed sample: toga fee and deposit collection', $staffId);
        $count++;
    }

    for ($day = -40; $day <= 0; $day += 5) {
        $date = date('Y-m-d', strtotime($day . ' days'));
        seed_project_entry_with_cash($pdo, $categories, 'business-center', $accounts['SEED-BC-POS'] ?? null, $date . ' 16:45:00', 'income', null, null, (float) mt_rand(1800, 8200), 'SEED-BC-INC-' . date('Ymd', strtotime($date)), 'Seed sample: business center service income', $adminId);
        $count++;
        seed_project_entry_with_cash($pdo, $categories, 'printing', $accounts['SEED-PRINT-UNIT'] ?? null, $date . ' 15:30:00', 'expense', null, null, (float) mt_rand(350, 1800), 'SEED-PRT-EXP-' . date('Ymd', strtotime($date)), 'Seed sample: toner and paper expense', $adminId);
        $count++;
    }

    return $count;
}

function seed_logbook(PDO $pdo, array $people, int $staffId): int
{
    $studentCodes = array_values(array_filter(array_keys($people), static fn (string $code): bool => str_starts_with($code, 'SEED-STU-')));
    $purposes = [
        'Printing request',
        'Photocopy documents',
        'ID replacement inquiry',
        'Toga release',
        'Toga return',
        'Payment processing',
        'Project proposal submission',
        'Document certification',
        'Business center purchase',
    ];

    $count = 0;
    for ($day = -45; $day <= 0; $day++) {
        $date = date('Y-m-d', strtotime($day . ' days'));
        $records = $day === 0 ? 9 : mt_rand(4, 10);
        for ($i = 0; $i < $records; $i++) {
            $code = $studentCodes[array_rand($studentCodes)];
            $person = $people[$code];
            $hourIn = mt_rand(8, 16);
            $minuteIn = mt_rand(0, 55);
            $timeOutHour = min(18, $hourIn + mt_rand(0, 2));
            $timeOutMinute = mt_rand(0, 59);
            seed_insert($pdo, 'office_logbook', [
                'person_id' => $person['id'],
                'log_date' => $date,
                'time_in' => sprintf('%02d:%02d:00', $hourIn, $minuteIn),
                'time_out' => sprintf('%02d:%02d:00', $timeOutHour, $timeOutMinute),
                'student_name' => $person['full_name'],
                'student_id' => $code,
                'purpose' => $purposes[array_rand($purposes)],
                'created_by' => $staffId,
            ]);
            $count++;
        }
    }

    return $count;
}

function seed_proposals(PDO $pdo, array $people, int $adminId, int $staffId): int
{
    $rows = [
        ['SEED-EMP-005', 'Fishpond aerator procurement', 'Fishpond Operations', 85000, '+45 days', 'submitted', 'Request for two paddle wheel aerators for Pond A and Pond B.'],
        ['SEED-EMP-006', 'Business Center queue number display', 'Business Center', 42000, '+60 days', 'under_review', 'Install a small queue display to reduce counter congestion.'],
        ['SEED-EMP-004', 'Inventory labeling rollout', 'Inventory Management', 38000, '+35 days', 'approved', 'Item labels and shelf tags for faster stock counts.'],
        ['SEED-EMP-007', 'POS receipt printer replacement', 'IT Support', 26000, '+30 days', 'approved', 'Replace aging thermal printers used by the POS counter.'],
        ['SEED-EMP-008', 'Toga claim scheduling desk', 'Student Services', 18000, '+20 days', 'implemented', 'Dedicated toga claim desk during graduation season.'],
        ['SEED-EMP-010', 'Campus entrepreneurship mini fair', 'Administration', 125000, '+90 days', 'under_review', 'Mini fair for student and campus income-generating projects.'],
        ['SEED-STU-003', 'Student printing discount hour', 'BSBA', 12000, '+25 days', 'submitted', 'Pilot discounted printing hour for student organizations.'],
        ['SEED-STU-005', 'Document request pickup shelves', 'BEED', 15000, '+40 days', 'rejected', 'Install pickup shelves near the Business Center counter.'],
        ['SEED-EMP-011', 'Additional ID card supply buffer', 'Procurement', 55000, '+15 days', 'approved', 'Maintain a buffer stock of PVC cards and ID laces.'],
        ['SEED-VEN-004', 'Toga laundry intake tracking', 'External Partner', 30000, '+50 days', 'submitted', 'Improve laundry intake and return tracking for toga rentals.'],
        ['SEED-EMP-002', 'Monthly project income review template', 'Production and Business Operation', 10000, '+10 days', 'implemented', 'Standardize project income review attachments.'],
        ['SEED-EMP-003', 'Cash collection drop box upgrade', 'Finance Department', 72000, '+75 days', 'under_review', 'Upgrade secured cash drop box for end-of-day collections.'],
    ];

    $count = 0;
    foreach ($rows as $index => $row) {
        [$personCode, $title, $department, $budget, $targetOffset, $status, $summary] = $row;
        $person = $people[$personCode] ?? reset($people);
        $submittedAt = date('Y-m-d H:i:s', strtotime('-' . (4 + $index * 3) . ' days'));
        $reviewed = in_array($status, ['approved', 'rejected', 'implemented'], true);
        seed_insert($pdo, 'proposals', [
            'proposer_id' => $person['id'],
            'title' => $title,
            'proposer_name' => $person['full_name'],
            'department' => $department,
            'submitted_at' => $submittedAt,
            'status' => $status,
            'estimated_budget' => $budget,
            'target_date' => date('Y-m-d', strtotime($targetOffset)),
            'summary' => $summary,
            'admin_notes' => 'Seed sample: ' . ($reviewed ? 'Reviewed proposal record.' : 'Pending proposal record.'),
            'created_by' => $staffId,
            'reviewed_by' => $reviewed ? $adminId : null,
            'reviewed_at' => $reviewed ? date('Y-m-d H:i:s', strtotime($submittedAt . ' +2 days')) : null,
        ]);
        $count++;
    }

    return $count;
}

function seed_business_center_content(PDO $pdo, int $adminId): int
{
    $rows = [
        ['hero', 'Production and Business Operation Services', 'Sales, inventory, cash flow, rentals, fishpond operations, proposal requests, logbook entries, and official reports in one record management system.'],
        ['mission_vision', 'Mission and Vision', 'Vision: The Mindoro State University is a center of excellence in agriculture and fishery, science, technology, culture and education of globally competitive lifelong learners in a diverse yet cohesive society.' . "\n\n" . 'Mission: The University commits to produce 21st-century skilled lifelong learners and generates and commercializes innovative technologies by providing excellent and relevant services in instruction, research, extension, and production through industry-driven curricula, collaboration, internationalization, and continual organizational growth for sustainable development.'],
        ['services', 'Campus Services', 'Daily operation tools for the campus business center and income-generating projects.'],
        ['features', 'What the System Helps Manage', 'Sales records and POS transactions' . "\n" . 'Cash in, cash out, and net cash monitoring' . "\n" . 'Inventory catalog, low stock alerts, and stock ledger' . "\n" . 'Fishpond monitoring, harvest income, and expense records' . "\n" . 'Stall rentals, toga releases, payments, and overdue records' . "\n" . 'Proposal requests and administrative approval workflow' . "\n" . 'Office logbook entries for visits and service requests' . "\n" . 'Printable official reports for campus operations'],
        ['contact', 'Visit the Business Center', 'Mindoro State University Bongabong Campus Production and Business Operation Office.'],
        ['footer', 'Production and Business Operation Record Management System', 'Official campus operations records for sales, inventory, cash flow, projects, rentals, proposals, logbook, reports, and audit monitoring.'],
    ];

    $stmt = $pdo->prepare('INSERT INTO business_center_content (section_key, title, body, is_active, updated_by)
        VALUES (:section_key, :title, :body, 1, :updated_by)
        ON DUPLICATE KEY UPDATE title = VALUES(title), body = VALUES(body), is_active = 1, updated_by = VALUES(updated_by)');

    foreach ($rows as $row) {
        $stmt->execute([
            'section_key' => $row[0],
            'title' => $row[1],
            'body' => $row[2],
            'updated_by' => $adminId,
        ]);
    }

    return count($rows);
}

function seed_security_and_admin_records(PDO $pdo, int $adminId, int $staffId): array
{
    $auditActions = [
        ['login', 'auth', 'user', $adminId, 'Admin logged in.'],
        ['create_record', 'sales', 'sale', 'SEED-OR', 'Created POS sale.'],
        ['generate_report', 'reports', 'report', 'SEED-RPT-001', 'Generated monthly sales report.'],
        ['print_report', 'reports', 'report', 'SEED-RPT-001', 'Printed official report template.'],
        ['update_record', 'inventory', 'product', 'SEED-A4-REAM', 'Updated reorder threshold.'],
        ['approve_request', 'approvals', 'approval', 'SEED-APR-002', 'Approved stock update request.'],
        ['create_backup', 'backup', 'backup', 'SEED-BACKUP-001', 'Created database backup.'],
        ['download_backup', 'backup', 'backup', 'SEED-BACKUP-001', 'Downloaded database backup.'],
    ];
    $auditCount = 0;
    foreach ($auditActions as $index => $row) {
        seed_insert($pdo, 'audit_logs', [
            'user_id' => $index % 2 === 0 ? $adminId : $staffId,
            'action' => $row[0],
            'module' => $row[1],
            'entity_type' => $row[2],
            'entity_id' => (string) $row[3],
            'details' => json_encode(['summary' => 'Seed sample: ' . $row[4]], JSON_UNESCAPED_SLASHES),
            'ip_address' => '10.90.0.' . (10 + $index),
            'created_at' => date('Y-m-d H:i:s', strtotime('-' . (8 - $index) . ' days')),
        ]);
        $auditCount++;
    }

    $sessionCount = 0;
    foreach ([$adminId, $staffId] as $userIndex => $userId) {
        for ($i = 1; $i <= 4; $i++) {
            seed_insert($pdo, 'session_logs', [
                'user_id' => $userId,
                'session_id' => 'seed-session-' . $userIndex . '-' . $i,
                'event' => $i === 4 ? 'logout' : 'login',
                'ip_address' => '10.90.1.' . (20 + $i + $userIndex),
                'user_agent' => 'Seed Browser / Windows',
                'created_at' => date('Y-m-d H:i:s', strtotime('-' . $i . ' days')),
            ]);
            $sessionCount++;
        }
    }

    $loginCount = 0;
    foreach (['admin', 'staff', 'unknown.staff', 'admin'] as $index => $username) {
        seed_insert($pdo, 'login_attempts', [
            'username' => $username,
            'ip_address' => '10.90.2.' . (30 + $index),
            'was_successful' => in_array($username, ['admin', 'staff'], true) ? 1 : 0,
            'attempted_at' => date('Y-m-d H:i:s', strtotime('-' . ($index + 1) . ' hours')),
        ]);
        $loginCount++;
    }

    $errorCount = 0;
    foreach ([
        ['notice', 'Report preview loaded with no rows for selected filter.'],
        ['warning', 'Low stock threshold reached for ID Lace.'],
        ['error', 'Receipt printer unavailable during test print.'],
    ] as $index => $row) {
        seed_insert($pdo, 'system_error_logs', [
            'user_id' => $index === 2 ? $staffId : $adminId,
            'severity' => $row[0],
            'message' => $row[1],
            'context' => json_encode(['summary' => 'Seed sample: security log data'], JSON_UNESCAPED_SLASHES),
            'ip_address' => '10.90.3.' . (40 + $index),
            'created_at' => date('Y-m-d H:i:s', strtotime('-' . ($index + 1) . ' days')),
        ]);
        $errorCount++;
    }

    $archiveCount = 0;
    foreach (['sales', 'cash_transactions', 'project_entries'] as $index => $sourceTable) {
        seed_insert($pdo, 'archived_records', [
            'source_table' => $sourceTable,
            'source_id' => 'SEED-ARCH-' . str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT),
            'record_data' => json_encode(['summary' => 'Seed sample: archived record snapshot'], JSON_UNESCAPED_SLASHES),
            'archived_by' => $adminId,
            'archived_at' => date('Y-m-d H:i:s', strtotime('-' . ($index + 2) . ' days')),
        ]);
        $archiveCount++;
    }

    return [
        'audit' => $auditCount,
        'sessions' => $sessionCount,
        'login_attempts' => $loginCount,
        'errors' => $errorCount,
        'archives' => $archiveCount,
    ];
}

function seed_approval_requests(PDO $pdo, int $adminId, int $staffId): int
{
    if (!seed_table_exists($pdo, 'approval_requests')) {
        return 0;
    }

    $rows = [
        ['inventory', 'seed_adjust_stock', 'product', 'SEED-ID-LACE', null, ['stock_qty' => 30], 'pending', null],
        ['cashflow', 'seed_add_manual_cash_transaction', 'cash_transaction', 'SEED-CASH-MAN-001', null, ['amount' => 2400, 'direction' => 'out'], 'approved', 'Approved for supplies reimbursement.'],
        ['sales', 'seed_void_pos_sale', 'sale', 'SEED-OR-VOID-001', ['status' => 'posted'], ['status' => 'void_requested'], 'rejected', 'OR number already remitted.'],
        ['rental', 'seed_add_rental_payment', 'project_entry', 'SEED-RENT-PAY-001', null, ['amount' => 3500], 'needs_revision', 'Attach signed receipt copy.'],
        ['fishpond', 'seed_add_fishpond_expense', 'project_entry', 'SEED-FP-EXP-001', null, ['amount' => 5200], 'pending', null],
        ['users', 'seed_suspend_user', 'user', 'SEED-USER-001', ['status' => 'approved'], ['status' => 'suspended'], 'pending', null],
    ];

    $count = 0;
    foreach ($rows as $index => $row) {
        [$module, $actionType, $entityType, $entityId, $oldValue, $newValue, $status, $remarks] = $row;
        $decided = in_array($status, ['approved', 'rejected', 'needs_revision'], true);
        seed_insert($pdo, 'approval_requests', [
            'requester_id' => $staffId,
            'module' => $module,
            'action_type' => $actionType,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'old_value' => $oldValue !== null ? json_encode($oldValue, JSON_UNESCAPED_SLASHES) : null,
            'new_value' => $newValue !== null ? json_encode($newValue, JSON_UNESCAPED_SLASHES) : null,
            'status' => $status,
            'admin_decision' => $decided ? $status : null,
            'admin_remarks' => $remarks,
            'decision_date' => $decided ? date('Y-m-d H:i:s', strtotime('-' . ($index + 1) . ' days')) : null,
            'decided_by_id' => $decided ? $adminId : null,
            'created_at' => date('Y-m-d H:i:s', strtotime('-' . ($index + 3) . ' days')),
            'updated_at' => date('Y-m-d H:i:s', strtotime('-' . ($index + 1) . ' days')),
        ]);
        $count++;
    }

    return $count;
}

try {
    if (!$is_cli) {
        echo "<pre style='font-family: monospace; padding: 20px; background: #f0f0f0; color: #333;'>";
    }

    log_message('Starting database seed...', $is_cli);

    seed_normalize_people_status($pdo);
    $adminId = seed_user_id($pdo, 'admin', 1);
    $staffId = seed_user_id($pdo, 'staff', $adminId);

    $pdo->beginTransaction();

    cleanup_seed_rows($pdo);

    log_message('Adding master data...', $is_cli);
    $people = seed_people($pdo, $adminId);
    $products = seed_products($pdo);
    $categories = seed_project_categories($pdo);
    $contentCount = seed_business_center_content($pdo, $adminId);

    log_message('Adding operations data...', $is_cli);
    $batchCount = seed_inventory($pdo, $products, $adminId);
    $sales = seed_sales($pdo, $products, $adminId, $staffId);
    $cashCount = seed_cash_transactions($pdo, $sales['daily_totals'], $adminId);
    $accounts = seed_project_accounts($pdo, $categories, $people);
    $projectEntryCount = seed_project_entries($pdo, $categories, $accounts, $adminId, $staffId);
    $logbookCount = seed_logbook($pdo, $people, $staffId);
    $proposalCount = seed_proposals($pdo, $people, $adminId, $staffId);

    log_message('Adding admin and security data...', $is_cli);
    $securityCounts = seed_security_and_admin_records($pdo, $adminId, $staffId);
    $approvalCount = seed_approval_requests($pdo, $adminId, $staffId);

    $pdo->commit();

    $seedCashRows = (int) $pdo->query("SELECT COUNT(*) FROM cash_transactions WHERE or_number LIKE 'SEED-%' OR description LIKE 'Seed sample:%'")->fetchColumn();

    log_message('', $is_cli);
    log_message('Database seed completed successfully.', $is_cli);
    log_message('Generated or refreshed:', $is_cli);
    log_message('  People/personnel: ' . count($people), $is_cli);
    log_message('  Products/services: ' . count($products), $is_cli);
    log_message('  Inventory stock batches: ' . $batchCount, $is_cli);
    log_message('  Sales transactions: ' . $sales['count'], $is_cli);
    log_message('  Cash transactions: ' . $seedCashRows, $is_cli);
    log_message('  Project categories: ' . count($categories), $is_cli);
    log_message('  Project accounts: ' . count($accounts), $is_cli);
    log_message('  Project entries: ' . $projectEntryCount, $is_cli);
    log_message('  Office logbook entries: ' . $logbookCount, $is_cli);
    log_message('  Proposal requests: ' . $proposalCount, $is_cli);
    log_message('  Landing page content sections: ' . $contentCount, $is_cli);
    log_message('  Audit logs: ' . $securityCounts['audit'], $is_cli);
    log_message('  Session logs: ' . $securityCounts['sessions'], $is_cli);
    log_message('  Login attempts: ' . $securityCounts['login_attempts'], $is_cli);
    log_message('  Error logs: ' . $securityCounts['errors'], $is_cli);
    log_message('  Archived records: ' . $securityCounts['archives'], $is_cli);
    log_message('  Approval requests: ' . $approvalCount, $is_cli);

    if (!$is_cli) {
        echo '</pre>';
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $error_msg = 'Error: ' . $e->getMessage() . PHP_EOL . 'Trace: ' . $e->getTraceAsString();
    if ($is_cli) {
        echo $error_msg . PHP_EOL;
    } else {
        echo "<pre style='color: red;'>" . htmlspecialchars($error_msg) . '</pre>';
    }
    exit(1);
}
