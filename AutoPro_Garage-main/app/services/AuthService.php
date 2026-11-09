<?php

declare(strict_types=1);

final class AuthService
{
    /** Real bcrypt hash used to equalise timing when the username does not exist. */
    private const DUMMY_HASH = '$2y$10$XV2ZfpFI26xy/iDUX6Y8au4BDvGNbfU.aScYUYsglBRL4/d1nKC3O';

    /** Remember-me cookie name. */
    private const REMEMBER_COOKIE = 'GMS_REMEMBER';

    /** @return array{ok:bool,error:?string,role_name:?string} */
    public static function attempt(string $username, string $password, bool $remember = false): array
    {
        $cfg = $GLOBALS['config']['security'];
        $ip = client_ip();
        $username = mb_substr(trim($username), 0, 150);
        $rateLimitUsername = mb_strtolower($username);

        if (self::isLocked($rateLimitUsername, $ip)) {
            AuditLogger::logSafe(
                'login_blocked_rate_limit',
                'auth',
                null,
                null,
                ['username' => $rateLimitUsername],
                'denied',
                ['user_id' => null, 'username' => $rateLimitUsername !== '' ? $rateLimitUsername : null, 'role' => null]
            );
            return ['ok' => false, 'error' => 'Too many failed attempts. Please wait ' . $cfg['lockout_minutes'] . ' minutes and try again.', 'role_name' => null];
        }

        $user = Database::one(
            'SELECT u.id, u.username, u.password_hash, u.is_active, r.name AS role_name
             FROM users u LEFT JOIN roles r ON r.id = u.role_id WHERE u.username = ? OR LOWER(u.email) = LOWER(?)',
            [$username, $username]
        );

        // Always run password_verify (dummy hash when user is unknown) so response time does not reveal valid usernames.
        $hash = $user['password_hash'] ?? self::DUMMY_HASH;
        $valid = password_verify($password, $hash);

        if ($user && $user['is_active'] && $valid) {
            if (empty($user['role_name'])) {
                throw new RuntimeException('Authenticated account has no valid role relationship.');
            }
            $authenticatedSession = [
                'uid' => (int) $user['id'],
                'started_at' => time(),
                'last_activity' => time(),
                'ua' => hash('sha256', user_agent()),
                'csrf' => bin2hex(random_bytes(32)),
                'csrf_created_at' => time(),
            ];
            Database::transaction(function () use ($user, $password, $ip, $username, $rateLimitUsername, $remember): void {
                if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
                    Database::run('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $user['id']]);
                }
                Database::run('UPDATE users SET last_login_at = NOW(), last_login_ip = ? WHERE id = ?', [$ip, $user['id']]);
                self::recordAttempt($rateLimitUsername, $ip, true);
                AuditLogger::log(
                    'login_success',
                    'auth',
                    (int) $user['id'],
                    null,
                    ['username' => $username],
                    'success',
                    ['user_id' => (int) $user['id'], 'username' => $user['username'], 'role' => $user['role_name']]
                );
                if ($remember) {
                    self::setRememberToken($user['id'], (int) ($GLOBALS['config']['remember']['duration_days'] ?? 30));
                    AuditLogger::log(
                        'remember_me_enabled',
                        'auth',
                        (int) $user['id'],
                        null,
                        ['username' => $username],
                        'success',
                        ['user_id' => (int) $user['id'], 'username' => $user['username']]
                    );
                } else {
                    self::clearRememberToken((int) $user['id']);
                }
            });
            if (!session_regenerate_id(true)) {
                throw new RuntimeException('Could not regenerate session ID after authentication.');
            }
            $_SESSION = $authenticatedSession;
            return ['ok' => true, 'error' => null, 'role_name' => $user['role_name']];
        }

        self::recordAttempt($rateLimitUsername, $ip, false);
        $reason = !$user ? 'unknown_user' : (!$user['is_active'] ? 'account_deactivated' : 'wrong_password');
        AuditLogger::logSafe(
            'login_failed',
            'auth',
            $user ? (int) $user['id'] : null,
            null,
            ['username' => $rateLimitUsername, 'reason' => $reason],
            'failure',
            ['user_id' => $user ? (int) $user['id'] : null, 'username' => $rateLimitUsername !== '' ? $rateLimitUsername : null, 'role' => $user['role_name'] ?? null]
        );
        return ['ok' => false, 'error' => 'Invalid username or password.', 'role_name' => null];   // identical message for every failure reason
    }

    /** Restore a session from a valid remember-me cookie. Returns the user row or null. */
    public static function restoreFromRememberCookie(): ?array
    {
        $cookie = $_COOKIE[self::REMEMBER_COOKIE] ?? '';
        if (!is_string($cookie) || $cookie === '' || !preg_match('/^[a-f0-9]{64}$/', $cookie)) {
            return null;
        }

        $tokenHash = hash('sha256', $cookie);
        $now = date('Y-m-d H:i:s');

        $user = Database::one(
            'SELECT u.id, u.username, u.full_name, u.email, u.role_id, u.must_change_password, r.name AS role_name, r.label AS role_label
             FROM users u JOIN roles r ON r.id = u.role_id
             WHERE u.remember_token = ? AND u.remember_expires_at > ? AND u.is_active = 1',
            [$tokenHash, $now]
        );

        if (!$user) {
            // Invalid or expired token: clear the cookie so the browser stops sending it.
            self::clearRememberCookie();
            AuditLogger::logSafe('remember_me_invalid', 'auth', null, null, [], 'denied');
            return null;
        }

        $row['permissions'] = array_column(Database::all(
            'SELECT p.code FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = ?', [$user['role_id']]), 'code');
        $mech = Database::one('SELECT id FROM mechanics WHERE user_id = ? AND is_active = 1', [$user['id']]);
        $row['mechanic_id'] = $mech ? (int) $mech['id'] : null;

        // Rotate the remember token on each use (theft-limited reuse).
        self::setRememberToken((int) $user['id'], (int) ($GLOBALS['config']['remember']['duration_days'] ?? 30));

        // Re-establish the PHP session.
        $authenticatedSession = [
            'uid' => (int) $user['id'],
            'started_at' => time(),
            'last_activity' => time(),
            'ua' => hash('sha256', user_agent()),
            'csrf' => bin2hex(random_bytes(32)),
            'csrf_created_at' => time(),
        ];
        $_SESSION = $authenticatedSession;

        AuditLogger::log(
            'remember_me_restored',
            'auth',
            (int) $user['id'],
            null,
            ['username' => $user['username']],
            'success',
            ['user_id' => (int) $user['id'], 'username' => $user['username'], 'role' => $user['role_name']]
        );

        return $user;
    }

