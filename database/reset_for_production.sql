-- =============================================================================
-- ASMS Database Reset Script
-- Clears ALL seed/demo/test data while preserving:
--   roles, permissions, role_permissions
--   class_levels, subjects, departments, fee_categories
--   grade_scale, exam_types
--   academic_years, terms, system_settings
--   payment_gateways (seeded config only)
--
-- After running this, ONLY the Director user remains.
-- Password: admin123  |  Login: director@omunju.ac.tz
-- =============================================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ===== 1. DELETE ALL TRANSACTIONAL DATA (FK order) =====
DELETE FROM lesson_attendance_students;
DELETE FROM lesson_attendance;
DELETE FROM exam_marks;
DELETE FROM timetable;
DELETE FROM class_subjects;
DELETE FROM student_promotions;
DELETE FROM student_fee_accounts;
DELETE FROM student_guardians;
DELETE FROM student_attendance;
DELETE FROM discipline_records;
DELETE FROM student_documents;
DELETE FROM students;
DELETE FROM guardians;
DELETE FROM invoice_items;
DELETE FROM control_numbers;
DELETE FROM payment_transactions;
DELETE FROM payments;
DELETE FROM invoices;
DELETE FROM payslip_items;
DELETE FROM payslips;
DELETE FROM payroll_runs;
DELETE FROM expenses;
DELETE FROM budgets;
DELETE FROM fee_reminders;
DELETE FROM fee_structures;
DELETE FROM classes;
DELETE FROM exams;
DELETE FROM announcements;
DELETE FROM messages;
DELETE FROM notifications;
DELETE FROM staff_documents;
DELETE FROM staff_certificates;
DELETE FROM staff_qualifications;
DELETE FROM staff_emergency_contacts;
DELETE FROM staff_leave;
DELETE FROM staff_performance;
DELETE FROM staff_trainings;
DELETE FROM staff_attendance;
DELETE FROM staff;
DELETE FROM login_activity;
DELETE FROM password_resets;
DELETE FROM audit_logs;
DELETE FROM deletion_requests;
DELETE FROM users;
DELETE FROM report_cards;
DELETE FROM term_results;
DELETE FROM payment_api_logs;
DELETE FROM id_sequences;
-- ===== 2. RESET AUTO-INCREMENT COUNTERS =====
ALTER TABLE users AUTO_INCREMENT = 1;
ALTER TABLE staff AUTO_INCREMENT = 1;
ALTER TABLE students AUTO_INCREMENT = 1;
ALTER TABLE guardians AUTO_INCREMENT = 1;
ALTER TABLE classes AUTO_INCREMENT = 1;
ALTER TABLE class_subjects AUTO_INCREMENT = 1;
ALTER TABLE invoices AUTO_INCREMENT = 1;
ALTER TABLE payments AUTO_INCREMENT = 1;
ALTER TABLE exams AUTO_INCREMENT = 1;
ALTER TABLE exam_marks AUTO_INCREMENT = 1;

-- ===== 3. RESET ID SEQUENCES =====
INSERT INTO id_sequences (seq_key, last_value) VALUES
('STU-2026', 0),
('STF-2026', 0)
ON DUPLICATE KEY UPDATE last_value = 0;

-- ===== 4. CREATE THE DIRECTOR USER =====
INSERT INTO users (uuid, role_id, username, email, phone, password_hash, first_name, last_name, gender, is_active, must_change_password)
VALUES (
    UUID(),
    (SELECT role_id FROM roles WHERE role_name = 'director'),
    'director@omunju.ac.tz',
    'director@omunju.ac.tz',
    '+255712100001',
    '$2y$10$8gb2MvjJZo7glB56rVXluuGBrBba1EhfcHYYxpcPq1lTJQ7tLghOm',
    'John',
    'Mushi',
    'male',
    1,
    0
);

-- Create the director's staff record
INSERT INTO staff (user_id, staff_no, department_id, job_title, employment_type, date_hired, status)
SELECT u.user_id, 'STF-2026-0001', d.department_id, 'School Director', 'full_time', '2026-01-01', 'active'
FROM (SELECT user_id FROM users WHERE username = 'director@omunju.ac.tz') u
CROSS JOIN (SELECT department_id FROM departments WHERE department_name IN ('Academic','Academic Department') LIMIT 1) d;

UPDATE id_sequences SET last_value = 1 WHERE seq_key = 'STF-2026';
-- ===== 5. UPDATE CURRENT TERM TO TERM 1 (fresh start) =====
UPDATE terms SET is_current = 0 WHERE is_current = 1;
UPDATE terms SET is_current = 1 WHERE term_id = 1;
UPDATE system_settings SET setting_value = '1' WHERE setting_key = 'current_term_id';

-- ===== 6. RE-ENABLE FOREIGN KEY CHECKS =====
SET FOREIGN_KEY_CHECKS = 1;

-- ===== 7. DONE =====
SELECT 'Database reset complete!' AS status;
SELECT 'Director login: director@omunju.ac.tz / admin123' AS credentials;
SELECT CONCAT('Users: ', (SELECT COUNT(*) FROM users)) AS users;
SELECT CONCAT('Staff: ', (SELECT COUNT(*) FROM staff)) AS staff;
SELECT CONCAT('Subjects: ', (SELECT COUNT(*) FROM subjects)) AS subjects;
SELECT CONCAT('Class Levels: ', (SELECT COUNT(*) FROM class_levels)) AS class_levels;
SELECT CONCAT('Departments: ', (SELECT COUNT(*) FROM departments)) AS departments;
