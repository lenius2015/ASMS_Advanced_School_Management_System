-- =====================================================================
-- ASMS Test Users for All Roles
-- Run this AFTER the main schema and seed_data have been applied.
-- Usernames ARE the email addresses (login with email).
-- Default password for ALL users: test1234
-- =====================================================================
-- CORRECT HASH (generated 26 June 2026):
-- password_hash('test1234', PASSWORD_BCRYPT) = $2y$10$CEV.5FwW.Iv0w6bCiISnt.W0QpD2rfiYNWBLGuDWtUmz6sXKtABi6
-- =====================================================================

-- role_ids: 1=school_board, 2=director, 3=head_of_school, 4=bursar,
--           5=department_head, 6=academic_officer, 7=subject_teacher,
--           8=class_teacher, 9=parent, 10=student, 11=system_admin

-- =====================================================================
-- 1. DIRECTOR (login: director@omunju.ac.tz / test1234)
-- =====================================================================
INSERT IGNORE INTO users (uuid, role_id, username, email, phone, password_hash, first_name, last_name, gender, is_active, must_change_password)
SELECT UUID(), 2, 'director@omunju.ac.tz', 'director@omunju.ac.tz', '+255712100001', '$2y$10$CEV.5FwW.Iv0w6bCiISnt.W0QpD2rfiYNWBLGuDWtUmz6sXKtABi6', 'John', 'Mushi', 'male', 1, 0
WHERE NOT EXISTS (SELECT 1 FROM users WHERE username = 'director@omunju.ac.tz');

INSERT IGNORE INTO staff (user_id, staff_no, department_id, job_title, employment_type, date_hired, status)
SELECT u.user_id, 'STF-2026-0001', (SELECT department_id FROM departments ORDER BY department_id LIMIT 1), 'School Director', 'full_time', '2024-01-15', 'active'
FROM users u WHERE u.username = 'director@omunju.ac.tz'
AND NOT EXISTS (SELECT 1 FROM staff s WHERE s.user_id = u.user_id);

-- =====================================================================
-- 2. HEAD OF SCHOOL (login: headofschool@omunju.ac.tz / test1234)
-- =====================================================================
INSERT IGNORE INTO users (uuid, role_id, username, email, phone, password_hash, first_name, last_name, gender, is_active, must_change_password)
SELECT UUID(), 3, 'headofschool@omunju.ac.tz', 'headofschool@omunju.ac.tz', '+255712100002', '$2y$10$CEV.5FwW.Iv0w6bCiISnt.W0QpD2rfiYNWBLGuDWtUmz6sXKtABi6', 'Amina', 'Kisanga', 'female', 1, 0
WHERE NOT EXISTS (SELECT 1 FROM users WHERE username = 'headofschool@omunju.ac.tz');

INSERT IGNORE INTO staff (user_id, staff_no, department_id, job_title, employment_type, date_hired, status)
SELECT u.user_id, 'STF-2026-0002', (SELECT department_id FROM departments ORDER BY department_id LIMIT 1), 'Head of School', 'full_time', '2024-02-01', 'active'
FROM users u WHERE u.username = 'headofschool@omunju.ac.tz'
AND NOT EXISTS (SELECT 1 FROM staff s WHERE s.user_id = u.user_id);

-- =====================================================================
-- 3. BURSAR (login: bursar@omunju.ac.tz / test1234)
-- =====================================================================
INSERT IGNORE INTO users (uuid, role_id, username, email, phone, password_hash, first_name, last_name, gender, is_active, must_change_password)
SELECT UUID(), 4, 'bursar@omunju.ac.tz', 'bursar@omunju.ac.tz', '+255712100003', '$2y$10$CEV.5FwW.Iv0w6bCiISnt.W0QpD2rfiYNWBLGuDWtUmz6sXKtABi6', 'Peter', 'Mwakalinga', 'male', 1, 0
WHERE NOT EXISTS (SELECT 1 FROM users WHERE username = 'bursar@omunju.ac.tz');

