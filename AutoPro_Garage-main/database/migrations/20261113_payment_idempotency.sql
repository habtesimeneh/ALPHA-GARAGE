-- =========================================================================
-- ALPHA GARAGE — PHASE 1.9E: PAYMENT IDEMPOTENCY MIGRATION
-- Target Table: payment_idempotency_keys
-- Enforces atomic duplicate payment prevention via database uniqueness
-- =========================================================================

CREATE TABLE IF NOT EXISTS payment_idempotency_keys (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    idempotency_key VARCHAR(64) NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    invoice_id INT UNSIGNED NOT NULL,
    operation_type ENUM('payment', 'refund') NOT NULL DEFAULT 'payment',
    amount DECIMAL(12,2) NOT NULL,
    request_hash VARCHAR(64) NOT NULL,
    status ENUM('pending', 'completed', 'failed') NOT NULL DEFAULT 'pending',
    payment_id INT UNSIGNED NULL,
    response_payload TEXT NULL,
    error_message VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_idempotency_key (idempotency_key),
    KEY idx_user_id (user_id),
    KEY idx_invoice_id (invoice_id),
    KEY idx_status (status),
    KEY idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
