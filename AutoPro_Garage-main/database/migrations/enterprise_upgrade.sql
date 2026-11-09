-- =====================================================================
-- AutoPro Garage Management System — Enterprise Upgrade Migration
-- Adds: Services Catalog, Job Services, Suppliers, Appointments, Notifications
-- =====================================================================

-- 0. Upgrade existing vehicles table with vehicle_type and notes if not present
SET @col_vtype = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='vehicles' AND COLUMN_NAME='vehicle_type');
SET @sql_vtype = IF(@col_vtype=0, 'ALTER TABLE vehicles ADD COLUMN vehicle_type VARCHAR(40) NOT NULL DEFAULT "Sedan" AFTER fuel_type', 'SELECT 1');
PREPARE stmt_vtype FROM @sql_vtype;
EXECUTE stmt_vtype;
DEALLOCATE PREPARE stmt_vtype;

SET @col_notes = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='vehicles' AND COLUMN_NAME='notes');
SET @sql_notes = IF(@col_notes=0, 'ALTER TABLE vehicles ADD COLUMN notes TEXT NULL AFTER vehicle_type', 'SELECT 1');
PREPARE stmt_notes FROM @sql_notes;
EXECUTE stmt_notes;
DEALLOCATE PREPARE stmt_notes;

-- 1. Suppliers
CREATE TABLE IF NOT EXISTS suppliers (
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

-- 2. Services Catalog
CREATE TABLE IF NOT EXISTS services (
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

-- 3. Job Services
CREATE TABLE IF NOT EXISTS job_services (
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

-- 4. Appointments & Scheduling
CREATE TABLE IF NOT EXISTS appointments (
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

-- 5. Notifications
CREATE TABLE IF NOT EXISTS notifications (
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

-- 6. Add supplier_id to spare_parts if not exists
SET @exist := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='spare_parts' AND column_name='supplier_id');
SET @sql := IF(@exist = 0, 'ALTER TABLE spare_parts ADD COLUMN supplier_id INT UNSIGNED NULL AFTER supplier, ADD CONSTRAINT fk_sp_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 7. Add new permissions
INSERT IGNORE INTO permissions (code, module, label) VALUES
  ('services.view', 'Services', 'View service catalog'),
  ('services.manage', 'Services', 'Create and edit garage services'),
  ('suppliers.view', 'Suppliers', 'View suppliers'),
  ('suppliers.manage', 'Suppliers', 'Create and edit suppliers'),
  ('appointments.view', 'Appointments', 'View service appointments'),
  ('appointments.manage', 'Appointments', 'Create, update and convert appointments');

-- Grant permissions to administrator
INSERT IGNORE INTO role_permissions (role_id, permission_id)
  SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
  WHERE r.name = 'administrator' AND p.code IN ('services.view','services.manage','suppliers.view','suppliers.manage','appointments.view','appointments.manage');

-- Grant permissions to receptionist
INSERT IGNORE INTO role_permissions (role_id, permission_id)
  SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
  WHERE r.name = 'receptionist' AND p.code IN ('services.view','appointments.view','appointments.manage','suppliers.view');

-- Grant permissions to inventory manager
INSERT IGNORE INTO role_permissions (role_id, permission_id)
  SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
  WHERE r.name = 'inventory_manager' AND p.code IN ('suppliers.view','suppliers.manage');

-- Seed standard enterprise garage services
INSERT IGNORE INTO services (code, name, category, description, standard_price, estimated_hours) VALUES
  ('SRV-ENG-001', 'Full Engine Diagnostic Scan', 'Diagnostics', 'Comprehensive OBD-II diagnostics, sensor check and report', 750.00, 1.00),
  ('SRV-MAINT-001', 'Complete Oil & Filter Service', 'Maintenance', 'Engine oil drain & refill with premium synthetic oil and OEM filter', 1200.00, 1.00),
  ('SRV-BRK-001', 'Front Brake Pad Replacement & Rotor Check', 'Brakes', 'Inspect rotors and replace front disc brake pads', 950.00, 1.50),
  ('SRV-BRK-002', 'Brake Fluid Flush & Bleed', 'Brakes', 'Complete brake hydraulic fluid flush and pressure bleed', 600.00, 1.00),
  ('SRV-SUSP-001', 'Wheel Alignment & Balancing (4 Wheels)', 'Suspension', 'Computerized 4-wheel laser alignment and dynamic balancing', 850.00, 1.50),
  ('SRV-AC-001', 'Air Conditioning Re-gas & Leak Test', 'AC & Heating', 'R134a evacuation, nitrogen leak test and precision refrigerant recharge', 1100.00, 1.25),
  ('SRV-TRN-001', 'Automatic Transmission Fluid Service', 'Transmission', 'Transmission fluid flush, pan gasket replacement and road test', 1800.00, 2.00),
  ('SRV-ELE-001', 'Battery & Charging System Health Test', 'Electrical', 'Battery conductance load test, alternator and starter health test', 450.00, 0.75);

-- Seed initial suppliers
INSERT IGNORE INTO suppliers (code, name, contact_person, phone, email, address) VALUES
  ('SUP-BOSCH', 'Bosch Auto Parts Ethiopia', 'Mulugeta Tadesse', '+251 11 661 2233', 'sales@bosch-eth.example', 'Bole Subcity, Addis Ababa'),
  ('SUP-DENSO', 'Denso Filtration & Electronics', 'Hiwot Bekele', '+251 11 552 4411', 'info@denso-eth.example', 'Kality Industrial Zone, Addis Ababa'),
  ('SUP-BREMBO', 'Brembo Brake Systems Direct', 'Yonas Alemu', '+251 11 440 9988', 'support@brembo-direct.example', 'Akaki Kality, Addis Ababa'),
  ('SUP-SHELL', 'National Lubricants & Oils Supply', 'Dawit Kebede', '+251 11 278 1122', 'orders@natlubricants.example', 'Mexico Square, Addis Ababa');
