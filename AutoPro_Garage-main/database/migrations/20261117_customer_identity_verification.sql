-- =========================================================================
-- ALPHA GARAGE — PHASE 1.10C: CUSTOMER IDENTITY & ACCOUNT LINKING MIGRATION
-- Remediates FIND-110B-02: Enforces explicit verified account-to-customer linkage.
-- Target Database: garage_management_system_test ONLY
-- Production Database: garage_management_system (MUST REMAIN UNTOUCHED)
-- =========================================================================

-- 1. Ensure user_id column exists on customers table
SET @col_user_id = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='customers' AND COLUMN_NAME='user_id');
SET @sql_user_id = IF(@col_user_id=0, 'ALTER TABLE customers ADD COLUMN user_id INT UNSIGNED NULL AFTER created_by, ADD CONSTRAINT fk_cust_linked_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL, ADD KEY idx_cust_user_id (user_id)', 'SELECT 1');
PREPARE stmt_user_id FROM @sql_user_id;
EXECUTE stmt_user_id;
DEALLOCATE PREPARE stmt_user_id;

-- 2. Link existing self-registered customer accounts (where created_by belongs to a user with role customer)
UPDATE customers c
JOIN users u ON u.id = c.created_by
JOIN roles r ON r.id = u.role_id
SET c.user_id = c.created_by
WHERE c.user_id IS NULL AND r.name = 'customer';

-- 3. Create customer link verification tokens table for secure single-use expiring SHA-256 tokens
CREATE TABLE IF NOT EXISTS customer_link_tokens (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    customer_id INT UNSIGNED NOT NULL,
    email VARCHAR(150) NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_clt_token_hash (token_hash),
    KEY idx_clt_user (user_id),
    KEY idx_clt_customer (customer_id),
    KEY idx_clt_expires (expires_at),
    CONSTRAINT fk_clt_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_clt_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