    /** Clear the remember-me cookie and token for the current session user. */
    public static function clearRememberForCurrentUser(): void
    {
        $u = current_user();
        if ($u) {
            self::clearRememberToken((int) $u['id']);
        }
        self::clearRememberCookie();
    }

    private static function setRememberToken(int $userId, int $durationDays): void
    {
        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $expiresAt = date('Y-m-d H:i:s', time() + $durationDays * 86400);
        Database::run(
            'UPDATE users SET remember_token = ?, remember_expires_at = ? WHERE id = ?',
            [$tokenHash, $expiresAt, $userId]
        );
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        setcookie(self::REMEMBER_COOKIE, $token, [
            'expires' => time() + $durationDays * 86400,
            'path' => '/',
            'domain' => '',
            'secure' => $https,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private static function clearRememberToken(int $userId): void
    {
        Database::run('UPDATE users SET remember_token = NULL, remember_expires_at = NULL WHERE id = ?', [$userId]);
    }

    private static function clearRememberCookie(): void
    {
        $p = session_get_cookie_params();
        setcookie(self::REMEMBER_COOKIE, '', [
            'expires' => time() - 3600,
            'path' => $p['path'] ?? '/',
            'domain' => $p['domain'] ?? '',
            'secure' => $p['secure'] ?? false,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    public static function isLocked(string $username, string $ip): bool
    {
        $cfg = $GLOBALS['config']['security'];
        $since = date('Y-m-d H:i:s', time() - $cfg['lockout_minutes'] * 60);
        $byUser = (int) Database::value(
            'SELECT COUNT(*) FROM login_attempts WHERE success = 0 AND username = ? AND ip_address = ? AND attempted_at >= ?',
            [$username, $ip, $since]
        );
        $byIp = (int) Database::value(
            'SELECT COUNT(*) FROM login_attempts WHERE success = 0 AND ip_address = ? AND attempted_at >= ?',
            [$ip, $since]
        );
        return $byUser >= $cfg['max_attempts_user'] || $byIp >= $cfg['max_attempts_ip'];
    }

    private static function recordAttempt(string $username, string $ip, bool $success): void
    {
        Database::run(
            'INSERT INTO login_attempts (username, ip_address, success, attempted_at) VALUES (?,?,?,?)',
            [$username, $ip, $success ? 1 : 0, date('Y-m-d H:i:s')]
        );
        if ($success) {   // a good login clears the counter for that user+IP
            Database::run('DELETE FROM login_attempts WHERE success = 0 AND username = ? AND ip_address = ?', [$username, $ip]);
        }
        if (random_int(1, 50) === 1) {   // housekeeping
            Database::run('DELETE FROM login_attempts WHERE attempted_at < ?', [date('Y-m-d H:i:s', time() - 86400 * 7)]);
        }
    }

    public static function logout(): void
    {
        $u = current_user();
        if ($u) {
            AuditLogger::logSafe('logout', 'auth', (int) $u['id']);
            self::clearRememberToken((int) $u['id']);
        } else {
            // No session user (e.g. CLI or session expired): clear the token
            // referenced by the remember cookie, if any.
            $cookie = $_COOKIE[self::REMEMBER_COOKIE] ?? '';
            if (is_string($cookie) && $cookie !== '' && preg_match('/^[a-f0-9]{64}$/', $cookie)) {
                $tokenHash = hash('sha256', $cookie);
                $row = Database::one('SELECT id FROM users WHERE remember_token = ?', [$tokenHash]);
                if ($row) {
                    self::clearRememberToken((int) $row['id']);
                    AuditLogger::logSafe('logout', 'auth', (int) $row['id']);
                }
            }
        }
        self::clearRememberCookie();
        self::destroySession();
    }

    public static function destroySession(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 3600,
                'path' => $p['path'],
                'domain' => $p['domain'],
                'secure' => $p['secure'],
                'httponly' => true,
                'samesite' => 'Lax'
            ]);
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    /** @return string[] list of problems (empty = acceptable) */
    public static function passwordProblems(string $password, string $username = ''): array
    {
        $min = $GLOBALS['config']['security']['min_password_len'];
        $p = [];
        if (mb_strlen($password) < $min) {
            $p[] = "at least $min characters";
        }
        if (!preg_match('/[a-z]/', $password)) {
            $p[] = 'a lowercase letter';
        }
        if (!preg_match('/[A-Z]/', $password)) {
            $p[] = 'an uppercase letter';
        }
        if (!preg_match('/\d/', $password)) {
            $p[] = 'a digit';
        }
        if (!preg_match('/[^A-Za-z0-9]/', $password)) {
            $p[] = 'a symbol';
        }
        if ($username !== '' && stripos($password, $username) !== false) {
            $p[] = 'must not contain the username';
        }
        return $p;
    }

    public static function hash(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }
}
