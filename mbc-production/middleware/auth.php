<?php
declare(strict_types=1);

function attempt_login(PDO $pdo, string $username, string $password): bool
{
    $stmt = $pdo->prepare('SELECT id, username, password_hash, full_name, role, status FROM users WHERE username = :username');
    $stmt->execute(['username' => trim($username)]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        return false;
    }

    if (($user['status'] ?? 'approved') !== 'approved') {
        audit_log($pdo, ['id' => (int) $user['id']], 'login_blocked_' . (string) $user['status'], 'auth', 'user', (int) $user['id']);
        return false;
    }

    if (password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
        $rehashStmt = $pdo->prepare('UPDATE users SET password_hash = :password_hash WHERE id = :id');
        $rehashStmt->execute([
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'id' => (int) $user['id'],
        ]);
    }

    session_regenerate_id(true);
    unset($_SESSION['csrf_token']);
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['last_activity'] = time();
    $_SESSION['fingerprint'] = session_fingerprint();
    record_session_log($pdo, (int) $user['id'], 'login');

    return true;
}

function current_user(PDO $pdo): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }

    if (
        !isset($_SESSION['last_activity'], $_SESSION['fingerprint'])
        || (time() - (int) $_SESSION['last_activity']) > max(300, (int) app_setting($pdo, 'security.session_timeout', '30') * 60)
        || !hash_equals((string) $_SESSION['fingerprint'], session_fingerprint())
    ) {
        $event = isset($_SESSION['last_activity'], $_SESSION['fingerprint'])
            && (time() - (int) $_SESSION['last_activity']) > max(300, (int) app_setting($pdo, 'security.session_timeout', '30') * 60)
            ? 'timeout'
            : 'fingerprint_mismatch';
        record_session_log($pdo, (int) $_SESSION['user_id'], $event);
        logout_user();
        return null;
    }

    $_SESSION['last_activity'] = time();

    $stmt = $pdo->prepare('SELECT id, username, full_name, role, status FROM users WHERE id = :id AND status = "approved"');
    $stmt->execute(['id' => (int) $_SESSION['user_id']]);
    $user = $stmt->fetch();

    return $user ?: null;
}

function require_login(PDO $pdo): array
{
    $user = current_user($pdo);
    if (!$user) {
        set_flash('error', 'Please log in first.');
        redirect('login.php');
    }

    return $user;
}

function logout_user(?PDO $pdo = null): void
{
    if ($pdo instanceof PDO && !empty($_SESSION['user_id'])) {
        record_session_log($pdo, (int) $_SESSION['user_id'], 'logout');
    }

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'],
            'domain' => $params['domain'],
            'secure' => (bool) $params['secure'],
            'httponly' => (bool) $params['httponly'],
            'samesite' => $params['samesite'] ?? 'Lax',
        ]);
    }

    session_destroy();
}

