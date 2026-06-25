-- =====================================================================
-- ASMS Migration 005 - Student Documents & Profile Enhancement
-- Adds student_documents table for storing student attachments
-- such as birth certificates, medical checkup forms, etc.
-- =====================================================================

SET FOREIGN_KEY_CHECKS = 0;

-- 1. Create student_documents table
CREATE TABLE IF NOT EXISTS student_documents (
    document_id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    document_type ENUM(
        'birth_certificate',
        'medical_checkup',
        'transfer_slip',
        'previous_results',
        'passport_photo',
        'guardian_id',
        'other'
    ) NOT NULL DEFAULT 'other',
    document_name VARCHAR(150) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    file_size INT DEFAULT NULL COMMENT 'File size in bytes',
    mime_type VARCHAR(80) DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    uploaded_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE,
    FOREIGN KEY (uploaded_by) REFERENCES users(user_id) ON DELETE SET NULL,
    INDEX idx_studentdocs_student (student_id)
) ENGINE=InnoDB;

-- 2. Add registration_completeness column to students table
ALTER TABLE students
  ADD COLUMN registration_complete TINYINT(1) NOT NULL DEFAULT 0
  AFTER address;

SET FOREIGN_KEY_CHECKS = 1;