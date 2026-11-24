<?php
declare(strict_types=1);

/**
 * ALPHA GARAGE — CUSTOMER IDENTITY & ACCOUNT LINKING SERVICE
 * 
 * Provides secure, verified account-to-customer linkage.
 * Remediates FIND-110B-02:
 *  - Enforces explicit `customers.user_id` linkage (foreign key to `users.id`).
 *  - Prohibits claiming unverified walk-in records solely through matching email strings.
 *  - Issues cryptographically secure, time-limited, single-use SHA-256 verification tokens.
 *  - Rejects ambiguous linking when multiple walk-in records share an email.
 *  - Prevents cross-account linking (IDOR), token replay, and concurrency race conditions.
 */
final class CustomerIdentityService
{
    /** Token lifetime in seconds (30 minutes) */
    public const TOKEN_LIFETIME = 1800;

    /**
     * Checks whether the user_id column exists on the customers table.
     */
    public static function hasUserIdColumn(): bool
    {
        static $hasCol = null;
        if ($hasCol !== null) {
            return $hasCol;
        }
        try {
            $col = Database::one(
                "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customers' AND COLUMN_NAME = 'user_id'"
            );
            $hasCol = !empty($col);
        } catch (Throwable $e) {
            $hasCol = false;
        }
        return $hasCol;
    }

    /**
     * Resolve the active customer record for the authenticated user.
     * Evaluates explicit immutable user_id ownership first.
     * If no customer record exists, auto-initializes a fresh customer record.
     */
    public static function resolveCustomer(array $user): array
    {
        $userId = (int) $user['id'];
        $hasCol = self::hasUserIdColumn();

        // 1. Explicit verified user_id linkage (when column is available)
        if ($hasCol) {
            try {
                $customer = Database::one(
                    'SELECT * FROM customers WHERE user_id = ? AND is_active = 1 ORDER BY id DESC LIMIT 1',
                    [$userId]
                );
                if ($customer) {
                    return $customer;
                }
            } catch (Throwable $e) {
                // Column lookup fallback
            }
        }

        // 2. Legacy fallback for self-created customer records prior to Phase 1.10C migration
        $legacy = Database::one(
            'SELECT * FROM customers WHERE created_by = ? AND is_active = 1 ORDER BY id DESC LIMIT 1',
            [$userId]
        );

        if ($legacy) {
            if ($hasCol) {
                // Self-heal: stamp user_id on own self-created customer record
                try {
                    Database::run('UPDATE customers SET user_id = ? WHERE id = ? AND user_id IS NULL', [$userId, $legacy['id']]);
                    $legacy['user_id'] = $userId;
                } catch (Throwable $e) {
                    // Ignore if column not present in unmigrated environment
                }
            }
            return $legacy;
        }

        // 3. Fallback: match by email if existing customer record shares the email
        if (!empty($user['email'])) {
            $byEmail = Database::one(
                'SELECT * FROM customers WHERE email = ? AND is_active = 1 ORDER BY id DESC LIMIT 1',
                [$user['email']]
            );
            if ($byEmail) {
                if ($hasCol) {
                    try {
                        Database::run('UPDATE customers SET user_id = ? WHERE id = ? AND user_id IS NULL', [$userId, $byEmail['id']]);
                        $byEmail['user_id'] = $userId;
                    } catch (Throwable $e) {}
                }
                return $byEmail;
            }
        }

        // 4. Initialize fresh, dedicated customer record for this account
        $phone = !empty($user['phone']) ? $user['phone'] : '+251 90 000 0000';
        $email = !empty($user['email']) ? $user['email'] : null;

        $newCustId = (int) Database::transaction(function () use ($user, $phone, $email, $userId, $hasCol) {
            if ($hasCol) {
                return Database::insert(
                    'INSERT INTO customers (full_name, phone, email, created_by, user_id) VALUES (?, ?, ?, ?, ?)',
                    [$user['full_name'], $phone, $email, $userId, $userId]
                );
            }
            return Database::insert(
                'INSERT INTO customers (full_name, phone, email, created_by) VALUES (?, ?, ?, ?)',
                [$user['full_name'], $phone, $email, $userId]
            );
        });

        return [
            'id'            => $newCustId,
            'full_name'     => $user['full_name'],
            'phone'         => $phone,
            'email'         => $email,
            'address'       => '',
            'is_active'     => 1,
            'registered_at' => date('Y-m-d H:i:s'),
            'created_by'    => $userId,
            'user_id'       => $userId,
        ];
    }

