<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';

$user = require_login($pdo);
require_permission($user, 'manage_settings');

function settings_generate_sql_backup(PDO $pdo, string $filePath): void
{
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM);
    $org = organization_profile($pdo);

    $sql = '';
    $sql .= '-- ' . $org['campus_display_name'] . ' ' . $org['system_name'] . ' MySQL Backup' . PHP_EOL;
    $sql .= '-- Generated at: ' . date('Y-m-d H:i:s') . PHP_EOL;
    $sql .= 'SET FOREIGN_KEY_CHECKS=0;' . PHP_EOL . PHP_EOL;

    foreach ($tables as $tableRow) {
        $table = (string) ($tableRow[0] ?? '');
        if ($table === '' || !preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            continue;
        }

        $createData = $pdo->query('SHOW CREATE TABLE `' . str_replace('`', '``', $table) . '`')->fetch(PDO::FETCH_ASSOC);
        $createSql = (string) ($createData['Create Table'] ?? '');
        if ($createSql === '') {
            continue;
        }

        $sql .= '-- Table: ' . $table . PHP_EOL;
        $sql .= 'DROP TABLE IF EXISTS `' . $table . '`;' . PHP_EOL;
        $sql .= $createSql . ';' . PHP_EOL . PHP_EOL;

        $columns = $pdo->query('SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . '`')->fetchAll(PDO::FETCH_ASSOC);
        $columnNames = array_map(static fn(array $col): string => '`' . $col['Field'] . '`', $columns);
        $rowsStmt = $pdo->query('SELECT ' . implode(', ', $columnNames) . ' FROM `' . str_replace('`', '``', $table) . '`');
        $rows = $rowsStmt ? $rowsStmt->fetchAll(PDO::FETCH_NUM) : [];

        if (!$rows) {
            continue;
        }

        $sql .= 'INSERT INTO `' . $table . '` (' . implode(', ', $columnNames) . ') VALUES' . PHP_EOL;
        $valueLines = [];
        foreach ($rows as $row) {
            $values = [];
            foreach ($row as $value) {
                $values[] = $value === null ? 'NULL' : $pdo->quote((string) $value);
            }
            $valueLines[] = '(' . implode(', ', $values) . ')';
        }
        $sql .= implode(',' . PHP_EOL, $valueLines) . ';' . PHP_EOL . PHP_EOL;
    }

    $sql .= 'SET FOREIGN_KEY_CHECKS=1;' . PHP_EOL;
    file_put_contents($filePath, $sql);
}

if (isset($_GET['download'])) {
    $downloadName = basename((string) $_GET['download']);
    $fullPath = dirname(__DIR__) . '/backups/' . $downloadName;

    if (!is_file($fullPath)) {
        set_flash('error', 'Backup file not found.');
        redirect('settings.php?section=backup');
    }

    header('Content-Type: application/sql');
    header('Content-Disposition: attachment; filename="' . $downloadName . '"');
    header('Content-Length: ' . (string) filesize($fullPath));
    audit_log($pdo, $user, 'download_backup', 'settings', 'backup_file', $downloadName);
    readfile($fullPath);
    exit;
}

