<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';

$user = require_login($pdo);

set_flash('error', 'The standalone approvals page has been removed.');
redirect('dashboard.php');
