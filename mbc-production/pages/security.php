<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';

$user = require_login($pdo);
require_permission($user, 'view_security_logs');

$type = (string) ($_GET['type'] ?? 'audit');
$type = in_array($type, ['audit', 'errors', 'login', 'sessions'], true) ? $type : 'audit';
$q = trim((string) ($_GET['q'] ?? ''));
$dateFrom = trim((string) ($_GET['date_from'] ?? ''));
$dateTo = trim((string) ($_GET['date_to'] ?? ''));
$logFilter = trim((string) ($_GET['log_filter'] ?? ''));
$order = strtolower((string) ($_GET['order'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
$perPage = 10;
$page = page_param();

function readable_log_details(?string $details): string
{
    $raw = trim((string) $details);
    if ($raw === '') {
        return '-';
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return $raw;
    }

    $parts = [];
    foreach ($decoded as $key => $value) {
        if (is_array($value)) {
            $value = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        $parts[] = ucwords(str_replace('_', ' ', (string) $key)) . ': ' . (string) $value;
    }

    return implode('; ', $parts);
}

function truncate_log_text(?string $text, int $limit = 90): string
{
    $value = trim((string) $text);
    if ($value === '') {
        return '-';
    }
    return strlen($value) > $limit ? substr($value, 0, $limit - 3) . '...' : $value;
}

function log_context_file(?string $details): string
{
    $decoded = json_decode((string) $details, true);
    if (!is_array($decoded)) {
        return '-';
    }
    foreach (['file', 'path', 'source', 'filename'] as $key) {
        if (!empty($decoded[$key]) && !is_array($decoded[$key])) {
            return basename((string) $decoded[$key]);
        }
    }
    return '-';
}

if ($type === 'errors') {
    $where = ['1=1'];
    $params = [];
    if ($q !== '') {
        $searchParam = prefix_search_param($q);
        $where[] = '(sel.message LIKE :q_message OR sel.context LIKE :q_context OR sel.severity LIKE :q_severity OR u.username LIKE :q_username)';
        $params['q_message'] = $searchParam;
        $params['q_context'] = $searchParam;
        $params['q_severity'] = $searchParam;
        $params['q_username'] = $searchParam;
    }
    if ($dateFrom !== '') {
        $where[] = 'DATE(sel.created_at) >= :date_from';
        $params['date_from'] = $dateFrom;
    }
    if ($dateTo !== '') {
        $where[] = 'DATE(sel.created_at) <= :date_to';
        $params['date_to'] = $dateTo;
    }
    if ($logFilter !== '') {
        $where[] = 'sel.severity = :log_filter';
        $params['log_filter'] = $logFilter;
    }

    $countSql = 'SELECT COUNT(*)
        FROM system_error_logs sel
        LEFT JOIN users u ON u.id = sel.user_id
        WHERE ' . implode(' AND ', $where);
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($params);
    $pagination = pagination_meta((int) $countStmt->fetchColumn(), $page, $perPage);

    $listSql = 'SELECT id, created_at, severity, message, context, ip_address, username
        FROM (
            SELECT sel.id, sel.created_at, sel.severity, sel.message, sel.context, sel.ip_address, u.username,
                ROW_NUMBER() OVER (ORDER BY sel.created_at ' . $order . ', sel.id ' . $order . ') AS row_num
            FROM system_error_logs sel
            LEFT JOIN users u ON u.id = sel.user_id
            WHERE ' . implode(' AND ', $where) . '
        ) ranked_errors
        WHERE row_num BETWEEN :first_row AND :last_row
        ORDER BY row_num';
    $stmt = $pdo->prepare($listSql);
    foreach ($params as $key => $value) {
        $stmt->bindValue(':' . ltrim((string) $key, ':'), $value);
    }
    [$firstRow, $lastRow] = pagination_row_bounds($pagination);
    $stmt->bindValue(':first_row', $firstRow, PDO::PARAM_INT);
    $stmt->bindValue(':last_row', $lastRow, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();
} elseif ($type === 'login') {
    $where = ['1=1'];
    $params = [];
    if ($q !== '') {
        $searchParam = prefix_search_param($q);
        $where[] = '(username LIKE :q_username OR ip_address LIKE :q_ip)';
        $params['q_username'] = $searchParam;
        $params['q_ip'] = $searchParam;
    }
    if ($dateFrom !== '') {
        $where[] = 'DATE(attempted_at) >= :date_from';
        $params['date_from'] = $dateFrom;
    }
    if ($dateTo !== '') {
        $where[] = 'DATE(attempted_at) <= :date_to';
        $params['date_to'] = $dateTo;
    }
    if (in_array($logFilter, ['success', 'failed'], true)) {
        $where[] = 'was_successful = :log_filter';
        $params['log_filter'] = $logFilter === 'success' ? 1 : 0;
    }

    $countSql = 'SELECT COUNT(*) FROM login_attempts WHERE ' . implode(' AND ', $where);
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($params);
    $pagination = pagination_meta((int) $countStmt->fetchColumn(), $page, $perPage);

    $listSql = 'SELECT id, created_at, username, ip_address, was_successful
        FROM (
            SELECT id, attempted_at AS created_at, username, ip_address, was_successful,
                ROW_NUMBER() OVER (ORDER BY attempted_at ' . $order . ', id ' . $order . ') AS row_num
            FROM login_attempts
            WHERE ' . implode(' AND ', $where) . '
        ) ranked_login
        WHERE row_num BETWEEN :first_row AND :last_row
        ORDER BY row_num';
    $stmt = $pdo->prepare($listSql);
    foreach ($params as $key => $value) {
        $stmt->bindValue(':' . ltrim((string) $key, ':'), $value);
    }
    [$firstRow, $lastRow] = pagination_row_bounds($pagination);
    $stmt->bindValue(':first_row', $firstRow, PDO::PARAM_INT);
    $stmt->bindValue(':last_row', $lastRow, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();
} elseif ($type === 'sessions') {
    $where = ['1=1'];
    $params = [];
    if ($q !== '') {
        $searchParam = prefix_search_param($q);
        $where[] = '(sl.event LIKE :q_event OR sl.ip_address LIKE :q_ip OR sl.user_agent LIKE :q_user_agent OR u.username LIKE :q_username)';
        $params['q_event'] = $searchParam;
        $params['q_ip'] = $searchParam;
        $params['q_user_agent'] = $searchParam;
        $params['q_username'] = $searchParam;
    }
    if ($dateFrom !== '') {
        $where[] = 'DATE(sl.created_at) >= :date_from';
        $params['date_from'] = $dateFrom;
    }
    if ($dateTo !== '') {
        $where[] = 'DATE(sl.created_at) <= :date_to';
        $params['date_to'] = $dateTo;
    }
    if ($logFilter !== '') {
        $where[] = 'sl.event = :log_filter';
        $params['log_filter'] = $logFilter;
    }

    $countSql = 'SELECT COUNT(*)
        FROM session_logs sl
        LEFT JOIN users u ON u.id = sl.user_id
        WHERE ' . implode(' AND ', $where);
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($params);
    $pagination = pagination_meta((int) $countStmt->fetchColumn(), $page, $perPage);

    $listSql = 'SELECT id, created_at, event, session_id, ip_address, user_agent, username
        FROM (
            SELECT sl.id, sl.created_at, sl.event, sl.session_id, sl.ip_address, sl.user_agent, u.username,
                ROW_NUMBER() OVER (ORDER BY sl.created_at ' . $order . ', sl.id ' . $order . ') AS row_num
            FROM session_logs sl
            LEFT JOIN users u ON u.id = sl.user_id
            WHERE ' . implode(' AND ', $where) . '
        ) ranked_sessions
        WHERE row_num BETWEEN :first_row AND :last_row
        ORDER BY row_num';
    $stmt = $pdo->prepare($listSql);
    foreach ($params as $key => $value) {
        $stmt->bindValue(':' . ltrim((string) $key, ':'), $value);
    }
    [$firstRow, $lastRow] = pagination_row_bounds($pagination);
    $stmt->bindValue(':first_row', $firstRow, PDO::PARAM_INT);
    $stmt->bindValue(':last_row', $lastRow, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();
} else {
    $where = ['1=1'];
    $params = [];
    if ($q !== '') {
        $searchParam = prefix_search_param($q);
        $where[] = '(al.action LIKE :q_action OR al.module LIKE :q_module OR al.entity_type LIKE :q_entity_type OR al.entity_id LIKE :q_entity_id OR al.details LIKE :q_details OR u.username LIKE :q_username)';
        $params['q_action'] = $searchParam;
        $params['q_module'] = $searchParam;
        $params['q_entity_type'] = $searchParam;
        $params['q_entity_id'] = $searchParam;
        $params['q_details'] = $searchParam;
        $params['q_username'] = $searchParam;
    }
    if ($dateFrom !== '') {
        $where[] = 'DATE(al.created_at) >= :date_from';
        $params['date_from'] = $dateFrom;
    }
    if ($dateTo !== '') {
        $where[] = 'DATE(al.created_at) <= :date_to';
        $params['date_to'] = $dateTo;
    }
    if ($logFilter !== '') {
        $where[] = 'al.module = :log_filter';
        $params['log_filter'] = $logFilter;
    }

    $countSql = 'SELECT COUNT(*)
        FROM audit_logs al
        LEFT JOIN users u ON u.id = al.user_id
        WHERE ' . implode(' AND ', $where);
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($params);
    $pagination = pagination_meta((int) $countStmt->fetchColumn(), $page, $perPage);

    $listSql = 'SELECT id, created_at, action, module, entity_type, entity_id, details, ip_address, username
        FROM (
            SELECT al.id, al.created_at, al.action, al.module, al.entity_type, al.entity_id, al.details, al.ip_address, u.username,
                ROW_NUMBER() OVER (ORDER BY al.created_at ' . $order . ', al.id ' . $order . ') AS row_num
            FROM audit_logs al
            LEFT JOIN users u ON u.id = al.user_id
            WHERE ' . implode(' AND ', $where) . '
        ) ranked_audit
        WHERE row_num BETWEEN :first_row AND :last_row
        ORDER BY row_num';
    $stmt = $pdo->prepare($listSql);
    foreach ($params as $key => $value) {
        $stmt->bindValue(':' . ltrim((string) $key, ':'), $value);
    }
    [$firstRow, $lastRow] = pagination_row_bounds($pagination);
    $stmt->bindValue(':first_row', $firstRow, PDO::PARAM_INT);
    $stmt->bindValue(':last_row', $lastRow, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();
}

audit_log($pdo, $user, 'view_security_logs', 'security', $type, null, ['q' => $q]);

$logTitle = match ($type) {
    'errors' => 'Error Logs',
    'login' => 'Login Attempts',
    'sessions' => 'Session Logs',
    default => 'Audit Trails',
};
$logSingular = match ($type) {
    'errors' => 'error log',
    'login' => 'login attempt',
    'sessions' => 'session log',
    default => 'audit trail',
};

$tabItems = [
    'audit' => 'Audit Trails',
    'sessions' => 'Sessions',
    'login' => 'Login Attempts',
    'errors' => 'Errors',
];
$filterLabel = match ($type) {
    'errors' => 'Severity',
    'login' => 'Status',
    'sessions' => 'Event',
    default => 'Module',
};
$filterOptions = match ($type) {
    'errors' => array_map(static fn(array $row): string => (string) $row['severity'], $pdo->query('SELECT DISTINCT severity FROM system_error_logs WHERE severity IS NOT NULL AND severity <> "" ORDER BY severity')->fetchAll()),
    'sessions' => array_map(static fn(array $row): string => (string) $row['event'], $pdo->query('SELECT DISTINCT event FROM session_logs WHERE event IS NOT NULL AND event <> "" ORDER BY event')->fetchAll()),
    'login' => ['success', 'failed'],
    default => array_map(static fn(array $row): string => (string) $row['module'], $pdo->query('SELECT DISTINCT module FROM audit_logs WHERE module IS NOT NULL AND module <> "" ORDER BY module')->fetchAll()),
};

render_header('Security Logs', $user);
?>

<section class="table-card data-panel">
    <div class="section-heading">
        <div>
            <h3>Security Logs</h3>
            <p class="muted mb-0">Review audit trails, sessions, login attempts, and system errors.</p>
        </div>
    </div>

    <nav class="tabs" aria-label="Security log sections">
        <?php foreach ($tabItems as $tabKey => $tabLabel): ?>
            <a class="tab-link <?= $type === $tabKey ? 'active' : '' ?>" href="security.php?type=<?= h($tabKey) ?>"><?= h($tabLabel) ?></a>
        <?php endforeach; ?>
    </nav>

    <form method="get" class="data-panel-filters security-filter-bar grid gap-3 md:grid-cols-2 xl:grid-cols-[minmax(220px,1.4fr)_minmax(280px,1.45fr)_minmax(160px,0.9fr)_minmax(140px,0.75fr)_minmax(110px,0.6fr)_auto] xl:items-end">
        <input type="hidden" name="type" value="<?= h($type) ?>">
        <div>
            <label for="q">Search</label>
            <input id="q" name="q" value="<?= h($q) ?>" placeholder="User, action, module, IP">
        </div>
        <div class="filter-date-range">
            <label>Date Range</label>
            <div class="grid gap-2 sm:grid-cols-2">
                <input id="date_from" name="date_from" type="date" value="<?= h($dateFrom) ?>" aria-label="Date from">
                <input id="date_to" name="date_to" type="date" value="<?= h($dateTo) ?>" aria-label="Date to">
            </div>
        </div>
        <div>
            <label for="log_filter"><?= h($filterLabel) ?></label>
            <select id="log_filter" name="log_filter">
                <option value="">All</option>
                <?php foreach ($filterOptions as $option): ?>
                    <option value="<?= h($option) ?>" <?= $logFilter === $option ? 'selected' : '' ?>><?= h(ucwords(str_replace('_', ' ', $option))) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="order">Sort</label>
            <select id="order" name="order">
                <option value="desc" <?= $order === 'DESC' ? 'selected' : '' ?>>Newest First</option>
                <option value="asc" <?= $order === 'ASC' ? 'selected' : '' ?>>Oldest First</option>
            </select>
        </div>
        <div>
            <label for="rows">Rows</label>
            <select id="rows" name="rows">
                <?php foreach ([10] as $rowOption): ?>
                    <option value="<?= $rowOption ?>" <?= $perPage === $rowOption ? 'selected' : '' ?>><?= $rowOption ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-actions">
            <button type="submit">Apply</button>
            <a class="btn alt" href="security.php?type=<?= h($type) ?>">Reset</a>
        </div>
    </form>

    <div class="table-wrap" data-no-client-table>
        <table>
            <thead>
            <?php if ($type === 'errors'): ?>
                <tr>
                    <th>Date</th>
                    <th>Severity</th>
                    <th>Message</th>
                    <th>File</th>
                    <th>User</th>
                    <th>Actions</th>
                </tr>
            <?php elseif ($type === 'login'): ?>
                <tr>
                    <th>Date</th>
                    <th>Username</th>
                    <th>IP</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            <?php elseif ($type === 'sessions'): ?>
                <tr>
                    <th>Date</th>
                    <th>User</th>
                    <th>Event</th>
                    <th>IP</th>
                    <th>Device</th>
                    <th>Actions</th>
                </tr>
            <?php else: ?>
                <tr>
                    <th>Date</th>
                    <th>User</th>
                    <th>Action</th>
                    <th>Module</th>
                    <th>Entity</th>
                    <th>IP</th>
                    <th>Actions</th>
                </tr>
            <?php endif; ?>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr>
                    <td colspan="<?= $type === 'login' ? '5' : ($type === 'sessions' ? '6' : ($type === 'errors' ? '6' : '7')) ?>" class="muted">No logs found.</td>
                </tr>
            <?php endif; ?>

            <?php foreach ($rows as $row): ?>
                <?php if ($type === 'errors'): ?>
                    <tr>
                        <td><?= h($row['created_at']) ?></td>
                        <td><?php render_status_badge((string) $row['severity']); ?></td>
                        <td><?= h(truncate_log_text((string) $row['message'])) ?></td>
                        <td><?= h(log_context_file((string) $row['context'])) ?></td>
                        <td><?= h($row['username'] ?: '-') ?></td>
                        <td><button type="button" class="btn alt" data-open-modal="security-log-<?= h($type) ?>-<?= (int) $row['id'] ?>">View</button></td>
                    </tr>
                <?php elseif ($type === 'login'): ?>
                    <tr>
                        <td><?= h($row['created_at']) ?></td>
                        <td><?= h($row['username']) ?></td>
                        <td><?= h($row['ip_address']) ?></td>
                        <td><?php render_status_badge(((int) $row['was_successful']) === 1 ? 'approved' : 'rejected'); ?></td>
                        <td><button type="button" class="btn alt" data-open-modal="security-log-<?= h($type) ?>-<?= (int) $row['id'] ?>">View</button></td>
                    </tr>
                <?php elseif ($type === 'sessions'): ?>
                    <tr>
                        <td><?= h($row['created_at']) ?></td>
                        <td><?= h($row['username'] ?: '-') ?></td>
                        <td><?= h($row['event']) ?></td>
                        <td><?= h($row['ip_address']) ?></td>
                        <td><?= h(truncate_log_text((string) $row['user_agent'], 48)) ?></td>
                        <td><button type="button" class="btn alt" data-open-modal="security-log-<?= h($type) ?>-<?= (int) $row['id'] ?>">View</button></td>
                    </tr>
                <?php else: ?>
                    <tr>
                        <td><?= h($row['created_at']) ?></td>
                        <td><?= h($row['username'] ?: '-') ?></td>
                        <td><?= h($row['action']) ?></td>
                        <td><?= h($row['module']) ?></td>
                        <td><?= h(trim((string) $row['entity_type'] . ' ' . (string) $row['entity_id']) ?: '-') ?></td>
                        <td><?= h($row['ip_address']) ?></td>
                        <td><button type="button" class="btn alt" data-open-modal="security-log-<?= h($type) ?>-<?= (int) $row['id'] ?>">View</button></td>
                    </tr>
                <?php endif; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php render_pagination($pagination); ?>
</section>

<?php foreach ($rows as $row): ?>
    <dialog id="security-log-<?= h($type) ?>-<?= (int) $row['id'] ?>" class="modal modal-wide">
        <div class="modal-header">
            <div>
                <h3><?= h($logTitle) ?> Details</h3>
                <p>Recorded on <?= h((string) $row['created_at']) ?></p>
            </div>
        </div>
        <div class="modal-content">
            <dl class="detail-grid">
                <?php if ($type === 'errors'): ?>
                    <div><dt>Date</dt><dd><?= h($row['created_at']) ?></dd></div>
                    <div><dt>Severity</dt><dd><?php render_status_badge((string) $row['severity']); ?></dd></div>
                    <div><dt>Message</dt><dd><?= h($row['message']) ?></dd></div>
                    <div><dt>File</dt><dd><?= h(log_context_file((string) $row['context'])) ?></dd></div>
                    <div><dt>User</dt><dd><?= h($row['username'] ?: '-') ?></dd></div>
                    <div><dt>IP</dt><dd><?= h($row['ip_address']) ?></dd></div>
                    <div class="field-wide"><dt>Details</dt><dd><pre class="whitespace-pre-wrap"><?= h(readable_log_details((string) $row['context'])) ?></pre></dd></div>
                <?php elseif ($type === 'login'): ?>
                    <div><dt>Date</dt><dd><?= h($row['created_at']) ?></dd></div>
                    <div><dt>Username</dt><dd><?= h($row['username']) ?></dd></div>
                    <div><dt>IP</dt><dd><?= h($row['ip_address']) ?></dd></div>
                    <div><dt>Status</dt><dd><?php render_status_badge(((int) $row['was_successful']) === 1 ? 'approved' : 'rejected'); ?></dd></div>
                <?php elseif ($type === 'sessions'): ?>
                    <div><dt>Date</dt><dd><?= h($row['created_at']) ?></dd></div>
                    <div><dt>User</dt><dd><?= h($row['username'] ?: '-') ?></dd></div>
                    <div><dt>Event</dt><dd><?= h($row['event']) ?></dd></div>
                    <div><dt>Session</dt><dd><?= h($row['session_id']) ?></dd></div>
                    <div><dt>IP</dt><dd><?= h($row['ip_address']) ?></dd></div>
                    <div class="field-wide"><dt>Device</dt><dd><?= h($row['user_agent']) ?></dd></div>
                <?php else: ?>
                    <div><dt>Date</dt><dd><?= h($row['created_at']) ?></dd></div>
                    <div><dt>User</dt><dd><?= h($row['username'] ?: '-') ?></dd></div>
                    <div><dt>Action</dt><dd><?= h($row['action']) ?></dd></div>
                    <div><dt>Module</dt><dd><?= h($row['module']) ?></dd></div>
                    <div><dt>Entity</dt><dd><?= h(trim((string) $row['entity_type'] . ' ' . (string) $row['entity_id']) ?: '-') ?></dd></div>
                    <div><dt>IP</dt><dd><?= h($row['ip_address']) ?></dd></div>
                    <div class="field-wide"><dt>Details</dt><dd><pre class="whitespace-pre-wrap"><?= h(readable_log_details((string) $row['details'])) ?></pre></dd></div>
                <?php endif; ?>
            </dl>
            <div class="modal-actions">
                <button type="button" class="btn alt" data-close-modal>Close</button>
            </div>
        </div>
    </dialog>
<?php endforeach; ?>

<?php render_footer();
