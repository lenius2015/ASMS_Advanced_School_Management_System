-- =====================================================================
-- ASMS - Advanced School Management System
-- Migration 006: School Fee Management Module (Bursar Module)
-- 
-- This migration adds:
-- 1. Control Number system for invoices (SCH2026INV00001 format)
-- 2. Enhanced invoice statuses (pending, partial, paid, overdue, cancelled)
-- 3. Student fee accounts (auto-created on student registration)
-- 4. Payment API logs for future gateway integration (GePG, M-Pesa, etc.)
-- 5. Standardized fee categories
-- 6. Control number sequence table
-- 7. Triggers for auto-updating fee accounts
-- =====================================================================

SET FOREIGN_KEY_CHECKS = 0;
USE asms_db;

-- =====================================================================
-- 1. CONTROL NUMBER SEQUENCE TABLE
-- =====================================================================
CREATE TABLE IF NOT EXISTS control_number_sequences (
    seq_id INT AUTO_INCREMENT PRIMARY KEY,
    year_prefix VARCHAR(4) NOT NULL,
    last_sequence INT NOT NULL DEFAULT 0,
    UNIQUE KEY uq_year_prefix (year_prefix)
) ENGINE=InnoDB;

INSERT INTO control_number_sequences (year_prefix, last_sequence) 
SELECT DATE_FORMAT(CURDATE(), '%Y'), 0
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM control_number_sequences WHERE year_prefix = DATE_FORMAT(CURDATE(), '%Y'));

-- =====================================================================
-- 2. ENHANCE INVOICES TABLE
-- =====================================================================
ALTER TABLE invoices 
  ADD COLUMN control_number VARCHAR(30) DEFAULT NULL AFTER invoice_no,
  ADD COLUMN cancel_reason VARCHAR(255) DEFAULT NULL,
  ADD COLUMN cancelled_by INT DEFAULT NULL,
  ADD COLUMN cancelled_at TIMESTAMP NULL DEFAULT NULL,
  ADD INDEX idx_control_number (control_number),
  ADD INDEX idx_invoices_due_date (due_date);

-- Update invoice statuses
ALTER TABLE invoices 
  MODIFY COLUMN status ENUM('pending','partial','paid','overdue','cancelled') NOT NULL DEFAULT 'pending';

-- =====================================================================
-- 3. ENHANCE PAYMENTS TABLE
-- =====================================================================
ALTER TABLE payments
  ADD COLUMN recorded_by_name VARCHAR(161) DEFAULT NULL,
  ADD COLUMN is_online_payment TINYINT(1) NOT NULL DEFAULT 0;

-- =====================================================================
-- 4. STUDENT FEE ACCOUNTS (aggregated per student)
-- =====================================================================
CREATE TABLE IF NOT EXISTS student_fee_accounts (
    account_id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL UNIQUE,
    total_fees DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    total_paid DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    balance DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    payment_status ENUM('pending','partial','paid','overdue') NOT NULL DEFAULT 'pending',
    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE,
    INDEX idx_fee_account_status (payment_status)
) ENGINE=InnoDB;

-- Seed existing students
INSERT INTO student_fee_accounts (student_id, total_fees, total_paid, balance, payment_status)
SELECT 
    s.student_id,
    COALESCE((SELECT SUM(i.total_amount) FROM invoices i WHERE i.student_id = s.student_id), 0),
    COALESCE((SELECT SUM(i.amount_paid) FROM invoices i WHERE i.student_id = s.student_id), 0),
    COALESCE((SELECT SUM(i.balance) FROM invoices i WHERE i.student_id = s.student_id), 0),
    CASE 
        WHEN COALESCE((SELECT SUM(i.balance) FROM invoices i WHERE i.student_id = s.student_id), 0) <= 0 THEN 'paid'
        WHEN COALESCE((SELECT SUM(i.amount_paid) FROM invoices i WHERE i.student_id = s.student_id), 0) > 0 THEN 'partial'
        ELSE 'pending'
    END
