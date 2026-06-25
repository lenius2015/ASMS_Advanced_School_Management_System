-- =====================================================================
-- ASMS Migration 007 - Payroll Item Types & Enhanced Payroll
-- 
-- Adds:
-- 1. payroll_item_types table (templates for allowances/deductions)
-- 2. Default allowance and deduction types
-- 3. Indexes for payslip_items
-- =====================================================================

SET FOREIGN_KEY_CHECKS = 0;
USE asms_db;

-- =====================================================================
-- 1. PAYROLL ITEM TYPES (templates for quick selection)
-- =====================================================================
CREATE TABLE IF NOT EXISTS payroll_item_types (
    item_type_id INT AUTO_INCREMENT PRIMARY KEY,
    item_name VARCHAR(80) NOT NULL,
    item_category ENUM('allowance','deduction') NOT NULL,
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    default_amount DECIMAL(14,2) DEFAULT 0.00,
    is_percentage TINYINT(1) NOT NULL DEFAULT 0,
    percentage_value DECIMAL(5,2) DEFAULT 0.00,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- =====================================================================
-- 2. DEFAULT ALLOWANCE TYPES
-- =====================================================================
INSERT INTO payroll_item_types (item_name, item_category, is_default, default_amount, sort_order) VALUES
('House Allowance', 'allowance', 1, 0.00, 1),
('Transport Allowance', 'allowance', 1, 0.00, 2),
('Medical Allowance', 'allowance', 1, 0.00, 3),
('Hardship Allowance', 'allowance', 1, 0.00, 4),
('Communication Allowance', 'allowance', 1, 0.00, 5),
('Meals Allowance', 'allowance', 1, 0.00, 6),
('Leave Allowance', 'allowance', 1, 0.00, 7),
('Bonus', 'allowance', 1, 0.00, 8),
('Overtime', 'allowance', 1, 0.00, 9),
('Other Allowance', 'allowance', 1, 0.00, 10)
ON DUPLICATE KEY UPDATE item_name = VALUES(item_name);

-- =====================================================================
-- 3. DEFAULT DEDUCTION TYPES
-- =====================================================================
INSERT INTO payroll_item_types (item_name, item_category, is_default, default_amount, sort_order) VALUES
('PAYE (Income Tax)', 'deduction', 1, 0.00, 1),
('NSSF (Social Security)', 'deduction', 1, 0.00, 2),
('NHIF (Health Insurance)', 'deduction', 1, 0.00, 3),
('Loan Repayment', 'deduction', 1, 0.00, 4),
('Advance Recovery', 'deduction', 1, 0.00, 5),
('Union Dues', 'deduction', 1, 0.00, 6),
('Pension Contribution', 'deduction', 1, 0.00, 7),
('Salary Advance', 'deduction', 1, 0.00, 8),
('Penalty', 'deduction', 1, 0.00, 9),
('Other Deduction', 'deduction', 1, 0.00, 10)
ON DUPLICATE KEY UPDATE item_name = VALUES(item_name);

-- =====================================================================
-- 4. ENSURE payslip_items has proper indexes
-- =====================================================================
ALTER TABLE payslip_items
  ADD INDEX IF NOT EXISTS idx_payslip_items_type (item_type),
  ADD INDEX IF NOT EXISTS idx_payslip_items_payslip (payslip_id);

SET FOREIGN_KEY_CHECKS = 1;