$sections = [
    'categories' => 'Categories',
    'reports' => 'Receipts & Reports',
    'security' => 'Security',
];
$section = (string) ($_GET['section'] ?? $_POST['section'] ?? 'categories');
if (!isset($sections[$section])) {
    $section = 'categories';
}
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['csrf_token'] ?? '');
    if (!verify_csrf($token)) {
        set_flash('error', 'Invalid form token.');
        redirect('settings.php?section=' . urlencode($section));
    }

    $action = (string) ($_POST['action'] ?? 'save_settings');

    if ($section === 'categories' && $action === 'add_inventory_category') {
        $categoryName = trim((string) ($_POST['category_name'] ?? ''));
        $categorySection = (string) ($_POST['category_section'] ?? 'products') === 'services' ? 'services' : 'products';
        if ($categoryName === '') {
            set_flash('error', 'Category name is required.');
            redirect('settings.php?section=categories');
        }
        try {
            $stmt = $pdo->prepare('INSERT INTO inventory_categories (name, section) VALUES (:name, :section)');
            $stmt->execute(['name' => substr($categoryName, 0, 120), 'section' => $categorySection]);
            audit_log($pdo, $user, 'create', 'settings', 'inventory_category', $categoryName, ['section' => $categorySection]);
            set_flash('success', 'Category added.');
        } catch (Throwable $e) {
            set_flash('error', 'Could not add category. It may already exist.');
        }
        redirect('settings.php?section=categories');
    }

    if ($section === 'categories' && $action === 'update_inventory_category') {
        $categoryId = (int) ($_POST['category_id'] ?? 0);
        $categoryName = trim((string) ($_POST['category_name'] ?? ''));
        $categorySection = (string) ($_POST['category_section'] ?? 'products') === 'services' ? 'services' : 'products';
        if ($categoryId <= 0 || $categoryName === '') {
            set_flash('error', 'Valid category details are required.');
            redirect('settings.php?section=categories');
        }
        $existingStmt = $pdo->prepare('SELECT name FROM inventory_categories WHERE id = :id AND section = :section AND is_active = 1');
        $existingStmt->execute(['id' => $categoryId, 'section' => $categorySection]);
        $oldName = $existingStmt->fetchColumn();
        if ($oldName === false) {
            set_flash('error', 'Category not found.');
            redirect('settings.php?section=categories');
        }
        try {
            $pdo->beginTransaction();
            $updateCategory = $pdo->prepare('UPDATE inventory_categories SET name = :name WHERE id = :id AND section = :section');
            $updateCategory->execute(['name' => substr($categoryName, 0, 120), 'id' => $categoryId, 'section' => $categorySection]);
            $updateProducts = $pdo->prepare('UPDATE products SET category_name = :new_name WHERE category = "other" AND category_name = :old_name AND type = :type');
            $updateProducts->execute([
                'new_name' => substr($categoryName, 0, 120),
                'old_name' => (string) $oldName,
                'type' => $categorySection === 'services' ? 'service' : 'item',
            ]);
            $pdo->commit();
            audit_log($pdo, $user, 'update', 'settings', 'inventory_category', (string) $categoryId, ['old_name' => $oldName, 'new_name' => $categoryName, 'section' => $categorySection]);
            set_flash('success', 'Category updated.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            set_flash('error', 'Could not update category.');
        }
        redirect('settings.php?section=categories');
    }

    if ($action === 'create_backup') {
        $filename = 'backup_' . date('Ymd_His') . '.sql';
        $backupDir = dirname(__DIR__) . '/backups';
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0775, true);
        }

        try {
            settings_generate_sql_backup($pdo, $backupDir . '/' . $filename);
            audit_log($pdo, $user, 'create_backup', 'settings', 'backup_file', $filename);
            set_flash('success', 'Backup created.');
        } catch (Throwable $e) {
            log_system_issue($pdo, 'critical', 'Backup failed.', ['error' => $e->getMessage()], $user);
            set_flash('error', 'Backup failed.');
        }
        redirect('settings.php?section=backup');
    }

    $updates = [];
    if ($section === 'organization') {
        $fieldMap = [
            'university_name' => 'organization.university_name',
            'campus_name' => 'organization.campus_name',
            'office_name' => 'organization.office_name',
            'system_name' => 'organization.system_name',
            'address' => 'organization.address',
            'contact_information' => 'organization.contact_information',
        ];
        foreach ($fieldMap as $field => $key) {
            $value = trim((string) ($_POST[$field] ?? ''));
            if (in_array($field, ['university_name', 'campus_name', 'office_name', 'system_name'], true) && $value === '') {
                $errors[$field] = 'This field is required.';
            }
            $updates[$key] = $value;
        }

        if (isset($_FILES['logo_upload']) && is_array($_FILES['logo_upload']) && (int) ($_FILES['logo_upload']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            if ((int) $_FILES['logo_upload']['error'] !== UPLOAD_ERR_OK) {
                $errors['logo_upload'] = 'Logo upload failed.';
            } else {
                $tmpName = (string) $_FILES['logo_upload']['tmp_name'];
                $mime = mime_content_type($tmpName) ?: '';
                $extensions = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];
                if (!isset($extensions[$mime])) {
                    $errors['logo_upload'] = 'Use a PNG, JPG, or WEBP logo.';
                } else {
                    $target = 'assets/images/system-logo.' . $extensions[$mime];
                    if (!move_uploaded_file($tmpName, APP_PUBLIC . '/' . $target)) {
                        $errors['logo_upload'] = 'Unable to save uploaded logo.';
                    } else {
                        $updates['organization.logo_path'] = $target;
                    }
                }
            }
        }
    }

    if ($section === 'display') {
        $sidebarState = (string) ($_POST['sidebar_default_state'] ?? 'expanded');
        $tableRows = (int) ($_POST['default_table_rows'] ?? 10);
        $dashboardRange = (string) ($_POST['dashboard_default_range'] ?? 'daily');
        $themeColor = (string) ($_POST['theme_color'] ?? 'green-gold');

        if (!in_array($sidebarState, ['expanded', 'collapsed'], true)) {
            $errors['sidebar_default_state'] = 'Choose expanded or collapsed.';
        }
        if (!in_array($tableRows, [10], true)) {
            $errors['default_table_rows'] = 'Choose a supported row count.';
        }
        if (!in_array($dashboardRange, ['daily', 'weekly', 'monthly', 'annual'], true)) {
            $errors['dashboard_default_range'] = 'Choose a supported range.';
        }

        $updates = [
            'display.sidebar_default_state' => $sidebarState,
            'display.default_table_rows' => (string) $tableRows,
            'display.dashboard_default_range' => $dashboardRange,
            'display.theme_color' => $themeColor,
        ];
    }

    if ($section === 'reports') {
        $fieldMap = [
            'receipt_header' => 'reports.receipt_header',
            'or_number_format' => 'reports.or_number_format',
            'prepared_by_default' => 'reports.prepared_by_default',
            'reviewed_by_default' => 'reports.reviewed_by_default',
            'approved_by_default' => 'reports.approved_by_default',
            'footer_notes' => 'reports.footer_notes',
            'confidentiality_note' => 'reports.confidentiality_note',
        ];
        foreach ($fieldMap as $field => $key) {
            $updates[$key] = trim((string) ($_POST[$field] ?? ''));
        }
        if ($updates['reports.receipt_header'] === '') {
            $errors['receipt_header'] = 'Receipt header is required.';
        }
    }

    if ($section === 'security') {
        $maxAttempts = (int) ($_POST['maximum_login_attempts'] ?? 5);
        $lockDuration = (int) ($_POST['account_lock_duration'] ?? 15);
        $sessionTimeout = (int) ($_POST['session_timeout'] ?? 30);
        $passwordMinimum = (int) ($_POST['password_minimum_length'] ?? 8);

        if ($maxAttempts < 3 || $maxAttempts > 20) {
            $errors['maximum_login_attempts'] = 'Use 3 to 20 attempts.';
        }
        if ($lockDuration < 1 || $lockDuration > 240) {
            $errors['account_lock_duration'] = 'Use 1 to 240 minutes.';
        }
        if ($sessionTimeout < 5 || $sessionTimeout > 480) {
            $errors['session_timeout'] = 'Use 5 to 480 minutes.';
        }
        if ($passwordMinimum < 8 || $passwordMinimum > 64) {
            $errors['password_minimum_length'] = 'Use 8 to 64 characters.';
        }

        $updates = [
            'security.maximum_login_attempts' => (string) $maxAttempts,
            'security.account_lock_duration' => (string) $lockDuration,
            'security.session_timeout' => (string) $sessionTimeout,
            'security.password_minimum_length' => (string) $passwordMinimum,
            'security.require_strong_password' => isset($_POST['require_strong_password']) ? '1' : '0',
            'security.enable_session_logs' => isset($_POST['enable_session_logs']) ? '1' : '0',
        ];
    }

    if (!$errors && $updates) {
        save_system_settings($pdo, $updates, $user);
        audit_log($pdo, $user, 'update_system_settings', 'settings', 'section', $section, ['keys' => array_keys($updates)]);
        set_flash('success', 'Settings saved.');
        redirect('settings.php?section=' . urlencode($section));
    }
}

