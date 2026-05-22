<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';
require_login($pdo);

redirect('projects.php?category=rental&rental_type=stall');
