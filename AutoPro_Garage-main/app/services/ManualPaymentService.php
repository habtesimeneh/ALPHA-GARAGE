<?php
declare(strict_types=1);

/**
 * ALPHA GARAGE — Manual Payment Management & Verification Service
 * Handles configurable payment methods, customer payment submissions with historical snapshots,
 * atomic database-level duplicate reference prevention, secure proof upload verification,
 * staff verification/rejection workflow, cash reception reconciliation, and CSV export protection.
 */
final class ManualPaymentService
{
    /** Allowed MIME types and their extensions for payment proof uploads */
    public const ALLOWED_MIME_TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
    ];

    /** Maximum proof file size: 5 MB */
    public const MAX_FILE_SIZE = 5 * 1024 * 1024;

    /** Standard rejection reasons */
    public const REJECTION_REASONS = [
        'ref_not_found' => 'Transaction reference not found in bank statement',
        'amount_mismatch' => 'Submitted amount does not match received transfer',
        'illegible_proof' => 'Payment proof is blurry, illegible, or incomplete',
        'duplicate_submission' => 'Duplicate transaction already verified or recorded',
        'wrong_account' => 'Funds transferred to incorrect account number',
        'other' => 'Other (specified in reviewer notes)',
    ];

    // =========================================================================
    // 1. PAYMENT METHODS CONFIGURATION
    // =========================================================================

    /**
     * Get all active payment methods ordered by display order.
     * @return array<int, array<string, mixed>>
     */
    public static function getActiveMethods(): array
    {
        return Database::all(
            'SELECT * FROM payment_methods WHERE is_active = 1 ORDER BY display_order ASC, name ASC'
        );
    }

    /**
     * Get all payment methods (for Admin management).
     * @return array<int, array<string, mixed>>
     */
    public static function getAllMethods(): array
    {
        return Database::all(
            'SELECT * FROM payment_methods ORDER BY display_order ASC, id ASC'
        );
    }

    /**
     * Find a single payment method by ID.
     */
    public static function getMethod(int $id): ?array
    {
        return Database::one('SELECT * FROM payment_methods WHERE id = ?', [$id]);
    }

    /**
     * Find a single payment method by its code (e.g., 'cbe', 'telebirr').
     */
    public static function getMethodByCode(string $code): ?array
    {
        return Database::one('SELECT * FROM payment_methods WHERE code = ?', [$code]);
    }

    /**
     * 1-Click Toggle Payment Method Active/Inactive status.
     * Dedicated privilege for Administrator.
     */
    public static function toggleMethodStatus(int $id, bool $active, int $userId): bool
    {
        $method = self::getMethod($id);
        if (!$method) {
            throw new DomainException('Payment method not found.');
        }

        $newActive = $active ? 1 : 0;
        Database::run(
            'UPDATE payment_methods SET is_active = ?, updated_at = NOW() WHERE id = ?',
            [$newActive, $id]
        );

        AuditLogger::log(
            'payment_method_status_toggled',
            'payment_methods',
            $id,
            ['is_active' => (int)$method['is_active']],
            ['is_active' => $newActive, 'code' => $method['code'], 'name' => $method['name'], 'updated_by' => $userId]
        );

        return true;
    }

    /**
     * Create or update a payment method.
     * Dynamically handles bank, mobile money, merchant, and cash accounts.
     */
    public static function saveMethod(array $data, ?int $id = null, int $userId = 0): int
    {
        $code = strtolower(trim((string)($data['code'] ?? '')));
        $name = trim((string)($data['name'] ?? ''));
        $nameAm = trim((string)($data['name_am'] ?? '')) ?: $name;
        $type = (string)($data['type'] ?? 'bank');
        $bankName = trim((string)($data['bank_name'] ?? '')) ?: null;
        $accountName = trim((string)($data['account_name'] ?? '')) ?: null;
        $accountNumber = trim((string)($data['account_number'] ?? '')) ?: null;
        $phoneNumber = trim((string)($data['phone_number'] ?? '')) ?: null;
        $merchantNumber = trim((string)($data['merchant_number'] ?? '')) ?: null;
        $tillNumber = trim((string)($data['till_number'] ?? '')) ?: null;
        $branch = trim((string)($data['branch'] ?? '')) ?: null;
        $accountType = trim((string)($data['account_type'] ?? '')) ?: null;
        $instructions = trim((string)($data['instructions'] ?? '')) ?: null;
        $instructionsAm = trim((string)($data['instructions_am'] ?? '')) ?: null;
        $isActive = !empty($data['is_active']) ? 1 : 0;
        $requiresRef = isset($data['requires_reference']) ? (!empty($data['requires_reference']) ? 1 : 0) : ($type === 'cash' ? 0 : 1);
        $requiresProof = isset($data['requires_screenshot']) ? (!empty($data['requires_screenshot']) ? 1 : 0) : ($type === 'cash' ? 0 : 1);
        $displayOrder = (int)($data['display_order'] ?? 0);
        $icon = trim((string)($data['icon'] ?? '')) ?: null;

        if ($name === '') {
            throw new DomainException('Provider display name in English is required.');
        }

        if ($code === '') {
            $code = strtolower(preg_replace('/[^a-z0-9_]/i', '_', $name));
            $code = trim(preg_replace('/_+/', '_', $code), '_');
            if (strlen($code) > 40) {
                $code = substr($code, 0, 40);
            }
        }

        if (!in_array($type, ['bank', 'mobile_money', 'cash', 'other'], true)) {
            throw new DomainException('Invalid provider category.');
        }

        // Validate category-specific requirements
        if ($type === 'bank' && empty($accountNumber)) {
            throw new DomainException('Bank account number is required for bank payment methods.');
        }
        if ($type === 'mobile_money' && empty($phoneNumber) && empty($merchantNumber) && empty($tillNumber)) {
            throw new DomainException('At least one mobile wallet identifier (phone number, merchant number, or till number) is required.');
        }

        if ($id !== null && $id > 0) {
            $existing = self::getMethod($id);
            if (!$existing) {
                throw new DomainException('Payment method not found.');
            }
            Database::run(
                'UPDATE payment_methods SET
                    code = ?, name = ?, name_am = ?, type = ?, bank_name = ?,
                    account_name = ?, account_number = ?, phone_number = ?,
                    merchant_number = ?, till_number = ?, branch = ?, account_type = ?,
                    instructions = ?, instructions_am = ?, is_active = ?,
                    requires_reference = ?, requires_screenshot = ?, display_order = ?, icon = ?,
                    updated_at = NOW()
                 WHERE id = ?',
                [$code, $name, $nameAm, $type, $bankName,
                 $accountName, $accountNumber, $phoneNumber,
                 $merchantNumber, $tillNumber, $branch, $accountType,
                 $instructions, $instructionsAm, $isActive,
                 $requiresRef, $requiresProof, $displayOrder, $icon, $id]
            );

            AuditLogger::log(
                'payment_method_updated',
                'payment_methods',
                $id,
                ['name' => $existing['name'], 'is_active' => $existing['is_active']],
                ['name' => $name, 'is_active' => $isActive, 'updated_by' => $userId]
            );
            return $id;
        }

        $newId = Database::insert(
            'INSERT INTO payment_methods
                (code, name, name_am, type, bank_name, account_name, account_number, phone_number,
                 merchant_number, till_number, branch, account_type,
                 instructions, instructions_am, is_active, requires_reference, requires_screenshot, display_order, icon)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$code, $name, $nameAm, $type, $bankName, $accountName, $accountNumber, $phoneNumber,
             $merchantNumber, $tillNumber, $branch, $accountType,
             $instructions, $instructionsAm, $isActive, $requiresRef, $requiresProof, $displayOrder, $icon]
        );

        AuditLogger::log(
            'payment_method_created',
            'payment_methods',
            $newId,
            null,
            ['code' => $code, 'name' => $name, 'type' => $type, 'created_by' => $userId]
        );

        return $newId;
    }

    /**
     * Mask sensitive account or phone numbers for safe display.
     */
    public static function maskIdentifier(?string $identifier): string
    {
        $id = trim((string)($identifier ?? ''));
        if ($id === '' || strlen($id) <= 4) {
            return $id;
        }
        $tail = substr($id, -4);
        $headLen = max(4, min(strlen($id) - 4, 10));
        return str_repeat('•', $headLen) . ' ' . $tail;
    }

    // =========================================================================
    // 2. CUSTOMER PAYMENT SUBMISSION
    // =========================================================================

    /**
     * Submit manual payment information and proof for an outstanding invoice.
     * Records historical configuration snapshots and enforces atomic duplicate transaction protection.
     *
     * @param int $customerId Logged-in customer ID
     * @param int $invoiceId Target invoice ID
     * @param array<string, mixed> $data Form inputs (payment_method_id, amount, transaction_reference, etc.)
     * @param array<string, mixed>|null $file $_FILES['proof'] if present
     * @return array<string, mixed> Created submission record
     */
    public static function submitPayment(int $customerId, int $invoiceId, array $data, ?array $file = null): array
    {
        $inv = InvoiceService::find($invoiceId);
        if (!$inv) {
            throw new DomainException('Invoice not found.');
        }

        // Validate customer access (anti-IDOR)
        if ($customerId > 0) {
            $customerMatch = (int)$inv['customer_id'] === $customerId;
            if (!$customerMatch && function_exists('current_user')) {
                $user = current_user();
                if ($user && ($user['role_name'] ?? '') === 'customer') {
                    $primaryCust = CustomerIdentityService::resolveCustomer($user);
                    $linkedIds = CustomerIdentityService::getLinkedCustomerIds((int) $user['id']);
                    if (!in_array((int) $primaryCust['id'], $linkedIds, true)) {
                        $linkedIds[] = (int) $primaryCust['id'];
                    }
                    if (!in_array((int)$inv['customer_id'], $linkedIds, true)) {
                        throw new DomainException('You are not authorized to pay this invoice.');
                    }
                }
            }
        }

        // Check invoice status
        if ($inv['payment_status'] === 'Paid' || (float)$inv['balance'] <= 0) {
            throw new DomainException('This invoice is already fully settled.');
        }
        if ($inv['payment_status'] === 'Refunded') {
            throw new DomainException('This invoice was refunded and is closed.');
        }

        // Validate Amount
        $rawAmount = (string)($data['amount'] ?? '');
        $cents = to_cents($rawAmount);
        if ($cents <= 0) {
            throw new DomainException('Please enter a payment amount greater than zero.');
        }
        $balanceCents = to_cents($inv['balance']);
        if ($cents > $balanceCents) {
            throw new DomainException(
                'The submitted amount (' . money(from_cents($cents)) . ') exceeds the outstanding balance (' . money(from_cents($balanceCents)) . ').'
            );
        }

        // Validate Payment Method
        $methodId = (int)($data['payment_method_id'] ?? 0);
        $method = self::getMethod($methodId);
        if (!$method) {
            throw new DomainException('Please select a valid payment method.');
        }
        if (empty($method['is_active'])) {
            throw new DomainException('Selected payment method is currently unavailable.');
        }

        // Validate Transaction Reference
        $txRef = trim((string)($data['transaction_reference'] ?? ''));
        if (!empty($method['requires_reference']) && $txRef === '') {
            throw new DomainException('Transaction reference number is required.');
        }

        // Application-Level Duplicate Reference Check
        if ($txRef !== '') {
            $dupCount = (int) Database::value(
                "SELECT COUNT(*) FROM payment_submissions
                 WHERE payment_method_id = ? AND transaction_reference = ? AND status IN ('pending_verification', 'under_review', 'verified')",
                [$methodId, $txRef]
            );
            if ($dupCount > 0) {
                throw new DomainException('This transaction reference has already been submitted for verification.');
            }
        }

        // Transaction Date & Time
        $txDate = trim((string)($data['transaction_date'] ?? '')) ?: date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $txDate)) {
            throw new DomainException('A valid transaction date (YYYY-MM-DD) is required.');
        }
        $txTime = trim((string)($data['transaction_time'] ?? '')) ?: date('H:i:s');
        if (strlen($txTime) === 5) {
            $txTime .= ':00';
        }

        // Sender Details
        $senderName = trim((string)($data['sender_name'] ?? ''));
        if ($senderName === '') {
            $senderName = $inv['customer_name'] ?? 'Customer';
        }
        $senderPhone = trim((string)($data['sender_phone'] ?? ''));
        if ($senderPhone === '') {
            $senderPhone = $inv['customer_phone'] ?? '';
        }

        $customerNotes = trim((string)($data['customer_notes'] ?? '')) ?: null;

        // Process Proof Upload
        $proofPath = null;
        $proofMime = null;
        $proofSize = null;

        $hasFile = $file && !empty($file['tmp_name']) && is_uploaded_file($file['tmp_name']);
        if ($file && !empty($file['tmp_name']) && file_exists($file['tmp_name']) && (PHP_SAPI === 'cli' || is_uploaded_file($file['tmp_name']))) {
            $hasFile = true;
        }

        if (!empty($method['requires_screenshot']) && !$hasFile) {
            throw new DomainException('Payment proof screenshot or receipt file is required.');
        }

        if ($hasFile) {
            if ($file['error'] !== UPLOAD_ERR_OK) {
                throw new DomainException('Error uploading payment proof file. Code: ' . $file['error']);
            }
            if ($file['size'] > self::MAX_FILE_SIZE) {
                throw new DomainException('Payment proof file size exceeds the 5 MB limit.');
            }

            $mime = self::detectMimeType($file['tmp_name']);

            if (!isset(self::ALLOWED_MIME_TYPES[$mime])) {
                throw new DomainException('Invalid file type (' . $mime . '). Allowed formats: JPG, PNG, WEBP, PDF.');
            }

            $ext = self::ALLOWED_MIME_TYPES[$mime];
            $storageDir = self::getStorageDir();
            if (!is_dir($storageDir)) {
                mkdir($storageDir, 0750, true);
            }

            $randomName = 'proof_' . bin2hex(random_bytes(16)) . '.' . $ext;
            $destination = $storageDir . '/' . $randomName;

            if (is_uploaded_file($file['tmp_name'])) {
                if (!move_uploaded_file($file['tmp_name'], $destination)) {
                    throw new RuntimeException('Failed to securely store uploaded proof.');
                }
            } else {
                if (!copy($file['tmp_name'], $destination)) {
                    throw new RuntimeException('Failed to securely copy proof file.');
                }
            }

            $proofPath = $randomName;
            $proofMime = $mime;
            $proofSize = (int)$file['size'];
        }

        // Historical snapshot of provider account details at the moment of submission
        $snapPname = $method['name'];
        $snapPnameAm = $method['name_am'] ?: $method['name'];
        $snapAname = $method['account_name'];
        $snapAnum = $method['account_number'] ?? $method['merchant_number'] ?? $method['till_number'];
        $snapPhone = $method['phone_number'];
        $snapInst = $method['instructions'];
        $snapInstAm = $method['instructions_am'];

        // Atomic Transaction with DB-level active reference lock
        $submissionId = (int) Database::transaction(function () use (
            $inv, $customerId, $methodId, $cents, $txRef, $txDate, $txTime,
            $senderName, $senderPhone, $proofPath, $proofMime, $proofSize, $customerNotes,
            $snapPname, $snapPnameAm, $snapAname, $snapAnum, $snapPhone, $snapInst, $snapInstAm
        ) {
            $placeholder = 'SUB-TEMP-' . bin2hex(random_bytes(6));
            $subId = Database::insert(
                'INSERT INTO payment_submissions
                    (submission_number, invoice_id, customer_id, payment_method_id, amount,
                     currency, transaction_reference, transaction_date, transaction_time,
                     sender_name, sender_phone, proof_file_path, proof_file_mime, proof_file_size,
                     customer_notes, snapshot_provider_name, snapshot_provider_name_am,
                     snapshot_account_name, snapshot_account_number, snapshot_phone_number,
                     snapshot_instructions, snapshot_instructions_am, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $placeholder,
                    $inv['id'],
                    $inv['customer_id'],
                    $methodId,
                    from_cents($cents),
                    Setting::get('currency', 'ETB'),
                    $txRef,
                    $txDate,
                    $txTime,
                    $senderName,
                    $senderPhone,
                    $proofPath,
                    $proofMime,
                    $proofSize,
                    $customerNotes,
                    $snapPname,
                    $snapPnameAm,
                    $snapAname,
                    $snapAnum,
                    $snapPhone,
                    $snapInst,
                    $snapInstAm,
                    'pending_verification'
                ]
            );

            // Atomic DB-level duplicate reference enforcement
            if ($txRef !== '') {
                try {
                    Database::insert(
                        'INSERT INTO payment_active_references (payment_method_id, transaction_reference, submission_id) VALUES (?, ?, ?)',
                        [$methodId, $txRef, $subId]
                    );
                } catch (Throwable $e) {
                    throw new DomainException('This transaction reference is currently active on another submission. Please verify your reference number.');
                }
            }

            $finalNumber = sprintf('SUB-%s-%04d', date('Ymd'), $subId);
            Database::run('UPDATE payment_submissions SET submission_number = ? WHERE id = ?', [$finalNumber, $subId]);

            AuditLogger::log(
                'payment_submission_created',
                'payment_submissions',
                $subId,
                null,
                [
                    'submission_number' => $finalNumber,
                    'invoice' => $inv['invoice_number'],
                    'amount' => from_cents($cents),
                    'method_id' => $methodId,
                    'reference' => $txRef,
                    'customer_id' => $inv['customer_id']
                ]
            );

            return $subId;
        });

        return self::getSubmission($submissionId);
    }

    // =========================================================================
    // 3. VERIFICATION, REJECTION & RECONCILIATION WORKFLOW
    // =========================================================================

    /**
     * Mark a submission as Under Review by an authorized staff reviewer.
     */
    public static function markUnderReview(int $submissionId, int $reviewerId): bool
    {
        $sub = self::getSubmission($submissionId);
        if (!$sub) {
            throw new DomainException('Payment submission not found.');
        }
        if ($sub['status'] !== 'pending_verification') {
            return false;
        }

        Database::run(
            'UPDATE payment_submissions SET status = "under_review", reviewer_id = ?, updated_at = NOW() WHERE id = ?',
            [$reviewerId, $submissionId]
        );

        AuditLogger::log(
            'payment_submission_under_review',
            'payment_submissions',
            $submissionId,
            ['status' => 'pending_verification'],
            ['status' => 'under_review', 'reviewer_id' => $reviewerId]
        );

        return true;
    }

    /**
     * Verify and approve a payment submission.
     * Atomically locks records, re-checks transaction uniqueness across verified submissions,
     * checks invoice balance, records ledger payment, updates submission status, and commits.
     */
    public static function verifyPayment(int $submissionId, int $reviewerId, ?string $notes = null, ?string $verifiedAmount = null): array
    {
        return Database::transaction(function () use ($submissionId, $reviewerId, $notes, $verifiedAmount) {
            // 1. Lock submission row
            $sub = Database::one(
                'SELECT ps.*, pm.name AS method_name, pm.code AS method_code
                 FROM payment_submissions ps
                 JOIN payment_methods pm ON pm.id = ps.payment_method_id
                 WHERE ps.id = ? FOR UPDATE',
                [$submissionId]
            );

            if (!$sub) {
                throw new DomainException('Payment submission not found.');
            }
            if (!in_array($sub['status'], ['pending_verification', 'under_review'], true)) {
                throw new DomainException('This payment submission is already ' . $sub['status'] . ' and cannot be verified.');
            }

            // 2. Re-check transaction uniqueness at approval time
            if (!empty($sub['transaction_reference'])) {
                $alreadyVerified = (int) Database::value(
                    "SELECT COUNT(*) FROM payment_submissions
                     WHERE payment_method_id = ? AND transaction_reference = ? AND status = 'verified' AND id != ?",
                    [$sub['payment_method_id'], $sub['transaction_reference'], $submissionId]
                );
                if ($alreadyVerified > 0) {
                    throw new DomainException('This transaction reference has already been verified and settled on another submission.');
                }
            }

            // 3. Lock and inspect invoice
            $inv = InvoiceService::lock((int)$sub['invoice_id']);
            if ($inv['payment_status'] === 'Refunded') {
                throw new DomainException('Invoice was refunded and is closed.');
            }

            // 4. Validate verified amount
            $amountToVerify = ($verifiedAmount !== null && $verifiedAmount !== '') ? $verifiedAmount : (string)$sub['amount'];
            $cents = to_cents($amountToVerify);
            if ($cents <= 0) {
                throw new DomainException('Verified amount must be greater than zero.');
            }
            $balanceCents = to_cents($inv['balance']);
            if ($cents > $balanceCents) {
                throw new DomainException(
                    'The verified amount (' . money(from_cents($cents)) . ') exceeds invoice balance (' . money(from_cents($balanceCents)) . ').'
                );
            }

            $methodToRecord = !empty($sub['snapshot_provider_name']) ? $sub['snapshot_provider_name'] : $sub['method_name'];
            $referenceNote = $sub['transaction_reference'] ?: ('SUB-' . $sub['id']);
            $internalNote = $notes ?: ('Verified from manual submission ' . $sub['submission_number']);

            // 5. Record immutable payment via standard PaymentService
            $paymentId = PaymentService::record(
                (int)$sub['invoice_id'],
                from_cents($cents),
                $methodToRecord,
                $referenceNote,
                $internalNote
            );

            // 6. Update submission status to 'verified'
            Database::run(
                'UPDATE payment_submissions SET
                    status = "verified",
                    payment_id = ?,
                    verified_amount = ?,
                    reviewer_id = ?,
                    reviewed_at = NOW(),
                    review_notes = ?,
                    updated_at = NOW()
                 WHERE id = ?',
                [$paymentId, from_cents($cents), $reviewerId, $notes, $submissionId]
            );

            AuditLogger::log(
                'payment_submission_verified',
                'payment_submissions',
                $submissionId,
                ['status' => $sub['status']],
                [
                    'status' => 'verified',
                    'payment_id' => $paymentId,
                    'verified_amount' => from_cents($cents),
                    'reviewer_id' => $reviewerId,
                    'notes' => $notes
                ]
            );

            return self::getSubmission($submissionId);
        });
    }

    /**
     * Reject a payment submission with an official reason and notes.
     * Atomically frees active transaction reference so the customer can legitimately resubmit.
     * Invoice balance remains untouched.
     */
    public static function rejectPayment(int $submissionId, int $reviewerId, string $rejectionReason, ?string $notes = null): array
    {
        $reason = trim($rejectionReason);
        if ($reason === '') {
            throw new DomainException('A rejection reason is required.');
        }

        return Database::transaction(function () use ($submissionId, $reviewerId, $reason, $notes) {
            $sub = Database::one(
                'SELECT * FROM payment_submissions WHERE id = ? FOR UPDATE',
                [$submissionId]
            );

            if (!$sub) {
                throw new DomainException('Payment submission not found.');
            }
            if (!in_array($sub['status'], ['pending_verification', 'under_review'], true)) {
                throw new DomainException('This submission is already ' . $sub['status'] . ' and cannot be rejected.');
            }

            // Release active reference so the customer may correct or resubmit
            Database::run('DELETE FROM payment_active_references WHERE submission_id = ?', [$submissionId]);

            Database::run(
                'UPDATE payment_submissions SET
                    status = "rejected",
                    rejection_reason = ?,
                    reviewer_id = ?,
                    reviewed_at = NOW(),
                    review_notes = ?,
                    updated_at = NOW()
                 WHERE id = ?',
                [$reason, $reviewerId, $notes, $submissionId]
            );

            AuditLogger::log(
                'payment_submission_rejected',
                'payment_submissions',
                $submissionId,
                ['status' => $sub['status']],
                [
                    'status' => 'rejected',
                    'reason' => $reason,
                    'reviewer_id' => $reviewerId,
                    'notes' => $notes
                ]
            );

            return self::getSubmission($submissionId);
        });
    }

    /**
     * Cash at Reception Reconciliation.
     * Reconciles a customer cash submission with a front-desk payment or confirms physical cash intake.
     * Prevents duplicate double-crediting across reception recording and portal verification.
     */
    public static function reconcileCashPayment(
        int $submissionId,
        int $reviewerId,
        ?int $existingPaymentId = null,
        ?string $receiptNumber = null,
        ?string $notes = null
    ): array {
        return Database::transaction(function () use ($submissionId, $reviewerId, $existingPaymentId, $receiptNumber, $notes) {
            $sub = Database::one('SELECT * FROM payment_submissions WHERE id = ? FOR UPDATE', [$submissionId]);
            if (!$sub) {
                throw new DomainException('Payment submission not found.');
            }
            if (!in_array($sub['status'], ['pending_verification', 'under_review'], true)) {
                throw new DomainException('This submission is already ' . $sub['status'] . ' and cannot be reconciled.');
            }

            $paymentId = $existingPaymentId;
            $verifiedAmount = (string)$sub['amount'];

            if ($paymentId && $paymentId > 0) {
                // Link to payment already recorded by reception staff via record.php
                $existingPayment = Database::one('SELECT * FROM payments WHERE id = ? AND invoice_id = ?', [$paymentId, $sub['invoice_id']]);
                if (!$existingPayment) {
                    throw new DomainException('Specified payment record was not found for this invoice.');
                }
                $verifiedAmount = (string)$existingPayment['amount'];
                // Notice: Invoice balance was already reduced when receptionist recorded it via record.php!
            } else {
                // Staff receptionist directly confirms physical cash in queue
                $inv = InvoiceService::lock((int)$sub['invoice_id']);
                $cents = to_cents($verifiedAmount);
                if ($cents > to_cents($inv['balance'])) {
                    throw new DomainException('Cash amount exceeds remaining invoice balance.');
                }
                $paymentId = PaymentService::record(
                    (int)$sub['invoice_id'],
                    from_cents($cents),
                    'Cash (Reception Desk)',
                    $receiptNumber ?: ('CASH-REC-' . $sub['id']),
                    $notes ?: ('Cash confirmed at reception desk by reviewer ' . $reviewerId)
                );
            }

            Database::run(
                'UPDATE payment_submissions SET
                    status = "verified",
                    payment_id = ?,
                    reconciled_payment_id = ?,
                    verified_amount = ?,
                    reviewer_id = ?,
                    reviewed_at = NOW(),
                    review_notes = ?,
                    updated_at = NOW()
                 WHERE id = ?',
                [$paymentId, $existingPaymentId, $verifiedAmount, $reviewerId, $notes, $submissionId]
            );

            AuditLogger::log(
                'payment_cash_reconciled',
                'payment_submissions',
                $submissionId,
                ['status' => $sub['status']],
                [
                    'status' => 'verified',
                    'payment_id' => $paymentId,
                    'reconciled_payment_id' => $existingPaymentId,
                    'amount' => $verifiedAmount,
                    'reviewer_id' => $reviewerId
                ]
            );

            return self::getSubmission($submissionId);
        });
    }

    // =========================================================================
    // 4. SUBMISSIONS LOOKUP & REPORTING
    // =========================================================================

    /**
     * Get a single submission by ID with joined relations and snapshot overrides.
     */
    public static function getSubmission(int $id): ?array
    {
        $sub = Database::one(
            'SELECT ps.*,
                    i.invoice_number, i.grand_total AS invoice_total, i.balance AS invoice_balance, i.payment_status AS invoice_status,
                    c.full_name AS customer_name, c.email AS customer_email, c.phone AS customer_phone,
                    pm.name AS method_name, pm.name_am AS method_name_am, pm.code AS method_code, pm.type AS method_type,
                    pm.account_name, pm.account_number, pm.phone_number AS method_phone, pm.merchant_number, pm.till_number,
                    u.full_name AS reviewer_name,
                    p.receipt_number
             FROM payment_submissions ps
             JOIN invoices i ON i.id = ps.invoice_id
             JOIN customers c ON c.id = ps.customer_id
             JOIN payment_methods pm ON pm.id = ps.payment_method_id
             LEFT JOIN users u ON u.id = ps.reviewer_id
             LEFT JOIN payments p ON p.id = ps.payment_id
             WHERE ps.id = ?',
            [$id]
        );

        if (!$sub) {
            return null;
        }

        // Apply historical snapshot display attributes
        $sub['display_provider_name'] = !empty($sub['snapshot_provider_name']) ? $sub['snapshot_provider_name'] : $sub['method_name'];
        $sub['display_provider_name_am'] = !empty($sub['snapshot_provider_name_am']) ? $sub['snapshot_provider_name_am'] : ($sub['method_name_am'] ?? $sub['method_name']);
        $sub['display_account_name'] = !empty($sub['snapshot_account_name']) ? $sub['snapshot_account_name'] : ($sub['account_name'] ?? '');
        $sub['display_account_number'] = !empty($sub['snapshot_account_number']) ? $sub['snapshot_account_number'] : ($sub['account_number'] ?? '');
        $sub['display_phone_number'] = !empty($sub['snapshot_phone_number']) ? $sub['snapshot_phone_number'] : ($sub['method_phone'] ?? '');
        $sub['display_instructions'] = !empty($sub['snapshot_instructions']) ? $sub['snapshot_instructions'] : ($sub['instructions'] ?? '');
        $sub['display_instructions_am'] = !empty($sub['snapshot_instructions_am']) ? $sub['snapshot_instructions_am'] : ($sub['instructions_am'] ?? '');

        return $sub;
    }

    /**
     * Search and filter payment submissions.
     */
    public static function findSubmissions(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        [$where, $params] = self::buildFilterWhere($filters);
        $w = implode(' AND ', $where);

        return Database::all(
            "SELECT ps.*,
                    i.invoice_number, i.balance AS invoice_balance,
                    c.full_name AS customer_name, c.phone AS customer_phone,
                    pm.name AS method_name, pm.code AS method_code, pm.type AS method_type,
                    u.full_name AS reviewer_name,
                    p.receipt_number
             FROM payment_submissions ps
             JOIN invoices i ON i.id = ps.invoice_id
             JOIN customers c ON c.id = ps.customer_id
             JOIN payment_methods pm ON pm.id = ps.payment_method_id
             LEFT JOIN users u ON u.id = ps.reviewer_id
             LEFT JOIN payments p ON p.id = ps.payment_id
             WHERE $w
             ORDER BY ps.id DESC
             LIMIT $limit OFFSET $offset",
            $params
        );
    }

    /**
     * Count matching submissions for pagination.
     */
    public static function countSubmissions(array $filters = []): int
    {
        [$where, $params] = self::buildFilterWhere($filters);
        $w = implode(' AND ', $where);
        return (int) Database::value(
            "SELECT COUNT(*) FROM payment_submissions ps
             JOIN invoices i ON i.id = ps.invoice_id
             JOIN customers c ON c.id = ps.customer_id
             JOIN payment_methods pm ON pm.id = ps.payment_method_id
             WHERE $w",
            $params
        );
    }

    /**
     * Get pending submissions count (for badges and alert counters).
     */
    public static function getPendingCount(): int
    {
        return (int) Database::value(
            "SELECT COUNT(*) FROM payment_submissions WHERE status IN ('pending_verification', 'under_review')"
        );
    }

    /**
     * Get summary KPI statistics.
     */
    public static function getSummaryStats(): array
    {
        return [
            'pending_count' => (int) Database::value("SELECT COUNT(*) FROM payment_submissions WHERE status = 'pending_verification'"),
            'pending_amount' => (float) Database::value("SELECT COALESCE(SUM(amount), 0) FROM payment_submissions WHERE status = 'pending_verification'"),
            'under_review_count' => (int) Database::value("SELECT COUNT(*) FROM payment_submissions WHERE status = 'under_review'"),
            'verified_count' => (int) Database::value("SELECT COUNT(*) FROM payment_submissions WHERE status = 'verified'"),
            'verified_amount' => (float) Database::value("SELECT COALESCE(SUM(COALESCE(verified_amount, amount)), 0) FROM payment_submissions WHERE status = 'verified'"),
            'rejected_count' => (int) Database::value("SELECT COUNT(*) FROM payment_submissions WHERE status = 'rejected'"),
        ];
    }

    /**
     * Get collections report aggregated by payment provider.
     */
    public static function getCollectionsByProvider(string $from = '', string $to = ''): array
    {
        $where = ['ps.status = "verified"'];
        $params = [];
        if ($from !== '') {
            $where[] = 'ps.transaction_date >= ?';
            $params[] = $from;
        }
        if ($to !== '') {
            $where[] = 'ps.transaction_date <= ?';
            $params[] = $to;
        }
        $w = implode(' AND ', $where);

        return Database::all(
            "SELECT pm.code, pm.name, pm.type,
                    COUNT(ps.id) AS total_count,
                    COALESCE(SUM(COALESCE(ps.verified_amount, ps.amount)), 0) AS total_amount
             FROM payment_submissions ps
             JOIN payment_methods pm ON pm.id = ps.payment_method_id
             WHERE $w
             GROUP BY pm.id, pm.code, pm.name, pm.type
             ORDER BY total_amount DESC",
            $params
        );
    }

    /**
     * Build WHERE conditions for search/filter queries.
     * @return array{0: list<string>, 1: list<mixed>}
     */
    private static function buildFilterWhere(array $filters): array
    {
        $where = ['1=1'];
        $params = [];

        if (!empty($filters['status'])) {
            $where[] = 'ps.status = ?';
            $params[] = (string)$filters['status'];
        }
        if (!empty($filters['method_id'])) {
            $where[] = 'ps.payment_method_id = ?';
            $params[] = (int)$filters['method_id'];
        }
        if (!empty($filters['customer_id'])) {
            $where[] = 'ps.customer_id = ?';
            $params[] = (int)$filters['customer_id'];
        }
        if (!empty($filters['invoice_id'])) {
            $where[] = 'ps.invoice_id = ?';
            $params[] = (int)$filters['invoice_id'];
        }
        if (!empty($filters['from'])) {
            $where[] = 'ps.transaction_date >= ?';
            $params[] = (string)$filters['from'];
        }
        if (!empty($filters['to'])) {
            $where[] = 'ps.transaction_date <= ?';
            $params[] = (string)$filters['to'];
        }
        if (!empty($filters['search'])) {
            $l = like_escape((string)$filters['search']);
            $where[] = '(ps.submission_number LIKE ? OR ps.transaction_reference LIKE ? OR ps.sender_name LIKE ? OR i.invoice_number LIKE ? OR c.full_name LIKE ?)';
            array_push($params, $l, $l, $l, $l, $l);
        }

        return [$where, $params];
    }

    // =========================================================================
    // 5. STORAGE & PATH HELPERS
    // =========================================================================

    /**
     * Absolute directory path for payment proofs.
     */
    public static function getStorageDir(): string
    {
        $base = getenv('GMS_STORAGE_PATH') ?: (defined('APP_ROOT') ? APP_ROOT . '/storage' : dirname(__DIR__, 2) . '/storage');
        return rtrim($base, '/\\') . '/uploads/payment_proofs';
    }

    /**
     * Absolute path for a proof filename.
     */
    public static function getProofFullPath(string $filename): string
    {
        $cleanName = basename($filename);
        return self::getStorageDir() . '/' . $cleanName;
    }

    /**
     * Accurately detect file MIME type using fileinfo extension if present,
     * or robust binary magic-byte header verification as fallback.
     */
    public static function detectMimeType(string $filePath): string
    {
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $mime = finfo_file($finfo, $filePath);
                finfo_close($finfo);
                if (is_string($mime) && $mime !== '') {
                    return $mime;
                }
            }
        }

        // Magic byte verification fallback
        $handle = @fopen($filePath, 'rb');
        if (!$handle) {
            return 'application/octet-stream';
        }
        $header = fread($handle, 16);
        fclose($handle);

        if (str_starts_with($header, "\xFF\xD8\xFF")) {
            return 'image/jpeg';
        }
        if (str_starts_with($header, "\x89PNG\r\n\x1a\n")) {
            return 'image/png';
        }
        if (str_starts_with($header, 'RIFF') && substr($header, 8, 4) === 'WEBP') {
            return 'image/webp';
        }
        if (str_starts_with($header, '%PDF-')) {
            return 'application/pdf';
        }

        return 'application/octet-stream';
    }

    // =========================================================================
    // 6. CSV FORMULA INJECTION DEFENSE HELPER
    // =========================================================================

    /**
     * Secure CSV cell against formula execution triggers (=, +, -, @, \t, \r).
     * Preserves numeric formatting while neutralising spreadsheet injection.
     */
    public static function sanitizeCsvCell(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }

        $str = (string)$value;
        // Clean out tab, CR, LF to prevent cell layout breakout
        $str = str_replace(["\r", "\n", "\t"], ' ', $str);
        $trimmed = trim($str);

        if ($trimmed !== '') {
            $firstChar = $trimmed[0];
            if (in_array($firstChar, ['=', '+', '-', '@'], true)) {
                // If it looks like a purely positive/negative numeric value, e.g. -12.50 or +50,
                // check if the remainder is entirely numeric.
                if (($firstChar === '-' || $firstChar === '+') && is_numeric($trimmed)) {
                    return $trimmed;
                }
                return "'" . $str;
            }
        }

        return $str;
    }
}
