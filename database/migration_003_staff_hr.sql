-- =====================================================================
-- ASMS Migration 003 - Staff & HR Enhancement
-- Adds HR department, expanded staff fields, certificates/documents
-- =====================================================================

SET FOREIGN_KEY_CHECKS = 0;

-- 1. Add Human Resources department if not exists
INSERT IGNORE INTO departments (department_name, description) VALUES
('Human Resources', 'Manages staff records, recruitment, training, and welfare');

-- 2. Add new columns to staff table
ALTER TABLE staff
  ADD COLUMN next_of_kin_name VARCHAR(120) DEFAULT NULL AFTER national_id,
  ADD COLUMN next_of_kin_phone VARCHAR(20) DEFAULT NULL AFTER next_of_kin_name,
  ADD COLUMN next_of_kin_relationship VARCHAR(40) DEFAULT NULL AFTER next_of_kin_phone,
  ADD COLUMN bank_name VARCHAR(80) DEFAULT NULL AFTER basic_salary,
  ADD COLUMN bank_account_no VARCHAR(40) DEFAULT NULL AFTER bank_name,
  ADD COLUMN bank_branch VARCHAR(80) DEFAULT NULL AFTER bank_account_no,
  ADD COLUMN tin_number VARCHAR(30) DEFAULT NULL AFTER bank_branch,
  ADD COLUMN nssf_number VARCHAR(30) DEFAULT NULL AFTER tin_number,
  ADD COLUMN education_level VARCHAR(50) DEFAULT NULL AFTER nssf_number,
  ADD COLUMN years_of_experience INT NOT NULL DEFAULT 0 AFTER education_level,
  ADD COLUMN marital_status ENUM('single','married','divorced','widowed') DEFAULT NULL AFTER years_of_experience,
  ADD COLUMN religion VARCHAR(50) DEFAULT NULL AFTER marital_status,
  ADD COLUMN emergency_contact_name VARCHAR(120) DEFAULT NULL AFTER religion,
  ADD COLUMN emergency_contact_phone VARCHAR(20) DEFAULT NULL AFTER emergency_contact_name;

-- 3. Create staff_documents table
CREATE TABLE IF NOT EXISTS staff_documents (
    document_id INT AUTO_INCREMENT PRIMARY KEY,
    staff_id INT NOT NULL,
    document_type ENUM('cv','certificate','degree','transcript','professional_cert','id_copy','recommendation','appointment_letter','contract','other') NOT NULL DEFAULT 'other',
    document_name VARCHAR(150) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    file_size INT DEFAULT NULL COMMENT 'File size in bytes',
    mime_type VARCHAR(80) DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    uploaded_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (staff_id) REFERENCES staff(staff_id) ON DELETE CASCADE,
    FOREIGN KEY (uploaded_by) REFERENCES users(user_id) ON DELETE SET NULL,
    INDEX idx_staffdocs_staff (staff_id)
) ENGINE=InnoDB;

-- 4. Create staff_certificates table
CREATE TABLE IF NOT EXISTS staff_certificates (
    certificate_id INT AUTO_INCREMENT PRIMARY KEY,
    staff_id INT NOT NULL,
    certificate_name VARCHAR(150) NOT NULL,
    issuing_authority VARCHAR(150) NOT NULL,
    certificate_number VARCHAR(60) DEFAULT NULL,
    issue_date DATE DEFAULT NULL,
    expiry_date DATE DEFAULT NULL,
    file_path VARCHAR(255) DEFAULT NULL,
    verified TINYINT(1) NOT NULL DEFAULT 0,
    verified_by INT DEFAULT NULL,
    verified_at TIMESTAMP NULL DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (staff_id) REFERENCES staff(staff_id) ON DELETE CASCADE,
    FOREIGN KEY (verified_by) REFERENCES users(user_id) ON DELETE SET NULL,
    INDEX idx_staffcerts_staff (staff_id)
) ENGINE=InnoDB;

