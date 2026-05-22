<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';

$user = require_login($pdo);
require_permission($user, 'manage_users');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf((string) ($_POST['csrf_token'] ?? ''))) {
        set_flash('error', 'Invalid form token.');
        redirect('users.php');
    }

    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'add_user') {
        $username = trim((string) ($_POST['username'] ?? ''));
        $fullName = trim((string) ($_POST['full_name'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $role = (string) ($_POST['role'] ?? 'staff');
        $status = (string) ($_POST['status'] ?? 'approved');

        if ($username === '' || $fullName === '' || strlen($password) < 8 || !in_array($role, ['admin', 'staff'], true) || !in_array($status, ['pending', 'approved', 'suspended'], true)) {
            set_flash('error', 'Complete user details are required. Password must be at least 8 characters.');
            redirect('users.php');
        }

        try {
            $stmt = $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role, status, approved_by, approved_at)
                VALUES (:username, :password_hash, :full_name, :role, :status, :approved_by, :approved_at)');
            $stmt->execute([
                'username' => $username,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'full_name' => $fullName,
                'role' => $role,
                'status' => $status,
                'approved_by' => $status === 'approved' ? (int) $user['id'] : null,
                'approved_at' => $status === 'approved' ? date('Y-m-d H:i:s') : null,
            ]);
            audit_log($pdo, $user, 'create_user', 'users', 'user', (int) $pdo->lastInsertId(), ['username' => $username, 'role' => $role, 'status' => $status]);
            set_flash('success', 'User account created.');
        } catch (PDOException $e) {
            log_system_issue($pdo, 'error', 'Could not create user.', ['error' => $e->getMessage(), 'username' => $username], $user);
            set_flash('error', 'Could not create user. Username may already exist.');
        }

        redirect('users.php');
    }

    if ($action === 'update_user') {
        $userId = (int) ($_POST['user_id'] ?? 0);
        $role = (string) ($_POST['role'] ?? 'staff');
        $status = (string) ($_POST['status'] ?? 'pending');

        if ($userId <= 0 || !in_array($role, ['admin', 'staff'], true) || !in_array($status, ['pending', 'approved', 'suspended'], true)) {
            set_flash('error', 'Invalid user update.');
            redirect('users.php');
        }

        $stmt = $pdo->prepare('UPDATE users
            SET role = :role,
                status = :status,
                approved_by = CASE WHEN :status_for_approved_by = "approved" THEN :approved_by ELSE approved_by END,
                approved_at = CASE WHEN :status_for_approved_at = "approved" THEN NOW() ELSE approved_at END
            WHERE id = :id');
        $stmt->execute([
            'id' => $userId,
            'role' => $role,
            'status' => $status,
            'status_for_approved_by' => $status,
            'status_for_approved_at' => $status,
            'approved_by' => (int) $user['id'],
        ]);
        audit_log($pdo, $user, 'update_user', 'users', 'user', $userId, ['role' => $role, 'status' => $status]);
        set_flash('success', 'User account updated.');
        redirect('users.php');
    }
}

$q = trim((string) ($_GET['q'] ?? ''));
$roleFilter = (string) ($_GET['role'] ?? '');
$statusFilter = (string) ($_GET['status'] ?? '');
$sort = (string) ($_GET['sort'] ?? 'name');
$order = strtolower((string) ($_GET['order'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';
$page = page_param();
$perPage = 10;

$where = ['1=1'];
$params = [];
if ($q !== '') {
    $where[] = '(u.full_name LIKE :q_name OR u.username LIKE :q_username)';
    $params['q_name'] = prefix_search_param($q);
    $params['q_username'] = prefix_search_param($q);
}
if (in_array($roleFilter, ['admin', 'staff'], true)) {
    $where[] = 'u.role = :role';
    $params['role'] = $roleFilter;
}
if (in_array($statusFilter, ['pending', 'approved', 'suspended'], true)) {
    $where[] = 'u.status = :status';
    $params['status'] = $statusFilter;
}

$sortSql = match ($sort) {
    'username' => 'u.username',
    'role' => 'u.role',
    'status' => 'u.status',
    default => 'u.full_name',
};

$countStmt = $pdo->prepare('SELECT COUNT(*) FROM users u WHERE ' . implode(' AND ', $where));
$countStmt->execute($params);
$pagination = pagination_meta((int) $countStmt->fetchColumn(), $page, $perPage);

$sql = 'SELECT id, username, full_name, role, status, created_at
    FROM (
        SELECT u.id, u.username, u.full_name, u.role, u.status, u.created_at,
            ROW_NUMBER() OVER (ORDER BY ' . $sortSql . ' ' . $order . ', u.id ASC) AS row_num
        FROM users u
        WHERE ' . implode(' AND ', $where) . '
    ) ranked_users
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
$rows = $stmt->fetchAll();
render_header('User Management', $user);
?>

<dialog id="user-modal" class="modal modal-wide app-form-modal">
    <div class="modal-header">
        <div>
            <h3>Add User</h3>
        </div>
        <button type="button" class="modal-close" data-close-modal aria-label="Close">Close</button>
    </div>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="add_user">
        <div class="form-grid">
            <div>
                <label for="username">Username</label>
                <input id="username" name="username" autocomplete="username" placeholder="Enter username" required>
            </div>
            <div>
                <label for="full_name">Full Name</label>
                <input id="full_name" name="full_name" autocomplete="name" placeholder="Account holder name" required>
            </div>
            <div>
                <label for="password">Temporary Password</label>
                <input id="password" type="password" name="password" minlength="8" autocomplete="new-password" placeholder="Enter temporary password" required>
            </div>
            <div>
                <label for="role">Role</label>
                <select id="role" name="role" required>
                    <option value="staff">Staff</option>
                    <option value="admin">Admin</option>
                </select>
            </div>
            <div>
                <label for="status">Status</label>
                <select id="status" name="status" required>
                    <option value="approved">Approved</option>
                    <option value="pending">Pending</option>
                    <option value="suspended">Suspended</option>
                </select>
            </div>
        </div>
        <div class="modal-actions"><button type="button" class="btn alt" data-close-modal>Cancel</button><button type="submit">Save User</button></div>
    </form>
</dialog>

<section class="table-card data-panel">
    <div class="section-heading">
        <div>
            <h3 class="text-base font-bold text-slate-950">Accounts</h3>
        </div>
        <div class="inline-actions">
            <button type="button" data-open-modal="user-modal">Add User</button>
        </div>
    </div>
    <form method="get" class="data-panel-filters users-filter-bar grid gap-3 md:grid-cols-2 xl:grid-cols-[minmax(220px,1.4fr)_minmax(150px,0.8fr)_minmax(160px,0.9fr)_minmax(150px,0.8fr)_minmax(150px,0.8fr)_auto] xl:items-end">
        <div>
            <label for="q">Search</label>
            <input id="q" name="q" value="<?= h($q) ?>" placeholder="Name or username">
        </div>
        <div>
            <label for="role_filter">Role</label>
            <select id="role_filter" name="role">
                <option value="">All Roles</option>
                <option value="admin" <?= $roleFilter === 'admin' ? 'selected' : '' ?>>Admin</option>
                <option value="staff" <?= $roleFilter === 'staff' ? 'selected' : '' ?>>Staff</option>
            </select>
        </div>
        <div>
            <label for="status_filter">Status</label>
            <select id="status_filter" name="status">
                <option value="">All Statuses</option>
                <option value="approved" <?= $statusFilter === 'approved' ? 'selected' : '' ?>>Approved</option>
                <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending</option>
                <option value="suspended" <?= $statusFilter === 'suspended' ? 'selected' : '' ?>>Suspended</option>
            </select>
        </div>
        <div>
            <label for="sort">Sort By</label>
            <select id="sort" name="sort">
                <option value="name" <?= $sort === 'name' ? 'selected' : '' ?>>Name</option>
                <option value="username" <?= $sort === 'username' ? 'selected' : '' ?>>Username</option>
                <option value="role" <?= $sort === 'role' ? 'selected' : '' ?>>Role</option>
                <option value="status" <?= $sort === 'status' ? 'selected' : '' ?>>Status</option>
            </select>
        </div>
        <div>
            <label for="order">Order</label>
            <select id="order" name="order">
                <option value="asc" <?= $order === 'ASC' ? 'selected' : '' ?>>Ascending</option>
                <option value="desc" <?= $order === 'DESC' ? 'selected' : '' ?>>Descending</option>
            </select>
        </div>
        <div class="filter-actions">
            <button type="submit">Apply</button>
            <a class="btn alt" href="users.php">Reset</a>
        </div>
    </form>
    <div class="table-wrap" data-no-client-table>
        <table>
            <thead><tr><th>Name</th><th>Username</th><th>Role</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="5" class="muted">No user accounts found.</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td><?= h($row['full_name']) ?></td>
                    <td><?= h($row['username']) ?></td>
                    <td><?= h(ucfirst((string) $row['role'])) ?></td>
                    <td><?php render_status_badge((string) $row['status']); ?></td>
                    <td>
                        <button type="button" class="btn alt" data-open-modal="edit-user-<?= (int) $row['id'] ?>">Edit</button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php render_pagination($pagination); ?>
</section>

<?php foreach ($rows as $row): ?>
    <dialog id="edit-user-<?= (int) $row['id'] ?>" class="modal">
        <div class="modal-header">
            <h3>Edit Account</h3>
            <button type="button" class="modal-close" data-close-modal aria-label="Close">Close</button>
        </div>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="update_user">
            <input type="hidden" name="user_id" value="<?= (int) $row['id'] ?>">
            <div class="form-grid">
                <div>
                    <label>Account</label>
                    <input value="<?= h($row['full_name']) ?> (@<?= h($row['username']) ?>)" readonly>
                </div>
                <div>
                    <label for="user_role_<?= (int) $row['id'] ?>">Role</label>
                    <select id="user_role_<?= (int) $row['id'] ?>" name="role" required>
                        <option value="staff" <?= $row['role'] === 'staff' ? 'selected' : '' ?>>Staff</option>
                        <option value="admin" <?= $row['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
                    </select>
                </div>
                <div>
                    <label for="user_status_<?= (int) $row['id'] ?>">Status</label>
                    <select id="user_status_<?= (int) $row['id'] ?>" name="status" required>
                        <option value="pending" <?= $row['status'] === 'pending' ? 'selected' : '' ?>>Pending</option>
                        <option value="approved" <?= $row['status'] === 'approved' ? 'selected' : '' ?>>Approved</option>
                        <option value="suspended" <?= $row['status'] === 'suspended' ? 'selected' : '' ?>>Suspended</option>
                    </select>
                </div>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn alt" data-close-modal>Cancel</button>
                <button type="submit">Save Account</button>
            </div>
        </form>
    </dialog>
<?php endforeach; ?>

<?php render_footer();
