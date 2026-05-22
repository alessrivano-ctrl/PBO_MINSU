<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';

$user = require_login($pdo);
$canManageInventoryPricing = user_can($user, 'manage_inventory_pricing');
$canAddProducts = $canManageInventoryPricing || user_can($user, 'record_operations');

function inventory_section_href(string $section): string
{
    return 'products.php?section=' . rawurlencode(in_array($section, ['products', 'services', 'stock'], true) ? $section : 'products');
}

function inventory_product_category_options(PDO $pdo, string $section): array
{
    $options = [];
    foreach (inventory_canonical_category_values($section) as $value) {
        $options[$value] = product_category_label($value);
    }

    $stmt = $pdo->prepare('SELECT name FROM inventory_categories WHERE section = :section AND is_active = 1 ORDER BY name');
    $stmt->execute(['section' => $section === 'services' ? 'services' : 'products']);
    $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
    foreach ($rows as $categoryName) {
        $label = trim((string) $categoryName);
        if ($label !== '') {
            $options['custom:' . $label] = $label;
        }
    }

    return $options;
}

function inventory_canonical_category_values(string $section): array
{
    if ($section === 'services') {
        return ['id_services', 'printing', 'photocopy'];
    }

    return ['school_supply', 'id_supplies'];
}

function inventory_category_allowed_for_section(string $category, ?string $categoryName, string $section): bool
{
    if ($category === 'other' && trim((string) $categoryName) !== '') {
        return true;
    }

    if ($section === 'services') {
        return in_array($category, inventory_canonical_category_values('services'), true);
    }

    return in_array($category, inventory_canonical_category_values('products'), true);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postSection = (string) ($_POST['inventory_section'] ?? 'products');
    $inventoryRedirectHref = inventory_section_href($postSection);
    $token = (string) ($_POST['csrf_token'] ?? '');
    if (!verify_csrf($token)) {
        set_flash('error', 'Invalid form token.');
        redirect($inventoryRedirectHref);
    }

    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'add_product') {
        if (!$canAddProducts) {
            set_flash('error', 'Your account does not have permission to add products.');
            redirect($inventoryRedirectHref);
        }

        $name = trim((string) ($_POST['name'] ?? ''));
        $sku = trim((string) ($_POST['sku'] ?? ''));
        $unit = trim((string) ($_POST['unit'] ?? ''));
        $categoryData = normalize_product_category_input((string) ($_POST['category'] ?? ''));
        $category = (string) $categoryData['category'];
        $categoryName = $categoryData['category_name'];
        $categoryLabel = product_category_label($category, $categoryName);
        $groupInput = (string) ($_POST['product_group'] ?? 'product');
        $requestedType = (string) ($_POST['type'] ?? 'item');
        $type = product_type_for_category($category, $requestedType);
        if ($groupInput === 'service') {
            $type = 'service';
        }
        $productGroup = product_group_for_type($category, $type, $groupInput);
        $cost = (float) ($_POST['cost_price'] ?? 0);
        $selling = (float) ($_POST['selling_price'] ?? 0);
        $initialQuantity = (int) ($_POST['initial_quantity'] ?? 0);
        $threshold = (int) ($_POST['low_stock_threshold'] ?? 5);
        $isActive = 1;
        $productType = (string) ($_POST['product_type'] ?? 'non_consumable') === 'consumable' ? 'consumable' : 'non_consumable';

        $validCategories = array_keys(product_category_options());
        $validTypes = ['item', 'service'];
        $validGroups = ['product', 'igp', 'service'];

        if ($name === '') {
            set_flash('error', 'Item name is required.');
            redirect($inventoryRedirectHref);
        }
        if (!in_array($category, $validCategories, true) || !in_array($type, $validTypes, true) || !in_array($productGroup, $validGroups, true) || !inventory_category_allowed_for_section($category, $categoryName, $groupInput === 'service' ? 'services' : 'products')) {
            set_flash('error', 'Invalid category or type.');
            redirect($inventoryRedirectHref);
        }

        if ($cost < 0 || $selling < 0 || $initialQuantity < 0 || $threshold < 0) {
            set_flash('error', 'Numeric fields cannot be negative.');
            redirect($inventoryRedirectHref);
        }

        if ($type === 'service') {
            $initialQuantity = 0;
            $threshold = 0;
            $productType = 'non_consumable';
        }
        $isConsumableProduct = $type === 'item' && $productType === 'consumable';
        if ($type === 'item' && $initialQuantity > 0 && $cost <= 0) {
            set_flash('error', 'Cost price is required when initial quantity is greater than zero.');
            redirect($inventoryRedirectHref);
        }
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare('INSERT INTO products (sku, name, unit, category, category_name, product_group, type, product_type, cost_price, selling_price, stock_qty, low_stock_threshold, is_consumable, requires_expiration, is_active) VALUES (:sku, :name, :unit, :category, :category_name, :product_group, :type, :product_type, :cost, :selling, 0, :threshold, :is_consumable, :requires_expiration, :is_active)');
            $stmt->execute([
                'sku' => $sku !== '' ? $sku : null,
                'name' => $name,
                'unit' => $unit !== '' ? substr($unit, 0, 40) : null,
                'category' => $category,
                'category_name' => $categoryName,
                'product_group' => $productGroup,
                'type' => $type,
                'product_type' => $productType,
                'cost' => $cost,
                'selling' => $selling,
                'threshold' => $threshold,
                'is_consumable' => $isConsumableProduct ? 1 : 0,
                'requires_expiration' => $isConsumableProduct ? 1 : 0,
                'is_active' => $isActive,
            ]);
            $productId = (int) $pdo->lastInsertId();
            record_product_price_history($pdo, $productId, $cost, $selling, $user, 'created', 'Initial item price');
            if ($type === 'item' && $initialQuantity > 0) {
                inventory_stock_in($pdo, $productId, $initialQuantity, $cost, date('Y-m-d H:i:s'), null, 'Initial quantity from Add Product', $user, 'opening_balance');
            }

            $pdo->commit();
            audit_log($pdo, $user, 'create', 'inventory', 'product', $productId, [
                'name' => $name,
                'unit' => $unit,
                'category' => $categoryLabel,
                'product_group' => $productGroup,
                'type' => $type,
                'product_type' => $productType,
                'initial_quantity' => $initialQuantity,
                'status' => $isActive === 1 ? 'active' : 'inactive',
            ]);
            set_flash('success', 'Item added successfully.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            log_system_issue($pdo, 'error', 'Could not add product.', ['error' => $e->getMessage(), 'name' => $name], $user);
            set_flash('error', 'Could not add product. Item reference might already exist.');
        }

        redirect($inventoryRedirectHref);
    }

    if ($action === 'add_batch') {
        $productId = (int) ($_POST['product_id'] ?? 0);
        $quantity = (int) ($_POST['quantity_received'] ?? 0);
        $unitCost = (float) ($_POST['unit_cost'] ?? 0);
        $receivedDate = normalize_datetime_input((string) ($_POST['received_date'] ?? ''));
        $expirationDate = trim((string) ($_POST['expiration_date'] ?? ''));
        $supplier = trim((string) ($_POST['supplier'] ?? ''));
        $referenceNo = trim((string) ($_POST['reference_no'] ?? ''));
        $notes = trim((string) ($_POST['notes'] ?? ''));

        if ($productId <= 0 || $quantity <= 0 || $unitCost < 0) {
            set_flash('error', 'Valid batch details are required.');
            redirect($inventoryRedirectHref);
        }
        if ($expirationDate === '') {
            set_flash('error', 'Expiration date is required for consumable stock.');
            redirect($inventoryRedirectHref);
        }

        try {
            $pdo->beginTransaction();
            inventory_stock_in($pdo, $productId, $quantity, $unitCost, $receivedDate, $referenceNo, $notes, $user, 'stock_in', $expirationDate !== '' ? $expirationDate : null, $supplier);
            $pdo->commit();
            set_flash('success', 'Batch added.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            set_flash('error', 'Could not add batch: ' . $e->getMessage());
        }

        redirect($inventoryRedirectHref);
    }

    if ($action === 'edit_batch') {
        $batchId = (int) ($_POST['batch_id'] ?? 0);
        $batchCode = trim((string) ($_POST['batch_code'] ?? ''));
        $expirationDate = trim((string) ($_POST['expiration_date'] ?? ''));
        $supplier = trim((string) ($_POST['supplier'] ?? ''));
        $receivedDate = normalize_datetime_input((string) ($_POST['received_date'] ?? ''));

        if ($batchId <= 0 || $receivedDate === '') {
            set_flash('error', 'Valid batch details are required.');
            redirect($inventoryRedirectHref);
        }

        $stmt = $pdo->prepare('UPDATE inventory_stock_batches
            SET batch_code = :batch_code,
                expiration_date = :expiration_date,
                supplier = :supplier,
                received_date = :received_date
            WHERE id = :id');
        $stmt->execute([
            'id' => $batchId,
            'batch_code' => $batchCode !== '' ? $batchCode : null,
            'expiration_date' => $expirationDate !== '' ? $expirationDate : null,
            'supplier' => $supplier !== '' ? $supplier : null,
            'received_date' => $receivedDate,
        ]);
        set_flash('success', 'Batch updated.');
        redirect($inventoryRedirectHref);
    }

    if ($action === 'dispose_expired_batch') {
        $batchId = (int) ($_POST['batch_id'] ?? 0);
        $notes = trim((string) ($_POST['notes'] ?? ''));
        if ($batchId <= 0) {
            set_flash('error', 'Invalid batch disposal request.');
            redirect($inventoryRedirectHref);
        }

        try {
            $pdo->beginTransaction();
            $batchStmt = $pdo->prepare('SELECT id, product_id, quantity_remaining, expiration_date
                FROM inventory_stock_batches
                WHERE id = :id
                FOR UPDATE');
            $batchStmt->execute(['id' => $batchId]);
            $batch = $batchStmt->fetch();
            if (!$batch || inventory_batch_status($batch['expiration_date']) !== 'Expired' || (int) $batch['quantity_remaining'] <= 0) {
                throw new RuntimeException('Only expired batches with remaining quantity can be disposed.');
            }

            $quantityRemaining = (int) $batch['quantity_remaining'];
            $updateBatch = $pdo->prepare('UPDATE inventory_stock_batches SET quantity_remaining = 0 WHERE id = :id');
            $updateBatch->execute(['id' => $batchId]);
            $insertMovement = $pdo->prepare('INSERT INTO inventory_stock_movements (product_id, batch_id, movement_date, movement_type, quantity_change, previous_quantity, new_quantity, reference_no, notes, created_by)
                VALUES (:product_id, :batch_id, NOW(), "disposal", :quantity_change, :previous_quantity, 0, NULL, :notes, :created_by)');
            $insertMovement->execute([
                'product_id' => (int) $batch['product_id'],
                'batch_id' => $batchId,
                'quantity_change' => -$quantityRemaining,
                'previous_quantity' => $quantityRemaining,
                'notes' => $notes !== '' ? $notes : 'Disposed expired batch',
                'created_by' => (int) $user['id'],
            ]);
            $updateProduct = $pdo->prepare('UPDATE products SET stock_qty = stock_qty - :quantity WHERE id = :id AND stock_qty >= :quantity');
            $updateProduct->execute([
                'quantity' => $quantityRemaining,
                'id' => (int) $batch['product_id'],
            ]);
            inventory_recalculate_product_cost($pdo, (int) $batch['product_id'], $user, 'disposal');
            $pdo->commit();
            set_flash('success', 'Expired batch disposed.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            set_flash('error', 'Could not dispose batch: ' . $e->getMessage());
        }

        redirect($inventoryRedirectHref);
    }

    if ($action === 'stock_movement' || $action === 'adjust_stock') {
        $productId = (int) ($_POST['product_id'] ?? 0);
        $direction = (string) ($_POST['direction'] ?? 'in');
        $quantity = abs((int) ($_POST['quantity'] ?? ($_POST['adjustment'] ?? 0)));
        $unitCost = (float) ($_POST['unit_cost'] ?? 0);
        $movementDate = normalize_datetime_input((string) ($_POST['movement_date'] ?? ''));
        $movementReason = (string) ($_POST['movement_reason'] ?? 'stock_out');
        $referenceNo = trim((string) ($_POST['reference_no'] ?? ''));
        $usageContext = trim((string) ($_POST['usage_context'] ?? ''));
        $notes = trim((string) ($_POST['notes'] ?? ''));
        $expirationDate = trim((string) ($_POST['expiration_date'] ?? ''));
        $supplier = trim((string) ($_POST['supplier'] ?? ''));
        $postCashOut = isset($_POST['post_cash_out']) && $_POST['post_cash_out'] === '1';

        $stockOutType = match ($movementReason) {
            'damaged' => 'damaged',
            'expired' => 'expired',
            'disposal' => 'disposal',
            'consumption' => 'consumption',
            'refill' => 'refill',
            default => 'stock_out',
        };

        if ($usageContext !== '') {
            $notes = trim('Context: ' . $usageContext . ($notes !== '' ? ' | ' . $notes : ''));
        }

        if ($productId <= 0 || $quantity <= 0 || !in_array($direction, ['in', 'out'], true) || $unitCost < 0) {
            set_flash('error', 'Invalid stock adjustment.');
            redirect($inventoryRedirectHref);
        }

        try {
            $pdo->beginTransaction();

            $productStmt = $pdo->prepare('SELECT name, cost_price, selling_price, product_type, requires_expiration FROM products WHERE id = :id AND type = "item" AND is_active = 1');
            $productStmt->execute(['id' => $productId]);
            $product = $productStmt->fetch();
            if (!$product) {
                throw new RuntimeException('Product is not an active stock item.');
            }

            $effectiveCost = $unitCost;
            if ($direction === 'in') {
                $effectiveCost = $unitCost > 0 ? $unitCost : (float) $product['cost_price'];
                $costError = validate_inventory_unit_cost($effectiveCost, (float) $product['cost_price'], (float) $product['selling_price']);
                if ($costError !== null) {
                    throw new RuntimeException($costError);
                }
                if (($product['product_type'] ?? 'non_consumable') === 'consumable' && $expirationDate === '') {
                    throw new RuntimeException('Expiration date is required for consumable stock.');
                }
                if ((int) ($product['requires_expiration'] ?? 0) === 1 && $expirationDate === '') {
                    throw new RuntimeException('Expiration date is required for this item.');
                }
            }

            if (user_requires_admin_approval($user)) {
                create_approval_request($pdo, (int) $user['id'], 'inventory', 'inventory_stock_movement', 'product', (string) $productId, null, [
                    'product_id' => $productId,
                    'direction' => $direction,
                    'quantity' => $quantity,
                    'unit_cost' => $effectiveCost,
                    'movement_date' => $movementDate,
                    'movement_reason' => $stockOutType,
                    'reference_no' => $referenceNo,
                    'notes' => $notes,
                    'expiration_date' => $expirationDate,
                    'post_cash_out' => $postCashOut,
                ]);
                $pdo->commit();
                set_flash('success', 'Stock movement request submitted for admin approval.');
                redirect($inventoryRedirectHref);
            }

            if ($direction === 'in') {
                inventory_stock_in($pdo, $productId, $quantity, $effectiveCost, $movementDate, $referenceNo, $notes, $user, 'stock_in', $expirationDate !== '' ? $expirationDate : null, $supplier);

                if ($postCashOut && $effectiveCost > 0) {
                    $cashStmt = $pdo->prepare('INSERT INTO cash_transactions (txn_date, direction, source_module, amount, or_number, description, created_by)
                        VALUES (:txn_date, "out", "inventory", :amount, :or_number, :description, :created_by)');
                    $cashStmt->execute([
                        'txn_date' => $movementDate,
                        'amount' => $effectiveCost * $quantity,
                        'or_number' => $referenceNo !== '' ? $referenceNo : null,
                        'description' => 'Stock purchase: ' . $product['name'],
                        'created_by' => (int) $user['id'],
                    ]);
                }
            } else {
                inventory_fifo_issue($pdo, $productId, $quantity, $movementDate, $stockOutType, $referenceNo, $notes !== '' ? $notes : inventory_movement_label($stockOutType), $user);
            }

            $pdo->commit();
            audit_log($pdo, $user, 'stock_movement', 'inventory', 'product', $productId, [
                'direction' => $direction,
                'movement_type' => $direction === 'out' ? $stockOutType : 'stock_in',
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
            ]);
            set_flash('success', 'Stock movement saved.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            log_system_issue($pdo, 'error', 'Stock movement failed.', ['error' => $e->getMessage(), 'product_id' => $productId], $user);
            set_flash('error', 'Stock movement failed: ' . $e->getMessage());
        }

        redirect($inventoryRedirectHref);
    }

    if ($action === 'update_product') {
        $productId = (int) ($_POST['product_id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        $sku = trim((string) ($_POST['sku'] ?? ''));
        $categoryData = normalize_product_category_input((string) ($_POST['category'] ?? ''));
        $category = (string) $categoryData['category'];
        $categoryName = $categoryData['category_name'];
        $categoryLabel = product_category_label($category, $categoryName);
        $groupInput = (string) ($_POST['product_group'] ?? 'product');
        $requestedType = (string) ($_POST['type'] ?? 'item');
        $type = product_type_for_category($category, $requestedType);
        if ($groupInput === 'service') {
            $type = 'service';
        }
        $productGroup = product_group_for_type($category, $type, $groupInput);
        $cost = (float) ($_POST['cost_price'] ?? 0);
        $selling = (float) ($_POST['selling_price'] ?? 0);
        $threshold = (int) ($_POST['low_stock_threshold'] ?? 5);
        $productType = (string) ($_POST['product_type'] ?? 'non_consumable') === 'consumable' ? 'consumable' : 'non_consumable';

        $validCategories = array_keys(product_category_options());
        $validTypes = ['item', 'service'];
        $validGroups = ['product', 'igp', 'service'];

        if ($productId <= 0 || $name === '' || !in_array($category, $validCategories, true) || !in_array($type, $validTypes, true) || !in_array($productGroup, $validGroups, true) || !inventory_category_allowed_for_section($category, $categoryName, $requestedType === 'service' ? 'services' : 'products') || $cost < 0 || $selling < 0 || $threshold < 0) {
            set_flash('error', 'Valid product details are required.');
            redirect($inventoryRedirectHref);
        }

        $existingProductStmt = $pdo->prepare('SELECT * FROM products WHERE id = :id AND is_active = 1');
        $existingProductStmt->execute(['id' => $productId]);
        $existingProduct = $existingProductStmt->fetch();

        if (!$existingProduct) {
            set_flash('error', 'Product not found.');
            redirect($inventoryRedirectHref);
        }

        if (!$canManageInventoryPricing) {
            if (round((float) $existingProduct['cost_price'], 2) !== round($cost, 2) || round((float) $existingProduct['selling_price'], 2) !== round($selling, 2)) {
                set_flash('error', 'Only authorized users can change inventory cost or selling price.');
                redirect($inventoryRedirectHref);
            }
        }

        if ($canManageInventoryPricing) {
            $costError = $cost > 0 ? validate_inventory_unit_cost($cost, (float) $existingProduct['cost_price'], $selling) : null;
            if ($costError !== null) {
                set_flash('error', $costError);
                redirect($inventoryRedirectHref);
            }
        }

        if ($type === 'service') {
            $productType = 'non_consumable';
            if ((int) ($existingProduct['stock_qty'] ?? 0) > 0) {
                set_flash('error', 'Move item stock out before converting it to a service.');
                redirect($inventoryRedirectHref);
            }
        }
        $isConsumableProduct = $type === 'item' && $productType === 'consumable';
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('UPDATE products
                SET sku = :sku, name = :name, category = :category, category_name = :category_name, product_group = :product_group, type = :type, product_type = :product_type, cost_price = :cost, selling_price = :selling, low_stock_threshold = :threshold, is_consumable = :is_consumable, requires_expiration = :requires_expiration
                WHERE id = :id AND is_active = 1');
            $stmt->execute([
                'id' => $productId,
                'sku' => $sku !== '' ? $sku : null,
                'name' => $name,
                'category' => $category,
                'category_name' => $categoryName,
                'product_group' => $productGroup,
                'type' => $type,
                'product_type' => $productType,
                'cost' => $cost,
                'selling' => $selling,
                'threshold' => $threshold,
                'is_consumable' => $isConsumableProduct ? 1 : 0,
                'requires_expiration' => $isConsumableProduct ? 1 : 0,
            ]);

            if (!$existingProduct || (float) $existingProduct['cost_price'] !== $cost || (float) $existingProduct['selling_price'] !== $selling) {
                record_product_price_history($pdo, $productId, $cost, $selling, $user, 'updated', 'Price updated from inventory form');
            }

            $pdo->commit();
            audit_log($pdo, $user, 'update', 'inventory', 'product', $productId, ['name' => $name]);
            set_flash('success', 'Item updated successfully.');
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            log_system_issue($pdo, 'error', 'Could not update product.', ['error' => $e->getMessage(), 'product_id' => $productId], $user);
            set_flash('error', 'Could not update item. Item reference might already exist.');
        }

        redirect($inventoryRedirectHref);
    }

    if ($action === 'archive_product') {
        require_permission($user, 'archive_records', 'products.php');
        $productId = (int) ($_POST['product_id'] ?? 0);
        if ($productId <= 0) {
            set_flash('error', 'Invalid product archive request.');
            redirect($inventoryRedirectHref);
        }

        $productStmt = $pdo->prepare('SELECT id, sku, name, unit, category, category_name, product_group, type, cost_price, selling_price, stock_qty, low_stock_threshold, is_consumable, requires_expiration, is_active, created_at
            FROM products
            WHERE id = :id AND is_active = 1');
        $productStmt->execute(['id' => $productId]);
        $product = $productStmt->fetch();
        if (!$product) {
            set_flash('error', 'Product not found or already archived.');
            redirect($inventoryRedirectHref);
        }

        try {
            $pdo->beginTransaction();

            $archiveStmt = $pdo->prepare('INSERT INTO archived_records (source_table, source_id, record_data, archived_by)
                VALUES (:source_table, :source_id, :record_data, :archived_by)');
            $archiveStmt->execute([
                'source_table' => 'products',
                'source_id' => (string) $productId,
                'record_data' => json_encode($product, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'archived_by' => (int) $user['id'],
            ]);

            $deleteStmt = $pdo->prepare('UPDATE products SET is_active = 0 WHERE id = :id');
            $deleteStmt->execute(['id' => $productId]);

            $pdo->commit();
            audit_log($pdo, $user, 'archive', 'inventory', 'product', $productId, ['name' => $product['name']]);
            set_flash('success', 'Product archived. Historical records were preserved.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            log_system_issue($pdo, 'error', 'Failed to archive product.', ['error' => $e->getMessage(), 'product_id' => $productId], $user);
            set_flash('error', 'Failed to archive product.');
        }

        redirect($inventoryRedirectHref);
    }
}

$q = trim((string) ($_GET['q'] ?? ''));
$inventorySection = (string) ($_GET['section'] ?? 'products');
if (!in_array($inventorySection, ['products', 'services', 'stock'], true)) {
    $inventorySection = 'products';
}
$categoryFilter = (string) ($_GET['category'] ?? 'all');
$categoryFilterOptions = inventory_product_category_options($pdo, $inventorySection);
$productCategoryOptions = inventory_product_category_options($pdo, 'products');
$serviceCategoryOptions = inventory_product_category_options($pdo, 'services');
$sort = (string) ($_GET['sort'] ?? 'name');
$order = strtolower((string) ($_GET['order'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';
$inventoryView = (string) ($_GET['view'] ?? 'inventory');
$selectedProductId = max(0, (int) ($_GET['product_id'] ?? 0));
if (!in_array($inventoryView, ['inventory', 'overview', 'low_stock', 'batches', 'ledger'], true)) {
    $inventoryView = 'inventory';
}
if ($inventorySection === 'stock' && in_array($inventoryView, ['inventory', 'overview'], true)) {
    $inventoryView = 'low_stock';
}
if ($inventorySection !== 'stock' && !in_array($inventoryView, ['inventory'], true)) {
    $inventoryView = 'inventory';
}

$allowedSort = [
    'name' => 'name',
    'category' => 'category',
    'stock_qty' => 'stock_qty',
    'selling_price' => 'selling_price',
    'cost_price' => 'cost_price',
];

$sortColumn = $allowedSort[$sort] ?? 'name';

$where = ['is_active = 1'];
$params = [];

if ($q !== '') {
    $where[] = 'name LIKE :q';
    $params['q'] = prefix_search_param($q);
}

if ($inventorySection === 'products') {
    $where[] = 'type = "item"';
} elseif ($inventorySection === 'services') {
    $where[] = 'type = "service"';
}

if ($categoryFilter !== 'all' && isset($categoryFilterOptions[$categoryFilter])) {
    if (str_starts_with($categoryFilter, 'custom:')) {
        $where[] = 'category_name = :category_name';
        $params['category_name'] = substr($categoryFilter, 7);
    } else {
        $where[] = 'category = :category AND (category_name IS NULL OR category_name = "")';
        $params['category'] = $categoryFilter;
    }
}

if ($inventoryView === 'low_stock') {
    $where[] = 'type = "item" AND COALESCE((SELECT SUM(quantity_remaining) FROM inventory_stock_batches b WHERE b.product_id = products.id AND b.quantity_remaining > 0 AND (b.expiration_date IS NULL OR b.expiration_date >= CURDATE())), 0) <= low_stock_threshold';
}

if (!isset($categoryFilterOptions[$categoryFilter]) && $categoryFilter !== 'all') {
    $categoryFilter = 'all';
}

$countSql = 'SELECT COUNT(*)
        FROM products
        WHERE ' . implode(' AND ', $where);
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$pagination = pagination_meta((int) $countStmt->fetchColumn(), page_param(), 10);

$sql = 'SELECT id, sku, name, unit, category, category_name, product_group, type, product_type, cost_price, selling_price, stock_qty, sellable_stock_qty, low_stock_threshold, is_consumable, requires_expiration, updated_at, unit_profit
        FROM (
            SELECT id, sku, name, unit, category, category_name, product_group, type, product_type, cost_price, selling_price, stock_qty,
                COALESCE((SELECT SUM(quantity_remaining) FROM inventory_stock_batches b WHERE b.product_id = products.id AND b.quantity_remaining > 0 AND (b.expiration_date IS NULL OR b.expiration_date >= CURDATE())), 0) AS sellable_stock_qty,
                low_stock_threshold, is_consumable, requires_expiration, updated_at, (selling_price - cost_price) AS unit_profit,
                ROW_NUMBER() OVER (ORDER BY ' . $sortColumn . ' ' . $order . ', id ASC) AS row_num
            FROM products
            WHERE ' . implode(' AND ', $where) . '
        ) ranked_products
        WHERE row_num BETWEEN :first_row AND :last_row
        ORDER BY row_num';

$stmt = $pdo->prepare($sql);
foreach ($params as $key => $value) {
    $stmt->bindValue(':' . ltrim((string) $key, ':'), $value);
}
[$firstRow, $lastRow] = pagination_row_bounds($pagination);
$stmt->bindValue(':first_row', $firstRow, PDO::PARAM_INT);
$stmt->bindValue(':last_row', $lastRow, PDO::PARAM_INT);
$stmt->execute();
$products = $stmt->fetchAll();

$lowStockItems = $pdo->query("SELECT
        p.id,
        p.name,
        COALESCE(SUM(CASE WHEN b.quantity_remaining > 0 AND (b.expiration_date IS NULL OR b.expiration_date >= CURDATE()) THEN b.quantity_remaining ELSE 0 END), 0) AS stock_qty
    FROM products p
    LEFT JOIN inventory_stock_batches b ON b.product_id = p.id
    WHERE p.type = 'item' AND p.is_active = 1
    GROUP BY p.id, p.name, p.low_stock_threshold
    HAVING stock_qty <= p.low_stock_threshold
    ORDER BY stock_qty ASC")->fetchAll();
$expiringSoonCount = (int) $pdo->query("SELECT COUNT(*)
    FROM inventory_stock_batches b
    INNER JOIN products p ON p.id = b.product_id
    WHERE p.is_active = 1
        AND p.type = 'item'
        AND b.quantity_remaining > 0
        AND b.expiration_date IS NOT NULL
        AND b.expiration_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)")->fetchColumn();
$expiredBatchCount = (int) $pdo->query("SELECT COUNT(*)
    FROM inventory_stock_batches b
    INNER JOIN products p ON p.id = b.product_id
    WHERE p.is_active = 1
        AND p.type = 'item'
        AND b.quantity_remaining > 0
        AND b.expiration_date IS NOT NULL
        AND b.expiration_date < CURDATE()")->fetchColumn();
$categoryAnalytics = $pdo->query('SELECT category, product_count AS item_count, stock_units, stock_value
    FROM v_inventory_category_summary
    ORDER BY category')->fetchAll();
$totalInventoryRecords = array_sum(array_map(static fn (array $row): int => (int) $row['item_count'], $categoryAnalytics));
$totalStockValue = array_sum(array_map(static fn (array $row): float => (float) $row['stock_value'], $categoryAnalytics));
$lowStockCount = count($lowStockItems);
$stockItemOptions = $pdo->query("SELECT id, name, sku, category, category_name, product_type, cost_price, stock_qty,
        COALESCE((SELECT SUM(quantity_remaining) FROM inventory_stock_batches b WHERE b.product_id = products.id AND b.quantity_remaining > 0 AND (b.expiration_date IS NULL OR b.expiration_date >= CURDATE())), stock_qty) AS sellable_stock_qty,
        is_consumable, requires_expiration
    FROM products
    WHERE type = 'item' AND is_active = 1 AND product_type = 'consumable'
    ORDER BY name")->fetchAll();
$batchCount = (int) $pdo->query('SELECT COUNT(*)
    FROM inventory_stock_batches b
    INNER JOIN products p ON p.id = b.product_id
    WHERE p.is_active = 1
        AND p.type = "item"
        AND b.expiration_date IS NOT NULL
        AND b.quantity_received > 0')->fetchColumn();
$batchesPagination = pagination_meta($batchCount, page_param(), 10);
$batchesSql = 'SELECT id, received_date, expiration_date, supplier, quantity_received, quantity_remaining, product_name, sku
    FROM (
        SELECT b.id, b.received_date, b.expiration_date, b.supplier, b.quantity_received, b.quantity_remaining, p.name AS product_name, p.sku,
            ROW_NUMBER() OVER (ORDER BY b.expiration_date IS NULL ASC, b.expiration_date ASC, b.received_date DESC, b.id DESC) AS row_num
        FROM inventory_stock_batches b
        INNER JOIN products p ON p.id = b.product_id
        WHERE p.is_active = 1
            AND p.type = "item"
            AND b.expiration_date IS NOT NULL
    ) ranked_expiring
    WHERE row_num BETWEEN :first_row AND :last_row
    ORDER BY row_num';
$batchesStmt = $pdo->prepare($batchesSql);
[$batchFirstRow, $batchLastRow] = pagination_row_bounds($batchesPagination);
$batchesStmt->bindValue(':first_row', $batchFirstRow, PDO::PARAM_INT);
$batchesStmt->bindValue(':last_row', $batchLastRow, PDO::PARAM_INT);
$batchesStmt->execute();
$batches = $batchesStmt->fetchAll();

$stockMovementsCount = (int) $pdo->query('SELECT COUNT(*)
    FROM inventory_stock_movements m
    INNER JOIN products p ON p.id = m.product_id')->fetchColumn();
$stockMovementsPagination = pagination_meta($stockMovementsCount, page_param(), 10);
$stockMovementsSql = 'SELECT movement_date, movement_type, quantity_change, reference_no, user_name, notes, product_name
    FROM (
        SELECT m.movement_date, m.movement_type, m.quantity_change, m.reference_no,
            COALESCE(u.full_name, u.username, "-") AS user_name, m.notes, p.name AS product_name,
            ROW_NUMBER() OVER (ORDER BY m.movement_date DESC, m.id DESC) AS row_num
        FROM inventory_stock_movements m
        INNER JOIN products p ON p.id = m.product_id
        LEFT JOIN users u ON u.id = m.created_by
    ) ranked_movements
    WHERE row_num BETWEEN :first_row AND :last_row
    ORDER BY row_num';
$stockMovementsStmt = $pdo->prepare($stockMovementsSql);
[$movementFirstRow, $movementLastRow] = pagination_row_bounds($stockMovementsPagination);
$stockMovementsStmt->bindValue(':first_row', $movementFirstRow, PDO::PARAM_INT);
$stockMovementsStmt->bindValue(':last_row', $movementLastRow, PDO::PARAM_INT);
$stockMovementsStmt->execute();
$stockMovements = $stockMovementsStmt->fetchAll();


$selectedProduct = null;
$selectedStockInHistory = [];
$selectedStockOutHistory = [];
$selectedUnitCostHistory = [];
$selectedStockSummary = ['stock_in_qty' => 0, 'stock_out_qty' => 0];

if ($selectedProductId > 0) {
    $selectedStmt = $pdo->prepare('SELECT id, sku, name, unit, category, category_name, product_group, type, product_type, cost_price, selling_price, stock_qty, low_stock_threshold, is_consumable, requires_expiration, is_active, created_at
        FROM products
        WHERE id = :id AND is_active = 1');
    $selectedStmt->execute(['id' => $selectedProductId]);
    $selectedProduct = $selectedStmt->fetch() ?: null;

    if ($selectedProduct) {
        $summaryStmt = $pdo->prepare('SELECT
                COALESCE(SUM(CASE WHEN quantity_change > 0 THEN quantity_change ELSE 0 END), 0) AS stock_in_qty,
                COALESCE(SUM(CASE WHEN quantity_change < 0 THEN ABS(quantity_change) ELSE 0 END), 0) AS stock_out_qty
            FROM inventory_stock_movements
            WHERE product_id = :product_id');
        $summaryStmt->execute(['product_id' => $selectedProductId]);
        $selectedStockSummary = $summaryStmt->fetch() ?: $selectedStockSummary;

        $stockInStmt = $pdo->prepare('SELECT movement_date, movement_type, quantity_change, reference_no, notes
            FROM inventory_stock_movements
            WHERE product_id = :product_id
            ORDER BY movement_date DESC, id DESC
            LIMIT 10');
        $stockInStmt->execute(['product_id' => $selectedProductId]);
        $selectedStockInHistory = $stockInStmt->fetchAll();

    }
}

$modalProducts = $products;
if ($selectedProduct) {
    $modalProductIds = array_map(static fn (array $product): int => (int) $product['id'], $modalProducts);
    if (!in_array((int) $selectedProduct['id'], $modalProductIds, true)) {
        $modalProducts[] = $selectedProduct;
    }
}

function inventory_status_badge(array $product): string
{
    if ((string) $product['type'] === 'service') {
        return '<span class="status-pill returned">Service</span>';
    }

    $stock = (int) ($product['sellable_stock_qty'] ?? $product['stock_qty']);
    if ($stock <= 0) {
        return '<span class="status-pill rejected">Out of Stock</span>';
    }

    if ($stock <= (int) $product['low_stock_threshold']) {
        return '<span class="status-pill submitted">Low Stock</span>';
    }

    return '<span class="status-pill active">In Stock</span>';
}

function movement_status_badge(string $movementType): string
{
    $label = inventory_movement_label($movementType);
    $class = match ($movementType) {
        'sale', 'stock_out', 'damaged', 'expired', 'disposal', 'consumption', 'refill' => 'rejected',
        'stock_in', 'initial_stock' => 'active',
        'adjustment' => 'submitted',
        default => 'returned',
    };

    return '<span class="status-pill ' . h($class) . '">' . h($label) . '</span>';
}

function stock_quantity_label(int $quantity): string
{
    $direction = $quantity < 0 ? 'OUT' : 'IN';
    return $direction . ' ' . abs($quantity);
}

function stock_quantity_class(int $quantity): string
{
    return $quantity < 0 ? 'stock-qty-out' : 'stock-qty-in';
}

function product_tracks_expiration(array $product): bool
{
    if (($product['product_type'] ?? 'non_consumable') === 'consumable') {
        return true;
    }

    if ((int) ($product['is_consumable'] ?? 0) === 1 || (int) ($product['requires_expiration'] ?? 0) === 1) {
        return true;
    }

    $text = strtolower(product_display_name($product) . ' ' . product_category_label((string) ($product['category'] ?? ''), $product['category_name'] ?? null));
    foreach (['bagoong', 'fish feed', 'feed', 'fertilizer', 'fertilizer', 'ink refill', 'ink', 'consumable'] as $needle) {
        if (str_contains($text, $needle)) {
            return true;
        }
    }

    return false;
}

$inventorySectionTitle = match ($inventorySection) {
    'services' => 'Services',
    'stock' => 'Stock',
    default => 'Products',
};
render_header($inventorySectionTitle, $user);
?>


<?php
$productBaseQuery = [
    'section' => $inventorySection,
    'q' => $q,
    'category' => $categoryFilter,
    'sort' => $sort,
    'order' => strtolower($order),
];
$productTabs = $inventorySection === 'stock'
    ? [
        'low_stock' => 'Low Stock',
        'batches' => 'Expiring Stock',
        'ledger' => 'Stock Ledger',
    ]
    : [
        'inventory' => $inventorySection === 'services' ? 'Services' : 'Products',
    ];
?>
<nav class="tabs" aria-label="Inventory sections">
    <?php foreach ($productTabs as $tabKey => $tabLabel): ?>
        <?php $tabHref = 'products.php?' . http_build_query(array_merge($productBaseQuery, ['view' => $tabKey, 'page' => 1])); ?>
        <a class="tab-link <?= $inventoryView === $tabKey ? 'active' : '' ?>" href="<?= h($tabHref) ?>"><?= h($tabLabel) ?></a>
    <?php endforeach; ?>
</nav>

<?php if ($inventorySection === 'stock'): ?>
<dialog id="add-batch-modal" class="modal app-form-modal stock-form-modal">
    <div class="modal-header">
        <h3>Add Consumable Stock</h3>
        <button type="button" class="modal-close" data-close-modal aria-label="Close">Close</button>
    </div>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="inventory_section" value="stock">
        <input type="hidden" name="action" value="add_batch">
        <div class="form-grid">
            <div>
                <label for="batch_product_id">Product</label>
                <select id="batch_product_id" name="product_id" required data-consumable-stock-product>
                    <?php if (!$stockItemOptions): ?>
                        <option value="" selected disabled>No consumable products available. Add a consumable product first.</option>
                    <?php endif; ?>
                    <?php foreach ($stockItemOptions as $item): ?>
                        <option value="<?= (int) $item['id'] ?>" data-stock="<?= h((string) (int) ($item['sellable_stock_qty'] ?? $item['stock_qty'])) ?>" data-cost="<?= h((string) $item['cost_price']) ?>"><?= h(product_display_name($item)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="batch_current_stock">Current Stock</label>
                <input id="batch_current_stock" value="0" readonly data-consumable-stock-current>
            </div>
            <div>
                <label for="batch_quantity_received">Quantity Added</label>
                <input id="batch_quantity_received" name="quantity_received" type="number" min="1" value="1" required>
            </div>
            <div>
                <label for="batch_unit_cost">Cost Price</label>
                <input id="batch_unit_cost" name="unit_cost" type="number" min="0" step="0.01" value="0" required>
            </div>
            <div>
                <label for="batch_expiration_date">Expiration Date</label>
                <input id="batch_expiration_date" name="expiration_date" type="date" required>
            </div>
            <div>
                <label for="batch_supplier">Supplier</label>
                <input id="batch_supplier" name="supplier">
            </div>
            <div>
                <label for="batch_received_date">Date Received</label>
                <input id="batch_received_date" name="received_date" type="datetime-local" value="<?= date('Y-m-d\\TH:i') ?>" required>
            </div>
            <div class="field-wide">
                <label for="batch_notes">Remarks</label>
                <textarea id="batch_notes" name="notes"></textarea>
            </div>
        </div>
        <div class="modal-actions">
            <button type="button" class="btn alt" data-close-modal>Cancel</button>
            <button type="submit">Save Stock</button>
        </div>
    </form>
</dialog>
<?php endif; ?>

<dialog id="add-product-modal" class="modal modal-wide app-form-modal">
    <div class="modal-header">
        <h3><?= $inventorySection === 'services' ? 'Add Service' : 'Add Product' ?></h3>
        <button type="button" class="modal-close" data-modal-close>Close</button>
    </div>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="inventory_section" value="<?= h($inventorySection) ?>">
        <input type="hidden" name="action" value="add_product">
        <input type="hidden" id="type" name="type" value="<?= $inventorySection === 'services' ? 'service' : 'item' ?>">
        <input type="hidden" name="product_group" value="<?= $inventorySection === 'services' ? 'service' : 'product' ?>">

        <div class="product-modal-sections">
            <section class="product-form-section">
                <h4>Basic Info</h4>
                <div class="form-grid product-form-grid">
                    <div>
                        <label for="name"><?= $inventorySection === 'services' ? 'Service Name' : 'Product Name' ?></label>
                        <input id="name" name="name" required>
                    </div>
                    <div>
                        <label for="category">Category</label>
                        <select id="category" name="category" required data-searchable-select>
                            <option value="" disabled selected hidden>Search category...</option>
                            <?php foreach ($categoryFilterOptions as $value => $label): ?>
                                <option value="<?= h($label) ?>"><?= h($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="unit">Unit</label>
                        <input id="unit" name="unit" placeholder="<?= $inventorySection === 'services' ? 'service' : 'pcs' ?>">
                    </div>
                    <?php if ($inventorySection !== 'services'): ?>
                    <div>
                        <label for="product_type">Product Type</label>
                        <select id="product_type" name="product_type" required>
                            <option value="non_consumable" selected>Non-Consumable</option>
                            <option value="consumable">Consumable</option>
                        </select>
                    </div>
                    <?php else: ?>
                        <input type="hidden" name="product_type" value="non_consumable">
                    <?php endif; ?>
                </div>
            </section>
            <section class="product-form-section">
                <h4>Pricing</h4>
                <div class="form-grid product-form-grid">
                    <div>
                        <label for="cost_price">Cost Price</label>
                        <input id="cost_price" name="cost_price" type="number" step="0.01" min="0" value="0" required>
                    </div>
                    <div>
                        <label for="selling_price">Selling Price</label>
                        <input id="selling_price" name="selling_price" type="number" step="0.01" min="0" value="0" required>
                    </div>
                </div>
            </section>
            <?php if ($inventorySection !== 'services'): ?>
            <section class="product-form-section">
                <h4>Stock</h4>
                <div class="form-grid product-form-grid">
                    <div>
                        <label for="initial_quantity">Initial Quantity</label>
                        <input id="initial_quantity" name="initial_quantity" type="number" min="0" value="0" required>
                    </div>
                    <div>
                        <label for="low_stock_threshold">Reorder Level</label>
                        <input id="low_stock_threshold" name="low_stock_threshold" type="number" min="0" value="5" required>
                    </div>
                </div>
            </section>
            <?php else: ?>
                <input type="hidden" name="initial_quantity" value="0">
                <input type="hidden" name="low_stock_threshold" value="0">
            <?php endif; ?>
        </div>
        <div class="modal-actions">
            <button type="button" class="btn alt" data-close-modal>Cancel</button>
            <button type="submit">Save Item</button>
        </div>
    </form>
</dialog>

<?php if (($inventorySection !== 'stock' && $inventoryView === 'inventory') || ($inventorySection === 'stock' && $inventoryView === 'low_stock')): ?>
<?php
$workspaceTitle = match (true) {
    $inventorySection === 'services' => 'Services',
    $inventoryView === 'low_stock' => 'Low Stock Items',
    default => 'Products',
};
$workspaceSummary = $inventoryView === 'low_stock'
    ? $pagination['total_rows'] . ' item' . ((int) $pagination['total_rows'] === 1 ? '' : 's') . ' need restocking'
    : $pagination['total_rows'] . ' active ' . ($inventorySection === 'services' ? 'service' : 'product') . ((int) $pagination['total_rows'] === 1 ? '' : 's');
?>
<section class="table-card data-panel">
    <div class="section-heading">
        <div>
            <h3 class="text-base font-bold text-slate-950"><?= h($workspaceTitle) ?></h3>
            <p class="text-sm text-slate-500"><?= h($workspaceSummary) ?></p>
        </div>
        <div class="inline-actions">
            <?php if ($canAddProducts && $inventorySection !== 'stock'): ?>
                <button type="button" data-open-modal="add-product-modal"><?= $inventorySection === 'services' ? 'Add Service' : 'Add Product' ?></button>
            <?php elseif ($inventorySection === 'stock'): ?>
                <button type="button" data-open-modal="add-batch-modal">Add Consumable Stock</button>
            <?php endif; ?>
        </div>
    </div>

    <form method="get" class="data-panel-filters grid gap-3 md:grid-cols-2 xl:grid-cols-[minmax(220px,1.4fr)_minmax(160px,0.9fr)_minmax(150px,0.8fr)_minmax(140px,0.7fr)_auto_auto] xl:items-end">
        <input type="hidden" name="view" value="<?= h($inventoryView) ?>">
        <input type="hidden" name="section" value="<?= h($inventorySection) ?>">
        <div>
            <label for="q">Search</label>
            <input id="q" name="q" value="<?= h($q) ?>" placeholder="Item name">
        </div>
        <div>
            <label for="category_filter">Category</label>
            <select id="category_filter" name="category">
                <option value="all" <?= $categoryFilter === 'all' ? 'selected' : '' ?>>All</option>
                <?php foreach ($categoryFilterOptions as $value => $label): ?>
                    <option value="<?= h($value) ?>" <?= $categoryFilter === $value ? 'selected' : '' ?>><?= h($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="sort">Sort By</label>
            <select id="sort" name="sort">
                <option value="name" <?= $sort === 'name' ? 'selected' : '' ?>>Name</option>
                <option value="category" <?= $sort === 'category' ? 'selected' : '' ?>>Category</option>
                <option value="stock_qty" <?= $sort === 'stock_qty' ? 'selected' : '' ?>>Stock</option>
                <option value="cost_price" <?= $sort === 'cost_price' ? 'selected' : '' ?>>Cost</option>
                <option value="selling_price" <?= $sort === 'selling_price' ? 'selected' : '' ?>>Selling Price</option>
            </select>
        </div>
        <div>
            <label for="order">Order</label>
            <select id="order" name="order">
                <option value="asc" <?= strtoupper($order) === 'ASC' ? 'selected' : '' ?>>Ascending</option>
                <option value="desc" <?= strtoupper($order) === 'DESC' ? 'selected' : '' ?>>Descending</option>
            </select>
        </div>
        <div class="filter-actions">
            <button type="submit">Apply</button>
            <a class="btn alt" href="products.php?section=<?= h($inventorySection) ?>&view=<?= h($inventoryView) ?>">Reset</a>
        </div>
    </form>

    <div class="table-wrap" data-no-table-enhance>
        <table>
            <thead>
            <tr>
                <th><?= $inventorySection === 'services' ? 'Service' : 'Item' ?></th>
                <?php if ($inventoryView === 'low_stock'): ?>
                    <th>Category</th>
                    <th>Current Stock</th>
                    <th>Reorder Level</th>
                    <th>Status</th>
                    <th>Last Updated</th>
                    <th>Actions</th>
                <?php else: ?>
                <th>Type</th>
                <th>Category</th>
                <th>Cost</th>
                <th>Selling Price</th>
                <th>Profit</th>
                <th>Status</th>
                <th>Actions</th>
                <?php endif; ?>
            </tr>
            </thead>
            <tbody>
            <?php if (!$products): ?>
                <tr>
                    <td colspan="<?= $inventoryView === 'low_stock' ? '7' : '8' ?>">
                        <?php if ($inventoryView === 'low_stock'): ?>
                            <?php render_empty_state('No low stock items.', 'All inventory levels are healthy.'); ?>
                        <?php else: ?>
                            <?php render_empty_state('No items found.', 'Adjust the filters or add an item to begin tracking inventory.'); ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endif; ?>

            <?php foreach ($products as $product): ?>
                <?php
                $isLow = $product['type'] === 'item' && (int) ($product['sellable_stock_qty'] ?? $product['stock_qty']) <= (int) $product['low_stock_threshold'];
                $productDetailHref = 'products.php?' . http_build_query(array_merge($productBaseQuery, [
                    'view' => $inventoryView,
                    'page' => (int) $pagination['page'],
                    'product_id' => (int) $product['id'],
                ]));
                ?>
                <tr class="clickable-row <?= $selectedProductId === (int) $product['id'] ? 'selected-row' : '' ?>" data-row-href="<?= h($productDetailHref) ?>" tabindex="0" aria-label="View details for <?= h(product_display_name($product)) ?>">
                    <td>
                        <a class="font-semibold text-brand-800 hover:text-brand-950" href="<?= h($productDetailHref) ?>"><?= h(product_display_name($product)) ?></a>
                    </td>
                    <?php if ($inventoryView === 'low_stock'): ?>
                        <td>
                            <span class="inline-flex rounded-full bg-slate-100 px-2 py-1 text-xs font-semibold text-slate-700">
                                <?= h(product_category_label((string) $product['category'], $product['category_name'] ?? null)) ?>
                            </span>
                        </td>
                        <td><span class="stock-level-badge <?= (int) ($product['sellable_stock_qty'] ?? $product['stock_qty']) <= 0 ? 'is-out' : 'is-low' ?>"><?= h((string) ($product['sellable_stock_qty'] ?? $product['stock_qty'])) ?></span></td>
                        <td><?= h((string) $product['low_stock_threshold']) ?></td>
                        <td><?= inventory_status_badge($product) ?></td>
                        <td><?= h((string) ($product['updated_at'] ?? '-')) ?></td>
                        <td>
                            <div class="inline-actions">
                                <button type="button" class="btn alt" data-open-modal="flow-product-<?= (int) $product['id'] ?>">View</button>
                                <button type="button" class="btn alt" data-open-modal="edit-product-<?= (int) $product['id'] ?>">Edit</button>
                                <?php if ($product['type'] === 'item'): ?>
                                    <button type="button" class="btn alt" data-open-modal="stock-product-<?= (int) $product['id'] ?>">Restock</button>
                                <?php endif; ?>
                                <?php if (user_can($user, 'archive_records')): ?>
                                    <button type="button" class="btn alt" data-open-modal="archive-product-<?= (int) $product['id'] ?>">Archive</button>
                                <?php endif; ?>
                            </div>
                        </td>
                    <?php else: ?>
                    <td><span class="status-pill <?= $product['type'] === 'service' ? 'pending' : 'active' ?>"><?= h(product_type_label((string) $product['type'])) ?></span></td>
                    <td>
                        <span class="inline-flex rounded-full bg-slate-100 px-2 py-1 text-xs font-semibold capitalize text-slate-700">
                            <?= h(product_category_label((string) $product['category'], $product['category_name'] ?? null)) ?>
                        </span>
                    </td>
                    <td><?= h(money((float) $product['cost_price'])) ?></td>
                    <td><?= h(money((float) $product['selling_price'])) ?></td>
                    <td class="font-semibold text-brand-700"><?= h(money((float) $product['unit_profit'])) ?></td>
                    <td><?= inventory_status_badge($product) ?></td>
                    <td>
                        <div class="inline-actions">
                            <button type="button" class="btn alt" data-open-modal="flow-product-<?= (int) $product['id'] ?>">View</button>
                            <button type="button" class="btn alt" data-open-modal="edit-product-<?= (int) $product['id'] ?>">Edit Item</button>
                            <?php if ($product['type'] === 'item'): ?>
                                <button type="button" class="btn alt" data-open-modal="stock-product-<?= (int) $product['id'] ?>">Restock</button>
                            <?php endif; ?>
                            <?php if (user_can($user, 'archive_records')): ?>
                                <button type="button" class="btn alt" data-open-modal="archive-product-<?= (int) $product['id'] ?>">Archive</button>
                            <?php endif; ?>
                        </div>
                    </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php render_pagination($pagination); ?>
</section>

<?php if ($selectedProduct): ?>
<section id="product-details" class="table-card data-panel inventory-detail-panel mt-4">
    <div class="section-heading">
        <div>
            <h3 class="text-lg font-black text-slate-950"><?= h(product_display_name($selectedProduct)) ?></h3>
            <p class="mt-1 text-sm text-slate-600"><?= h(product_category_label((string) $selectedProduct['category'], $selectedProduct['category_name'] ?? null)) ?></p>
        </div>
        <a class="btn alt" href="products.php?<?= h(http_build_query(array_merge($productBaseQuery, ['view' => $inventoryView, 'page' => (int) $pagination['page']]))) ?>">Close Details</a>
    </div>

    <div class="inventory-detail-summary">
        <div class="inventory-detail-metric">
            <div class="text-xs font-semibold uppercase text-slate-500">Total Quantity In</div>
            <div class="mt-1 text-xl font-bold text-slate-950"><?= h((string) (int) $selectedStockSummary['stock_in_qty']) ?></div>
        </div>
        <div class="inventory-detail-metric">
            <div class="text-xs font-semibold uppercase text-slate-500">Total Quantity Out</div>
            <div class="mt-1 text-xl font-bold text-slate-950"><?= h((string) (int) $selectedStockSummary['stock_out_qty']) ?></div>
        </div>
        <div class="inventory-detail-metric">
            <div class="text-xs font-semibold uppercase text-slate-500">Current Balance</div>
            <div class="mt-1 text-xl font-bold text-brand-800"><?= $selectedProduct['type'] === 'item' ? h((string) (int) $selectedProduct['stock_qty']) : 'Service' ?></div>
        </div>
        <div class="inventory-detail-metric">
            <div class="text-xs font-semibold uppercase text-slate-500">Current Cost Price</div>
            <div class="mt-1 text-xl font-bold text-slate-950"><?= h(money((float) $selectedProduct['cost_price'])) ?></div>
        </div>
        <div class="inventory-detail-metric">
            <div class="text-xs font-semibold uppercase text-slate-500">Selling Price</div>
            <div class="mt-1 text-xl font-bold text-slate-950"><?= h(money((float) $selectedProduct['selling_price'])) ?></div>
        </div>
        <div class="inventory-detail-metric">
            <div class="text-xs font-semibold uppercase text-slate-500">Status</div>
            <div class="mt-2"><?= inventory_status_badge($selectedProduct) ?></div>
        </div>
    </div>

    <div class="inventory-history-grid">
        <div class="inventory-history-card table-wrap stock-modal-table" data-no-table-enhance>
            <h4>Recent Stock Movement History</h4>
            <table>
                <thead><tr><th>Date</th><th>Movement Type</th><th>Quantity</th><th>Remarks</th></tr></thead>
                <tbody>
                <?php if (!$selectedStockInHistory): ?><tr><td colspan="4" class="muted">No stock movement history.</td></tr><?php endif; ?>
                <?php foreach ($selectedStockInHistory as $movement): ?>
                    <?php $movementQty = (int) $movement['quantity_change']; ?>
                    <tr>
                        <td><?= h($movement['movement_date']) ?></td>
                        <td><?= movement_status_badge((string) $movement['movement_type']) ?></td>
                        <td><span class="stock-qty-chip <?= h(stock_quantity_class($movementQty)) ?>"><?= h(stock_quantity_label($movementQty)) ?></span></td>
                        <td><?= h($movement['notes'] ?: '-') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>
<?php elseif ($selectedProductId > 0): ?>
<section class="table-card data-panel mt-4">
    <?php render_empty_state('Item not found.', 'The selected product is inactive or no longer exists.'); ?>
</section>
<?php endif; ?>
<?php endif; ?>

<?php
$productFlowMovementStmt = $pdo->prepare('SELECT movement_date, movement_type, quantity_change, notes
    FROM inventory_stock_movements
    WHERE product_id = :product_id
    ORDER BY movement_date DESC, id DESC
    LIMIT 10');
?>
<?php foreach ($modalProducts as $product): ?>
    <?php
    $productFlowMovementStmt->execute(['product_id' => (int) $product['id']]);
    $productFlowMovements = $productFlowMovementStmt->fetchAll();
    ?>
    <dialog id="flow-product-<?= (int) $product['id'] ?>" class="modal modal-wide">
        <div class="modal-header">
            <div>
                <h3><?= h(product_display_name($product)) ?></h3>
                <p class="mt-1 text-sm text-slate-500"><?= h(product_type_label((string) $product['type'])) ?> | <?= h(product_category_label((string) $product['category'], $product['category_name'] ?? null)) ?></p>
            </div>
            <button type="button" class="modal-close" data-close-modal aria-label="Close">Close</button>
        </div>
        <div class="modal-content">
            <div class="inventory-detail-summary">
                <div class="inventory-detail-metric">
                    <div class="text-xs font-semibold uppercase text-slate-500">Product Name</div>
                    <div class="mt-1 text-xl font-bold text-slate-950"><?= h(product_display_name($product)) ?></div>
                </div>
                <div class="inventory-detail-metric">
                    <div class="text-xs font-semibold uppercase text-slate-500">Category</div>
                    <div class="mt-1 text-xl font-bold text-slate-950"><?= h(product_category_label((string) $product['category'], $product['category_name'] ?? null)) ?></div>
                </div>
                <div class="inventory-detail-metric">
                    <div class="text-xs font-semibold uppercase text-slate-500">Current Stock</div>
                    <div class="mt-1 text-xl font-bold text-brand-800"><?= $product['type'] === 'item' ? h((string) (int) $product['stock_qty']) : 'Service' ?></div>
                </div>
                <div class="inventory-detail-metric">
                    <div class="text-xs font-semibold uppercase text-slate-500">Cost Price</div>
                    <div class="mt-1 text-xl font-bold text-slate-950"><?= h(money((float) $product['cost_price'])) ?></div>
                </div>
                <div class="inventory-detail-metric">
                    <div class="text-xs font-semibold uppercase text-slate-500">Selling Price</div>
                    <div class="mt-1 text-xl font-bold text-slate-950"><?= h(money((float) $product['selling_price'])) ?></div>
                </div>
                <div class="inventory-detail-metric">
                    <div class="text-xs font-semibold uppercase text-slate-500">Status</div>
                    <div class="mt-2"><?= inventory_status_badge($product) ?></div>
                </div>
            </div>

            <div class="inventory-history-grid">
                <div class="inventory-history-card table-wrap stock-modal-table" data-no-table-enhance>
                    <h4>Recent Stock Movement History</h4>
                    <table>
                        <thead><tr><th>Date</th><th>Movement Type</th><th>Quantity</th><th>Remarks</th></tr></thead>
                        <tbody>
                        <?php if (!$productFlowMovements): ?><tr><td colspan="4" class="muted">No stock movement history.</td></tr><?php endif; ?>
                        <?php foreach ($productFlowMovements as $movement): ?>
                            <?php $movementQty = (int) $movement['quantity_change']; ?>
                            <tr>
                                <td><?= h($movement['movement_date']) ?></td>
                                <td><?= movement_status_badge((string) $movement['movement_type']) ?></td>
                                <td><span class="stock-qty-chip <?= h(stock_quantity_class($movementQty)) ?>"><?= h(stock_quantity_label($movementQty)) ?></span></td>
                                <td><?= h($movement['notes'] ?: '-') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </dialog>
    <dialog id="edit-product-<?= (int) $product['id'] ?>" class="modal app-form-modal edit-product-modal">
        <div class="modal-header">
            <h3><?= $product['type'] === 'service' ? 'Edit Service' : 'Edit Product' ?></h3>
            <button type="button" class="modal-close" data-close-modal aria-label="Close">Close</button>
        </div>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="inventory_section" value="<?= h($inventorySection) ?>">
            <input type="hidden" name="action" value="update_product">
            <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">
            <input type="hidden" name="sku" value="<?= h($product['sku']) ?>">
            <input type="hidden" name="type" value="<?= h($product['type']) ?>">
            <?php
            $editCategoryOptions = $product['type'] === 'service' ? $serviceCategoryOptions : $productCategoryOptions;
            $currentCategoryLabel = product_category_label((string) $product['category'], $product['category_name'] ?? null);
            $currentCategoryAllowed = inventory_category_allowed_for_section(
                (string) $product['category'],
                $product['category_name'] ?? null,
                $product['type'] === 'service' ? 'services' : 'products'
            );
            if ($currentCategoryAllowed && $currentCategoryLabel !== '' && !in_array($currentCategoryLabel, $editCategoryOptions, true)) {
                $editCategoryOptions['custom:' . $currentCategoryLabel] = $currentCategoryLabel;
            }
            $currentStock = $product['type'] === 'item' ? (int) ($product['sellable_stock_qty'] ?? $product['stock_qty']) : 0;
            $initialProfit = (float) $product['selling_price'] - (float) $product['cost_price'];
            ?>
            <div class="product-modal-sections">
                <section class="product-form-section">
                    <h4>Product Information</h4>
                    <div class="form-grid product-form-grid">
                        <div>
                            <label for="edit_name_<?= (int) $product['id'] ?>"><?= $product['type'] === 'service' ? 'Service Name' : 'Product Name' ?></label>
                            <input id="edit_name_<?= (int) $product['id'] ?>" name="name" value="<?= h($product['name']) ?>" required>
                        </div>
                        <div>
                            <label for="edit_category_<?= (int) $product['id'] ?>">Category</label>
                            <select id="edit_category_<?= (int) $product['id'] ?>" name="category" required data-searchable-select>
                                <?php foreach ($editCategoryOptions as $value => $label): ?>
                                    <option value="<?= h($label) ?>" <?= product_category_label((string) $product['category'], $product['category_name'] ?? null) === $label ? 'selected' : '' ?>><?= h($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </section>
                <section class="product-form-section">
                    <h4>Pricing</h4>
                    <div class="form-grid product-form-grid">
                        <div>
                            <label for="edit_cost_<?= (int) $product['id'] ?>"><?= $product['type'] === 'service' ? 'Cost' : 'Cost Price' ?></label>
                            <input id="edit_cost_<?= (int) $product['id'] ?>" class="edit-cost-input" name="cost_price" type="number" step="0.01" min="0" value="<?= h((string) $product['cost_price']) ?>" <?= $canManageInventoryPricing ? '' : 'readonly' ?> required>
                        </div>
                        <div>
                            <label for="edit_selling_<?= (int) $product['id'] ?>">Selling Price</label>
                            <input id="edit_selling_<?= (int) $product['id'] ?>" class="edit-selling-input" name="selling_price" type="number" step="0.01" min="0" value="<?= h((string) $product['selling_price']) ?>" <?= $canManageInventoryPricing ? '' : 'readonly' ?> required>
                        </div>
                        <div>
                            <label>Profit Preview</label>
                            <output class="profit-preview" data-profit-preview><?= h(money($initialProfit)) ?></output>
                        </div>
                    </div>
                </section>
                <section class="product-form-section">
                    <h4>Stock Settings</h4>
                    <div class="form-grid product-form-grid">
                        <div>
                            <label for="edit_current_stock_<?= (int) $product['id'] ?>">Current Stock</label>
                            <input id="edit_current_stock_<?= (int) $product['id'] ?>" value="<?= $product['type'] === 'item' ? h((string) $currentStock) : 'Service' ?>" readonly>
                        </div>
                        <?php if ($product['type'] !== 'service'): ?>
                            <div>
                                <label for="edit_threshold_<?= (int) $product['id'] ?>">Reorder Level</label>
                                <input id="edit_threshold_<?= (int) $product['id'] ?>" name="low_stock_threshold" type="number" min="0" value="<?= h((string) $product['low_stock_threshold']) ?>" required>
                            </div>
                        <?php else: ?>
                            <input type="hidden" name="low_stock_threshold" value="0">
                        <?php endif; ?>
                    </div>
                </section>
                <?php if ($product['type'] !== 'service'): ?>
                    <section class="product-form-section">
                        <h4>Product Type</h4>
                        <div class="form-grid product-form-grid">
                            <div>
                                <label for="edit_product_type_<?= (int) $product['id'] ?>">Product Type</label>
                                <select id="edit_product_type_<?= (int) $product['id'] ?>" name="product_type" required>
                                    <option value="non_consumable" <?= ($product['product_type'] ?? 'non_consumable') !== 'consumable' ? 'selected' : '' ?>>Non-Consumable</option>
                                    <option value="consumable" <?= ($product['product_type'] ?? 'non_consumable') === 'consumable' ? 'selected' : '' ?>>Consumable</option>
                                </select>
                            </div>
                        </div>
                    </section>
                <?php else: ?>
                    <input type="hidden" name="product_type" value="non_consumable">
                <?php endif; ?>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn alt" data-close-modal>Cancel</button>
                <button type="submit">Save Changes</button>
            </div>
        </form>
    </dialog>
    <?php if ($product['type'] === 'item'): ?>
        <?php $tracksExpiration = product_tracks_expiration($product); ?>
        <dialog id="stock-product-<?= (int) $product['id'] ?>" class="modal app-form-modal stock-form-modal">
            <div class="modal-header">
                <h3>Restock / Adjust Stock</h3>
                <button type="button" class="modal-close" data-close-modal aria-label="Close">Close</button>
            </div>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="inventory_section" value="<?= h($inventorySection) ?>">
                <input type="hidden" name="action" value="stock_movement">
                <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">
                <input type="hidden" name="movement_reason" value="stock_out">
                <p class="muted"><?= h($product['name']) ?> has <?= h((string) ($product['sellable_stock_qty'] ?? $product['stock_qty'])) ?> unit<?= (int) ($product['sellable_stock_qty'] ?? $product['stock_qty']) === 1 ? '' : 's' ?> on hand.</p>
                <div class="form-grid product-form-grid">
                    <div>
                        <label for="stock_direction_<?= (int) $product['id'] ?>">Movement Type</label>
                        <select id="stock_direction_<?= (int) $product['id'] ?>" name="direction" required>
                            <option value="in">Stock In</option>
                            <option value="out">Stock Out</option>
                        </select>
                    </div>
                    <div>
                        <label for="stock_quantity_<?= (int) $product['id'] ?>">Quantity</label>
                        <input id="stock_quantity_<?= (int) $product['id'] ?>" type="number" min="1" name="quantity" value="1" required>
                    </div>
                    <div>
                        <label for="stock_unit_cost_<?= (int) $product['id'] ?>">Cost Price</label>
                        <input id="stock_unit_cost_<?= (int) $product['id'] ?>" type="number" min="0" step="0.01" name="unit_cost" value="<?= h((string) $product['cost_price']) ?>">
                    </div>
                    <div>
                        <label for="stock_date_<?= (int) $product['id'] ?>">Date and Time</label>
                        <input id="stock_date_<?= (int) $product['id'] ?>" type="datetime-local" name="movement_date" value="<?= date('Y-m-d\\TH:i') ?>">
                    </div>
                    <?php if ($tracksExpiration): ?>
                        <div>
                            <label for="stock_expiration_<?= (int) $product['id'] ?>">Expiration Date</label>
                            <input id="stock_expiration_<?= (int) $product['id'] ?>" type="date" name="expiration_date">
                        </div>
                        <div>
                            <label for="stock_supplier_<?= (int) $product['id'] ?>">Supplier</label>
                            <input id="stock_supplier_<?= (int) $product['id'] ?>" name="supplier">
                        </div>
                        <div class="stock-batch-note field-wide">Batch tracking is recorded for this consumable item.</div>
                    <?php endif; ?>
                    <div class="field-wide">
                        <label for="stock_notes_<?= (int) $product['id'] ?>">Notes</label>
                        <textarea id="stock_notes_<?= (int) $product['id'] ?>" name="notes"></textarea>
                    </div>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn alt" data-close-modal>Cancel</button>
                    <button type="submit">Save Stock Movement</button>
                </div>
            </form>
        </dialog>
    <?php endif; ?>
    <?php if (user_can($user, 'archive_records')): ?>
        <dialog id="archive-product-<?= (int) $product['id'] ?>" class="modal modal-compact archive-confirm-modal">
            <div class="modal-header">
                <div>
                    <h3>Archive Item</h3>
                    <p class="mt-1 text-sm text-slate-500">Historical sales and stock records will remain available.</p>
                </div>
                <button type="button" class="modal-close" data-close-modal aria-label="Close">Close</button>
            </div>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="inventory_section" value="<?= h($inventorySection) ?>">
                <input type="hidden" name="action" value="archive_product">
                <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">
                <div class="modal-content">
                    <p class="text-sm text-slate-700">Archive <strong><?= h(product_display_name($product)) ?></strong> from active inventory?</p>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn alt" data-close-modal>Cancel</button>
                    <button type="submit" class="btn-danger">Archive Item</button>
                </div>
            </form>
        </dialog>
    <?php endif; ?>
<?php endforeach; ?>

<?php if ($inventorySection === 'stock' && $inventoryView === 'overview'): ?>
<section class="grid gap-4 md:grid-cols-3">
    <div class="table-card data-panel p-5">
        <div class="text-sm text-slate-500">Active Products</div>
        <div class="mt-2 text-2xl font-bold text-slate-950"><?= h((string) $totalInventoryRecords) ?></div>
    </div>
    <div class="table-card data-panel p-5">
        <div class="text-sm text-slate-500">Low Stock</div>
        <div class="mt-2 text-2xl font-bold text-slate-950"><?= h((string) $lowStockCount) ?></div>
    </div>
    <div class="table-card data-panel p-5">
        <div class="text-sm text-slate-500">Expired Stock</div>
        <div class="mt-2 text-2xl font-bold text-slate-950"><?= h((string) $expiredBatchCount) ?></div>
    </div>
</section>
<?php endif; ?>

<?php if ($inventoryView === 'batches'): ?>
<section class="table-card data-panel">
    <div class="section-heading">
        <div>
            <h3>Expiring Stock</h3>
            <p class="muted">Consumable inventory with expiration dates.</p>
        </div>
    </div>
    <div class="table-wrap" data-no-table-enhance>
        <table>
            <thead>
            <tr>
                <th>Product Name</th>
                <th>Quantity Added</th>
                <th>Quantity Remaining</th>
                <th>Expiration Date</th>
                <th>Supplier</th>
                <th>Date Received</th>
                <th>Status</th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$batches): ?>
                <tr>
                    <td colspan="7"><?php render_empty_state('No expiring stock found.', 'Add consumable stock for items such as bagoong, feeds, fertilizers, and ink refills.'); ?></td>
                </tr>
            <?php endif; ?>
            <?php foreach ($batches as $batch): ?>
                <?php
                $batchStatus = inventory_batch_status($batch['expiration_date']);
                $batchClass = match ($batchStatus) {
                    'Expired' => 'rejected',
                    'Near Expiry' => 'submitted',
                    default => 'active',
                };
                ?>
                <tr>
                    <td><?= h(product_display_name(['name' => $batch['product_name'], 'sku' => $batch['sku']])) ?></td>
                    <td><?= h((string) $batch['quantity_received']) ?></td>
                    <td><?= h((string) $batch['quantity_remaining']) ?></td>
                    <td><?= h($batch['expiration_date'] ?: '-') ?></td>
                    <td><?= h($batch['supplier'] ?: '-') ?></td>
                    <td><?= h($batch['received_date']) ?></td>
                    <td><span class="status-pill <?= h($batchClass) ?>"><?= h($batchStatus) ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php render_pagination($batchesPagination); ?>
</section>
<?php endif; ?>

<?php if ($inventoryView === 'ledger'): ?>
<section class="table-card data-panel">
    <div class="section-heading">
        <div>
            <h3>Stock Movement Ledger</h3>
        </div>
    </div>
    <div class="table-wrap" data-no-table-enhance>
        <table>
            <thead>
            <tr>
                <th>Date</th>
                <th>Product</th>
                <th>Movement Type</th>
                <th>Quantity</th>
                <th>User</th>
                <th>Reference</th>
                <th>Remarks</th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$stockMovements): ?>
                <tr>
                    <td colspan="7"><?php render_empty_state('No stock movements yet.', 'Stock movements will appear after opening stock, restocking, sales, or adjustments.'); ?></td>
                </tr>
            <?php endif; ?>
            <?php foreach ($stockMovements as $movement): ?>
                <?php $movementQty = (int) $movement['quantity_change']; ?>
                <tr>
                    <td><?= h($movement['movement_date']) ?></td>
                    <td><?= h($movement['product_name']) ?></td>
                    <td><?= movement_status_badge((string) $movement['movement_type']) ?></td>
                    <td><span class="stock-qty-chip <?= h(stock_quantity_class($movementQty)) ?>"><?= h(stock_quantity_label($movementQty)) ?></span></td>
                    <td><?= h($movement['user_name']) ?></td>
                    <td><?= h($movement['reference_no'] ?: '-') ?></td>
                    <td><?= h($movement['notes'] ?: '-') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php render_pagination($stockMovementsPagination); ?>
</section>
<?php endif; ?>


<?php if ($lowStockItems): ?>
<dialog id="low-stock-alerts" class="modal">
    <div class="modal-header">
        <div>
            <h3>Low Stock Alerts</h3>
            <p class="mt-1 text-sm text-slate-500"><?= h((string) count($lowStockItems)) ?> item<?= count($lowStockItems) === 1 ? '' : 's' ?> below threshold</p>
        </div>
        <button type="button" class="modal-close" data-close-modal aria-label="Close">Close</button>
    </div>
    <div class="modal-content">
        <div class="space-y-2">
            <?php foreach ($lowStockItems as $item): ?>
                <a class="block w-full rounded-lg border border-red-200 bg-red-50 p-3 text-left transition hover:bg-red-100" href="products.php?<?= h(http_build_query(['section' => 'stock', 'view' => 'low_stock', 'product_id' => (int) $item['id'], 'open_stock' => 1])) ?>">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="font-semibold text-slate-950"><?= h($item['name']) ?></p>
                            <p class="text-sm text-red-700">Stock: <strong><?= h((string) $item['stock_qty']) ?></strong> unit<?= (int) $item['stock_qty'] === 1 ? '' : 's' ?></p>
                        </div>
                        <?php if ((int) $item['stock_qty'] === 0): ?>
                            <span class="rounded-full bg-red-600 px-3 py-1 text-xs font-bold text-white">OUT OF STOCK</span>
                        <?php else: ?>
                            <span class="rounded-full bg-yellow-600 px-3 py-1 text-xs font-bold text-white">LOW STOCK</span>
                        <?php endif; ?>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="modal-actions">
        <button type="button" class="btn alt" data-close-modal>Close</button>
    </div>
</dialog>
<?php endif; ?>
<?php if ($selectedProduct && (string) ($_GET['open_stock'] ?? '') === '1' && $selectedProduct['type'] === 'item'): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const modal = document.getElementById('stock-product-<?= (int) $selectedProduct['id'] ?>');
    if (modal && typeof modal.showModal === 'function') {
        modal.showModal();
    }
});
</script>
<?php endif; ?>



<script>
    (function () {
        function bindProductType(categorySelect, typeSelect, stockInput) {
            if (!categorySelect || !typeSelect) {
                return;
            }

            function sync() {
                const categoryValue = String(categorySelect.value || '').trim().toLowerCase();
                const isServiceCategory = ['printing', 'printing service', 'photocopy', 'photocopy service', 'id_services', 'id services'].includes(categoryValue);
                if (isServiceCategory || <?= $inventorySection === 'services' ? 'true' : 'false' ?>) {
                    typeSelect.value = 'service';
                } else {
                    typeSelect.value = 'item';
                }

                const isService = typeSelect.value === 'service';
                if (stockInput) {
                    stockInput.disabled = isService;
                    if (isService) {
                        stockInput.value = '0';
                    }
                }
            }

            categorySelect.addEventListener('change', sync);
            sync();
        }

        bindProductType(
            document.getElementById('category'),
            document.getElementById('type'),
            document.getElementById('initial_quantity')
        );

        const consumableProductSelect = document.querySelector('[data-consumable-stock-product]');
        if (consumableProductSelect) {
            const currentStockInput = document.querySelector('[data-consumable-stock-current]');
            const unitCostInput = document.getElementById('batch_unit_cost');
            function syncConsumableStockFields() {
                const selectedOption = consumableProductSelect.selectedOptions[0];
                if (!selectedOption) {
                    if (currentStockInput) {
                        currentStockInput.value = '0';
                    }
                    return;
                }
                if (currentStockInput) {
                    currentStockInput.value = selectedOption.dataset.stock || '0';
                }
                if (unitCostInput && selectedOption.dataset.cost !== undefined) {
                    unitCostInput.value = selectedOption.dataset.cost || '0';
                }
            }
            consumableProductSelect.addEventListener('change', syncConsumableStockFields);
            syncConsumableStockFields();
        }

        document.querySelectorAll('dialog[id^="edit-product-"]').forEach(function (modal) {
            bindProductType(
                modal.querySelector('[name="category"]'),
                modal.querySelector('input[name="type"]'),
                null
            );

            const costInput = modal.querySelector('.edit-cost-input');
            const sellingInput = modal.querySelector('.edit-selling-input');
            const profitPreview = modal.querySelector('[data-profit-preview]');
            function updateProfitPreview() {
                if (!costInput || !sellingInput || !profitPreview) {
                    return;
                }
                const cost = parseFloat(costInput.value || '0');
                const selling = parseFloat(sellingInput.value || '0');
                const profit = selling - cost;
                profitPreview.textContent = new Intl.NumberFormat('en-PH', {
                    style: 'currency',
                    currency: 'PHP'
                }).format(Number.isFinite(profit) ? profit : 0);
                profitPreview.classList.toggle('is-negative', profit < 0);
            }
            if (costInput && sellingInput && profitPreview) {
                costInput.addEventListener('input', updateProfitPreview);
                sellingInput.addEventListener('input', updateProfitPreview);
                updateProfitPreview();
            }
        });

        document.querySelectorAll('.clickable-row[data-row-href]').forEach(function (row) {
            function shouldIgnore(target) {
                return target.closest('a, button, input, select, textarea, summary, details, form');
            }

            row.addEventListener('click', function (event) {
                if (!shouldIgnore(event.target)) {
                    window.location.href = row.dataset.rowHref;
                }
            });

            row.addEventListener('keydown', function (event) {
                if ((event.key === 'Enter' || event.key === ' ') && !shouldIgnore(event.target)) {
                    event.preventDefault();
                    window.location.href = row.dataset.rowHref;
                }
            });
        });
    })();
</script>

<?php render_footer();
