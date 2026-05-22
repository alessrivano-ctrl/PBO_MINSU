<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';

$user = require_login($pdo);

function create_logbook_from_sale(PDO $pdo, string $saleDate, string $purpose, array $user, ?string $orNumber = null): int
{
    $timestamp = strtotime($saleDate) ?: time();
    $stmt = $pdo->prepare('INSERT INTO office_logbook (person_id, log_date, time_in, time_out, student_name, student_id, or_number, purpose, created_by)
        VALUES (NULL, :log_date, :time_in, NULL, :student_name, NULL, :or_number, :purpose, :created_by)');
    $stmt->execute([
        'log_date' => date('Y-m-d', $timestamp),
        'time_in' => date('H:i:s', $timestamp),
        'student_name' => '',
        'or_number' => $orNumber !== null && trim($orNumber) !== '' ? trim($orNumber) : null,
        'purpose' => $purpose,
        'created_by' => (int) $user['id'],
    ]);

    return (int) $pdo->lastInsertId();
}

function pos_service_variant_match(array $product, array $skus, array $nameNeedles, string $category): bool
{
    $sku = strtoupper(trim((string) ($product['sku'] ?? '')));
    $name = strtolower(trim((string) ($product['name'] ?? '')));
    $productCategory = strtolower(trim((string) ($product['category'] ?? '')));

    if (in_array($sku, $skus, true)) {
        return true;
    }
    if ($productCategory !== $category) {
        return false;
    }
    foreach ($nameNeedles as $needle) {
        if ($needle !== '' && str_contains($name, $needle)) {
            return true;
        }
    }

    return false;
}

function pos_find_service_variant(array $products, array $skus, array $nameNeedles, string $category): ?array
{
    foreach ($products as $product) {
        if (pos_service_variant_match($product, $skus, $nameNeedles, $category)) {
            return $product;
        }
    }

    return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['csrf_token'] ?? '');
    $action = (string) ($_POST['action'] ?? '');
    if (!verify_csrf($token)) {
        set_flash('error', 'Invalid form token.');
        redirect('sales.php');
    }

    if ($action === 'record_sale') {
        $productId = (int) ($_POST['product_id'] ?? 0);
        $quantity = (int) ($_POST['quantity'] ?? 0);
        $saleDate = normalize_datetime_input((string) ($_POST['sale_date'] ?? ''));
        $orNumber = '';
        $notes = trim((string) ($_POST['notes'] ?? ''));
        $paymentAmount = (float) ($_POST['payment_amount'] ?? 0);

        if ($productId <= 0 || $quantity <= 0) {
            set_flash('error', 'Item and quantity are required.');
            redirect('sales.php');
        }

        $stmt = $pdo->prepare('SELECT p.id, p.name, p.sku, p.type, p.stock_qty,
                COALESCE(s.sellable_stock_qty, 0) AS sellable_stock_qty,
                p.cost_price, p.selling_price
            FROM products p
            LEFT JOIN (
                SELECT product_id, SUM(quantity_remaining) AS sellable_stock_qty
                FROM inventory_stock_batches
                WHERE quantity_remaining > 0
                    AND (expiration_date IS NULL OR expiration_date >= CURDATE())
                GROUP BY product_id
            ) s ON s.product_id = p.id
            WHERE p.id = :id AND p.is_active = 1');
        $stmt->execute(['id' => $productId]);
        $product = $stmt->fetch();

        if (!$product) {
            set_flash('error', 'Selected item does not exist.');
            redirect('sales.php');
        }

        if ($product['type'] === 'item' && (int) $product['sellable_stock_qty'] < $quantity) {
            set_flash('error', 'Insufficient stock for this sale.');
            redirect('sales.php');
        }

        $unitPrice = (float) $product['selling_price'];
        $totalAmount = $unitPrice * $quantity;
        if ($paymentAmount + 0.0001 < $totalAmount) {
            set_flash('error', 'Payment amount must cover the sale total.');
            redirect('sales.php');
        }
        $changeAmount = $paymentAmount - $totalAmount;

        try {
            $pdo->beginTransaction();
            $orNumber = generate_pos_or_number($pdo);

            if ($product['type'] === 'item') {
                $fifo = inventory_fifo_issue($pdo, $productId, $quantity, $saleDate, 'sale', $orNumber, 'POS sale', $user);
                $totalCost = (float) $fifo['total_cost'];
                $unitCost = (float) $fifo['unit_cost'];
                $movementIds = $fifo['movement_ids'];
            } else {
                $unitCost = (float) $product['cost_price'];
                $totalCost = $unitCost * $quantity;
                $movementIds = [];
            }
            $totalProfit = $totalAmount - $totalCost;

            $insertSale = $pdo->prepare('INSERT INTO sales (sale_date, product_id, person_id, quantity, unit_price, unit_cost, total_amount, total_cost, total_profit, or_number, notes, created_by)
                VALUES (:sale_date, :product_id, :person_id, :quantity, :unit_price, :unit_cost, :total_amount, :total_cost, :total_profit, :or_number, :notes, :created_by)');
            $insertSale->execute([
                'sale_date' => $saleDate,
                'product_id' => $productId,
                'person_id' => null,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'unit_cost' => $unitCost,
                'total_amount' => $totalAmount,
                'total_cost' => $totalCost,
                'total_profit' => $totalProfit,
                'or_number' => $orNumber !== '' ? $orNumber : null,
                'notes' => $notes !== '' ? $notes : null,
                'created_by' => (int) $user['id'],
            ]);
            $saleId = (int) $pdo->lastInsertId();

            if ($movementIds) {
                $placeholders = implode(',', array_fill(0, count($movementIds), '?'));
                $linkMovements = $pdo->prepare('UPDATE inventory_stock_movements SET sale_id = ? WHERE id IN (' . $placeholders . ')');
                $linkMovements->execute(array_merge([$saleId], $movementIds));
            }

            $saleHeaderId = create_sale_header($pdo, $orNumber, $saleDate, null, $totalAmount, $totalCost, $totalProfit, $paymentAmount, $notes !== '' ? $notes : null, $user);
            create_sale_item($pdo, $saleHeaderId, $saleId, $productId, $quantity, $unitPrice, $unitCost, $totalAmount, $totalCost, $totalProfit);
            create_sale_payment($pdo, $saleHeaderId, $saleDate, $paymentAmount, 'cash', $orNumber, $user);

            $insertCash = $pdo->prepare('INSERT INTO cash_transactions (txn_date, direction, source_module, amount, or_number, description, created_by)
                VALUES (:txn_date, "in", "sales", :amount, :or_number, :description, :created_by)');
            $insertCash->execute([
                'txn_date' => $saleDate,
                'amount' => $totalAmount,
                'or_number' => $orNumber !== '' ? $orNumber : null,
                'description' => 'Sale: ' . $product['name'] . ' x ' . $quantity,
                'created_by' => (int) $user['id'],
            ]);

            $logbookId = create_logbook_from_sale(
                $pdo,
                $saleDate,
                'POS sale: ' . $product['name'] . ' x ' . $quantity,
                $user,
                $orNumber
            );

            $pdo->commit();
            audit_log($pdo, $user, 'record_sale', 'sales', 'sale', $saleId, [
                'product_id' => $productId,
                'quantity' => $quantity,
                'amount' => $totalAmount,
                'profit' => $totalProfit,
                'logbook_id' => $logbookId,
            ]);
            $_SESSION['pos_receipt'] = [
                'receipt_no' => $orNumber !== '' ? $orNumber : 'SALE-' . $saleId,
                'sale_date' => $saleDate,
                'items' => [[
                    'name' => product_display_name($product),
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'total' => $totalAmount,
                ]],
                'total' => $totalAmount,
                'payment' => $paymentAmount,
                'change' => $changeAmount,
                'cashier' => (string) ($user['full_name'] ?? $user['username'] ?? ''),
                'sale_header_id' => $saleHeaderId,
                'sale_ids' => [$saleId],
            ];
            set_flash('success', 'Sale recorded. Revenue: ' . money($totalAmount) . ' | Profit: ' . money($totalProfit));
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            log_system_issue($pdo, 'error', 'Failed to record sale.', ['error' => $e->getMessage(), 'product_id' => $productId], $user);
            set_flash('error', 'Failed to record sale.');
        }

        redirect('sales.php');
    }

    if ($action === 'batch_sale') {
        $itemsJson = (string) ($_POST['items_json'] ?? '{}');
        $orNumber = '';
        $notes = trim((string) ($_POST['notes'] ?? ''));
        $paymentAmount = (float) ($_POST['payment_amount'] ?? 0);
        $saleDate = date('Y-m-d H:i:s');

        $items = json_decode($itemsJson, true) ?: [];
        
        if (empty($items)) {
            set_flash('error', 'No items in checkout.');
            redirect('sales.php');
        }
        $totalRevenue = 0;
        $totalProfit = 0;
        $batchTotalCost = 0;
        $saleBatches = [];
        $normalizedItems = [];
        $receiptItems = [];

        try {
            $pdo->beginTransaction();
            $orNumber = generate_pos_or_number($pdo);

            foreach ($items as $productId => $itemData) {
                $productId = (int) $productId;
                $quantity = (int) ($itemData['quantity'] ?? 0);

                if ($productId <= 0 || $quantity <= 0) {
                    continue;
                }

                $stmt = $pdo->prepare('SELECT p.id, p.name, p.sku, p.type, p.stock_qty,
                        COALESCE(s.sellable_stock_qty, 0) AS sellable_stock_qty,
                        p.cost_price, p.selling_price
                    FROM products p
                    LEFT JOIN (
                        SELECT product_id, SUM(quantity_remaining) AS sellable_stock_qty
                        FROM inventory_stock_batches
                        WHERE quantity_remaining > 0
                            AND (expiration_date IS NULL OR expiration_date >= CURDATE())
                        GROUP BY product_id
                    ) s ON s.product_id = p.id
                    WHERE p.id = :id AND p.is_active = 1');
                $stmt->execute(['id' => $productId]);
                $product = $stmt->fetch();

                if (!$product) {
                    throw new RuntimeException('Item ' . $productId . ' does not exist.');
                }

                if ($product['type'] === 'item' && (int) $product['sellable_stock_qty'] < $quantity) {
                    throw new RuntimeException('Insufficient stock for ' . $product['name']);
                }

                $unitPrice = (float) $product['selling_price'];
                $totalAmount = $unitPrice * $quantity;

                if ($product['type'] === 'item') {
                    $fifo = inventory_fifo_issue($pdo, $productId, $quantity, $saleDate, 'sale', $orNumber, 'POS sale (batch)', $user);
                    $totalCost = (float) $fifo['total_cost'];
                    $unitCost = (float) $fifo['unit_cost'];
                    $movementIds = $fifo['movement_ids'];
                } else {
                    $unitCost = (float) $product['cost_price'];
                    $totalCost = $unitCost * $quantity;
                    $movementIds = [];
                }
                
                $itemProfit = $totalAmount - $totalCost;
                $totalRevenue += $totalAmount;
                $totalProfit += $itemProfit;
                $batchTotalCost += $totalCost;
                $receiptItems[] = [
                    'name' => product_display_name($product),
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'total' => $totalAmount,
                ];

                $insertSale = $pdo->prepare('INSERT INTO sales (sale_date, product_id, person_id, quantity, unit_price, unit_cost, total_amount, total_cost, total_profit, or_number, notes, created_by)
                    VALUES (:sale_date, :product_id, :person_id, :quantity, :unit_price, :unit_cost, :total_amount, :total_cost, :total_profit, :or_number, :notes, :created_by)');
                $insertSale->execute([
                    'sale_date' => $saleDate,
                    'product_id' => $productId,
                    'person_id' => null,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'unit_cost' => $unitCost,
                    'total_amount' => $totalAmount,
                    'total_cost' => $totalCost,
                    'total_profit' => $itemProfit,
                    'or_number' => $orNumber !== '' ? $orNumber : null,
                    'notes' => ($notes !== '' ? $notes . ' | ' : '') . $product['name'] . ' x' . $quantity,
                    'created_by' => (int) $user['id'],
                ]);
                $saleId = (int) $pdo->lastInsertId();

                if ($movementIds) {
                    $placeholders = implode(',', array_fill(0, count($movementIds), '?'));
                    $linkMovements = $pdo->prepare('UPDATE inventory_stock_movements SET sale_id = ? WHERE id IN (' . $placeholders . ')');
                    $linkMovements->execute(array_merge([$saleId], $movementIds));
                }

                $saleBatches[] = $saleId;
                $normalizedItems[] = [
                    'legacy_sale_id' => $saleId,
                    'product_id' => $productId,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'unit_cost' => $unitCost,
                    'total_amount' => $totalAmount,
                    'total_cost' => $totalCost,
                    'profit' => $itemProfit,
                ];
            }

            if (!$saleBatches || $paymentAmount + 0.0001 < $totalRevenue) {
                throw new RuntimeException('Payment amount must cover the sale total.');
            }
            $changeAmount = $paymentAmount - $totalRevenue;

            $saleHeaderId = create_sale_header($pdo, $orNumber, $saleDate, null, $totalRevenue, $batchTotalCost, $totalProfit, $paymentAmount, $notes !== '' ? $notes : null, $user);
            foreach ($normalizedItems as $normalizedItem) {
                create_sale_item(
                    $pdo,
                    $saleHeaderId,
                    (int) $normalizedItem['legacy_sale_id'],
                    (int) $normalizedItem['product_id'],
                    (int) $normalizedItem['quantity'],
                    (float) $normalizedItem['unit_price'],
                    (float) $normalizedItem['unit_cost'],
                    (float) $normalizedItem['total_amount'],
                    (float) $normalizedItem['total_cost'],
                    (float) $normalizedItem['profit']
                );
            }
            create_sale_payment($pdo, $saleHeaderId, $saleDate, $paymentAmount, 'cash', $orNumber, $user);

            $insertCash = $pdo->prepare('INSERT INTO cash_transactions (txn_date, direction, source_module, amount, or_number, description, created_by)
                VALUES (:txn_date, "in", "sales", :amount, :or_number, :description, :created_by)');
            $insertCash->execute([
                'txn_date' => $saleDate,
                'amount' => $totalRevenue,
                'or_number' => $orNumber !== '' ? $orNumber : null,
                'description' => 'Batch POS sale (' . count($saleBatches) . ' items)',
                'created_by' => (int) $user['id'],
            ]);

            $logPurposeItems = array_map(
                static fn (array $item): string => (string) $item['name'] . ' x ' . (string) $item['quantity'],
                $receiptItems
            );
            $logPurpose = 'POS sale: ' . implode(', ', $logPurposeItems);
            if (strlen($logPurpose) > 255) {
                $logPurpose = substr($logPurpose, 0, 252) . '...';
            }

            $logbookId = create_logbook_from_sale(
                $pdo,
                $saleDate,
                $logPurpose,
                $user,
                $orNumber
            );

            $pdo->commit();
            audit_log($pdo, $user, 'batch_sale', 'sales', 'sale', implode(',', $saleBatches), [
                'item_count' => count($saleBatches),
                'total_revenue' => $totalRevenue,
                'total_profit' => $totalProfit,
                'logbook_id' => $logbookId,
            ]);
            $_SESSION['pos_receipt'] = [
                'receipt_no' => $orNumber !== '' ? $orNumber : 'SALE-' . implode('-', $saleBatches),
                'sale_date' => $saleDate,
                'items' => $receiptItems,
                'total' => $totalRevenue,
                'payment' => $paymentAmount,
                'change' => $changeAmount,
                'cashier' => (string) ($user['full_name'] ?? $user['username'] ?? ''),
                'sale_header_id' => $saleHeaderId,
                'sale_ids' => $saleBatches,
            ];
            set_flash('success', 'Batch sale completed. ' . count($saleBatches) . ' item(s) | Revenue: ' . money($totalRevenue) . ' | Profit: ' . money($totalProfit));
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            log_system_issue($pdo, 'error', 'Failed to record batch sale.', ['error' => $e->getMessage(), 'items_count' => count($items)], $user);
            set_flash('error', 'Failed to record sale: ' . $e->getMessage());
        }

        redirect('sales.php');
    }
}

$products = product_options($pdo);
$printBwProduct = pos_find_service_variant($products, ['SEED-PRINT-BW', 'PRINT-BW-PAPER'], ['black and white print', 'black white print'], 'printing');
$printColorProduct = pos_find_service_variant($products, ['SEED-PRINT-COLOR'], ['colored print', 'color print'], 'printing');
$photocopyBwProduct = pos_find_service_variant($products, ['SEED-PHOTO-BW', 'PHOTO-BW'], ['photocopy black', 'photocopy b w'], 'photocopy');
$photocopyColorProduct = pos_find_service_variant($products, ['SEED-PHOTO-COLOR', 'PHOTO-COLOR'], ['photocopy color', 'photocopy colored'], 'photocopy');
$combinedServiceProductIds = array_values(array_filter(array_map(
    static fn (?array $product): int => $product ? (int) $product['id'] : 0,
    [$printBwProduct, $printColorProduct, $photocopyBwProduct, $photocopyColorProduct]
)));
$posServiceCards = [];
if ($printBwProduct || $printColorProduct) {
    $posServiceCards[] = [
        'title' => 'Print Service',
        'category' => 'Printing Service',
        'search' => 'print service printing black white colored color',
        'options' => array_values(array_filter([
            $printBwProduct ? ['label' => 'Black & White', 'cart_name' => 'Print Service, Black & White', 'product' => $printBwProduct] : null,
            $printColorProduct ? ['label' => 'Colored', 'cart_name' => 'Print Service, Colored', 'product' => $printColorProduct] : null,
        ])),
    ];
}
if ($photocopyBwProduct || $photocopyColorProduct) {
    $posServiceCards[] = [
        'title' => 'Photocopy Service',
        'category' => 'Photocopy Service',
        'search' => 'photocopy service black white colored color',
        'options' => array_values(array_filter([
            $photocopyBwProduct ? ['label' => 'Black & White', 'cart_name' => 'Photocopy Service, Black & White', 'product' => $photocopyBwProduct] : null,
            $photocopyColorProduct ? ['label' => 'Colored', 'cart_name' => 'Photocopy Service, Colored', 'product' => $photocopyColorProduct] : null,
        ])),
    ];
}
$displayProducts = array_values(array_filter(
    $products,
    static fn (array $product): bool => !in_array((int) $product['id'], $combinedServiceProductIds, true)
));
$receiptHeader = app_setting($pdo, 'reports.receipt_header', APP_CAMPUS_NAME);
$orNumberFormat = app_setting($pdo, 'reports.or_number_format', '0000-{0000}');
$nextOrNumberPreview = preview_pos_or_number($pdo);
$posReceipt = $_SESSION['pos_receipt'] ?? null;
unset($_SESSION['pos_receipt']);

render_header('POS', $user);
?>


<div class="pos-workspace">
<section class="pos-layout">
    <div class="card pos-card">
        <div class="section-heading">
            <div>
                <h3>Items and Services</h3>
                <p class="muted">Search by item name, then add the needed quantity.</p>
            </div>
        </div>

        <div class="pos-filter-row mb-3">
            <input id="pos_search" class="pos-search-input" type="search" aria-label="Search item" placeholder="Search item or service...">
            <div class="pos-categories" aria-label="Categories">
                <span class="pos-category-label">Categories</span>
                <div class="pos-category-group" role="group" aria-label="Product categories">
                    <button type="button" class="pos-category-button is-active" data-pos-category="all" aria-pressed="true">All Items</button>
                    <button type="button" class="pos-category-button" data-pos-category="product" aria-pressed="false">Products</button>
                    <button type="button" class="pos-category-button" data-pos-category="service" aria-pressed="false">Services</button>
                </div>
            </div>
            <div class="pos-shortcut-action">
                <button type="button" class="btn alt pos-shortcut-button" id="open-shortcuts-btn" aria-haspopup="dialog">Shortcuts</button>
            </div>
        </div>

        <div id="pos_grid" class="pos-list grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(min(100%,14rem),1fr))]">
            <?php if (!$displayProducts && !$posServiceCards): ?>
                <div class="md:col-span-2 xl:col-span-3">
                    <?php render_empty_state('No items available.', 'Add products and services in Inventory before recording a sale.'); ?>
                    <?php if ($user['role'] === 'admin'): ?>
                        <div class="mt-3 text-center">
                            <a class="btn" href="products.php">Open Inventory</a>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <?php foreach ($posServiceCards as $serviceCard): ?>
                <article
                    class="pos-option pos-service-option rounded-lg border border-slate-200 bg-white p-3 shadow-sm"
                    role="button"
                    tabindex="0"
                    aria-label="<?= h($serviceCard['title']) ?> options"
                    aria-expanded="false"
                    data-service-selector="true"
                    data-group="service"
                    data-search="<?= h($serviceCard['search']) ?>"
                >
                    <div class="pos-card-top">
                        <div class="min-w-0">
                            <h3 class="text-sm font-bold text-slate-950"><?= h($serviceCard['title']) ?></h3>
                            <p class="mt-1 text-xs text-slate-500"><?= h($serviceCard['category']) ?></p>
                        </div>
                        <span class="status-pill pending">Service</span>
                    </div>
                    <div class="pos-service-card-body">
                        <p class="pos-service-hint">Choose service type</p>
                        <div class="pos-service-chip-row" aria-label="<?= h($serviceCard['title']) ?> service options">
                            <?php foreach ($serviceCard['options'] as $serviceOption): ?>
                                <?php $serviceProduct = $serviceOption['product']; ?>
                                <button
                                    type="button"
                                    class="pos-service-chip"
                                    data-service-choice="true"
                                    data-product-id="<?= (int) $serviceProduct['id'] ?>"
                                    data-name="<?= h($serviceOption['cart_name']) ?>"
                                    data-price="<?= h((string) $serviceProduct['selling_price']) ?>"
                                    data-stock="<?= h((string) (int) $serviceProduct['stock_qty']) ?>"
                                    data-type="<?= h((string) $serviceProduct['type']) ?>"
                                >
                                    <span><?= h($serviceOption['label']) ?></span>
                                    <strong><?= h(money((float) $serviceProduct['selling_price'])) ?></strong>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="pos-card-footer">
                        <div>
                            <p class="text-lg font-bold text-slate-950">Select Option</p>
                            <p class="mt-1 text-xs text-slate-500">Black & White or Colored</p>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
            <?php foreach ($displayProducts as $product): ?>
                <?php
                $isItem = $product['type'] === 'item';
                $stock = (int) ($product['sellable_stock_qty'] ?? $product['stock_qty']);
                $disabled = $isItem && $stock <= 0;
                $name = product_display_name($product);
                $sku = trim((string) ($product['sku'] ?? ''));
                $searchText = strtolower(trim($sku . ' ' . $name . ' ' . product_type_label((string) $product['type']) . ' ' . product_category_label((string) $product['category'], $product['category_name'] ?? null)));
                ?>
                <article
                    class="pos-option rounded-lg border border-slate-200 bg-white p-3 shadow-sm <?= $disabled ? 'is-disabled opacity-60' : '' ?>"
                    role="button"
                    tabindex="<?= $disabled ? '-1' : '0' ?>"
                    aria-disabled="<?= $disabled ? 'true' : 'false' ?>"
                    aria-label="Add <?= h($name) ?> to cart"
                    data-product-id="<?= (int) $product['id'] ?>"
                    data-name="<?= h($name) ?>"
                    data-price="<?= h((string) $product['selling_price']) ?>"
                    data-stock="<?= h((string) $stock) ?>"
                    data-type="<?= h((string) $product['type']) ?>"
                    data-group="<?= h((string) $product['product_group']) ?>"
                    data-search="<?= h($searchText) ?>"
                >
                    <div class="pos-card-top">
                        <div class="min-w-0">
                            <h3 class="truncate text-sm font-bold text-slate-950" title="<?= h($name) ?>"><?= h($name) ?></h3>
                            <p class="mt-1 text-xs text-slate-500"><?= h(product_category_label((string) $product['category'], $product['category_name'] ?? null)) ?></p>
                        </div>
                        <span class="status-pill <?= $isItem ? 'active' : 'pending' ?>"><?= h(product_type_label((string) $product['type'])) ?></span>
                    </div>
                    <div class="pos-card-footer">
                        <div>
                            <p class="text-lg font-bold text-slate-950"><?= h(money((float) $product['selling_price'])) ?></p>
                            <p class="mt-1 text-xs text-slate-500"><?= $isItem ? 'Stock: ' . h((string) $stock) : 'Service' ?></p>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
        <div id="pos_empty_page" class="empty-state mt-3" hidden>
            <strong>No matching items.</strong>
            <p>Adjust the search or category filter.</p>
        </div>
        <div class="pos-pagination" aria-label="POS item pages">
            <button type="button" class="btn alt" id="pos-prev-page">Previous</button>
            <span id="pos-page-info">Page 1 of 1</span>
            <button type="button" class="btn alt" id="pos-next-page">Next</button>
        </div>
    </div>

    <aside class="card pos-checkout">
        <div class="section-heading">
            <div>
                <h3>Cart</h3>
                <p class="muted">Items selected for this sale.</p>
            </div>
        </div>
        <div class="pos-total-panel rounded-lg bg-slate-50 p-4">
            <p class="text-sm font-semibold text-slate-500">Total Amount</p>
            <p id="checkout-total" class="mt-1 text-3xl font-bold text-slate-950">PHP 0.00</p>
        </div>
        <div id="checkout-items" class="mt-4 space-y-2 rounded-lg border border-slate-200 p-3" tabindex="-1" aria-label="Cart items">
            <div class="empty-state">
                <strong>No items added.</strong>
                <p>Select a product or service to begin checkout.</p>
            </div>
        </div>
        <form method="post" id="batch-sale-form" class="mt-4 space-y-3">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="batch_sale">
            <input type="hidden" id="batch_items_json" name="items_json" value="{}">
            <input type="hidden" id="batch_payment_amount" name="payment_amount" value="">
            <input type="hidden" id="batch_notes" name="notes" value="">
            <div class="pos-inline-payment">
                <div>
                    <label for="payment_amount_input">Cash Received</label>
                    <input id="payment_amount_input" type="number" min="0" step="0.01" inputmode="decimal" placeholder="0.00">
                    <p id="payment-warning" class="mt-2 text-sm font-semibold text-red-700" hidden>Payment is less than the total amount.</p>
                </div>
                <div class="pos-payment-mini-grid">
                    <div><span>Amount Due</span><strong id="payment-total">PHP 0.00</strong></div>
                    <div><span>Change</span><strong id="payment-change">PHP 0.00</strong></div>
                </div>
            </div>
            <button type="button" id="open-payment-btn" class="pos-pay-button w-full" disabled>Confirm and Print Receipt</button>
            <button type="button" id="clear-checkout-btn" class="btn alt pos-transaction-secondary w-full">Cancel Transaction</button>
        </form>
    </aside>
</section>
</div>

<dialog id="pos-shortcuts-modal" class="modal modal-compact pos-shortcuts-modal">
    <div class="modal-header">
        <div>
            <h3>Keyboard Shortcuts</h3>
        </div>
    </div>
    <div class="pos-shortcuts-grid">
        <div>
            <h4>Core POS</h4>
            <dl>
                <dt>F2</dt><dd>Focus search bar</dd>
                <dt>F3</dt><dd>Focus category filters</dd>
                <dt>F4</dt><dd>Confirm sale</dd>
                <dt>F5</dt><dd>Refresh products</dd>
                <dt>F6</dt><dd>Focus cart section</dd>
                <dt>F7</dt><dd>Clear cart</dd>
                <dt>F8</dt><dd>Cancel transaction</dd>
                <dt>F9</dt><dd>Open transaction records</dd>
                <dt>F10</dt><dd>Open cash flow</dd>
                <dt>Esc</dt><dd>Close modal or dialog</dd>
            </dl>
        </div>
        <div>
            <h4>Products and Cart</h4>
            <dl>
                <dt>Arrow Keys</dt><dd>Navigate product cards</dd>
                <dt>Enter</dt><dd>Add selected item to cart</dd>
                <dt>Delete</dt><dd>Remove selected cart item</dd>
                <dt>+</dt><dd>Increase selected cart quantity</dd>
                <dt>-</dt><dd>Decrease selected cart quantity</dd>
            </dl>
        </div>
        <div>
            <h4>Filters and Payment</h4>
            <dl>
                <dt>Alt + A</dt><dd>All Items</dd>
                <dt>Alt + P</dt><dd>Products</dd>
                <dt>Alt + S</dt><dd>Services</dd>
                <dt>Ctrl + Enter</dt><dd>Confirm payment</dd>
                <dt>Ctrl + P</dt><dd>Print receipt</dd>
                <dt>Ctrl + C</dt><dd>Focus cash received</dd>
            </dl>
        </div>
    </div>
    <div class="modal-actions">
        <button type="button" class="btn alt" id="close-shortcuts-btn">Close</button>
    </div>
</dialog>

<dialog id="cancel-transaction-modal" class="modal modal-compact">
    <div class="modal-header">
        <div>
            <h3>Cancel Transaction</h3>
            <p class="mt-1 text-sm text-slate-500">This will clear every item currently in the cart.</p>
        </div>
    </div>
    <div class="modal-actions payment-modal-actions">
        <button type="button" class="btn alt" id="keep-cart-btn">Keep Cart</button>
        <button type="button" class="btn-danger" id="confirm-cancel-transaction-btn">Cancel Transaction</button>
    </div>
</dialog>

<?php if (is_array($posReceipt)): ?>
<dialog id="receipt-modal" class="modal" data-auto-open="true">
    <div class="modal-header">
        <h3>Receipt</h3>
    </div>
    <div class="modal-content receipt-print">
        <div class="receipt-paper">
            <div class="receipt-brand">
                <h3><?= h($receiptHeader) ?></h3>
                <p>Production and Business Operation Record Management System</p>
                <p>Official POS Receipt</p>
            </div>
            <div class="receipt-meta">
                <span>OR Number</span>
                <strong><?= h((string) ($posReceipt['receipt_no'] ?? '')) ?></strong>
                <span>Date</span>
                <strong><?= h((string) ($posReceipt['sale_date'] ?? '')) ?></strong>
                <span>Cashier</span>
                <strong><?= h((string) ($posReceipt['cashier'] ?? '')) ?></strong>
            </div>
        </div>
        <table class="receipt-table mt-4 text-sm" data-no-table-enhance>
            <thead>
            <tr>
                <th>Item</th>
                <th class="num">Qty</th>
                <th class="num">Price</th>
                <th class="num">Total</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach (($posReceipt['items'] ?? []) as $item): ?>
                <tr>
                    <td><?= h((string) ($item['name'] ?? '')) ?></td>
                    <td class="num"><?= h((string) ($item['quantity'] ?? '')) ?></td>
                    <td class="num"><?= h(money((float) ($item['unit_price'] ?? 0))) ?></td>
                    <td class="num"><?= h(money((float) ($item['total'] ?? 0))) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
            <tr><th colspan="3" class="num">Total</th><th class="num"><?= h(money((float) ($posReceipt['total'] ?? 0))) ?></th></tr>
            <tr><th colspan="3" class="num">Cash</th><th class="num"><?= h(money((float) ($posReceipt['payment'] ?? 0))) ?></th></tr>
            <tr><th colspan="3" class="num">Change</th><th class="num"><?= h(money((float) ($posReceipt['change'] ?? 0))) ?></th></tr>
            </tfoot>
        </table>
        <div class="receipt-footer-note">
            <p>Thank you. Please keep this receipt for verification.</p>
        </div>
    </div>
    <div class="modal-actions receipt-modal-actions">
        <button type="button" class="btn alt" id="close-receipt-btn">Close</button>
        <button type="button" id="print-receipt-btn">Print Receipt</button>
    </div>
</dialog>
<?php endif; ?>

<script>
    (function () {
        return;
        const searchInput = document.getElementById('pos_search');
        const groupSelect = document.getElementById('pos_group');
        const options = Array.from(document.querySelectorAll('.pos-option'));
        const modalTitle = document.getElementById('sale_modal_title');
        const modalMeta = document.getElementById('sale_modal_meta');
        const quantityInput = document.getElementById('sale_quantity');
        const totalPreview = document.getElementById('sale_total_preview');
        const addBtn = document.getElementById('sale_add_btn');
        const checkoutTotal = document.getElementById('checkout-total');
        const checkoutItems = document.getElementById('checkout-items');
        const checkoutControls = document.getElementById('checkout-controls');
        const batchItemsJson = document.getElementById('batch_items_json');
        const clearCheckoutBtn = document.getElementById('clear-checkout-btn');
        const batchSaleForm = document.getElementById('batch-sale-form');
        
        let selectedPrice = 0;
        let selectedStock = 0;
        let selectedType = 'service';
        let selectedProductId = 0;
        let selectedProductName = '';
        let checkoutData = {};

        function money(value) {
            return 'PHP ' + Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }

        function updateCheckoutDisplay() {
            let total = 0;
            const itemsList = [];
            
            Object.entries(checkoutData).forEach(function([productId, item]) {
                const itemTotal = item.price * item.quantity;
                total += itemTotal;
                itemsList.push({
                    id: productId,
                    name: item.name,
                    quantity: item.quantity,
                    price: item.price,
                    total: itemTotal
                });
            });

            checkoutTotal.textContent = money(total);
            batchItemsJson.value = JSON.stringify(checkoutData);
            
            if (itemsList.length === 0) {
                checkoutItems.innerHTML = '<p class="text-sm text-slate-400">No items added</p>';
                checkoutControls.classList.add('hidden');
            } else {
                checkoutItems.innerHTML = itemsList.map(function(item) {
                    return '<div class="flex items-center justify-between rounded-lg bg-slate-50 p-2 text-sm"><div class="flex-1"><span class="font-medium text-slate-900">' + item.quantity + 'x ' + item.name + '</span><br><span class="text-xs text-slate-500">' + money(item.price) + ' = ' + money(item.total) + '</span></div><button type="button" class="remove-item-btn shrink-0 ml-2 text-red-600 hover:text-red-800 font-bold" data-product-id="' + item.id + '">✕</button></div>';
                }).join('');
                
                document.querySelectorAll('.remove-item-btn').forEach(function(btn) {
                    btn.addEventListener('click', function(e) {
                        e.preventDefault();
                        const productId = this.getAttribute('data-product-id');
                        delete checkoutData[productId];
                        updateCheckoutDisplay();
                    });
                });
                
                checkoutControls.classList.remove('hidden');
            }
        }

        function filterOptions() {
            const term = (searchInput.value || '').trim().toLowerCase();
            const group = groupSelect.value;
            options.forEach(function (option) {
                const matchesSearch = term === '' || (option.dataset.search || '').includes(term);
                const matchesGroup = group === 'all' || option.dataset.group === group || (group === 'product' && option.dataset.group === 'igp');
                option.hidden = !matchesSearch || !matchesGroup;
            });
        }

        function updateTotal() {
            const qty = Math.max(1, parseInt(quantityInput.value || '1', 10));
            totalPreview.value = money(selectedPrice * qty);
            if (selectedType === 'item') {
                addBtn.disabled = qty > selectedStock;
            }
        }

        options.forEach(function (option) {
            option.addEventListener('click', function () {
                if (!option.hasAttribute('data-open-modal')) {
                    return;
                }
                selectedPrice = parseFloat(option.dataset.price || '0');
                selectedStock = parseInt(option.dataset.stock || '0', 10);
                selectedType = option.dataset.type || 'service';
                selectedProductId = option.dataset.productId || '';
                selectedProductName = option.dataset.name || 'Unknown Item';
                quantityInput.value = '1';
                quantityInput.max = selectedType === 'item' ? String(selectedStock) : '';
                modalTitle.textContent = 'Add to Checkout';
                modalMeta.textContent = money(selectedPrice) + (selectedType === 'item' ? ' | Stock: ' + selectedStock : ' | Service');
                addBtn.disabled = false;
                updateTotal();
            });
            option.addEventListener('keydown', function (event) {
                if ((event.key === 'Enter' || event.key === ' ') && option.hasAttribute('data-open-modal')) {
                    event.preventDefault();
                    option.click();
                }
            });
        });

        addBtn.addEventListener('click', function(e) {
            e.preventDefault();
            const quantity = parseInt(quantityInput.value || '1', 10);
            
            if (selectedProductId && quantity > 0) {
                if (checkoutData[selectedProductId]) {
                    checkoutData[selectedProductId].quantity += quantity;
                } else {
                    checkoutData[selectedProductId] = {
                        name: selectedProductName,
                        quantity: quantity,
                        price: selectedPrice
                    };
                }
                updateCheckoutDisplay();
                document.querySelector('[data-close-modal="sale-modal"]')?.click() || 
                document.querySelector('[data-close-modal]')?.click();
            }
        });

        clearCheckoutBtn.addEventListener('click', function() {
            if (confirm('Clear all items from checkout?')) {
                checkoutData = {};
                updateCheckoutDisplay();
            }
        });

        searchInput.addEventListener('input', filterOptions);
        groupSelect.addEventListener('change', filterOptions);
        quantityInput.addEventListener('input', updateTotal);
        filterOptions();
        updateCheckoutDisplay();
    })();
</script>

<script>
    (function () {
        const searchInput = document.getElementById('pos_search');
        const categoryButtons = Array.from(document.querySelectorAll('[data-pos-category]'));
        const options = Array.from(document.querySelectorAll('.pos-option'));
        const posEmptyPage = document.getElementById('pos_empty_page');
        const posPrevPageBtn = document.getElementById('pos-prev-page');
        const posNextPageBtn = document.getElementById('pos-next-page');
        const posPageInfo = document.getElementById('pos-page-info');
        const checkoutTotal = document.getElementById('checkout-total');
        const checkoutItems = document.getElementById('checkout-items');
        const batchItemsJson = document.getElementById('batch_items_json');
        const batchPaymentAmount = document.getElementById('batch_payment_amount');
        const clearCheckoutBtn = document.getElementById('clear-checkout-btn');
        const batchSaleForm = document.getElementById('batch-sale-form');
        const openPaymentBtn = document.getElementById('open-payment-btn');
        const paymentTotal = document.getElementById('payment-total');
        const paymentChange = document.getElementById('payment-change');
        const paymentItems = document.getElementById('payment-items');
        const paymentAmountInput = document.getElementById('payment_amount_input');
        const paymentWarning = document.getElementById('payment-warning');
        const finalConfirmSaleBtn = document.getElementById('final-confirm-sale-btn');
        const cancelTransactionModal = document.getElementById('cancel-transaction-modal');
        const keepCartBtn = document.getElementById('keep-cart-btn');
        const confirmCancelTransactionBtn = document.getElementById('confirm-cancel-transaction-btn');
        const printReceiptBtn = document.getElementById('print-receipt-btn');
        const closeReceiptBtn = document.getElementById('close-receipt-btn');
        const receiptModal = document.getElementById('receipt-modal');
        const shortcutsModal = document.getElementById('pos-shortcuts-modal');
        const openShortcutsBtn = document.getElementById('open-shortcuts-btn');
        const closeShortcutsBtn = document.getElementById('close-shortcuts-btn');
        let checkoutData = {};
        let activeCategory = 'all';
        let checkoutTotalValue = 0;
        let isSubmittingSale = false;
        let posPage = 1;
        let visiblePageOptions = [];
        let highlightedOption = null;
        let selectedCartProductId = null;
        const posPageSize = 10;
        const selectedOptionTimers = new WeakMap();

        function money(value) {
            return 'PHP ' + Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }

        function updateCheckoutDisplay() {
            let total = 0;
            const itemsList = [];

            Object.entries(checkoutData).forEach(function([productId, item]) {
                const itemTotal = item.price * item.quantity;
                total += itemTotal;
                itemsList.push({
                    id: productId,
                    name: item.name,
                    quantity: item.quantity,
                    price: item.price,
                    total: itemTotal,
                    stock: item.stock,
                    type: item.type
                });
            });

            checkoutTotalValue = total;
            checkoutTotal.textContent = money(total);
            batchItemsJson.value = JSON.stringify(checkoutData);
            openPaymentBtn.disabled = itemsList.length === 0;
            checkoutItems.classList.toggle('cart-scroll', itemsList.length >= 2);
            updatePaymentDisplay();

            if (itemsList.length === 0) {
                checkoutItems.classList.remove('cart-scroll');
                checkoutItems.innerHTML = '<div class="empty-state"><strong>No items added.</strong><p>Select a product or service to begin checkout.</p></div>';
                return;
            }

            checkoutItems.innerHTML = itemsList.map(function(item) {
                const maxAttr = item.type === 'item' && item.stock > 0 ? ' max="' + item.stock + '"' : '';
                const safeName = escapeHtml(item.name);
                const isSelected = String(item.id) === String(selectedCartProductId);
                return '<div class="cart-item-card' + (isSelected ? ' is-keyboard-selected' : '') + '" tabindex="0" data-cart-item-id="' + item.id + '"><div class="min-w-0"><p class="truncate font-semibold text-slate-950" title="' + safeName + '">' + safeName + '</p><p class="mt-1 text-xs text-slate-500">' + money(item.price) + ' each</p><p class="mt-2 font-bold text-slate-950">' + money(item.total) + '</p></div><div class="cart-item-controls"><input class="cart-quantity-input" type="number" min="1"' + maxAttr + ' value="' + item.quantity + '" aria-label="Quantity for ' + safeName + '" data-product-id="' + item.id + '"><button type="button" class="remove-item-btn" data-product-id="' + item.id + '">Remove</button></div></div>';
            }).join('');

            document.querySelectorAll('[data-cart-item-id]').forEach(function(card) {
                card.addEventListener('focus', function() {
                    selectCartItem(card.getAttribute('data-cart-item-id'));
                });
                card.addEventListener('click', function(event) {
                    if (!event.target.closest('input, button')) {
                        selectCartItem(card.getAttribute('data-cart-item-id'));
                    }
                });
            });

            document.querySelectorAll('.cart-quantity-input').forEach(function(input) {
                input.addEventListener('focus', function() {
                    selectCartItem(this.getAttribute('data-product-id'));
                });
                input.addEventListener('change', function() {
                    const productId = this.getAttribute('data-product-id');
                    const item = checkoutData[productId];
                    if (!item) {
                        return;
                    }

                    const parsedQuantity = parseInt(this.value || '1', 10);
                    let nextQuantity = Number.isFinite(parsedQuantity) ? Math.max(1, parsedQuantity) : 1;
                    if (item.type === 'item' && item.stock > 0 && nextQuantity > item.stock) {
                        alert('Quantity exceeds available stock.');
                        nextQuantity = item.stock;
                    }

                    item.quantity = nextQuantity;
                    updateCheckoutDisplay();
                });
            });

            document.querySelectorAll('.remove-item-btn').forEach(function(btn) {
                btn.addEventListener('click', function(event) {
                    event.preventDefault();
                    removeCartItem(this.getAttribute('data-product-id'));
                    updateCheckoutDisplay();
                });
            });
        }

        function escapeHtml(value) {
            return String(value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function paymentAmountValue() {
            return Math.max(0, parseFloat(paymentAmountInput.value || '0') || 0);
        }

        function updatePaymentDisplay() {
            const paymentAmount = paymentAmountValue();
            const change = paymentAmount - checkoutTotalValue;
            const hasItems = Object.keys(checkoutData).length > 0;

            if (paymentTotal) paymentTotal.textContent = money(checkoutTotalValue);
            if (paymentChange) paymentChange.textContent = money(Math.max(0, change));
            if (paymentWarning) paymentWarning.hidden = !hasItems || paymentAmount >= checkoutTotalValue;
            if (openPaymentBtn) openPaymentBtn.disabled = !hasItems || paymentAmount < checkoutTotalValue || isSubmittingSale;
            if (finalConfirmSaleBtn) finalConfirmSaleBtn.disabled = !hasItems || paymentAmount < checkoutTotalValue || isSubmittingSale;
            batchPaymentAmount.value = hasItems && paymentAmount >= checkoutTotalValue ? paymentAmount.toFixed(2) : '';
        }

        function renderPaymentItems() {
            if (!paymentItems) {
                return;
            }
            const rows = Object.entries(checkoutData).map(function([productId, item]) {
                const itemTotal = item.price * item.quantity;
                return '<tr><td>' + escapeHtml(item.name) + '</td><td>' + item.quantity + '</td><td class="text-right">' + money(itemTotal) + '</td></tr>';
            });
            paymentItems.innerHTML = rows.length > 0 ? rows.join('') : '<tr><td colspan="3" class="muted">No items added.</td></tr>';
        }

        function selectCartItem(productId) {
            if (!productId || !checkoutData[productId]) {
                selectedCartProductId = null;
            } else {
                selectedCartProductId = String(productId);
            }

            document.querySelectorAll('[data-cart-item-id]').forEach(function(card) {
                card.classList.toggle('is-keyboard-selected', card.getAttribute('data-cart-item-id') === selectedCartProductId);
            });
        }

        function removeCartItem(productId) {
            if (!productId || !checkoutData[productId]) {
                return;
            }

            delete checkoutData[productId];
            if (selectedCartProductId === String(productId)) {
                const nextId = Object.keys(checkoutData)[0] || null;
                selectedCartProductId = nextId;
            }
        }

        function adjustSelectedCartQuantity(delta) {
            const productId = selectedCartProductId || Object.keys(checkoutData)[0] || null;
            if (!productId || !checkoutData[productId]) {
                return;
            }

            const item = checkoutData[productId];
            const nextQuantity = Math.max(1, item.quantity + delta);
            if (item.type === 'item' && item.stock > 0 && nextQuantity > item.stock) {
                alert('Quantity exceeds available stock.');
                return;
            }

            item.quantity = nextQuantity;
            selectedCartProductId = String(productId);
            updateCheckoutDisplay();
        }

        function canSubmitSale() {
            updatePaymentDisplay();
            if (Object.keys(checkoutData).length === 0) {
                return false;
            }
            if (paymentAmountValue() < checkoutTotalValue) {
                paymentAmountInput.focus();
                paymentAmountInput.select();
                return false;
            }

            return true;
        }

        function markSaleSubmitting() {
            isSubmittingSale = true;
            if (openPaymentBtn) {
                openPaymentBtn.disabled = true;
                openPaymentBtn.textContent = 'Recording Sale...';
            }
            if (finalConfirmSaleBtn) {
                finalConfirmSaleBtn.disabled = true;
                finalConfirmSaleBtn.textContent = 'Recording Sale...';
            }
        }

        function openPaymentReview() {
            if (!canSubmitSale() || isSubmittingSale) {
                return;
            }

            markSaleSubmitting();
            if (typeof batchSaleForm.requestSubmit === 'function') {
                batchSaleForm.requestSubmit();
                return;
            }
            batchSaleForm.submit();
        }

        function clearTransaction() {
            checkoutData = {};
            selectedCartProductId = null;
            paymentAmountInput.value = '';
            batchPaymentAmount.value = '';
            isSubmittingSale = false;
            if (finalConfirmSaleBtn) {
                finalConfirmSaleBtn.textContent = 'Confirm and Print Receipt';
            }
            if (openPaymentBtn) {
                openPaymentBtn.textContent = 'Confirm and Print Receipt';
            }
            updateCheckoutDisplay();
        }

        function requestCancelTransaction() {
            if (Object.keys(checkoutData).length === 0) {
                clearTransaction();
                return;
            }

            if (cancelTransactionModal && typeof cancelTransactionModal.showModal === 'function') {
                cancelTransactionModal.showModal();
                return;
            }

            clearTransaction();
        }

        function cleanupReceiptPrint() {
            document.documentElement.classList.remove('printing-receipt');
            const printRoot = document.getElementById('receipt-print-root');
            if (printRoot) {
                printRoot.remove();
            }
        }

        function prepareReceiptPrint() {
            const receiptContent = document.querySelector('#receipt-modal .receipt-print');
            if (!receiptContent) {
                return false;
            }

            cleanupReceiptPrint();
            const printRoot = document.createElement('div');
            printRoot.id = 'receipt-print-root';
            printRoot.appendChild(receiptContent.cloneNode(true));
            document.body.appendChild(printRoot);
            document.documentElement.classList.add('printing-receipt');
            return true;
        }

        function printPreparedReceipt() {
            if (!prepareReceiptPrint()) {
                window.print();
                return;
            }

            window.print();
            window.setTimeout(cleanupReceiptPrint, 1000);
        }

        function printReceiptOnly() {
            printPreparedReceipt();
        }

        function optionMatchesFilters(option) {
            const term = (searchInput.value || '').trim().toLowerCase();
            const matchesSearch = term === '' || (option.dataset.search || '').includes(term);
            const matchesGroup = activeCategory === 'all'
                || option.dataset.group === activeCategory
                || (activeCategory === 'product' && option.dataset.group === 'igp');
            return matchesSearch && matchesGroup;
        }

        function enabledVisibleOptions() {
            return visiblePageOptions.filter(function(option) {
                return option.getAttribute('aria-disabled') !== 'true';
            });
        }

        function setHighlightedOption(option, shouldFocus) {
            options.forEach(function(item) {
                const isHighlighted = item === option;
                item.classList.toggle('is-keyboard-selected', isHighlighted);
                item.setAttribute('aria-selected', isHighlighted ? 'true' : 'false');
            });

            highlightedOption = option || null;
            if (shouldFocus && highlightedOption) {
                highlightedOption.focus({ preventScroll: true });
                highlightedOption.scrollIntoView({ block: 'nearest', inline: 'nearest' });
            }
        }

        function gridColumnCount(items) {
            if (items.length <= 1) {
                return 1;
            }

            const firstTop = items[0].offsetTop;
            const columns = items.filter(function(item) {
                return Math.abs(item.offsetTop - firstTop) < 6;
            }).length;
            return Math.max(1, columns);
        }

        function moveHighlightedOption(direction) {
            const items = enabledVisibleOptions();
            if (!items.length) {
                setHighlightedOption(null, false);
                return;
            }

            const currentIndex = Math.max(0, items.indexOf(highlightedOption));
            const columns = gridColumnCount(items);
            let nextIndex = currentIndex;

            if (direction === 'left') nextIndex = currentIndex - 1;
            if (direction === 'right') nextIndex = currentIndex + 1;
            if (direction === 'up') nextIndex = currentIndex - columns;
            if (direction === 'down') nextIndex = currentIndex + columns;

            nextIndex = Math.max(0, Math.min(items.length - 1, nextIndex));
            setHighlightedOption(items[nextIndex], true);
        }

        function renderPosPage() {
            const filteredOptions = options.filter(optionMatchesFilters);
            const totalPages = Math.max(1, Math.ceil(filteredOptions.length / posPageSize));
            posPage = Math.max(1, Math.min(posPage, totalPages));
            const startIndex = (posPage - 1) * posPageSize;
            visiblePageOptions = filteredOptions.slice(startIndex, startIndex + posPageSize);
            const visibleSet = new Set(visiblePageOptions);

            options.forEach(function(option) {
                option.hidden = !visibleSet.has(option);
            });

            if (highlightedOption && !visibleSet.has(highlightedOption)) {
                setHighlightedOption(enabledVisibleOptions()[0] || null, false);
            }
            if (posEmptyPage) {
                posEmptyPage.hidden = filteredOptions.length > 0;
            }
            if (posPageInfo) {
                posPageInfo.textContent = filteredOptions.length === 0
                    ? 'No items'
                    : 'Page ' + posPage + ' of ' + totalPages + ' | ' + filteredOptions.length + ' item' + (filteredOptions.length === 1 ? '' : 's');
            }
            if (posPrevPageBtn) {
                posPrevPageBtn.disabled = posPage <= 1 || filteredOptions.length === 0;
            }
            if (posNextPageBtn) {
                posNextPageBtn.disabled = posPage >= totalPages || filteredOptions.length === 0;
            }
        }

        function filterOptions() {
            posPage = 1;
            renderPosPage();
        }

        function changePosPage(direction) {
            posPage += direction;
            renderPosPage();
        }

        function focusFirstVisibleOption() {
            const firstOption = enabledVisibleOptions()[0];
            if (firstOption) {
                setHighlightedOption(firstOption, true);
            }
        }

        function setActiveCategory(nextCategory) {
            activeCategory = nextCategory || 'all';
            categoryButtons.forEach(function(item) {
                const isActive = item.dataset.posCategory === activeCategory;
                item.classList.toggle('is-active', isActive);
                item.setAttribute('aria-pressed', isActive ? 'true' : 'false');
            });
            filterOptions();
        }

        function focusCategoryFilters() {
            const activeButton = categoryButtons.find(function(button) {
                return button.dataset.posCategory === activeCategory;
            }) || categoryButtons[0];
            if (activeButton) {
                activeButton.focus();
            }
        }

        function focusCartSection() {
            checkoutItems.focus({ preventScroll: true });
            checkoutItems.scrollIntoView({ block: 'nearest', inline: 'nearest' });
            const cartCards = Array.from(document.querySelectorAll('[data-cart-item-id]'));
            const selectedCard = cartCards.find(function(card) {
                return card.getAttribute('data-cart-item-id') === selectedCartProductId;
            }) || cartCards[0];
            if (selectedCard) {
                selectedCard.focus({ preventScroll: true });
                selectCartItem(selectedCard.getAttribute('data-cart-item-id'));
            }
        }

        function isTypingTarget(target) {
            return target && target.matches && target.matches('input, textarea, select, [contenteditable="true"]');
        }

        function closeTopModal() {
            const openDialogs = Array.from(document.querySelectorAll('dialog[open]'));
            const topDialog = openDialogs[openDialogs.length - 1];
            if (!topDialog) {
                return false;
            }

            topDialog.close();
            return true;
        }

        function addOptionToCart(option) {
            if (option.dataset.serviceSelector === 'true') {
                option.classList.toggle('is-open');
                option.setAttribute('aria-expanded', option.classList.contains('is-open') ? 'true' : 'false');
                return;
            }

            const productId = option.dataset.productId || '';
            const productName = option.dataset.name || 'Unknown Item';
            const price = parseFloat(option.dataset.price || '0');
            const stock = parseInt(option.dataset.stock || '0', 10);
            const type = option.dataset.type || 'service';

            if (!productId || option.classList.contains('is-disabled') || option.getAttribute('aria-disabled') === 'true') {
                return;
            }

            if (checkoutData[productId]) {
                const nextQuantity = checkoutData[productId].quantity + 1;
                if (type === 'item' && nextQuantity > stock) {
                    alert('Cart quantity exceeds available stock.');
                    return;
                }
                checkoutData[productId].quantity = nextQuantity;
            } else {
                checkoutData[productId] = {
                    name: productName,
                    quantity: 1,
                    price: price,
                    stock: stock,
                    type: type
                };
            }

            selectedCartProductId = String(productId);
            updateCheckoutDisplay();
            markOptionSelected(option);
        }

        function markOptionSelected(option) {
            const previousTimer = selectedOptionTimers.get(option);
            if (previousTimer) {
                window.clearTimeout(previousTimer);
            }

            option.classList.add('is-selected');
            selectedOptionTimers.set(option, window.setTimeout(function() {
                option.classList.remove('is-selected');
                selectedOptionTimers.delete(option);
            }, 450));
        }

        options.forEach(function(option) {
            option.addEventListener('focus', function() {
                setHighlightedOption(option, false);
            });
            option.addEventListener('click', function() {
                setHighlightedOption(option, false);
                addOptionToCart(option);
            });
            option.addEventListener('keydown', function(event) {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    addOptionToCart(option);
                }
            });
        });

        document.querySelectorAll('[data-service-choice]').forEach(function(choice) {
            choice.addEventListener('click', function(event) {
                event.preventDefault();
                event.stopPropagation();
                addOptionToCart(choice);
                const serviceCard = choice.closest('[data-service-selector]');
                if (serviceCard) {
                    serviceCard.classList.remove('is-open');
                    serviceCard.setAttribute('aria-expanded', 'false');
                    markOptionSelected(serviceCard);
                }
            });
        });

        clearCheckoutBtn.addEventListener('click', function() {
            requestCancelTransaction();
        });

        openPaymentBtn.addEventListener('click', function() {
            openPaymentReview();
        });

        paymentAmountInput.addEventListener('input', updatePaymentDisplay);
        paymentAmountInput.addEventListener('keydown', function(event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                openPaymentReview();
            }
        });
        keepCartBtn.addEventListener('click', function() {
            if (cancelTransactionModal && cancelTransactionModal.open) {
                cancelTransactionModal.close();
            }
        });
        confirmCancelTransactionBtn.addEventListener('click', function() {
            if (cancelTransactionModal && cancelTransactionModal.open) {
                cancelTransactionModal.close();
            }
            clearTransaction();
        });

        if (finalConfirmSaleBtn) {
            finalConfirmSaleBtn.addEventListener('click', openPaymentReview);
        }

        batchSaleForm.addEventListener('submit', function(event) {
            if (isSubmittingSale) {
                return;
            }
            if (!canSubmitSale()) {
                event.preventDefault();
                return;
            }
            markSaleSubmitting();
        });

        searchInput.addEventListener('input', filterOptions);
        if (posPrevPageBtn) {
            posPrevPageBtn.addEventListener('click', function() {
                changePosPage(-1);
            });
        }
        if (posNextPageBtn) {
            posNextPageBtn.addEventListener('click', function() {
                changePosPage(1);
            });
        }
        categoryButtons.forEach(function(button) {
            button.addEventListener('click', function() {
                setActiveCategory(button.dataset.posCategory || 'all');
            });
        });
        document.addEventListener('keydown', function(event) {
            if (event.defaultPrevented) {
                return;
            }

            const key = event.key;
            const lowerKey = key.toLowerCase();
            const isTyping = isTypingTarget(event.target);
            const hasOpenDialog = Boolean(document.querySelector('dialog[open]'));

            if (key === 'Escape' && closeTopModal()) {
                event.preventDefault();
                return;
            }
            if ((event.ctrlKey || event.metaKey) && lowerKey === 'p') {
                event.preventDefault();
                if (receiptModal && receiptModal.open) {
                    printReceiptOnly();
                }
                return;
            }
            if (hasOpenDialog) {
                return;
            }
            if ((event.ctrlKey || event.metaKey) && key === 'Enter') {
                event.preventDefault();
                openPaymentReview();
                return;
            }
            if ((event.ctrlKey || event.metaKey) && lowerKey === 'c' && !isTyping) {
                event.preventDefault();
                paymentAmountInput.focus();
                paymentAmountInput.select();
                return;
            }

            if (key === 'F2') {
                event.preventDefault();
                searchInput.focus();
                searchInput.select();
                return;
            }
            if (key === 'F3') {
                event.preventDefault();
                focusCategoryFilters();
                return;
            }
            if (key === 'F4') {
                event.preventDefault();
                openPaymentReview();
                return;
            }
            if (key === 'F5') {
                event.preventDefault();
                window.location.reload();
                return;
            }
            if (key === 'F6') {
                event.preventDefault();
                focusCartSection();
                return;
            }
            if (key === 'F7') {
                event.preventDefault();
                clearTransaction();
                return;
            }
            if (key === 'F8') {
                event.preventDefault();
                requestCancelTransaction();
                return;
            }
            if (key === 'F9') {
                event.preventDefault();
                window.location.href = 'sales-reports.php';
                return;
            }
            if (key === 'F10') {
                event.preventDefault();
                window.location.href = 'cashflow.php';
                return;
            }

            if (isTyping) {
                return;
            }
            if (event.target && event.target.matches && event.target.matches('button, a, summary')) {
                return;
            }

            if (event.altKey && lowerKey === 'a') {
                event.preventDefault();
                setActiveCategory('all');
                return;
            }
            if (event.altKey && lowerKey === 'p') {
                event.preventDefault();
                setActiveCategory('product');
                return;
            }
            if (event.altKey && lowerKey === 's') {
                event.preventDefault();
                setActiveCategory('service');
                return;
            }
            if (key === 'PageDown') {
                event.preventDefault();
                changePosPage(1);
                return;
            }
            if (key === 'PageUp') {
                event.preventDefault();
                changePosPage(-1);
                return;
            }
            if (key === 'ArrowLeft') {
                event.preventDefault();
                moveHighlightedOption('left');
                return;
            }
            if (key === 'ArrowRight') {
                event.preventDefault();
                moveHighlightedOption('right');
                return;
            }
            if (key === 'ArrowUp') {
                event.preventDefault();
                moveHighlightedOption('up');
                return;
            }
            if (key === 'ArrowDown') {
                event.preventDefault();
                moveHighlightedOption('down');
                return;
            }
            if (key === 'Enter') {
                if (highlightedOption) {
                    event.preventDefault();
                    addOptionToCart(highlightedOption);
                }
                return;
            }
            if (key === 'Delete') {
                event.preventDefault();
                if (selectedCartProductId) {
                    removeCartItem(selectedCartProductId);
                    updateCheckoutDisplay();
                }
                return;
            }
            if (key === '+' || key === '=') {
                event.preventDefault();
                adjustSelectedCartQuantity(1);
                return;
            }
            if (key === '-' || key === '_') {
                event.preventDefault();
                adjustSelectedCartQuantity(-1);
            }
        });
        if (receiptModal && receiptModal.dataset.autoOpen === 'true' && typeof receiptModal.showModal === 'function') {
            receiptModal.showModal();
        }
        if (closeReceiptBtn) {
            closeReceiptBtn.addEventListener('click', function() {
                if (receiptModal && receiptModal.open) {
                    receiptModal.close();
                }
            });
        }
        if (printReceiptBtn) {
            printReceiptBtn.addEventListener('click', printReceiptOnly);
        }
        if (openShortcutsBtn) {
            openShortcutsBtn.addEventListener('click', function() {
                if (shortcutsModal && typeof shortcutsModal.showModal === 'function') {
                    shortcutsModal.showModal();
                }
            });
        }
        if (closeShortcutsBtn) {
            closeShortcutsBtn.addEventListener('click', function() {
                if (shortcutsModal && shortcutsModal.open) {
                    shortcutsModal.close();
                }
            });
        }
        window.addEventListener('beforeprint', function() {
            if (receiptModal && receiptModal.open) {
                prepareReceiptPrint();
            }
        });
        window.addEventListener('afterprint', cleanupReceiptPrint);
        filterOptions();
        updateCheckoutDisplay();
        setHighlightedOption(enabledVisibleOptions()[0] || null, false);
        if (!receiptModal || !receiptModal.open) {
            searchInput.focus();
            searchInput.select();
        }
    })();
</script>

<?php render_footer();
