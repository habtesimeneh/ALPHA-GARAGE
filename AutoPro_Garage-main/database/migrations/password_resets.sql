-- =====================================================================
-- AutoPro Garage Management System — Password Recovery & Client Roles
-- =====================================================================

-- 1. Ensure phone column exists on users
SET @col_phone = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='phone');
SET @sql_phone = IF(@col_phone=0, 'ALTER TABLE users ADD COLUMN phone VARCHAR(30) NULL AFTER email', 'SELECT 1');
PREPARE stmt_phone FROM @sql_phone;
EXECUTE stmt_phone;
DEALLOCATE PREPARE stmt_phone;

-- 2. Password resets table for secure self-service recovery
CREATE TABLE IF NOT EXISTS password_resets (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_pr_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_pr_user (user_id),
  INDEX idx_pr_hash (token_hash),
  INDEX idx_pr_expires (expires_at)
) ENGINE=InnoDB;

-- 3. Ensure customer role exists for self-registered clients
INSERT IGNORE INTO roles (name, label, description) VALUES
  ('customer', 'Customer', 'Vehicle owner with access to service booking and repair history');

-- 4. Grant self-service permissions to customer role
INSERT IGNORE INTO role_permissions (role_id, permission_id)
  SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
  WHERE r.name = 'customer' AND p.code IN ('dashboard.view', 'vehicles.view', 'appointments.view', 'appointments.manage');
