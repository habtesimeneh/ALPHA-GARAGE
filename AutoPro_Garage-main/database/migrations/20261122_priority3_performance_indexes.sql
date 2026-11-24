-- =====================================================================
-- ALPHA GARAGE — PRIORITY 3 DATABASE PERFORMANCE INDEXES MIGRATION
-- Migration File: 20261122_priority3_performance_indexes.sql
-- Description: Composite index optimizations for high-traffic query paths
--              and pruning of redundant single-column index.
-- Engine: InnoDB
-- Charset: utf8mb4_unicode_ci
-- =====================================================================

-- ---------------------------------------------------------------------
-- UP: Apply Performance Indexes
-- ---------------------------------------------------------------------

-- 1. Appointments: Optimize receptionist upcoming count and customer appointment ranges
CREATE INDEX idx_apt_status_date ON appointments (status, appointment_date);
CREATE INDEX idx_apt_cust_date_status ON appointments (customer_id, appointment_date, status);

-- 2. Job Cards: Optimize mechanic workstation queue and customer repair status
CREATE INDEX idx_job_mech_status ON job_cards (mechanic_id, status);
CREATE INDEX idx_job_cust_status ON job_cards (customer_id, status);

-- 3. Invoices: Optimize customer billing lookup and outstanding balance calculation
CREATE INDEX idx_inv_cust_status ON invoices (customer_id, payment_status, balance);

-- 4. Vehicles: Optimize customer vehicle list and intake count
CREATE INDEX idx_veh_cust_active ON vehicles (customer_id, is_active);

-- 5. Inventory Transactions: Optimize purchase expense aggregation
CREATE INDEX idx_it_type_created ON inventory_transactions (txn_type, created_at);

-- 6. Part Requests: Optimize correlated pending parts lookup on active jobs
CREATE INDEX idx_pr_job_status ON part_requests (job_id, status);

-- 7. Prune redundant index on password_resets (unique token_hash already indexes token_hash)
ALTER TABLE password_resets DROP INDEX idx_pr_hash;

-- ---------------------------------------------------------------------
-- DOWN: Reversible Rollback Script
-- ---------------------------------------------------------------------
-- To roll back this migration, execute:
--
-- ALTER TABLE appointments DROP INDEX idx_apt_status_date;
-- CREATE INDEX fk_apt_cust ON appointments (customer_id);
-- ALTER TABLE appointments DROP INDEX idx_apt_cust_date_status;
-- ALTER TABLE job_cards DROP INDEX idx_job_mech_status;
-- ALTER TABLE job_cards DROP INDEX idx_job_cust_status;
-- ALTER TABLE invoices DROP INDEX idx_inv_cust_status;
-- ALTER TABLE vehicles DROP INDEX idx_veh_cust_active;
-- ALTER TABLE inventory_transactions DROP INDEX idx_it_type_created;
-- CREATE INDEX fk_pr_job ON part_requests (job_id);
-- ALTER TABLE part_requests DROP INDEX idx_pr_job_status;
-- CREATE INDEX idx_pr_hash ON password_resets (token_hash);
-- DELETE FROM schema_migrations WHERE migration = '20261122_priority3_performance_indexes.sql';
