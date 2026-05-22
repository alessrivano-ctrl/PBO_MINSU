<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';

$user = require_login($pdo);

function slugify(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
    return trim($value, '-');
}

function rental_type_options(): array
{
    return [
        'stall' => 'Stall Rentals',
        'toga' => 'Toga Rentals',
    ];
}

function normalize_rental_type(string $type): string
{
    return array_key_exists($type, rental_type_options()) ? $type : 'stall';
}

function rental_type_label(string $type): string
{
    return rental_type_options()[normalize_rental_type($type)] ?? 'Stall Rentals';
}

function rental_account_label(string $type): string
{
    return match (normalize_rental_type($type)) {
        'toga' => 'Toga Release',
        default => 'Stall Rental',
    };
}

function projects_url_for(string $categorySlug, ?string $rentalType = null, ?string $tab = null): string
{
    $query = [];
    if ($categorySlug !== 'all') {
        $query['category'] = in_array($categorySlug, ['rental', 'toga'], true) ? 'rental' : $categorySlug;
    }
    if (in_array($categorySlug, ['rental', 'toga'], true)) {
        $query['rental_type'] = $categorySlug === 'toga' ? 'toga' : normalize_rental_type((string) ($rentalType ?? 'stall'));
    }
    if ($tab !== null && $tab !== '') {
        $query['tab'] = $tab;
    }

    return 'projects.php' . ($query ? '?' . http_build_query($query) : '');
}

function fishpond_meta_input(string $key): string
{
    return substr(trim((string) ($_POST[$key] ?? '')), 0, 255);
}

function fishpond_meta_value(array $row, string $key, string $fallback = '-'): string
{
    $value = trim((string) ($row[$key] ?? ''));
    return $value !== '' ? $value : $fallback;
}

function fishpond_record_type(array $entry): string
{
    $recordType = strtolower(trim((string) ($entry['fishpond_record_type'] ?? '')));
    if (in_array($recordType, ['feeding', 'stock_movement', 'expense', 'harvest', 'monitoring', 'mortality', 'water_quality'], true)) {
        return $recordType;
    }

    $entryType = strtolower((string) ($entry['entry_type'] ?? ''));
    if ($entryType === 'expense' || $entryType === 'harvest') {
        return $entryType;
    }

    $notes = strtolower((string) ($entry['notes'] ?? ''));
    if (str_contains($notes, 'feed')) {
        return 'feeding';
    }
    if (str_contains($notes, 'stock') || str_contains($notes, 'mortality')) {
        return 'stock_movement';
    }

    return 'monitoring';
}

