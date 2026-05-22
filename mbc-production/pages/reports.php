<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';

$user = require_login($pdo);

$period = (string) ($_GET['period'] ?? 'daily');
$referenceDate = trim((string) ($_GET['reference_date'] ?? date('Y-m-d')));
$reportTab = (string) ($_GET['tab'] ?? 'sales');
$itemQ = trim((string) ($_GET['item_q'] ?? ''));
$sortBy = (string) ($_GET['sort'] ?? 'default');
$order = strtolower((string) ($_GET['order'] ?? 'desc'));

$validPeriods = ['daily', 'weekly', 'monthly', 'annual'];
if (!in_array($period, $validPeriods, true)) {
    $period = 'daily';
}
if (!in_array($reportTab, ['sales', 'cash', 'projects', 'inventory'], true)) {
    $reportTab = 'sales';
}
if (!in_array($order, ['asc', 'desc'], true)) {
    $order = 'desc';
}

[$start, $end] = period_bounds($period, $referenceDate);
$startDateTime = $start->format('Y-m-d H:i:s');
$endDateTime = $end->format('Y-m-d H:i:s');

$sales = false;
try {
    $salesStmt = $pdo->prepare('CALL sp_sales_summary_by_period(:start_date, :end_date)');
    $salesStmt->execute([
        'start_date' => $startDateTime,
        'end_date' => (clone $end)->modify('+1 second')->format('Y-m-d H:i:s'),
    ]);
    $sales = $salesStmt->fetch();
    $salesStmt->closeCursor();
} catch (Throwable $e) {
    log_system_issue($pdo, 'warning', 'Sales summary procedure unavailable; using fallback query.', ['error' => $e->getMessage()], $user);
}