INSERT IGNORE INTO staff (user_id, staff_no, department_id, job_title, employment_type, date_hired, status)
SELECT u.user_id, 'STF-2026-0003', NULL, 'School Bursar', 'full_time', '2024-03-01', 'active'
FROM users u WHERE u.username = 'bursar@omunju.ac.tz'
AND NOT EXISTS (SELECT 1 FROM staff s WHERE s.user_id = u.user_id);

-- =====================================================================
-- 4. ACADEMIC OFFICER (login: academic@omunju.ac.tz / test1234)
-- =====================================================================
INSERT IGNORE INTO users (uuid, role_id, username, email, phone, password_hash, first_name, last_name, gender, is_active, must_change_password)
SELECT UUID(), 6, 'academic@omunju.ac.tz', 'academic@omunju.ac.tz', '+255712100004', '$2y$10$CEV.5FwW.Iv0w6bCiISnt.W0QpD2rfiYNWBLGuDWtUmz6sXKtABi6', 'Grace', 'Mbelwa', 'female', 1, 0
WHERE NOT EXISTS (SELECT 1 FROM users WHERE username = 'academic@omunju.ac.tz');

INSERT IGNORE INTO staff (user_id, staff_no, department_id, job_title, employment_type, date_hired, status)
SELECT u.user_id, 'STF-2026-0004', (SELECT department_id FROM departments ORDER BY department_id LIMIT 1), 'Academic Officer', 'full_time', '2024-01-20', 'active'
FROM users u WHERE u.username = 'academic@omunju.ac.tz'
AND NOT EXISTS (SELECT 1 FROM staff s WHERE s.user_id = u.user_id);

-- =====================================================================
-- 5. SUBJECT TEACHERS (login: juma.mwita@omunju.ac.tz / test1234, etc.)
-- =====================================================================
INSERT IGNORE INTO users (uuid, role_id, username, email, phone, password_hash, first_name, last_name, gender, is_active, must_change_password)
SELECT UUID(), 7, 'juma.mwita@omunju.ac.tz', 'juma.mwita@omunju.ac.tz', '+255712100005', '$2y$10$CEV.5FwW.Iv0w6bCiISnt.W0QpD2rfiYNWBLGuDWtUmz6sXKtABi6', 'Juma', 'Mwita', 'male', 1, 0
WHERE NOT EXISTS (SELECT 1 FROM users WHERE username = 'juma.mwita@omunju.ac.tz');

INSERT IGNORE INTO staff (user_id, staff_no, department_id, job_title, employment_type, date_hired, status)
SELECT u.user_id, 'STF-2026-0005', (SELECT department_id FROM departments ORDER BY department_id LIMIT 1), 'Mathematics Teacher', 'full_time', '2024-09-01', 'active'
FROM users u WHERE u.username = 'juma.mwita@omunju.ac.tz'
AND NOT EXISTS (SELECT 1 FROM staff s WHERE s.user_id = u.user_id);

INSERT IGNORE INTO users (uuid, role_id, username, email, phone, password_hash, first_name, last_name, gender, is_active, must_change_password)
SELECT UUID(), 7, 'neema.chuwa@omunju.ac.tz', 'neema.chuwa@omunju.ac.tz', '+255712100006', '$2y$10$CEV.5FwW.Iv0w6bCiISnt.W0QpD2rfiYNWBLGuDWtUmz6sXKtABi6', 'Neema', 'Chuwa', 'female', 1, 0
WHERE NOT EXISTS (SELECT 1 FROM users WHERE username = 'neema.chuwa@omunju.ac.tz');

INSERT IGNORE INTO staff (user_id, staff_no, department_id, job_title, employment_type, date_hired, status)
SELECT u.user_id, 'STF-2026-0006', (SELECT department_id FROM departments ORDER BY department_id LIMIT 1), 'English Teacher', 'full_time', '2024-09-01', 'active'
FROM users u WHERE u.username = 'neema.chuwa@omunju.ac.tz'
AND NOT EXISTS (SELECT 1 FROM staff s WHERE s.user_id = u.user_id);

