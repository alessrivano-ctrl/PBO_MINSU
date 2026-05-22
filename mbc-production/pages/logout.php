<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';

logout_user($pdo);
session_start();
set_flash('success', 'You have been logged out.');
redirect(app_base_path() . 'login.php');
