<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';

require_login($pdo);

$query = ['category' => 'rental', 'rental_type' => 'toga'];

$status = (string) ($_GET['status'] ?? $_GET['account_status'] ?? '');
if (in_array($status, ['released', 'returned', 'forfeited', 'active', 'inactive'], true)) {
    $query['account_status'] = $status;
}

$search = trim((string) ($_GET['q'] ?? ''));
if ($search !== '') {
    $query['q'] = $search;
}

redirect('projects.php?' . http_build_query($query));
