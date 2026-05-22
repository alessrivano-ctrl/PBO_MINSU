<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';

$user = require_login($pdo);

$fromInput = trim((string) ($_GET['from'] ?? date('Y-m-01')));
$toInput = trim((string) ($_GET['to'] ?? date('Y-m-d')));
$projectFilter = (int) ($_GET['project_id'] ?? 0);
$categoryFilter = (string) ($_GET['category'] ?? 'all');
$productFilter = (int) ($_GET['product_id'] ?? 0);

$startDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromInput) ? $fromInput : date('Y-m-01');
$endDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $toInput) ? $toInput : date('Y-m-d');
if (strtotime($startDate) > strtotime($endDate)) {
    [$startDate, $endDate] = [$endDate, $startDate];
}

[$periodStart, $periodEndExclusive] = date_filter_bounds($startDate, $endDate);

$projectOptions = $pdo->query('SELECT id, name FROM project_categories WHERE is_active = 1 ORDER BY name')->fetchAll();
$productOptions = product_options($pdo);

$salesWhere = ['sh.sale_date >= :start_dt', 'sh.sale_date < :end_dt', 'sh.payment_status <> "void"'];
$salesParams = ['start_dt' => $periodStart, 'end_dt' => $periodEndExclusive];
if ($productFilter > 0) {
    $salesWhere[] = 'p.id = :product_id';
    $salesParams['product_id'] = $productFilter;
} elseif ($categoryFilter !== 'all') {
    $salesWhere[] = 'p.category = :category';
    $salesParams['category'] = $categoryFilter;
}
$salesWhereSql = implode(' AND ', $salesWhere);

$projectWhere = ['pe.entry_datetime >= :start_dt', 'pe.entry_datetime < :end_dt'];
$projectParams = ['start_dt' => $periodStart, 'end_dt' => $periodEndExclusive];
if ($projectFilter > 0) {
    $projectWhere[] = 'pc.id = :project_id';
    $projectParams['project_id'] = $projectFilter;
}
$projectWhereSql = implode(' AND ', $projectWhere);