$settings = app_settings($pdo, true);
$inventoryCategories = [];
if ($section === 'categories') {
    $inventoryCategories = $pdo->query('SELECT id, name, section FROM inventory_categories WHERE is_active = 1 ORDER BY section, name')->fetchAll();
}
$backupFiles = glob(dirname(__DIR__) . '/backups/*.sql') ?: [];
rsort($backupFiles);

render_header('System Settings', $user);
?>

<section class="settings-page-shell">
    <div class="settings-hero table-card">
        <div>
            <h2>System Settings</h2>
            <p>Manage categories, receipt report defaults, and login security.</p>
        </div>
    </div>

    <nav class="tabs" aria-label="System settings sections">
        <?php foreach ($sections as $key => $label): ?>
            <a class="tab-link <?= $section === $key ? 'active' : '' ?>" href="settings.php?section=<?= h($key) ?>"><?= h($label) ?></a>
        <?php endforeach; ?>
    </nav>

    <section class="table-card data-panel settings-panel">
        <div class="section-heading">
            <div>
                <h3><?= h($sections[$section]) ?></h3>
                <p class="muted mb-0">
                    <?= h(match ($section) {
                        'reports' => 'Configure receipt labels and report signatory defaults.',
                        'security' => 'Set login protection and session requirements.',
                        default => 'Manage product and service categories used across inventory forms.',
                    }) ?>
                </p>
            </div>
        </div>

        <?php if ($section === 'categories'): ?>
            <div class="settings-content">
                <form method="post" class="settings-form-card">
                    <div class="settings-card-heading">
                        <h4>Add Category</h4>
                        <p>Create selectable categories for product and service records.</p>
                    </div>
                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="section" value="categories">
                    <input type="hidden" name="action" value="add_inventory_category">
                    <div class="settings-form-grid settings-form-grid-compact">
                        <div>
                            <label for="category_section">Category Type</label>
                            <select id="category_section" name="category_section">
                                <option value="products">Products</option>
                                <option value="services">Services</option>
                            </select>
                        </div>
                        <div>
                            <label for="new_category_name">Category Name</label>
                            <input id="new_category_name" name="category_name" required>
                        </div>
                        <div class="form-submit">
                            <button type="submit">Add Category</button>
                        </div>
                    </div>
                </form>

                <div class="settings-table-card">
                    <div class="settings-card-heading">
                        <h4>Managed Categories</h4>
                        <p><?= h((string) count($inventoryCategories)) ?> active categor<?= count($inventoryCategories) === 1 ? 'y' : 'ies' ?> available.</p>
                    </div>
                <div class="table-wrap" data-no-client-table>
                    <table>
                        <thead><tr><th>Category</th><th>Type</th><th>Actions</th></tr></thead>
                        <tbody>
                        <?php if (!$inventoryCategories): ?>
                            <tr><td colspan="3" class="muted">No managed categories yet.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($inventoryCategories as $category): ?>
                            <tr>
                                <td><?= h($category['name']) ?></td>
                                <td><?= h(ucfirst((string) $category['section'])) ?></td>
                                <td>
                                    <form method="post" class="inline-form">
                                        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                        <input type="hidden" name="section" value="categories">
                                        <input type="hidden" name="action" value="update_inventory_category">
                                        <input type="hidden" name="category_id" value="<?= (int) $category['id'] ?>">
                                        <input type="hidden" name="category_section" value="<?= h($category['section']) ?>">
                                        <input name="category_name" value="<?= h($category['name']) ?>" required>
                                        <button type="submit" class="btn alt">Edit Category</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                </div>
            </div>
        <?php else: ?>
            <form method="post" enctype="multipart/form-data" class="settings-content">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="section" value="<?= h($section) ?>">
                <input type="hidden" name="action" value="save_settings">

                <?php if ($section === 'organization'): ?>
                    <div class="form-grid">
                        <?php
                        $fields = [
                            'university_name' => ['University Name', 'organization.university_name'],
                            'campus_name' => ['Campus Name', 'organization.campus_name'],
                            'office_name' => ['Office Name', 'organization.office_name'],
                            'system_name' => ['System Name', 'organization.system_name'],
                            'address' => ['Address', 'organization.address'],
                            'contact_information' => ['Contact Information', 'organization.contact_information'],
                        ];
                        ?>
                        <?php foreach ($fields as $field => [$label, $key]): ?>
                            <div class="<?= in_array($field, ['address', 'contact_information'], true) ? 'field-wide' : '' ?>">
                                <label for="<?= h($field) ?>"><?= h($label) ?></label>
                                <input id="<?= h($field) ?>" name="<?= h($field) ?>" value="<?= h((string) ($settings[$key] ?? '')) ?>" <?= in_array($field, ['university_name', 'campus_name', 'office_name', 'system_name'], true) ? 'required' : '' ?>>
                                <?php if (isset($errors[$field])): ?><p class="mt-1 text-xs font-semibold text-red-700"><?= h($errors[$field]) ?></p><?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                        <div class="field-wide">
                            <label for="logo_upload">Logo Upload</label>
                            <input id="logo_upload" name="logo_upload" type="file" accept="image/png,image/jpeg,image/webp">
                            <p class="mt-1 text-xs text-slate-500">Current logo: <?= h((string) ($settings['organization.logo_path'] ?? APP_LOGO)) ?></p>
                            <?php if (isset($errors['logo_upload'])): ?><p class="mt-1 text-xs font-semibold text-red-700"><?= h($errors['logo_upload']) ?></p><?php endif; ?>
                        </div>
                    </div>
                <?php elseif ($section === 'display'): ?>
                    <div class="form-grid">
                        <div>
                            <label for="sidebar_default_state">Sidebar Default State</label>
                            <select id="sidebar_default_state" name="sidebar_default_state">
                                <option value="expanded" <?= ($settings['display.sidebar_default_state'] ?? '') === 'expanded' ? 'selected' : '' ?>>Expanded</option>
                                <option value="collapsed" <?= ($settings['display.sidebar_default_state'] ?? '') === 'collapsed' ? 'selected' : '' ?>>Collapsed</option>
                            </select>
                        </div>
                        <div>
                            <label for="default_table_rows">Default Table Rows Per Page</label>
                            <select id="default_table_rows" name="default_table_rows">
                                <?php foreach ([10] as $rows): ?>
                                    <option value="<?= $rows ?>" <?= (int) ($settings['display.default_table_rows'] ?? 10) === $rows ? 'selected' : '' ?>><?= $rows ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label for="dashboard_default_range">Dashboard Default Range</label>
                            <select id="dashboard_default_range" name="dashboard_default_range">
                                <?php foreach (['daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly', 'annual' => 'Annual'] as $value => $label): ?>
                                    <option value="<?= h($value) ?>" <?= ($settings['display.dashboard_default_range'] ?? '') === $value ? 'selected' : '' ?>><?= h($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label for="theme_color">Theme Color</label>
                            <input id="theme_color" name="theme_color" value="<?= h((string) ($settings['display.theme_color'] ?? 'green-gold')) ?>">
                        </div>
                    </div>
                <?php elseif ($section === 'reports'): ?>
                    <div class="settings-form-card">
                        <div class="settings-card-heading">
                            <h4>Receipt Header</h4>
                            <p>Control printed receipt labels and official report wording.</p>
                        </div>
                    <div class="settings-form-grid">
                        <?php
                        $receiptFields = [
                            'receipt_header' => ['Receipt Header', 'reports.receipt_header'],
                            'or_number_format' => ['OR Number Format', 'reports.or_number_format'],
                            'footer_notes' => ['Footer Notes', 'reports.footer_notes'],
                            'confidentiality_note' => ['Confidentiality Note', 'reports.confidentiality_note'],
                        ];
                        ?>
                        <?php foreach ($receiptFields as $field => [$label, $key]): ?>
                            <div class="<?= in_array($field, ['footer_notes', 'confidentiality_note'], true) ? 'field-wide' : '' ?>">
                                <label for="<?= h($field) ?>"><?= h($label) ?></label>
                                <input id="<?= h($field) ?>" name="<?= h($field) ?>" value="<?= h((string) ($settings[$key] ?? '')) ?>">
                                <?php if (isset($errors[$field])): ?><p class="mt-1 text-xs font-semibold text-red-700"><?= h($errors[$field]) ?></p><?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    </div>
                    <div class="settings-form-card">
                        <div class="settings-card-heading">
                            <h4>Report Signatories</h4>
                            <p>Default names shown in generated reports.</p>
                        </div>
                        <div class="settings-form-grid">
                            <?php
                            $signatoryFields = [
                                'prepared_by_default' => ['Prepared By', 'reports.prepared_by_default'],
                                'reviewed_by_default' => ['Reviewed By', 'reports.reviewed_by_default'],
                                'approved_by_default' => ['Approved By', 'reports.approved_by_default'],
                            ];
                            ?>
                            <?php foreach ($signatoryFields as $field => [$label, $key]): ?>
                                <div>
                                    <label for="<?= h($field) ?>"><?= h($label) ?></label>
                                    <input id="<?= h($field) ?>" name="<?= h($field) ?>" value="<?= h((string) ($settings[$key] ?? '')) ?>">
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php elseif ($section === 'security'): ?>
                    <div class="settings-form-card">
                        <div class="settings-card-heading">
                            <h4>Login Protection</h4>
                            <p>Keep controls compact and easy to scan for defense presentation.</p>
                        </div>
                    <div class="settings-form-grid">
                        <div>
                            <label for="maximum_login_attempts">Maximum Login Attempts</label>
                            <input id="maximum_login_attempts" name="maximum_login_attempts" type="number" min="3" max="20" value="<?= h((string) ($settings['security.maximum_login_attempts'] ?? '5')) ?>">
                            <?php if (isset($errors['maximum_login_attempts'])): ?><p class="mt-1 text-xs font-semibold text-red-700"><?= h($errors['maximum_login_attempts']) ?></p><?php endif; ?>
                        </div>
                        <div>
                            <label for="account_lock_duration">Account Lock Duration</label>
                            <input id="account_lock_duration" name="account_lock_duration" type="number" min="1" max="240" value="<?= h((string) ($settings['security.account_lock_duration'] ?? '15')) ?>">
                        </div>
                        <div>
                            <label for="session_timeout">Session Timeout</label>
                            <input id="session_timeout" name="session_timeout" type="number" min="5" max="480" value="<?= h((string) ($settings['security.session_timeout'] ?? '30')) ?>">
                        </div>
                        <div>
                            <label for="password_minimum_length">Password Minimum Length</label>
                            <input id="password_minimum_length" name="password_minimum_length" type="number" min="8" max="64" value="<?= h((string) ($settings['security.password_minimum_length'] ?? '8')) ?>">
                        </div>
                    </div>
                    </div>
                    <div class="settings-form-card">
                        <div class="settings-card-heading">
                            <h4>Security Requirements</h4>
                            <p>Enable password and session safeguards.</p>
                        </div>
                        <div class="settings-toggle-grid">
                        <label class="settings-toggle-option">
                            <input type="checkbox" name="require_strong_password" value="1" <?= ($settings['security.require_strong_password'] ?? '1') === '1' ? 'checked' : '' ?>>
                            <span>
                                <strong>Require strong password</strong>
                                <small>Use stricter password validation for user accounts.</small>
                            </span>
                        </label>
                        <label class="settings-toggle-option">
                            <input type="checkbox" name="enable_session_logs" value="1" <?= ($settings['security.enable_session_logs'] ?? '1') === '1' ? 'checked' : '' ?>>
                            <span>
                                <strong>Enable session logs</strong>
                                <small>Record sign-in and session activity in Security Logs.</small>
                            </span>
                        </label>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="settings-actions">
                    <a class="btn alt" href="settings.php?section=<?= h($section) ?>">Reset</a>
                    <button type="submit">Save Changes</button>
                </div>
            </form>
        <?php endif; ?>
    </section>
</section>

<?php render_footer();