-- 5. Create staff_qualifications table
CREATE TABLE IF NOT EXISTS staff_qualifications (
    qualification_id INT AUTO_INCREMENT PRIMARY KEY,
    staff_id INT NOT NULL,
    qualification_name VARCHAR(150) NOT NULL,
    institution VARCHAR(150) NOT NULL,
    field_of_study VARCHAR(100) DEFAULT NULL,
    year_obtained YEAR DEFAULT NULL,
    grade VARCHAR(20) DEFAULT NULL,
    file_path VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (staff_id) REFERENCES staff(staff_id) ON DELETE CASCADE,
    INDEX idx_staffquals_staff (staff_id)
) ENGINE=InnoDB;

-- 6. Create staff_emergency_contacts table
CREATE TABLE IF NOT EXISTS staff_emergency_contacts (
    contact_id INT AUTO_INCREMENT PRIMARY KEY,
    staff_id INT NOT NULL,
    full_name VARCHAR(120) NOT NULL,
    relationship VARCHAR(40) NOT NULL,
    phone VARCHAR(20) DEFAULT NULL,
    email VARCHAR(120) DEFAULT NULL,
    address TEXT DEFAULT NULL,
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (staff_id) REFERENCES staff(staff_id) ON DELETE CASCADE,
    INDEX idx_staffecontact_staff (staff_id)
) ENGINE=InnoDB;

-- 7. Create staff_leave table for HR
CREATE TABLE IF NOT EXISTS staff_leave (
    leave_id INT AUTO_INCREMENT PRIMARY KEY,
    staff_id INT NOT NULL,
    leave_type ENUM('annual','sick','maternity','paternity','study','compassionate','unpaid','other') NOT NULL DEFAULT 'annual',
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    total_days INT GENERATED ALWAYS AS (DATEDIFF(end_date, start_date) + 1) STORED,
    reason TEXT DEFAULT NULL,
    status ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
    approved_by INT DEFAULT NULL,
    approved_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (staff_id) REFERENCES staff(staff_id) ON DELETE CASCADE,
    FOREIGN KEY (approved_by) REFERENCES users(user_id) ON DELETE SET NULL,
    INDEX idx_staffleave_staff (staff_id),
    INDEX idx_staffleave_status (status)
) ENGINE=InnoDB;

-- 8. Create staff_training table for HR
CREATE TABLE IF NOT EXISTS staff_trainings (
    training_id INT AUTO_INCREMENT PRIMARY KEY,
    staff_id INT NOT NULL,
    training_name VARCHAR(150) NOT NULL,
    provider VARCHAR(150) DEFAULT NULL,
    start_date DATE DEFAULT NULL,
    end_date DATE DEFAULT NULL,
    certificate_obtained TINYINT(1) NOT NULL DEFAULT 0,
    file_path VARCHAR(255) DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (staff_id) REFERENCES staff(staff_id) ON DELETE CASCADE,
    INDEX idx_stafftrain_staff (staff_id)
) ENGINE=InnoDB;

-- 9. Create staff_performance table (simplified)
CREATE TABLE IF NOT EXISTS staff_performance (
    performance_id INT AUTO_INCREMENT PRIMARY KEY,
    staff_id INT NOT NULL,
    review_period VARCHAR(50) NOT NULL COMMENT 'e.g. Q1 2026, Annual 2025',
    review_date DATE DEFAULT NULL,
    rating ENUM('excellent','good','satisfactory','needs_improvement','unsatisfactory') DEFAULT NULL,
    reviewer_id INT DEFAULT NULL,
    strengths TEXT DEFAULT NULL,
    areas_for_improvement TEXT DEFAULT NULL,
    goals TEXT DEFAULT NULL,
    overall_score DECIMAL(5,2) DEFAULT NULL,
    file_path VARCHAR(255) DEFAULT NULL,
    status ENUM('draft','submitted','reviewed','acknowledged') NOT NULL DEFAULT 'draft',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (staff_id) REFERENCES staff(staff_id) ON DELETE CASCADE,
    FOREIGN KEY (reviewer_id) REFERENCES users(user_id) ON DELETE SET NULL,
    INDEX idx_staffperf_staff (staff_id)
) ENGINE=InnoDB;

SET FOREIGN_KEY_CHECKS = 1;