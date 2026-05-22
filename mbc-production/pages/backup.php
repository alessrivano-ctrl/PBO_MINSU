<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';

$user = require_login($pdo);
require_permission($user, 'manage_backups');

function generate_sql_backup(PDO $pdo, string $filePath): void
{
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM);
    $org = organization_profile($pdo);

    $sql = '';
    $sql .= '-- ' . $org['campus_display_name'] . ' ' . $org['system_name'] . ' MySQL Backup' . PHP_EOL;
    $sql .= '-- Generated at: ' . date('Y-m-d H:i:s') . PHP_EOL;
    $sql .= 'SET FOREIGN_KEY_CHECKS=0;' . PHP_EOL . PHP_EOL;

    foreach ($tables as $tableRow) {
        $table = (string) ($tableRow[0] ?? '');
        if ($table === '') {
            continue;
        }
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            continue;
        }

        $createData = $pdo->query('SHOW CREATE TABLE `' . str_replace('`', '``', $table) . '`')->fetch(PDO::FETCH_ASSOC);
        $createSql = $createData['Create Table'] ?? '';
        if ($createSql === '') {
            continue;
        }

        $sql .= '-- --------------------------------------------------' . PHP_EOL;
        $sql .= '-- Table: ' . $table . PHP_EOL;
        $sql .= '-- --------------------------------------------------' . PHP_EOL;
        $sql .= 'DROP TABLE IF EXISTS `' . $table . '`;' . PHP_EOL;
        $sql .= $createSql . ';' . PHP_EOL . PHP_EOL;

        $columns = $pdo->query('SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . '`')->fetchAll(PDO::FETCH_ASSOC);
        $columnNames = array_map(static fn(array $col): string => '`' . $col['Field'] . '`', $columns);

        $rowsStmt = $pdo->query('SELECT ' . implode(', ', $columnNames) . ' FROM `' . str_replace('`', '``', $table) . '`');
        $rows = $rowsStmt ? $rowsStmt->fetchAll(PDO::FETCH_NUM) : [];

        if ($rows) {
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
    }

    $sql .= 'SET FOREIGN_KEY_CHECKS=1;' . PHP_EOL;

    file_put_contents($filePath, $sql);
}

if (isset($_GET['download'])) {
    $downloadName = basename((string) $_GET['download']);
    $fullPath = dirname(__DIR__) . '/backups/' . $downloadName;

    if (!is_file($fullPath)) {
        set_flash('error', 'Backup file not found.');
        redirect('backup.php');
    }

    header('Content-Type: application/sql');
    header('Content-Disposition: attachment; filename="' . $downloadName . '"');
    header('Content-Length: ' . (string) filesize($fullPath));
    audit_log($pdo, $user, 'download_backup', 'backup', 'backup_file', $downloadName);
    readfile($fullPath);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['csrf_token'] ?? '');
    if (!verify_csrf($token)) {
        set_flash('error', 'Invalid form token.');
        redirect('backup.php');
    }

    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'create_backup') {
        $filename = 'backup_' . date('Ymd_His') . '.sql';
        $backupPath = dirname(__DIR__) . '/backups/' . $filename;

        try {
            generate_sql_backup($pdo, $backupPath);
            audit_log($pdo, $user, 'create_backup', 'backup', 'backup_file', $filename);
            set_flash('success', 'Backup created successfully: ' . $filename);
        } catch (Throwable $e) {
            log_system_issue($pdo, 'critical', 'Backup failed.', ['error' => $e->getMessage(), 'filename' => $filename], $user);
            set_flash('error', 'Backup failed: ' . $e->getMessage());
        }

        redirect('backup.php');
    }
}

$backupFiles = glob(dirname(__DIR__) . '/backups/*.sql') ?: [];
rsort($backupFiles);
$lastBackup = $backupFiles[0] ?? null;
$lastBackupLabel = $lastBackup ? date('Y-m-d H:i:s', (int) filemtime($lastBackup)) : 'No backups yet';

render_header('Database Backup', $user);
?>

<section class="dashboard-card-grid mb-4">
    <div class="card stat-card">
        <span>Last Backup</span>
        <strong><?= h($lastBackupLabel) ?></strong>
    </div>
    <div class="card stat-card">
        <span>Total Backup Files</span>
        <strong><?= h((string) count($backupFiles)) ?></strong>
    </div>
</section>

<section class="table-card data-panel">
    <div class="section-heading">
        <div>
            <h3>Backup Files</h3>
            <p class="muted mb-0">Create and download database backup files.</p>
        </div>
        <div class="inline-actions">
            <form method="post" class="no-print">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action" value="create_backup">
                <button type="submit">Create Backup</button>
            </form>
        </div>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th>File Name</th>
                <th>Created</th>
                <th>Size</th>
                <th>Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$backupFiles): ?>
                <tr>
                    <td colspan="4" class="muted">No backups yet.</td>
                </tr>
            <?php endif; ?>
            <?php foreach ($backupFiles as $path): ?>
                <?php $name = basename($path); ?>
                <tr>
                    <td><?= h($name) ?></td>
                    <td><?= h(date('Y-m-d H:i:s', (int) filemtime($path))) ?></td>
                    <td><?= h(number_format((float) filesize($path) / 1024, 2)) ?> KB</td>
                    <td>
                        <a class="btn alt" href="backup.php?download=<?= urlencode($name) ?>">Download</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?php render_footer();
