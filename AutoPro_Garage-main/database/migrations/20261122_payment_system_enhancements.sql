-- =====================================================================
-- ALPHA GARAGE: Priority 13 — Payment Security Remediation & Module Enhancement
-- Migration: 20261122_payment_system_enhancements.sql
-- =====================================================================

-- 1. Enhance payment_methods table (merchant_number, till_number, branch, account_type, icon)
SET @c_merchant := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payment_methods' AND COLUMN_NAME = 'merchant_number');
SET @sql_merchant := IF(@c_merchant = 0, "ALTER TABLE payment_methods ADD COLUMN merchant_number VARCHAR(80) NULL AFTER phone_number", 'SELECT 1');
PREPARE stmt_merchant FROM @sql_merchant;
EXECUTE stmt_merchant;
DEALLOCATE PREPARE stmt_merchant;

SET @c_till := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payment_methods' AND COLUMN_NAME = 'till_number');
SET @sql_till := IF(@c_till = 0, "ALTER TABLE payment_methods ADD COLUMN till_number VARCHAR(80) NULL AFTER merchant_number", 'SELECT 1');
PREPARE stmt_till FROM @sql_till;
EXECUTE stmt_till;
DEALLOCATE PREPARE stmt_till;

SET @c_branch := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payment_methods' AND COLUMN_NAME = 'branch');
SET @sql_branch := IF(@c_branch = 0, "ALTER TABLE payment_methods ADD COLUMN branch VARCHAR(100) NULL AFTER till_number", 'SELECT 1');
PREPARE stmt_branch FROM @sql_branch;
EXECUTE stmt_branch;
DEALLOCATE PREPARE stmt_branch;

SET @c_acctype := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payment_methods' AND COLUMN_NAME = 'account_type');
SET @sql_acctype := IF(@c_acctype = 0, "ALTER TABLE payment_methods ADD COLUMN account_type VARCHAR(50) NULL AFTER branch", 'SELECT 1');
PREPARE stmt_acctype FROM @sql_acctype;
EXECUTE stmt_acctype;
DEALLOCATE PREPARE stmt_acctype;

SET @c_icon := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payment_methods' AND COLUMN_NAME = 'icon');
SET @sql_icon := IF(@c_icon = 0, "ALTER TABLE payment_methods ADD COLUMN icon VARCHAR(50) NULL AFTER display_order", 'SELECT 1');
PREPARE stmt_icon FROM @sql_icon;
EXECUTE stmt_icon;
DEALLOCATE PREPARE stmt_icon;

-- 2. Enhance payment_submissions table (historical snapshots & cash reconciliation)
SET @c_snp_pname := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payment_submissions' AND COLUMN_NAME = 'snapshot_provider_name');
SET @sql_snp_pname := IF(@c_snp_pname = 0, "ALTER TABLE payment_submissions ADD COLUMN snapshot_provider_name VARCHAR(150) NULL AFTER customer_notes", 'SELECT 1');
PREPARE stmt_snp_pname FROM @sql_snp_pname;
EXECUTE stmt_snp_pname;
DEALLOCATE PREPARE stmt_snp_pname;

SET @c_snp_pname_am := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payment_submissions' AND COLUMN_NAME = 'snapshot_provider_name_am');
SET @sql_snp_pname_am := IF(@c_snp_pname_am = 0, "ALTER TABLE payment_submissions ADD COLUMN snapshot_provider_name_am VARCHAR(150) NULL AFTER snapshot_provider_name", 'SELECT 1');
PREPARE stmt_snp_pname_am FROM @sql_snp_pname_am;
EXECUTE stmt_snp_pname_am;
DEALLOCATE PREPARE stmt_snp_pname_am;