FROM students s
ON DUPLICATE KEY UPDATE 
    total_fees = VALUES(total_fees),
    total_paid = VALUES(total_paid),
    balance = VALUES(balance),
    payment_status = VALUES(payment_status);

-- =====================================================================
-- 5. PAYMENT API LOGS (for future GePG/M-Pesa integration)
-- =====================================================================
CREATE TABLE IF NOT EXISTS payment_api_logs (
    api_log_id BIGINT AUTO_INCREMENT PRIMARY KEY,
    payment_id INT DEFAULT NULL,
    invoice_id INT DEFAULT NULL,
    gateway ENUM('gepg','mpesa','tigo_pesa','airtel_money','halopesa','bank','manual') NOT NULL DEFAULT 'manual',
    request_payload TEXT,
    response_payload TEXT,
    status ENUM('success','failed','pending') NOT NULL DEFAULT 'pending',
    reference_no VARCHAR(100) DEFAULT NULL,
    transaction_id VARCHAR(100) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (payment_id) REFERENCES payments(payment_id) ON DELETE SET NULL,
    FOREIGN KEY (invoice_id) REFERENCES invoices(invoice_id) ON DELETE SET NULL,
    INDEX idx_api_log_gateway (gateway),
    INDEX idx_api_log_status (status),
    INDEX idx_api_log_created (created_at)
) ENGINE=InnoDB;

-- =====================================================================
-- 6. STANDARDIZED FEE CATEGORIES
-- =====================================================================
INSERT INTO fee_categories (category_name) VALUES
('School Fees'),
('Examination Fees'),
('Hostel Fees'),
('Transport Fees'),
('Meals Fees'),
('Uniform Fees'),
('Other Charges')
ON DUPLICATE KEY UPDATE category_name = VALUES(category_name);

-- =====================================================================
-- 7. TRIGGER: Auto-create fee account after student registration
-- =====================================================================
DELIMITER //
DROP TRIGGER IF EXISTS after_student_insert_fee_account//
CREATE TRIGGER after_student_insert_fee_account
AFTER INSERT ON students
FOR EACH ROW
BEGIN
    INSERT INTO student_fee_accounts (student_id, total_fees, total_paid, balance, payment_status)
    VALUES (NEW.student_id, 0.00, 0.00, 0.00, 'pending')
    ON DUPLICATE KEY UPDATE student_id = VALUES(student_id);
END//
DELIMITER ;

-- =====================================================================
-- 8. TRIGGER: Auto-update invoice + fee account after payment
-- =====================================================================
DELIMITER //
DROP TRIGGER IF EXISTS after_payment_insert_update_fee//
CREATE TRIGGER after_payment_insert_update_fee
AFTER INSERT ON payments
FOR EACH ROW
BEGIN
    DECLARE inv_total DECIMAL(14,2);
    DECLARE inv_paid DECIMAL(14,2);
    DECLARE inv_balance DECIMAL(14,2);
    DECLARE new_status VARCHAR(20);
    
    SELECT total_amount, amount_paid, balance INTO inv_total, inv_paid, inv_balance
    FROM invoices WHERE invoice_id = NEW.invoice_id;
    
    SET inv_paid = inv_paid + NEW.amount;
    SET inv_balance = inv_total - inv_paid;
    
    IF inv_balance <= 0 THEN
        SET new_status = 'paid';
    ELSEIF inv_paid > 0 THEN
        SET new_status = 'partial';
    ELSE
        SET new_status = 'pending';
    END IF;
    
    UPDATE invoices 
    SET amount_paid = inv_paid, 
        balance = GREATEST(inv_balance, 0), 
        status = new_status
    WHERE invoice_id = NEW.invoice_id;
    
    -- Update aggregated student fee account
    INSERT INTO student_fee_accounts (student_id, total_fees, total_paid, balance, payment_status)
    SELECT 
        NEW.student_id,
        COALESCE((SELECT SUM(total_amount) FROM invoices WHERE student_id = NEW.student_id), 0),
        COALESCE((SELECT SUM(amount_paid) FROM invoices WHERE student_id = NEW.student_id), 0),
        COALESCE((SELECT SUM(balance) FROM invoices WHERE student_id = NEW.student_id), 0),
        CASE 
            WHEN COALESCE((SELECT SUM(balance) FROM invoices WHERE student_id = NEW.student_id), 0) <= 0 THEN 'paid'
            WHEN COALESCE((SELECT SUM(amount_paid) FROM invoices WHERE student_id = NEW.student_id), 0) > 0 THEN 'partial'
            ELSE 'pending'
        END
    ON DUPLICATE KEY UPDATE
        total_fees = VALUES(total_fees),
        total_paid = VALUES(total_paid),
        balance = VALUES(balance),
        payment_status = VALUES(payment_status);
