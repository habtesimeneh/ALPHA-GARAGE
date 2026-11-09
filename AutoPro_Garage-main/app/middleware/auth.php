<?php
declare(strict_types=1);

/** Currently authenticated user (re-read from the DB on every request so deactivation/role changes apply instantly). */
function current_user(): ?array
{
    static $loaded = false, $user = null;
    if ($loaded) {
        return $user;
    }
    $loaded = true;
    $id = $_SESSION['uid'] ?? null;
    if (!$id) {
        return null;
    }
    try {
        $row = Database::one(
            'SELECT u.id, u.username, u.full_name, u.email, u.role_id, u.must_change_password, r.name AS role_name, r.label AS role_label
             FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ? AND u.is_active = 1', [$id]);
    } catch (Throwable $e) {
        return null;
    }
    if (!$row) {
        unset($_SESSION['uid']);
        return null;
    }
    $row['permissions'] = array_column(Database::all(
        'SELECT p.code FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = ?', [$row['role_id']]), 'code');
    $mech = Database::one('SELECT id FROM mechanics WHERE user_id = ? AND is_active = 1', [$row['id']]);
    $row['mechanic_id'] = $mech ? (int) $mech['id'] : null;
    return $user = $row;
}

/** Enforces login, idle/absolute session timeouts and the forced-password-change rule. */
function require_login(bool $allowPasswordChangePage = false): array
{
    $cfg = $GLOBALS['config']['session'];
    $now = time();
    if (!empty($_SESSION['uid'])) {
        $idle = $now - (int) ($_SESSION['last_activity'] ?? 0);
        $life = $now - (int) ($_SESSION['started_at'] ?? 0);
        $uaOk = hash_equals((string) ($_SESSION['ua'] ?? ''), hash('sha256', user_agent()));
        if ($idle > $cfg['idle_timeout'] || $life > $cfg['absolute_life'] || !$uaOk) {
            AuthService::destroySession();
            session_start();
            flash('warning', 'Your session expired. Please sign in again.');
            redirect('login.php');
        }
        $_SESSION['last_activity'] = $now;
    }
    $user = current_user();
    if (!$user) {
        redirect('login.php');
    }
    if (!empty($user['must_change_password']) && !$allowPasswordChangePage) {
        flash('warning', 'Please set a new password before continuing.');
        redirect('profile.php');
    }
    return $user;
}
