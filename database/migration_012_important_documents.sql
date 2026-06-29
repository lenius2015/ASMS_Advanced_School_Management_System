-- =====================================================================
-- ASMS Migration 012 - Important Documents & Identity Fields
-- Adds NIDA, Passport fields, Medical Records, and enhanced document types
-- =====================================================================

SET FOREIGN_KEY_CHECKS = 0;

-- 1. Add NIDA (National ID) and Passport fields to students table
ALTER TABLE students
  ADD COLUMN nida_number VARCHAR(30) DEFAULT NULL AFTER blood_group,
  ADD COLUMN passport_number VARCHAR(30) DEFAULT NULL AFTER nida_number,
  ADD COLUMN passport_expiry DATE DEFAULT NULL AFTER passport_number,
  ADD COLUMN passport_photo_path VARCHAR(255) DEFAULT NULL AFTER passport_expiry;

-- 2. Add NIDA and Passport fields to staff table
ALTER TABLE staff
  ADD COLUMN nida_number VARCHAR(30) DEFAULT NULL AFTER national_id,
  ADD COLUMN passport_number VARCHAR(30) DEFAULT NULL AFTER nida_number,
  ADD COLUMN passport_expiry DATE DEFAULT NULL AFTER passport_number,
  ADD COLUMN passport_photo_path VARCHAR(255) DEFAULT NULL AFTER passport_expiry;

-- 3. Create student_medical_records table for structured health data
CREATE TABLE IF NOT EXISTS student_medical_records (
    medical_id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    blood_group VARCHAR(5) DEFAULT NULL,
    allergies TEXT DEFAULT NULL COMMENT 'List of known allergies',
    chronic_conditions TEXT DEFAULT NULL COMMENT 'Asthma, diabetes, etc.',
    medications TEXT DEFAULT NULL COMMENT 'Current medications',
    disability_info TEXT DEFAULT NULL COMMENT 'Any disabilities or special needs',
    doctor_name VARCHAR(120) DEFAULT NULL,
    doctor_phone VARCHAR(20) DEFAULT NULL,
    doctor_address TEXT DEFAULT NULL,
    hospital_preference VARCHAR(150) DEFAULT NULL,
    health_insurance_provider VARCHAR(100) DEFAULT NULL,
    health_insurance_no VARCHAR(40) DEFAULT NULL,
    emergency_notes TEXT DEFAULT NULL,
    last_checkup_date DATE DEFAULT NULL,
    updated_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE,
    FOREIGN KEY (updated_by) REFERENCES users(user_id) ON DELETE SET NULL,
    UNIQUE KEY uq_student_medical (student_id),
    INDEX idx_medical_student (student_id)
) ENGINE=InnoDB;

-- 4. Add new document types to student_documents (alter ENUM)
ALTER TABLE student_documents
  MODIFY COLUMN document_type ENUM(
      'birth_certificate',
      'medical_checkup',
      'transfer_slip',
      'previous_results',
      'passport_photo',
      'guardian_id',
      'nida_card',
      'passport_copy',
      'vaccination_card',
      'parent_consent',
      'fee_agreement',
      'other'
  ) NOT NULL DEFAULT 'other';

-- 5. Add new document types to staff_documents (alter ENUM)
ALTER TABLE staff_documents
  MODIFY COLUMN document_type ENUM(
      'cv',
      'certificate',
      'degree',
      'transcript',
      'professional_cert',
      'id_copy',
      'recommendation',
      'appointment_letter',
      'contract',
      'nida_card',
      'passport_copy',
      'bank_statement',
      'tax_clearance',
      'medical_report',
      'other'
  ) NOT NULL DEFAULT 'other';

-- 6. Add passport_photo_path to users table for all user types
ALTER TABLE users
  ADD COLUMN passport_photo_path VARCHAR(255) DEFAULT NULL AFTER photo_path;

SET FOREIGN_KEY_CHECKS = 1;