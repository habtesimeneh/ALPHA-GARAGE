<?php
declare(strict_types=1);

/**
 * PasswordResetService: Secure, time-limited, single-use password recovery.
 * Implements cryptographic token generation (random_bytes), SHA-256 hash storage,
 * and multi-layered anti-enumeration defenses.
 */
final class PasswordResetService
{
    /** Token lifespan in minutes */
    public const TOKEN_LIFETIME_MINUTES = 30;

    /**
     * Initiate a password recovery request for the given email address.
     * Always returns a generic confirmation to prevent user enumeration.
     */
    public static function requestReset(string $email): array
    {
        $email = trim(strtolower($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return [
                'ok'      => false,
                'message' => 'Please provide a valid email address.',
            ];
        }

        $user = Database::one(
            'SELECT id, username, full_name, email, is_active FROM users WHERE LOWER(email) = ?',
            [$email]
        );

        if ($user && !empty($user['is_active'])) {
            // Generate cryptographically secure 256-bit token
            $rawToken = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $rawToken);
            $expiresAt = date('Y-m-d H:i:s', time() + (self::TOKEN_LIFETIME_MINUTES * 60));

            Database::transaction(function () use ($user, $tokenHash, $expiresAt) {
                // Invalidate any previously pending unused reset tokens for this user
                Database::run(
                    'UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL',
                    [(int) $user['id']]
                );

                // Insert the new hashed token
                Database::insert(
                    'INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, ?)',
                    [(int) $user['id'], $tokenHash, $expiresAt]
                );
            });

            // Build full password recovery URL (absolute: it is opened from an e-mail client)
            $resetUrl = app_url('reset-password.php?token=' . $rawToken);

            // Deliver notification via MailService
            $mailResult = MailService::sendPasswordReset($user['email'], $user['full_name'], $resetUrl);
            $delivered  = !empty($mailResult['ok']);

            // Audit the request (without ever exposing raw token or link)
            AuditLogger::logSafe(
                'password_reset_requested',
                'users',
                (int) $user['id'],
                null,
                [
                    'email'     => $email,
                    'transport' => $mailResult['transport'] ?? 'unknown',
                    'delivered' => $delivered,
                ],
                'success',
                ['user_id' => (int) $user['id'], 'username' => $user['username']]
            );

            if (!$delivered) {
                AuditLogger::logSafe(
                    'password_reset_delivery_failed',
                    'security',
                    (int) $user['id'],
                    null,
                    [
                        'email'     => $email,
                        'transport' => $mailResult['transport'] ?? 'unknown',
                        'error'     => mb_substr((string) ($mailResult['error'] ?? 'Unknown delivery failure.'), 0, 300),
                    ],
                    'failure'
                );
            }
        } else {
            // Log generic failed attempt for monitoring
            AuditLogger::logSafe(
                'password_reset_unknown_email',
                'security',
                null,
                null,
                ['email' => $email],
                'denied'
            );
        }

        return [
            'ok'      => true,
            'message' => 'If an account is associated with this email address, you will receive password reset instructions shortly. Please check your inbox and spam folder.',
        ];
    }

    /**
     * Validate an incoming raw token string against the database.
     * Returns the associated user record if valid, null otherwise.
     */
    public static function validateToken(string $rawToken): ?array
    {
        $rawToken = trim($rawToken);
        if (strlen($rawToken) !== 64 || !ctype_xdigit($rawToken)) {
            return null;
        }

        $tokenHash = hash('sha256', $rawToken);

        $row = Database::one(
            'SELECT pr.id AS reset_id, pr.user_id, pr.expires_at, pr.used_at,
                    u.username, u.full_name, u.email, u.is_active
             FROM password_resets pr
             JOIN users u ON u.id = pr.user_id
             WHERE pr.token_hash = ? AND pr.used_at IS NULL AND pr.expires_at > NOW() AND u.is_active = 1',
            [$tokenHash]
        );

        return $row ?: null;
    }

    /**
     * Execute the password change for a valid token.
     */
    public static function completeReset(string $rawToken, string $newPassword): array
    {
        $reset = self::validateToken($rawToken);
        if (!$reset) {
            return [
                'ok'    => false,
                'error' => 'This password reset link is invalid or has expired. Please request a new recovery link.',
            ];
        }

        $problems = AuthService::passwordProblems($newPassword, $reset['username']);
        if ($problems) {
            return [
                'ok'    => false,
                'error' => 'Password requirements not met: ' . implode(', ', $problems) . '.',
            ];
        }

        $tokenHash = hash('sha256', trim($rawToken));
        $newHash = AuthService::hash($newPassword);
        $userId = (int) $reset['user_id'];

        Database::transaction(function () use ($userId, $tokenHash, $newHash, $reset) {
            // Update user password and clear must_change_password
            Database::run(
                'UPDATE users SET password_hash = ?, must_change_password = 0, updated_at = NOW() WHERE id = ?',
                [$newHash, $userId]
            );

            // Mark the reset token as consumed
            Database::run(
                'UPDATE password_resets SET used_at = NOW() WHERE token_hash = ?',
                [$tokenHash]
            );

            // Terminate any active sessions for security
            if (session_status() === PHP_SESSION_ACTIVE) {
                unset($_SESSION['uid']);
            }

            // Forensic audit record
            AuditLogger::log(
                'password_reset_completed',
                'users',
                $userId,
                null,
                ['username' => $reset['username'], 'email' => $reset['email']],
                'success',
                ['user_id' => $userId, 'username' => $reset['username']]
            );
        });

        return ['ok' => true];
    }
}