if (!$sales) {
    $salesStmt = $pdo->prepare('SELECT
        COUNT(DISTINCT sh.id) AS total_sales,
        COALESCE(SUM(si.total_amount), 0) AS revenue,
        COALESCE(SUM(si.total_cost), 0) AS cost,
        COALESCE(SUM(si.profit), 0) AS profit
        FROM sale_headers sh
        INNER JOIN sale_items si ON si.sale_header_id = sh.id
        WHERE sh.sale_date BETWEEN :start_dt AND :end_dt
            AND sh.payment_status <> "void"');
    $salesStmt->execute([
        'start_dt' => $startDateTime,
        'end_dt' => $endDateTime,
    ]);
    $sales = $salesStmt->fetch();
}

$cash = false;
try {
    $cashStmt = $pdo->prepare('CALL sp_cash_summary_by_period(:start_date, :end_date)');
    $cashStmt->execute([
        'start_date' => $startDateTime,
        'end_date' => (clone $end)->modify('+1 second')->format('Y-m-d H:i:s'),
    ]);
    $cash = $cashStmt->fetch();
    $cashStmt->closeCursor();
} catch (Throwable $e) {
    log_system_issue($pdo, 'warning', 'Cash summary procedure unavailable; using fallback query.', ['error' => $e->getMessage()], $user);
}

if (!$cash) {
    $cashStmt = $pdo->prepare('SELECT
        COALESCE(SUM(CASE WHEN direction = "in" THEN amount ELSE 0 END), 0) AS cash_in,
        COALESCE(SUM(CASE WHEN direction = "out" THEN amount ELSE 0 END), 0) AS cash_out
        FROM cash_transactions
        WHERE txn_date BETWEEN :start_dt AND :end_dt');
    $cashStmt->execute([
        'start_dt' => $startDateTime,
        'end_dt' => $endDateTime,
    ]);
    $cash = $cashStmt->fetch();
}

$projectStmt = $pdo->prepare('SELECT
    COALESCE(SUM(CASE WHEN entry_type IN ("income", "payment", "harvest") THEN amount ELSE 0 END), 0) AS project_income,
    COALESCE(SUM(CASE WHEN entry_type = "expense" THEN amount ELSE 0 END), 0) AS project_expense
    FROM project_entries
    WHERE entry_datetime BETWEEN :start_dt AND :end_dt');
$projectStmt->execute([
    'start_dt' => $startDateTime,
    'end_dt' => $endDateTime,
]);
$projects = $projectStmt->fetch();

$topProductsWhere = ['sh.sale_date BETWEEN :start_dt AND :end_dt', 'sh.payment_status <> "void"'];
$topProductsParams = [
    'start_dt' => $startDateTime,
    'end_dt' => $endDateTime,
];
if ($itemQ !== '') {
    $topProductsWhere[] = '(p.name LIKE :item_q OR p.sku LIKE :item_q)';
    $topProductsParams['item_q'] = prefix_search_param($itemQ);
}

$topProductsStmt = $pdo->prepare('SELECT p.name, p.sku, p.type, COALESCE(SUM(si.quantity), 0) AS sold_qty, COALESCE(SUM(si.total_amount), 0) AS revenue, COALESCE(SUM(si.profit), 0) AS profit
    FROM sale_headers sh
    INNER JOIN sale_items si ON si.sale_header_id = sh.id
    INNER JOIN products p ON p.id = si.product_id
    WHERE ' . implode(' AND ', $topProductsWhere) . '
    GROUP BY p.id, p.name, p.sku, p.type
    ORDER BY profit DESC');
$topProductsStmt->execute($topProductsParams);
$topProducts = $topProductsStmt->fetchAll();

$categoryStmt = $pdo->prepare('SELECT pc.name,
    COALESCE(SUM(CASE WHEN pe.entry_type IN ("income", "payment", "harvest") THEN pe.amount ELSE 0 END), 0) AS income,
    COALESCE(SUM(CASE WHEN pe.entry_type = "expense" THEN pe.amount ELSE 0 END), 0) AS expense
    FROM project_categories pc
    INNER JOIN project_entries pe
        ON pe.category_id = pc.id
        AND pe.entry_datetime BETWEEN :start_dt AND :end_dt
    WHERE pc.is_active = 1
    GROUP BY pc.id, pc.name
    ORDER BY pc.name');
$categoryStmt->execute([
    'start_dt' => $startDateTime,
    'end_dt' => $endDateTime,
]);
$categoryRows = $categoryStmt->fetchAll();

$inventoryLowStock = (int) $pdo->query("SELECT COUNT(*) FROM products WHERE type = 'item' AND is_active = 1 AND stock_qty <= low_stock_threshold")->fetchColumn();
$overdueRenewals = (int) $pdo->query('SELECT COUNT(*) FROM v_overdue_project_accounts')->fetchColumn();
$inventoryRows = $pdo->query('SELECT name, sku, product_group, category, category_name, type, stock_qty, low_stock_threshold, selling_price,
        CASE WHEN type = "item" THEN stock_qty * selling_price ELSE 0 END AS stock_value
    FROM products
    WHERE is_active = 1 AND type = "item"
    ORDER BY FIELD(product_group, "product", "igp", "service"), name
    LIMIT 100')->fetchAll();

$logbookCountStmt = $pdo->prepare('SELECT COUNT(*) FROM office_logbook WHERE log_date BETWEEN :start_date AND :end_date');
$logbookCountStmt->execute([
    'start_date' => $start->format('Y-m-d'),
    'end_date' => $end->format('Y-m-d'),
]);
$logbookCount = (int) $logbookCountStmt->fetchColumn();

$togaSummaryStmt = $pdo->prepare('SELECT
    COALESCE(SUM(CASE WHEN COALESCE(status_meta.meta_value, CASE WHEN pa.status = "active" THEN "released" ELSE "returned" END) = "released" THEN 1 ELSE 0 END), 0) AS released,
    COALESCE(SUM(CASE WHEN COALESCE(status_meta.meta_value, CASE WHEN pa.status = "active" THEN "released" ELSE "returned" END) = "returned" THEN 1 ELSE 0 END), 0) AS returned_count,
    COALESCE(SUM(CASE WHEN COALESCE(status_meta.meta_value, CASE WHEN pa.status = "active" THEN "released" ELSE "returned" END) = "forfeited" THEN 1 ELSE 0 END), 0) AS forfeited,
    COALESCE(SUM(pa.expected_amount), 0) AS collected
    FROM project_accounts pa
    INNER JOIN project_categories pc ON pc.id = pa.category_id
    LEFT JOIN project_account_meta status_meta
        ON status_meta.account_id = pa.id AND status_meta.meta_key = "toga_status"
    WHERE pc.slug = "toga"
        AND pa.start_date BETWEEN :start_date AND :end_date');
$togaSummaryStmt->execute([
    'start_date' => $start->format('Y-m-d'),
    'end_date' => $end->format('Y-m-d'),
]);
$toga = $togaSummaryStmt->fetch();

$cashByDateStmt = $pdo->prepare('SELECT DATE(txn_date) AS report_date,
    COALESCE(SUM(CASE WHEN direction = "in" THEN amount ELSE 0 END), 0) AS cash_in,
    COALESCE(SUM(CASE WHEN direction = "out" THEN amount ELSE 0 END), 0) AS cash_out
    FROM cash_transactions
    WHERE txn_date BETWEEN :start_dt AND :end_dt
    GROUP BY DATE(txn_date)
    ORDER BY DATE(txn_date) ASC');
$cashByDateStmt->execute([
    'start_dt' => $startDateTime,
    'end_dt' => $endDateTime,
]);
$cashByDate = $cashByDateStmt->fetchAll();

if ($itemQ !== '') {
    $needle = strtolower($itemQ);
    $cashByDate = array_values(array_filter($cashByDate, static fn(array $row): bool =>
        str_contains(strtolower((string) ($row['report_date'] ?? '')), $needle)
    ));
    $categoryRows = array_values(array_filter($categoryRows, static fn(array $row): bool =>
        str_contains(strtolower((string) ($row['name'] ?? '')), $needle)
    ));
    $inventoryRows = array_values(array_filter($inventoryRows, static fn(array $row): bool =>
        str_contains(strtolower(product_display_name($row)), $needle)
        || str_contains(strtolower((string) ($row['sku'] ?? '')), $needle)
        || str_contains(strtolower(product_category_label((string) ($row['category'] ?? ''), $row['category_name'] ?? null)), $needle)
    ));
}

$reportTabs = [
    'sales' => 'Sales',
    'cash' => 'Cash Flow',
    'projects' => 'Projects',
    'inventory' => 'Inventory',
];
$reportSortOptions = [
    'default' => 'Default',
    'name' => $reportTab === 'cash' ? 'Date' : ($reportTab === 'projects' ? 'Category' : 'Name'),
    'amount' => match ($reportTab) {
        'cash' => 'Net Cash',
        'projects' => 'Net Income',
        'inventory' => 'Stock Value',
        default => 'Revenue',
    },
    'profit' => $reportTab === 'projects' ? 'Net Income' : 'Profit',
    'quantity' => $reportTab === 'inventory' ? 'Stock' : 'Quantity',
];
if (!array_key_exists($sortBy, $reportSortOptions)) {
    $sortBy = 'default';
}
$sortDirection = $order === 'asc' ? 1 : -1;
$sortValue = static function (array $row, string $tab, string $sort): string|float|int {
    return match ($sort) {
        'name' => match ($tab) {
            'cash' => (string) ($row['report_date'] ?? ''),
            'projects' => (string) ($row['name'] ?? ''),
            default => product_display_name($row),
        },
        'amount' => match ($tab) {
            'cash' => (float) ($row['cash_in'] ?? 0) - (float) ($row['cash_out'] ?? 0),
            'projects' => (float) ($row['income'] ?? 0) - (float) ($row['expense'] ?? 0),
            'inventory' => (float) ($row['stock_value'] ?? 0),
            default => (float) ($row['revenue'] ?? 0),
        },
        'profit' => match ($tab) {
            'projects' => (float) ($row['income'] ?? 0) - (float) ($row['expense'] ?? 0),
            default => (float) ($row['profit'] ?? 0),
        },
        'quantity' => match ($tab) {
            'inventory' => (int) ($row['stock_qty'] ?? 0),
            default => (float) ($row['sold_qty'] ?? 0),
        },
        default => 0,
    };
};
$applyReportSort = static function (array &$rows, string $tab) use ($sortBy, $sortDirection, $sortValue): void {
    if ($sortBy === 'default') {
        return;
    }
    usort($rows, static function (array $a, array $b) use ($tab, $sortBy, $sortDirection, $sortValue): int {
        $left = $sortValue($a, $tab, $sortBy);
        $right = $sortValue($b, $tab, $sortBy);
        if (is_string($left) || is_string($right)) {
            return $sortDirection * strcasecmp((string) $left, (string) $right);
        }
        return $sortDirection * ($left <=> $right);
    });
};
$applyReportSort($topProducts, 'sales');
$applyReportSort($cashByDate, 'cash');
$applyReportSort($categoryRows, 'projects');
$applyReportSort($inventoryRows, 'inventory');
$printRowLimit = 10;
$printTopProducts = array_slice($topProducts, 0, $printRowLimit);
$printCashByDate = array_slice($cashByDate, 0, $printRowLimit);
$printCategoryRows = array_slice($categoryRows, 0, $printRowLimit);
$printInventoryRows = array_slice($inventoryRows, 0, $printRowLimit);
$printRowsTotal = 0;
$printRowsLabel = 'rows';
if ($reportTab === 'sales') {
    $printRowsTotal = count($topProducts);
    $printRowsLabel = 'item profit rows';
} elseif ($reportTab === 'cash') {
    $printRowsTotal = count($cashByDate);
    $printRowsLabel = 'cash trend rows';
} elseif ($reportTab === 'projects') {
    $printRowsTotal = count($categoryRows);
    $printRowsLabel = 'project category rows';
} elseif ($reportTab === 'inventory') {
    $printRowsTotal = count($inventoryRows);
    $printRowsLabel = 'inventory rows';
}
$printRowsShown = min($printRowLimit, $printRowsTotal);
$printRowsNote = $printRowsTotal > $printRowsShown
    ? 'Showing the first ' . $printRowsShown . ' of ' . $printRowsTotal . ' ' . $printRowsLabel . ' to keep this report on one page.'
    : 'Showing ' . $printRowsShown . ' ' . $printRowsLabel . ' for this report.';
$org = organization_profile($pdo);
$preparedByDefault = app_setting($pdo, 'reports.prepared_by_default', '');
$reviewedByDefault = app_setting($pdo, 'reports.reviewed_by_default', 'Department Head / Supervisor');
$approvedByDefault = app_setting($pdo, 'reports.approved_by_default', 'System Administrator');
audit_log($pdo, $user, 'view_report', 'reports', $period, null, [
    'reference_date' => $referenceDate,
    'start' => $startDateTime,
    'end' => $endDateTime,
]);

render_header('Reports', $user);
?>

<link rel="stylesheet" href="<?= h(asset_url('assets/print-styles.css')) ?>">

<!-- PROFESSIONAL PRINT REPORT TEMPLATE (A4 Format) -->
<section class="print-report" data-no-table-enhance aria-hidden="true" hidden>
    <div class="print-report-header">
        <div class="institution-name"><?= h($org['campus_display_name']) ?></div>
        <div class="system-name"><?= h($org['system_name']) ?></div>
        <h1 class="report-title"><?= h(ucfirst($period)) ?> <?= h($reportTabs[$reportTab] ?? 'Report') ?></h1>
    </div>

    <!-- METADATA SECTION -->
    <div class="print-metadata">
        <table>
            <tr>
                <td class="print-metadata-label">Report Period:</td>
                <td class="print-metadata-value"><?= h($start->format('Y-m-d')) ?> to <?= h($end->format('Y-m-d')) ?></td>
                <td class="print-metadata-label">Report Type:</td>
                <td class="print-metadata-value"><?= h($reportTabs[$reportTab] ?? 'Report') ?></td>
            </tr>
            <tr>
                <td class="print-metadata-label">Generated Date:</td>
                <td class="print-metadata-value"><?= h(date('Y-m-d H:i:s')) ?></td>
                <td class="print-metadata-label">Generated By:</td>
                <td class="print-metadata-value"><?= h((string) ($user['full_name'] ?? $user['username'] ?? '')) ?></td>
            </tr>
        </table>
    </div>

    <!-- SUMMARY TOTALS SECTION -->
    <div class="print-summary print-section">
        <h3>Summary Totals</h3>
        <div class="print-summary-grid">
            <div class="print-summary-item">
                <div class="print-summary-item-label">Revenue</div>
                <div class="print-summary-item-value"><?= h(money((float) $sales['revenue'])) ?></div>
            </div>
            <div class="print-summary-item">
                <div class="print-summary-item-label">Profit</div>
                <div class="print-summary-item-value"><?= h(money((float) $sales['profit'])) ?></div>
            </div>
            <div class="print-summary-item">
                <div class="print-summary-item-label">Net Cash</div>
                <div class="print-summary-item-value"><?= h(money((float) $cash['cash_in'] - (float) $cash['cash_out'])) ?></div>
            </div>
            <div class="print-summary-item">
                <div class="print-summary-item-label">Project Net</div>
                <div class="print-summary-item-value"><?= h(money((float) $projects['project_income'] - (float) $projects['project_expense'])) ?></div>
            </div>
        </div>
    </div>

    <div class="print-data-section print-section">
        <div class="print-section-title">
            <h3>Report Details</h3>
            <span><?= h($printRowsNote) ?></span>
        </div>
        <?php if ($reportTab === 'sales'): ?>
            <table>
                <thead>
                <tr>
                    <th>Item</th>
                    <th>Type</th>
                    <th>Sold Qty</th>
                    <th>Revenue</th>
                    <th>Profit</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$printTopProducts): ?>
                    <tr><td colspan="5">No sales data in this period.</td></tr>
                <?php endif; ?>
                <?php foreach ($printTopProducts as $row): ?>
                    <tr>
                        <td><?= h(product_display_name($row)) ?></td>
                        <td><?= h(product_type_label((string) $row['type'])) ?></td>
                        <td><?= h((string) $row['sold_qty']) ?></td>
                        <td><?= h(money((float) $row['revenue'])) ?></td>
                        <td><?= h(money((float) $row['profit'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php elseif ($reportTab === 'cash'): ?>
            <table>
                <thead>
                <tr>
                    <th>Date</th>
                    <th>Cash In</th>
                    <th>Expenses</th>
                    <th>Net Cash</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$printCashByDate): ?>
                    <tr><td colspan="4">No cash transactions in this period.</td></tr>
                <?php endif; ?>
                <?php foreach ($printCashByDate as $row): ?>
                    <tr>
                        <td><?= h($row['report_date']) ?></td>
                        <td><?= h(money((float) $row['cash_in'])) ?></td>
                        <td><?= h(money((float) $row['cash_out'])) ?></td>
                        <td><?= h(money((float) $row['cash_in'] - (float) $row['cash_out'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php elseif ($reportTab === 'projects'): ?>
            <table>
                <thead>
                <tr>
                    <th>Project Category</th>
                    <th>Income</th>
                    <th>Expense</th>
                    <th>Net Income</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$printCategoryRows): ?>
                    <tr><td colspan="4">No project records in this period.</td></tr>
                <?php endif; ?>
                <?php foreach ($printCategoryRows as $row): ?>
                    <tr>
                        <td><?= h($row['name']) ?></td>
                        <td><?= h(money((float) $row['income'])) ?></td>
                        <td><?= h(money((float) $row['expense'])) ?></td>
                        <td><?= h(money((float) $row['income'] - (float) $row['expense'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <table>
                <thead>
                <tr>
                    <th>Item</th>
                    <th>Category</th>
                    <th>Stock</th>
                    <th>Status</th>
                    <th>Stock Value</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$printInventoryRows): ?>
                    <tr><td colspan="5">No inventory records found.</td></tr>
                <?php endif; ?>
                <?php foreach ($printInventoryRows as $row): ?>
                    <?php $isLowStock = (int) $row['stock_qty'] <= (int) $row['low_stock_threshold']; ?>
                    <tr>
                        <td><?= h(product_display_name($row)) ?></td>
                        <td><?= h(product_category_label((string) $row['category'], $row['category_name'] ?? null)) ?></td>
                        <td><?= h((string) $row['stock_qty']) ?></td>
                        <td><?= $isLowStock ? 'Low Stock' : 'In Stock' ?></td>
                        <td><?= h(money((float) $row['stock_value'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <div class="print-notes print-section">
        <div class="note-row">
            <strong>Data Basis:</strong>
            <span>All figures are based on records entered into the system as of the report generation date.</span>
        </div>
    </div>

    <div class="print-signatures print-section">
        <h4>Approval and Signatures</h4>
        <div class="signature-grid">
            <div class="signature-block">
                <div class="signature-line"></div>
                <div class="signature-title">Prepared By</div>
                <div class="signature-subtitle"><?= h($preparedByDefault !== '' ? $preparedByDefault : (string) ($user['full_name'] ?? $user['username'] ?? '')) ?></div>
                <div class="signature-subtitle"><?= h((string) $user['role']) ?></div>
                <div class="signature-date">Date: _______________</div>
            </div>
            <div class="signature-block">
                <div class="signature-line"></div>
                <div class="signature-title">Reviewed By</div>
                <div class="signature-subtitle"><?= h($reviewedByDefault) ?></div>
                <div class="signature-date">Date: _______________</div>
            </div>
            <div class="signature-block">
                <div class="signature-line"></div>
                <div class="signature-title">Approved By</div>
                <div class="signature-subtitle"><?= h($approvedByDefault) ?></div>
                <div class="signature-date">Date: _______________</div>
            </div>
        </div>
    </div>

    <div class="print-footer">
        Generated on <?= h(date('Y-m-d H:i:s')) ?> by <?= h((string) ($user['full_name'] ?? $user['username'] ?? APP_NAME)) ?>
    </div>
</section>

<?php
$reportBaseQuery = [
    'period' => $period,
    'reference_date' => $referenceDate,
    'item_q' => $itemQ,
    'sort' => $sortBy,
    'order' => $order,
];
$reportBaseQuery = array_filter($reportBaseQuery, static fn($value): bool => $value !== '');
$reportResetQuery = http_build_query(['tab' => $reportTab]);
?>

<!-- WEB INTERFACE SECTION (NOT PRINTED) -->
<nav class="tabs" aria-label="Report sections">
    <?php foreach ($reportTabs as $tabKey => $tabLabel): ?>
        <a class="tab-link <?= $reportTab === $tabKey ? 'active' : '' ?>" href="reports.php?<?= h(http_build_query(array_merge($reportBaseQuery, ['tab' => $tabKey]))) ?>"><?= h($tabLabel) ?></a>
    <?php endforeach; ?>
</nav>

<section class="table-card data-panel mb-4">
    <form method="get" class="data-panel-filters grid gap-3 md:grid-cols-2 xl:grid-cols-[minmax(130px,0.7fr)_minmax(150px,0.75fr)_minmax(220px,1.2fr)_minmax(150px,0.8fr)_minmax(130px,0.65fr)_auto_auto_auto] xl:items-end">
        <input type="hidden" name="tab" value="<?= h($reportTab) ?>">
        <div>
            <label for="period">Period</label>
            <select id="period" name="period">
                <option value="daily" <?= $period === 'daily' ? 'selected' : '' ?>>Daily</option>
                <option value="weekly" <?= $period === 'weekly' ? 'selected' : '' ?>>Weekly</option>
                <option value="monthly" <?= $period === 'monthly' ? 'selected' : '' ?>>Monthly</option>
                <option value="annual" <?= $period === 'annual' ? 'selected' : '' ?>>Annual</option>
            </select>
        </div>
        <div>
            <label for="reference_date">Reference Date</label>
            <input id="reference_date" type="date" name="reference_date" value="<?= h($referenceDate) ?>" required>
        </div>
        <div>
            <label for="item_q">Search</label>
            <input id="item_q" name="item_q" value="<?= h($itemQ) ?>" placeholder="Search current report">
        </div>
        <div>
            <label for="sort">Sort By</label>
            <select id="sort" name="sort">
                <?php foreach ($reportSortOptions as $value => $label): ?>
                    <option value="<?= h($value) ?>" <?= $sortBy === $value ? 'selected' : '' ?>><?= h($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="order">Order</label>
            <select id="order" name="order">
                <option value="desc" <?= $order === 'desc' ? 'selected' : '' ?>>Descending</option>
                <option value="asc" <?= $order === 'asc' ? 'selected' : '' ?>>Ascending</option>
            </select>
        </div>
        <div class="filter-actions">
            <button type="button" class="btn print-button" onclick="openPrintReport()">Print Report</button>
            <button type="submit">Apply</button>
            <a class="btn alt" href="reports.php?<?= h($reportResetQuery) ?>">Reset</a>
        </div>
    </form>
</section>

<div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4 mb-4">
    <section class="table-card p-4">
        <div class="flex items-center justify-between gap-3">
            <h3 class="text-sm font-bold text-slate-700">Revenue</h3>
        </div>
        <div class="mt-2 text-2xl font-bold text-slate-950"><?= h(money((float) $sales['revenue'])) ?></div>
        <p class="mt-1 text-sm text-slate-500">Transactions: <?= h((string) $sales['total_sales']) ?></p>
    </section>
    <section class="table-card p-4">
        <div class="flex items-center justify-between gap-3">
            <h3 class="text-sm font-bold text-slate-700">Profit</h3>
        </div>
        <div class="mt-2 text-2xl font-bold text-slate-950"><?= h(money((float) $sales['profit'])) ?></div>
        <p class="mt-1 text-sm text-slate-500">Cost: <?= h(money((float) $sales['cost'])) ?></p>
    </section>
    <section class="table-card p-4">
        <div class="flex items-center justify-between gap-3">
            <h3 class="text-sm font-bold text-slate-700">Net Cash</h3>
        </div>
        <div class="mt-2 text-2xl font-bold text-slate-950"><?= h(money((float) $cash['cash_in'] - (float) $cash['cash_out'])) ?></div>
        <p class="mt-1 text-sm text-slate-500">In: <?= h(money((float) $cash['cash_in'])) ?> | Out: <?= h(money((float) $cash['cash_out'])) ?></p>
    </section>
    <section class="table-card p-4">
        <div class="flex items-center justify-between gap-3">
            <h3 class="text-sm font-bold text-slate-700">Project Net</h3>
        </div>
        <div class="mt-2 text-2xl font-bold text-slate-950"><?= h(money((float) $projects['project_income'] - (float) $projects['project_expense'])) ?></div>
        <p class="mt-1 text-sm text-slate-500">Income: <?= h(money((float) $projects['project_income'])) ?> | Expense: <?= h(money((float) $projects['project_expense'])) ?></p>
    </section>
</div>

<?php if ($reportTab === 'sales'): ?>
<section class="table-card data-panel report-table">
    <div class="section-heading">
        <div>
            <h3 class="text-base font-bold text-slate-950">Item Profit</h3>
            <p class="text-sm text-slate-500">Revenue and profit by sold item.</p>
        </div>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th>Item</th>
                <th>Type</th>
                <th>Sold Qty</th>
                <th>Revenue</th>
                <th>Profit</th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$topProducts): ?>
                <tr>
                    <td colspan="5" class="muted">No sales data in this period.</td>
                </tr>
            <?php endif; ?>
            <?php foreach ($topProducts as $row): ?>
                <tr>
                    <td>
                        <div class="font-semibold text-slate-950"><?= h(product_display_name($row)) ?></div>
                    </td>
                    <td><span class="status-pill <?= $row['type'] === 'service' ? 'pending' : 'active' ?>"><?= h(product_type_label((string) $row['type'])) ?></span></td>
                    <td><?= h((string) $row['sold_qty']) ?></td>
                    <td><?= h(money((float) $row['revenue'])) ?></td>
                    <td><?= h(money((float) $row['profit'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>

<?php if ($reportTab === 'projects'): ?>
<section class="table-card data-panel report-table">
    <div class="section-heading">
        <div>
            <h3 class="text-base font-bold text-slate-950">Project Category Performance</h3>
            <p class="text-sm text-slate-500">Income, expenses, and net results by project category.</p>
        </div>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th>Category</th>
                <th>Income</th>
                <th>Expense</th>
                <th>Net</th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$categoryRows): ?>
                <tr>
                    <td colspan="4" class="muted">No project records in this period.</td>
                </tr>
            <?php endif; ?>
            <?php foreach ($categoryRows as $row): ?>
                <tr>
                    <td><?= h($row['name']) ?></td>
                    <td><?= h(money((float) $row['income'])) ?></td>
                    <td><?= h(money((float) $row['expense'])) ?></td>
                    <td><?= h(money((float) $row['income'] - (float) $row['expense'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>

<?php if ($reportTab === 'cash'): ?>
<section class="table-card data-panel report-table">
    <div class="section-heading">
        <div>
            <h3 class="text-base font-bold text-slate-950">Cash Trend by Date</h3>
            <p class="text-sm text-slate-500">Daily cash in, expenses, and net cash.</p>
        </div>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th>Date</th>
                <th>Cash In</th>
                <th>Expenses</th>
                <th>Net</th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$cashByDate): ?>
                <tr>
                    <td colspan="4" class="muted">No cash transactions in this period.</td>
                </tr>
            <?php endif; ?>
            <?php foreach ($cashByDate as $row): ?>
                <tr>
                    <td><?= h($row['report_date']) ?></td>
                    <td><?= h(money((float) $row['cash_in'])) ?></td>
                    <td><?= h(money((float) $row['cash_out'])) ?></td>
                    <td><?= h(money((float) $row['cash_in'] - (float) $row['cash_out'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>

<?php if ($reportTab === 'inventory'): ?>
<section class="table-card data-panel report-table">
    <div class="section-heading">
        <div>
            <h3 class="text-base font-bold text-slate-950">Inventory Status</h3>
            <p class="text-sm text-slate-500">Stock status and value for active inventory items.</p>
        </div>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th>Item</th>
                <th>Type</th>
                <th>Category</th>
                <th>Stock</th>
                <th>Status</th>
                <th>Stock Value</th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$inventoryRows): ?>
                <tr>
                    <td colspan="6" class="muted">No inventory records found.</td>
                </tr>
            <?php endif; ?>
            <?php foreach ($inventoryRows as $row): ?>
                <?php
                $isLowStock = (int) $row['stock_qty'] <= (int) $row['low_stock_threshold'];
                ?>
                <tr>
                    <td>
                        <div class="font-semibold text-slate-950"><?= h(product_display_name($row)) ?></div>
                    </td>
                    <td><span class="status-pill active">Product</span></td>
                    <td><?= h(product_category_label((string) $row['category'], $row['category_name'] ?? null)) ?></td>
                    <td><?= h((string) $row['stock_qty']) ?></td>
                    <td>
                        <?php if ($isLowStock): ?>
                            <span class="status-pill low-stock">Low Stock</span>
                        <?php else: ?>
                            <span class="status-pill active">In Stock</span>
                        <?php endif; ?>
                    </td>
                    <td><?= h(money((float) $row['stock_value'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>

<script>
function reportPrintStyles() {
    return `
        @page { size: A4 portrait; margin: 8mm; }
        * { box-sizing: border-box; color: #000; box-shadow: none; text-shadow: none; }
        html, body { margin: 0; padding: 0; background: #fff; font-family: Arial, "Segoe UI", sans-serif; font-size: 8.4pt; line-height: 1.18; }
        .print-report { display: block !important; width: 100%; margin: 0; padding: 0; }
        .print-report-header { text-align: center; margin: 0 0 3mm; padding: 0 0 2mm; border-bottom: 1px solid #000; }
        .institution-name { font-size: 10.5pt; font-weight: 700; line-height: 1.15; }
        .system-name { margin-top: .5mm; font-size: 8.8pt; font-weight: 600; line-height: 1.15; }
        .report-title { margin: 1.5mm 0 0; font-size: 12pt; font-weight: 700; line-height: 1.15; text-align: center; }
        .print-section, .print-metadata, .print-summary, .print-data-section, .print-notes, .print-signatures { margin: 0 0 2.5mm; page-break-inside: avoid; break-inside: avoid; }
        .print-data-section { page-break-inside: auto; break-inside: auto; }
        h3, h4 { margin: 0 0 1.5mm; font-size: 9.2pt; font-weight: 700; line-height: 1.1; }
        .print-section-title { display: flex; align-items: flex-end; justify-content: space-between; gap: 4mm; margin-bottom: 1.5mm; padding-bottom: 1mm; border-bottom: 1px solid #000; }
        .print-section-title h3 { margin: 0; border: 0; padding: 0; }
        .print-section-title span { max-width: 58%; text-align: right; font-size: 7.2pt; font-weight: 600; line-height: 1.15; }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; border: 1px solid #000; }
        th, td { border: 1px solid #000; padding: .95mm; vertical-align: top; text-align: left; white-space: normal; word-break: break-word; overflow-wrap: anywhere; }
        th { font-weight: 700; }
        .print-metadata td { font-size: 7.6pt; }
        .print-metadata-label { width: 18%; font-weight: 700; }
        .print-metadata-value { width: 32%; }
        .print-summary h3, .print-signatures h4 { padding-bottom: 1mm; border-bottom: 1px solid #000; }
        .print-summary-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); border: 1px solid #000; }
        .print-summary-item { min-width: 0; padding: 1.2mm; border-right: 1px solid #000; page-break-inside: avoid; break-inside: avoid; }
        .print-summary-item:last-child { border-right: 0; }
        .print-summary-item-label { font-size: 7.2pt; font-weight: 700; }
        .print-summary-item-value { margin-top: .5mm; font-size: 8.8pt; font-weight: 700; }
        .print-data-section table { font-size: 7.3pt; page-break-inside: auto; break-inside: auto; }
        .print-data-section thead { display: table-header-group; }
        .print-data-section tr { page-break-inside: avoid; break-inside: avoid; }
        .note-row { display: grid; grid-template-columns: 24mm minmax(0, 1fr); gap: 2mm; margin-bottom: .6mm; font-size: 7pt; }
        .note-row strong { font-weight: 700; }
        .signature-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 5mm; }
        .signature-block { min-width: 0; page-break-inside: avoid; break-inside: avoid; }
        .signature-line { height: 7mm; border-bottom: 1px solid #000; margin-bottom: 1mm; }
        .signature-title { font-size: 7.8pt; font-weight: 700; }
        .signature-subtitle, .signature-date { margin-top: .5mm; font-size: 7pt; }
        .print-footer { margin-top: 1.5mm; padding-top: 1mm; border-top: 1px solid #000; text-align: center; font-size: 7.2pt; line-height: 1.15; }
        img, svg, canvas, .no-print, button { display: none !important; }
    `;
}

function removeReportPrintFrame() {
    const existingFrame = document.getElementById('report-print-frame');
    if (existingFrame) {
        existingFrame.remove();
    }
}

function printReportFrame(frame) {
    if (!frame || frame.dataset.printed === '1') {
        return;
    }
    frame.dataset.printed = '1';
    const printWindow = frame.contentWindow;
    if (!printWindow) {
        window.print();
        return;
    }
    printWindow.focus();
    printWindow.print();
    window.setTimeout(removeReportPrintFrame, 1200);
}

function openPrintReport() {
    const printReport = document.querySelector('.print-report');
    if (!printReport) {
        window.print();
        return;
    }

    removeReportPrintFrame();

    const printNode = printReport.cloneNode(true);
    printNode.id = 'report-print-source';
    printNode.hidden = false;
    printNode.removeAttribute('hidden');
    printNode.removeAttribute('aria-hidden');
    printNode.style.display = 'block';

    const frame = document.createElement('iframe');
    frame.id = 'report-print-frame';
    frame.setAttribute('aria-hidden', 'true');
    frame.style.position = 'fixed';
    frame.style.right = '0';
    frame.style.bottom = '0';
    frame.style.width = '1px';
    frame.style.height = '1px';
    frame.style.border = '0';
    frame.style.opacity = '0';
    frame.style.pointerEvents = 'none';
    document.body.appendChild(frame);

    const frameDocument = frame.contentDocument || frame.contentWindow.document;
    frameDocument.open();
    frameDocument.write('<!doctype html><html><head><meta charset="utf-8"><title>Print Report</title><style>' + reportPrintStyles() + '</style></head><body>' + printNode.outerHTML + '</body></html>');
    frameDocument.close();

    frame.onload = function () {
        printReportFrame(frame);
    };
    window.setTimeout(function () {
        printReportFrame(frame);
    }, 200);
}

window.addEventListener('afterprint', removeReportPrintFrame);
</script>

<?php render_footer();