function record_session_log(PDO $pdo, ?int $userId, string $event): void
{
    if (app_setting($pdo, 'security.enable_session_logs', '1') !== '1') {
        return;
    }

    try {
        $stmt = $pdo->prepare('INSERT INTO session_logs (user_id, session_id, event, ip_address, user_agent)
            VALUES (:user_id, :session_id, :event, :ip_address, :user_agent)');
        $stmt->execute([
            'user_id' => $userId,
            'session_id' => substr(session_id(), 0, 128),
            'event' => $event,
            'ip_address' => client_ip_address(),
            'user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        ]);
    } catch (Throwable $e) {
        error_log('Session log failed: ' . $e->getMessage());
    }
}

function client_ip_address(): string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');

    return substr($ip !== '' ? $ip : 'unknown', 0, 45);
}

function normalized_login_username(string $username): string
{
    return substr(strtolower(trim($username)), 0, 120);
}

function session_fingerprint(): string
{
    $userAgent = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');

    return hash('sha256', $userAgent);
}

function cleanup_login_attempts(PDO $pdo): void
{
    $retentionMinutes = max(LOGIN_WINDOW_MINUTES, LOGIN_LOCKOUT_MINUTES) * 4;
    $pdo->exec('DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL ' . (int) $retentionMinutes . ' MINUTE)');
}

function login_rate_limit_status(PDO $pdo, string $username, string $ipAddress): array
{
    cleanup_login_attempts($pdo);

    $windowMinutes = (int) LOGIN_WINDOW_MINUTES;
    $lockoutMinutes = max(1, (int) app_setting($pdo, 'security.account_lock_duration', (string) LOGIN_LOCKOUT_MINUTES));
    $maxAttempts = max(3, (int) app_setting($pdo, 'security.maximum_login_attempts', (string) LOGIN_MAX_ATTEMPTS));
    $accountStmt = $pdo->prepare('SELECT
            COUNT(*) AS failed_count,
            COALESCE(GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(), DATE_ADD(MAX(attempted_at), INTERVAL ' . $lockoutMinutes . ' MINUTE))), 0) AS seconds_remaining
        FROM login_attempts
        WHERE username = :username
            AND ip_address = :ip_address
            AND was_successful = 0
            AND attempted_at >= DATE_SUB(NOW(), INTERVAL ' . $windowMinutes . ' MINUTE)');
    $accountStmt->execute([
        'username' => normalized_login_username($username),
        'ip_address' => $ipAddress,
    ]);
    $accountRow = $accountStmt->fetch() ?: ['failed_count' => 0, 'seconds_remaining' => 0];

    $ipStmt = $pdo->prepare('SELECT
            COUNT(*) AS failed_count,
            COALESCE(GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(), DATE_ADD(MAX(attempted_at), INTERVAL ' . $lockoutMinutes . ' MINUTE))), 0) AS seconds_remaining
        FROM login_attempts
        WHERE ip_address = :ip_address
            AND was_successful = 0
            AND attempted_at >= DATE_SUB(NOW(), INTERVAL ' . $windowMinutes . ' MINUTE)');
    $ipStmt->execute(['ip_address' => $ipAddress]);
    $ipRow = $ipStmt->fetch() ?: ['failed_count' => 0, 'seconds_remaining' => 0];

    $failedCount = (int) $accountRow['failed_count'];
    $accountSecondsRemaining = (int) $accountRow['seconds_remaining'];
    $ipFailedCount = (int) $ipRow['failed_count'];
    $ipSecondsRemaining = (int) $ipRow['seconds_remaining'];
    $isAccountLocked = $failedCount >= $maxAttempts && $accountSecondsRemaining > 0;
    $isIpLocked = $ipFailedCount >= LOGIN_MAX_IP_ATTEMPTS && $ipSecondsRemaining > 0;

    return [
        'locked' => $isAccountLocked || $isIpLocked,
        'failed_count' => $failedCount,
        'attempts_remaining' => max(0, $maxAttempts - $failedCount),
        'seconds_remaining' => max($isAccountLocked ? $accountSecondsRemaining : 0, $isIpLocked ? $ipSecondsRemaining : 0),
    ];
}

function record_login_attempt(PDO $pdo, string $username, string $ipAddress, bool $wasSuccessful): void
{
    $normalizedUsername = normalized_login_username($username);

    $stmt = $pdo->prepare('INSERT INTO login_attempts (username, ip_address, was_successful)
        VALUES (:username, :ip_address, :was_successful)');
    $stmt->execute([
        'username' => $normalizedUsername,
        'ip_address' => $ipAddress,
        'was_successful' => $wasSuccessful ? 1 : 0,
    ]);

    if ($wasSuccessful) {
        $clearStmt = $pdo->prepare('DELETE FROM login_attempts
            WHERE username = :username AND ip_address = :ip_address AND was_successful = 0');
        $clearStmt->execute([
            'username' => $normalizedUsername,
            'ip_address' => $ipAddress,
        ]);
    }
}

function format_wait_time(int $seconds): string
{
    $minutes = (int) ceil(max(1, $seconds) / 60);

    return $minutes === 1 ? '1 minute' : $minutes . ' minutes';
}

// Role-based access control functions

function require_role(PDO $pdo, string $requiredRole): array
{
    $user = require_login($pdo);

    if ((string) $user['role'] !== $requiredRole) {
        set_flash('error', 'Access denied. You do not have permission to access this page.');
        audit_log($pdo, $user, 'access_denied_' . $requiredRole, 'auth', 'page_access');
        redirect('index.php');
    }

    return $user;
}

function require_admin(PDO $pdo): array
{
    return require_role($pdo, 'admin');
}

function require_staff(PDO $pdo): array
{
    return require_role($pdo, 'staff');
}

function user_has_role(array $user, string $role): bool
{
    return (string) $user['role'] === $role;
}

function is_admin(array $user): bool
{
    return user_has_role($user, 'admin');
}

function is_staff(array $user): bool
{
    return user_has_role($user, 'staff');
}