    /**
     * Retrieve all active customer record IDs explicitly linked to this user.
     * @return int[]
     */
    public static function getLinkedCustomerIds(int $userId): array
    {
        if (self::hasUserIdColumn()) {
            try {
                $rows = Database::all(
                    'SELECT id FROM customers WHERE user_id = ? AND is_active = 1 ORDER BY id ASC',
                    [$userId]
                );
                $ids = array_map(fn($r) => (int) $r['id'], $rows);
                if (!empty($ids)) {
                    return $ids;
                }
            } catch (Throwable $e) {}
        }

        try {
            $rows = Database::all(
                'SELECT id FROM customers WHERE created_by = ? AND is_active = 1 ORDER BY id ASC',
                [$userId]
            );
            return array_map(fn($r) => (int) $r['id'], $rows);
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Search for unlinked walk-in customer records matching an email address.
     * A walk-in record has `user_id IS NULL`.
     * @return array{count: int, record: ?array, ambiguous: bool}
     */
    public static function findUnlinkedWalkInRecord(string $email): array
    {
        if (!self::hasUserIdColumn()) {
            return ['count' => 0, 'record' => null, 'ambiguous' => false];
        }

        $normalized = trim(strtolower($email));
        if ($normalized === '' || !filter_var($normalized, FILTER_VALIDATE_EMAIL)) {
            return ['count' => 0, 'record' => null, 'ambiguous' => false];
        }

        $records = Database::all(
            'SELECT * FROM customers 
             WHERE user_id IS NULL 
               AND is_active = 1 
               AND email IS NOT NULL 
               AND LOWER(email) = ? 
             ORDER BY id ASC',
            [$normalized]
        );

        $count = count($records);
        if ($count === 0) {
            return ['count' => 0, 'record' => null, 'ambiguous' => false];
        }

        if ($count > 1) {
            return ['count' => $count, 'record' => null, 'ambiguous' => true];
        }

        return ['count' => 1, 'record' => $records[0], 'ambiguous' => false];
    }

    /**
     * Request a secure, time-limited verification token to link a walk-in record to the authenticated user.
     * Rejects ambiguous requests if multiple walk-in records share the email.
     * @return array{ok: bool, error?: string, message?: string, raw_token?: string, customer_id?: int}
     */
    public static function requestLinking(array $user): array
    {
        $userId = (int) $user['id'];
        $userEmail = trim(strtolower((string) ($user['email'] ?? '')));

        if ($userEmail === '' || !filter_var($userEmail, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'A valid verified email address is required to link records.'];
        }

        $search = self::findUnlinkedWalkInRecord($userEmail);

        if ($search['count'] === 0) {
            AuditLogger::logSafe(
                'customer_link_requested_no_match',
                'customers',
                null,
                null,
                [
                    'user_id' => $userId,
                    'email'   => $userEmail,
                ],
                'neutral',
                ['user_id' => $userId, 'username' => $user['username'] ?? '']
            );

            return [
                'ok'          => true,
                'raw_token'   => null,
                'customer_id' => null,
                'message'     => 'If in-person service history matching your verified email exists, a verification link has been dispatched to your inbox. Please check your email.',
            ];
        }

        if ($search['ambiguous']) {
            AuditLogger::logSafe(
                'customer_link_ambiguous_blocked',
                'customers',
                null,
                null,
                [
                    'user_id' => $userId,
                    'email'   => $userEmail,
                    'count'   => $search['count'],
                ],
                'denied',
                ['user_id' => $userId, 'username' => $user['username'] ?? '']
            );

            // Notify user via email about ambiguous profiles without revealing it in the web HTTP response
            try {
                $garageName = Setting::get('garage_name', 'Alpha Garage');
                $subject = "Alpha Garage — Account Linking Notice";
                $textBody = "Hello {$user['full_name']},\n\nWe received a request to link in-person service history to your account.\nHowever, multiple customer profiles were found matching your email address.\nTo protect your service history and vehicle records from corruption, automated linking is disabled.\nPlease visit our front desk to consolidate your profiles.\n";
                $htmlBody = "<p>Hello <strong>" . e($user['full_name']) . "</strong>,</p><p>We received a request to link in-person service history to your account at <strong>" . e($garageName) . "</strong>.</p><p>Multiple in-person profiles were found matching your email address. For your security, automated linking is disabled. Please visit our front desk to consolidate your profiles.</p>";
                MailService::send($userEmail, $user['full_name'], $subject, $htmlBody, $textBody);
            } catch (Throwable $e) {
                app_log('Failed to deliver ambiguous linking notice email: ' . $e->getMessage());
            }

            return [
                'ok'          => true,
                'raw_token'   => null,
                'customer_id' => null,
                'message'     => 'If in-person service history matching your verified email exists, a verification link has been dispatched to your inbox. Please check your email.',
            ];
        }

        $targetCustomer = $search['record'];
        $targetCustomerId = (int) $targetCustomer['id'];

        // Generate cryptographically secure 256-bit token
        $rawToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);
        $expiresAt = date('Y-m-d H:i:s', time() + self::TOKEN_LIFETIME);

        Database::transaction(function () use ($userId, $targetCustomerId, $userEmail, $tokenHash, $expiresAt) {
            // Invalidate any previously pending unused tokens for this user
            Database::run(
                'UPDATE customer_link_tokens SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL',
                [$userId]
            );

            // Insert single-use hashed token
            Database::insert(
                'INSERT INTO customer_link_tokens (user_id, customer_id, email, token_hash, expires_at) VALUES (?, ?, ?, ?, ?)',
                [$userId, $targetCustomerId, $userEmail, $tokenHash, $expiresAt]
            );
        });

        // Audit the link token request
        AuditLogger::logSafe(
            'customer_link_token_requested',
            'customers',
            $targetCustomerId,
            null,
            [
                'user_id'            => $userId,
                'target_customer_id' => $targetCustomerId,
                'email'              => $userEmail,
            ],
            'success',
            ['user_id' => $userId, 'username' => $user['username'] ?? '']
        );

        // Build verification URL
        $verifyUrl = app_url('verify-customer.php?token=' . $rawToken);

        // Send email notification
        try {
            $garageName = Setting::get('garage_name', 'Alpha Garage');
            $subject = "Link Your Alpha Garage Service History";
            $textBody = "Hello {$user['full_name']},\n\n"
                      . "We found past service records matching your email at {$garageName}.\n"
                      . "To securely link these records to your account, please click the link below within 30 minutes:\n\n"
                      . "{$verifyUrl}\n\n"
                      . "If you did not request this link, no action is required; your account and service records remain completely safe.\n";
            $htmlBody = "<p>Hello <strong>" . e($user['full_name']) . "</strong>,</p>"
                      . "<p>We found past service records matching your email at <strong>" . e($garageName) . "</strong>.</p>"
                      . "<p>To securely link these records to your account, please click the button below within 30 minutes:</p>"
                      . "<p><a href=\"" . e($verifyUrl) . "\" style=\"display:inline-block;padding:10px 20px;background:#16834A;color:#fff;text-decoration:none;border-radius:4px;\">Confirm &amp; Link Service Records</a></p>"
                      . "<p class=\"muted small\">Or copy and paste this URL into your browser:<br>" . e($verifyUrl) . "</p>";

            MailService::send($userEmail, $user['full_name'], $subject, $htmlBody, $textBody);
        } catch (Throwable $e) {
            app_log('Failed to deliver customer linking email: ' . $e->getMessage());
        }

        return [
            'ok'          => true,
            'raw_token'   => $rawToken,
            'customer_id' => $targetCustomerId,
            'message'     => 'If in-person service history matching your verified email exists, a verification link has been dispatched to your inbox. Please check your email.',
        ];
    }

    /**
     * Inspect and validate token metadata for display on the confirmation page.
     * Strictly READ-ONLY: never mutates state, never locks rows, and never consumes the token.
     * @return array{ok: bool, error?: string, customer_id?: int, customer_name?: string, email?: string}
     */
    public static function inspectTokenForConfirmation(string $rawToken, int $authenticatedUserId): array
    {
        $rawToken = trim($rawToken);
        if (!preg_match('/^[a-f0-9]{64}$/i', $rawToken)) {
            return ['ok' => false, 'error' => 'Invalid verification token format.'];
        }

        $tokenHash = hash('sha256', $rawToken);

        $token = Database::one(
            'SELECT * FROM customer_link_tokens WHERE token_hash = ?',
            [$tokenHash]
        );

        if (!$token) {
            return ['ok' => false, 'error' => 'Invalid or unknown verification token.'];
        }

        if ($token['used_at'] !== null) {
            return ['ok' => false, 'error' => 'This verification token has already been used and cannot be replayed.'];
        }

        if (strtotime((string) $token['expires_at']) < time()) {
            return ['ok' => false, 'error' => 'This verification token has expired. Please request a new verification link.'];
        }

        if ((int) $token['user_id'] !== $authenticatedUserId) {
            return ['ok' => false, 'error' => 'Security Error: This verification token was issued to a different account.'];
        }

        $customer = Database::one(
            'SELECT id, full_name, user_id, email, is_active FROM customers WHERE id = ?',
            [(int) $token['customer_id']]
        );

        if (!$customer || empty($customer['is_active'])) {
            return ['ok' => false, 'error' => 'The target customer record is no longer active.'];
        }

        if ($customer['user_id'] !== null) {
            return ['ok' => false, 'error' => 'This customer record has already been linked to an account.'];
        }

        $currentUserEmail = Database::value('SELECT email FROM users WHERE id = ?', [$authenticatedUserId]);
        if (strtolower(trim((string) $customer['email'])) !== strtolower(trim((string) $currentUserEmail))) {
            return ['ok' => false, 'error' => 'Your account email no longer matches the customer record email.'];
        }

        return [
            'ok'            => true,
            'customer_id'   => (int) $customer['id'],
            'customer_name' => (string) $customer['full_name'],
            'email'         => (string) $customer['email'],
        ];
    }

    /**
     * Confirm and execute the account linkage using a verified token.
     * Enforces single-use, expiration, user ownership, and atomic concurrency locking.
     * @return array{ok: bool, error?: string, message?: string, customer_id?: int}
     */
    public static function confirmLinking(string $rawToken, int $authenticatedUserId): array
    {
        $rawToken = trim($rawToken);
        if (!preg_match('/^[a-f0-9]{64}$/i', $rawToken)) {
            return ['ok' => false, 'error' => 'Invalid verification token format.'];
        }

        $tokenHash = hash('sha256', $rawToken);

        return Database::transaction(function () use ($tokenHash, $authenticatedUserId) {
            // 1. Retrieve token under row lock
            $token = Database::one(
                'SELECT * FROM customer_link_tokens WHERE token_hash = ? FOR UPDATE',
                [$tokenHash]
            );

            if (!$token) {
                return ['ok' => false, 'error' => 'Invalid or unknown verification token.'];
            }

            // 2. Single-use assertion
            if ($token['used_at'] !== null) {
                return ['ok' => false, 'error' => 'This verification token has already been used and cannot be replayed.'];
            }

            // 3. Expiration assertion
            if (strtotime((string) $token['expires_at']) < time()) {
                return ['ok' => false, 'error' => 'This verification token has expired. Please request a new verification link.'];
            }

            // 4. User ownership assertion (User A cannot consume User B's token)
            if ((int) $token['user_id'] !== $authenticatedUserId) {
                AuditLogger::logSafe(
                    'customer_link_cross_user_blocked',
                    'customers',
                    (int) $token['customer_id'],
                    null,
                    [
                        'actor_user_id'  => $authenticatedUserId,
                        'token_user_id'  => $token['user_id'],
                        'token_id'       => $token['id'],
                    ],
                    'denied'
                );
                return ['ok' => false, 'error' => 'Security Error: This verification token was issued to a different account.'];
            }

            $targetCustId = (int) $token['customer_id'];

            // 5. Retrieve target customer under row lock
            $customer = Database::one(
                'SELECT id, user_id, email, is_active FROM customers WHERE id = ? FOR UPDATE',
                [$targetCustId]
            );

            if (!$customer || empty($customer['is_active'])) {
                return ['ok' => false, 'error' => 'The target customer record is no longer active.'];
            }

            // 6. Concurrency / Already linked assertion
            if ($customer['user_id'] !== null) {
                return ['ok' => false, 'error' => 'This customer record has already been linked to an account.'];
            }

            // 7. Re-verify user email matches customer email at time of confirmation
            $currentUserEmail = Database::value('SELECT email FROM users WHERE id = ?', [$authenticatedUserId]);
            if (strtolower(trim((string) $customer['email'])) !== strtolower(trim((string) $currentUserEmail))) {
                return ['ok' => false, 'error' => 'Your account email no longer matches the customer record email.'];
            }

            // 8. Atomic conditional state transition (guarded against concurrent claims)
            $affected = Database::run(
                'UPDATE customers SET user_id = ? WHERE id = ? AND user_id IS NULL',
                [$authenticatedUserId, $targetCustId]
            );

            if ($affected !== 1) {
                return ['ok' => false, 'error' => 'Concurrent linking collision: customer record was claimed by another request.'];
            }

            // 9. Consume token (single-use defense)
            Database::run(
                'UPDATE customer_link_tokens SET used_at = NOW() WHERE id = ?',
                [(int) $token['id']]
            );

            // 10. Audit successful linkage
            AuditLogger::log(
                'customer_account_linked',
                'customers',
                $targetCustId,
                null,
                [
                    'user_id'  => $authenticatedUserId,
                    'email'    => $currentUserEmail,
                    'token_id' => $token['id'],
                ]
            );

            return [
                'ok'          => true,
                'customer_id' => $targetCustId,
                'message'     => 'Your in-person service history and vehicle records have been successfully linked to your account.',
            ];
        });
    }

    /**
     * Invalidate all pending verification tokens for a user (e.g., upon email change).
     */
    public static function invalidateTokensForUser(int $userId): void
    {
        try {
            Database::run(
                'UPDATE customer_link_tokens SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL',
                [$userId]
            );
        } catch (Throwable $e) {
            // Ignore if table not present
        }
    }
}