$salesSummaryStmt = $pdo->prepare("SELECT
        COALESCE(SUM(si.total_amount), 0) AS amount,
        COALESCE(SUM(si.profit), 0) AS profit,
        COUNT(DISTINCT sh.id) AS transactions
    FROM sale_headers sh
    INNER JOIN sale_items si ON si.sale_header_id = sh.id
    INNER JOIN products p ON p.id = si.product_id
    WHERE {$salesWhereSql}");
$salesSummaryStmt->execute($salesParams);
$periodSales = $salesSummaryStmt->fetch() ?: ['amount' => 0, 'profit' => 0, 'transactions' => 0];

$cashSummaryStmt = $pdo->prepare("SELECT
        COALESCE(SUM(CASE WHEN direction = 'in' THEN amount ELSE 0 END), 0) AS cash_in,
        COALESCE(SUM(CASE WHEN direction = 'out' THEN amount ELSE 0 END), 0) AS cash_out
    FROM cash_transactions
    WHERE txn_date >= :start_dt AND txn_date < :end_dt");
$cashSummaryStmt->execute(['start_dt' => $periodStart, 'end_dt' => $periodEndExclusive]);
$periodCash = $cashSummaryStmt->fetch() ?: ['cash_in' => 0, 'cash_out' => 0];

$lowStockWhere = ['type = "item"', 'is_active = 1', 'stock_qty <= low_stock_threshold'];
$lowStockParams = [];
if ($categoryFilter !== 'all') {
    $lowStockWhere[] = 'category = :category';
    $lowStockParams['category'] = $categoryFilter;
}
if ($productFilter > 0) {
    $lowStockWhere[] = 'id = :product_id';
    $lowStockParams['product_id'] = $productFilter;
}
$lowStockStmt = $pdo->prepare('SELECT id, name, sku, stock_qty, low_stock_threshold
    FROM products
    WHERE ' . implode(' AND ', $lowStockWhere) . '
    ORDER BY stock_qty ASC');
$lowStockStmt->execute($lowStockParams);
$lowStockItems = $lowStockStmt->fetchAll();

$overdueRentals = $pdo->query("SELECT pa.id, pa.account_name AS tenant_name, pa.code AS stall_name, pa.next_due_date, pa.expected_amount AS monthly_rent
    FROM v_overdue_project_accounts pa
    WHERE pa.category_slug = 'rental'
    ORDER BY pa.next_due_date ASC")->fetchAll();

$pendingProposals = (int) $pdo->query('SELECT COUNT(*) FROM proposals WHERE status IN ("submitted", "under_review")')->fetchColumn();
$pendingApprovals = count_pending_approvals($pdo);
$salesTrendStmt = $pdo->prepare("SELECT DATE(sh.sale_date) AS report_date,
        COUNT(DISTINCT sh.id) AS transactions,
        COALESCE(SUM(si.total_amount), 0) AS revenue,
        COALESCE(SUM(si.profit), 0) AS profit
    FROM sale_headers sh
    INNER JOIN sale_items si ON si.sale_header_id = sh.id
    INNER JOIN products p ON p.id = si.product_id
    WHERE {$salesWhereSql}
    GROUP BY DATE(sh.sale_date)
    ORDER BY DATE(sh.sale_date) ASC");
$salesTrendStmt->execute($salesParams);
$salesTrend = $salesTrendStmt->fetchAll();

$projectCategoryParams = ['start_dt' => $periodStart, 'end_dt' => $periodEndExclusive];
$projectCategoryFilterSql = $projectFilter > 0 ? 'AND pc.id = :project_id' : '';
if ($projectFilter > 0) {
    $projectCategoryParams['project_id'] = $projectFilter;
}
$projectCategoryStmt = $pdo->prepare("SELECT pc.name,
        COALESCE(SUM(CASE WHEN pe.entry_type IN ('income', 'payment', 'harvest') THEN pe.amount ELSE 0 END), 0) AS income,
        COALESCE(SUM(CASE WHEN pe.entry_type = 'expense' THEN pe.amount ELSE 0 END), 0) AS expense,
        COALESCE(SUM(CASE WHEN pe.entry_type IN ('income', 'payment', 'harvest') THEN pe.amount WHEN pe.entry_type = 'expense' THEN -pe.amount ELSE 0 END), 0) AS net
    FROM project_categories pc
    LEFT JOIN project_entries pe
        ON pe.category_id = pc.id
        AND pe.entry_datetime >= :start_dt
        AND pe.entry_datetime < :end_dt
    WHERE pc.is_active = 1
        {$projectCategoryFilterSql}
    GROUP BY pc.id, pc.name
    ORDER BY pc.name");
$projectCategoryStmt->execute($projectCategoryParams);
$projectCategoryChart = $projectCategoryStmt->fetchAll();

$projectEntryStmt = $pdo->prepare("SELECT pe.entry_type,
        COUNT(*) AS entry_count,
        COALESCE(SUM(pe.amount), 0) AS total_amount
    FROM project_entries pe
    INNER JOIN project_categories pc ON pc.id = pe.category_id
    WHERE {$projectWhereSql}
    GROUP BY pe.entry_type
    ORDER BY pe.entry_type");
$projectEntryStmt->execute($projectParams);
$projectEntryTypes = $projectEntryStmt->fetchAll();

$inventoryAnalyticsStmt = $pdo->prepare('SELECT product_group,
        COUNT(*) AS item_count,
        COALESCE(SUM(CASE WHEN type = "item" THEN stock_qty * cost_price ELSE 0 END), 0) AS stock_value,
        COALESCE(SUM(CASE WHEN type = "item" AND stock_qty <= low_stock_threshold THEN 1 ELSE 0 END), 0) AS low_stock_count,
        COALESCE(SUM(CASE WHEN type = "item" THEN stock_qty ELSE 0 END), 0) AS stock_units
    FROM products
    WHERE is_active = 1'
    . ($categoryFilter !== 'all' ? ' AND category = :category' : '')
    . ($productFilter > 0 ? ' AND id = :product_id' : '')
    . ' GROUP BY product_group
    ORDER BY FIELD(product_group, "product", "igp", "service")');
$inventoryAnalyticsStmt->execute(array_filter([
    'category' => $categoryFilter !== 'all' ? $categoryFilter : null,
    'product_id' => $productFilter > 0 ? $productFilter : null,
], static fn ($value): bool => $value !== null));
$inventoryAnalytics = $inventoryAnalyticsStmt->fetchAll();

$topProductStmt = $pdo->prepare("SELECT p.name, p.sku,
        COALESCE(SUM(si.quantity), 0) AS quantity_sold,
        COALESCE(SUM(si.total_amount), 0) AS revenue,
        COALESCE(SUM(si.total_cost), 0) AS cost,
        COALESCE(SUM(si.profit), 0) AS profit
    FROM sale_headers sh
    INNER JOIN sale_items si ON si.sale_header_id = sh.id
    INNER JOIN products p ON p.id = si.product_id
    WHERE {$salesWhereSql}
    GROUP BY p.id, p.name, p.sku
    ORDER BY profit DESC
    LIMIT 8");
$topProductStmt->execute($salesParams);
$topProductProfit = $topProductStmt->fetchAll();

$topSellingProductStmt = $pdo->prepare("SELECT p.name, p.sku,
        COALESCE(SUM(si.quantity), 0) AS quantity_sold,
        COALESCE(SUM(si.total_amount), 0) AS revenue,
        COALESCE(SUM(si.total_cost), 0) AS cost,
        COALESCE(SUM(si.profit), 0) AS profit
    FROM sale_headers sh
    INNER JOIN sale_items si ON si.sale_header_id = sh.id
    INNER JOIN products p ON p.id = si.product_id
    WHERE {$salesWhereSql}
    GROUP BY p.id, p.name, p.sku
    ORDER BY quantity_sold DESC, revenue DESC
    LIMIT 8");
$topSellingProductStmt->execute($salesParams);
$topSellingProducts = $topSellingProductStmt->fetchAll();

$rentalCollectionStmt = $pdo->prepare("SELECT pa.account_name,
        pa.expected_amount,
        pa.next_due_date,
        COALESCE(SUM(CASE WHEN pe.entry_type = 'payment' THEN pe.amount ELSE 0 END), 0) AS paid_amount,
        GREATEST(COALESCE(pa.expected_amount, 0) - COALESCE(SUM(CASE WHEN pe.entry_type = 'payment' THEN pe.amount ELSE 0 END), 0), 0) AS balance
    FROM project_accounts pa
    INNER JOIN project_categories pc ON pc.id = pa.category_id
    LEFT JOIN project_entries pe ON pe.account_id = pa.id
        AND pe.entry_datetime >= :start_dt
        AND pe.entry_datetime < :end_dt
    WHERE pc.slug = 'rental' AND pa.status = 'active'
    GROUP BY pa.id, pa.account_name, pa.expected_amount, pa.next_due_date
    ORDER BY balance DESC, pa.next_due_date ASC
    LIMIT 8");
$rentalCollectionStmt->execute(['start_dt' => $periodStart, 'end_dt' => $periodEndExclusive]);
$rentalCollection = $rentalCollectionStmt->fetchAll();

$salesTrendLabels = array_map(static fn (array $row): string => (string) $row['report_date'], $salesTrend);
$salesTrendTransactions = array_map(static fn (array $row): int => (int) $row['transactions'], $salesTrend);
$salesTrendRevenue = array_map(static fn (array $row): float => (float) $row['revenue'], $salesTrend);
$salesTrendProfit = array_map(static fn (array $row): float => (float) $row['profit'], $salesTrend);
$projectChartLabels = array_map(static fn (array $row): string => (string) $row['name'], $projectCategoryChart);
$projectChartIncome = array_map(static fn (array $row): float => (float) $row['income'], $projectCategoryChart);
$projectChartExpense = array_map(static fn (array $row): float => (float) $row['expense'], $projectCategoryChart);
$projectChartNet = array_map(static fn (array $row): float => (float) $row['net'], $projectCategoryChart);
$projectEntryTypeLabels = array_map(static fn (array $row): string => ucwords(str_replace('_', ' ', (string) $row['entry_type'])), $projectEntryTypes);
$projectEntryTypeCounts = array_map(static fn (array $row): int => (int) $row['entry_count'], $projectEntryTypes);
$projectEntryTypeAmounts = array_map(static fn (array $row): float => (float) $row['total_amount'], $projectEntryTypes);
$inventoryLabels = array_map(static fn (array $row): string => product_group_label((string) $row['product_group']), $inventoryAnalytics);
$inventoryCounts = array_map(static fn (array $row): int => (int) $row['item_count'], $inventoryAnalytics);
$inventoryStockValues = array_map(static fn (array $row): float => (float) $row['stock_value'], $inventoryAnalytics);
$inventoryLowStockCounts = array_map(static fn (array $row): int => (int) $row['low_stock_count'], $inventoryAnalytics);
$inventoryStockUnits = array_map(static fn (array $row): int => (int) $row['stock_units'], $inventoryAnalytics);
$topProductLabels = array_map(static fn (array $row): string => product_display_name($row), $topProductProfit);
$topProductQty = array_map(static fn (array $row): int => (int) $row['quantity_sold'], $topProductProfit);
$topProductRevenue = array_map(static fn (array $row): float => (float) $row['revenue'], $topProductProfit);
$topProductCost = array_map(static fn (array $row): float => (float) $row['cost'], $topProductProfit);
$topProductProfitValues = array_map(static fn (array $row): float => (float) $row['profit'], $topProductProfit);
$topSellingProductLabels = array_map(static fn (array $row): string => product_display_name($row), $topSellingProducts);
$topSellingProductQty = array_map(static fn (array $row): int => (int) $row['quantity_sold'], $topSellingProducts);
$topSellingProductRevenue = array_map(static fn (array $row): float => (float) $row['revenue'], $topSellingProducts);
$topSellingProductCost = array_map(static fn (array $row): float => (float) $row['cost'], $topSellingProducts);
$topSellingProductProfit = array_map(static fn (array $row): float => (float) $row['profit'], $topSellingProducts);
$rentalCollectionLabels = array_map(static fn (array $row): string => (string) $row['account_name'], $rentalCollection);
$rentalExpected = array_map(static fn (array $row): float => (float) $row['expected_amount'], $rentalCollection);
$rentalPaid = array_map(static fn (array $row): float => (float) $row['paid_amount'], $rentalCollection);
$rentalBalance = array_map(static fn (array $row): float => (float) $row['balance'], $rentalCollection);
$rentalDueDates = array_map(static fn (array $row): string => (string) ($row['next_due_date'] ?? ''), $rentalCollection);
render_header('Dashboard', $user);
?>


<form class="dashboard-filter-panel" method="get">
    <div class="dashboard-filter-grid">
        <div>
            <label for="from">From</label>
            <input id="from" type="date" name="from" value="<?= h($startDate) ?>">
        </div>
        <div>
            <label for="to">To</label>
            <input id="to" type="date" name="to" value="<?= h($endDate) ?>">
        </div>
        <div>
            <label for="project_id">Project</label>
            <select id="project_id" name="project_id">
                <option value="0">All projects</option>
                <?php foreach ($projectOptions as $project): ?>
                    <option value="<?= (int) $project['id'] ?>" <?= $projectFilter === (int) $project['id'] ? 'selected' : '' ?>><?= h($project['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="category">Product Category</label>
            <select id="category" name="category">
                <option value="all">All categories</option>
                <?php foreach (product_category_options() as $value => $label): ?>
                    <option value="<?= h($value) ?>" <?= $categoryFilter === $value ? 'selected' : '' ?>><?= h($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="product_id">Product</label>
            <select id="product_id" name="product_id">
                <option value="0">All products</option>
                <?php foreach ($productOptions as $product): ?>
                    <option value="<?= (int) $product['id'] ?>" <?= $productFilter === (int) $product['id'] ? 'selected' : '' ?>><?= h(product_display_name($product)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="dashboard-filter-actions">
            <button type="submit">Apply</button>
            <a class="btn alt" href="dashboard.php">Reset</a>
        </div>
    </div>
</form>

<div class="dashboard-layout">
<section class="dashboard-section" aria-label="Key operation summary">
<div class="dashboard-card-grid">
    <a class="card dashboard-card dashboard-link dashboard-pos-card" href="sales.php" aria-label="Open POS">
        <h3>POS</h3>
        <div class="stat">Open POS</div>
        <div class="muted">Record a sale or service transaction.</div>
        <span class="dashboard-card-cta">Start sale -></span>
    </a>

    <a class="card dashboard-card dashboard-link" href="sales-reports.php" aria-label="View sales details">
        <h3>Sales</h3>
        <div class="stat"><?= h(money((float) $periodSales['amount'])) ?></div>
        <div class="muted">Profit <?= h(money((float) $periodSales['profit'])) ?> | <?= h((string) (int) $periodSales['transactions']) ?> txn</div>
        <span class="dashboard-card-cta">View details -></span>
    </a>

    <a class="card dashboard-card dashboard-link" href="cashflow.php" aria-label="View net cash details">
        <h3>Net Cash</h3>
        <div class="stat"><?= h(money((float) $periodCash['cash_in'] - (float) $periodCash['cash_out'])) ?></div>
        <div class="muted">In <?= h(money((float) $periodCash['cash_in'])) ?> | Out <?= h(money((float) $periodCash['cash_out'])) ?></div>
        <span class="dashboard-card-cta">View details -></span>
    </a>

    <a class="card dashboard-card dashboard-link <?= $lowStockItems ? 'border-gold-400 bg-gold-50' : '' ?>" href="products.php?view=low_stock" aria-label="View Low Stock Items">
        <h3>Low Stock Items</h3>
        <div class="stat"><?= h((string) count($lowStockItems)) ?></div>
        <span class="dashboard-card-cta">View details -></span>
    </a>

    <a class="card dashboard-card dashboard-link <?= $overdueRentals ? 'border-red-200 bg-red-50' : '' ?>" href="projects.php?category=rental&rental_type=stall&tab=overdue" aria-label="View Overdue Rentals">
        <h3>Overdue Rentals</h3>
        <div class="stat"><?= h((string) count($overdueRentals)) ?></div>
        <span class="dashboard-card-cta">View details -></span>
    </a>

</div>
</section>

<section class="card chart-card dashboard-section" aria-label="Financial and operation charts">
    <div class="section-heading">
        <div>
            <h3>Operation Charts</h3>
            <p class="muted">Hover each chart for revenue, profit, stock, and collection details.</p>
        </div>
    </div>
    <div class="chart-grid dashboard-chart-grid">
        <div class="chart-panel dashboard-sales-chart">
            <h4>Sales Trend</h4>
            <div class="chart-frame">
                <canvas id="dashboardSalesChart"></canvas>
            </div>
        </div>
        <div class="chart-panel">
            <h4>Item Profit</h4>
            <div class="chart-frame">
                <canvas id="dashboardTopProductChart"></canvas>
            </div>
        </div>
        <div class="chart-panel">
            <h4>Inventory Stock Status</h4>
            <div class="chart-frame">
                <canvas id="dashboardInventoryStatusChart"></canvas>
            </div>
        </div>
        <div class="chart-panel">
            <h4>Top Selling Products</h4>
            <div class="chart-frame">
                <canvas id="dashboardProjectChart"></canvas>
            </div>
        </div>
        <div class="chart-panel">
            <h4>Rental Collection Status</h4>
            <div class="chart-frame">
                <canvas id="dashboardRentalCollectionChart"></canvas>
            </div>
        </div>
    </div>
</section>

</div>

<script>
    window.BPO_CHARTS = window.BPO_CHARTS || {};
    window.BPO_CHARTS.dashboard = {
        salesLabels: <?= json_encode($salesTrendLabels, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
        salesTransactions: <?= json_encode($salesTrendTransactions, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
        salesRevenue: <?= json_encode($salesTrendRevenue, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
        salesProfit: <?= json_encode($salesTrendProfit, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
        topProductLabels: <?= json_encode($topProductLabels, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
        topProductQty: <?= json_encode($topProductQty, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
        topProductRevenue: <?= json_encode($topProductRevenue, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
        topProductCost: <?= json_encode($topProductCost, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
        topProductProfit: <?= json_encode($topProductProfitValues, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
        topSellingProductLabels: <?= json_encode($topSellingProductLabels, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
        topSellingProductQty: <?= json_encode($topSellingProductQty, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
        topSellingProductRevenue: <?= json_encode($topSellingProductRevenue, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
        topSellingProductCost: <?= json_encode($topSellingProductCost, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
        topSellingProductProfit: <?= json_encode($topSellingProductProfit, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
        inventoryLabels: <?= json_encode($inventoryLabels, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
        inventoryCounts: <?= json_encode($inventoryCounts, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
        inventoryStockValues: <?= json_encode($inventoryStockValues, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
        inventoryLowStockCounts: <?= json_encode($inventoryLowStockCounts, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
        inventoryStockUnits: <?= json_encode($inventoryStockUnits, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
        projectLabels: <?= json_encode($projectChartLabels, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
        projectIncome: <?= json_encode($projectChartIncome, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
        projectExpense: <?= json_encode($projectChartExpense, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
        projectNet: <?= json_encode($projectChartNet, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
        rentalCollectionLabels: <?= json_encode($rentalCollectionLabels, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
        rentalExpected: <?= json_encode($rentalExpected, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
        rentalPaid: <?= json_encode($rentalPaid, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
        rentalBalance: <?= json_encode($rentalBalance, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
        rentalDueDates: <?= json_encode($rentalDueDates, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>
    };
    window.BPO_CHARTS.projects = {
        categoryLabels: <?= json_encode($projectChartLabels, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
        income: <?= json_encode($projectChartIncome, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
        expense: <?= json_encode($projectChartExpense, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
        net: <?= json_encode($projectChartNet, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
        entryTypeLabels: <?= json_encode($projectEntryTypeLabels, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
        entryTypeCounts: <?= json_encode($projectEntryTypeCounts, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
        entryTypeAmounts: <?= json_encode($projectEntryTypeAmounts, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>
    };
    window.BPO_CHARTS.inventory = {
        labels: <?= json_encode($inventoryLabels, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
        counts: <?= json_encode($inventoryCounts, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
        values: <?= json_encode($inventoryStockValues, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>
    };
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.dashboard-link').forEach(function (card) {
            card.addEventListener('keydown', function (event) {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    window.location.href = card.href;
                }
            });
        });
    });
</script>

<?php render_footer();
