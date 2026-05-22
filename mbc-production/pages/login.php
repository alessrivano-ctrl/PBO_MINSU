<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verify_csrf((string) $token)) {
        set_flash('error', 'Invalid form token. Please try again.');
        redirect('login.php');
    }

    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        set_flash('error', 'Username and password are required.');
        redirect('login.php');
    }

    $ipAddress = client_ip_address();
    $rateLimit = login_rate_limit_status($pdo, $username, $ipAddress);
    if ($rateLimit['locked']) {
        audit_log($pdo, null, 'login_lockout', 'auth', 'user', $username, ['ip_address' => $ipAddress]);
        set_flash('error', 'Too many failed login attempts. Try again in ' . format_wait_time((int) $rateLimit['seconds_remaining']) . '.');
        redirect('login.php');
    }

    if (!attempt_login($pdo, $username, $password)) {
        record_login_attempt($pdo, $username, $ipAddress, false);
        audit_log($pdo, null, 'login_failed', 'auth', 'user', $username, ['ip_address' => $ipAddress]);
        $rateLimit = login_rate_limit_status($pdo, $username, $ipAddress);

        if ($rateLimit['locked']) {
            audit_log($pdo, null, 'login_lockout', 'auth', 'user', $username, ['ip_address' => $ipAddress]);
            set_flash('error', 'Too many failed login attempts. Try again in ' . format_wait_time((int) $rateLimit['seconds_remaining']) . '.');
            redirect('login.php');
        }

        set_flash('error', 'Invalid credentials. Attempts remaining: ' . (string) $rateLimit['attempts_remaining'] . '.');
        redirect('login.php');
    }

    record_login_attempt($pdo, $username, $ipAddress, true);
    audit_log($pdo, current_user($pdo), 'login_success', 'auth', 'user', $username, ['ip_address' => $ipAddress]);
    set_flash('success', 'Welcome back!');
    redirect('dashboard.php');
}

$flash = get_flash();
$showAccountSecurityNotice = $flash
    && ($flash['type'] ?? '') === 'error'
    && preg_match('/credential|attempt/i', (string) ($flash['message'] ?? ''));
$org = organization_profile($pdo);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($org['system_name']) ?> | Login</title>
<link rel="stylesheet" href="<?= h(asset_url('assets/tailwind.css')) ?>">
</head>
<body class="auth-body">
    <div class="auth-wrapper">
        <!-- Left Panel: Form -->
        <div class="auth-form-panel">
            <div class="auth-form-container">
                <!-- Logo and Organization -->
                <div class="auth-logo-section">
                    <div class="auth-logo">
                        <img src="<?= h($org['logo_path']) ?>" alt="<?= h($org['campus_display_name']) ?> logo">
                    </div>
                    <div class="auth-org-info">
                        <h1><?= h($org['campus_display_name']) ?></h1>
                        <p><?= h($org['system_name']) ?></p>
                    </div>
                </div>

                <!-- Welcome Section -->
                <div class="auth-welcome">
                    <h2>Welcome back</h2>
                    <p>Sign in using your assigned campus account.</p>
                </div>

                <div class="form-divider"></div>

                <!-- Flash Messages -->
                <?php if ($flash): ?>
                    <div class="alert <?= $flash['type'] === 'error' ? 'alert-error' : 'alert-success' ?>">
                        <?= h($flash['message']) ?>
                    </div>
                <?php endif; ?>

                <!-- Login Form -->
                <form method="post" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">

                    <div class="form-group">
                        <label for="username">Username</label>
                        <input type="text" id="username" name="username" autocomplete="username" required>
                    </div>

                    <div class="form-group">
                        <label for="password">Password</label>
                        <input type="password" id="password" name="password" autocomplete="current-password" required>
                    </div>

                    <button type="submit" class="btn-auth">Log In</button>
                </form>

                <?php if ($showAccountSecurityNotice): ?>
                    <div class="auth-footer-text">
                        <strong>Account Security:</strong>
                        After 3 failed login attempts, your account will be temporarily locked for 15 minutes.
                    </div>
                <?php endif; ?>

            </div>
        </div>

        <!-- Right Panel: Visual Branding -->
        <div class="auth-visual-panel">
            <div class="auth-visual-content">
                <div class="auth-visual-icon">🏢</div>
                <h3>Production and Business Operations</h3>
                <div class="auth-visual-accent"></div>
                <p>Manage records, sales, rentals, inventory, and reports in one place. Secure access for campus staff.</p>
            </div>
        </div>
    </div>
</body>
</html>

