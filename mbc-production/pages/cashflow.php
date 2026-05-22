<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';

$user = require_login($pdo);

$cashSourceGroups = [
    'system' => [
        'pos_sales' => 'POS Sales',
        'services' => 'Services',
        'rentals' => 'Rentals',
        'fishpond' => 'Fishpond',
    ],
    'manual' => [
        'miscellaneous_income' => 'Miscellaneous Income',
        'miscellaneous_expense' => 'Miscellaneous Expense',
        'inventory_expense' => 'Inventory Expense',
        'maintenance' => 'Maintenance',
        'utilities' => 'Utilities',
        'manual_adjustment' => 'Manual Adjustment',
    ],
];
$manualCategories = [
    'in' => [
        'miscellaneous_income' => 'Miscellaneous Income',
        'manual_adjustment' => 'Manual Adjustment',
    ],
    'out' => [
        'utilities' => 'Utilities',
        'maintenance' => 'Maintenance',
        'inventory_expense' => 'Inventory Expense',
        'miscellaneous_expense' => 'Miscellaneous Expense',
    ],
];
$manualCategoryLabels = $manualCategories['in'] + $manualCategories['out'];
$validSourceFilters = array_merge(array_keys($cashSourceGroups['system']), array_keys($cashSourceGroups['manual']));

