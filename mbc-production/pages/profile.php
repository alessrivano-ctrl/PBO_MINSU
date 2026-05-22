<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';

$user = require_login($pdo);
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['csrf_token'] ?? '');
    if (!verify_csrf($token)) {
        set_flash('error', 'Invalid form token.');
        redirect('profile.php');
    }

    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'update_profile') {
        $fullName = trim((string) ($_POST['full_name'] ?? ''));
        if ($fullName === '') {
            $errors['full_name'] = 'Full name is required.';
        }

        if (!$errors) {
            $stmt = $pdo->prepare('UPDATE users SET full_name = :full_name WHERE id = :id');
            $stmt->execute([
                'full_name' => $fullName,
                'id' => (int) $user['id'],
            ]);
            audit_log($pdo, $user, 'update_profile', 'account', 'user', (int) $user['id']);
            set_flash('success', 'Account settings saved.');
            redirect('profile.php');
        }
    }

    if ($action === 'change_password') {
        $currentPassword = (string) ($_POST['current_password'] ?? '');
        $newPassword = (string) ($_POST['new_password'] ?? '');
        $confirmPassword = (string) ($_POST['confirm_password'] ?? '');
        $minimumLength = max(8, (int) app_setting($pdo, 'security.password_minimum_length', '8'));

        $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = :id');
        $stmt->execute(['id' => (int) $user['id']]);
        $passwordHash = (string) $stmt->fetchColumn();

        if (!password_verify($currentPassword, $passwordHash)) {
            $errors['current_password'] = 'Current password is incorrect.';
        }
        if (strlen($newPassword) < $minimumLength) {
            $errors['new_password'] = 'Password must be at least ' . $minimumLength . ' characters.';
        }
        if ($newPassword !== $confirmPassword) {
            $errors['confirm_password'] = 'Passwords do not match.';
        }

        if (!$errors) {
            $stmt = $pdo->prepare('UPDATE users SET password_hash = :password_hash WHERE id = :id');
            $stmt->execute([
                'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
                'id' => (int) $user['id'],
            ]);
            audit_log($pdo, $user, 'change_password', 'account', 'user', (int) $user['id']);
            set_flash('success', 'Password changed.');
            redirect('profile.php');
        }
    }
}

render_header('Account Settings', $user);
?>

<section class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(320px,420px)]">
    <form method="post" class="rounded-lg border border-brand-100 bg-white p-5 shadow-sm">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="update_profile">
        <div class="section-heading -mx-5 -mt-5 mb-4 rounded-t-lg">
            <h3>Profile</h3>
        </div>
        <div class="form-grid">
            <div>
                <label for="full_name">Full Name</label>
                <input id="full_name" name="full_name" value="<?= h((string) $user['full_name']) ?>" required>
                <?php if (isset($errors['full_name'])): ?><p class="mt-1 text-xs font-semibold text-red-700"><?= h($errors['full_name']) ?></p><?php endif; ?>
            </div>
            <div>
                <label for="username">Username</label>
                <input id="username" value="<?= h((string) $user['username']) ?>" readonly>
            </div>
            <div>
                <label for="theme_preference">Theme Preference</label>
                <input id="theme_preference" value="System default" readonly>
            </div>
        </div>
        <div class="mt-5 flex justify-end gap-2">
            <a class="btn alt" href="dashboard.php">Reset</a>
            <button type="submit">Save Changes</button>
        </div>
    </form>

    <form method="post" class="rounded-lg border border-brand-100 bg-white p-5 shadow-sm">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="change_password">
        <div class="section-heading -mx-5 -mt-5 mb-4 rounded-t-lg">
            <h3>Change Password</h3>
        </div>
        <div class="space-y-3">
            <div>
                <label for="current_password">Current Password</label>
                <input id="current_password" name="current_password" type="password" autocomplete="current-password" required>
                <?php if (isset($errors['current_password'])): ?><p class="mt-1 text-xs font-semibold text-red-700"><?= h($errors['current_password']) ?></p><?php endif; ?>
            </div>
            <div>
                <label for="new_password">New Password</label>
                <input id="new_password" name="new_password" type="password" autocomplete="new-password" required>
                <?php if (isset($errors['new_password'])): ?><p class="mt-1 text-xs font-semibold text-red-700"><?= h($errors['new_password']) ?></p><?php endif; ?>
            </div>
            <div>
                <label for="confirm_password">Confirm Password</label>
                <input id="confirm_password" name="confirm_password" type="password" autocomplete="new-password" required>
                <?php if (isset($errors['confirm_password'])): ?><p class="mt-1 text-xs font-semibold text-red-700"><?= h($errors['confirm_password']) ?></p><?php endif; ?>
            </div>
        </div>
        <div class="mt-5 flex justify-end gap-2">
            <button type="reset" class="btn alt">Reset</button>
            <button type="submit">Change Password</button>
        </div>
    </form>
</section>

<?php render_footer();
