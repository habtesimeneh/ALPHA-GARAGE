-- Run as a database administrator after creating the application database.
-- Replace both placeholders with different, high-entropy secrets before execution.
-- The web runtime uses garage_app. Only the CLI migration command uses garage_migrator.
-- Never commit this file with real secrets; local.php (git-ignored) holds the runtime secret.

USE garage_management_system;

CREATE USER IF NOT EXISTS 'garage_app'@'localhost';
ALTER USER 'garage_app'@'localhost' IDENTIFIED BY 'REPLACE_WITH_NEW_RANDOM_APP_SECRET';
CREATE USER IF NOT EXISTS 'garage_migrator'@'localhost';
ALTER USER 'garage_migrator'@'localhost' IDENTIFIED BY 'REPLACE_WITH_DIFFERENT_RANDOM_MIGRATION_SECRET';

REVOKE ALL PRIVILEGES, GRANT OPTION FROM 'garage_app'@'localhost';
REVOKE ALL PRIVILEGES, GRANT OPTION FROM 'garage_migrator'@'localhost';

-- Runtime account: narrow, per-table privileges. No GRANT OPTION.
GRANT SELECT, INSERT, UPDATE, DELETE ON garage_management_system.users TO 'garage_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON garage_management_system.roles TO 'garage_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON garage_management_system.permissions TO 'garage_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON garage_management_system.role_permissions TO 'garage_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON garage_management_system.mechanics TO 'garage_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON garage_management_system.customers TO 'garage_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON garage_management_system.vehicles TO 'garage_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON garage_management_system.job_cards TO 'garage_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON garage_management_system.job_updates TO 'garage_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON garage_management_system.services TO 'garage_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON garage_management_system.job_services TO 'garage_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON garage_management_system.appointments TO 'garage_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON garage_management_system.suppliers TO 'garage_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON garage_management_system.spare_parts TO 'garage_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON garage_management_system.inventory_transactions TO 'garage_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON garage_management_system.job_parts TO 'garage_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON garage_management_system.part_requests TO 'garage_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON garage_management_system.invoices TO 'garage_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON garage_management_system.invoice_items TO 'garage_app'@'localhost';
-- Expense entries are append-only in the application; runtime cannot update or delete them.
GRANT SELECT, INSERT ON garage_management_system.expenses TO 'garage_app'@'localhost';
-- payments: INSERT for new payments/refunds; UPDATE only on receipt_number (generated after insert).
-- All financial columns are engine-level immutable via trg_payments_immutable (below).
GRANT SELECT, INSERT ON garage_management_system.payments TO 'garage_app'@'localhost';
GRANT UPDATE (receipt_number) ON garage_management_system.payments TO 'garage_app'@'localhost';
GRANT SELECT, INSERT, DELETE ON garage_management_system.login_attempts TO 'garage_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON garage_management_system.notifications TO 'garage_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON garage_management_system.password_resets TO 'garage_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON garage_management_system.garage_settings TO 'garage_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON garage_management_system.videos TO 'garage_app'@'localhost';
-- Customer link tokens: created, looked up and soft-invalidated (used_at = NOW()). DELETE is denied to preserve token audit history.
GRANT SELECT, INSERT, UPDATE ON garage_management_system.customer_link_tokens TO 'garage_app'@'localhost';
-- Payment idempotency keys: atomic lock acquisition, lookup, completion and failure updates. DELETE is denied to prevent replaying idempotency locks.
GRANT SELECT, INSERT, UPDATE ON garage_management_system.payment_idempotency_keys TO 'garage_app'@'localhost';
-- Manual Payment System: payment methods configuration and customer verification submissions
GRANT SELECT, INSERT, UPDATE ON garage_management_system.payment_methods TO 'garage_app'@'localhost';
GRANT SELECT, INSERT, UPDATE ON garage_management_system.payment_submissions TO 'garage_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON garage_management_system.payment_active_references TO 'garage_app'@'localhost';
GRANT SELECT, INSERT ON garage_management_system.contact_inquiries TO 'garage_app'@'localhost';
-- audit_logs: append-only (SELECT, INSERT). UPDATE/DELETE are blocked by triggers in database.sql.
GRANT SELECT, INSERT ON garage_management_system.audit_logs TO 'garage_app'@'localhost';
-- audit_chain_head: Serialises hash-chain appends (SELECT ... FOR UPDATE + UPDATE last_id/last_hash).
GRANT SELECT, UPDATE ON garage_management_system.audit_chain_head TO 'garage_app'@'localhost';

-- Payment immutability at the engine level. Mirrors the audit_logs append-only triggers.
-- Only receipt_number may change after insert; every financial/metadata column is frozen.
DROP TRIGGER IF EXISTS trg_payments_immutable;
DELIMITER $$
CREATE TRIGGER trg_payments_immutable BEFORE UPDATE ON payments
FOR EACH ROW
BEGIN
    IF NOT (
        OLD.amount <=> NEW.amount AND OLD.kind <=> NEW.kind AND OLD.invoice_id <=> NEW.invoice_id
        AND OLD.method <=> NEW.method AND OLD.reference <=> NEW.reference AND OLD.paid_at <=> NEW.paid_at
        AND OLD.notes <=> NEW.notes AND OLD.recorded_by <=> NEW.recorded_by
    ) THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'payments are immutable: only receipt_number may be set after insert';
    END IF;
END $$
DELIMITER ;

-- Migrations are executed manually through database/migrate.php from the CLI.
-- This account is not configured in the web runtime.
GRANT ALL PRIVILEGES ON garage_management_system.* TO 'garage_migrator'@'localhost';

FLUSH PRIVILEGES;