INSERT IGNORE INTO users (uuid, role_id, username, email, phone, password_hash, first_name, last_name, gender, is_active, must_change_password)
SELECT UUID(), 7, 'baraka.mganga@omunju.ac.tz', 'baraka.mganga@omunju.ac.tz', '+255712100007', '$2y$10$CEV.5FwW.Iv0w6bCiISnt.W0QpD2rfiYNWBLGuDWtUmz6sXKtABi6', 'Baraka', 'Mganga', 'male', 1, 0
WHERE NOT EXISTS (SELECT 1 FROM users WHERE username = 'baraka.mganga@omunju.ac.tz');

INSERT IGNORE INTO staff (user_id, staff_no, department_id, job_title, employment_type, date_hired, status)
SELECT u.user_id, 'STF-2026-0007', (SELECT department_id FROM departments ORDER BY department_id LIMIT 1), 'Science Teacher', 'full_time', '2024-09-01', 'active'
FROM users u WHERE u.username = 'baraka.mganga@omunju.ac.tz'
AND NOT EXISTS (SELECT 1 FROM staff s WHERE s.user_id = u.user_id);

-- =====================================================================
-- 6. CLASS TEACHER (login: asha.mtenzi@omunju.ac.tz / test1234)
-- =====================================================================
INSERT IGNORE INTO users (uuid, role_id, username, email, phone, password_hash, first_name, last_name, gender, is_active, must_change_password)
SELECT UUID(), 8, 'asha.mtenzi@omunju.ac.tz', 'asha.mtenzi@omunju.ac.tz', '+255712100008', '$2y$10$CEV.5FwW.Iv0w6bCiISnt.W0QpD2rfiYNWBLGuDWtUmz6sXKtABi6', 'Asha', 'Mtenzi', 'female', 1, 0
WHERE NOT EXISTS (SELECT 1 FROM users WHERE username = 'asha.mtenzi@omunju.ac.tz');

INSERT IGNORE INTO staff (user_id, staff_no, department_id, job_title, employment_type, date_hired, status)
SELECT u.user_id, 'STF-2026-0008', (SELECT department_id FROM departments ORDER BY department_id LIMIT 1), 'Class Teacher - Form 1A', 'full_time', '2024-09-01', 'active'
FROM users u WHERE u.username = 'asha.mtenzi@omunju.ac.tz'
AND NOT EXISTS (SELECT 1 FROM staff s WHERE s.user_id = u.user_id);

-- =====================================================================
-- 7. STUDENTS (login: abdul.juma@student.omunju.ac.tz / test1234, etc.)
-- =====================================================================
INSERT IGNORE INTO users (uuid, role_id, username, email, password_hash, first_name, last_name, gender, is_active, must_change_password)
SELECT UUID(), 10, 'abdul.juma@student.omunju.ac.tz', 'abdul.juma@student.omunju.ac.tz', '$2y$10$CEV.5FwW.Iv0w6bCiISnt.W0QpD2rfiYNWBLGuDWtUmz6sXKtABi6', 'Abdul', 'Juma', 'male', 1, 0
WHERE NOT EXISTS (SELECT 1 FROM users WHERE username = 'abdul.juma@student.omunju.ac.tz');

INSERT IGNORE INTO students (user_id, admission_no, gender, admission_date, status)
SELECT u.user_id, 'STU-2026-0011', 'male', CURDATE(), 'active'
FROM users u WHERE u.username = 'abdul.juma@student.omunju.ac.tz'
AND NOT EXISTS (SELECT 1 FROM students s WHERE s.user_id = u.user_id);

INSERT IGNORE INTO users (uuid, role_id, username, email, password_hash, first_name, last_name, gender, is_active, must_change_password)
SELECT UUID(), 10, 'mary.kassim@student.omunju.ac.tz', 'mary.kassim@student.omunju.ac.tz', '$2y$10$CEV.5FwW.Iv0w6bCiISnt.W0QpD2rfiYNWBLGuDWtUmz6sXKtABi6', 'Mary', 'Kassim', 'female', 1, 0
WHERE NOT EXISTS (SELECT 1 FROM users WHERE username = 'mary.kassim@student.omunju.ac.tz');

