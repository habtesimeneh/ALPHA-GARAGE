-- Backward-compatible addition for New Job Card priority (requested UX field).
-- Safe to run multiple times on MySQL 8+/MariaDB with INFORMATION_SCHEMA guard.
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'job_cards' AND COLUMN_NAME = 'priority');
SET @sql := IF(@col = 0, "ALTER TABLE job_cards ADD COLUMN priority ENUM('Low','Normal','High','Urgent') NOT NULL DEFAULT 'Normal' AFTER status", 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