function cashflow_source_label(array $row, array $manualCategoryLabels): string
{
    if (($row['source_module'] ?? '') === 'manual' && !empty($row['manual_category'])) {
        return $manualCategoryLabels[(string) $row['manual_category']] ?? 'Manual Adjustment';
    }

    return match ((string) ($row['source_module'] ?? '')) {
        'sales' => 'POS Sales',
        'printing', 'photocopy', 'laundry', 'business-center' => 'Services',
        'rental', 'toga' => 'Rentals',
        'fishpond' => 'Fishpond',
        'inventory' => 'Inventory Expense',
        'manual' => 'Manual Adjustment',
        default => 'Manual Adjustment',
    };
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['csrf_token'] ?? '');
    if (!verify_csrf($token)) {
        set_flash('error', 'Invalid form token.');
        redirect('cashflow.php');
    }

    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'add_transaction') {
        $txnDate = normalize_datetime_input((string) ($_POST['txn_date'] ?? ''));
        $direction = (string) ($_POST['direction'] ?? 'in');
        $source = 'manual';
        $manualCategory = (string) ($_POST['manual_category'] ?? '');
        $amount = (float) ($_POST['amount'] ?? 0);
        $orNumber = trim((string) ($_POST['or_number'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));

        $validDirections = ['in', 'out'];

        if (!in_array($direction, $validDirections, true) || !isset($manualCategories[$direction][$manualCategory])) {
            set_flash('error', 'Invalid manual entry type or category.');
            redirect('cashflow.php');
        }

        if ($amount <= 0) {
            set_flash('error', 'Amount must be greater than zero.');
            redirect('cashflow.php');
        }

        try {
            $stmt = $pdo->prepare('INSERT INTO cash_transactions (txn_date, direction, source_module, manual_category, amount, or_number, description, created_by)
                VALUES (:txn_date, :direction, :source_module, :manual_category, :amount, :or_number, :description, :created_by)');
            $stmt->execute([
                'txn_date' => $txnDate,
                'direction' => $direction,
                'source_module' => $source,
                'manual_category' => $manualCategory,
                'amount' => $amount,
                'or_number' => $orNumber !== '' ? $orNumber : null,
                'description' => $description !== '' ? $description : null,
                'created_by' => (int) $user['id'],
            ]);
            audit_log($pdo, $user, 'create_transaction', 'cashflow', 'cash_transaction', (int) $pdo->lastInsertId(), [
                'direction' => $direction,
                'source' => $source,
                'manual_category' => $manualCategory,
                'amount' => $amount,
            ]);
            set_flash('success', 'Cash transaction saved.');
        } catch (Throwable $e) {
            log_system_issue($pdo, 'error', 'Failed to save cash transaction.', ['error' => $e->getMessage()], $user);
            set_flash('error', 'Failed to save cash transaction.');
        }
        redirect('cashflow.php');
    }
}

$from = trim((string) ($_GET['from'] ?? date('Y-m-01')));
$to = trim((string) ($_GET['to'] ?? date('Y-m-d')));
$directionFilter = (string) ($_GET['direction'] ?? 'all');
$sourceFilter = (string) ($_GET['source_module'] ?? 'all');
$q = trim((string) ($_GET['q'] ?? ''));
[$fromDateTime, $toDateTimeExclusive] = date_filter_bounds($from, $to);
if ($sourceFilter !== 'all' && !in_array($sourceFilter, $validSourceFilters, true)) {
    $sourceFilter = 'all';
}

$where = ['txn_date >= :from_dt AND txn_date < :to_dt'];
$params = [
    'from_dt' => $fromDateTime,
    'to_dt' => $toDateTimeExclusive,
];

if (in_array($directionFilter, ['in', 'out'], true)) {
    $where[] = 'direction = :direction';
    $params['direction'] = $directionFilter;
}

if (in_array($sourceFilter, $validSourceFilters, true)) {
    if ($sourceFilter === 'pos_sales') {
        $where[] = 'source_module = "sales"';
    } elseif ($sourceFilter === 'services') {
        $where[] = 'source_module IN ("printing", "photocopy", "laundry", "business-center")';
    } elseif ($sourceFilter === 'rentals') {
        $where[] = 'source_module IN ("rental", "toga")';
    } elseif ($sourceFilter === 'fishpond') {
        $where[] = 'source_module = "fishpond"';
    } elseif ($sourceFilter === 'inventory_expense') {
        $where[] = '(source_module = "inventory" OR (source_module = "manual" AND manual_category = "inventory_expense"))';
    } else {
        $where[] = 'source_module = "manual" AND manual_category = :manual_category';
        $params['manual_category'] = $sourceFilter;
    }
}

if ($q !== '') {
    $searchParam = prefix_search_param($q);
    $where[] = '(description LIKE :q_description OR or_number LIKE :q_or_number)';
    $params['q_description'] = $searchParam;
    $params['q_or_number'] = $searchParam;
}

$countSql = 'SELECT COUNT(*)
    FROM cash_transactions
    WHERE ' . implode(' AND ', $where);
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$pagination = pagination_meta((int) $countStmt->fetchColumn(), page_param(), 10);

$listSql = 'SELECT txn_date, direction, source_module, manual_category, amount, or_number, description
    FROM (
        SELECT txn_date, direction, source_module, manual_category, amount, or_number, description,
            ROW_NUMBER() OVER (ORDER BY txn_date DESC, id DESC) AS row_num
        FROM cash_transactions
        WHERE ' . implode(' AND ', $where) . '
    ) ranked_cash
    WHERE row_num BETWEEN :first_row AND :last_row
    ORDER BY row_num';
$listStmt = $pdo->prepare($listSql);
foreach ($params as $key => $value) {
    $listStmt->bindValue(':' . ltrim((string) $key, ':'), $value);
}
[$firstRow, $lastRow] = pagination_row_bounds($pagination);
$listStmt->bindValue(':first_row', $firstRow, PDO::PARAM_INT);
$listStmt->bindValue(':last_row', $lastRow, PDO::PARAM_INT);
$listStmt->execute();
$rows = $listStmt->fetchAll();

$summarySql = 'SELECT
    COALESCE(SUM(CASE WHEN direction = "in" THEN amount ELSE 0 END), 0) AS total_in,
    COALESCE(SUM(CASE WHEN direction = "out" THEN amount ELSE 0 END), 0) AS total_out
    FROM cash_transactions
    WHERE ' . implode(' AND ', $where);
$summaryStmt = $pdo->prepare($summarySql);
$summaryStmt->execute($params);
$summary = $summaryStmt->fetch();

$printSql = 'SELECT txn_date, direction, source_module, manual_category, amount, or_number, description
    FROM cash_transactions
    WHERE ' . implode(' AND ', $where) . '
    ORDER BY txn_date ASC, id ASC';
$printStmt = $pdo->prepare($printSql);
foreach ($params as $key => $value) {
    $printStmt->bindValue(':' . ltrim((string) $key, ':'), $value);
}
$printStmt->execute();
$printRows = $printStmt->fetchAll();
$org = organization_profile($pdo);

render_header('Cash Flow', $user);
?>

<link rel="stylesheet" href="<?= h(asset_url('assets/print-styles.css')) ?>">

<section class="print-report screen-print-source" aria-hidden="true">
    <div class="print-report-header">
        <div class="institution-name"><?= h($org['campus_display_name']) ?></div>
        <div class="system-name"><?= h($org['system_name']) ?></div>
        <h1 class="report-title">Cash Flow Report</h1>
    </div>
    <div class="print-metadata">
        <table>
            <tr>
                <td class="print-metadata-label">Period:</td>
                <td class="print-metadata-value"><?= h($from) ?> to <?= h($to) ?></td>
                <td class="print-metadata-label">Generated:</td>
                <td class="print-metadata-value"><?= h(date('Y-m-d H:i:s')) ?></td>
            </tr>
            <tr>
                <td class="print-metadata-label">Cash In:</td>
                <td class="print-metadata-value"><?= h(money((float) $summary['total_in'])) ?></td>
                <td class="print-metadata-label">Expenses:</td>
                <td class="print-metadata-value"><?= h(money((float) $summary['total_out'])) ?></td>
            </tr>
            <tr>
                <td class="print-metadata-label">Net Cash:</td>
                <td class="print-metadata-value"><?= h(money((float) $summary['total_in'] - (float) $summary['total_out'])) ?></td>
                <td class="print-metadata-label">Records:</td>
                <td class="print-metadata-value"><?= h((string) count($printRows)) ?></td>
            </tr>
        </table>
    </div>
    <div class="print-data-section print-section">
        <h3>Cash Transactions</h3>
        <table>
            <thead>
            <tr>
                <th>Date</th>
                <th>Type</th>
                <th>Source</th>
                <th>Reference</th>
                <th>Description</th>
                <th>Amount</th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$printRows): ?>
                <tr><td colspan="6">No cash transactions found.</td></tr>
            <?php endif; ?>
            <?php foreach ($printRows as $row): ?>
                <tr>
                    <td><?= h($row['txn_date']) ?></td>
                    <td><?= h(((string) $row['direction']) === 'in' ? 'Cash In' : 'Expenses') ?></td>
                    <td><?= h(cashflow_source_label($row, $manualCategoryLabels)) ?></td>
                    <td><?= h($row['or_number'] ?: '-') ?></td>
                    <td><?= h($row['description'] ?: '-') ?></td>
                    <td><?= h(money((float) $row['amount'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<style>
    .screen-print-source { display: none; }
    @media print { .screen-print-source { display: block !important; } }
</style>

<dialog id="cash-transaction-modal" class="modal modal-wide app-form-modal">
    <div class="modal-header">
        <div>
            <h3>Add Manual Entry</h3>
        </div>
        <button type="button" class="modal-close" data-close-modal aria-label="Close">Close</button>
    </div>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="add_transaction">

        <div class="form-grid">
            <div>
                <label for="txn_date">Date and Time</label>
                <input id="txn_date" type="datetime-local" name="txn_date" value="<?= date('Y-m-d\\TH:i') ?>" required>
            </div>
            <div>
                <label for="direction">Type</label>
                <select id="direction" name="direction" required>
                    <option value="in">Cash In</option>
                    <option value="out">Expenses</option>
                </select>
            </div>
            <div>
                <label for="manual_category">Category</label>
                <select id="manual_category" name="manual_category" required>
                    <optgroup label="Income">
                        <?php foreach ($manualCategories['in'] as $categoryKey => $categoryLabel): ?>
                            <option value="<?= h($categoryKey) ?>" data-direction="in"><?= h($categoryLabel) ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                    <optgroup label="Expenses">
                        <?php foreach ($manualCategories['out'] as $categoryKey => $categoryLabel): ?>
                            <option value="<?= h($categoryKey) ?>" data-direction="out"><?= h($categoryLabel) ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                </select>
            </div>
            <div>
                <label for="amount">Amount</label>
                <input id="amount" type="number" min="0.01" step="0.01" name="amount" required>
            </div>
            <div>
                <label for="or_number">Reference No.</label>
                <input id="or_number" name="or_number" placeholder="Optional">
            </div>
            <div>
                <label for="description">Description</label>
                <input id="description" name="description" placeholder="Optional">
            </div>
        </div>

        <div class="modal-actions">
            <button type="button" class="btn alt" data-close-modal>Cancel</button>
            <button type="submit">Save Manual Entry</button>
        </div>
    </form>
</dialog>

<section class="table-card data-panel">
    <div class="section-heading">
        <div>
            <h3>Cash Transactions</h3>
        </div>
        <div class="inline-actions">
            <button type="button" class="btn alt print-button" onclick="window.print()">Print Range</button>
            <button type="button" data-open-modal="cash-transaction-modal">Add Manual Entry</button>
        </div>
    </div>
    <div class="metric-grid">
        <div class="rounded-lg border border-brand-100 bg-brand-50 p-4">
            <h3 class="text-sm font-semibold text-slate-700">Cash In</h3>
            <div class="stat"><?= h(money((float) $summary['total_in'])) ?></div>
        </div>
        <div class="rounded-lg border border-brand-100 bg-white p-4">
            <h3 class="text-sm font-semibold text-slate-700">Expenses</h3>
            <div class="stat"><?= h(money((float) $summary['total_out'])) ?></div>
        </div>
        <div class="rounded-lg border border-brand-100 bg-white p-4">
            <h3 class="text-sm font-semibold text-slate-700">Net Cash</h3>
            <div class="stat"><?= h(money((float) $summary['total_in'] - (float) $summary['total_out'])) ?></div>
        </div>
        <div class="rounded-lg border border-brand-100 bg-white p-4">
            <h3 class="text-sm font-semibold text-slate-700">Total Transactions</h3>
            <div class="stat"><?= h((string) $pagination['total_rows']) ?></div>
        </div>
    </div>
    <form method="get" class="form-grid data-panel-filters">
        <div>
            <label for="from">From</label>
            <input id="from" type="date" name="from" value="<?= h($from) ?>">
        </div>
        <div>
            <label for="to">To</label>
            <input id="to" type="date" name="to" value="<?= h($to) ?>">
        </div>
        <div>
                <label for="direction_filter">Type</label>
            <select id="direction_filter" name="direction">
                <option value="all" <?= $directionFilter === 'all' ? 'selected' : '' ?>>All</option>
                <option value="in" <?= $directionFilter === 'in' ? 'selected' : '' ?>>Cash In</option>
                <option value="out" <?= $directionFilter === 'out' ? 'selected' : '' ?>>Expenses</option>
            </select>
        </div>
        <div>
            <label for="source_filter">Source</label>
            <select id="source_filter" name="source_module">
                <option value="all" <?= $sourceFilter === 'all' ? 'selected' : '' ?>>All</option>
                <optgroup label="System Generated">
                    <?php foreach ($cashSourceGroups['system'] as $sourceKey => $sourceLabel): ?>
                        <option value="<?= h($sourceKey) ?>" <?= $sourceFilter === $sourceKey ? 'selected' : '' ?>><?= h($sourceLabel) ?></option>
                    <?php endforeach; ?>
                </optgroup>
                <optgroup label="Manual Entries">
                    <?php foreach ($cashSourceGroups['manual'] as $sourceKey => $sourceLabel): ?>
                        <option value="<?= h($sourceKey) ?>" <?= $sourceFilter === $sourceKey ? 'selected' : '' ?>><?= h($sourceLabel) ?></option>
                    <?php endforeach; ?>
                </optgroup>
            </select>
        </div>
        <div>
            <label for="q">Search</label>
            <input id="q" name="q" value="<?= h($q) ?>" placeholder="OR or description">
        </div>
        <div class="filter-actions">
            <button type="submit">Apply</button>
            <a class="btn alt" href="cashflow.php">Reset</a>
        </div>
    </form>
    <div class="table-wrap" data-no-table-enhance>
        <table>
            <thead>
            <tr>
                <th>Date and Time</th>
                <th>Type</th>
                <th>Source</th>
                <th>Amount</th>
                <th>Reference No.</th>
                <th>Description</th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr>
                    <td colspan="6"><?php render_empty_state('No cash transactions found.', 'Add a manual entry or adjust your filters.', 'Add Manual Entry', 'cash-transaction-modal'); ?></td>
                </tr>
            <?php endif; ?>
            <?php foreach ($rows as $row): ?>
                <?php
                $isCashIn = $row['direction'] === 'in';
                $amountLabel = ($isCashIn ? '+ ' : '- ') . money((float) $row['amount']);
                ?>
                <tr>
                    <td><?= h($row['txn_date']) ?></td>
                    <td><span class="status-pill <?= $isCashIn ? 'cash-in' : 'cash-out' ?>"><?= $isCashIn ? 'Cash In' : 'Expenses' ?></span></td>
                    <td><?= h(cashflow_source_label($row, $manualCategoryLabels)) ?></td>
                    <td class="font-semibold <?= $isCashIn ? 'text-emerald-700' : 'text-red-700' ?>"><?= h($amountLabel) ?></td>
                    <td><?= h($row['or_number']) ?></td>
                    <td><?= h($row['description']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php render_pagination($pagination); ?>
</section>

<script>
(() => {
    const directionSelect = document.getElementById('direction');
    const categorySelect = document.getElementById('manual_category');
    if (!directionSelect || !categorySelect) {
        return;
    }

    function syncManualCategories() {
        const direction = directionSelect.value;
        let firstVisible = null;
        Array.from(categorySelect.options).forEach((option) => {
            const matches = option.dataset.direction === direction;
            option.hidden = !matches;
            option.disabled = !matches;
            if (matches && firstVisible === null) {
                firstVisible = option;
            }
        });
        if (firstVisible && (!categorySelect.selectedOptions[0] || categorySelect.selectedOptions[0].disabled)) {
            categorySelect.value = firstVisible.value;
        }
    }

    directionSelect.addEventListener('change', syncManualCategories);
    syncManualCategories();
})();
</script>

<?php render_footer();
