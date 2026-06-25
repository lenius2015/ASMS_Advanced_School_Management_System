-- =====================================================================
-- ASMS - SEED DATA
-- Default accounts (CHANGE PASSWORDS IMMEDIATELY AFTER FIRST LOGIN)
-- Password for all seeded accounts below is:  test@2026
-- The hash below is a bcrypt hash of that string, generated via PHP password_hash().
-- =====================================================================

USE asms_db;

-- ---------------------------------------------------------------------
-- Academic Year & Term
-- ---------------------------------------------------------------------
INSERT INTO academic_years (year_name, start_date, end_date, is_current) VALUES
('2026', '2026-01-06', '2026-11-27', 1);

INSERT INTO terms (year_id, term_name, start_date, end_date, is_current) VALUES
(1, 'Term 1', '2026-01-06', '2026-04-10', 1),
(1, 'Term 2', '2026-04-28', '2026-08-07', 0),
(1, 'Term 3', '2026-08-25', '2026-11-27', 0);

UPDATE system_settings SET setting_value = '1' WHERE setting_key = 'current_academic_year_id';
UPDATE system_settings SET setting_value = '1' WHERE setting_key = 'current_term_id';

-- ---------------------------------------------------------------------
-- Class levels & a starter class
-- ---------------------------------------------------------------------
INSERT INTO class_levels (level_name, sort_order) VALUES
('Form 1', 1), ('Form 2', 2), ('Form 3', 3), ('Form 4', 4);

INSERT INTO classes (class_level_id, stream_name, year_id, capacity) VALUES
(1, 'A', 1, 40),
(2, 'A', 1, 40);

-- ---------------------------------------------------------------------
-- Subjects
-- ---------------------------------------------------------------------
INSERT INTO subjects (subject_name, subject_code) VALUES
('Mathematics', 'MATH'),
('English', 'ENG'),
('Kiswahili', 'KIS'),
('Biology', 'BIO'),
('Chemistry', 'CHEM'),
('Physics', 'PHY'),
('Geography', 'GEO'),
('History', 'HIST'),
('Computer Studies', 'COMP');

-- ---------------------------------------------------------------------
-- Exam types & grade scale
-- ---------------------------------------------------------------------
INSERT INTO exam_types (type_name, weight_percent) VALUES
('Assignment', 10.00),
('Test', 20.00),
('Mid-Term Exam', 30.00),
('Final Exam', 40.00);

INSERT INTO grade_scale (grade_letter, min_score, max_score, gpa_value, remarks) VALUES
('A', 80.00, 100.00, 4.00, 'Excellent'),
('B', 70.00, 79.99, 3.00, 'Very Good'),
('C', 60.00, 69.99, 2.00, 'Good'),
('D', 50.00, 59.99, 1.00, 'Average'),
('E', 40.00, 49.99, 0.50, 'Below Average'),
('F', 0.00, 39.99, 0.00, 'Fail');

-- ---------------------------------------------------------------------
-- Departments
-- ---------------------------------------------------------------------
INSERT INTO departments (department_name, description) VALUES
('Academic', 'Manages academic programs, exams, and results'),
('Finance', 'Manages school finances, fees, and payroll'),
('Infrastructure', 'Manages buildings, assets, and maintenance'),
('Environment', 'Manages environmental and beautification projects'),
('Social Welfare', 'Manages student welfare and counseling'),
('Health', 'Manages student and staff health records');

-- ---------------------------------------------------------------------
-- Fee categories
-- ---------------------------------------------------------------------
INSERT INTO fee_categories (category_name) VALUES
('Tuition Fee'), ('Transport Fee'), ('Boarding Fee'), ('Examination Fee'), ('Uniform Fee'), ('Library Fee');

-- ---------------------------------------------------------------------
-- Default user accounts (one per role) for first login & testing
-- Username / password pattern: <role>.demo / test@2026
-- You can also login using the email address as username.
-- ---------------------------------------------------------------------
-- IMPORTANT: Run database/generate_seed_hash.php to generate a fresh
-- bcrypt hash on your server before importing this SQL. The hash below
-- may not be compatible with all PHP versions/environments.
SET @pwd_hash = '$2y$10$NZ1zubf6JIVRUKz1BxeW6eLD3kWiW8Dq.rqt5k8Y8cLT8bLEE.SzW';