SET @c_snp_aname := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payment_submissions' AND COLUMN_NAME = 'snapshot_account_name');
SET @sql_snp_aname := IF(@c_snp_aname = 0, "ALTER TABLE payment_submissions ADD COLUMN snapshot_account_name VARCHAR(150) NULL AFTER snapshot_provider_name_am", 'SELECT 1');
PREPARE stmt_snp_aname FROM @sql_snp_aname;
EXECUTE stmt_snp_aname;
DEALLOCATE PREPARE stmt_snp_aname;

SET @c_snp_anum := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payment_submissions' AND COLUMN_NAME = 'snapshot_account_number');
SET @sql_snp_anum := IF(@c_snp_anum = 0, "ALTER TABLE payment_submissions ADD COLUMN snapshot_account_number VARCHAR(100) NULL AFTER snapshot_account_name", 'SELECT 1');
PREPARE stmt_snp_anum FROM @sql_snp_anum;
EXECUTE stmt_snp_anum;
DEALLOCATE PREPARE stmt_snp_anum;

SET @c_snp_phone := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payment_submissions' AND COLUMN_NAME = 'snapshot_phone_number');
SET @sql_snp_phone := IF(@c_snp_phone = 0, "ALTER TABLE payment_submissions ADD COLUMN snapshot_phone_number VARCHAR(40) NULL AFTER snapshot_account_number", 'SELECT 1');
PREPARE stmt_snp_phone FROM @sql_snp_phone;
EXECUTE stmt_snp_phone;
DEALLOCATE PREPARE stmt_snp_phone;

SET @c_snp_inst := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payment_submissions' AND COLUMN_NAME = 'snapshot_instructions');
SET @sql_snp_inst := IF(@c_snp_inst = 0, "ALTER TABLE payment_submissions ADD COLUMN snapshot_instructions TEXT NULL AFTER snapshot_phone_number", 'SELECT 1');
PREPARE stmt_snp_inst FROM @sql_snp_inst;
EXECUTE stmt_snp_inst;
DEALLOCATE PREPARE stmt_snp_inst;

SET @c_snp_inst_am := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payment_submissions' AND COLUMN_NAME = 'snapshot_instructions_am');
SET @sql_snp_inst_am := IF(@c_snp_inst_am = 0, "ALTER TABLE payment_submissions ADD COLUMN snapshot_instructions_am TEXT NULL AFTER snapshot_instructions", 'SELECT 1');
PREPARE stmt_snp_inst_am FROM @sql_snp_inst_am;
EXECUTE stmt_snp_inst_am;
DEALLOCATE PREPARE stmt_snp_inst_am;

SET @c_rec_pid := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payment_submissions' AND COLUMN_NAME = 'reconciled_payment_id');
SET @sql_rec_pid := IF(@c_rec_pid = 0, "ALTER TABLE payment_submissions ADD COLUMN reconciled_payment_id INT UNSIGNED NULL AFTER reviewer_id", 'SELECT 1');
PREPARE stmt_rec_pid FROM @sql_rec_pid;
EXECUTE stmt_rec_pid;
DEALLOCATE PREPARE stmt_rec_pid;

-- 3. Create payment_active_references table (Atomic duplicate prevention at DB engine level)
CREATE TABLE IF NOT EXISTS payment_active_references (
    payment_method_id INT UNSIGNED NOT NULL,
    transaction_reference VARCHAR(100) NOT NULL,
    submission_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (payment_method_id, transaction_reference),
    INDEX idx_par_sub (submission_id),
    CONSTRAINT fk_par_method FOREIGN KEY (payment_method_id) REFERENCES payment_methods(id) ON DELETE CASCADE,
    CONSTRAINT fk_par_sub FOREIGN KEY (submission_id) REFERENCES payment_submissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Backfill payment_active_references from existing active submissions
INSERT IGNORE INTO payment_active_references (payment_method_id, transaction_reference, submission_id, created_at)
SELECT payment_method_id, transaction_reference, id, created_at
FROM payment_submissions
WHERE status IN ('pending_verification', 'under_review', 'verified')
  AND transaction_reference IS NOT NULL
  AND transaction_reference != '';