END//
DELIMITER ;

-- =====================================================================
-- 9. STORED PROCEDURE: Generate Control Number
--    Format: SCH2026INV00001
-- =====================================================================
DELIMITER //
DROP PROCEDURE IF EXISTS generate_control_number//
CREATE PROCEDURE generate_control_number(
    IN p_year VARCHAR(4),
    OUT p_control_number VARCHAR(30)
)
BEGIN
    DECLARE next_seq INT;
    
    INSERT INTO control_number_sequences (year_prefix, last_sequence) 
    VALUES (p_year, 0) 
    ON DUPLICATE KEY UPDATE last_sequence = last_sequence;
    
    SELECT last_sequence + 1 INTO next_seq 
    FROM control_number_sequences 
    WHERE year_prefix = p_year 
    FOR UPDATE;
    
    UPDATE control_number_sequences 
    SET last_sequence = next_seq 
    WHERE year_prefix = p_year;
    
    SET p_control_number = CONCAT('SCH', p_year, 'INV', LPAD(next_seq, 5, '0'));
END//
DELIMITER ;

-- =====================================================================
-- 10. STORED PROCEDURE: Sync Student Fee Account
-- =====================================================================
DELIMITER //
DROP PROCEDURE IF EXISTS sync_student_fee_account//
CREATE PROCEDURE sync_student_fee_account(IN p_student_id INT)
BEGIN
    INSERT INTO student_fee_accounts (student_id, total_fees, total_paid, balance, payment_status)
    SELECT 
        p_student_id,
        COALESCE((SELECT SUM(total_amount) FROM invoices WHERE student_id = p_student_id), 0),
        COALESCE((SELECT SUM(amount_paid) FROM invoices WHERE student_id = p_student_id), 0),
        COALESCE((SELECT SUM(balance) FROM invoices WHERE student_id = p_student_id), 0),
        CASE 
            WHEN COALESCE((SELECT SUM(balance) FROM invoices WHERE student_id = p_student_id), 0) <= 0 THEN 'paid'
            WHEN COALESCE((SELECT SUM(amount_paid) FROM invoices WHERE student_id = p_student_id), 0) > 0 THEN 'partial'
            ELSE 'pending'
        END
    ON DUPLICATE KEY UPDATE
        total_fees = VALUES(total_fees),
        total_paid = VALUES(total_paid),
        balance = VALUES(balance),
        payment_status = VALUES(payment_status);
END//
DELIMITER ;

-- =====================================================================
-- 11. STORED PROCEDURE: Mark Overdue Invoices
-- =====================================================================
DELIMITER //
DROP PROCEDURE IF EXISTS mark_overdue_invoices//
CREATE PROCEDURE mark_overdue_invoices()
BEGIN
    UPDATE invoices 
    SET status = 'overdue' 
    WHERE status IN ('pending', 'partial') 
      AND due_date IS NOT NULL 
      AND due_date < CURDATE()
      AND balance > 0;
    
    UPDATE student_fee_accounts sfa
    JOIN (
        SELECT student_id 
        FROM invoices 
        WHERE status = 'overdue' 
        GROUP BY student_id
    ) inv ON inv.student_id = sfa.student_id
    SET sfa.payment_status = 'overdue';
END//
DELIMITER ;

SET FOREIGN_KEY_CHECKS = 1;