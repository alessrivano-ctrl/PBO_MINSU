<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';

$user = require_login($pdo);

function void_pos_transaction(PDO $pdo, int $saleHeaderId, string $voidReason, array $user): void
{
    $voidReason = trim($voidReason);
    if ($saleHeaderId <= 0 || $voidReason === '') {
        throw new RuntimeException('Void reason is required.');
    }

    $startedTransaction = !$pdo->inTransaction();
    if ($startedTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $headerStmt = $pdo->prepare('SELECT sh.*, cashier.full_name AS cashier_name, cashier.username AS cashier_username
            FROM sale_headers sh
            LEFT JOIN users cashier ON cashier.id = sh.created_by
            WHERE sh.id = :id
            FOR UPDATE');
        $headerStmt->execute(['id' => $saleHeaderId]);
        $header = $headerStmt->fetch();
        if (!$header) {
            throw new RuntimeException('Transaction not found.');
        }
        if ((string) $header['payment_status'] === 'void') {
            throw new RuntimeException('This transaction is already voided.');
        }
        if ((string) $header['payment_status'] !== 'paid') {
            throw new RuntimeException('Only completed transactions can be voided.');
        }
        if (($user['role'] ?? '') !== 'admin' && (int) $header['created_by'] !== (int) $user['id']) {
            throw new RuntimeException('Staff can only void transactions they created.');
        }

        $itemsStmt = $pdo->prepare('SELECT si.*, p.name AS product_name, p.sku, p.type
            FROM sale_items si
            INNER JOIN products p ON p.id = si.product_id
            WHERE si.sale_header_id = :sale_header_id
            ORDER BY si.id ASC
            FOR UPDATE');
        $itemsStmt->execute(['sale_header_id' => $saleHeaderId]);
        $items = $itemsStmt->fetchAll();
        if (!$items) {
            throw new RuntimeException('Transaction has no sale items.');
        }

        $voidedAt = date('Y-m-d H:i:s');
        $orNumber = (string) ($header['or_number'] ?? '');
        $itemQtyByProduct = [];
        foreach ($items as $item) {
            if ((string) $item['type'] !== 'item') {
                continue;
            }
            $productId = (int) $item['product_id'];
            $itemQtyByProduct[$productId] = ($itemQtyByProduct[$productId] ?? 0) + (int) $item['quantity'];
        }
        $legacySaleIds = array_values(array_filter(array_map(static fn (array $item): int => (int) ($item['legacy_sale_id'] ?? 0), $items)));
        $affectedInventory = [];
        $restoredByProduct = [];

        if ($legacySaleIds) {
            $placeholders = implode(',', array_fill(0, count($legacySaleIds), '?'));
            $movementStmt = $pdo->prepare('SELECT id, product_id, batch_id, sale_id, quantity_change, unit_cost, total_cost
                FROM inventory_stock_movements
                WHERE sale_id IN (' . $placeholders . ')
                    AND quantity_change < 0
                ORDER BY id ASC
                FOR UPDATE');
            $movementStmt->execute($legacySaleIds);
            $movements = $movementStmt->fetchAll();

            $restoreBatch = $pdo->prepare('UPDATE inventory_stock_batches SET quantity_remaining = quantity_remaining + :quantity WHERE id = :id');
            $insertRestoreMovement = $pdo->prepare('INSERT INTO inventory_stock_movements (product_id, batch_id, sale_id, movement_date, movement_type, quantity_change, unit_cost, total_cost, reference_no, notes, created_by)
                VALUES (:product_id, :batch_id, :sale_id, :movement_date, "stock_in", :quantity_change, :unit_cost, :total_cost, :reference_no, :notes, :created_by)');

            foreach ($movements as $movement) {
                $restoreQty = abs((int) $movement['quantity_change']);
                if ($restoreQty <= 0) {
                    continue;
                }
                $batchId = $movement['batch_id'] !== null ? (int) $movement['batch_id'] : null;
                if ($batchId !== null) {
                    $restoreBatch->execute(['quantity' => $restoreQty, 'id' => $batchId]);
                }

                $unitCost = (float) $movement['unit_cost'];
                $insertRestoreMovement->execute([
                    'product_id' => (int) $movement['product_id'],
                    'batch_id' => $batchId,
                    'sale_id' => $movement['sale_id'] !== null ? (int) $movement['sale_id'] : null,
                    'movement_date' => $voidedAt,
                    'quantity_change' => $restoreQty,
                    'unit_cost' => $unitCost,
                    'total_cost' => $unitCost * $restoreQty,
                    'reference_no' => $orNumber !== '' ? $orNumber : null,
                    'notes' => 'Void restore: ' . substr($voidReason, 0, 220),
                    'created_by' => (int) $user['id'],
                ]);

                $productId = (int) $movement['product_id'];
                $restoredByProduct[$productId] = ($restoredByProduct[$productId] ?? 0) + $restoreQty;
                $affectedInventory[] = [
                    'product_id' => $productId,
                    'batch_id' => $batchId,
                    'quantity_restored' => $restoreQty,
                    'unit_cost' => $unitCost,
                    'source_movement_id' => (int) $movement['id'],
                    'restore_movement_id' => (int) $pdo->lastInsertId(),
                ];
            }
        }

        $updateProductStock = $pdo->prepare('UPDATE products SET stock_qty = stock_qty + :quantity WHERE id = :id');
        foreach ($restoredByProduct as $productId => $quantity) {
            $updateProductStock->execute(['quantity' => $quantity, 'id' => $productId]);
            inventory_recalculate_product_cost($pdo, (int) $productId, $user, 'void_restore');
        }

        foreach ($itemQtyByProduct as $productId => $soldQty) {
            $missingQty = (int) $soldQty - (int) ($restoredByProduct[$productId] ?? 0);
            if ($missingQty <= 0) {
                continue;
            }
            $item = current(array_filter($items, static fn (array $candidate): bool => (int) $candidate['product_id'] === (int) $productId));
            $batchId = inventory_stock_in(
                $pdo,
                (int) $productId,
                $missingQty,
                (float) $item['unit_cost'],
                $voidedAt,
                $orNumber !== '' ? $orNumber : null,
                'Void restore fallback: ' . substr($voidReason, 0, 200),
                $user,
                'void_restore'
            );
            $affectedInventory[] = [
                'product_id' => $productId,
                'batch_id' => $batchId,
                'quantity_restored' => $missingQty,
                'unit_cost' => (float) $item['unit_cost'],
                'fallback' => true,
            ];
        }

        $cashStmt = $pdo->prepare('INSERT INTO cash_transactions (txn_date, direction, source_module, amount, or_number, description, created_by)
            VALUES (:txn_date, "out", "sales", :amount, :or_number, :description, :created_by)');
        $cashStmt->execute([
            'txn_date' => $voidedAt,
            'amount' => (float) $header['total_amount'],
            'or_number' => $orNumber !== '' ? $orNumber : null,
            'description' => 'Void reversal for OR ' . ($orNumber !== '' ? $orNumber : ('#' . $saleHeaderId)),
            'created_by' => (int) $user['id'],
        ]);
        $cashReversalId = (int) $pdo->lastInsertId();

        $updateHeader = $pdo->prepare('UPDATE sale_headers
            SET payment_status = "void", voided_by = :voided_by, voided_at = :voided_at, void_reason = :void_reason
            WHERE id = :id AND payment_status <> "void"');
        $updateHeader->execute([
            'id' => $saleHeaderId,
            'voided_by' => (int) $user['id'],
            'voided_at' => $voidedAt,
            'void_reason' => $voidReason,
        ]);

        audit_log($pdo, $user, 'void_transaction', 'sales', 'sale_header', $saleHeaderId, [
            'or_number' => $orNumber,
            'original_cashier' => trim((string) ($header['cashier_name'] ?: $header['cashier_username'])),
            'voided_by' => (string) ($user['full_name'] ?? $user['username'] ?? ''),
            'void_reason' => $voidReason,
            'voided_at' => $voidedAt,
            'affected_inventory' => $affectedInventory,
            'cash_flow_reversal' => [
                'cash_transaction_id' => $cashReversalId,
                'direction' => 'out',
                'amount' => (float) $header['total_amount'],
            ],
        ]);

        if ($startedTransaction) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($startedTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['csrf_token'] ?? '');
    if (!verify_csrf($token)) {
        set_flash('error', 'Invalid form token.');
        redirect('sales-reports.php');
    }

    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'void_transaction') {
        try {
            void_pos_transaction($pdo, (int) ($_POST['sale_header_id'] ?? 0), (string) ($_POST['void_reason'] ?? ''), $user);
            set_flash('success', 'Transaction voided. Inventory and cash flow were reversed.');
        } catch (Throwable $e) {
            log_system_issue($pdo, 'error', 'Failed to void transaction.', ['error' => $e->getMessage(), 'sale_header_id' => (int) ($_POST['sale_header_id'] ?? 0)], $user);
            set_flash('error', $e->getMessage());
        }
        redirect('sales-reports.php?' . http_build_query(array_filter([
            'tab' => $_POST['return_tab'] ?? 'transactions',
            'from' => $_POST['return_from'] ?? null,
            'to' => $_POST['return_to'] ?? null,
            'q' => $_POST['return_q'] ?? null,
        ], static fn ($value): bool => $value !== null && $value !== '')));
    }
}

$from = trim((string) ($_GET['from'] ?? date('Y-m-01')));
$to = trim((string) ($_GET['to'] ?? date('Y-m-d')));
$q = trim((string) ($_GET['q'] ?? ''));
$tab = (string) ($_GET['tab'] ?? 'transactions');
if (!in_array($tab, ['transactions', 'void_history'], true)) {
    $tab = 'transactions';
}
[$fromDateTime, $toDateTimeExclusive] = date_filter_bounds($from, $to);

$where = ['sh.sale_date >= :from_dt AND sh.sale_date < :to_dt'];
$params = ['from_dt' => $fromDateTime, 'to_dt' => $toDateTimeExclusive];

if ($q !== '') {
    $where[] = 'p.name LIKE :q_name';
    $params['q_name'] = prefix_search_param($q);
}

$countSql = 'SELECT COUNT(DISTINCT sh.id)
    FROM sale_headers sh
    INNER JOIN sale_items si ON si.sale_header_id = sh.id
    INNER JOIN products p ON p.id = si.product_id
    WHERE ' . implode(' AND ', $where);
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$pagination = pagination_meta((int) $countStmt->fetchColumn(), page_param(), 10);

$listSql = 'SELECT sale_header_id, sale_date, total_quantity, total_amount, total_cost, total_profit, or_number, payment_status, created_by, cashier_name, cashier_username, voided_by, voided_at, void_reason, voided_by_name, voided_by_username, item_count, items_summary
    FROM (
        SELECT sh.id AS sale_header_id,
            sh.sale_date,
            COALESCE(SUM(si.quantity), 0) AS total_quantity,
            sh.total_amount,
            sh.total_cost,
            sh.total_profit,
            sh.or_number,
            sh.payment_status,
            sh.created_by,
            cashier.full_name AS cashier_name,
            cashier.username AS cashier_username,
            sh.voided_by,
            sh.voided_at,
            sh.void_reason,
            voider.full_name AS voided_by_name,
            voider.username AS voided_by_username,
            COUNT(si.id) AS item_count,
            SUBSTRING(GROUP_CONCAT(CONCAT(si.quantity, "x ", p.name) ORDER BY si.id ASC SEPARATOR ", "), 1, 255) AS items_summary,
            ROW_NUMBER() OVER (ORDER BY sh.sale_date DESC, sh.id DESC) AS row_num
        FROM sale_headers sh
        INNER JOIN sale_items si ON si.sale_header_id = sh.id
        INNER JOIN products p ON p.id = si.product_id
        LEFT JOIN users cashier ON cashier.id = sh.created_by
        LEFT JOIN users voider ON voider.id = sh.voided_by
        WHERE ' . implode(' AND ', $where) . '
        GROUP BY sh.id, sh.sale_date, sh.total_amount, sh.total_cost, sh.total_profit, sh.or_number, sh.payment_status, sh.created_by, cashier.full_name, cashier.username, sh.voided_by, sh.voided_at, sh.void_reason, voider.full_name, voider.username
    ) ranked_sales
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
$salesRows = $listStmt->fetchAll();

$voidWhere = array_merge($where, ['sh.payment_status = "void"']);
$voidCountSql = str_replace('WHERE ' . implode(' AND ', $where), 'WHERE ' . implode(' AND ', $voidWhere), $countSql);
$voidCountStmt = $pdo->prepare($voidCountSql);
$voidCountStmt->execute($params);
$voidPagination = pagination_meta((int) $voidCountStmt->fetchColumn(), page_param('void_page'), 10);
$voidListSql = str_replace('WHERE ' . implode(' AND ', $where), 'WHERE ' . implode(' AND ', $voidWhere), $listSql);
$voidListStmt = $pdo->prepare($voidListSql);
foreach ($params as $key => $value) {
    $voidListStmt->bindValue(':' . ltrim((string) $key, ':'), $value);
}
[$voidFirstRow, $voidLastRow] = pagination_row_bounds($voidPagination);
$voidListStmt->bindValue(':first_row', $voidFirstRow, PDO::PARAM_INT);
$voidListStmt->bindValue(':last_row', $voidLastRow, PDO::PARAM_INT);
$voidListStmt->execute();
$voidRows = $voidListStmt->fetchAll();

$baseQuery = [
    'from' => $from,
    'to' => $to,
    'q' => $q,
];

render_header('Sales Records', $user);
?>


<section class="table-card data-panel">
    <div class="section-heading">
        <div>
            <h3>Transactions</h3>
        </div>
    </div>
    <form method="get" class="form-grid data-panel-filters">
        <input type="hidden" name="tab" value="<?= h($tab) ?>">
        <div>
            <label for="from">From</label>
            <input id="from" type="date" name="from" value="<?= h($from) ?>">
        </div>
        <div>
            <label for="to">To</label>
            <input id="to" type="date" name="to" value="<?= h($to) ?>">
        </div>
        <div>
            <label for="q">Search</label>
            <input id="q" name="q" value="<?= h($q) ?>">
        </div>
        <div class="filter-actions">
            <button type="submit">Apply</button>
            <a class="btn alt" href="sales-reports.php">Reset</a>
        </div>
    </form>

<nav class="tabs" aria-label="Transaction views">
    <a class="tab-link <?= $tab === 'transactions' ? 'active' : '' ?>" href="sales-reports.php?<?= h(http_build_query(array_merge($baseQuery, ['tab' => 'transactions']))) ?>">Sales Transactions</a>
    <a class="tab-link <?= $tab === 'void_history' ? 'active' : '' ?>" href="sales-reports.php?<?= h(http_build_query(array_merge($baseQuery, ['tab' => 'void_history']))) ?>">Void History</a>
</nav>

<?php if ($tab === 'transactions'): ?>
<div class="sales-report-block">
    <div class="section-heading">
        <div>
            <h3>Sales Transactions</h3>
        </div>
    </div>
    <div class="table-wrap" data-no-table-enhance>
        <table>
            <thead>
            <tr>
                <th>Date and Time</th>
                <th>OR Number</th>
                <th>Cashier</th>
                <th>Items</th>
                <th>Qty</th>
                <th>Total Amount</th>
                <th>Cost</th>
                <th>Profit</th>
                <th>Status</th>
                <th>Void Details</th>
                <th>Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$salesRows): ?>
                <tr>
                    <td colspan="11"><?php render_empty_state('No sales records found.', 'Change the date range or search another item.'); ?></td>
                </tr>
            <?php endif; ?>
            <?php foreach ($salesRows as $sale): ?>
                <?php
                $isVoided = (string) $sale['payment_status'] === 'void';
                $isCompleted = (string) $sale['payment_status'] === 'paid';
                $canVoid = $isCompleted && (($user['role'] ?? '') === 'admin' || (int) $sale['created_by'] === (int) $user['id']);
                $cashierName = trim((string) ($sale['cashier_name'] ?: $sale['cashier_username'] ?: 'Unknown'));
                $voiderName = trim((string) ($sale['voided_by_name'] ?: $sale['voided_by_username'] ?: ''));
                $statusClass = $isVoided ? 'danger' : 'active';
                $statusLabel = $isVoided ? 'Voided' : 'Completed';
                ?>
                <tr>
                    <td><?= h($sale['sale_date']) ?></td>
                    <td class="font-semibold"><?= h($sale['or_number'] ?: '-') ?></td>
                    <td><?= h($cashierName) ?></td>
                    <td>
                        <div class="font-semibold text-slate-950"><?= h((string) $sale['items_summary']) ?></div>
                        <div class="muted"><?= h((string) $sale['item_count']) ?> line<?= (int) $sale['item_count'] === 1 ? '' : 's' ?></div>
                    </td>
                    <td><?= h((string) $sale['total_quantity']) ?></td>
                    <td><?= h(money((float) $sale['total_amount'])) ?></td>
                    <td><?= h(money((float) $sale['total_cost'])) ?></td>
                    <td><?= h(money((float) $sale['total_profit'])) ?></td>
                    <td><span class="status-pill <?= h($statusClass) ?>"><?= h($statusLabel) ?></span></td>
                    <td>
                        <?php if ($isVoided): ?>
                            <div class="font-semibold text-slate-950"><?= h($sale['voided_at'] ?: '-') ?></div>
                            <div class="muted">By <?= h($voiderName !== '' ? $voiderName : '-') ?></div>
                            <div class="muted"><?= h($sale['void_reason'] ?: '-') ?></div>
                        <?php else: ?>
                            <span class="muted">-</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($canVoid): ?>
                            <button
                                type="button"
                                class="btn void-transaction-btn"
                                data-open-void-transaction
                                data-sale-header-id="<?= (int) $sale['sale_header_id'] ?>"
                                data-or-number="<?= h((string) ($sale['or_number'] ?: '-')) ?>"
                                data-cashier="<?= h($cashierName) ?>"
                                data-total="<?= h(money((float) $sale['total_amount'])) ?>"
                            >Void Transaction</button>
                        <?php else: ?>
                            <span class="muted">-</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php render_pagination($pagination); ?>
</div>
<?php elseif ($tab === 'void_history'): ?>

<div class="sales-report-block">
    <div class="section-heading">
        <div>
            <h3>Void History</h3>
        </div>
    </div>
    <div class="table-wrap" data-no-table-enhance>
        <table>
            <thead>
            <tr>
                <th>Voided At</th>
                <th>OR Number</th>
                <th>Original Cashier</th>
                <th>Voided By</th>
                <th>Total Amount</th>
                <th>Reason</th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$voidRows): ?>
                <tr>
                    <td colspan="6"><?php render_empty_state('No voided transactions found.', 'Voided transactions in the selected range will appear here.'); ?></td>
                </tr>
            <?php endif; ?>
            <?php foreach ($voidRows as $sale): ?>
                <?php
                $cashierName = trim((string) ($sale['cashier_name'] ?: $sale['cashier_username'] ?: 'Unknown'));
                $voiderName = trim((string) ($sale['voided_by_name'] ?: $sale['voided_by_username'] ?: '-'));
                ?>
                <tr>
                    <td><?= h($sale['voided_at'] ?: '-') ?></td>
                    <td class="font-semibold"><?= h($sale['or_number'] ?: '-') ?></td>
                    <td><?= h($cashierName) ?></td>
                    <td><?= h($voiderName) ?></td>
                    <td><?= h(money((float) $sale['total_amount'])) ?></td>
                    <td><?= h($sale['void_reason'] ?: '-') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php render_pagination($voidPagination, 'void_page'); ?>
</div>
<?php endif; ?>
</section>

<dialog id="void-transaction-modal" class="modal app-form-modal void-transaction-modal">
    <div class="modal-header">
        <div>
            <h3>Void Transaction</h3>
            <p class="muted">This will mark the sale as void, restore deducted stock, and add a cash flow reversal.</p>
        </div>
        <button type="button" class="modal-close" data-close-modal aria-label="Close">&times;</button>
    </div>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="void_transaction">
        <input type="hidden" name="sale_header_id" id="void_sale_header_id">
        <input type="hidden" name="return_tab" value="<?= h($tab) ?>">
        <input type="hidden" name="return_from" value="<?= h($from) ?>">
        <input type="hidden" name="return_to" value="<?= h($to) ?>">
        <input type="hidden" name="return_q" value="<?= h($q) ?>">
        <div class="void-warning">
            <strong>Warning:</strong> Voiding is an audit action. The transaction will remain visible, but it will be excluded from sales, income, and profit reports.
        </div>
        <dl class="void-summary">
            <dt>OR Number</dt>
            <dd id="void_transaction_or">-</dd>
            <dt>Original Cashier</dt>
            <dd id="void_transaction_cashier">-</dd>
            <dt>Total Amount</dt>
            <dd id="void_transaction_total">-</dd>
        </dl>
        <div>
            <label for="void_reason">Void Reason</label>
            <textarea id="void_reason" name="void_reason" rows="4" required placeholder="Enter the reason for voiding this completed transaction"></textarea>
        </div>
        <div class="modal-actions">
            <button type="button" class="btn alt" data-close-modal>Keep Transaction</button>
            <button type="submit" class="btn-danger">Void Transaction</button>
        </div>
    </form>
</dialog>

<script>
document.addEventListener('click', function (event) {
    const trigger = event.target.closest('[data-open-void-transaction]');
    if (!trigger) {
        return;
    }

    const modal = document.getElementById('void-transaction-modal');
    if (!modal) {
        return;
    }

    document.getElementById('void_sale_header_id').value = trigger.dataset.saleHeaderId || '';
    document.getElementById('void_transaction_or').textContent = trigger.dataset.orNumber || '-';
    document.getElementById('void_transaction_cashier').textContent = trigger.dataset.cashier || '-';
    document.getElementById('void_transaction_total').textContent = trigger.dataset.total || '-';
    const reasonField = document.getElementById('void_reason');
    reasonField.value = '';
    if (typeof modal.showModal === 'function') {
        modal.showModal();
    } else {
        modal.setAttribute('open', 'open');
    }
    window.setTimeout(function () {
        reasonField.focus();
    }, 40);
});
</script>

<?php render_footer();
