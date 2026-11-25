-- =====================================================================
-- Garage Management System  |  database: garage_management_system
-- Import with phpMyAdmin (Import tab) or:  mysql -u root -p < database/database.sql
-- Contains: schema, relationships, append-only audit protection, and
-- initial configuration (roles, permissions, settings).
-- No user accounts are created here: the first administrator is created
-- through public/setup.php (password chosen by you, stored with password_hash).
--
-- DESTRUCTIVE: this file contains CREATE DATABASE and USE garage_management_system
-- and DROPs the listed tables. Importing it with a differently named database on
-- the command line does NOT redirect it: the USE statement wins. For a disposable
-- or test database, strip the CREATE DATABASE/USE lines first (see
-- docs/phase-1.6/PHASE_1_6_EXECUTION_REPORT.md §5).
-- =====================================================================


SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS notifications, appointments, job_services, services, expenses, audit_chain_head, audit_logs,
  login_attempts, payments, invoice_items, invoices, part_requests, job_parts, inventory_transactions,
  spare_parts, suppliers, job_updates, job_cards, vehicles, customers, mechanics, users, role_permissions,
  permissions, roles, garage_settings;
SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------- RBAC
CREATE TABLE roles (
  id TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(40) NOT NULL UNIQUE,
  label VARCHAR(60) NOT NULL,
  description VARCHAR(255) NULL
) ENGINE=InnoDB;

CREATE TABLE permissions (
  id SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(60) NOT NULL UNIQUE,
  module VARCHAR(40) NOT NULL,
  label VARCHAR(120) NOT NULL
) ENGINE=InnoDB;