INSERT INTO users (uuid, role_id, username, email, phone, password_hash, first_name, last_name, gender, is_active, must_change_password)
VALUES
(UUID(), (SELECT role_id FROM roles WHERE role_name='director'),       'director.demo',     'director@example.com',     '+255700000001', @pwd_hash, 'Amani',  'Mwakasege', 'male',   1, 0),
(UUID(), (SELECT role_id FROM roles WHERE role_name='head_of_school'), 'headofschool.demo', 'headofschool@example.com', '+255700000002', @pwd_hash, 'Grace',  'Mushi',     'female', 1, 0),
(UUID(), (SELECT role_id FROM roles WHERE role_name='bursar'),         'bursar.demo',       'bursar@example.com',       '+255700000003', @pwd_hash, 'John',   'Komba',     'male',   1, 0),
(UUID(), (SELECT role_id FROM roles WHERE role_name='academic_officer'),'academic.demo',    'academic@example.com',     '+255700000004', @pwd_hash, 'Fatma',  'Salim',     'female', 1, 0),
(UUID(), (SELECT role_id FROM roles WHERE role_name='subject_teacher'),'teacher.demo',      'teacher@example.com',      '+255700000005', @pwd_hash, 'Peter',  'Ndege',     'male',   1, 0),
(UUID(), (SELECT role_id FROM roles WHERE role_name='class_teacher'),  'classteacher.demo', 'classteacher@example.com', '+255700000006', @pwd_hash, 'Mary',   'Lyimo',     'female', 1, 0),
(UUID(), (SELECT role_id FROM roles WHERE role_name='system_admin'),   'sysadmin.demo',     'sysadmin@example.com',     '+255700000007', @pwd_hash, 'Hassan', 'Juma',      'male',   1, 0),
(UUID(), (SELECT role_id FROM roles WHERE role_name='parent'),         'parent.demo',       'parent@example.com',       '+255700000008', @pwd_hash, 'Esther', 'Mollel',    'female', 1, 0),
(UUID(), (SELECT role_id FROM roles WHERE role_name='student'),        'student.demo',      'student@example.com',      '+255700000009', @pwd_hash, 'David',  'Mollel',    'male',   1, 0);

-- Link the class teacher account to the seeded class
UPDATE classes SET class_teacher_id = (SELECT user_id FROM users WHERE username = 'classteacher.demo') WHERE class_id = 1;

-- Link subject teacher to a class_subject
INSERT INTO class_subjects (class_id, subject_id, teacher_id) VALUES
(1, 1, (SELECT user_id FROM users WHERE username = 'teacher.demo')), -- Mathematics, Form 1A
(1, 2, NULL),
(1, 3, NULL);

-- Demo staff record for academic officer (so staff-linked features work)
INSERT INTO staff (user_id, staff_no, department_id, job_title, employment_type, date_hired, basic_salary, status)
VALUES
((SELECT user_id FROM users WHERE username='academic.demo'), 'STF-2026-0001', 1, 'Academic Officer', 'full_time', '2024-01-10', 1200000.00, 'active'),
((SELECT user_id FROM users WHERE username='teacher.demo'), 'STF-2026-0002', 1, 'Subject Teacher', 'full_time', '2023-09-01', 950000.00, 'active'),
((SELECT user_id FROM users WHERE username='classteacher.demo'), 'STF-2026-0003', 1, 'Class Teacher', 'full_time', '2022-02-15', 980000.00, 'active'),
((SELECT user_id FROM users WHERE username='bursar.demo'), 'STF-2026-0004', 2, 'Bursar', 'full_time', '2021-05-20', 1100000.00, 'active');

-- Demo guardian + student (David Mollel, child of Esther Mollel) for portal testing
INSERT INTO guardians (user_id, first_name, last_name, relationship, phone, email)
VALUES ((SELECT user_id FROM users WHERE username='parent.demo'), 'Esther', 'Mollel', 'mother', '+255700000008', 'parent@example.com');

INSERT INTO students (user_id, admission_no, class_id, date_of_birth, gender, admission_date, status)
VALUES ((SELECT user_id FROM users WHERE username='student.demo'), 'STU-2026-0001', 1, '2011-03-14', 'male', '2026-01-06', 'active');

INSERT INTO student_guardians (student_id, guardian_id, is_primary_contact)
VALUES (1, 1, 1);

-- Seed the ID sequence counters to match the demo records already created
INSERT INTO id_sequences (seq_key, last_value) VALUES
('STU-2026', 1),
('STF-2026', 4);

-- Sample invoice for the demo student
INSERT INTO invoices (student_id, term_id, invoice_no, total_amount, amount_paid, balance, due_date, status)
VALUES (1, 1, 'INV-2026-000001', 450000.00, 200000.00, 250000.00, '2026-02-15', 'partial');

INSERT INTO invoice_items (invoice_id, fee_category_id, description, amount) VALUES
(1, 1, 'Term 1 Tuition Fee', 400000.00),
(1, 4, 'Examination Fee', 50000.00);

INSERT INTO payments (invoice_id, student_id, amount, payment_method, reference_no, payment_date, received_by)
VALUES (1, 1, 200000.00, 'mobile_money', 'MM-TXN-00231', '2026-01-15', (SELECT user_id FROM users WHERE username='bursar.demo'));
</write_to_file>
<task_progress>
- [x] Generate new password hash for test@2026
- [x] Update all users' passwords in database
- [x] Modify login/auth to accept email as username
- [x] Update seed_data.sql with new hash
- [ ] Update CREDENTIALS.md
- [ ] Clean up temp files
- [ ] Test login
</task_progress>
</write_to_file>