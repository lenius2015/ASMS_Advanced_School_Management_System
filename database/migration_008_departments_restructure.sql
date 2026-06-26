-- =====================================================================
-- ASMS Migration 008: Departments Restructure & User Linking
-- Adds mandatory school departments, permission changes, and
-- infrastructure for payment gateway integration.
-- =====================================================================

SET FOREIGN_KEY_CHECKS = 0;

-- 1. Seed the 5 mandatory departments (if not already present)
INSERT INTO departments (department_name, description) VALUES
('Academic Department', 'Manages curriculum, examinations, and academic programs'),
('Hostels Department', 'Manages student boarding, hostel facilities, and accommodation'),
('Health Department', 'Manages school health services, clinic, and student wellness'),
('Social & Welfare Department', 'Manages student social activities, counseling, and welfare programs'),
('Environment & Maintenance Department', 'Manages school grounds, facilities maintenance, and environmental programs')
ON DUPLICATE KEY UPDATE department_name = VALUES(department_name);

-- 2. Add education_level column to staff table (if not exists - supported by migration_003)
-- Check if it already exists from migration_003
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS 
                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staff' AND COLUMN_NAME = 'education_level');
SET @query = IF(@col_exists = 0, 
    'ALTER TABLE staff ADD COLUMN education_level VARCHAR(100) DEFAULT NULL AFTER basic_salary',
    'SELECT "education_level already exists" AS status');
PREPARE stmt FROM @query;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 3. Add payment gateway settings table
CREATE TABLE IF NOT EXISTS payment_gateways (
    gateway_id INT AUTO_INCREMENT PRIMARY KEY,
    gateway_name VARCHAR(50) NOT NULL UNIQUE, -- 'selcom', 'nmb', 'crdb', 'mpesa', 'airtel'
    is_active TINYINT(1) NOT NULL DEFAULT 0,
    api_key VARCHAR(255) DEFAULT NULL,
    api_secret VARCHAR(255) DEFAULT NULL,
    api_endpoint VARCHAR(255) DEFAULT NULL,
    merchant_code VARCHAR(100) DEFAULT NULL,
    callback_url VARCHAR(255) DEFAULT NULL,
    config_json TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT INTO payment_gateways (gateway_name, is_active) VALUES
('selcom', 0), ('nmb', 0), ('crdb', 0), ('mpesa', 0), ('airtel_money', 0)
ON DUPLICATE KEY UPDATE gateway_name = VALUES(gateway_name);

-- 4. Add payment_transactions table for tracking online payments
CREATE TABLE IF NOT EXISTS payment_transactions (
    transaction_id BIGINT AUTO_INCREMENT PRIMARY KEY,
    invoice_id INT DEFAULT NULL,
    student_id INT DEFAULT NULL,
    amount DECIMAL(14,2) NOT NULL,
    payment_method ENUM('cash','bank_transfer','mobile_money','card','online_gateway','cheque') NOT NULL DEFAULT 'online_gateway',
    gateway_name VARCHAR(50) DEFAULT NULL,
    gateway_transaction_id VARCHAR(100) DEFAULT NULL,
    control_number VARCHAR(50) DEFAULT NULL,
    reference_no VARCHAR(100) DEFAULT NULL,
    phone_number VARCHAR(20) DEFAULT NULL,
    status ENUM('pending','completed','failed','refunded','expired') NOT NULL DEFAULT 'pending',
    paid_at TIMESTAMP NULL DEFAULT NULL,
    callback_received_at TIMESTAMP NULL DEFAULT NULL,
    callback_data TEXT DEFAULT NULL,
    initiated_by INT DEFAULT NULL, -- user_id who initiated (parent)
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (invoice_id) REFERENCES invoices(invoice_id) ON DELETE SET NULL,
    FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE SET NULL,
    FOREIGN KEY (initiated_by) REFERENCES users(user_id) ON DELETE SET NULL,
    INDEX idx_pt_transaction (gateway_transaction_id),
    INDEX idx_pt_control (control_number),
    INDEX idx_pt_status (status),
    INDEX idx_pt_student (student_id)
) ENGINE=InnoDB;

-- 5. Add control_numbers table for bill payment systems (NMB/CRDB)
CREATE TABLE IF NOT EXISTS control_numbers (
    control_number_id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_id INT NOT NULL,
    student_id INT NOT NULL,
    control_number VARCHAR(50) NOT NULL,
    amount DECIMAL(14,2) NOT NULL,
    generated_date DATE NOT NULL,
    expiry_date DATE DEFAULT NULL,
    status ENUM('active','paid','expired','cancelled') NOT NULL DEFAULT 'active',
    payment_transaction_id BIGINT DEFAULT NULL,
    generated_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (invoice_id) REFERENCES invoices(invoice_id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE,
    FOREIGN KEY (payment_transaction_id) REFERENCES payment_transactions(transaction_id) ON DELETE SET NULL,
    FOREIGN KEY (generated_by) REFERENCES users(user_id) ON DELETE SET NULL,
    INDEX idx_cn_number (control_number),
    INDEX idx_cn_invoice (invoice_id),
    INDEX idx_cn_status (status)
) ENGINE=InnoDB;

SET FOREIGN_KEY_CHECKS = 1;