CREATE TABLE role_permissions (
  role_id TINYINT UNSIGNED NOT NULL,
  permission_id SMALLINT UNSIGNED NOT NULL,
  PRIMARY KEY (role_id, permission_id),
  CONSTRAINT fk_rp_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
  CONSTRAINT fk_rp_perm FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  role_id TINYINT UNSIGNED NOT NULL,
  username VARCHAR(50) NOT NULL UNIQUE,
  full_name VARCHAR(120) NOT NULL,
  email VARCHAR(150) NOT NULL UNIQUE,
  phone VARCHAR(30) NULL,
  password_hash VARCHAR(255) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  must_change_password TINYINT(1) NOT NULL DEFAULT 0,
  last_login_at DATETIME NULL,
  last_login_ip VARCHAR(45) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles(id),
  INDEX idx_users_role (role_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------- people
CREATE TABLE mechanics (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL UNIQUE,
  full_name VARCHAR(120) NOT NULL,
  phone VARCHAR(30) NULL,
  specialty VARCHAR(100) NULL,
  availability ENUM('available','busy','off_duty') NOT NULL DEFAULT 'available',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_mech_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE customers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  full_name VARCHAR(120) NOT NULL,
  phone VARCHAR(30) NOT NULL,
  email VARCHAR(150) NULL,
  address VARCHAR(255) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  registered_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by INT UNSIGNED NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_cust_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_cust_name (full_name),
  INDEX idx_cust_phone (phone)
) ENGINE=InnoDB;

CREATE TABLE vehicles (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id INT UNSIGNED NOT NULL,
  plate_number VARCHAR(20) NOT NULL UNIQUE,
  make VARCHAR(50) NOT NULL,
  model VARCHAR(50) NOT NULL,
  year SMALLINT UNSIGNED NULL,
  color VARCHAR(30) NULL,
  vin VARCHAR(17) NULL UNIQUE,
  mileage INT UNSIGNED NOT NULL DEFAULT 0,
  fuel_type ENUM('Petrol','Diesel','Hybrid','Electric','LPG','Other') NOT NULL DEFAULT 'Petrol',
  vehicle_type VARCHAR(40) NOT NULL DEFAULT 'Sedan',
  notes TEXT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_veh_cust FOREIGN KEY (customer_id) REFERENCES customers(id),
  INDEX idx_veh_customer (customer_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------- jobs
CREATE TABLE job_cards (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  job_number VARCHAR(30) NOT NULL UNIQUE,
  customer_id INT UNSIGNED NOT NULL,
  vehicle_id INT UNSIGNED NOT NULL,
  mechanic_id INT UNSIGNED NULL,
  date_received DATE NOT NULL,
  reported_problem TEXT NOT NULL,
  initial_condition TEXT NULL,
  diagnosis TEXT NULL,
  required_repairs TEXT NULL,
  labor_cost DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  estimated_completion DATE NULL,
  actual_completion DATE NULL,
  mileage_in INT UNSIGNED NULL,
  status ENUM('Pending','Diagnosing','Waiting for Parts','In Progress','Completed','Delivered','Cancelled')
    NOT NULL DEFAULT 'Pending',
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_job_cust FOREIGN KEY (customer_id) REFERENCES customers(id),
  CONSTRAINT fk_job_veh FOREIGN KEY (vehicle_id) REFERENCES vehicles(id),
  CONSTRAINT fk_job_mech FOREIGN KEY (mechanic_id) REFERENCES mechanics(id) ON DELETE SET NULL,
  CONSTRAINT fk_job_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT chk_job_labor CHECK (labor_cost >= 0),
  INDEX idx_job_status (status),
  INDEX idx_job_mech (mechanic_id),
  INDEX idx_job_vehicle (vehicle_id),
  INDEX idx_job_customer (customer_id),
  INDEX idx_job_received (date_received)
) ENGINE=InnoDB;

CREATE TABLE job_updates (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  job_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NULL,
  update_type ENUM('status','note','labor','assignment','parts') NOT NULL,
  old_status VARCHAR(30) NULL,
  new_status VARCHAR(30) NULL,
  note TEXT NULL,
  hours DECIMAL(6,2) NULL,
  amount DECIMAL(12,2) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_ju_job FOREIGN KEY (job_id) REFERENCES job_cards(id) ON DELETE CASCADE,
  CONSTRAINT fk_ju_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_ju_job (job_id, created_at)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------- services & work orders
CREATE TABLE services (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(40) NOT NULL UNIQUE,
  name VARCHAR(150) NOT NULL,
  category VARCHAR(60) NOT NULL,
  description TEXT NULL,
  standard_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  estimated_hours DECIMAL(4,2) NOT NULL DEFAULT 1.00,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_srv_cat (category)
) ENGINE=InnoDB;

CREATE TABLE job_services (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  job_id INT UNSIGNED NOT NULL,
  service_id INT UNSIGNED NULL,
  service_name VARCHAR(150) NOT NULL,
  price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  status ENUM('Pending','In Progress','Completed','Cancelled') NOT NULL DEFAULT 'Pending',
  assigned_mechanic_id INT UNSIGNED NULL,
  notes VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_js_job FOREIGN KEY (job_id) REFERENCES job_cards(id) ON DELETE CASCADE,
  CONSTRAINT fk_js_service FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE SET NULL,
  CONSTRAINT fk_js_mech FOREIGN KEY (assigned_mechanic_id) REFERENCES mechanics(id) ON DELETE SET NULL,
  INDEX idx_js_job (job_id)
) ENGINE=InnoDB;

CREATE TABLE appointments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  appointment_number VARCHAR(30) NOT NULL UNIQUE,
  customer_id INT UNSIGNED NOT NULL,
  vehicle_id INT UNSIGNED NOT NULL,
  service_id INT UNSIGNED NULL,
  appointment_date DATE NOT NULL,
  appointment_time TIME NOT NULL,
  status ENUM('scheduled','confirmed','in_progress','completed','cancelled') NOT NULL DEFAULT 'scheduled',
  problem_description TEXT NULL,
  converted_job_id INT UNSIGNED NULL,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_apt_cust FOREIGN KEY (customer_id) REFERENCES customers(id),
  CONSTRAINT fk_apt_veh FOREIGN KEY (vehicle_id) REFERENCES vehicles(id),
  CONSTRAINT fk_apt_srv FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE SET NULL,
  CONSTRAINT fk_apt_job FOREIGN KEY (converted_job_id) REFERENCES job_cards(id) ON DELETE SET NULL,
  INDEX idx_apt_date (appointment_date),
  INDEX idx_apt_status (status)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------- suppliers & inventory
CREATE TABLE suppliers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(50) NOT NULL UNIQUE,
  name VARCHAR(150) NOT NULL,
  contact_person VARCHAR(120) NULL,
  phone VARCHAR(40) NULL,
  email VARCHAR(150) NULL,
  address VARCHAR(255) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_sup_name (name)
) ENGINE=InnoDB;

CREATE TABLE spare_parts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sku VARCHAR(40) NOT NULL UNIQUE,
  name VARCHAR(150) NOT NULL,
  category VARCHAR(60) NULL,
  unit VARCHAR(20) NOT NULL DEFAULT 'pcs',
  quantity INT NOT NULL DEFAULT 0,
  reorder_level INT NOT NULL DEFAULT 5,
  unit_cost DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  unit_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  supplier VARCHAR(120) NULL,
  supplier_id INT UNSIGNED NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_sp_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL,
  CONSTRAINT chk_part_qty CHECK (quantity >= 0),
  CONSTRAINT chk_part_prices CHECK (unit_cost >= 0 AND unit_price >= 0),
  INDEX idx_part_name (name),
  INDEX idx_part_category (category)
) ENGINE=InnoDB;

CREATE TABLE inventory_transactions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  part_id INT UNSIGNED NOT NULL,
  txn_type ENUM('purchase','issue','return','adjustment_in','adjustment_out') NOT NULL,
  quantity INT UNSIGNED NOT NULL,
  balance_after INT NOT NULL,
  unit_cost DECIMAL(12,2) NULL,
  supplier VARCHAR(120) NULL,
  reference VARCHAR(80) NULL,
  job_card_id INT UNSIGNED NULL,
  notes VARCHAR(255) NULL,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_it_part FOREIGN KEY (part_id) REFERENCES spare_parts(id),
  CONSTRAINT fk_it_job FOREIGN KEY (job_card_id) REFERENCES job_cards(id) ON DELETE SET NULL,
  CONSTRAINT fk_it_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_it_part (part_id, created_at),
  INDEX idx_it_type (txn_type),
  INDEX idx_it_job (job_card_id)
) ENGINE=InnoDB;

CREATE TABLE job_parts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  job_id INT UNSIGNED NOT NULL,
  part_id INT UNSIGNED NOT NULL,
  quantity INT UNSIGNED NOT NULL,
  unit_price DECIMAL(12,2) NOT NULL,
  returned TINYINT(1) NOT NULL DEFAULT 0,
  issued_by INT UNSIGNED NULL,
  issued_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_jp_job FOREIGN KEY (job_id) REFERENCES job_cards(id) ON DELETE CASCADE,
  CONSTRAINT fk_jp_part FOREIGN KEY (part_id) REFERENCES spare_parts(id),
  CONSTRAINT fk_jp_user FOREIGN KEY (issued_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT chk_jp_qty CHECK (quantity > 0),
  INDEX idx_jp_job (job_id)
) ENGINE=InnoDB;

CREATE TABLE part_requests (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  job_id INT UNSIGNED NOT NULL,
  part_id INT UNSIGNED NOT NULL,
  quantity INT UNSIGNED NOT NULL,
  note VARCHAR(255) NULL,
  status ENUM('pending','issued','rejected') NOT NULL DEFAULT 'pending',
  requested_by INT UNSIGNED NULL,
  handled_by INT UNSIGNED NULL,
  handled_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_pr_job FOREIGN KEY (job_id) REFERENCES job_cards(id) ON DELETE CASCADE,
  CONSTRAINT fk_pr_part FOREIGN KEY (part_id) REFERENCES spare_parts(id),
  CONSTRAINT fk_pr_req FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_pr_hand FOREIGN KEY (handled_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT chk_pr_qty CHECK (quantity > 0),
  INDEX idx_pr_status (status)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------- billing
CREATE TABLE invoices (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  invoice_number VARCHAR(30) NOT NULL UNIQUE,
  job_card_id INT UNSIGNED NOT NULL UNIQUE,
  customer_id INT UNSIGNED NOT NULL,
  vehicle_id INT UNSIGNED NOT NULL,
  issue_date DATE NOT NULL,
  due_date DATE NULL,
  labor_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  parts_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  subtotal DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  discount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  tax_rate DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  tax_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  grand_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  amount_paid DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  balance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  payment_status ENUM('Unpaid','Partially Paid','Paid','Refunded') NOT NULL DEFAULT 'Unpaid',
  notes VARCHAR(500) NULL,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_inv_job FOREIGN KEY (job_card_id) REFERENCES job_cards(id),
  CONSTRAINT fk_inv_cust FOREIGN KEY (customer_id) REFERENCES customers(id),
  CONSTRAINT fk_inv_veh FOREIGN KEY (vehicle_id) REFERENCES vehicles(id),
  CONSTRAINT fk_inv_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT chk_inv_money CHECK (discount >= 0 AND grand_total >= 0),
  INDEX idx_inv_status (payment_status),
  INDEX idx_inv_customer (customer_id),
  INDEX idx_inv_date (issue_date)
) ENGINE=InnoDB;

CREATE TABLE invoice_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  invoice_id INT UNSIGNED NOT NULL,
  item_type ENUM('labor','part') NOT NULL,
  part_id INT UNSIGNED NULL,
  description VARCHAR(255) NOT NULL,
  quantity DECIMAL(10,2) NOT NULL DEFAULT 1,
  unit_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  line_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  CONSTRAINT fk_ii_inv FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
  CONSTRAINT fk_ii_part FOREIGN KEY (part_id) REFERENCES spare_parts(id) ON DELETE SET NULL,
  INDEX idx_ii_invoice (invoice_id)
) ENGINE=InnoDB;

CREATE TABLE payments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  receipt_number VARCHAR(30) NOT NULL UNIQUE,
  invoice_id INT UNSIGNED NOT NULL,
  kind ENUM('payment','refund') NOT NULL DEFAULT 'payment',
  amount DECIMAL(12,2) NOT NULL,
  method VARCHAR(40) NOT NULL,
  reference VARCHAR(80) NULL,
  paid_at DATETIME NOT NULL,
  notes VARCHAR(255) NULL,
  recorded_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_pay_inv FOREIGN KEY (invoice_id) REFERENCES invoices(id),
  CONSTRAINT fk_pay_user FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT chk_pay_amount CHECK (amount > 0),
  INDEX idx_pay_invoice (invoice_id),
  INDEX idx_pay_date (paid_at)
) ENGINE=InnoDB;

CREATE TABLE expenses (
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

-- ---------------------------------------------------------------- security
CREATE TABLE login_attempts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(150) NOT NULL,
  ip_address VARCHAR(45) NOT NULL,
  success TINYINT(1) NOT NULL DEFAULT 0,
  attempted_at DATETIME NOT NULL,
  INDEX idx_la_user (username, attempted_at),
  INDEX idx_la_ip (ip_address, attempted_at)
) ENGINE=InnoDB;

-- Tamper-evident audit trail: every row stores the SHA-256 of its own content
-- chained to the previous row's hash (see app/services/AuditLogger.php).
CREATE TABLE audit_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  username VARCHAR(50) NULL,
  user_role VARCHAR(40) NULL,
  action_type VARCHAR(60) NOT NULL,
  module_name VARCHAR(40) NOT NULL,
  record_id BIGINT UNSIGNED NULL,
  previous_value MEDIUMTEXT NULL,
  new_value MEDIUMTEXT NULL,
  ip_address VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  action_result ENUM('success','failure','denied') NOT NULL DEFAULT 'success',
  created_at DATETIME NOT NULL,
  prev_hash CHAR(64) NOT NULL,
  row_hash CHAR(64) NOT NULL,
  INDEX idx_al_user (user_id),
  INDEX idx_al_action (action_type),
  INDEX idx_al_module (module_name, record_id),
  INDEX idx_al_created (created_at)
) ENGINE=InnoDB;

-- Single-row table used to serialise hash-chain appends (SELECT ... FOR UPDATE).
CREATE TABLE audit_chain_head (
  id TINYINT UNSIGNED PRIMARY KEY,
  last_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  last_hash CHAR(64) NOT NULL
) ENGINE=InnoDB;
INSERT INTO audit_chain_head (id, last_id, last_hash) VALUES (1, 0, REPEAT('0', 64));

-- Append-only enforcement at database level (works even for the root user).
DROP TRIGGER IF EXISTS trg_audit_no_update;
DROP TRIGGER IF EXISTS trg_audit_no_delete;
CREATE TRIGGER trg_audit_no_update BEFORE UPDATE ON audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs is append-only: UPDATE is not allowed';
CREATE TRIGGER trg_audit_no_delete BEFORE DELETE ON audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs is append-only: DELETE is not allowed';

CREATE TABLE notifications (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  role_name VARCHAR(40) NULL,
  title VARCHAR(150) NOT NULL,
  message VARCHAR(500) NOT NULL,
  link VARCHAR(255) NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_notif_user (user_id, is_read),
  INDEX idx_notif_role (role_name, is_read)
) ENGINE=InnoDB;

CREATE TABLE password_resets (
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

CREATE TABLE garage_settings (
  setting_key VARCHAR(60) PRIMARY KEY,
  setting_value TEXT NOT NULL,
  updated_by INT UNSIGNED NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ================================================================ INITIAL DATA
INSERT INTO roles (name, label, description) VALUES
 ('administrator','Administrator','Full system access, user management, audit logs'),
 ('receptionist','Receptionist','Customers, vehicles, job cards, invoices, payments'),
 ('mechanic','Mechanic','Works on assigned job cards'),
 ('inventory_manager','Inventory Manager','Spare parts, stock and issuing'),
 ('accountant','Accountant','Invoices, payments and financial reports'),
 ('customer','Customer','Public website account without staff dashboard access');

INSERT INTO permissions (code, module, label) VALUES
 ('dashboard.view','Dashboard','View dashboard'),
 ('customers.view','Customers','View customers'),
 ('customers.manage','Customers','Create and edit customers'),
 ('customers.deactivate','Customers','Deactivate / reactivate customers'),
 ('vehicles.view','Vehicles','View vehicles'),
 ('vehicles.manage','Vehicles','Create and edit vehicles'),
 ('jobs.view_all','Job cards','View all job cards'),
 ('jobs.view_assigned','Job cards','View only job cards assigned to me'),
 ('jobs.create','Job cards','Create job cards'),
 ('jobs.edit','Job cards','Edit job card details and cancel jobs'),
 ('jobs.assign','Job cards','Assign mechanics'),
 ('jobs.progress','Job cards','Update status, notes and labor'),
 ('jobs.request_parts','Job cards','Request spare parts'),
 ('mechanics.view','Mechanics','View mechanics'),
 ('mechanics.manage','Mechanics','Edit mechanic profiles'),
 ('inventory.view','Inventory','View spare parts'),
 ('inventory.manage','Inventory','Create and edit spare parts'),
 ('inventory.stock','Inventory','Record purchases and stock adjustments'),
 ('inventory.issue','Inventory','Issue parts to job cards'),
 ('invoices.view','Invoices','View invoices'),
 ('invoices.create','Invoices','Generate invoices'),
 ('invoices.edit','Invoices','Edit invoice discount / sync from job'),
 ('payments.view','Payments','View payments'),
 ('payments.record','Payments','Record payments'),
 ('payments.refund','Payments','Authorise refunds'),
 ('expenses.view','Expenses','View recorded operating expenses'),
 ('expenses.manage','Expenses','Record operating expenses'),
 ('reports.view','Reports','Operational reports'),
 ('reports.financial','Reports','Financial reports'),
 ('reports.inventory','Reports','Inventory reports'),
 ('services.view','Services','View service catalog'),
 ('services.manage','Services','Create and edit garage services'),
 ('suppliers.view','Suppliers','View suppliers'),
 ('suppliers.manage','Suppliers','Create and edit suppliers'),
 ('appointments.view','Appointments','View service appointments'),
 ('appointments.manage','Appointments','Create, update and convert appointments'),
 ('users.manage','Administration','Manage users'),
 ('roles.manage','Administration','Manage role permissions'),
 ('settings.manage','Administration','Manage garage settings'),
 ('audit.view','Administration','View and export audit logs');

INSERT INTO role_permissions (role_id, permission_id)
 SELECT r.id, p.id FROM roles r CROSS JOIN permissions p WHERE r.name = 'administrator';
INSERT INTO role_permissions (role_id, permission_id)
 SELECT r.id, p.id FROM roles r JOIN permissions p ON p.code IN
 ('dashboard.view','customers.view','customers.manage','vehicles.view','vehicles.manage','jobs.view_all','jobs.create',
  'jobs.edit','jobs.assign','mechanics.view','invoices.view','invoices.create','invoices.edit','payments.view',
  'payments.record','reports.view','services.view','appointments.view','appointments.manage','suppliers.view') WHERE r.name = 'receptionist';
INSERT INTO role_permissions (role_id, permission_id)
 SELECT r.id, p.id FROM roles r JOIN permissions p ON p.code IN
 ('dashboard.view','vehicles.view','jobs.view_assigned','jobs.progress','jobs.request_parts','inventory.view','services.view')
 WHERE r.name = 'mechanic';
INSERT INTO role_permissions (role_id, permission_id)
 SELECT r.id, p.id FROM roles r JOIN permissions p ON p.code IN
 ('dashboard.view','inventory.view','inventory.manage','inventory.stock','inventory.issue','jobs.view_all',
  'reports.inventory','suppliers.view','suppliers.manage') WHERE r.name = 'inventory_manager';
INSERT INTO role_permissions (role_id, permission_id)
 SELECT r.id, p.id FROM roles r JOIN permissions p ON p.code IN
 ('dashboard.view','customers.view','invoices.view','payments.view','payments.record','reports.view',
  'reports.financial','expenses.view','expenses.manage') WHERE r.name = 'accountant';
INSERT INTO garage_settings (setting_key, setting_value) VALUES
 ('garage_name','Alpha Garage'),
 ('garage_address','Bole Road, Addis Ababa, Ethiopia'),
 ('garage_phone','+251 11 000 0000'),
 ('garage_email','info@autopro-garage.example'),
 ('currency','ETB'),
 ('tax_rate','15'),
 ('labor_hourly_rate','350'),
 ('invoice_due_days','14'),
 ('payment_methods','Cash,Bank Transfer,Telebirr,Chapa,Card'),
 ('invoice_footer','Thank you for choosing us. Parts are covered by the supplier warranty; labor is guaranteed for 30 days.');

-- Standard Garage Services
INSERT INTO services (code, name, category, description, standard_price, estimated_hours) VALUES
 ('SRV-ENG-001', 'Full Engine Diagnostic Scan', 'Diagnostics', 'Comprehensive OBD-II diagnostics, sensor check and report', 750.00, 1.00),
 ('SRV-MAINT-001', 'Complete Oil & Filter Service', 'Maintenance', 'Engine oil drain & refill with premium synthetic oil and OEM filter', 1200.00, 1.00),
 ('SRV-BRK-001', 'Front Brake Pad Replacement & Rotor Check', 'Brakes', 'Inspect rotors and replace front disc brake pads', 950.00, 1.50),
 ('SRV-BRK-002', 'Brake Fluid Flush & Bleed', 'Brakes', 'Complete brake hydraulic fluid flush and pressure bleed', 600.00, 1.00),
 ('SRV-SUSP-001', 'Wheel Alignment & Balancing (4 Wheels)', 'Suspension', 'Computerized 4-wheel laser alignment and dynamic balancing', 850.00, 1.50),
 ('SRV-AC-001', 'Air Conditioning Re-gas & Leak Test', 'AC & Heating', 'R134a evacuation, nitrogen leak test and precision refrigerant recharge', 1100.00, 1.25),
 ('SRV-TRN-001', 'Automatic Transmission Fluid Service', 'Transmission', 'Transmission fluid flush, pan gasket replacement and road test', 1800.00, 2.00),
 ('SRV-ELE-001', 'Battery & Charging System Health Test', 'Electrical', 'Battery conductance load test, alternator and starter health test', 450.00, 0.75);

-- Initial Automotive Suppliers
INSERT INTO suppliers (code, name, contact_person, phone, email, address) VALUES
 ('SUP-BOSCH', 'Bosch Auto Parts Ethiopia', 'Mulugeta Tadesse', '+251 11 661 2233', 'sales@bosch-eth.example', 'Bole Subcity, Addis Ababa'),
 ('SUP-DENSO', 'Denso Filtration & Electronics', 'Hiwot Bekele', '+251 11 552 4411', 'info@denso-eth.example', 'Kality Industrial Zone, Addis Ababa'),
 ('SUP-BREMBO', 'Brembo Brake Systems Direct', 'Yonas Alemu', '+251 11 440 9988', 'support@brembo-direct.example', 'Akaki Kality, Addis Ababa'),
 ('SUP-SHELL', 'National Lubricants & Oils Supply', 'Dawit Kebede', '+251 11 278 1122', 'orders@natlubricants.example', 'Mexico Square, Addis Ababa');


-- ---------------------------------------------------------------- Contact Inquiries
CREATE TABLE IF NOT EXISTS contact_inquiries (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(150) NOT NULL,
  phone VARCHAR(40) NULL,
  channel VARCHAR(40) NOT NULL DEFAULT 'Web Contact Form',
  subject VARCHAR(150) NOT NULL,
  message TEXT NOT NULL,
  ip_address VARCHAR(45) NULL,
  status ENUM('new','in_progress','resolved') NOT NULL DEFAULT 'new',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
