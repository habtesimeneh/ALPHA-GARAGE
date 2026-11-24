-- =====================================================================
-- ALPHA GARAGE: Manual Payment Management & Verification System Migration
-- Migration: 20261122_manual_payment_system.sql
-- =====================================================================

-- 1. PAYMENT METHODS TABLE (Configurable Ethiopian Banks & Mobile Money)
CREATE TABLE IF NOT EXISTS payment_methods (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    name_am VARCHAR(150) NOT NULL,
    type ENUM('bank', 'mobile_money', 'cash', 'other') NOT NULL DEFAULT 'bank',
    bank_name VARCHAR(100) NULL,
    account_name VARCHAR(150) NULL,
    account_number VARCHAR(80) NULL,
    phone_number VARCHAR(40) NULL,
    instructions TEXT NULL,
    instructions_am TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    requires_reference TINYINT(1) NOT NULL DEFAULT 1,
    requires_screenshot TINYINT(1) NOT NULL DEFAULT 1,
    display_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_pm_active_order (is_active, display_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. PAYMENT SUBMISSIONS TABLE (Customer Submissions & Staff Verification)
CREATE TABLE IF NOT EXISTS payment_submissions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    submission_number VARCHAR(30) NOT NULL UNIQUE,
    invoice_id INT UNSIGNED NOT NULL,
    customer_id INT UNSIGNED NOT NULL,
    payment_method_id INT UNSIGNED NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    currency VARCHAR(10) NOT NULL DEFAULT 'ETB',
    transaction_reference VARCHAR(100) NOT NULL,
    transaction_date DATE NOT NULL,
    transaction_time TIME NULL,
    sender_name VARCHAR(150) NOT NULL,
    sender_phone VARCHAR(40) NOT NULL,
    proof_file_path VARCHAR(255) NULL,
    proof_file_mime VARCHAR(50) NULL,
    proof_file_size INT UNSIGNED NULL,
    customer_notes VARCHAR(500) NULL,
    status ENUM('pending_verification', 'under_review', 'verified', 'rejected') NOT NULL DEFAULT 'pending_verification',
    payment_id INT UNSIGNED NULL,
    verified_amount DECIMAL(12,2) NULL,
    reviewer_id INT UNSIGNED NULL,
    reviewed_at DATETIME NULL,
    review_notes TEXT NULL,
    rejection_reason VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_ps_inv FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
    CONSTRAINT fk_ps_cust FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    CONSTRAINT fk_ps_method FOREIGN KEY (payment_method_id) REFERENCES payment_methods(id),
    CONSTRAINT fk_ps_payment FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE SET NULL,
    CONSTRAINT fk_ps_reviewer FOREIGN KEY (reviewer_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_ps_status (status),
    INDEX idx_ps_invoice (invoice_id),
    INDEX idx_ps_customer (customer_id),
    INDEX idx_ps_dup_check (payment_method_id, transaction_reference)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. REGISTER NEW PERMISSIONS
INSERT IGNORE INTO permissions (code, module, label) VALUES
('payments.verify', 'Payments', 'Verify and approve manual customer payment submissions'),
('payments.methods', 'Payments', 'Manage manual payment methods and account status');

-- 4. ASSIGN PERMISSIONS TO ROLES
-- Administrator gets all permissions
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.name = 'administrator' AND p.code IN ('payments.verify', 'payments.methods');

-- Accountant gets payments.verify
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.code = 'payments.verify'
WHERE r.name = 'accountant';

-- 5. SEED CONFIGURABLE ETHIOPIAN PAYMENT METHODS
INSERT INTO payment_methods (code, name, name_am, type, bank_name, account_name, account_number, phone_number, instructions, instructions_am, is_active, requires_reference, requires_screenshot, display_order)
VALUES
('cbe', 'Commercial Bank of Ethiopia (CBE)', 'የኢትዮጵያ ንግድ ባንክ (CBE)', 'bank', 'Commercial Bank of Ethiopia', 'ALPHA GARAGE PLC', '1000123456789', NULL, 'Transfer via CBE Mobile Banking, CBE Birr, or branch deposit. Enter transaction reference and upload screenshot.', 'በኢትዮጵያ ንግድ ባንክ ሞባይል ባንኪንግ ወይም ቅርንጫፍ ገቢ ያድርጉ እና የደረሰኝ ቁጥር በማስገባት ስክሪንሾት ይላኩ።', 1, 1, 1, 1),
('telebirr', 'Telebirr (Ethio Telecom)', 'ቴሌብር (ኢትዮ ቴሌኮም)', 'mobile_money', NULL, 'ALPHA GARAGE PLC', NULL, '0911002233', 'Pay via Telebirr app or *127# to merchant number 0911002233. Upload SMS or app screenshot.', 'በቴሌብር መተግበሪያ ወይም በ *127# ወደ 0911002233 ይላኩ። የክፍያ ስክሪንሾት ወይም SMS ማረጋገጫ ይላኩ።', 1, 1, 1, 2),
('boa', 'Bank of Abyssinia', 'አቢሲኒያ ባንክ', 'bank', 'Bank of Abyssinia', 'ALPHA GARAGE PLC', '2000987654321', NULL, 'Transfer via Apollo or BOA Mobile Banking. Upload transaction screenshot.', 'በአቢሲኒያ ባንክ አፖሎ ወይም ሞባይል ባንኪንግ ገቢ ያድርጉ እና የደረሰኝ ስክሪንሾት ይላኩ።', 1, 1, 1, 3),
('awash', 'Awash Bank', 'አዋሽ ባንክ', 'bank', 'Awash Bank', 'ALPHA GARAGE PLC', '01304567891000', NULL, 'Transfer via Awash Birr Pro or Awash Online. Upload payment receipt.', 'በአዋሽ ብር ወይም አዋሽ ሞባይል ባንኪንግ ገቢ ያድርጉ እና የደረሰኝ ስክሪንሾት ይላኩ።', 1, 1, 1, 4),
('dashen', 'Dashen Bank', 'ዳሸን ባንክ', 'bank', 'Dashen Bank', 'ALPHA GARAGE PLC', '5012345678011', NULL, 'Transfer via Amole or Dashen Mobile App. Upload transaction proof.', 'በዳሸን ባንክ አሞሌ ወይም ሞባይል ባንኪንግ ገቢ ያድርጉ እና ማረጋገጫ ይላኩ።', 1, 1, 1, 5),
('mpesa', 'M-Pesa (Safaricom)', 'ኤም-ፔሳ (ሳፋሪኮም)', 'mobile_money', NULL, 'ALPHA GARAGE PLC', NULL, '0711002233', 'Pay via M-Pesa Safaricom to till number 0711002233. Upload confirmation SMS or app screenshot.', 'በኤም-ፔሳ ሳፋሪኮም ወደ 0711002233 ይላኩ። የክፍያ ማረጋገጫ SMS ወይም ስክሪንሾት ይላኩ።', 1, 1, 1, 6),
('cbe_birr', 'CBE Birr', 'ሲቢኢ ብር', 'mobile_money', NULL, 'ALPHA GARAGE PLC', NULL, '0922003344', 'Pay via CBE Birr to 0922003344. Upload transaction screenshot.', 'በሲቢኢ ብር ወደ 0922003344 ይላኩ። የግብይት ስክሪንሾት ይላኩ።', 1, 1, 1, 7),
('amole', 'Amole (Dashen)', 'አሞሌ (ዳሸን)', 'mobile_money', NULL, 'ALPHA GARAGE PLC', NULL, '0933004455', 'Pay via Amole wallet to 0933004455. Upload confirmation screenshot.', 'በአሞሌ ወደ 0933004455 ይላኩ። የማረጋገጫ ስክሪንሾት ይላኩ።', 1, 1, 1, 8),
('hibret', 'Hibret Bank (United Bank)', 'ህብረት ባንክ', 'bank', 'Hibret Bank', 'ALPHA GARAGE PLC', '1010101010101', NULL, 'Transfer via Hibret Bank Mobile. Upload payment receipt.', 'በህብረት ባንክ ሞባይል ባንኪንግ ገቢ ያድርጉ እና ደረሰኝ ይላኩ።', 1, 1, 1, 9),
('wegagen', 'Wegagen Bank', 'ወጋገን ባንክ', 'bank', 'Wegagen Bank', 'ALPHA GARAGE PLC', '0812345678901', NULL, 'Transfer via Wegagen Mobile Banking. Upload payment slip.', 'በወጋገን ባንክ ሞባይል ባንኪንግ ገቢ ያድርጉ እና ደረሰኝ ይላኩ።', 1, 1, 1, 10),
('nib', 'Nib International Bank', 'ንብ ኢንተርናሽናል ባንክ', 'bank', 'Nib International Bank', 'ALPHA GARAGE PLC', '7001234567890', NULL, 'Transfer via Nib Mobile Banking. Upload payment receipt.', 'በንብ ኢንተርናሽናል ባንክ ሞባይል ባንኪንግ ገቢ ያድርጉ እና ደረሰኝ ይላኩ።', 1, 1, 1, 11),
('coopbank', 'Cooperative Bank of Oromia (Coopbank)', 'የኦሮሚያ ህብረት ስራ ባንክ', 'bank', 'Cooperative Bank of Oromia', 'ALPHA GARAGE PLC', '1000023456781', NULL, 'Transfer via Coopnet / CoopApp. Upload payment proof.', 'በኦሮሚያ ህብረት ስራ ባንክ ኩፕኔት ገቢ ያድርጉ እና ማረጋገጫ ይላኩ።', 1, 1, 1, 12),
('oromia_bank', 'Oromia Bank', 'ኦሮሚያ ባንክ', 'bank', 'Oromia Bank', 'ALPHA GARAGE PLC', '1234567890123', NULL, 'Transfer via Oromia Bank Mobile. Upload payment receipt.', 'በኦሮሚያ ባንክ ሞባይል ባንኪንግ ገቢ ያድርጉ እና ደረሰኝ ይላኩ።', 1, 1, 1, 13),
('zemen', 'Zemen Bank', 'ዘመን ባንክ', 'bank', 'Zemen Bank', 'ALPHA GARAGE PLC', '3001234567890', NULL, 'Transfer via Zemen Mobile Banking. Upload transaction proof.', 'በዘመን ባንክ ሞባይል ባንኪንግ ገቢ ያድርጉ እና ማረጋገጫ ይላኩ።', 1, 1, 1, 14),
('amhara_bank', 'Amhara Bank', 'አማራ ባንክ', 'bank', 'Amhara Bank', 'ALPHA GARAGE PLC', '4001234567890', NULL, 'Transfer via Amhara Bank Mobile. Upload transaction proof.', 'በአማራ ባንክ ሞባይል ባንኪንግ ገቢ ያድርጉ እና ማረጋገጫ ይላኩ።', 1, 1, 1, 15),
('enat_bank', 'Enat Bank', 'እናት ባንክ', 'bank', 'Enat Bank', 'ALPHA GARAGE PLC', '5001234567890', NULL, 'Transfer via Enat Bank Mobile. Upload payment receipt.', 'በእናት ባንክ ሞባይል ባንኪንግ ገቢ ያድርጉ እና ደረሰኝ ይላኩ።', 1, 1, 1, 16),
('bunna_bank', 'Bunna Bank', 'ቡና ባንክ', 'bank', 'Bunna Bank', 'ALPHA GARAGE PLC', '6001234567890', NULL, 'Transfer via Bunna Mobile Banking. Upload payment proof.', 'በቡና ባንክ ሞባይል ባንኪንግ ገቢ ያድርጉ እና ማረጋገጫ ይላኩ።', 1, 1, 1, 17),
('berhan_bank', 'Berhan Bank', 'ብርሃን ባንክ', 'bank', 'Berhan Bank', 'ALPHA GARAGE PLC', '7009876543210', NULL, 'Transfer via Berhan Mobile. Upload payment receipt.', 'በብርሃን ባንክ ሞባይል ባንኪንግ ገቢ ያድርጉ እና ደረሰኝ ይላኩ።', 1, 1, 1, 18),
('lion_bank', 'Lion International Bank', 'አንበሳ ኢንተርናሽናል ባንክ', 'bank', 'Lion International Bank', 'ALPHA GARAGE PLC', '8001234567890', NULL, 'Transfer via Anbessa Mobile. Upload payment proof.', 'በአንበሳ ኢንተርናሽናል ባንክ ሞባይል ባንኪንግ ገቢ ያድርጉ እና ማረጋገጫ ይላኩ።', 1, 1, 1, 19),
('addis_bank', 'Addis International Bank', 'አዲስ ኢንተርናሽናል ባንክ', 'bank', 'Addis International Bank', 'ALPHA GARAGE PLC', '9001234567890', NULL, 'Transfer via Addis Bank Mobile. Upload payment proof.', 'በአዲስ ኢንተርናሽናል ባንክ ሞባይል ባንኪንግ ገቢ ያድርጉ እና ማረጋገጫ ይላኩ።', 1, 1, 1, 20),
('goh_bank', 'Goh Betoch Bank', 'ጎህ ቤቶች ባንክ', 'bank', 'Goh Betoch Bank', 'ALPHA GARAGE PLC', '1101234567890', NULL, 'Transfer via Goh Bank Mobile. Upload payment proof.', 'በጎህ ቤቶች ባንክ ገቢ ያድርጉ እና ማረጋገጫ ይላኩ።', 1, 1, 1, 21),
('tsehay_bank', 'Tsehay Bank', 'ፀሐይ ባንክ', 'bank', 'Tsehay Bank', 'ALPHA GARAGE PLC', '1201234567890', NULL, 'Transfer via Tsehay Bank Mobile. Upload payment proof.', 'በፀሐይ ባንክ ሞባይል ባንኪንግ ገቢ ያድርጉ እና ማረጋገጫ ይላኩ።', 1, 1, 1, 22),
('siinqee_bank', 'Siinqee Bank', 'ሲንቄ ባንክ', 'bank', 'Siinqee Bank', 'ALPHA GARAGE PLC', '1301234567890', NULL, 'Transfer via Siinqee Bank Mobile. Upload payment proof.', 'በሲንቄ ባንክ ሞባይል ባንኪንግ ገቢ ያድርጉ እና ማረጋገጫ ይላኩ።', 1, 1, 1, 23),
('global_bank', 'Global Bank Ethiopia', 'ግሎባል ባንክ ኢትዮጵያ', 'bank', 'Global Bank Ethiopia', 'ALPHA GARAGE PLC', '1401234567890', NULL, 'Transfer via Global Bank Mobile. Upload payment proof.', 'በግሎባል ባንክ ኢትዮጵያ ሞባይል ባንኪንግ ገቢ ያድርጉ እና ማረጋገጫ ይላኩ።', 1, 1, 1, 24),
('hijra_bank', 'Hijra Bank', 'ሂጅራ ባንክ', 'bank', 'Hijra Bank', 'ALPHA GARAGE PLC', '1501234567890', NULL, 'Transfer via Hijra Interest-Free Banking. Upload payment proof.', 'በሂጅራ ባንክ ወለድ አልባ የባንክ አገልግሎት ገቢ ያድርጉ እና ማረጋገጫ ይላኩ።', 1, 1, 1, 25),
('zamzam_bank', 'ZamZam Bank', 'ዘምዘም ባንክ', 'bank', 'ZamZam Bank', 'ALPHA GARAGE PLC', '1601234567890', NULL, 'Transfer via ZamZam Sharia Banking. Upload payment proof.', 'በዘምዘም ባንክ ሸሪዓ ባንኪንግ ገቢ ያድርጉ እና ማረጋገጫ ይላኩ።', 1, 1, 1, 26),
('cash', 'Cash (Over-the-Counter)', 'ጥሬ ገንዘብ (በቀጥታ)', 'cash', NULL, 'ALPHA GARAGE Front Desk', NULL, NULL, 'Pay directly in cash at the ALPHA GARAGE reception desk. An official verified receipt will be issued.', 'በአልፋ ጋራዥ የፊት ዴስክ በቀጥታ በጥሬ ገንዘብ ይክፈሉ። የደረሰኝ ማረጋገጫ ይሰጥዎታል።', 1, 0, 0, 27)
ON DUPLICATE KEY UPDATE
name = VALUES(name),
name_am = VALUES(name_am),
instructions = VALUES(instructions),
instructions_am = VALUES(instructions_am);