INSERT IGNORE INTO students (user_id, admission_no, gender, admission_date, status)
SELECT u.user_id, 'STU-2026-0012', 'female', CURDATE(), 'active'
FROM users u WHERE u.username = 'mary.kassim@student.omunju.ac.tz'
AND NOT EXISTS (SELECT 1 FROM students s WHERE s.user_id = u.user_id);

-- =====================================================================
-- 8. PARENTS (login: hassan.mwinyi@family.co.tz / test1234, etc.)
-- =====================================================================
INSERT IGNORE INTO users (uuid, role_id, username, email, phone, password_hash, first_name, last_name, gender, is_active, must_change_password)
SELECT UUID(), 9, 'hassan.mwinyi@family.co.tz', 'hassan.mwinyi@family.co.tz', '+255712100009', '$2y$10$CEV.5FwW.Iv0w6bCiISnt.W0QpD2rfiYNWBLGuDWtUmz6sXKtABi6', 'Hassan', 'Mwinyi', 'male', 1, 0
WHERE NOT EXISTS (SELECT 1 FROM users WHERE username = 'hassan.mwinyi@family.co.tz');

INSERT IGNORE INTO guardians (user_id, first_name, last_name, relationship, phone, email)
SELECT u.user_id, 'Hassan', 'Mwinyi', 'father', '+255712100009', 'hassan.mwinyi@family.co.tz'
FROM users u WHERE u.username = 'hassan.mwinyi@family.co.tz'
AND NOT EXISTS (SELECT 1 FROM guardians g WHERE g.user_id = u.user_id);

INSERT IGNORE INTO users (uuid, role_id, username, email, phone, password_hash, first_name, last_name, gender, is_active, must_change_password)
SELECT UUID(), 9, 'zainab.said@family.co.tz', 'zainab.said@family.co.tz', '+255712100010', '$2y$10$CEV.5FwW.Iv0w6bCiISnt.W0QpD2rfiYNWBLGuDWtUmz6sXKtABi6', 'Zainab', 'Said', 'female', 1, 0
WHERE NOT EXISTS (SELECT 1 FROM users WHERE username = 'zainab.said@family.co.tz');

INSERT IGNORE INTO guardians (user_id, first_name, last_name, relationship, phone, email)
SELECT u.user_id, 'Zainab', 'Said', 'mother', '+255712100010', 'zainab.said@family.co.tz'
FROM users u WHERE u.username = 'zainab.said@family.co.tz'
AND NOT EXISTS (SELECT 1 FROM guardians g WHERE g.user_id = u.user_id);

-- Link parents to students
INSERT IGNORE INTO student_guardians (student_id, guardian_id, is_primary_contact)
SELECT s.student_id, g.guardian_id, 1
FROM students s, guardians g
WHERE s.user_id = (SELECT user_id FROM users WHERE username = 'abdul.juma@student.omunju.ac.tz')
AND g.user_id = (SELECT user_id FROM users WHERE username = 'hassan.mwinyi@family.co.tz')
AND NOT EXISTS (SELECT 1 FROM student_guardians sg WHERE sg.student_id = s.student_id AND sg.guardian_id = g.guardian_id);

INSERT IGNORE INTO student_guardians (student_id, guardian_id, is_primary_contact)
SELECT s.student_id, g.guardian_id, 1
FROM students s, guardians g
WHERE s.user_id = (SELECT user_id FROM users WHERE username = 'mary.kassim@student.omunju.ac.tz')
AND g.user_id = (SELECT user_id FROM users WHERE username = 'zainab.said@family.co.tz')
AND NOT EXISTS (SELECT 1 FROM student_guardians sg WHERE sg.student_id = s.student_id AND sg.guardian_id = g.guardian_id);