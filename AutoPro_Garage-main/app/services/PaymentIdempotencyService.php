<?php
declare(strict_types=1);

/**
 * ALPHA GARAGE — PAYMENT IDEMPOTENCY SERVICE
 * 
 * Enforces atomic duplicate payment prevention via database-level uniqueness constraints.
 * Eliminates duplicate credits from:
 *   - Rapid double-clicks
 *   - Browser form resubmission
 *   - Concurrent HTTP requests
 *   - Network retries
 *   - Replayed CSRF tokens
 */
final class PaymentIdempotencyService
{
    private static ?bool $tableAvailable = null;

    /**
     * Generate a cryptographically secure idempotency key.
     */
    public static function generateKey(): string
    {
        return bin2hex(random_bytes(24)); // 48-character hex string
    }

    /**
     * Check if a given string matches valid idempotency key format.
     */
    public static function isValidFormat(?string $key): bool
    {
        if ($key === null || $key === '') {
            return false;
        }
        return (bool) preg_match('/^[a-zA-Z0-9_\-]{16,64}$/', $key);
    }

    /**
     * Validate key format or throw DomainException.
     */
    public static function validateKey(?string $key): string
    {
        if (!self::isValidFormat($key)) {
            throw new DomainException('A valid idempotency key (16 to 64 alphanumeric characters) is required for payment processing.');
        }
        return (string) $key;
    }

    /**
     * Compute SHA-256 fingerprint for request payload consistency checks.
     */
    public static function computeHash(
        int $userId,
        int $invoiceId,
        string $operationType,
        string $amount,
        string $method,
        string $reference
    ): string {
        $normalized = implode('|', [
            $userId,
            $invoiceId,
            strtolower(trim($operationType)),
            number_format((float) $amount, 2, '.', ''),
            strtolower(trim($method)),
            trim($reference),
        ]);
        return hash('sha256', $normalized);
    }

    /**
     * Attempt to atomically acquire an idempotency lock.
     * 
     * @return array{status: 'new'|'completed', payment_id?: int, payload?: array}
     */
    public static function acquire(
        string $key,
        int $userId,
        int $invoiceId,
        string $operationType,
        string $amount,
        string $method,
        string $reference
    ): array {
        if (!self::isTableAvailable()) {
            return ['status' => 'new'];
        }

        $validatedKey = self::validateKey($key);
        $requestHash = self::computeHash($userId, $invoiceId, $operationType, $amount, $method, $reference);
        $formattedAmount = number_format((float) $amount, 2, '.', '');

        try {
            // Attempt atomic insert (enforced by UNIQUE KEY uq_idempotency_key)
            Database::insert(
                'INSERT INTO payment_idempotency_keys 
                 (idempotency_key, user_id, invoice_id, operation_type, amount, request_hash, status) 
                 VALUES (?, ?, ?, ?, ?, ?, "pending")',
                [$validatedKey, $userId, $invoiceId, $operationType, $formattedAmount, $requestHash]
            );

            return ['status' => 'new'];
        } catch (PDOException $e) {
            // Check for duplicate key violation (SQLSTATE 23000, MySQL Error 1062)
            if ($e->getCode() !== '23000' && !str_contains($e->getMessage(), '1062')) {
                throw $e;
            }

            // Key already exists — fetch existing record
            $existing = Database::one('SELECT * FROM payment_idempotency_keys WHERE idempotency_key = ?', [$validatedKey]);
            if (!$existing) {
                throw new DomainException('Idempotency lock collision detected, but existing record could not be loaded.');
            }

            // 1. Verify ownership (prevent cross-user key hijacking)
            if ((int) $existing['user_id'] !== $userId) {
                throw new DomainException('Unauthorized: Idempotency key belongs to another user.');
            }

            // 2. Handle previously failed transactions: allow clean retry
            if ($existing['status'] === 'failed') {
                Database::run(
                    'UPDATE payment_idempotency_keys 
                     SET status = "pending", invoice_id = ?, operation_type = ?, amount = ?, request_hash = ?, error_message = NULL 
                     WHERE idempotency_key = ?',
                    [$invoiceId, $operationType, $formattedAmount, $requestHash, $validatedKey]
                );
                return ['status' => 'new'];
            }

            // 3. Verify payload integrity for completed or in-flight keys (prevent key reuse with altered amount/invoice)
            $existingAmount = number_format((float) $existing['amount'], 2, '.', '');
            if ((int) $existing['invoice_id'] !== $invoiceId ||
                $existing['operation_type'] !== $operationType ||
                $existingAmount !== $formattedAmount ||
                $existing['request_hash'] !== $requestHash) {
                throw new DomainException('Conflict: Idempotency key was already used with a different payment amount, invoice, or method.');
            }

            // 4. Completed status
            if ($existing['status'] === 'completed') {
                $payload = json_decode($existing['response_payload'] ?? '{}', true);
                return [
                    'status' => 'completed',
                    'payment_id' => (int) ($existing['payment_id'] ?? 0),
                    'payload' => is_array($payload) ? $payload : [],
                ];
            }

            // 5. In-flight pending status: poll for concurrent completion
            if ($existing['status'] === 'pending') {
                $maxAttempts = 50;
                for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
                    usleep(50000); // 50ms
                    $poll = Database::one('SELECT * FROM payment_idempotency_keys WHERE idempotency_key = ?', [$validatedKey]);
                    if ($poll && $poll['status'] === 'completed') {
                        $payload = json_decode($poll['response_payload'] ?? '{}', true);
                        return [
                            'status' => 'completed',
                            'payment_id' => (int) ($poll['payment_id'] ?? 0),
                            'payload' => is_array($payload) ? $payload : [],
                        ];
                    }
                    if ($poll && $poll['status'] === 'failed') {
                        Database::run(
                            'UPDATE payment_idempotency_keys SET status = "pending", request_hash = ?, error_message = NULL WHERE idempotency_key = ?',
                            [$requestHash, $validatedKey]
                        );
                        return ['status' => 'new'];
                    }
                }

                throw new DomainException('Payment is currently being processed by another simultaneous request. Please check receipt before retrying.');
            }

            throw new DomainException('Unexpected idempotency state.');
        }
    }

    /**
     * Mark an idempotency key as completed and persist response payload.
     */
    public static function complete(string $key, int $paymentId, array $responsePayload): void
    {
        if (!self::isTableAvailable()) {
            return;
        }

        Database::run(
            'UPDATE payment_idempotency_keys 
             SET status = "completed", payment_id = ?, response_payload = ?, error_message = NULL 
             WHERE idempotency_key = ?',
            [$paymentId, json_encode($responsePayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $key]
        );
    }

    /**
     * Mark an idempotency key as failed.
     */
    public static function fail(string $key, string $errorMessage): void
    {
        if (!self::isTableAvailable()) {
            return;
        }

        try {
            Database::run(
                'UPDATE payment_idempotency_keys 
                 SET status = "failed", error_message = ? 
                 WHERE idempotency_key = ?',
                [mb_substr($errorMessage, 0, 255), $key]
            );
        } catch (Throwable) {
            // Fail safely if database error occurs during error recording
        }
    }

    /**
     * Check if payment_idempotency_keys table exists in active database.
     */
    public static function isTableAvailable(): bool
    {
        if (self::$tableAvailable !== null) {
            return self::$tableAvailable;
        }

        try {
            $exists = Database::value(
                "SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'payment_idempotency_keys'"
            );
            self::$tableAvailable = (bool) $exists;
        } catch (Throwable) {
            self::$tableAvailable = false;
        }

        return self::$tableAvailable;
    }
}