function fishpond_latest_value(array $entries, int $accountId, string $recordType, string $column, string $fallback = '-'): string
{
    foreach ($entries as $entry) {
        if ((int) ($entry['account_id'] ?? 0) !== $accountId || fishpond_record_type($entry) !== $recordType) {
            continue;
        }
        $value = trim((string) ($entry[$column] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }

    return $fallback;
}

$categoryStmt = $pdo->query('SELECT id, slug, name, description FROM project_categories WHERE is_active = 1 ORDER BY name ASC');
$categories = $categoryStmt->fetchAll();

$categoryById = [];
$categoryBySlug = [];
foreach ($categories as $cat) {
    $categoryById[(int) $cat['id']] = $cat;
    $categoryBySlug[(string) $cat['slug']] = $cat;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['csrf_token'] ?? '');
    if (!verify_csrf($token)) {
        set_flash('error', 'Invalid form token.');
        redirect('projects.php');
    }

    $action = (string) ($_POST['action'] ?? '');
    handle_person_post($pdo, $user, 'projects.php');

    if ($action === 'add_category') {
        require_permission($user, 'manage_projects', 'projects.php');
        $name = trim((string) ($_POST['name'] ?? ''));
        $slugInput = trim((string) ($_POST['slug'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $slug = slugify($slugInput !== '' ? $slugInput : $name);

        if ($name === '' || $slug === '') {
            set_flash('error', 'Category name is required.');
            redirect('projects.php');
        }

        try {
            $stmt = $pdo->prepare('INSERT INTO project_categories (slug, name, description, is_active) VALUES (:slug, :name, :description, 1)');
            $stmt->execute([
                'slug' => $slug,
                'name' => $name,
                'description' => $description !== '' ? $description : null,
            ]);
            audit_log($pdo, $user, 'create_category', 'projects', 'project_category', (int) $pdo->lastInsertId(), [
                'name' => $name,
                'slug' => $slug,
            ]);
            set_flash('success', 'Project category added.');
            redirect('projects.php?category=' . urlencode($slug));
        } catch (PDOException $e) {
            log_system_issue($pdo, 'error', 'Could not add project category.', ['error' => $e->getMessage(), 'slug' => $slug], $user);
            set_flash('error', 'Category slug already exists. Try another category name.');
            redirect('projects.php');
        }
    }

    if ($action === 'add_project') {
        require_permission($user, 'manage_projects', 'projects.php');
        $name = trim((string) ($_POST['name'] ?? ''));
        $slugInput = trim((string) ($_POST['slug'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $accountName = trim((string) ($_POST['account_name'] ?? ''));
        $accountPersonId = (int) ($_POST['account_person_id'] ?? 0);
        $code = trim((string) ($_POST['code'] ?? ''));
        $contact = trim((string) ($_POST['contact_name'] ?? ''));
        $startDate = trim((string) ($_POST['start_date'] ?? ''));
        $nextDueDate = trim((string) ($_POST['next_due_date'] ?? ''));
        $expectedAmountRaw = trim((string) ($_POST['expected_amount'] ?? ''));
        $notes = trim((string) ($_POST['notes'] ?? ''));
        $slug = slugify($slugInput !== '' ? $slugInput : $name);

        if ($name === '' || $slug === '') {
            set_flash('error', 'Project name is required.');
            redirect('projects.php');
        }

        $expectedAmount = null;
        if ($expectedAmountRaw !== '') {
            $expectedAmount = (float) $expectedAmountRaw;
            if ($expectedAmount < 0) {
                set_flash('error', 'Expected amount cannot be negative.');
                redirect('projects.php');
            }
        }

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare('INSERT INTO project_categories (slug, name, description, is_active) VALUES (:slug, :name, :description, 1)');
            $stmt->execute([
                'slug' => $slug,
                'name' => $name,
                'description' => $description !== '' ? $description : null,
            ]);
            $categoryId = (int) $pdo->lastInsertId();

            $accountId = null;
            if ($accountName !== '') {
                $accountPerson = $accountPersonId > 0 ? find_person($pdo, $accountPersonId, true) : null;
                if ($accountPerson) {
                    $accountName = (string) $accountPerson['full_name'];
                    $contact = $contact !== '' ? $contact : (string) ($accountPerson['department'] ?: $accountPerson['role_or_position'] ?: '');
                }
                $accountStmt = $pdo->prepare('INSERT INTO project_accounts (category_id, person_id, account_name, code, contact_name, start_date, next_due_date, expected_amount, notes)
                    VALUES (:category_id, :person_id, :account_name, :code, :contact_name, :start_date, :next_due_date, :expected_amount, :notes)');
                $accountStmt->execute([
                    'category_id' => $categoryId,
                    'person_id' => $accountPerson ? (int) $accountPerson['id'] : null,
                    'account_name' => $accountName,
                    'code' => $code !== '' ? $code : null,
                    'contact_name' => $contact !== '' ? $contact : null,
                    'start_date' => $startDate !== '' ? $startDate : null,
                    'next_due_date' => $nextDueDate !== '' ? $nextDueDate : null,
                    'expected_amount' => $expectedAmount,
                    'notes' => $notes !== '' ? $notes : null,
                ]);
                $accountId = (int) $pdo->lastInsertId();
            }

            audit_log($pdo, $user, 'create_project', 'projects', 'project_category', $categoryId, [
                'name' => $name,
                'slug' => $slug,
                'account_id' => $accountId,
            ]);

            $pdo->commit();
            set_flash('success', $accountId ? 'Project and first account added.' : 'Project added.');
            redirect('projects.php?category=' . urlencode($slug));
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            log_system_issue($pdo, 'error', 'Could not add project.', ['error' => $e->getMessage(), 'slug' => $slug], $user);
            set_flash('error', 'Project slug already exists. Try another project name.');
            redirect('projects.php');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            log_system_issue($pdo, 'error', 'Could not add project.', ['error' => $e->getMessage(), 'slug' => $slug], $user);
            set_flash('error', 'Could not add project.');
            redirect('projects.php');
        }
    }

    if ($action === 'add_account') {
        $categoryId = (int) ($_POST['category_id'] ?? 0);
        $accountPersonId = (int) ($_POST['account_person_id'] ?? 0);
        $accountName = trim((string) ($_POST['account_name'] ?? ''));
        $code = trim((string) ($_POST['code'] ?? ''));
        $contact = trim((string) ($_POST['contact_name'] ?? ''));
        $startDate = trim((string) ($_POST['start_date'] ?? ''));
        $nextDueDate = trim((string) ($_POST['next_due_date'] ?? ''));
        $expectedAmountRaw = trim((string) ($_POST['expected_amount'] ?? ''));
        $notes = trim((string) ($_POST['notes'] ?? ''));
        $accountStatus = in_array((string) ($_POST['status'] ?? 'active'), ['active', 'inactive'], true) ? (string) $_POST['status'] : 'active';
        $rentalTypeInput = normalize_rental_type((string) ($_POST['rental_type'] ?? 'stall'));
        $fishpondAccountMeta = [
            'fish_type' => fishpond_meta_input('fish_type'),
            'stock_count' => fishpond_meta_input('stock_count'),
            'survival_rate' => fishpond_meta_input('survival_rate'),
            'fish_growth_stage' => fishpond_meta_input('fish_growth_stage'),
            'caretaker_person_id' => '',
            'caretaker' => fishpond_meta_input('caretaker'),
        ];

        if ($categoryId <= 0 || !isset($categoryById[$categoryId])) {
            set_flash('error', 'Please choose a valid category.');
            redirect('projects.php');
        }

        $categorySlug = (string) $categoryById[$categoryId]['slug'];
        if ($categorySlug === 'toga') {
            $rentalTypeInput = 'toga';
        }
        if ($categorySlug === 'fishpond') {
            $code = strtoupper($code);
        }
        $redirectTarget = projects_url_for($categorySlug, $categorySlug === 'rental' ? $rentalTypeInput : null);
        $accountPerson = $accountPersonId > 0 ? find_person($pdo, $accountPersonId, true) : null;
        if (in_array($categorySlug, ['rental', 'toga'], true) && !$accountPerson) {
            set_flash('error', 'Select an approved person for rental and toga accounts.');
            redirect($redirectTarget);
        }
        if ($categorySlug === 'fishpond' && $accountPersonId > 0 && !$accountPerson) {
            set_flash('error', 'Select a valid caretaker from the people records.');
            redirect($redirectTarget);
        }
        if ($accountPerson && $categorySlug !== 'fishpond') {
            $accountName = (string) $accountPerson['full_name'];
            $contact = $contact !== '' ? $contact : (string) ($accountPerson['department'] ?: $accountPerson['role_or_position'] ?: '');
        } elseif ($accountPerson) {
            $contact = $contact !== '' ? $contact : (string) ($accountPerson['department'] ?: $accountPerson['role_or_position'] ?: '');
        }
        if ($categorySlug === 'fishpond' && $accountPerson) {
            $fishpondAccountMeta['caretaker_person_id'] = (string) $accountPerson['id'];
            $fishpondAccountMeta['caretaker'] = (string) $accountPerson['full_name'];
        }

        if ($accountName === '') {
            set_flash('error', 'Account name is required (example: Stall 1, Pond A).');
            redirect($redirectTarget);
        }

        $expectedAmount = null;
        if ($expectedAmountRaw !== '') {
            $expectedAmount = (float) $expectedAmountRaw;
            if ($expectedAmount < 0) {
                set_flash('error', 'Expected amount cannot be negative.');
                redirect($redirectTarget);
            }
        }
        if ($categorySlug === 'fishpond' && $fishpondAccountMeta['stock_count'] !== '' && (int) $fishpondAccountMeta['stock_count'] < 0) {
            set_flash('error', 'Stock count cannot be negative.');
            redirect($redirectTarget);
        }

        $accountPayload = [
            'category_id' => $categoryId,
            'category_slug' => $categorySlug,
            'rental_type' => $categorySlug === 'rental' ? $rentalTypeInput : null,
            'person_id' => $accountPerson ? (int) $accountPerson['id'] : null,
            'account_name' => $accountName,
            'code' => $code,
            'contact_name' => $contact,
            'start_date' => $startDate,
            'next_due_date' => $nextDueDate,
            'expected_amount' => $expectedAmount,
            'notes' => $notes,
            'fishpond_meta' => $categorySlug === 'fishpond' ? $fishpondAccountMeta : [],
        ];

        if (user_requires_admin_approval($user)) {
            create_approval_request(
                $pdo,
                (int) $user['id'],
                'projects',
                $categorySlug === 'rental' ? 'add_rental_account' : 'add_project_account',
                'project_account',
                null,
                null,
                $accountPayload
            );
            set_flash('success', 'Request submitted for admin approval.');
            redirect($redirectTarget);
        }

        if ($categorySlug === 'fishpond') {
            try {
                $stockCount = $fishpondAccountMeta['stock_count'] !== '' ? max(0, (int) $fishpondAccountMeta['stock_count']) : null;
                $stmt = $pdo->prepare('INSERT INTO ponds (pond_code, pond_name, fish_type, stock_count, survival_rate, fish_growth_stage, caretaker_id, status, notes, created_by)
                    VALUES (:pond_code, :pond_name, :fish_type, :stock_count, :survival_rate, :fish_growth_stage, :caretaker_id, :status, :notes, :created_by)');
                $stmt->execute([
                    'pond_code' => $code !== '' ? $code : null,
                    'pond_name' => $accountName,
                    'fish_type' => $fishpondAccountMeta['fish_type'] !== '' ? $fishpondAccountMeta['fish_type'] : null,
                    'stock_count' => $stockCount,
                    'survival_rate' => $fishpondAccountMeta['survival_rate'] !== '' ? $fishpondAccountMeta['survival_rate'] : null,
                    'fish_growth_stage' => $fishpondAccountMeta['fish_growth_stage'] !== '' ? $fishpondAccountMeta['fish_growth_stage'] : null,
                    'caretaker_id' => $accountPerson ? (int) $accountPerson['id'] : null,
                    'status' => $accountStatus,
                    'notes' => $notes !== '' ? $notes : null,
                    'created_by' => (int) $user['id'],
                ]);
                $pondId = (int) $pdo->lastInsertId();
                audit_log($pdo, $user, 'create_pond', 'fishpond', 'pond', $pondId, [
                    'pond_name' => $accountName,
                    'pond_code' => $code !== '' ? $code : null,
                ]);
                set_flash('success', 'Pond added.');
            } catch (PDOException $e) {
                log_system_issue($pdo, 'error', 'Could not add pond.', ['error' => $e->getMessage(), 'pond_name' => $accountName], $user);
                set_flash('error', 'Could not add pond. Pond code or name may already exist.');
            }
            redirect($redirectTarget);
        }

        $stmt = $pdo->prepare('INSERT INTO project_accounts (category_id, person_id, account_name, code, contact_name, start_date, next_due_date, expected_amount, notes)
            VALUES (:category_id, :person_id, :account_name, :code, :contact_name, :start_date, :next_due_date, :expected_amount, :notes)');
        $stmt->execute([
            'category_id' => $categoryId,
            'person_id' => $accountPerson ? (int) $accountPerson['id'] : null,
            'account_name' => $accountName,
            'code' => $code !== '' ? $code : null,
            'contact_name' => $contact !== '' ? $contact : null,
            'start_date' => $startDate !== '' ? $startDate : null,
            'next_due_date' => $nextDueDate !== '' ? $nextDueDate : null,
            'expected_amount' => $expectedAmount,
            'notes' => $notes !== '' ? $notes : null,
        ]);
        $accountId = (int) $pdo->lastInsertId();
        if ($categorySlug === 'rental') {
            project_account_meta_set($pdo, $accountId, 'rental_type', $rentalTypeInput);
        } elseif ($categorySlug === 'fishpond') {
            foreach ($fishpondAccountMeta as $key => $value) {
                if ($value !== '') {
                    project_account_meta_set($pdo, $accountId, $key, $value);
                }
            }
        }
        audit_log($pdo, $user, 'create_account', 'projects', 'project_account', $accountId, [
            'category_id' => $categoryId,
            'account_name' => $accountName,
            'rental_type' => $categorySlug === 'rental' ? $rentalTypeInput : null,
        ]);

        set_flash('success', $categorySlug === 'fishpond' ? 'Pond added.' : 'Project account added.');
        redirect($redirectTarget);
    }

    if ($action === 'record_entry') {
        $categoryId = (int) ($_POST['category_id'] ?? 0);
        $accountId = (int) ($_POST['account_id'] ?? 0);
        $entryDateTime = normalize_datetime_input((string) ($_POST['entry_datetime'] ?? ''));
        $entryType = (string) ($_POST['entry_type'] ?? 'monitoring');
        $quantityRaw = trim((string) ($_POST['quantity'] ?? ''));
        $unit = trim((string) ($_POST['unit'] ?? ''));
        $amount = (float) ($_POST['amount'] ?? 0);
        $referenceNo = trim((string) ($_POST['reference_no'] ?? ''));
        $notes = trim((string) ($_POST['notes'] ?? ''));
        $syncCashInput = isset($_POST['sync_cash']) && $_POST['sync_cash'] === '1';
        $syncCash = $entryType === 'expense'
            || ($syncCashInput && $amount > 0 && in_array($entryType, ['income', 'payment', 'harvest'], true));
        $nextDueDate = trim((string) ($_POST['update_next_due_date'] ?? ''));
        $rentalTypeInput = normalize_rental_type((string) ($_POST['rental_type'] ?? 'stall'));
        $caretakerPersonId = (int) ($_POST['caretaker_person_id'] ?? 0);
        $caretakerPerson = $caretakerPersonId > 0 ? find_person($pdo, $caretakerPersonId, true) : null;
        $fishpondRecordType = fishpond_meta_input('fishpond_record_type');
        $fishpondEntryMeta = [
            'fishpond_record_type' => $fishpondRecordType,
            'water_temperature' => fishpond_meta_input('water_temperature'),
            'ph_level' => fishpond_meta_input('ph_level'),
            'turbidity' => fishpond_meta_input('turbidity'),
            'water_level' => fishpond_meta_input('water_level'),
            'feed_quantity' => fishpond_meta_input('feed_quantity'),
            'mortality_count' => fishpond_meta_input('mortality_count'),
            'harvest_kilos' => fishpond_meta_input('harvest_kilos'),
            'fish_growth_stage' => fishpond_meta_input('fish_growth_stage'),
            'caretaker_person_id' => $caretakerPerson ? (string) $caretakerPerson['id'] : '',
            'caretaker' => $caretakerPerson ? (string) $caretakerPerson['full_name'] : fishpond_meta_input('caretaker'),
            'movement_type' => fishpond_meta_input('movement_type'),
            'remaining_stock' => fishpond_meta_input('remaining_stock'),
            'feed_type' => fishpond_meta_input('feed_type'),
            'expense_type' => fishpond_meta_input('expense_type'),
            'buyer' => fishpond_meta_input('buyer'),
            'price_per_kilo' => fishpond_meta_input('price_per_kilo'),
        ];

        $validEntryTypes = ['income', 'expense', 'production', 'harvest', 'payment', 'monitoring', 'other'];

        if ($categoryId <= 0 || !isset($categoryById[$categoryId])) {
            set_flash('error', 'Please choose a valid category.');
            redirect('projects.php');
        }

        if (!in_array($entryType, $validEntryTypes, true)) {
            set_flash('error', 'Invalid entry type.');
            redirect('projects.php?category=' . urlencode((string) $categoryById[$categoryId]['slug']));
        }

        if ($amount < 0) {
            set_flash('error', 'Amount cannot be negative.');
            redirect('projects.php?category=' . urlencode((string) $categoryById[$categoryId]['slug']));
        }

        $quantity = null;
        if ($quantityRaw !== '') {
            $quantity = (float) $quantityRaw;
        }

        $slug = (string) $categoryById[$categoryId]['slug'];
        $module = $slug !== '' ? $slug : 'other';
        if ($slug === 'toga') {
            $rentalTypeInput = 'toga';
        }
        if ($slug === 'fishpond' && $fishpondRecordType === 'harvest') {
            $harvestKilos = $fishpondEntryMeta['harvest_kilos'] !== '' ? (float) $fishpondEntryMeta['harvest_kilos'] : null;
            $pricePerKilo = $fishpondEntryMeta['price_per_kilo'] !== '' ? (float) $fishpondEntryMeta['price_per_kilo'] : null;
            if ($harvestKilos !== null && $pricePerKilo !== null) {
                $amount = max(0, $harvestKilos * $pricePerKilo);
            }
        }

        if ($accountId > 0) {
            if ($slug === 'fishpond') {
                $accountCheck = $pdo->prepare('SELECT id FROM ponds WHERE id = :id AND status = "active"');
                $accountCheck->execute(['id' => $accountId]);
            } else {
                $accountCheck = $pdo->prepare('SELECT id FROM project_accounts WHERE id = :id AND category_id = :category_id');
                $accountCheck->execute([
                    'id' => $accountId,
                    'category_id' => $categoryId,
                ]);
            }
            if (!$accountCheck->fetch()) {
                set_flash('error', $slug === 'fishpond' ? 'Select a valid pond record.' : 'The selected account does not belong to the chosen category.');
                redirect('projects.php?category=' . urlencode((string) $categoryById[$categoryId]['slug']));
            }
        } else {
            $accountId = 0;
        }

        if ($slug === 'fishpond' && $fishpondRecordType !== '' && !in_array($fishpondRecordType, ['feeding', 'stock_movement', 'expense', 'harvest', 'mortality'], true)) {
            set_flash('error', 'Invalid fishpond record type.');
            redirect(projects_url_for('fishpond'));
        }
        if ($slug === 'fishpond' && $fishpondRecordType !== '' && $accountId <= 0) {
            set_flash('error', 'Select a valid pond record.');
            redirect(projects_url_for('fishpond', null, match ($fishpondRecordType) {
                'feeding' => 'feeding',
                'stock_movement', 'mortality' => 'stock',
                'expense' => 'expenses',
                'harvest' => 'harvest',
                default => 'ponds',
            }));
        }
        if ($slug === 'fishpond' && $caretakerPersonId > 0 && !$caretakerPerson) {
            set_flash('error', 'Select a valid caretaker from the people records.');
            redirect(projects_url_for('fishpond'));
        }

        $entryPayload = [
            'category_id' => $categoryId,
            'category_slug' => $slug,
            'rental_type' => $slug === 'rental' ? $rentalTypeInput : null,
            'account_id' => $accountId > 0 ? $accountId : null,
            'entry_datetime' => $entryDateTime,
            'entry_type' => $entryType,
            'quantity' => $quantity,
            'unit' => $unit,
            'amount' => $amount,
            'reference_no' => $referenceNo,
            'notes' => $notes,
            'sync_cash' => $syncCash,
            'update_next_due_date' => $nextDueDate,
            'fishpond_meta' => $slug === 'fishpond' ? $fishpondEntryMeta : [],
        ];
        $fishpondRedirectTab = $slug === 'fishpond' && $fishpondRecordType !== ''
            ? match ($fishpondRecordType) {
                'feeding' => 'feeding',
                'stock_movement', 'mortality' => 'stock',
                'expense' => 'expenses',
                'harvest' => 'harvest',
                default => 'ponds',
            }
            : 'entries';
        $requiresApproval = user_requires_admin_approval($user)
            && ($amount > 0 || $syncCash || $nextDueDate !== '' || in_array($entryType, ['income', 'expense', 'harvest', 'payment'], true));
        if ($requiresApproval) {
            create_approval_request($pdo, (int) $user['id'], 'projects', 'record_project_entry', 'project_entry', null, null, $entryPayload);
            set_flash('success', 'Request submitted for admin approval.');
            redirect(projects_url_for($slug, $slug === 'rental' ? $rentalTypeInput : null, $fishpondRedirectTab));
        }

        try {
            $pdo->beginTransaction();

            if ($slug === 'fishpond') {
                $entityType = 'fishpond_record';
                $entityId = null;

                if ($fishpondRecordType === 'feeding') {
                    $insertFishpond = $pdo->prepare('INSERT INTO fishpond_feeding_records (pond_id, fed_at, feed_type, quantity, unit, cost, caretaker_id, remarks, created_by)
                        VALUES (:pond_id, :fed_at, :feed_type, :quantity, :unit, :cost, :caretaker_id, :remarks, :created_by)');
                    $insertFishpond->execute([
                        'pond_id' => $accountId,
                        'fed_at' => $entryDateTime,
                        'feed_type' => $fishpondEntryMeta['feed_type'] !== '' ? $fishpondEntryMeta['feed_type'] : null,
                        'quantity' => $fishpondEntryMeta['feed_quantity'] !== '' ? (float) $fishpondEntryMeta['feed_quantity'] : $quantity,
                        'unit' => $unit !== '' ? $unit : null,
                        'cost' => $amount,
                        'caretaker_id' => $caretakerPerson ? (int) $caretakerPerson['id'] : null,
                        'remarks' => $notes !== '' ? $notes : null,
                        'created_by' => (int) $user['id'],
                    ]);
                    $entityType = 'fishpond_feeding_record';
                    $entityId = (int) $pdo->lastInsertId();
                    $cashDirection = $amount > 0 ? 'out' : null;
                    $cashDescription = 'Fishpond feeding';
                } elseif (in_array($fishpondRecordType, ['stock_movement', 'mortality'], true)) {
                    $movementType = $fishpondEntryMeta['movement_type'] !== '' ? $fishpondEntryMeta['movement_type'] : 'Stock Movement';
                    $mortalityCount = $fishpondEntryMeta['mortality_count'] !== '' ? max(0, (int) $fishpondEntryMeta['mortality_count']) : null;
                    $quantityInt = $quantity !== null ? max(0, (int) $quantity) : 0;
                    if ($fishpondRecordType === 'mortality' || str_contains(strtolower($movementType), 'mortality')) {
                        $quantityInt = $mortalityCount ?? 0;
                    }
                    $stockStmt = $pdo->prepare('SELECT COALESCE(stock_count, 0) FROM ponds WHERE id = :id');
                    $stockStmt->execute(['id' => $accountId]);
                    $currentStock = max(0, (int) $stockStmt->fetchColumn());
                    $movementKey = strtolower($movementType);
                    $stockDelta = 0;
                    if ($fishpondRecordType === 'mortality' || str_contains($movementKey, 'mortality')) {
                        $stockDelta = -1 * ($mortalityCount ?? $quantityInt);
                    } elseif (str_contains($movementKey, 'stock in') || str_contains($movementKey, 'transfer in')) {
                        $stockDelta = $quantityInt;
                    } elseif (str_contains($movementKey, 'stock out') || str_contains($movementKey, 'transfer')) {
                        $stockDelta = -1 * $quantityInt;
                    }
                    $computedRemainingStock = max(0, $currentStock + $stockDelta);
                    $insertFishpond = $pdo->prepare('INSERT INTO fishpond_stock_movements (pond_id, movement_at, movement_type, quantity, unit, remaining_stock, mortality_count, fish_growth_stage, caretaker_id, remarks, created_by)
                        VALUES (:pond_id, :movement_at, :movement_type, :quantity, :unit, :remaining_stock, :mortality_count, :fish_growth_stage, :caretaker_id, :remarks, :created_by)');
                    $insertFishpond->execute([
                        'pond_id' => $accountId,
                        'movement_at' => $entryDateTime,
                        'movement_type' => $movementType,
                        'quantity' => $quantityInt > 0 ? $quantityInt : null,
                        'unit' => $unit !== '' ? $unit : 'fish',
                        'remaining_stock' => $computedRemainingStock,
                        'mortality_count' => $mortalityCount,
                        'fish_growth_stage' => $fishpondEntryMeta['fish_growth_stage'] !== '' ? $fishpondEntryMeta['fish_growth_stage'] : null,
                        'caretaker_id' => $caretakerPerson ? (int) $caretakerPerson['id'] : null,
                        'remarks' => $notes !== '' ? $notes : null,
                        'created_by' => (int) $user['id'],
                    ]);
                    $entityType = 'fishpond_stock_movement';
                    $entityId = (int) $pdo->lastInsertId();
                    $cashDirection = null;
                    $cashDescription = 'Fishpond stock movement';
                    $updatePond = $pdo->prepare('UPDATE ponds SET stock_count = :stock_count WHERE id = :id');
                    $updatePond->execute(['stock_count' => $computedRemainingStock, 'id' => $accountId]);
                    if ($accountId > 0 && $fishpondEntryMeta['fish_growth_stage'] !== '') {
                        $updatePond = $pdo->prepare('UPDATE ponds SET fish_growth_stage = :fish_growth_stage WHERE id = :id');
                        $updatePond->execute(['fish_growth_stage' => $fishpondEntryMeta['fish_growth_stage'], 'id' => $accountId]);
                    }
                } elseif ($fishpondRecordType === 'expense') {
                    $insertFishpond = $pdo->prepare('INSERT INTO fishpond_expenses (pond_id, expense_date, expense_type, amount, reference_no, remarks, created_by)
                        VALUES (:pond_id, :expense_date, :expense_type, :amount, :reference_no, :remarks, :created_by)');
                    $insertFishpond->execute([
                        'pond_id' => $accountId,
                        'expense_date' => $entryDateTime,
                        'expense_type' => $fishpondEntryMeta['expense_type'] !== '' ? $fishpondEntryMeta['expense_type'] : null,
                        'amount' => $amount,
                        'reference_no' => $referenceNo !== '' ? $referenceNo : null,
                        'remarks' => $notes !== '' ? $notes : null,
                        'created_by' => (int) $user['id'],
                    ]);
                    $entityType = 'fishpond_expense';
                    $entityId = (int) $pdo->lastInsertId();
                    $cashDirection = $amount > 0 ? 'out' : null;
                    $cashDescription = 'Fishpond expense';
                } elseif ($fishpondRecordType === 'harvest') {
                    $kilos = $fishpondEntryMeta['harvest_kilos'] !== '' ? (float) $fishpondEntryMeta['harvest_kilos'] : $quantity;
                    $pricePerKilo = $fishpondEntryMeta['price_per_kilo'] !== '' ? (float) $fishpondEntryMeta['price_per_kilo'] : null;
                    $insertFishpond = $pdo->prepare('INSERT INTO fishpond_harvest_records (pond_id, harvest_date, kilos, price_per_kilo, total_sales, buyer, remarks, created_by)
                        VALUES (:pond_id, :harvest_date, :kilos, :price_per_kilo, :total_sales, :buyer, :remarks, :created_by)');
                    $insertFishpond->execute([
                        'pond_id' => $accountId,
                        'harvest_date' => $entryDateTime,
                        'kilos' => $kilos,
                        'price_per_kilo' => $pricePerKilo,
                        'total_sales' => $amount,
                        'buyer' => $fishpondEntryMeta['buyer'] !== '' ? $fishpondEntryMeta['buyer'] : null,
                        'remarks' => $notes !== '' ? $notes : null,
                        'created_by' => (int) $user['id'],
                    ]);
                    $entityType = 'fishpond_harvest_record';
                    $entityId = (int) $pdo->lastInsertId();
                    $cashDirection = $amount > 0 ? 'in' : null;
                    $cashDescription = 'Fishpond harvest';
                } else {
                    throw new RuntimeException('Invalid fishpond record type.');
                }

                if (!empty($cashDirection) && $amount > 0) {
                    $insertCash = $pdo->prepare('INSERT INTO cash_transactions (txn_date, direction, source_module, amount, or_number, description, created_by)
                        VALUES (:txn_date, :direction, :source_module, :amount, :or_number, :description, :created_by)');
                    $insertCash->execute([
                        'txn_date' => $entryDateTime,
                        'direction' => $cashDirection,
                        'source_module' => 'fishpond',
                        'amount' => $amount,
                        'or_number' => $referenceNo !== '' ? $referenceNo : null,
                        'description' => $cashDescription,
                        'created_by' => (int) $user['id'],
                    ]);
                }

                $pdo->commit();
                audit_log($pdo, $user, 'record_fishpond_entry', 'fishpond', $entityType, $entityId, [
                    'pond_id' => $accountId,
                    'record_type' => $fishpondRecordType,
                    'amount' => $amount,
                ]);
                set_flash('success', 'Fishpond record saved.');
                redirect(projects_url_for('fishpond', null, $fishpondRedirectTab));
            }

            $insertEntry = $pdo->prepare('INSERT INTO project_entries (category_id, account_id, entry_datetime, entry_type, quantity, unit, amount, reference_no, notes, created_by)
                VALUES (:category_id, :account_id, :entry_datetime, :entry_type, :quantity, :unit, :amount, :reference_no, :notes, :created_by)');
            $insertEntry->execute([
                'category_id' => $categoryId,
                'account_id' => $accountId > 0 ? $accountId : null,
                'entry_datetime' => $entryDateTime,
                'entry_type' => $entryType,
                'quantity' => $quantity,
                'unit' => $unit !== '' ? $unit : null,
                'amount' => $amount,
                'reference_no' => $referenceNo !== '' ? $referenceNo : null,
                'notes' => $notes !== '' ? $notes : null,
                'created_by' => (int) $user['id'],
            ]);
            $entryId = (int) $pdo->lastInsertId();

            if ($slug === 'fishpond') {
                foreach ($fishpondEntryMeta as $key => $value) {
                    if ($value !== '') {
                        project_entry_meta_set($pdo, $entryId, $key, $value);
                    }
                }
                if ($accountId > 0 && $fishpondEntryMeta['remaining_stock'] !== '') {
                    project_account_meta_set($pdo, $accountId, 'stock_count', $fishpondEntryMeta['remaining_stock']);
                }
                if ($accountId > 0 && $fishpondEntryMeta['fish_growth_stage'] !== '') {
                    project_account_meta_set($pdo, $accountId, 'fish_growth_stage', $fishpondEntryMeta['fish_growth_stage']);
                }
                if ($accountId > 0 && $fishpondEntryMeta['caretaker'] !== '') {
                    project_account_meta_set($pdo, $accountId, 'caretaker', $fishpondEntryMeta['caretaker']);
                }
                foreach (['water_temperature', 'ph_level', 'turbidity', 'water_level'] as $pondWaterKey) {
                    if ($accountId > 0 && $fishpondEntryMeta[$pondWaterKey] !== '') {
                        project_account_meta_set($pdo, $accountId, $pondWaterKey, $fishpondEntryMeta[$pondWaterKey]);
                    }
                }
            }

            if ($syncCash && $amount > 0) {
                $direction = null;
                if (in_array($entryType, ['income', 'payment', 'harvest'], true)) {
                    $direction = 'in';
                } elseif ($entryType === 'expense') {
                    $direction = 'out';
                }

                if ($direction !== null) {
                    $insertCash = $pdo->prepare('INSERT INTO cash_transactions (txn_date, direction, source_module, project_entry_id, amount, or_number, description, created_by)
                        VALUES (:txn_date, :direction, :source_module, :project_entry_id, :amount, :or_number, :description, :created_by)');
                    $insertCash->execute([
                        'txn_date' => $entryDateTime,
                        'direction' => $direction,
                        'source_module' => $module,
                        'project_entry_id' => $entryId,
                        'amount' => $amount,
                        'or_number' => $referenceNo !== '' ? $referenceNo : null,
                        'description' => $categoryById[$categoryId]['name'] . ' - ' . $entryType,
                        'created_by' => (int) $user['id'],
                    ]);
                }
            }

            if ($accountId > 0 && $nextDueDate !== '') {
                $updateDue = $pdo->prepare('UPDATE project_accounts SET next_due_date = :next_due_date WHERE id = :id');
                $updateDue->execute([
                    'next_due_date' => $nextDueDate,
                    'id' => $accountId,
                ]);
            }

            $pdo->commit();
            audit_log($pdo, $user, 'record_entry', 'projects', 'project_entry', $entryId, [
                'category_id' => $categoryId,
                'account_id' => $accountId > 0 ? $accountId : null,
                'entry_type' => $entryType,
                'amount' => $amount,
            ]);
            set_flash('success', $slug === 'fishpond' ? 'Fishpond record saved.' : 'Project entry saved.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            log_system_issue($pdo, 'error', 'Failed to save project entry.', ['error' => $e->getMessage(), 'category_id' => $categoryId], $user);
            set_flash('error', 'Failed to save project entry.');
        }

        redirect(projects_url_for($slug, $slug === 'rental' ? $rentalTypeInput : null, $fishpondRedirectTab));
    }

    if ($action === 'update_pond') {
        require_permission($user, 'manage_projects', 'projects.php');
        $pondId = (int) ($_POST['pond_id'] ?? 0);
        $pondName = trim((string) ($_POST['pond_name'] ?? ''));
        $fishType = trim((string) ($_POST['fish_type'] ?? ''));
        $status = in_array((string) ($_POST['status'] ?? 'active'), ['active', 'inactive'], true) ? (string) $_POST['status'] : 'active';
        $notes = trim((string) ($_POST['notes'] ?? ''));

        if ($pondId <= 0 || $pondName === '') {
            set_flash('error', 'Valid pond details are required.');
            redirect(projects_url_for('fishpond'));
        }

        $stmt = $pdo->prepare('UPDATE ponds SET pond_name = :pond_name, fish_type = :fish_type, status = :status, notes = :notes WHERE id = :id');
        $stmt->execute([
            'id' => $pondId,
            'pond_name' => $pondName,
            'fish_type' => $fishType !== '' ? $fishType : null,
            'status' => $status,
            'notes' => $notes !== '' ? $notes : null,
        ]);
        $accountStmt = $pdo->prepare('UPDATE project_accounts pa INNER JOIN ponds p ON p.project_account_id = pa.id SET pa.account_name = :pond_name, pa.status = :status, pa.notes = :notes WHERE p.id = :id');
        $accountStmt->execute([
            'id' => $pondId,
            'pond_name' => $pondName,
            'status' => $status,
            'notes' => $notes !== '' ? $notes : null,
        ]);
        audit_log($pdo, $user, 'update_pond', 'fishpond', 'pond', $pondId);
        set_flash('success', 'Pond updated.');
        redirect(projects_url_for('fishpond', null, 'ponds'));
    }

    if ($action === 'archive_pond') {
        require_permission($user, 'manage_projects', 'projects.php');
        $pondId = (int) ($_POST['pond_id'] ?? 0);
        if ($pondId <= 0) {
            set_flash('error', 'Invalid pond archive request.');
            redirect(projects_url_for('fishpond'));
        }
        $stmt = $pdo->prepare('UPDATE ponds SET status = "inactive" WHERE id = :id');
        $stmt->execute(['id' => $pondId]);
        $accountStmt = $pdo->prepare('UPDATE project_accounts pa INNER JOIN ponds p ON p.project_account_id = pa.id SET pa.status = "inactive" WHERE p.id = :id');
        $accountStmt->execute(['id' => $pondId]);
        audit_log($pdo, $user, 'archive_pond', 'fishpond', 'pond', $pondId);
        set_flash('success', 'Pond archived.');
        redirect(projects_url_for('fishpond', null, 'ponds'));
    }

    if ($action === 'update_fishpond_expense') {
        require_permission($user, 'manage_projects', 'projects.php');
        $expenseId = (int) ($_POST['expense_id'] ?? 0);
        $pondId = (int) ($_POST['pond_id'] ?? 0);
        $expenseDate = normalize_datetime_input((string) ($_POST['expense_date'] ?? ''));
        $expenseType = trim((string) ($_POST['expense_type'] ?? ''));
        $amount = (float) ($_POST['amount'] ?? 0);
        $remarks = trim((string) ($_POST['remarks'] ?? ''));
        if ($expenseId <= 0 || $pondId <= 0 || $expenseDate === '' || $amount < 0) {
            set_flash('error', 'Valid expense details are required.');
            redirect(projects_url_for('fishpond', null, 'expenses'));
        }
        $stmt = $pdo->prepare('UPDATE fishpond_expenses SET pond_id = :pond_id, expense_date = :expense_date, expense_type = :expense_type, amount = :amount, remarks = :remarks WHERE id = :id AND is_active = 1');
        $stmt->execute([
            'id' => $expenseId,
            'pond_id' => $pondId,
            'expense_date' => $expenseDate,
            'expense_type' => $expenseType !== '' ? $expenseType : null,
            'amount' => $amount,
            'remarks' => $remarks !== '' ? $remarks : null,
        ]);
        set_flash('success', 'Expense updated.');
        redirect(projects_url_for('fishpond', null, 'expenses'));
    }

    if ($action === 'archive_fishpond_expense') {
        require_permission($user, 'manage_projects', 'projects.php');
        $expenseId = (int) ($_POST['expense_id'] ?? 0);
        $stmt = $pdo->prepare('UPDATE fishpond_expenses SET is_active = 0 WHERE id = :id');
        $stmt->execute(['id' => $expenseId]);
        set_flash('success', 'Expense archived.');
        redirect(projects_url_for('fishpond', null, 'expenses'));
    }

    if ($action === 'update_fishpond_harvest') {
        require_permission($user, 'manage_projects', 'projects.php');
        $harvestId = (int) ($_POST['harvest_id'] ?? 0);
        $pondId = (int) ($_POST['pond_id'] ?? 0);
        $harvestDate = normalize_datetime_input((string) ($_POST['harvest_date'] ?? ''));
        $kilos = (float) ($_POST['harvest_kilos'] ?? 0);
        $pricePerKilo = (float) ($_POST['price_per_kilo'] ?? 0);
        $buyer = trim((string) ($_POST['buyer'] ?? ''));
        $remarks = trim((string) ($_POST['remarks'] ?? ''));
        $totalSales = max(0, $kilos * $pricePerKilo);
        if ($harvestId <= 0 || $pondId <= 0 || $harvestDate === '' || $kilos < 0 || $pricePerKilo < 0) {
            set_flash('error', 'Valid harvest details are required.');
            redirect(projects_url_for('fishpond', null, 'harvest'));
        }
        $stmt = $pdo->prepare('UPDATE fishpond_harvest_records SET pond_id = :pond_id, harvest_date = :harvest_date, kilos = :kilos, price_per_kilo = :price_per_kilo, total_sales = :total_sales, buyer = :buyer, remarks = :remarks WHERE id = :id AND is_active = 1');
        $stmt->execute([
            'id' => $harvestId,
            'pond_id' => $pondId,
            'harvest_date' => $harvestDate,
            'kilos' => $kilos,
            'price_per_kilo' => $pricePerKilo,
            'total_sales' => $totalSales,
            'buyer' => $buyer !== '' ? $buyer : null,
            'remarks' => $remarks !== '' ? $remarks : null,
        ]);
        set_flash('success', 'Harvest updated.');
        redirect(projects_url_for('fishpond', null, 'harvest'));
    }

    if ($action === 'archive_fishpond_harvest') {
        require_permission($user, 'manage_projects', 'projects.php');
        $harvestId = (int) ($_POST['harvest_id'] ?? 0);
        $stmt = $pdo->prepare('UPDATE fishpond_harvest_records SET is_active = 0 WHERE id = :id');
        $stmt->execute(['id' => $harvestId]);
        set_flash('success', 'Harvest archived.');
        redirect(projects_url_for('fishpond', null, 'harvest'));
    }

    if ($action === 'edit_category') {
        require_permission($user, 'manage_projects', 'projects.php');
        $categoryId = (int) ($_POST['category_id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        $slugInput = trim((string) ($_POST['slug'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $slug = slugify($slugInput !== '' ? $slugInput : $name);

        if ($categoryId <= 0 || $name === '' || $slug === '') {
            set_flash('error', 'Valid project category details are required.');
            redirect('projects.php');
        }

        try {
            $stmt = $pdo->prepare('UPDATE project_categories
                SET slug = :slug, name = :name, description = :description
                WHERE id = :id');
            $stmt->execute([
                'id' => $categoryId,
                'slug' => $slug,
                'name' => $name,
                'description' => $description !== '' ? $description : null,
            ]);
            audit_log($pdo, $user, 'edit_category', 'projects', 'project_category', $categoryId, [
                'name' => $name,
                'slug' => $slug,
            ]);
            set_flash('success', 'Project category updated.');
            redirect('projects.php?category=' . urlencode($slug));
        } catch (PDOException $e) {
            log_system_issue($pdo, 'error', 'Could not edit project category.', ['error' => $e->getMessage(), 'category_id' => $categoryId], $user);
            set_flash('error', 'Could not update category. Slug may already exist.');
            redirect('projects.php');
        }
    }

    if ($action === 'add_toga') {
        $togaCategory = $categoryBySlug['toga'] ?? null;
        $personId = (int) ($_POST['person_id'] ?? 0);
        $person = find_person($pdo, $personId, true);
        $studentName = $person ? (string) $person['full_name'] : trim((string) ($_POST['student_name'] ?? ''));
        $studentId = $person ? (string) ($person['person_code'] ?? '') : trim((string) ($_POST['student_id'] ?? ''));
        $program = $person ? (string) ($person['department'] ?? '') : trim((string) ($_POST['program'] ?? ''));
        $releaseDate = trim((string) ($_POST['release_date'] ?? date('Y-m-d')));
        $depositAmount = (float) ($_POST['deposit_amount'] ?? 0);
        $feeAmount = (float) ($_POST['fee_amount'] ?? 0);
        $notes = trim((string) ($_POST['notes'] ?? ''));
        $syncCash = isset($_POST['sync_cash']) && $_POST['sync_cash'] === '1';

        if (!$togaCategory) {
            set_flash('error', 'Toga category is not available.');
            redirect('projects.php');
        }

        if (!$person || $studentName === '') {
            set_flash('error', 'Select an approved person for the toga release.');
            redirect('projects.php?category=toga');
        }

        if ($depositAmount < 0 || $feeAmount < 0) {
            set_flash('error', 'Amounts cannot be negative.');
            redirect('projects.php?category=toga');
        }

        if (user_requires_admin_approval($user) && ($depositAmount > 0 || $feeAmount > 0)) {
            create_approval_request($pdo, (int) $user['id'], 'projects', 'add_toga_release', 'project_account', null, null, [
                'category_id' => (int) $togaCategory['id'],
                'person_id' => (int) $person['id'],
                'student_name' => $studentName,
                'student_id' => $studentId,
                'program' => $program,
                'release_date' => $releaseDate,
                'deposit_amount' => $depositAmount,
                'fee_amount' => $feeAmount,
                'notes' => $notes,
                'sync_cash' => $syncCash,
            ]);
            set_flash('success', 'Request submitted for admin approval.');
            redirect(projects_url_for('toga', 'toga', 'accounts'));
        }

        try {
            $pdo->beginTransaction();

            $totalAmount = $depositAmount + $feeAmount;
            $insertAccount = $pdo->prepare('INSERT INTO project_accounts (category_id, person_id, account_name, code, contact_name, start_date, expected_amount, status, notes)
                VALUES (:category_id, :person_id, :account_name, :code, :contact_name, :start_date, :expected_amount, "active", :notes)');
            $insertAccount->execute([
                'category_id' => (int) $togaCategory['id'],
                'person_id' => (int) $person['id'],
                'account_name' => $studentName,
                'code' => $studentId !== '' ? $studentId : null,
                'contact_name' => $program !== '' ? $program : null,
                'start_date' => $releaseDate,
                'expected_amount' => $totalAmount,
                'notes' => $notes !== '' ? $notes : null,
            ]);
            $accountId = (int) $pdo->lastInsertId();

            project_account_meta_set($pdo, $accountId, 'toga_status', 'released');
            project_account_meta_set($pdo, $accountId, 'deposit_amount', number_format($depositAmount, 2, '.', ''));
            project_account_meta_set($pdo, $accountId, 'fee_amount', number_format($feeAmount, 2, '.', ''));
            project_account_meta_set($pdo, $accountId, 'program', $program !== '' ? $program : null);
            project_account_meta_set($pdo, $accountId, 'return_date', null);

            $insertEntry = $pdo->prepare('INSERT INTO project_entries (category_id, account_id, entry_datetime, entry_type, amount, notes, created_by)
                VALUES (:category_id, :account_id, :entry_datetime, "payment", :amount, :notes, :created_by)');
            $insertEntry->execute([
                'category_id' => (int) $togaCategory['id'],
                'account_id' => $accountId,
                'entry_datetime' => $releaseDate . ' 00:00:00',
                'amount' => $totalAmount,
                'notes' => $notes !== '' ? $notes : 'Toga release fee/deposit',
                'created_by' => (int) $user['id'],
            ]);
            $entryId = (int) $pdo->lastInsertId();
            project_entry_meta_set($pdo, $entryId, 'entry_event', 'toga_release');

            if ($syncCash && $totalAmount > 0) {
                $cashStmt = $pdo->prepare('INSERT INTO cash_transactions (txn_date, direction, source_module, project_entry_id, amount, description, created_by)
                    VALUES (:txn_date, "in", "toga", :project_entry_id, :amount, :description, :created_by)');
                $cashStmt->execute([
                    'txn_date' => $releaseDate . ' 00:00:00',
                    'project_entry_id' => $entryId,
                    'amount' => $totalAmount,
                    'description' => 'Toga release fee/deposit - ' . $studentName,
                    'created_by' => (int) $user['id'],
                ]);
            }

            $pdo->commit();
            audit_log($pdo, $user, 'create_toga_release', 'projects', 'project_account', $accountId, [
                'student_name' => $studentName,
                'deposit_amount' => $depositAmount,
                'fee_amount' => $feeAmount,
            ]);
            set_flash('success', 'Toga release saved under the Toga project category.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            log_system_issue($pdo, 'error', 'Failed to save toga release.', ['error' => $e->getMessage(), 'student_name' => $studentName], $user);
            set_flash('error', 'Failed to save toga release.');
        }

        redirect(projects_url_for('toga', 'toga', 'accounts'));
    }

    if ($action === 'mark_toga_returned') {
        $accountId = (int) ($_POST['account_id'] ?? 0);
        $returnDate = trim((string) ($_POST['return_date'] ?? date('Y-m-d')));
        $refundAmount = (float) ($_POST['refund_amount'] ?? 0);

        if ($accountId <= 0 || $refundAmount < 0) {
            set_flash('error', 'Invalid toga return details.');
            redirect('projects.php?category=toga');
        }

        $accountStmt = $pdo->prepare('SELECT pa.id, pa.category_id, pa.account_name
            FROM project_accounts pa
            INNER JOIN project_categories pc ON pc.id = pa.category_id
            WHERE pa.id = :id AND pc.slug = "toga"
            ');
        $accountStmt->execute(['id' => $accountId]);
        $account = $accountStmt->fetch();
        if (!$account) {
            set_flash('error', 'Invalid toga record.');
            redirect('projects.php?category=toga');
        }

        if (user_requires_admin_approval($user) && $refundAmount > 0) {
            create_approval_request($pdo, (int) $user['id'], 'projects', 'mark_toga_returned', 'project_account', (string) $accountId, [
                'status' => 'released',
            ], [
                'account_id' => $accountId,
                'return_date' => $returnDate,
                'refund_amount' => $refundAmount,
            ]);
            set_flash('success', 'Request submitted for admin approval.');
            redirect(projects_url_for('toga', 'toga', 'accounts'));
        }

        try {
            $pdo->beginTransaction();

            $update = $pdo->prepare('UPDATE project_accounts SET status = "inactive" WHERE id = :id');
            $update->execute(['id' => $accountId]);
            project_account_meta_set($pdo, $accountId, 'toga_status', 'returned');
            project_account_meta_set($pdo, $accountId, 'return_date', $returnDate);

            $entryType = $refundAmount > 0 ? 'expense' : 'monitoring';
            $insertEntry = $pdo->prepare('INSERT INTO project_entries (category_id, account_id, entry_datetime, entry_type, amount, notes, created_by)
                VALUES (:category_id, :account_id, :entry_datetime, :entry_type, :amount, :notes, :created_by)');
            $insertEntry->execute([
                'category_id' => (int) $account['category_id'],
                'account_id' => $accountId,
                'entry_datetime' => $returnDate . ' 00:00:00',
                'entry_type' => $entryType,
                'amount' => $refundAmount,
                'notes' => $refundAmount > 0 ? 'Toga returned with deposit refund' : 'Toga returned',
                'created_by' => (int) $user['id'],
            ]);
            $entryId = (int) $pdo->lastInsertId();
            project_entry_meta_set($pdo, $entryId, 'entry_event', 'toga_returned');

            if ($refundAmount > 0) {
                $cashStmt = $pdo->prepare('INSERT INTO cash_transactions (txn_date, direction, source_module, project_entry_id, amount, description, created_by)
                    VALUES (:txn_date, "out", "toga", :project_entry_id, :amount, :description, :created_by)');
                $cashStmt->execute([
                    'txn_date' => $returnDate . ' 00:00:00',
                    'project_entry_id' => $entryId,
                    'amount' => $refundAmount,
                    'description' => 'Toga deposit refund - ' . (string) $account['account_name'],
                    'created_by' => (int) $user['id'],
                ]);
            }

            $pdo->commit();
            audit_log($pdo, $user, 'mark_toga_returned', 'projects', 'project_account', $accountId, [
                'return_date' => $returnDate,
                'refund_amount' => $refundAmount,
            ]);
            set_flash('success', 'Toga marked as returned.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            log_system_issue($pdo, 'error', 'Failed to mark toga as returned.', ['error' => $e->getMessage(), 'account_id' => $accountId], $user);
            set_flash('error', 'Failed to mark toga as returned.');
        }

        redirect(projects_url_for('toga', 'toga', 'accounts'));
    }

    if ($action === 'mark_toga_forfeited') {
        $accountId = (int) ($_POST['account_id'] ?? 0);

        if ($accountId <= 0) {
            set_flash('error', 'Invalid toga record.');
            redirect('projects.php?category=toga');
        }

        $accountStmt = $pdo->prepare('SELECT pa.id, pa.category_id
            FROM project_accounts pa
            INNER JOIN project_categories pc ON pc.id = pa.category_id
            WHERE pa.id = :id AND pc.slug = "toga"
            ');
        $accountStmt->execute(['id' => $accountId]);
        $account = $accountStmt->fetch();
        if (!$account) {
            set_flash('error', 'Invalid toga record.');
            redirect('projects.php?category=toga');
        }

        if (user_requires_admin_approval($user)) {
            create_approval_request($pdo, (int) $user['id'], 'projects', 'mark_toga_forfeited', 'project_account', (string) $accountId, [
                'status' => 'released',
            ], [
                'account_id' => $accountId,
            ]);
            set_flash('success', 'Request submitted for admin approval.');
            redirect(projects_url_for('toga', 'toga', 'accounts'));
        }

        try {
            $pdo->beginTransaction();
            $update = $pdo->prepare('UPDATE project_accounts SET status = "inactive" WHERE id = :id');
            $update->execute(['id' => $accountId]);
            project_account_meta_set($pdo, $accountId, 'toga_status', 'forfeited');

            $insertEntry = $pdo->prepare('INSERT INTO project_entries (category_id, account_id, entry_datetime, entry_type, amount, notes, created_by)
                VALUES (:category_id, :account_id, NOW(), "monitoring", 0, "Toga deposit forfeited", :created_by)');
            $insertEntry->execute([
                'category_id' => (int) $account['category_id'],
                'account_id' => $accountId,
                'created_by' => (int) $user['id'],
            ]);
            $entryId = (int) $pdo->lastInsertId();
            project_entry_meta_set($pdo, $entryId, 'entry_event', 'toga_forfeited');

            $pdo->commit();
            audit_log($pdo, $user, 'mark_toga_forfeited', 'projects', 'project_account', $accountId);
            set_flash('success', 'Toga marked as forfeited.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            log_system_issue($pdo, 'error', 'Failed to mark toga as forfeited.', ['error' => $e->getMessage(), 'account_id' => $accountId], $user);
            set_flash('error', 'Failed to mark toga as forfeited.');
        }

        redirect(projects_url_for('toga', 'toga', 'accounts'));
    }
}

$requestedCategorySlug = trim((string) ($_GET['category'] ?? 'all'));
if ($requestedCategorySlug === 'all' && !isset($_GET['view'])) {
    redirect(projects_url_for('fishpond'));
}
$rentalType = normalize_rental_type((string) ($_GET['rental_type'] ?? ($requestedCategorySlug === 'toga' ? 'toga' : 'stall')));
$isRentalManagement = in_array($requestedCategorySlug, ['rental', 'toga'], true);
$selectedCategorySlug = $isRentalManagement
    ? ($rentalType === 'toga' ? 'toga' : 'rental')
    : $requestedCategorySlug;
$selectedCategory = $selectedCategorySlug !== 'all' && isset($categoryBySlug[$selectedCategorySlug])
    ? $categoryBySlug[$selectedCategorySlug]
    : null;
if ($selectedCategory === null && $selectedCategorySlug !== 'all') {
    redirect(projects_url_for('fishpond'));
}

$selectedCategoryId = $selectedCategory ? (int) $selectedCategory['id'] : null;
$isTogaView = $selectedCategorySlug === 'toga';
$isFishpondManagement = $selectedCategorySlug === 'fishpond';
$isProjectOverview = $selectedCategory === null;
$rentalLabel = rental_type_label($rentalType);
$rentalSingularLabel = rental_account_label($rentalType);
$accountStatusFilter = (string) ($_GET['account_status'] ?? 'all');
if ($isFishpondManagement && !isset($_GET['account_status'])) {
    $accountStatusFilter = 'active';
}
$accountSearch = trim((string) ($_GET['q'] ?? ''));
$projectTab = (string) ($_GET['tab'] ?? 'accounts');
$validProjectTabs = $isFishpondManagement
    ? ['ponds', 'expenses', 'harvest']
    : ['accounts', 'entries', 'overdue', 'performance'];
if (!in_array($projectTab, $validProjectTabs, true)) {
    $projectTab = $isFishpondManagement ? 'ponds' : 'accounts';
}
$validAccountStatuses = ['active', 'inactive', 'released', 'returned', 'forfeited'];

$autoOpenAddProject = (isset($_GET['view']) && (string) $_GET['view'] === 'add-project' && user_can($user, 'manage_projects'));

$accountsSql = 'SELECT pa.id, pa.category_id, pa.person_id, pa.account_name, pa.code, pa.contact_name, pa.start_date, pa.next_due_date, pa.expected_amount, pa.status, pa.notes,
        pc.name AS category_name, pc.slug AS category_slug,
        person.full_name AS person_full_name,
        person.person_code AS person_code,
        person.department AS person_department,
        person.role_or_position AS person_role,
        status_meta.meta_value AS toga_status,
        return_meta.meta_value AS return_date,
        deposit_meta.meta_value AS deposit_amount,
        fee_meta.meta_value AS fee_amount,
        rental_type_meta.meta_value AS rental_type,
        fish_type_meta.meta_value AS fish_type,
        stock_count_meta.meta_value AS stock_count,
        survival_rate_meta.meta_value AS survival_rate,
        water_temperature_meta.meta_value AS water_temperature,
        ph_level_meta.meta_value AS ph_level,
        turbidity_meta.meta_value AS turbidity,
        water_level_meta.meta_value AS water_level,
        fish_growth_stage_meta.meta_value AS fish_growth_stage,
        caretaker_meta.meta_value AS caretaker
    FROM project_accounts pa
    INNER JOIN project_categories pc ON pc.id = pa.category_id
    LEFT JOIN people person ON person.id = pa.person_id
    LEFT JOIN project_account_meta status_meta
        ON status_meta.account_id = pa.id AND status_meta.meta_key = "toga_status"
    LEFT JOIN project_account_meta return_meta
        ON return_meta.account_id = pa.id AND return_meta.meta_key = "return_date"
    LEFT JOIN project_account_meta deposit_meta
        ON deposit_meta.account_id = pa.id AND deposit_meta.meta_key = "deposit_amount"
    LEFT JOIN project_account_meta fee_meta
        ON fee_meta.account_id = pa.id AND fee_meta.meta_key = "fee_amount"
    LEFT JOIN project_account_meta rental_type_meta
        ON rental_type_meta.account_id = pa.id AND rental_type_meta.meta_key = "rental_type"
    LEFT JOIN project_account_meta fish_type_meta
        ON fish_type_meta.account_id = pa.id AND fish_type_meta.meta_key = "fish_type"
    LEFT JOIN project_account_meta stock_count_meta
        ON stock_count_meta.account_id = pa.id AND stock_count_meta.meta_key = "stock_count"
    LEFT JOIN project_account_meta survival_rate_meta
        ON survival_rate_meta.account_id = pa.id AND survival_rate_meta.meta_key = "survival_rate"
    LEFT JOIN project_account_meta water_temperature_meta
        ON water_temperature_meta.account_id = pa.id AND water_temperature_meta.meta_key = "water_temperature"
    LEFT JOIN project_account_meta ph_level_meta
        ON ph_level_meta.account_id = pa.id AND ph_level_meta.meta_key = "ph_level"
    LEFT JOIN project_account_meta turbidity_meta
        ON turbidity_meta.account_id = pa.id AND turbidity_meta.meta_key = "turbidity"
    LEFT JOIN project_account_meta water_level_meta
        ON water_level_meta.account_id = pa.id AND water_level_meta.meta_key = "water_level"
    LEFT JOIN project_account_meta fish_growth_stage_meta
        ON fish_growth_stage_meta.account_id = pa.id AND fish_growth_stage_meta.meta_key = "fish_growth_stage"
    LEFT JOIN project_account_meta caretaker_meta
        ON caretaker_meta.account_id = pa.id AND caretaker_meta.meta_key = "caretaker"
    WHERE pc.is_active = 1';
$accountsParams = [];
if ($selectedCategoryId !== null) {
    $accountsSql .= ' AND pa.category_id = :category_id';
    $accountsParams['category_id'] = $selectedCategoryId;
}
if ($isRentalManagement && !$isTogaView) {
    $accountsSql .= ' AND COALESCE(rental_type_meta.meta_value, "stall") = :rental_type';
    $accountsParams['rental_type'] = $rentalType;
}
if (in_array($accountStatusFilter, $validAccountStatuses, true)) {
    if (in_array($accountStatusFilter, ['released', 'returned', 'forfeited'], true)) {
        if ($accountStatusFilter === 'released') {
            $accountsSql .= ' AND (status_meta.meta_value = :account_status OR (pc.slug = "toga" AND status_meta.meta_value IS NULL AND pa.status = "active"))';
        } elseif ($accountStatusFilter === 'returned') {
            $accountsSql .= ' AND (status_meta.meta_value = :account_status OR (pc.slug = "toga" AND status_meta.meta_value IS NULL AND pa.status = "inactive"))';
        } else {
            $accountsSql .= ' AND status_meta.meta_value = :account_status';
        }
    } else {
        $accountsSql .= ' AND pa.status = :account_status';
    }
    $accountsParams['account_status'] = $accountStatusFilter;
}
if ($accountSearch !== '') {
    $accountsSql .= ' AND (pa.account_name LIKE :account_search_name OR pa.code LIKE :account_search_code OR pa.contact_name LIKE :account_search_contact OR pa.notes LIKE :account_search_notes OR person.full_name LIKE :account_search_person OR person.person_code LIKE :account_search_person_code OR person.department LIKE :account_search_department OR person.role_or_position LIKE :account_search_role)';
    $accountsParams['account_search_name'] = prefix_search_param($accountSearch);
    $accountsParams['account_search_code'] = prefix_search_param($accountSearch);
    $accountsParams['account_search_contact'] = prefix_search_param($accountSearch);
    $accountsParams['account_search_notes'] = prefix_search_param($accountSearch);
    $accountsParams['account_search_person'] = prefix_search_param($accountSearch);
    $accountsParams['account_search_person_code'] = prefix_search_param($accountSearch);
    $accountsParams['account_search_department'] = prefix_search_param($accountSearch);
    $accountsParams['account_search_role'] = prefix_search_param($accountSearch);
}
$accountsSql .= ' ORDER BY pa.category_id, pa.account_name';
$accountsStmt = $pdo->prepare($accountsSql);
$accountsStmt->execute($accountsParams);
$accounts = $accountsStmt->fetchAll();

$entryAccountsSql = 'SELECT pa.id, pa.category_id, CASE WHEN pc.slug = "fishpond" THEN pa.account_name ELSE COALESCE(person.full_name, pa.account_name) END AS account_name, pc.name AS category_name
    FROM project_accounts pa
    INNER JOIN project_categories pc ON pc.id = pa.category_id
    LEFT JOIN people person ON person.id = pa.person_id
    LEFT JOIN project_account_meta rental_type_meta
        ON rental_type_meta.account_id = pa.id AND rental_type_meta.meta_key = "rental_type"
    WHERE pc.is_active = 1';
$entryAccountsParams = [];
if ($selectedCategoryId !== null) {
    $entryAccountsSql .= ' AND pa.category_id = :category_id';
    $entryAccountsParams['category_id'] = $selectedCategoryId;
}
if ($isRentalManagement && !$isTogaView) {
    $entryAccountsSql .= ' AND COALESCE(rental_type_meta.meta_value, "stall") = :rental_type';
    $entryAccountsParams['rental_type'] = $rentalType;
}
$entryAccountsSql .= ' ORDER BY pc.name, pa.account_name';
$entryAccountsStmt = $pdo->prepare($entryAccountsSql);
$entryAccountsStmt->execute($entryAccountsParams);
$entryAccounts = $entryAccountsStmt->fetchAll();

$from = trim((string) ($_GET['from'] ?? date('Y-m-01')));
$to = trim((string) ($_GET['to'] ?? date('Y-m-d')));
[$fromDateTime, $toDateTimeExclusive] = date_filter_bounds($from, $to);

$entryWhere = ['pe.entry_datetime >= :from_dt AND pe.entry_datetime < :to_dt'];
$entryParams = ['from_dt' => $fromDateTime, 'to_dt' => $toDateTimeExclusive];
if ($selectedCategoryId !== null) {
    $entryWhere[] = 'pe.category_id = :category_id';
    $entryParams['category_id'] = $selectedCategoryId;
}
if ($isRentalManagement && !$isTogaView) {
    $entryWhere[] = 'COALESCE((SELECT pam.meta_value FROM project_account_meta pam WHERE pam.account_id = pe.account_id AND pam.meta_key = "rental_type" LIMIT 1), "stall") = :rental_type';
    $entryParams['rental_type'] = $rentalType;
}

$entriesCountSql = 'SELECT COUNT(*)
    FROM project_entries pe
    INNER JOIN project_categories pc ON pc.id = pe.category_id
    LEFT JOIN project_accounts pa ON pa.id = pe.account_id
    WHERE ' . implode(' AND ', $entryWhere);
$entriesCountStmt = $pdo->prepare($entriesCountSql);
$entriesCountStmt->execute($entryParams);
$entriesPagination = pagination_meta((int) $entriesCountStmt->fetchColumn(), page_param(), 10);

$fishpondEntryMetaSelect = ',
            record_type_meta.meta_value AS fishpond_record_type,
            water_temperature_meta.meta_value AS water_temperature,
            ph_level_meta.meta_value AS ph_level,
            turbidity_meta.meta_value AS turbidity,
            water_level_meta.meta_value AS water_level,
            feed_quantity_meta.meta_value AS feed_quantity,
            mortality_count_meta.meta_value AS mortality_count,
            harvest_kilos_meta.meta_value AS harvest_kilos,
            fish_growth_stage_meta.meta_value AS fish_growth_stage,
            caretaker_meta.meta_value AS caretaker,
            movement_type_meta.meta_value AS movement_type,
            remaining_stock_meta.meta_value AS remaining_stock,
            feed_type_meta.meta_value AS feed_type,
            expense_type_meta.meta_value AS expense_type,
            buyer_meta.meta_value AS buyer,
            price_per_kilo_meta.meta_value AS price_per_kilo';
$fishpondEntryMetaJoins = '
        LEFT JOIN project_entry_meta record_type_meta
            ON record_type_meta.entry_id = pe.id AND record_type_meta.meta_key = "fishpond_record_type"
        LEFT JOIN project_entry_meta water_temperature_meta
            ON water_temperature_meta.entry_id = pe.id AND water_temperature_meta.meta_key = "water_temperature"
        LEFT JOIN project_entry_meta ph_level_meta
            ON ph_level_meta.entry_id = pe.id AND ph_level_meta.meta_key = "ph_level"
        LEFT JOIN project_entry_meta turbidity_meta
            ON turbidity_meta.entry_id = pe.id AND turbidity_meta.meta_key = "turbidity"
        LEFT JOIN project_entry_meta water_level_meta
            ON water_level_meta.entry_id = pe.id AND water_level_meta.meta_key = "water_level"
        LEFT JOIN project_entry_meta feed_quantity_meta
            ON feed_quantity_meta.entry_id = pe.id AND feed_quantity_meta.meta_key = "feed_quantity"
        LEFT JOIN project_entry_meta mortality_count_meta
            ON mortality_count_meta.entry_id = pe.id AND mortality_count_meta.meta_key = "mortality_count"
        LEFT JOIN project_entry_meta harvest_kilos_meta
            ON harvest_kilos_meta.entry_id = pe.id AND harvest_kilos_meta.meta_key = "harvest_kilos"
        LEFT JOIN project_entry_meta fish_growth_stage_meta
            ON fish_growth_stage_meta.entry_id = pe.id AND fish_growth_stage_meta.meta_key = "fish_growth_stage"
        LEFT JOIN project_entry_meta caretaker_meta
            ON caretaker_meta.entry_id = pe.id AND caretaker_meta.meta_key = "caretaker"
        LEFT JOIN project_entry_meta movement_type_meta
            ON movement_type_meta.entry_id = pe.id AND movement_type_meta.meta_key = "movement_type"
        LEFT JOIN project_entry_meta remaining_stock_meta
            ON remaining_stock_meta.entry_id = pe.id AND remaining_stock_meta.meta_key = "remaining_stock"
        LEFT JOIN project_entry_meta feed_type_meta
            ON feed_type_meta.entry_id = pe.id AND feed_type_meta.meta_key = "feed_type"
        LEFT JOIN project_entry_meta expense_type_meta
            ON expense_type_meta.entry_id = pe.id AND expense_type_meta.meta_key = "expense_type"
        LEFT JOIN project_entry_meta buyer_meta
            ON buyer_meta.entry_id = pe.id AND buyer_meta.meta_key = "buyer"
        LEFT JOIN project_entry_meta price_per_kilo_meta
            ON price_per_kilo_meta.entry_id = pe.id AND price_per_kilo_meta.meta_key = "price_per_kilo"';

$entriesSql = 'SELECT id, account_id, entry_datetime, entry_type, quantity, unit, amount, reference_no, notes, category_name, category_slug, account_name,
        fishpond_record_type, water_temperature, ph_level, turbidity, water_level, feed_quantity, mortality_count, harvest_kilos, fish_growth_stage, caretaker, movement_type, remaining_stock, feed_type, expense_type, buyer, price_per_kilo
    FROM (
        SELECT pe.id, pe.account_id, pe.entry_datetime, pe.entry_type, pe.quantity, pe.unit, pe.amount, pe.reference_no, pe.notes,
            pc.name AS category_name, pc.slug AS category_slug, CASE WHEN pc.slug = "fishpond" THEN pa.account_name ELSE COALESCE(person.full_name, pa.account_name) END AS account_name' . $fishpondEntryMetaSelect . ',
            ROW_NUMBER() OVER (ORDER BY pe.entry_datetime DESC, pe.id DESC) AS row_num
        FROM project_entries pe
        INNER JOIN project_categories pc ON pc.id = pe.category_id
        LEFT JOIN project_accounts pa ON pa.id = pe.account_id
        LEFT JOIN people person ON person.id = pa.person_id' . $fishpondEntryMetaJoins . '
        WHERE ' . implode(' AND ', $entryWhere) . '
    ) ranked_entries
    WHERE row_num BETWEEN :first_row AND :last_row
    ORDER BY row_num';
$entriesStmt = $pdo->prepare($entriesSql);
foreach ($entryParams as $key => $value) {
    $entriesStmt->bindValue(':' . ltrim((string) $key, ':'), $value);
}
[$firstRow, $lastRow] = pagination_row_bounds($entriesPagination);
$entriesStmt->bindValue(':first_row', $firstRow, PDO::PARAM_INT);
$entriesStmt->bindValue(':last_row', $lastRow, PDO::PARAM_INT);
$entriesStmt->execute();
$entries = $entriesStmt->fetchAll();

$fishpondEntries = [];
if ($isFishpondManagement) {
    $fishpondEntriesSql = 'SELECT pe.id, pe.account_id, pe.entry_datetime, pe.entry_type, pe.quantity, pe.unit, pe.amount, pe.reference_no, pe.notes,
            pc.name AS category_name, pc.slug AS category_slug, CASE WHEN pc.slug = "fishpond" THEN pa.account_name ELSE COALESCE(person.full_name, pa.account_name) END AS account_name' . $fishpondEntryMetaSelect . '
        FROM project_entries pe
        INNER JOIN project_categories pc ON pc.id = pe.category_id
        LEFT JOIN project_accounts pa ON pa.id = pe.account_id
        LEFT JOIN people person ON person.id = pa.person_id' . $fishpondEntryMetaJoins . '
        WHERE ' . implode(' AND ', $entryWhere) . '
        ORDER BY pe.entry_datetime DESC, pe.id DESC';
    $fishpondEntriesStmt = $pdo->prepare($fishpondEntriesSql);
    $fishpondEntriesStmt->execute($entryParams);
    $fishpondEntries = $fishpondEntriesStmt->fetchAll();
}

$summarySql = 'SELECT
        COALESCE(SUM(CASE WHEN pe.entry_type IN ("income", "payment", "harvest") THEN pe.amount ELSE 0 END), 0) AS total_income,
        COALESCE(SUM(CASE WHEN pe.entry_type = "expense" THEN pe.amount ELSE 0 END), 0) AS total_expense
    FROM project_entries pe
    WHERE ' . implode(' AND ', $entryWhere);
$summaryStmt = $pdo->prepare($summarySql);
$summaryStmt->execute($entryParams);
$summary = $summaryStmt->fetch();

$incomeByCategorySql = 'SELECT pc.name,
        COALESCE(SUM(CASE WHEN pe.entry_type IN ("income", "payment", "harvest") THEN pe.amount ELSE 0 END), 0) AS income,
        COALESCE(SUM(CASE WHEN pe.entry_type = "expense" THEN pe.amount ELSE 0 END), 0) AS expense
    FROM project_categories pc
    LEFT JOIN project_entries pe
        ON pe.category_id = pc.id
        AND pe.entry_datetime >= :from_dt AND pe.entry_datetime < :to_dt
    WHERE pc.is_active = 1
    GROUP BY pc.id, pc.name
    ORDER BY pc.name';
$incomeByCategoryStmt = $pdo->prepare($incomeByCategorySql);
$incomeByCategoryStmt->execute([
    'from_dt' => $fromDateTime,
    'to_dt' => $toDateTimeExclusive,
]);
$incomeByCategory = $incomeByCategoryStmt->fetchAll();

$overdueSql = 'SELECT COALESCE(person.full_name, pa.account_name) AS account_name, pa.code, pa.next_due_date, pa.expected_amount, pc.name AS category_name
    FROM project_accounts pa
    INNER JOIN project_categories pc ON pc.id = pa.category_id
    LEFT JOIN people person ON person.id = pa.person_id
    LEFT JOIN project_account_meta rental_type_meta
        ON rental_type_meta.account_id = pa.id AND rental_type_meta.meta_key = "rental_type"
    WHERE pa.status = "active" AND pa.next_due_date IS NOT NULL AND pa.next_due_date < CURDATE()';
$overdueParams = [];
if ($selectedCategoryId !== null) {
    $overdueSql .= ' AND pa.category_id = :category_id';
    $overdueParams['category_id'] = $selectedCategoryId;
}
if ($isRentalManagement && !$isTogaView) {
    $overdueSql .= ' AND COALESCE(rental_type_meta.meta_value, "stall") = :rental_type';
    $overdueParams['rental_type'] = $rentalType;
}
$overdueSql .= ' ORDER BY pa.next_due_date ASC, pa.account_name ASC';
$overdueStmt = $pdo->prepare($overdueSql);
$overdueStmt->execute($overdueParams);
$overdues = $overdueStmt->fetchAll();

$rentalActiveCount = 0;
$rentalReturnedCount = 0;
$rentalForfeitedCount = 0;
if ($isRentalManagement) {
    foreach ($accounts as $account) {
        $rentalStatus = $account['category_slug'] === 'toga'
            ? (string) ($account['toga_status'] ?: ($account['status'] === 'active' ? 'released' : 'returned'))
            : (string) $account['status'];
        if (in_array($rentalStatus, ['active', 'released'], true)) {
            $rentalActiveCount++;
        } elseif ($rentalStatus === 'returned') {
            $rentalReturnedCount++;
        } elseif ($rentalStatus === 'forfeited') {
            $rentalForfeitedCount++;
        }
    }
}

$rentalOverdueCounts = array_fill_keys(array_keys(rental_type_options()), 0);
if ($isRentalManagement) {
    $rentalCountRows = $pdo->query('SELECT COALESCE(rental_type_meta.meta_value, "stall") AS rental_type, COUNT(*) AS count_rows
        FROM project_accounts pa
        INNER JOIN project_categories pc ON pc.id = pa.category_id
        LEFT JOIN project_account_meta rental_type_meta
            ON rental_type_meta.account_id = pa.id AND rental_type_meta.meta_key = "rental_type"
        WHERE pc.slug = "rental" AND pa.status = "active" AND pa.next_due_date IS NOT NULL AND pa.next_due_date < CURDATE()
        GROUP BY COALESCE(rental_type_meta.meta_value, "stall")')->fetchAll();
    foreach ($rentalCountRows as $row) {
        $type = normalize_rental_type((string) ($row['rental_type'] ?? 'stall'));
        $rentalOverdueCounts[$type] = ($rentalOverdueCounts[$type] ?? 0) + (int) $row['count_rows'];
    }
    $togaOverdueStmt = $pdo->query('SELECT COUNT(*)
        FROM project_accounts pa
        INNER JOIN project_categories pc ON pc.id = pa.category_id
        WHERE pc.slug = "toga" AND pa.status = "active" AND pa.next_due_date IS NOT NULL AND pa.next_due_date < CURDATE()');
    $rentalOverdueCounts['toga'] = (int) $togaOverdueStmt->fetchColumn();
}

$pendingProposals = (int) $pdo->query('SELECT COUNT(*) FROM proposals WHERE status IN ("submitted", "under_review")')->fetchColumn();
// Include both approved and pending people so newly added people appear immediately in the selector
$people = people_options($pdo, false);
$caretakers = caretaker_options($pdo);
$tenants = tenant_options($pdo);
$ponds = $isFishpondManagement ? pond_options($pdo) : [];
if ($isFishpondManagement) {
    $pondWhere = ['LOWER(TRIM(p.pond_name)) <> "general"', 'LOWER(TRIM(COALESCE(p.pond_code, ""))) <> "general"'];
    $pondParams = [];
    if (in_array($accountStatusFilter, ['active', 'inactive'], true)) {
        $pondWhere[] = 'p.status = :pond_status';
        $pondParams['pond_status'] = $accountStatusFilter;
    }
    if ($accountSearch !== '') {
        $pondWhere[] = '(p.pond_name LIKE :pond_q_name OR p.pond_code LIKE :pond_q_code OR p.fish_type LIKE :pond_q_fish)';
        $pondParams['pond_q_name'] = prefix_search_param($accountSearch);
        $pondParams['pond_q_code'] = prefix_search_param($accountSearch);
        $pondParams['pond_q_fish'] = prefix_search_param($accountSearch);
    }

    $pondStmt = $pdo->prepare('SELECT
            p.id,
            NULL AS category_id,
            p.caretaker_id AS person_id,
            p.pond_name AS account_name,
            p.pond_code AS code,
            caretaker.full_name AS contact_name,
            NULL AS start_date,
            NULL AS next_due_date,
            NULL AS expected_amount,
            p.status,
            p.notes,
            "Fishpond" AS category_name,
            "fishpond" AS category_slug,
            caretaker.full_name AS person_full_name,
            caretaker.person_code AS person_code,
            caretaker.department AS person_department,
            caretaker.role_or_position AS person_role,
            NULL AS toga_status,
            NULL AS return_date,
            NULL AS deposit_amount,
            NULL AS fee_amount,
            NULL AS rental_type,
            p.fish_type,
            p.stock_count,
            p.survival_rate,
            NULL AS water_temperature,
            NULL AS ph_level,
            NULL AS turbidity,
            NULL AS water_level,
            p.fish_growth_stage,
            caretaker.full_name AS caretaker,
            p.updated_at
        FROM ponds p
        LEFT JOIN people caretaker ON caretaker.id = p.caretaker_id
        WHERE ' . implode(' AND ', $pondWhere) . '
        ORDER BY p.pond_name ASC, p.pond_code ASC');
    $pondStmt->execute($pondParams);
    $accounts = $pondStmt->fetchAll();

    $recordRows = [];
    $expenseSearchSql = '';
    $harvestSearchSql = '';
    $recordSearchParams = [];
    if ($accountSearch !== '') {
        $expenseSearchSql = ' AND (p.pond_name LIKE :q_pond OR p.pond_code LIKE :q_code OR p.fish_type LIKE :q_fish OR ex.expense_type LIKE :q_expense_type OR ex.remarks LIKE :q_remarks)';
        $harvestSearchSql = ' AND (p.pond_name LIKE :q_pond OR p.pond_code LIKE :q_code OR p.fish_type LIKE :q_fish OR hr.buyer LIKE :q_buyer OR hr.remarks LIKE :q_remarks)';
        $recordSearchParams = [
            'q_pond' => prefix_search_param($accountSearch),
            'q_code' => prefix_search_param($accountSearch),
            'q_fish' => prefix_search_param($accountSearch),
            'q_expense_type' => prefix_search_param($accountSearch),
            'q_buyer' => prefix_search_param($accountSearch),
            'q_remarks' => prefix_search_param($accountSearch),
        ];
    }

    $expenseStmt = $pdo->prepare('SELECT ex.id, ex.pond_id AS account_id, ex.expense_date AS entry_datetime, "expense" AS entry_type,
            NULL AS quantity, NULL AS unit, ex.amount, ex.reference_no, ex.remarks AS notes,
            "Fishpond" AS category_name, "fishpond" AS category_slug, p.pond_name AS account_name,
            "expense" AS fishpond_record_type, NULL AS water_temperature, NULL AS ph_level, NULL AS turbidity, NULL AS water_level,
            NULL AS feed_quantity, NULL AS mortality_count, NULL AS harvest_kilos, p.fish_growth_stage,
            NULL AS caretaker, NULL AS movement_type, NULL AS remaining_stock, NULL AS feed_type, ex.expense_type, NULL AS buyer, NULL AS price_per_kilo,
            COALESCE(u.full_name, u.username, "-") AS recorded_by
        FROM fishpond_expenses ex
        INNER JOIN ponds p ON p.id = ex.pond_id
        LEFT JOIN users u ON u.id = ex.created_by
        WHERE ex.is_active = 1 AND ex.expense_date >= :from_dt AND ex.expense_date < :to_dt' . $expenseSearchSql . '
        ORDER BY ex.expense_date DESC, ex.id DESC');
    $expenseParams = array_merge(['from_dt' => $fromDateTime, 'to_dt' => $toDateTimeExclusive], $recordSearchParams);
    unset($expenseParams['q_buyer']);
    $expenseStmt->execute($expenseParams);
    $recordRows = array_merge($recordRows, $expenseStmt->fetchAll());

    $harvestStmt = $pdo->prepare('SELECT hr.id, hr.pond_id AS account_id, hr.harvest_date AS entry_datetime, "harvest" AS entry_type,
            hr.kilos AS quantity, "kg" AS unit, hr.total_sales AS amount, NULL AS reference_no, hr.remarks AS notes,
            "Fishpond" AS category_name, "fishpond" AS category_slug, p.pond_name AS account_name,
            "harvest" AS fishpond_record_type, NULL AS water_temperature, NULL AS ph_level, NULL AS turbidity, NULL AS water_level,
            NULL AS feed_quantity, NULL AS mortality_count, hr.kilos AS harvest_kilos, p.fish_growth_stage,
            NULL AS caretaker, NULL AS movement_type, NULL AS remaining_stock, NULL AS feed_type, NULL AS expense_type, hr.buyer, hr.price_per_kilo,
            COALESCE(u.full_name, u.username, "-") AS recorded_by
        FROM fishpond_harvest_records hr
        INNER JOIN ponds p ON p.id = hr.pond_id
        LEFT JOIN users u ON u.id = hr.created_by
        WHERE hr.is_active = 1 AND hr.harvest_date >= :from_dt AND hr.harvest_date < :to_dt' . $harvestSearchSql . '
        ORDER BY hr.harvest_date DESC, hr.id DESC');
    $harvestParams = array_merge(['from_dt' => $fromDateTime, 'to_dt' => $toDateTimeExclusive], $recordSearchParams);
    unset($harvestParams['q_expense_type']);
    $harvestStmt->execute($harvestParams);
    $recordRows = array_merge($recordRows, $harvestStmt->fetchAll());

    usort($recordRows, static fn(array $a, array $b): int => strcmp((string) $b['entry_datetime'], (string) $a['entry_datetime']));
    $fishpondEntries = $recordRows;
    $entries = $recordRows;
    $summaryStmt = $pdo->prepare('SELECT
            (SELECT COALESCE(SUM(total_sales), 0) FROM fishpond_harvest_records WHERE is_active = 1 AND harvest_date >= :income_from_dt AND harvest_date < :income_to_dt) AS total_income,
            (SELECT COALESCE(SUM(amount), 0) FROM fishpond_expenses WHERE is_active = 1 AND expense_date >= :expense_from_dt AND expense_date < :expense_to_dt) AS total_expense');
    $summaryStmt->execute([
        'income_from_dt' => $fromDateTime,
        'income_to_dt' => $toDateTimeExclusive,
        'expense_from_dt' => $fromDateTime,
        'expense_to_dt' => $toDateTimeExclusive,
    ]);
    $summary = $summaryStmt->fetch() ?: ['total_income' => 0, 'total_expense' => 0];
}

$dashboardCards = [];
if ($isProjectOverview) {
    $dashboardStmt = $pdo->prepare('SELECT
            pc.slug,
            pc.name,
            COALESCE(SUM(CASE WHEN pe.entry_type IN ("income", "payment", "harvest") THEN pe.amount ELSE 0 END), 0) AS income,
            COALESCE(SUM(CASE WHEN pe.entry_type = "expense" THEN pe.amount ELSE 0 END), 0) AS expense,
            (SELECT COUNT(*) FROM project_accounts pa WHERE pa.category_id = pc.id AND pa.status = "active") AS active_records,
            (SELECT COUNT(*) FROM project_accounts pa WHERE pa.category_id = pc.id AND pa.status = "active" AND pa.next_due_date IS NOT NULL AND pa.next_due_date < CURDATE()) AS overdue_count
        FROM project_categories pc
        LEFT JOIN project_entries pe
            ON pe.category_id = pc.id
            AND pe.entry_datetime >= :from_dt AND pe.entry_datetime < :to_dt
        WHERE pc.slug IN ("fishpond", "rental", "toga") AND pc.is_active = 1
        GROUP BY pc.id, pc.slug, pc.name
        ORDER BY FIELD(pc.slug, "fishpond", "rental", "toga")');
    $dashboardStmt->execute([
        'from_dt' => $fromDateTime,
        'to_dt' => $toDateTimeExclusive,
    ]);
    $dashboardRows = $dashboardStmt->fetchAll();
    foreach ($dashboardRows as $row) {
        $slug = (string) $row['slug'];
        if ($slug === 'toga') {
            continue;
        }
        $dashboardCards[$slug] = $row;
    }

    $proposalCount = (int) $pdo->query('SELECT COUNT(*) FROM proposals')->fetchColumn();
    $dashboardCards['proposals'] = [
        'slug' => 'proposals',
        'name' => 'Proposal Requests',
        'income' => 0,
        'expense' => 0,
        'active_records' => $proposalCount,
        'overdue_count' => $pendingProposals,
    ];
}

$pageTitle = $isRentalManagement ? 'Rental Operations' : ($selectedCategory ? ((string) $selectedCategory['slug'] === 'fishpond' ? 'Fishpond Monitoring' : (string) $selectedCategory['name'] . ' Monitoring') : 'Fishpond Monitoring');
render_header($pageTitle, $user);
?>

<?php if ($isFishpondManagement): ?>
    <?php
    $fishpondBaseQuery = [
        'category' => 'fishpond',
        'from' => $from,
        'to' => $to,
        'q' => $accountSearch,
    ];
    $fishpondBaseQuery = array_filter($fishpondBaseQuery, static fn($value): bool => $value !== null && $value !== '');
    $fishpondTabs = [
        'ponds' => 'Ponds',
        'expenses' => 'Expenses',
        'harvest' => 'Harvest',
    ];
    $fishpondRows = [
        'expenses' => array_values(array_filter($fishpondEntries, static fn(array $entry): bool => fishpond_record_type($entry) === 'expense')),
        'harvest' => array_values(array_filter($fishpondEntries, static fn(array $entry): bool => fishpond_record_type($entry) === 'harvest')),
    ];
    $totalPonds = count($accounts);
    $totalExpenses = (float) $summary['total_expense'];
    $totalHarvestSales = (float) $summary['total_income'];
    $fishpondNetIncome = $totalHarvestSales - $totalExpenses;
    ?>

    <nav class="tabs" aria-label="Fishpond monitoring sections">
        <?php foreach ($fishpondTabs as $tabKey => $tabLabel): ?>
            <a class="tab-link <?= $projectTab === $tabKey ? 'active' : '' ?>" href="projects.php?<?= h(http_build_query(array_merge($fishpondBaseQuery, ['tab' => $tabKey]))) ?>"><?= h($tabLabel) ?></a>
        <?php endforeach; ?>
    </nav>

    <section class="table-card data-panel mb-4">
        <div class="section-heading">
            <div>
                <h3 class="text-base font-bold text-slate-950">Fishpond Monitoring</h3>
                <p class="text-sm text-slate-500">Harvest sales and expense records only.</p>
            </div>
            <div class="inline-actions">
                <?php if ($projectTab === 'ponds'): ?>
                    <button type="button" data-open-modal="account-modal">Add Pond</button>
                <?php elseif ($projectTab === 'expenses'): ?>
                    <button type="button" data-open-modal="expense-modal">Add Expense</button>
                <?php else: ?>
                    <button type="button" data-open-modal="harvest-modal">Add Harvest</button>
                <?php endif; ?>
            </div>
        </div>

        <form class="data-panel-filters grid gap-3 md:grid-cols-2 xl:grid-cols-[minmax(220px,1fr)_minmax(150px,0.65fr)_minmax(150px,0.65fr)_auto_auto] xl:items-end" method="get">
            <input type="hidden" name="category" value="fishpond">
            <input type="hidden" name="tab" value="<?= h($projectTab) ?>">
            <div>
                <label for="fishpond_q">Search</label>
                <input id="fishpond_q" name="q" value="<?= h($accountSearch) ?>" placeholder="Pond, fish type, buyer, remarks">
            </div>
            <div>
                <label for="fishpond_from">From</label>
                <input id="fishpond_from" type="date" name="from" value="<?= h($from) ?>">
            </div>
            <div>
                <label for="fishpond_to">To</label>
                <input id="fishpond_to" type="date" name="to" value="<?= h($to) ?>">
            </div>
            <div class="filter-actions">
                <button type="submit">Apply</button>
                <a class="btn alt" href="projects.php?category=fishpond&tab=<?= h($projectTab) ?>">Reset</a>
            </div>
        </form>
    </section>

    <?php if ($projectTab === 'ponds'): ?>
        <section class="table-card data-panel">
            <div class="section-heading">
                <div>
                    <h3 class="text-base font-bold text-slate-950">Ponds</h3>
                    <p class="text-sm text-slate-500">Summary of fishpond harvest and expenses.</p>
                </div>
            </div>
            <div class="table-wrap" data-no-column-filter>
                <table class="fishpond-action-table" data-no-column-filter>
                    <thead>
                    <tr>
                        <th>Pond Name</th>
                        <th>Fish Type</th>
                        <th>Status</th>
                        <th>Total Harvest</th>
                        <th>Total Expenses</th>
                        <th>Net Income</th>
                        <th>Last Updated</th>
                        <th>Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (!$accounts): ?>
                        <tr><td colspan="8"><?php render_empty_state('No pond records found.', 'Add a pond to begin monitoring harvest and expenses.'); ?></td></tr>
                    <?php endif; ?>
                    <?php foreach ($accounts as $account): ?>
                        <?php
                        $pondId = (int) $account['id'];
                        $pondRows = array_values(array_filter($fishpondEntries, static fn(array $entry): bool => (int) ($entry['account_id'] ?? 0) === $pondId));
                        $pondHarvestTotal = array_sum(array_map(static fn(array $entry): float => fishpond_record_type($entry) === 'harvest' ? (float) $entry['amount'] : 0.0, $pondRows));
                        $pondExpenseTotal = array_sum(array_map(static fn(array $entry): float => fishpond_record_type($entry) === 'expense' ? (float) $entry['amount'] : 0.0, $pondRows));
                        $displayStatus = (string) $account['status'];
                        $statusClass = preg_replace('/[^a-z0-9_-]+/', '-', strtolower($displayStatus)) ?: 'active';
                        ?>
                        <tr>
                            <td><span class="font-semibold text-brand-800"><?= h((string) $account['account_name']) ?></span></td>
                            <td><?= h((string) ($account['fish_type'] ?: '-')) ?></td>
                            <td><span class="status-pill <?= h($statusClass) ?>"><?= h($displayStatus) ?></span></td>
                            <td><?= h(money($pondHarvestTotal)) ?></td>
                            <td><?= h(money($pondExpenseTotal)) ?></td>
                            <td><?= h(money($pondHarvestTotal - $pondExpenseTotal)) ?></td>
                            <td><?= h((string) ($account['updated_at'] ?? '-')) ?></td>
                            <td>
                                <div class="inline-actions">
                                    <button type="button" class="btn alt" data-open-modal="pond-detail-<?= $pondId ?>">View</button>
                                    <?php if (user_can($user, 'manage_projects')): ?>
                                        <button type="button" class="btn alt" data-open-modal="pond-edit-<?= $pondId ?>">Edit</button>
                                        <button type="button" class="btn alt" data-open-modal="pond-archive-<?= $pondId ?>">Archive</button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($projectTab === 'expenses'): ?>
        <section class="table-card data-panel">
            <div class="section-heading">
                <div>
                    <h3 class="text-base font-bold text-slate-950">Expenses</h3>
                    <p class="text-sm text-slate-500">Fishpond-related expense records.</p>
                </div>
            </div>
            <div class="table-wrap" data-no-column-filter>
                <table class="fishpond-action-table" data-no-column-filter>
                    <thead><tr><th>Date</th><th>Pond</th><th>Expense Type</th><th>Amount</th><th>Recorded By</th><th>Remarks</th><th>Actions</th></tr></thead>
                    <tbody>
                    <?php if (!$fishpondRows['expenses']): ?><tr><td colspan="7"><?php render_empty_state('No expense records found.', 'Add fishpond expenses to monitor net income.'); ?></td></tr><?php endif; ?>
                    <?php foreach ($fishpondRows['expenses'] as $entry): ?>
                        <?php $expenseId = (int) $entry['id']; ?>
                        <tr>
                            <td><?= h((string) $entry['entry_datetime']) ?></td>
                            <td><?= h((string) ($entry['account_name'] ?: '-')) ?></td>
                            <td><?= h(fishpond_meta_value($entry, 'expense_type')) ?></td>
                            <td><?= h(money((float) $entry['amount'])) ?></td>
                            <td><?= h((string) ($entry['recorded_by'] ?? '-')) ?></td>
                            <td><?= h((string) ($entry['notes'] ?: '-')) ?></td>
                            <td>
                                <div class="inline-actions">
                                    <button type="button" class="btn alt" data-open-modal="expense-view-<?= $expenseId ?>">View</button>
                                    <?php if (user_can($user, 'manage_projects')): ?>
                                        <button type="button" class="btn alt" data-open-modal="expense-edit-<?= $expenseId ?>">Edit</button>
                                        <button type="button" class="btn alt" data-open-modal="expense-archive-<?= $expenseId ?>">Archive</button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($projectTab === 'harvest'): ?>
        <section class="table-card data-panel">
            <div class="section-heading">
                <div>
                    <h3 class="text-base font-bold text-slate-950">Harvest</h3>
                    <p class="text-sm text-slate-500">Harvest quantity and sales records.</p>
                </div>
            </div>
            <div class="table-wrap" data-no-column-filter>
                <table class="fishpond-action-table" data-no-column-filter>
                    <thead><tr><th>Date</th><th>Pond</th><th>Kilos Harvested</th><th>Price Per Kilo</th><th>Total Revenue</th><th>Buyer</th><th>Recorded By</th><th>Remarks</th><th>Actions</th></tr></thead>
                    <tbody>
                    <?php if (!$fishpondRows['harvest']): ?><tr><td colspan="9"><?php render_empty_state('No harvest records found.', 'Add harvest records to monitor fishpond sales.'); ?></td></tr><?php endif; ?>
                    <?php foreach ($fishpondRows['harvest'] as $entry): ?>
                        <?php $harvestId = (int) $entry['id']; ?>
                        <tr>
                            <td><?= h((string) $entry['entry_datetime']) ?></td>
                            <td><?= h((string) ($entry['account_name'] ?: '-')) ?></td>
                            <td><?= h(fishpond_meta_value($entry, 'harvest_kilos', (string) ($entry['quantity'] ?? '-'))) ?></td>
                            <td><?= h(money((float) fishpond_meta_value($entry, 'price_per_kilo', '0'))) ?></td>
                            <td><?= h(money((float) $entry['amount'])) ?></td>
                            <td><?= h(fishpond_meta_value($entry, 'buyer')) ?></td>
                            <td><?= h((string) ($entry['recorded_by'] ?? '-')) ?></td>
                            <td><?= h((string) ($entry['notes'] ?: '-')) ?></td>
                            <td>
                                <div class="inline-actions">
                                    <button type="button" class="btn alt" data-open-modal="harvest-view-<?= $harvestId ?>">View</button>
                                    <?php if (user_can($user, 'manage_projects')): ?>
                                        <button type="button" class="btn alt" data-open-modal="harvest-edit-<?= $harvestId ?>">Edit</button>
                                        <button type="button" class="btn alt" data-open-modal="harvest-archive-<?= $harvestId ?>">Archive</button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>

    <?php
    $fishpondPondOptions = static function (array $ponds, ?int $selectedId = null): void {
        $seen = [];
        foreach ($ponds as $pond) {
            $pondId = (int) ($pond['id'] ?? 0);
            $label = trim((string) ($pond['account_name'] ?? $pond['pond_name'] ?? ''));
            $key = strtolower($label);
            if ($pondId <= 0 || $label === '' || $key === 'general' || isset($seen[$pondId])) {
                continue;
            }
            $seen[$pondId] = true;
            echo '<option value="' . $pondId . '"' . ($selectedId === $pondId ? ' selected' : '') . '>' . h($label) . '</option>';
        }
        if (!$seen) {
            echo '<option value="" disabled>No ponds found. Add Pond first.</option>';
        }
    };
    $fishpondDateLocal = static function (?string $value): string {
        $timestamp = $value ? strtotime($value) : false;
        return $timestamp ? date('Y-m-d\TH:i', $timestamp) : date('Y-m-d\TH:i');
    };
    ?>

    <dialog id="account-modal" class="modal fishpond-modal fishpond-modal-compact">
        <div class="modal-header"><h3>Add Pond</h3><button type="button" class="modal-close" data-close-modal aria-label="Close">Close</button></div>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="add_account">
            <input type="hidden" name="category_id" value="<?= (int) $selectedCategoryId ?>">
            <div class="fishpond-modal-body">
                <section class="fishpond-form-section">
                    <h4>Pond Information</h4>
                    <div class="form-grid fishpond-form-grid">
                        <div><label for="account_name">Pond Name</label><input id="account_name" name="account_name" required></div>
                        <div><label for="fish_type">Fish Type</label><input id="fish_type" name="fish_type"></div>
                        <div><label for="pond_status">Status</label><select id="pond_status" name="status"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
                    </div>
                </section>
                <section class="fishpond-form-section">
                    <h4>Remarks</h4>
                    <textarea id="account_notes" name="notes"></textarea>
                </section>
            </div>
            <div class="modal-actions"><button type="button" class="btn alt" data-close-modal>Cancel</button><button type="submit">Add Pond</button></div>
        </form>
    </dialog>

    <dialog id="expense-modal" class="modal fishpond-modal fishpond-modal-compact">
        <div class="modal-header"><h3>Add Expense</h3><button type="button" class="modal-close" data-close-modal aria-label="Close">Close</button></div>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="record_entry">
            <input type="hidden" name="category_id" value="<?= (int) $selectedCategoryId ?>">
            <input type="hidden" name="entry_type" value="expense">
            <input type="hidden" name="fishpond_record_type" value="expense">
            <input type="hidden" name="sync_cash" value="1">
            <div class="fishpond-modal-body">
                <section class="fishpond-form-section">
                    <h4>Expense Details</h4>
                    <div class="form-grid fishpond-form-grid">
                        <div><label for="expense_pond_id">Pond</label><select id="expense_pond_id" name="account_id" required><option value="">Select pond</option><?php $fishpondPondOptions($ponds); ?></select></div>
                        <div><label for="expense_datetime">Expense Date</label><input id="expense_datetime" type="datetime-local" name="entry_datetime" value="<?= date('Y-m-d\\TH:i') ?>" required></div>
                        <div><label for="activity_expense_type">Expense Type</label><select id="activity_expense_type" name="expense_type" required><option value="">Select type</option><option value="Feeds">Feeds</option><option value="Maintenance">Maintenance</option><option value="Medicine">Medicine</option><option value="Electricity">Electricity</option><option value="Labor">Labor</option><option value="Equipment">Equipment</option><option value="Others">Others</option></select></div>
                        <div><label for="activity_expense_amount">Amount</label><input id="activity_expense_amount" name="amount" type="number" min="0" step="0.01" value="0" data-currency-input></div>
                    </div>
                </section>
                <section class="fishpond-form-section">
                    <h4>Remarks</h4>
                    <textarea id="expense_notes" name="notes"></textarea>
                </section>
            </div>
            <div class="modal-actions"><button type="button" class="btn alt" data-close-modal>Cancel</button><button type="submit">Save Expense</button></div>
        </form>
    </dialog>

    <dialog id="harvest-modal" class="modal fishpond-modal fishpond-modal-compact">
        <div class="modal-header"><h3>Add Harvest</h3><button type="button" class="modal-close" data-close-modal aria-label="Close">Close</button></div>
        <form method="post" data-fishpond-harvest-form>
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="record_entry">
            <input type="hidden" name="category_id" value="<?= (int) $selectedCategoryId ?>">
            <input type="hidden" name="entry_type" value="harvest">
            <input type="hidden" name="fishpond_record_type" value="harvest">
            <input type="hidden" name="unit" value="kg">
            <input type="hidden" name="sync_cash" value="1">
            <input type="hidden" name="amount" value="0" data-harvest-amount>
            <div class="fishpond-modal-body">
                <section class="fishpond-form-section">
                    <h4>Harvest Details</h4>
                    <div class="form-grid fishpond-form-grid">
                        <div><label for="harvest_pond_id">Pond</label><select id="harvest_pond_id" name="account_id" required><option value="">Select pond</option><?php $fishpondPondOptions($ponds); ?></select></div>
                        <div><label for="harvest_datetime">Harvest Date</label><input id="harvest_datetime" type="datetime-local" name="entry_datetime" value="<?= date('Y-m-d\\TH:i') ?>" required></div>
                        <div><label for="harvest_kilos">Kilos Harvested</label><input id="harvest_kilos" name="harvest_kilos" type="number" min="0" step="0.01" data-harvest-kilos required></div>
                        <div><label for="price_per_kilo">Price Per Kilo</label><input id="price_per_kilo" name="price_per_kilo" type="number" min="0" step="0.01" data-harvest-price data-currency-input required></div>
                        <div><label for="harvest_total">Total Revenue</label><input id="harvest_total" value="PHP 0.00" data-harvest-total readonly></div>
                        <div><label for="buyer">Buyer</label><input id="buyer" name="buyer"></div>
                    </div>
                </section>
                <section class="fishpond-form-section">
                    <h4>Remarks</h4>
                    <textarea id="harvest_notes" name="notes"></textarea>
                </section>
            </div>
            <div class="modal-actions"><button type="button" class="btn alt" data-close-modal>Cancel</button><button type="submit">Save Harvest</button></div>
        </form>
    </dialog>

    <?php foreach ($accounts as $account): ?>
        <?php
        $pondId = (int) $account['id'];
        $pondEntries = array_values(array_filter($fishpondEntries, static fn(array $entry): bool => (int) ($entry['account_id'] ?? 0) === $pondId));
        $pondExpenses = array_values(array_filter($pondEntries, static fn(array $entry): bool => fishpond_record_type($entry) === 'expense'));
        $pondHarvest = array_values(array_filter($pondEntries, static fn(array $entry): bool => fishpond_record_type($entry) === 'harvest'));
        $pondIncome = array_sum(array_map(static fn(array $entry): float => fishpond_record_type($entry) === 'harvest' ? (float) $entry['amount'] : 0.0, $pondEntries));
        $pondExpenseTotal = array_sum(array_map(static fn(array $entry): float => fishpond_record_type($entry) === 'expense' ? (float) $entry['amount'] : 0.0, $pondEntries));
        ?>
        <dialog id="pond-detail-<?= $pondId ?>" class="modal fishpond-modal fishpond-modal-compact">
            <div class="modal-header"><h3><?= h((string) $account['account_name']) ?></h3><button type="button" class="modal-close" data-close-modal aria-label="Close">Close</button></div>
            <div class="fishpond-modal-body">
                <section class="fishpond-form-section">
                    <h4>Pond Details</h4>
                    <dl class="grid gap-2 text-sm md:grid-cols-2">
                        <div><dt class="text-slate-500">Pond Name</dt><dd class="font-semibold"><?= h((string) $account['account_name']) ?></dd></div>
                        <div><dt class="text-slate-500">Fish Type</dt><dd class="font-semibold"><?= h((string) ($account['fish_type'] ?: '-')) ?></dd></div>
                        <div><dt class="text-slate-500">Status</dt><dd><span class="status-pill <?= h(preg_replace('/[^a-z0-9_-]+/', '-', strtolower((string) $account['status'])) ?: 'active') ?>"><?= h((string) $account['status']) ?></span></dd></div>
                        <div><dt class="text-slate-500">Last Updated</dt><dd class="font-semibold"><?= h((string) ($account['updated_at'] ?? '-')) ?></dd></div>
                        <div><dt class="text-slate-500">Total Harvest</dt><dd class="font-semibold"><?= h(money($pondIncome)) ?></dd></div>
                        <div><dt class="text-slate-500">Total Expenses</dt><dd class="font-semibold"><?= h(money($pondExpenseTotal)) ?></dd></div>
                        <div><dt class="text-slate-500">Net Income</dt><dd class="font-semibold"><?= h(money($pondIncome - $pondExpenseTotal)) ?></dd></div>
                        <div><dt class="text-slate-500">Remarks</dt><dd class="font-semibold"><?= h((string) ($account['notes'] ?: '-')) ?></dd></div>
                    </dl>
                </section>
            </div>
            <div class="modal-actions"><button type="button" class="btn alt" data-close-modal>Close</button></div>
        </dialog>

        <dialog id="pond-edit-<?= $pondId ?>" class="modal fishpond-modal fishpond-modal-compact">
            <div class="modal-header"><h3>Edit Pond</h3><button type="button" class="modal-close" data-close-modal aria-label="Close">Close</button></div>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action" value="update_pond">
                <input type="hidden" name="pond_id" value="<?= $pondId ?>">
                <div class="fishpond-modal-body">
                    <section class="fishpond-form-section">
                        <h4>Pond Information</h4>
                        <div class="form-grid fishpond-form-grid">
                            <div><label for="pond_name_<?= $pondId ?>">Pond Name</label><input id="pond_name_<?= $pondId ?>" name="pond_name" value="<?= h((string) $account['account_name']) ?>" required></div>
                            <div><label for="pond_fish_type_<?= $pondId ?>">Fish Type</label><input id="pond_fish_type_<?= $pondId ?>" name="fish_type" value="<?= h((string) ($account['fish_type'] ?? '')) ?>"></div>
                            <div><label for="pond_status_<?= $pondId ?>">Status</label><select id="pond_status_<?= $pondId ?>" name="status"><option value="active" <?= $account['status'] === 'active' ? 'selected' : '' ?>>Active</option><option value="inactive" <?= $account['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option></select></div>
                        </div>
                    </section>
                    <section class="fishpond-form-section">
                        <h4>Remarks</h4>
                        <textarea name="notes"><?= h((string) ($account['notes'] ?? '')) ?></textarea>
                    </section>
                </div>
                <div class="modal-actions"><button type="button" class="btn alt" data-close-modal>Cancel</button><button type="submit">Save Pond</button></div>
            </form>
        </dialog>

        <dialog id="pond-archive-<?= $pondId ?>" class="modal fishpond-modal fishpond-modal-compact">
            <div class="modal-header"><h3>Archive Pond</h3><button type="button" class="modal-close" data-close-modal aria-label="Close">Close</button></div>
            <form method="post" class="p-5">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action" value="archive_pond">
                <input type="hidden" name="pond_id" value="<?= $pondId ?>">
                <p class="text-sm text-slate-700">Archive <?= h((string) $account['account_name']) ?> from active pond lists?</p>
                <div class="modal-actions"><button type="button" class="btn alt" data-close-modal>Cancel</button><button type="submit">Archive</button></div>
            </form>
        </dialog>
    <?php endforeach; ?>

    <?php foreach ($fishpondRows['expenses'] as $entry): ?>
        <?php
        $expenseId = (int) $entry['id'];
        $expensePondId = (int) ($entry['account_id'] ?? 0);
        ?>
        <dialog id="expense-view-<?= $expenseId ?>" class="modal fishpond-modal fishpond-modal-compact">
            <div class="modal-header"><h3>Expense Details</h3><button type="button" class="modal-close" data-close-modal aria-label="Close">Close</button></div>
            <div class="fishpond-modal-body">
                <section class="fishpond-form-section">
                    <dl class="grid gap-2 text-sm md:grid-cols-2">
                        <div><dt class="text-slate-500">Date</dt><dd class="font-semibold"><?= h((string) $entry['entry_datetime']) ?></dd></div>
                        <div><dt class="text-slate-500">Pond</dt><dd class="font-semibold"><?= h((string) ($entry['account_name'] ?: '-')) ?></dd></div>
                        <div><dt class="text-slate-500">Expense Type</dt><dd class="font-semibold"><?= h(fishpond_meta_value($entry, 'expense_type')) ?></dd></div>
                        <div><dt class="text-slate-500">Amount</dt><dd class="font-semibold"><?= h(money((float) $entry['amount'])) ?></dd></div>
                        <div><dt class="text-slate-500">Recorded By</dt><dd class="font-semibold"><?= h((string) ($entry['recorded_by'] ?? '-')) ?></dd></div>
                        <div><dt class="text-slate-500">Remarks</dt><dd class="font-semibold"><?= h((string) ($entry['notes'] ?: '-')) ?></dd></div>
                    </dl>
                </section>
            </div>
            <div class="modal-actions"><button type="button" class="btn alt" data-close-modal>Close</button></div>
        </dialog>

        <dialog id="expense-edit-<?= $expenseId ?>" class="modal fishpond-modal fishpond-modal-compact">
            <div class="modal-header"><h3>Edit Expense</h3><button type="button" class="modal-close" data-close-modal aria-label="Close">Close</button></div>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action" value="update_fishpond_expense">
                <input type="hidden" name="expense_id" value="<?= $expenseId ?>">
                <div class="fishpond-modal-body">
                    <section class="fishpond-form-section">
                        <h4>Expense Details</h4>
                        <div class="form-grid fishpond-form-grid">
                            <div><label for="expense_edit_pond_<?= $expenseId ?>">Pond</label><select id="expense_edit_pond_<?= $expenseId ?>" name="pond_id" required><?php $fishpondPondOptions($ponds, $expensePondId); ?></select></div>
                            <div><label for="expense_edit_date_<?= $expenseId ?>">Expense Date</label><input id="expense_edit_date_<?= $expenseId ?>" type="datetime-local" name="expense_date" value="<?= h($fishpondDateLocal((string) $entry['entry_datetime'])) ?>" required></div>
                            <div><label for="expense_edit_type_<?= $expenseId ?>">Expense Type</label><input id="expense_edit_type_<?= $expenseId ?>" name="expense_type" value="<?= h(fishpond_meta_value($entry, 'expense_type')) ?>" required></div>
                            <div><label for="expense_edit_amount_<?= $expenseId ?>">Amount</label><input id="expense_edit_amount_<?= $expenseId ?>" name="amount" type="number" min="0" step="0.01" value="<?= h((string) (float) $entry['amount']) ?>" data-currency-input required></div>
                        </div>
                    </section>
                    <section class="fishpond-form-section"><h4>Remarks</h4><textarea name="remarks"><?= h((string) ($entry['notes'] ?? '')) ?></textarea></section>
                </div>
                <div class="modal-actions"><button type="button" class="btn alt" data-close-modal>Cancel</button><button type="submit">Save Expense</button></div>
            </form>
        </dialog>

        <dialog id="expense-archive-<?= $expenseId ?>" class="modal fishpond-modal fishpond-modal-compact">
            <div class="modal-header"><h3>Archive Expense</h3><button type="button" class="modal-close" data-close-modal aria-label="Close">Close</button></div>
            <form method="post" class="p-5">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action" value="archive_fishpond_expense">
                <input type="hidden" name="expense_id" value="<?= $expenseId ?>">
                <p class="text-sm text-slate-700">Archive this expense record?</p>
                <div class="modal-actions"><button type="button" class="btn alt" data-close-modal>Cancel</button><button type="submit">Archive</button></div>
            </form>
        </dialog>
    <?php endforeach; ?>

    <?php foreach ($fishpondRows['harvest'] as $entry): ?>
        <?php
        $harvestId = (int) $entry['id'];
        $harvestPondId = (int) ($entry['account_id'] ?? 0);
        $harvestKilos = (float) fishpond_meta_value($entry, 'harvest_kilos', (string) ($entry['quantity'] ?? 0));
        $harvestPrice = (float) fishpond_meta_value($entry, 'price_per_kilo', '0');
        ?>
        <dialog id="harvest-view-<?= $harvestId ?>" class="modal fishpond-modal fishpond-modal-compact">
            <div class="modal-header"><h3>Harvest Details</h3><button type="button" class="modal-close" data-close-modal aria-label="Close">Close</button></div>
            <div class="fishpond-modal-body">
                <section class="fishpond-form-section">
                    <dl class="grid gap-2 text-sm md:grid-cols-2">
                        <div><dt class="text-slate-500">Date</dt><dd class="font-semibold"><?= h((string) $entry['entry_datetime']) ?></dd></div>
                        <div><dt class="text-slate-500">Pond</dt><dd class="font-semibold"><?= h((string) ($entry['account_name'] ?: '-')) ?></dd></div>
                        <div><dt class="text-slate-500">Kilos Harvested</dt><dd class="font-semibold"><?= h((string) $harvestKilos) ?></dd></div>
                        <div><dt class="text-slate-500">Price Per Kilo</dt><dd class="font-semibold"><?= h(money($harvestPrice)) ?></dd></div>
                        <div><dt class="text-slate-500">Total Revenue</dt><dd class="font-semibold"><?= h(money((float) $entry['amount'])) ?></dd></div>
                        <div><dt class="text-slate-500">Buyer</dt><dd class="font-semibold"><?= h(fishpond_meta_value($entry, 'buyer')) ?></dd></div>
                        <div><dt class="text-slate-500">Recorded By</dt><dd class="font-semibold"><?= h((string) ($entry['recorded_by'] ?? '-')) ?></dd></div>
                        <div><dt class="text-slate-500">Remarks</dt><dd class="font-semibold"><?= h((string) ($entry['notes'] ?: '-')) ?></dd></div>
                    </dl>
                </section>
            </div>
            <div class="modal-actions"><button type="button" class="btn alt" data-close-modal>Close</button></div>
        </dialog>

        <dialog id="harvest-edit-<?= $harvestId ?>" class="modal fishpond-modal fishpond-modal-compact">
            <div class="modal-header"><h3>Edit Harvest</h3><button type="button" class="modal-close" data-close-modal aria-label="Close">Close</button></div>
            <form method="post" data-fishpond-harvest-form>
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action" value="update_fishpond_harvest">
                <input type="hidden" name="harvest_id" value="<?= $harvestId ?>">
                <div class="fishpond-modal-body">
                    <section class="fishpond-form-section">
                        <h4>Harvest Details</h4>
                        <div class="form-grid fishpond-form-grid">
                            <div><label for="harvest_edit_pond_<?= $harvestId ?>">Pond</label><select id="harvest_edit_pond_<?= $harvestId ?>" name="pond_id" required><?php $fishpondPondOptions($ponds, $harvestPondId); ?></select></div>
                            <div><label for="harvest_edit_date_<?= $harvestId ?>">Harvest Date</label><input id="harvest_edit_date_<?= $harvestId ?>" type="datetime-local" name="harvest_date" value="<?= h($fishpondDateLocal((string) $entry['entry_datetime'])) ?>" required></div>
                            <div><label for="harvest_edit_kilos_<?= $harvestId ?>">Kilos Harvested</label><input id="harvest_edit_kilos_<?= $harvestId ?>" name="kilos" type="number" min="0" step="0.01" value="<?= h((string) $harvestKilos) ?>" data-harvest-kilos required></div>
                            <div><label for="harvest_edit_price_<?= $harvestId ?>">Price Per Kilo</label><input id="harvest_edit_price_<?= $harvestId ?>" name="price_per_kilo" type="number" min="0" step="0.01" value="<?= h((string) $harvestPrice) ?>" data-harvest-price data-currency-input required></div>
                            <div><label for="harvest_edit_total_<?= $harvestId ?>">Total Revenue</label><input id="harvest_edit_total_<?= $harvestId ?>" value="<?= h(money((float) $entry['amount'])) ?>" data-harvest-total readonly></div>
                            <div><label for="harvest_edit_buyer_<?= $harvestId ?>">Buyer</label><input id="harvest_edit_buyer_<?= $harvestId ?>" name="buyer" value="<?= h(fishpond_meta_value($entry, 'buyer')) ?>"></div>
                        </div>
                    </section>
                    <section class="fishpond-form-section"><h4>Remarks</h4><textarea name="remarks"><?= h((string) ($entry['notes'] ?? '')) ?></textarea></section>
                </div>
                <div class="modal-actions"><button type="button" class="btn alt" data-close-modal>Cancel</button><button type="submit">Save Harvest</button></div>
            </form>
        </dialog>

        <dialog id="harvest-archive-<?= $harvestId ?>" class="modal fishpond-modal fishpond-modal-compact">
            <div class="modal-header"><h3>Archive Harvest</h3><button type="button" class="modal-close" data-close-modal aria-label="Close">Close</button></div>
            <form method="post" class="p-5">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action" value="archive_fishpond_harvest">
                <input type="hidden" name="harvest_id" value="<?= $harvestId ?>">
                <p class="text-sm text-slate-700">Archive this harvest record?</p>
                <div class="modal-actions"><button type="button" class="btn alt" data-close-modal>Cancel</button><button type="submit">Archive</button></div>
            </form>
        </dialog>
    <?php endforeach; ?>

    <script>
        (function () {
            const peso = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' });

            function updateHarvestSummary(form) {
                const kilos = Number.parseFloat(form.querySelector('[data-harvest-kilos]')?.value || '0');
                const price = Number.parseFloat(form.querySelector('[data-harvest-price]')?.value || '0');
                const total = Math.max(0, (Number.isFinite(kilos) ? kilos : 0) * (Number.isFinite(price) ? price : 0));
                const amount = form.querySelector('[data-harvest-amount]');
                const totalPreview = form.querySelector('[data-harvest-total]');
                if (amount) amount.value = total.toFixed(2);
                if (totalPreview) totalPreview.value = peso.format(total);
            }

            document.querySelectorAll('[data-fishpond-harvest-form]').forEach(function (form) {
                form.querySelectorAll('[data-harvest-kilos], [data-harvest-price]').forEach(function (field) {
                    field.addEventListener('input', function () {
                        updateHarvestSummary(form);
                    });
                });
                updateHarvestSummary(form);
            });

            document.querySelectorAll('[data-currency-input]').forEach(function (field) {
                field.addEventListener('blur', function () {
                    const value = Number.parseFloat(field.value || '0');
                    field.value = Number.isFinite(value) ? Math.max(0, value).toFixed(2) : '0.00';
                    const harvestForm = field.closest('[data-fishpond-harvest-form]');
                    if (harvestForm) {
                        updateHarvestSummary(harvestForm);
                    }
                });
            });
        })();
    </script>

    <?php render_footer(); ?>
    <?php return; ?>
<?php endif; ?>

<?php if ($isRentalManagement): ?>
    <?php
    $rentalSwitcherBase = [
        'category' => 'rental',
        'from' => $from,
        'to' => $to,
        'account_status' => $accountStatusFilter,
        'q' => $accountSearch,
    ];
    $rentalSections = [
        'stall' => [
            'title' => 'Stall Rentals',
        ],
        'toga' => [
            'title' => 'Toga Rentals',
        ],
    ];
    ?>
    <section class="rental-workflow-section-grid" aria-label="Rental sections">
        <?php foreach ($rentalSections as $type => $section): ?>
            <?php $count = (int) ($rentalOverdueCounts[$type] ?? 0); ?>
            <a class="rental-section-card <?= $rentalType === $type ? 'is-active' : '' ?>" href="projects.php?<?= h(http_build_query(array_merge($rentalSwitcherBase, ['rental_type' => $type, 'tab' => 'accounts']))) ?>">
                <span>
                    <strong><?= h($section['title']) ?></strong>
                </span>
                <em><?= h((string) $count) ?> overdue</em>
            </a>
        <?php endforeach; ?>
    </section>
<?php endif; ?>

<section class="page-toolbar table-card data-panel mb-4">
    <div class="section-heading">
        <div>
            <h3 class="text-base font-bold text-slate-950">Filters</h3>
        </div>
        <div class="actions-row">
            <?php if ($isRentalManagement): ?>
                <details class="action-menu rental-toolbar-menu">
                    <summary>+ Add</summary>
                    <div class="action-menu-panel">
                        <?php if ($rentalType === 'toga'): ?>
                            <button type="button" class="action-menu-item btn alt" data-open-modal="toga-modal">Add Release</button>
                            <button type="button" class="action-menu-item btn alt" data-open-modal="person-modal">Add Student</button>
                        <?php else: ?>
                            <button type="button" class="action-menu-item btn alt" data-open-modal="entry-modal">Payment</button>
                            <button type="button" class="action-menu-item btn alt" data-open-modal="account-modal">Stall Rental</button>
                            <button type="button" class="action-menu-item btn alt" data-open-modal="person-modal">Add Tenant</button>
                        <?php endif; ?>
                    </div>
                </details>
                <details class="action-menu rental-toolbar-menu">
                    <summary>More</summary>
                    <div class="action-menu-panel">
                        <?php if (user_can($user, 'manage_settings')): ?>
                            <a class="action-menu-item btn alt" href="settings.php">Admin Settings</a>
                        <?php else: ?>
                            <span class="action-menu-item muted">No extra actions</span>
                        <?php endif; ?>
                    </div>
                </details>
            <?php else: ?>
                <?php if (!$isProjectOverview): ?>
                    <button type="button" data-open-modal="entry-modal">Record Entry</button>
                    <button type="button" class="btn alt" data-open-modal="person-modal">Add Proponent</button>
                    <button type="button" class="btn alt" data-open-modal="account-modal">New Account</button>
                    <?php if (user_can($user, 'manage_projects')): ?>
                        <button type="button" class="btn alt" data-open-modal="category-modal">Add Project</button>
                        <button type="button" class="btn alt" data-open-modal="edit-category-modal">Edit Project</button>
                    <?php endif; ?>
                <?php elseif (user_can($user, 'manage_projects')): ?>
                    <button type="button" data-open-modal="category-modal">Add Project</button>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <form method="get" class="data-panel-filters grid gap-3 p-4 pt-0">
        <input type="hidden" name="tab" value="<?= h($projectTab) ?>">
        <?php if ($isRentalManagement): ?>
            <input type="hidden" name="category" value="rental">
            <input type="hidden" name="rental_type" value="<?= h($rentalType) ?>">
        <?php endif; ?>
        <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
            <?php if (!$selectedCategory): ?>
                <div>
                    <label for="filter_category">Category</label>
                    <select id="filter_category" name="category">
                        <option value="all">All Categories</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= h($cat['slug']) ?>" <?= $selectedCategorySlug === $cat['slug'] ? 'selected' : '' ?>>
                                <?= h($cat['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
            <div>
                <label for="filter_from">From</label>
                <input id="filter_from" type="date" name="from" value="<?= h($from) ?>">
            </div>
            <div>
                <label for="filter_to">To</label>
                <input id="filter_to" type="date" name="to" value="<?= h($to) ?>">
            </div>
            <div>
                <label for="filter_account_status">Status</label>
                <select id="filter_account_status" name="account_status">
                    <option value="all" <?= $accountStatusFilter === 'all' ? 'selected' : '' ?>>All</option>
                    <option value="active" <?= $accountStatusFilter === 'active' ? 'selected' : '' ?>>Active</option>
                    <?php if (!$isRentalManagement || !$isTogaView): ?>
                        <option value="inactive" <?= $accountStatusFilter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                    <?php endif; ?>
                    <?php if ($isTogaView): ?>
                        <option value="returned" <?= $accountStatusFilter === 'returned' ? 'selected' : '' ?>>Returned</option>
                        <option value="forfeited" <?= $accountStatusFilter === 'forfeited' ? 'selected' : '' ?>>Forfeited</option>
                    <?php endif; ?>
                </select>
            </div>
        </div>
        <div class="grid gap-3 md:grid-cols-[minmax(220px,1fr)_auto_auto] md:items-end">
            <div>
                <label for="filter_q">Search</label>
                <input id="filter_q" name="q" value="<?= h($accountSearch) ?>" placeholder="Name, code, contact">
            </div>
            <button type="submit">Apply Filters</button>
            <a class="btn alt" href="<?= h($isRentalManagement ? 'projects.php?category=rental&rental_type=' . urlencode($rentalType) : 'projects.php' . ($selectedCategorySlug !== 'all' ? '?category=' . urlencode($selectedCategorySlug) : '')) ?>">Reset</a>
        </div>
    </form>
</section>

<?php
$projectBaseQuery = [
    'category' => $isRentalManagement ? 'rental' : $selectedCategorySlug,
    'rental_type' => $isRentalManagement ? $rentalType : null,
    'from' => $from,
    'to' => $to,
    'account_status' => $accountStatusFilter,
    'q' => $accountSearch,
];
$projectBaseQuery = array_filter($projectBaseQuery, static fn($value): bool => $value !== null && $value !== '');
$projectTabs = $isRentalManagement
    ? [
        'accounts' => $isTogaView ? 'Releases' : 'Rentals',
        'entries' => $isTogaView ? 'Activity' : 'Payments',
        'overdue' => 'Overdue (' . count($overdues) . ')',
    ]
    : [
        'accounts' => $isTogaView ? 'Toga Releases' : 'Accounts',
        'entries' => 'Entries',
        'overdue' => 'Overdue',
        'performance' => 'Performance',
    ];
$recordLabel = $isTogaView ? 'toga records' : strtolower($rentalLabel);
$emptyEntriesMessage = $isRentalManagement
    ? 'No rental activity found.'
    : ($isFishpondManagement ? 'No fishpond entries found.' : 'No entries found.');
$emptyAccountsMessage = $isRentalManagement
    ? ($isTogaView ? 'No toga releases found.' : 'No ' . strtolower($rentalLabel) . ' found.')
    : 'No accounts found.';
$summaryParts = [
    count($accounts) . ' ' . $recordLabel,
    'Income ' . money((float) $summary['total_income']),
    'Expense ' . money((float) $summary['total_expense']),
    'Net ' . money((float) $summary['total_income'] - (float) $summary['total_expense']),
    count($overdues) . ' overdue',
];
$tableTitles = [
    'accounts' => $isRentalManagement
        ? ($isTogaView ? 'Release Records' : 'Rental Records')
        : 'Project Accounts',
    'entries' => $isRentalManagement
        ? ($isTogaView ? 'Rental Activity' : 'Rental Payments')
        : 'Project Entries',
    'overdue' => $isRentalManagement
        ? 'Overdue Rental Accounts'
        : 'Overdue Accounts',
    'performance' => $isFishpondManagement ? 'Fishpond Performance' : 'Category Performance',
];
?>
<nav class="tabs" aria-label="Project sections">
    <?php foreach ($projectTabs as $tabKey => $tabLabel): ?>
        <a class="tab-link <?= $projectTab === $tabKey ? 'active' : '' ?>" href="projects.php?<?= h(http_build_query(array_merge($projectBaseQuery, ['tab' => $tabKey]))) ?>"><?= h($tabLabel) ?></a>
    <?php endforeach; ?>
</nav>

<?php if ($isRentalManagement): ?>
    <section class="rental-kpi-grid" aria-label="Rental metrics">
        <article><span>Active</span><strong><?= h((string) $rentalActiveCount) ?></strong></article>
        <article><span>Overdue</span><strong><?= h((string) count($overdues)) ?></strong></article>
        <article><span>Income</span><strong><?= h(money((float) $summary['total_income'])) ?></strong></article>
        <article><span>Returns</span><strong><?= h((string) $rentalReturnedCount) ?></strong></article>
    </section>
<?php else: ?>
    <p class="mb-4 text-sm text-slate-600"><?= implode(' &bull; ', array_map('h', $summaryParts)) ?></p>
<?php endif; ?>

<dialog id="category-modal" class="modal">
    <div class="modal-header">
        <h3>Add Project</h3>
        <button type="button" class="modal-close" data-close-modal aria-label="Close">Close</button>
    </div>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="add_project">

        <div class="form-grid">
            <div>
                <label for="category_name">Project Name</label>
                <input id="category_name" name="name" placeholder="Example: Livelihood Farm" required>
            </div>
            <div>
                <label for="category_slug">Slug (optional)</label>
                <input id="category_slug" name="slug" placeholder="Example: livelihood-farm">
            </div>
            <div class="field-wide">
                <label for="category_description">Description</label>
                <textarea id="category_description" name="description" placeholder="Short description"></textarea>
            </div>
            <div>
                <label for="project_account_name">First Account (optional)</label>
                <input id="project_account_name" name="account_name" placeholder="Example: Pond A / Stall 1">
            </div>
            <?php render_person_selector($people, 'account_person_id', 'project_account_person_id', null, 'First Account Person', false, ['name_target' => 'project_account_name', 'department_target' => 'project_contact_name']); ?>
            <div>
                <label for="project_code">Code</label>
                <input id="project_code" name="code" placeholder="Optional">
            </div>
            <div>
                <label for="project_contact_name">Contact/Owner</label>
                <input id="project_contact_name" name="contact_name" placeholder="Optional">
            </div>
            <div>
                <label for="project_start_date">Start Date</label>
                <input id="project_start_date" type="date" name="start_date">
            </div>
            <div>
                <label for="project_next_due_date">Next Due Date</label>
                <input id="project_next_due_date" type="date" name="next_due_date">
            </div>
            <div>
                <label for="project_expected_amount">Expected Amount</label>
                <input id="project_expected_amount" name="expected_amount" type="number" min="0" step="0.01" placeholder="Optional">
            </div>
            <div class="field-wide">
                <label for="project_notes">Account Notes</label>
                <textarea id="project_notes" name="notes" placeholder="Optional notes for the first account"></textarea>
            </div>
        </div>
        <div class="modal-actions">
            <button type="button" class="btn alt" data-close-modal>Cancel</button>
            <button type="submit">Add Project</button>
        </div>
    </form>
</dialog>

<?php if ($selectedCategory && !$isRentalManagement && user_can($user, 'manage_projects')): ?>
    <dialog id="edit-category-modal" class="modal">
        <div class="modal-header">
            <h3><?= $isRentalManagement ? 'Edit Rental Category' : 'Edit Project Category' ?></h3>
            <button type="button" class="modal-close" data-close-modal aria-label="Close">&times;</button>
        </div>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="edit_category">
            <input type="hidden" name="category_id" value="<?= (int) $selectedCategory['id'] ?>">

            <div class="form-grid">
                <div>
                    <label for="edit_category_name"><?= $isRentalManagement ? 'Category Name' : 'Project Name' ?></label>
                    <input id="edit_category_name" name="name" value="<?= h($selectedCategory['name']) ?>" required>
                </div>
                <div>
                    <label for="edit_category_slug">Slug</label>
                    <input id="edit_category_slug" name="slug" value="<?= h($selectedCategory['slug']) ?>" required>
                </div>
                <div class="field-wide">
                    <label for="edit_category_description">Description</label>
                    <textarea id="edit_category_description" name="description"><?= h($selectedCategory['description']) ?></textarea>
                </div>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn alt" data-close-modal>Cancel</button>
                <button type="submit"><?= $isRentalManagement ? 'Save Category' : 'Save Project' ?></button>
            </div>
        </form>
    </dialog>
<?php endif; ?>

<dialog id="account-modal" class="modal modal-wide rental-modal">
    <div class="modal-header">
        <h3><?= $isRentalManagement ? 'Add ' . h($rentalSingularLabel) : 'Add Account' ?></h3>
        <button type="button" class="modal-close" data-close-modal aria-label="Close">&times;</button>
    </div>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="add_account">
        <?php if ($isRentalManagement): ?>
            <input type="hidden" name="rental_type" value="<?= h($rentalType) ?>">
        <?php endif; ?>

        <div class="rental-modal-body">
            <section class="rental-form-section">
                <h4><?= $isRentalManagement ? 'Tenant Info' : 'Account Details' ?></h4>
                <div class="form-grid rental-form-grid">
                    <?php if ($isRentalManagement): ?>
                        <input type="hidden" name="category_id" value="<?= (int) $selectedCategoryId ?>">
                    <?php else: ?>
                        <div>
                            <label for="account_category_id">Category</label>
                            <select id="account_category_id" name="category_id" required>
                                <option value="">Select...</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?= (int) $cat['id'] ?>" <?= $selectedCategoryId === (int) $cat['id'] ? 'selected' : '' ?>>
                                        <?= h($cat['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>
                    <?php render_person_selector($isRentalManagement ? $tenants : $people, 'account_person_id', 'account_person_id', null, $isRentalManagement ? 'Tenant' : 'Person', $isRentalManagement, ['name_target' => 'account_name', 'department_target' => 'contact_name']); ?>
                    <div>
                        <label for="account_name"><?= $isRentalManagement ? 'Stall / Unit' : 'Account Name' ?></label>
                        <input id="account_name" name="account_name" placeholder="<?= h($isRentalManagement ? 'Stall 1' : 'Stall 1 / Pond A') ?>" required>
                    </div>
                    <div>
                        <label for="account_code"><?= $isRentalManagement ? 'Code / Reference' : 'Reference' ?></label>
                        <input id="account_code" name="code">
                    </div>
                    <div>
                        <label for="contact_name">Contact</label>
                        <input id="contact_name" name="contact_name">
                    </div>
                </div>
            </section>
            <section class="rental-form-section">
                <h4><?= $isRentalManagement ? 'Rental Details' : 'Terms' ?></h4>
                <div class="form-grid rental-form-grid">
                    <div>
                        <label for="start_date">Start Date</label>
                        <input id="start_date" type="date" name="start_date">
                    </div>
                    <div>
                        <label for="next_due_date">Next Due</label>
                        <input id="next_due_date" type="date" name="next_due_date">
                    </div>
                    <div>
                        <label for="expected_amount">Expected Amount</label>
                        <input id="expected_amount" name="expected_amount" type="number" min="0" step="0.01">
                    </div>
                </div>
            </section>
            <section class="rental-form-section">
                <h4>Notes</h4>
                <textarea id="account_notes" name="notes"></textarea>
            </section>
        </div>
        <div class="modal-actions">
            <?php if (!$isRentalManagement): ?>
                <button type="button" class="btn alt" data-close-modal>Cancel</button>
            <?php endif; ?>
            <button type="submit"><?= $isRentalManagement ? 'Save ' . h($rentalSingularLabel) : 'Add Account' ?></button>
        </div>
    </form>
</dialog>

<dialog id="entry-modal" class="modal modal-wide rental-modal">
    <div class="modal-header">
        <h3><?= $isFishpondManagement ? 'Add Fishpond Entry' : ($isRentalManagement ? 'Add Payment' : 'Add Project Entry') ?></h3>
        <button type="button" class="modal-close" data-close-modal aria-label="Close">&times;</button>
    </div>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="record_entry">
        <?php if ($isRentalManagement): ?>
            <input type="hidden" name="rental_type" value="<?= h($rentalType) ?>">
        <?php endif; ?>

        <?php if ($isRentalManagement): ?>
            <input type="hidden" id="entry_category_id" name="category_id" value="<?= (int) $selectedCategoryId ?>">
            <input type="hidden" name="entry_type" value="payment">
            <input type="hidden" name="sync_cash" value="1">
        <?php endif; ?>
        <div class="rental-modal-body">
            <section class="rental-form-section">
                <h4><?= $isRentalManagement ? 'Payment Details' : 'Entry Details' ?></h4>
                <div class="form-grid rental-form-grid">
                    <?php if (!$isRentalManagement): ?>
                        <div>
                            <label for="entry_category_id">Category</label>
                            <select id="entry_category_id" name="category_id" required>
                                <option value="">Select...</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?= (int) $cat['id'] ?>" <?= $selectedCategoryId === (int) $cat['id'] ? 'selected' : '' ?>>
                                        <?= h($cat['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>
                    <div>
                        <label for="entry_account_id"><?= $isRentalManagement ? 'Linked Rental' : 'Account' ?></label>
                        <select id="entry_account_id" name="account_id" <?= $isRentalManagement ? 'required' : '' ?>>
                            <?php if ($isRentalManagement): ?><option value="0">Manual rental entry</option><?php else: ?><option value="0">General</option><?php endif; ?>
                            <?php foreach ($entryAccounts as $account): ?>
                                <option value="<?= (int) $account['id'] ?>" data-category-id="<?= (int) $account['category_id'] ?>">
                                    <?= h($isRentalManagement ? (string) $account['account_name'] : $account['category_name'] . ' - ' . $account['account_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="entry_datetime">Date and Time</label>
                        <input id="entry_datetime" type="datetime-local" name="entry_datetime" value="<?= date('Y-m-d\\TH:i') ?>">
                    </div>
                    <?php if (!$isRentalManagement): ?>
                        <div>
                            <label for="entry_type">Entry Type</label>
                            <select id="entry_type" name="entry_type" required>
                                <option value="monitoring" selected>Monitoring</option>
                                <option value="harvest">Harvest Income</option>
                                <option value="income">Income</option>
                                <option value="expense">Expense</option>
                                <option value="payment">Payment</option>
                                <option value="production">Maintenance</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        <div>
                            <label for="quantity">Quantity</label>
                            <input id="quantity" name="quantity" type="number" step="0.01" min="0">
                        </div>
                        <div>
                            <label for="unit">Unit</label>
                            <input id="unit" name="unit">
                        </div>
                    <?php endif; ?>
                    <div>
                        <label for="amount">Amount</label>
                        <input id="amount" name="amount" type="number" min="0" step="0.01" value="0" required>
                    </div>
                    <div>
                        <label for="reference_no">OR / Reference</label>
                        <input id="reference_no" name="reference_no">
                    </div>
                    <div>
                        <label for="update_next_due_date">Next Due</label>
                        <input id="update_next_due_date" type="date" name="update_next_due_date">
                    </div>
                    <?php if (!$isRentalManagement): ?>
                        <div class="checkbox-field">
                            <label>
                                <input type="checkbox" name="sync_cash" value="1" checked>
                                Also post to Cash Flow
                            </label>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
            <section class="rental-form-section">
                <h4>Notes</h4>
                <textarea id="entry_notes" name="notes"></textarea>
            </section>
        </div>
        <div class="modal-actions">
            <?php if (!$isRentalManagement): ?>
                <button type="button" class="btn alt" data-close-modal>Cancel</button>
            <?php endif; ?>
            <button type="submit">Save Entry</button>
        </div>
    </form>
</dialog>

<?php if (isset($categoryBySlug['toga'])): ?>
    <dialog id="toga-modal" class="modal modal-wide rental-modal">
        <div class="modal-header">
            <h3>Add Toga Release</h3>
            <button type="button" class="modal-close" data-close-modal aria-label="Close">&times;</button>
        </div>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="add_toga">
            <input type="hidden" name="sync_cash" value="1">

            <div class="rental-modal-body">
                <section class="rental-form-section">
                    <h4>Tenant Info</h4>
                    <div class="form-grid rental-form-grid">
                        <?php render_person_selector($people, 'person_id', 'toga_person_id', null, 'Student', true, ['name_target' => 'student_name', 'code_target' => 'student_id', 'department_target' => 'program']); ?>
                        <div>
                            <label for="student_name">Name</label>
                            <input id="student_name" name="student_name" readonly required>
                        </div>
                        <div>
                            <label for="student_id">Student ID</label>
                            <input id="student_id" name="student_id" readonly>
                        </div>
                        <div>
                            <label for="program">Program</label>
                            <input id="program" name="program" readonly>
                        </div>
                    </div>
                </section>
                <section class="rental-form-section">
                    <h4>Payment Details</h4>
                    <div class="form-grid rental-form-grid">
                        <div>
                            <label for="release_date">Release Date</label>
                            <input id="release_date" type="date" name="release_date" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div>
                            <label for="deposit_amount">Deposit</label>
                            <input id="deposit_amount" type="number" min="0" step="0.01" name="deposit_amount" value="0" required>
                        </div>
                        <div>
                            <label for="fee_amount">Fee</label>
                            <input id="fee_amount" type="number" min="0" step="0.01" name="fee_amount" value="0" required>
                        </div>
                    </div>
                </section>
                <section class="rental-form-section">
                    <h4>Notes</h4>
                    <textarea id="toga_notes" name="notes"></textarea>
                </section>
            </div>
            <div class="modal-actions">
                <button type="submit">Save Release</button>
            </div>
        </form>
    </dialog>
<?php endif; ?>

<div class="stack">
    <?php if ($projectTab === 'overdue'): ?>
    <details class="collapse-card table-card" open>
        <summary>
            <span>
                <strong><?= h($tableTitles['overdue']) ?></strong>
                <?php if (!$isRentalManagement): ?><small>Renewal monitoring</small><?php endif; ?>
            </span>
        </summary>
        <?php if (!$overdues): ?>
            <p class="muted empty-state"><?= h($isRentalManagement ? 'No overdue rental accounts found.' : 'No overdue accounts found.') ?></p>
        <?php else: ?>
            <div class="table-wrap compact-table" <?= $isRentalManagement ? 'data-no-column-filter' : '' ?>>
                <table <?= $isRentalManagement ? 'data-no-column-filter' : '' ?>>
                    <thead>
                    <tr>
                        <th>Account</th>
                        <th>Code</th>
                        <th>Next Due</th>
                        <th>Expected</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($overdues as $row): ?>
                        <tr>
                            <td><?= h($row['account_name']) ?></td>
                            <td><?= h($row['code']) ?></td>
                            <td><?= h($row['next_due_date']) ?></td>
                            <td><?= h(money((float) $row['expected_amount'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </details>
    <?php endif; ?>

    <?php if ($projectTab === 'performance'): ?>
    <details class="collapse-card table-card" open>
        <summary>
            <span>
                <strong><?= h($tableTitles['performance']) ?></strong>
                <small>Income and expense summary</small>
            </span>
        </summary>
        <div class="table-wrap compact-table">
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
                <?php foreach ($incomeByCategory as $row): ?>
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
    </details>
    <?php endif; ?>
</div>

<?php if ($projectTab === 'entries'): ?>
<details class="collapse-card table-card">
    <summary>
        <span>
            <strong><?= h($tableTitles['entries']) ?></strong>
            <?php if (!$isRentalManagement): ?><small><?= h($from) ?> to <?= h($to) ?></small><?php endif; ?>
        </span>
    </summary>
    <div class="table-wrap" data-no-client-table <?= $isRentalManagement ? 'data-no-column-filter' : '' ?>>
        <table <?= $isRentalManagement ? 'data-no-column-filter' : '' ?>>
            <thead>
            <tr>
                <th>Date and Time</th>
                <th><?= $isRentalManagement ? 'Rental' : 'Account' ?></th>
                <?php if (!$isRentalManagement): ?>
                    <th>Category</th>
                    <th>Type</th>
                    <th>Qty/Unit</th>
                <?php endif; ?>
                <th>Amount</th>
                <th>Reference</th>
                <th>Notes</th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$entries): ?>
                <tr>
                    <td colspan="<?= $isRentalManagement ? 5 : 8 ?>" class="muted"><?= h($emptyEntriesMessage) ?></td>
                </tr>
            <?php endif; ?>
            <?php foreach ($entries as $entry): ?>
                <tr>
                    <td><?= h($entry['entry_datetime']) ?></td>
                    <td><?= h($entry['account_name'] ?: '-') ?></td>
                    <?php if (!$isRentalManagement): ?>
                        <td><?= h($entry['category_name']) ?></td>
                        <td><?= h($entry['entry_type']) ?></td>
                        <td>
                            <?php if ($entry['quantity'] !== null): ?>
                                <?= h((string) $entry['quantity']) ?> <?= h((string) $entry['unit']) ?>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>
                    <?php endif; ?>
                    <td><?= h(money((float) $entry['amount'])) ?></td>
                    <td><?= h($entry['reference_no']) ?></td>
                    <td><?= h($entry['notes']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php render_pagination($entriesPagination); ?>
</details>
<?php endif; ?>

<?php if ($projectTab === 'accounts'): ?>
<details class="collapse-card table-card" <?= !$isProjectOverview ? 'open' : '' ?>>
    <summary>
        <span>
            <strong><?= h($tableTitles['accounts']) ?></strong>
            <?php if (!$isRentalManagement): ?><small><?= $isTogaView ? 'Release and return status' : 'Trackable units by category' ?></small><?php endif; ?>
        </span>
    </summary>
    <div class="table-wrap" <?= $isRentalManagement ? 'data-no-column-filter' : '' ?>>
        <table <?= $isRentalManagement ? 'data-no-column-filter' : '' ?>>
            <thead>
            <?php if ($isTogaView): ?>
                <tr>
                    <th>Student</th>
                    <th>Student ID</th>
                    <th>Program</th>
                    <th>Released</th>
                    <th>Returned</th>
                    <th>Deposit</th>
                    <th>Fee</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            <?php else: ?>
                <tr>
                    <th><?= $isRentalManagement ? 'Tenant' : 'Account' ?></th>
                    <th>Code</th>
                    <th>Contact</th>
                    <th>Start Date</th>
                    <th>Next Due</th>
                    <th>Expected Amount</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            <?php endif; ?>
            </thead>
            <tbody>
            <?php if (!$accounts): ?>
                <tr>
                    <td colspan="<?= $isTogaView ? 9 : ($isRentalManagement ? 8 : 9) ?>" class="muted"><?= h($emptyAccountsMessage) ?></td>
                </tr>
            <?php endif; ?>
            <?php foreach ($accounts as $account): ?>
                <?php
                $displayStatus = $account['category_slug'] === 'toga'
                    ? (string) ($account['toga_status'] ?: ($account['status'] === 'active' ? 'released' : 'returned'))
                    : (string) $account['status'];
                $badgeStatus = $displayStatus === 'released' ? 'active' : $displayStatus;
                if ($badgeStatus === 'active' && !empty($account['next_due_date']) && (string) $account['next_due_date'] < date('Y-m-d')) {
                    $badgeStatus = 'overdue';
                }
                $statusClass = preg_replace('/[^a-z0-9_-]+/', '-', strtolower($badgeStatus)) ?: 'active';
                $accountDisplayName = (string) ($account['person_full_name'] ?: $account['account_name']);
                $accountDisplayCode = (string) ($account['person_code'] ?: $account['code']);
                $accountDisplayDepartment = (string) ($account['person_department'] ?: $account['contact_name']);
                ?>
                <?php if ($isTogaView): ?>
                    <tr>
                        <td><?= h($accountDisplayName) ?></td>
                        <td><?= h($accountDisplayCode ?: '-') ?></td>
                        <td><?= h($accountDisplayDepartment ?: '-') ?></td>
                        <td><?= h($account['start_date']) ?></td>
                        <td><?= h($account['return_date'] ?: '-') ?></td>
                        <td><?= h(money(project_meta_decimal($account['deposit_amount']))) ?></td>
                        <td><?= h(money(project_meta_decimal($account['fee_amount']))) ?></td>
                        <td><span class="status-pill <?= h($statusClass) ?>"><?= h($badgeStatus) ?></span></td>
                        <td>
                            <?php if ($displayStatus === 'released'): ?>
                                <div class="inline-actions">
                                    <button type="button" class="btn alt rental-icon-action" data-open-modal="return-toga-<?= (int) $account['id'] ?>" aria-label="Return" data-tooltip="Return">R</button>
                                    <details class="action-menu">
                                        <summary class="rental-icon-action" aria-label="More" data-tooltip="More">...</summary>
                                        <div class="action-menu-panel">
                                            <button type="button" class="action-menu-item btn alt btn-danger" data-open-modal="forfeit-toga-<?= (int) $account['id'] ?>">Forfeit Deposit</button>
                                        </div>
                                    </details>
                                </div>
                            <?php else: ?>
                                <span class="muted">-</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php else: ?>
                    <tr>
                        <td><?= h($accountDisplayName) ?></td>
                        <td><?= h($account['code']) ?></td>
                        <td><?= h($accountDisplayDepartment) ?></td>
                        <td><?= h($account['start_date']) ?></td>
                        <td><?= h($account['next_due_date']) ?></td>
                        <td><?= h(money((float) $account['expected_amount'])) ?></td>
                        <td><span class="status-pill <?= h($statusClass) ?>"><?= h($badgeStatus) ?></span></td>
                        <td>
                            <?php if ($isRentalManagement && $displayStatus === 'active'): ?>
                                <button type="button" class="btn alt rental-icon-action" data-open-modal="entry-modal" data-entry-account-id="<?= (int) $account['id'] ?>" aria-label="Add Payment" data-tooltip="Add Payment">$</button>
                            <?php elseif ($isRentalManagement): ?>
                                <span class="muted">-</span>
                            <?php else: ?>
                                <span class="muted">-</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endif; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</details>
<?php endif; ?>

<?php if ($isTogaView): ?>
    <?php foreach ($accounts as $account): ?>
        <?php
        $displayStatus = $account['category_slug'] === 'toga'
            ? (string) ($account['toga_status'] ?: ($account['status'] === 'active' ? 'released' : 'returned'))
            : (string) $account['status'];
        if ($displayStatus !== 'released') {
            continue;
        }
        ?>
        <dialog id="return-toga-<?= (int) $account['id'] ?>" class="modal">
            <div class="modal-header">
                <h3>Return Toga</h3>
                <button type="button" class="modal-close" data-close-modal aria-label="Close">&times;</button>
            </div>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action" value="mark_toga_returned">
                <input type="hidden" name="account_id" value="<?= (int) $account['id'] ?>">
                <p class="muted">Mark <?= h($account['account_name']) ?> as returned and record any deposit refund.</p>
                <div class="form-grid">
                    <div>
                        <label for="return_date_<?= (int) $account['id'] ?>">Return Date</label>
                        <input id="return_date_<?= (int) $account['id'] ?>" type="date" name="return_date" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div>
                        <label for="refund_amount_<?= (int) $account['id'] ?>">Refund Amount</label>
                        <input id="refund_amount_<?= (int) $account['id'] ?>" type="number" min="0" step="0.01" name="refund_amount" value="0">
                    </div>
                </div>
                <div class="modal-actions">
                    <button type="submit">Mark Returned</button>
                </div>
            </form>
        </dialog>

        <dialog id="forfeit-toga-<?= (int) $account['id'] ?>" class="modal">
            <div class="modal-header">
                <h3>Forfeit Toga Deposit</h3>
                <button type="button" class="modal-close" data-close-modal aria-label="Close">&times;</button>
            </div>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action" value="mark_toga_forfeited">
                <input type="hidden" name="account_id" value="<?= (int) $account['id'] ?>">
                <p class="muted">This will close <?= h($account['account_name']) ?> as forfeited while keeping the history in project entries.</p>
                <div class="modal-actions">
                    <button type="submit">Forfeit Deposit</button>
                </div>
            </form>
        </dialog>
    <?php endforeach; ?>
<?php endif; ?>

<?php
$personModalLabels = [];
if ($isRentalManagement) {
    $personLabel = $isTogaView ? 'Student' : 'Tenant';
    $personModalLabels = [
        'admin_title' => 'Add ' . $personLabel,
        'request_title' => 'Request New ' . $personLabel,
        'description' => $isTogaView ? 'Save student details for toga releases.' : 'Save tenant details for stall rentals.',
        'submit_admin' => 'Save ' . $personLabel,
        'submit_request' => 'Submit ' . $personLabel . ' Request',
        'icon_close' => true,
        'show_cancel' => false,
    ];
}
render_add_person_modal($user, projects_url_for($selectedCategorySlug, $isRentalManagement ? $rentalType : null, $projectTab), 'person-modal', $personModalLabels);
?>

<script>
    (function () {
        const autoOpenAddProject = <?= $autoOpenAddProject ? 'true' : 'false' ?>;
        if (autoOpenAddProject) {
            const modal = document.getElementById('category-modal');
            if (modal && typeof modal.showModal === 'function' && !modal.open) {
                modal.showModal();
            }
        }
    })();

    (function () {
        const categorySelect = document.getElementById('entry_category_id');
        const accountSelect = document.getElementById('entry_account_id');
        if (!categorySelect || !accountSelect) {
            return;
        }

        const baseOption = accountSelect.options[0];

        function filterAccounts() {
            const selectedCategoryId = categorySelect.value;
            for (let i = 1; i < accountSelect.options.length; i += 1) {
                const option = accountSelect.options[i];
                const categoryId = option.getAttribute('data-category-id');
                option.hidden = selectedCategoryId !== '' && categoryId !== selectedCategoryId;
            }
            accountSelect.value = baseOption.value;
        }

        categorySelect.addEventListener('change', filterAccounts);
        filterAccounts();

        document.querySelectorAll('[data-entry-account-id]').forEach(function (button) {
            button.addEventListener('click', function () {
                categorySelect.value = '<?= h((string) ($selectedCategoryId ?? '')) ?>';
                filterAccounts();
                accountSelect.value = button.getAttribute('data-entry-account-id') || baseOption.value;
                const entryType = document.getElementById('entry_type');
                if (entryType) {
                    entryType.value = 'payment';
                }
            });
        });

    })();
</script>

<?php render_footer();
