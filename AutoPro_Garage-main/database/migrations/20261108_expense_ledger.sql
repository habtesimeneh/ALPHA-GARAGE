-- After applying this migration on an existing installation, grant the runtime
-- account SELECT and INSERT on expenses using an authorized database administrator:
-- GRANT SELECT, INSERT ON garage_management_system.expenses TO 'garage_app'@'localhost';
-- Do not rerun least_privilege.sql just to apply this grant; it resets account passwords.

CREATE TABLE IF NOT EXISTS expenses (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  category VARCHAR(40) NOT NULL,
  description VARCHAR(255) NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  expense_date DATE NOT NULL,
  reference VARCHAR(80) NULL,
  recorded_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT chk_expense_amount CHECK (amount > 0),
  CONSTRAINT fk_expense_user FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_expense_date (expense_date),
  INDEX idx_expense_category_date (category, expense_date)
) ENGINE=InnoDB;

INSERT IGNORE INTO permissions (code, module, label) VALUES
  ('expenses.view', 'Expenses', 'View recorded operating expenses'),
  ('expenses.manage', 'Expenses', 'Record operating expenses');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
  SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
  WHERE r.name = 'administrator' AND p.code IN ('expenses.view', 'expenses.manage');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
  SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
  WHERE r.name = 'accountant' AND p.code IN ('expenses.view', 'expenses.manage');
