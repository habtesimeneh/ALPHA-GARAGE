# ALPHA GARAGE — PHASE 1.9F: IMPLEMENTATION PLAN
## Payment Idempotency Concurrency & Crash-Recovery Hardening

**Document Version:** 1.0.0  
**Phase:** 1.9F  
**Target Environment:** Windows + MAMP (PHP 8.3.1, MySQL 5.7 / MariaDB, Apache)  
**Target Test Database:** `garage_management_system_test`  
**Production Database Status:** `garage_management_system` (Strictly Read-Only / Untouched)  
**Baseline Git Commit:** `b4e2a54`  

---

## 1. Executive Summary & Remediation Scope

Based on the independent security audit conducted in `docs/phase-1.9/PHASE_1_9E_FINAL_SECURITY_REVIEW.md`, Phase 1.9F hardens the server-side payment idempotency mechanism. The enhancements eliminate race conditions during failed-key retry reclamation, eliminate crash windows between financial transaction commit and idempotency completion, prevent late-arriving errors from corrupting completed records, expand request fingerprinting to include notes and operational metadata, and formalize retention and key-storage security policies.

---

## 2. Hardening Objectives & Design Architecture

### SEC-IDEM-01: Atomic Failed-Key Reclamation
- **Vulnerability Identified in 1.9E:** If two retries arrived simultaneously for a previously failed idempotency key, both could pass an initial `SELECT` check and attempt to reuse the key without atomic serialization.
- **Hardened Architecture:** Implemented atomic conditional update:
  ```sql
  UPDATE payment_idempotency_keys
  SET status = 'pending',
      error_message = NULL,
      updated_at = NOW()
  WHERE idempotency_key = ? AND status = 'failed'
  ```
- **Concurrency Control:** Evaluates MySQL `rowCount()`. Exactly one concurrent worker transitions the row and receives `['status' => 'new']`. Any competing concurrent request observes `rowCount() === 0` and falls into the polling synchronization loop.
- **Ownership Verification:** Before initiating the conditional transition, user ownership (`user_id`) and payload hash identity (`request_hash`) are validated against the stored record.

### SEC-IDEM-02: Elimination of the Reservation-to-Completion Crash Window
- **Vulnerability Identified in 1.9E:** Previously, `PaymentIdempotencyService::complete()` was called *after* `Database::transaction(...)` closed. A process termination or network failure between the commit of the payment and the update of `payment_idempotency_keys` would leave an orphaned `pending` key with a committed payment.
- **Hardened Architecture:** Moved `PaymentIdempotencyService::complete()` *inside* the financial `Database::transaction(...)` closure in both `PaymentService::record` and `PaymentService::refund`.
- **Microsecond Redo Log Atomicity:**
  - `payments` row insertion,
  - `invoices` balance and status recalculation,
  - `audit_logs` record insertion and Merkle hash link,
  - `payment_idempotency_keys` status transition to `completed` and `payment_id` assignment
  all commit in the exact same InnoDB transaction and redo log write.
- **Crash Recovery Guarantees:**
  - If a worker dies before commit: InnoDB automatically rolls back all table mutations. The idempotency key remains `pending` and can be cleanly marked `failed` and retried. Zero money is moved and zero duplicate payments occur.
  - If a worker dies after commit: Both payment and idempotency completion are committed. Any retry receives the cached payment result immediately without re-executing financial logic.

### SEC-IDEM-03: Guarded Failed-State Transitions
- **Vulnerability Identified in 1.9E:** A late network timeout or background failure handler could invoke `PaymentIdempotencyService::fail()` after the financial transaction had already committed.
- **Hardened Architecture:** Conditional update enforcing `status = 'pending'`:
  ```sql
  UPDATE payment_idempotency_keys
  SET status = 'failed',
      error_message = SUBSTRING(?, 1, 255),
      updated_at = NOW()
  WHERE idempotency_key = ? AND status = 'pending'
  ```
  If the status has already transitioned to `completed`, `rowCount()` is 0, completely preserving the completed transaction record.

### SEC-IDEM-04: Complete Request Fingerprint Integrity
- **Enhancement:** Extended `PaymentIdempotencyService::computeHash()` and `PaymentIdempotencyService::acquire()` to include normalized `notes` (for payments) and `reason` (for refunds):
  ```php
  $canonical = implode('|', [
      $userId,
      $invoiceId,
      $operationType,
      $normalizedAmount,
      trim($method),
      trim($reference),
      trim($notes),
  ]);
  return hash('sha256', $canonical);
  ```
- **Protection:** Prevents memo/note tampering, ensures identical requests produce identical hashes, and rejects modified administrative notes under an existing key as `409 Conflict`.

### SEC-IDEM-05 & SEC-IDEM-06: Key Storage & Retention Architecture
- **Raw Key vs Digest Storage:** Analysis confirms the current 64-character hex key (`bin2hex(random_bytes(24))`) stored in indexed column `idempotency_key` is computationally irreversible and cryptographically random (192 bits of entropy). Storing raw random tokens in an internal relational table with restrictive RBAC provides optimal index performance.
- **Retention Policy:** Idempotency keys should be retained for 90 days to cover standard financial reconciliation periods, after which keys in `completed` or `failed` status older than 90 days may be archived. Keys in `pending` status older than 24 hours should be reconciled before archiving.

---

## 3. Implementation Verification Matrix

| Component | Target File | Verification Technique |
| :--- | :--- | :--- |
| Failed-Key Reclamation | `app/services/PaymentIdempotencyService.php` | Concurrency test with 2 simultaneous retries (`F-IDEM-01`) |
| Commit Atomicity | `app/services/PaymentService.php` | Transaction inspection & post-commit replay (`F-IDEM-05`) |
| Guarded Fail Transition | `app/services/PaymentIdempotencyService.php` | Late fail attempt against completed key (`F-IDEM-07`) |
| Fingerprint Completeness | `app/services/PaymentIdempotencyService.php` | Note tampering conflict test (`F-IDEM-08`) |
| Multi-socket Concurrency | `tests/payment_idempotency_1_9f_hardening_suite.php` | High-concurrency curl_multi benchmark on port 8089 |
