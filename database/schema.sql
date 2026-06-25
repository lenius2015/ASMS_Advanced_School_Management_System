-- =====================================================================
-- ADVANCED SCHOOL MANAGEMENT SYSTEM (ASMS)
-- Database Schema - MySQL 8.0+
-- Follows 3NF, uses Foreign Keys, Indexes, and Audit Tables
-- =====================================================================

SET FOREIGN_KEY_CHECKS = 0;
SET sql_mode = 'NO_ENGINE_SUBSTITUTION';

CREATE DATABASE IF NOT EXISTS asms_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE asms_db;

-- =====================================================================
-- 1. ROLES & PERMISSIONS (RBAC core)
-- =====================================================================

CREATE TABLE roles (
    role_id INT AUTO_INCREMENT PRIMARY KEY,
    role_name VARCHAR(50) NOT NULL UNIQUE,
    role_description VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Fixed system roles. Code references these by name, so do not rename casually.
INSERT INTO roles (role_name, role_description) VALUES
('school_board', 'School Board Member - governance and oversight'),
('director', 'School Director - Super Administrator, full system access'),
('head_of_school', 'Head of School - Administrator, day-to-day operations'),
('bursar', 'Bursar - Finance management'),
('department_head', 'Department Head - manages a specific department'),
('academic_officer', 'Academic Department staff - manages academics, exams, results'),
('subject_teacher', 'Subject Teacher - records marks and manages lessons'),
('class_teacher', 'Class Teacher - manages a specific class/homeroom'),
('parent', 'Parent/Guardian - views child progress'),
('student', 'Student - views own academic data'),
('system_admin', 'System Administrator - manages users, roles, system settings');

CREATE TABLE permissions (
    permission_id INT AUTO_INCREMENT PRIMARY KEY,
    permission_key VARCHAR(100) NOT NULL UNIQUE,
    description VARCHAR(255) DEFAULT NULL
) ENGINE=InnoDB;

CREATE TABLE role_permissions (
    role_id INT NOT NULL,
    permission_id INT NOT NULL,
    PRIMARY KEY (role_id, permission_id),
    FOREIGN KEY (role_id) REFERENCES roles(role_id) ON DELETE CASCADE,
    FOREIGN KEY (permission_id) REFERENCES permissions(permission_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- =====================================================================
-- 2. USERS (single login table for every human in the system)
-- =====================================================================

CREATE TABLE users (
    user_id INT AUTO_INCREMENT PRIMARY KEY,
    uuid VARCHAR(36) NOT NULL UNIQUE,
    role_id INT NOT NULL,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(120) DEFAULT NULL UNIQUE,
    phone VARCHAR(20) DEFAULT NULL,
    password_hash VARCHAR(255) NOT NULL,
    first_name VARCHAR(80) NOT NULL,
    last_name VARCHAR(80) NOT NULL,
    gender ENUM('male','female','other') DEFAULT NULL,
    photo_path VARCHAR(255) DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    must_change_password TINYINT(1) NOT NULL DEFAULT 1,
    two_factor_enabled TINYINT(1) NOT NULL DEFAULT 0,
    two_factor_secret VARCHAR(64) DEFAULT NULL,
    failed_login_attempts INT NOT NULL DEFAULT 0,
    locked_until DATETIME DEFAULT NULL,
    last_login_at DATETIME DEFAULT NULL,
    last_login_ip VARCHAR(45) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (role_id) REFERENCES roles(role_id) ON DELETE RESTRICT,
    INDEX idx_users_role (role_id),
    INDEX idx_users_active (is_active)
) ENGINE=InnoDB;

CREATE TABLE password_resets (
    reset_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token_hash VARCHAR(255) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    INDEX idx_pwreset_user (user_id)
) ENGINE=InnoDB;

CREATE TABLE login_activity (
    login_id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id INT DEFAULT NULL,
    username_attempted VARCHAR(50) DEFAULT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    user_agent VARCHAR(255) DEFAULT NULL,
    status ENUM('success','failed_password','failed_locked','failed_inactive','logout') NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE SET NULL,
    INDEX idx_login_user (user_id),
    INDEX idx_login_created (created_at)
) ENGINE=InnoDB;

CREATE TABLE audit_logs (
    audit_id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id INT DEFAULT NULL,
    action VARCHAR(100) NOT NULL,
    module VARCHAR(60) NOT NULL,
    record_table VARCHAR(60) DEFAULT NULL,
    record_id INT DEFAULT NULL,
    description TEXT DEFAULT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE SET NULL,
    INDEX idx_audit_user (user_id),
    INDEX idx_audit_module (module),
    INDEX idx_audit_created (created_at)
) ENGINE=InnoDB;

-- =====================================================================
-- 3. ACADEMIC STRUCTURE: Academic Years, Terms, Classes, Streams, Subjects
-- =====================================================================

CREATE TABLE academic_years (
    year_id INT AUTO_INCREMENT PRIMARY KEY,
    year_name VARCHAR(20) NOT NULL UNIQUE, -- e.g. '2026'
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    is_current TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB;

CREATE TABLE terms (
    term_id INT AUTO_INCREMENT PRIMARY KEY,
    year_id INT NOT NULL,
    term_name VARCHAR(30) NOT NULL, -- e.g. 'Term 1'
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    is_current TINYINT(1) NOT NULL DEFAULT 0,
    FOREIGN KEY (year_id) REFERENCES academic_years(year_id) ON DELETE CASCADE,
    INDEX idx_terms_year (year_id)
) ENGINE=InnoDB;

CREATE TABLE class_levels (
    class_level_id INT AUTO_INCREMENT PRIMARY KEY,
    level_name VARCHAR(50) NOT NULL UNIQUE, -- e.g. 'Form 1', 'Grade 7'
    sort_order INT NOT NULL DEFAULT 0
) ENGINE=InnoDB;

CREATE TABLE classes (
    class_id INT AUTO_INCREMENT PRIMARY KEY,
    class_level_id INT NOT NULL,
    stream_name VARCHAR(30) NOT NULL DEFAULT 'A', -- e.g. 'A', 'Blue', 'North'
    year_id INT NOT NULL,
    class_teacher_id INT DEFAULT NULL, -- users.user_id where role = class_teacher
    capacity INT DEFAULT 40,
    FOREIGN KEY (class_level_id) REFERENCES class_levels(class_level_id) ON DELETE RESTRICT,
    FOREIGN KEY (year_id) REFERENCES academic_years(year_id) ON DELETE RESTRICT,
    FOREIGN KEY (class_teacher_id) REFERENCES users(user_id) ON DELETE SET NULL,
    UNIQUE KEY uq_class (class_level_id, stream_name, year_id),
    INDEX idx_classes_year (year_id)
) ENGINE=InnoDB;

CREATE TABLE subjects (
    subject_id INT AUTO_INCREMENT PRIMARY KEY,
    subject_name VARCHAR(80) NOT NULL,
    subject_code VARCHAR(20) NOT NULL UNIQUE,
    is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

-- Which subjects are taught in which class, and by whom
CREATE TABLE class_subjects (
    class_subject_id INT AUTO_INCREMENT PRIMARY KEY,
    class_id INT NOT NULL,
    subject_id INT NOT NULL,
    teacher_id INT DEFAULT NULL, -- users.user_id (subject_teacher)
    FOREIGN KEY (class_id) REFERENCES classes(class_id) ON DELETE CASCADE,
    FOREIGN KEY (subject_id) REFERENCES subjects(subject_id) ON DELETE CASCADE,
    FOREIGN KEY (teacher_id) REFERENCES users(user_id) ON DELETE SET NULL,
    UNIQUE KEY uq_class_subject (class_id, subject_id),
    INDEX idx_classsubj_teacher (teacher_id)
) ENGINE=InnoDB;

CREATE TABLE timetable (
    timetable_id INT AUTO_INCREMENT PRIMARY KEY,
    class_subject_id INT NOT NULL,
    day_of_week ENUM('Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday') NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    room VARCHAR(50) DEFAULT NULL,
    FOREIGN KEY (class_subject_id) REFERENCES class_subjects(class_subject_id) ON DELETE CASCADE,
    INDEX idx_timetable_day (day_of_week)
) ENGINE=InnoDB;

-- =====================================================================
-- 4. STUDENTS, GUARDIANS, STAFF
-- =====================================================================

CREATE TABLE students (
    student_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT DEFAULT NULL UNIQUE, -- nullable: student may not have login yet
    admission_no VARCHAR(30) NOT NULL UNIQUE, -- e.g. STU-2026-0001
    class_id INT DEFAULT NULL,
    date_of_birth DATE DEFAULT NULL,
    gender ENUM('male','female','other') DEFAULT NULL,
    admission_date DATE DEFAULT NULL,
    status ENUM('active','graduated','transferred','suspended','expelled','inactive') NOT NULL DEFAULT 'active',
    blood_group VARCHAR(5) DEFAULT NULL,
    address TEXT DEFAULT NULL,
    emergency_contact_name VARCHAR(120) DEFAULT NULL,
    emergency_contact_phone VARCHAR(20) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE SET NULL,
    FOREIGN KEY (class_id) REFERENCES classes(class_id) ON DELETE SET NULL,
    INDEX idx_students_class (class_id),
    INDEX idx_students_status (status)
) ENGINE=InnoDB;

CREATE TABLE guardians (
    guardian_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT DEFAULT NULL UNIQUE, -- parent portal login
    first_name VARCHAR(80) NOT NULL,
    last_name VARCHAR(80) NOT NULL,
    relationship ENUM('father','mother','guardian','other') NOT NULL DEFAULT 'guardian',
    phone VARCHAR(20) DEFAULT NULL,
    email VARCHAR(120) DEFAULT NULL,
    occupation VARCHAR(100) DEFAULT NULL,
    address TEXT DEFAULT NULL,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Many-to-many: a student can have multiple guardians; a guardian can have multiple students (siblings)
CREATE TABLE student_guardians (
    student_id INT NOT NULL,
    guardian_id INT NOT NULL,
    is_primary_contact TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (student_id, guardian_id),
    FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE,
    FOREIGN KEY (guardian_id) REFERENCES guardians(guardian_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE departments (
    department_id INT AUTO_INCREMENT PRIMARY KEY,
    department_name VARCHAR(80) NOT NULL UNIQUE, -- Academic, Infrastructure, Environment, Social Welfare, Health, Finance
    department_head_id INT DEFAULT NULL,
    description VARCHAR(255) DEFAULT NULL,
    FOREIGN KEY (department_head_id) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE staff (
    staff_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL UNIQUE,
    staff_no VARCHAR(30) NOT NULL UNIQUE, -- e.g. STF-2026-0001
    department_id INT DEFAULT NULL,
    job_title VARCHAR(100) DEFAULT NULL,
    employment_type ENUM('full_time','part_time','contract','volunteer') NOT NULL DEFAULT 'full_time',
    date_hired DATE DEFAULT NULL,
    basic_salary DECIMAL(14,2) DEFAULT 0.00,
    national_id VARCHAR(40) DEFAULT NULL,
    status ENUM('active','on_leave','suspended','terminated','retired') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (department_id) REFERENCES departments(department_id) ON DELETE SET NULL,
    INDEX idx_staff_department (department_id)
) ENGINE=InnoDB;

-- =====================================================================
-- 5. ATTENDANCE
-- =====================================================================

CREATE TABLE student_attendance (
    attendance_id BIGINT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    class_id INT NOT NULL,
    attendance_date DATE NOT NULL,
    status ENUM('present','absent','late','excused') NOT NULL DEFAULT 'present',
    recorded_by INT DEFAULT NULL, -- users.user_id (class teacher)
    remarks VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE,
    FOREIGN KEY (class_id) REFERENCES classes(class_id) ON DELETE CASCADE,
    FOREIGN KEY (recorded_by) REFERENCES users(user_id) ON DELETE SET NULL,
    UNIQUE KEY uq_attendance_day (student_id, attendance_date),
    INDEX idx_attendance_date (attendance_date),
    INDEX idx_attendance_class (class_id)
) ENGINE=InnoDB;

CREATE TABLE staff_attendance (
    attendance_id BIGINT AUTO_INCREMENT PRIMARY KEY,
    staff_id INT NOT NULL,
    attendance_date DATE NOT NULL,
    time_in TIME DEFAULT NULL,
    time_out TIME DEFAULT NULL,
    status ENUM('present','absent','late','on_leave') NOT NULL DEFAULT 'present',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (staff_id) REFERENCES staff(staff_id) ON DELETE CASCADE,
    UNIQUE KEY uq_staff_attendance_day (staff_id, attendance_date)
) ENGINE=InnoDB;

-- =====================================================================
-- 6. EXAMINATIONS & RESULTS (the core academic workflow)
-- =====================================================================

CREATE TABLE exam_types (
    exam_type_id INT AUTO_INCREMENT PRIMARY KEY,
    type_name VARCHAR(50) NOT NULL UNIQUE, -- 'Assignment','Test','Mid-Term','Final Exam'
    weight_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00 -- contribution to final mark
) ENGINE=InnoDB;

CREATE TABLE exams (
    exam_id INT AUTO_INCREMENT PRIMARY KEY,
    exam_type_id INT NOT NULL,
    term_id INT NOT NULL,
    exam_name VARCHAR(100) NOT NULL, -- e.g. 'Mid Term 1 Examination'
    start_date DATE DEFAULT NULL,
    end_date DATE DEFAULT NULL,
    max_marks DECIMAL(6,2) NOT NULL DEFAULT 100.00,
    status ENUM('scheduled','ongoing','marks_pending','submitted','verified','published') NOT NULL DEFAULT 'scheduled',
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (exam_type_id) REFERENCES exam_types(exam_type_id) ON DELETE RESTRICT,
    FOREIGN KEY (term_id) REFERENCES terms(term_id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE SET NULL,
    INDEX idx_exams_term (term_id)
) ENGINE=InnoDB;

-- Step 1 & 2: Subject Teacher enters and submits marks
CREATE TABLE exam_marks (
    mark_id BIGINT AUTO_INCREMENT PRIMARY KEY,
    exam_id INT NOT NULL,
    student_id INT NOT NULL,
    class_subject_id INT NOT NULL,
    marks_obtained DECIMAL(6,2) DEFAULT NULL,
    is_absent TINYINT(1) NOT NULL DEFAULT 0,
    entered_by INT DEFAULT NULL, -- subject teacher
    entered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    submitted_at TIMESTAMP NULL DEFAULT NULL, -- Step 2: submission to Academic Dept
    -- Step 3: verification by Academic Department
    verified_by INT DEFAULT NULL,
    verified_at TIMESTAMP NULL DEFAULT NULL,
    verification_status ENUM('pending','verified','rejected') NOT NULL DEFAULT 'pending',
    rejection_reason VARCHAR(255) DEFAULT NULL,
    FOREIGN KEY (exam_id) REFERENCES exams(exam_id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE,
    FOREIGN KEY (class_subject_id) REFERENCES class_subjects(class_subject_id) ON DELETE CASCADE,
    FOREIGN KEY (entered_by) REFERENCES users(user_id) ON DELETE SET NULL,
    FOREIGN KEY (verified_by) REFERENCES users(user_id) ON DELETE SET NULL,
    UNIQUE KEY uq_exam_mark (exam_id, student_id, class_subject_id),
    INDEX idx_exammarks_student (student_id),
    INDEX idx_exammarks_status (verification_status)
) ENGINE=InnoDB;

-- Grade scale used to compute letter grades from percentage
CREATE TABLE grade_scale (
    grade_id INT AUTO_INCREMENT PRIMARY KEY,
    grade_letter VARCHAR(5) NOT NULL,
    min_score DECIMAL(5,2) NOT NULL,
    max_score DECIMAL(5,2) NOT NULL,
    gpa_value DECIMAL(3,2) NOT NULL,
    remarks VARCHAR(100) DEFAULT NULL
) ENGINE=InnoDB;

-- Step 4: System-calculated aggregate per student per subject per term
CREATE TABLE term_results (
    result_id BIGINT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    class_subject_id INT NOT NULL,
    term_id INT NOT NULL,
    total_marks DECIMAL(6,2) DEFAULT NULL,
    average_marks DECIMAL(6,2) DEFAULT NULL,
    grade_letter VARCHAR(5) DEFAULT NULL,
    gpa DECIMAL(3,2) DEFAULT NULL,
    subject_position INT DEFAULT NULL,
    remarks VARCHAR(255) DEFAULT NULL,
    computed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE,
    FOREIGN KEY (class_subject_id) REFERENCES class_subjects(class_subject_id) ON DELETE CASCADE,
    FOREIGN KEY (term_id) REFERENCES terms(term_id) ON DELETE CASCADE,
    UNIQUE KEY uq_term_result (student_id, class_subject_id, term_id),
    INDEX idx_termresults_term (term_id)
) ENGINE=InnoDB;

-- Step 5: Overall report card per student per term (publish gate)
CREATE TABLE report_cards (
    report_card_id BIGINT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    term_id INT NOT NULL,
    overall_average DECIMAL(6,2) DEFAULT NULL,
    overall_gpa DECIMAL(3,2) DEFAULT NULL,
    overall_position INT DEFAULT NULL,
    class_size INT DEFAULT NULL,
    attendance_percent DECIMAL(5,2) DEFAULT NULL,
    class_teacher_remarks TEXT DEFAULT NULL,
    head_remarks TEXT DEFAULT NULL,
    status ENUM('draft','published') NOT NULL DEFAULT 'draft',
    published_by INT DEFAULT NULL,
    published_at TIMESTAMP NULL DEFAULT NULL,
    pdf_path VARCHAR(255) DEFAULT NULL,
    FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE,
    FOREIGN KEY (term_id) REFERENCES terms(term_id) ON DELETE CASCADE,
    FOREIGN KEY (published_by) REFERENCES users(user_id) ON DELETE SET NULL,
    UNIQUE KEY uq_report_card (student_id, term_id),
    INDEX idx_reportcards_term (term_id)
) ENGINE=InnoDB;

-- Student promotion records (between academic years)
CREATE TABLE student_promotions (
    promotion_id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    from_class_id INT DEFAULT NULL,
    to_class_id INT DEFAULT NULL,
    from_year_id INT NOT NULL,
    to_year_id INT NOT NULL,
    decision ENUM('promoted','repeated','graduated','transferred') NOT NULL,
    decided_by INT DEFAULT NULL,
    decided_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    remarks VARCHAR(255) DEFAULT NULL,
    FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE,
    FOREIGN KEY (from_class_id) REFERENCES classes(class_id) ON DELETE SET NULL,
    FOREIGN KEY (to_class_id) REFERENCES classes(class_id) ON DELETE SET NULL,
    FOREIGN KEY (from_year_id) REFERENCES academic_years(year_id) ON DELETE RESTRICT,
    FOREIGN KEY (to_year_id) REFERENCES academic_years(year_id) ON DELETE RESTRICT,
    FOREIGN KEY (decided_by) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- =====================================================================
-- 7. DISCIPLINE
-- =====================================================================

CREATE TABLE discipline_records (
    record_id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    incident_date DATE NOT NULL,
    category ENUM('minor','moderate','severe') NOT NULL DEFAULT 'minor',
    description TEXT NOT NULL,
    action_taken VARCHAR(255) DEFAULT NULL,
    reported_by INT DEFAULT NULL,
    parent_notified TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE,
    FOREIGN KEY (reported_by) REFERENCES users(user_id) ON DELETE SET NULL,
    INDEX idx_discipline_student (student_id)
) ENGINE=InnoDB;

-- =====================================================================
-- 8. FINANCE: Fee Structure, Invoices, Payments, Payroll, Budget
-- =====================================================================

CREATE TABLE fee_categories (
    fee_category_id INT AUTO_INCREMENT PRIMARY KEY,
    category_name VARCHAR(80) NOT NULL UNIQUE -- Tuition, Transport, Uniform, Exam Fee, etc.
) ENGINE=InnoDB;

CREATE TABLE fee_structures (
    fee_structure_id INT AUTO_INCREMENT PRIMARY KEY,
    class_level_id INT NOT NULL,
    fee_category_id INT NOT NULL,
    term_id INT NOT NULL,
    amount DECIMAL(14,2) NOT NULL,
    FOREIGN KEY (class_level_id) REFERENCES class_levels(class_level_id) ON DELETE CASCADE,
    FOREIGN KEY (fee_category_id) REFERENCES fee_categories(fee_category_id) ON DELETE CASCADE,
    FOREIGN KEY (term_id) REFERENCES terms(term_id) ON DELETE CASCADE,
    UNIQUE KEY uq_fee_structure (class_level_id, fee_category_id, term_id)
) ENGINE=InnoDB;

CREATE TABLE invoices (
    invoice_id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    term_id INT NOT NULL,
    invoice_no VARCHAR(40) NOT NULL UNIQUE,
    total_amount DECIMAL(14,2) NOT NULL,
    amount_paid DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    balance DECIMAL(14,2) NOT NULL,
    due_date DATE DEFAULT NULL,
    status ENUM('unpaid','partial','paid','overdue','waived') NOT NULL DEFAULT 'unpaid',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE,
    FOREIGN KEY (term_id) REFERENCES terms(term_id) ON DELETE CASCADE,
    INDEX idx_invoices_student (student_id),
    INDEX idx_invoices_status (status)
) ENGINE=InnoDB;

CREATE TABLE invoice_items (
    invoice_item_id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_id INT NOT NULL,
    fee_category_id INT NOT NULL,
    description VARCHAR(150) DEFAULT NULL,
    amount DECIMAL(14,2) NOT NULL,
    FOREIGN KEY (invoice_id) REFERENCES invoices(invoice_id) ON DELETE CASCADE,
    FOREIGN KEY (fee_category_id) REFERENCES fee_categories(fee_category_id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE payments (
    payment_id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_id INT NOT NULL,
    student_id INT NOT NULL,
    amount DECIMAL(14,2) NOT NULL,
    payment_method ENUM('cash','bank_transfer','mobile_money','card','online_gateway','cheque') NOT NULL,
    reference_no VARCHAR(100) DEFAULT NULL,
    payment_date DATE NOT NULL,
    received_by INT DEFAULT NULL, -- bursar user_id, null for online/gateway auto entries
    notes VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (invoice_id) REFERENCES invoices(invoice_id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE,
    FOREIGN KEY (received_by) REFERENCES users(user_id) ON DELETE SET NULL,
    INDEX idx_payments_student (student_id),
    INDEX idx_payments_date (payment_date)
) ENGINE=InnoDB;

CREATE TABLE fee_reminders (
    reminder_id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_id INT NOT NULL,
    sent_to_guardian_id INT DEFAULT NULL,
    channel ENUM('sms','email','push','in_app') NOT NULL DEFAULT 'in_app',
    message TEXT DEFAULT NULL,
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (invoice_id) REFERENCES invoices(invoice_id) ON DELETE CASCADE,
    FOREIGN KEY (sent_to_guardian_id) REFERENCES guardians(guardian_id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Payroll
CREATE TABLE payroll_runs (
    payroll_run_id INT AUTO_INCREMENT PRIMARY KEY,
    pay_period_month TINYINT NOT NULL, -- 1-12
    pay_period_year SMALLINT NOT NULL,
    status ENUM('draft','approved','paid') NOT NULL DEFAULT 'draft',
    created_by INT DEFAULT NULL,
    approved_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE SET NULL,
    FOREIGN KEY (approved_by) REFERENCES users(user_id) ON DELETE SET NULL,
    UNIQUE KEY uq_payroll_period (pay_period_month, pay_period_year)
) ENGINE=InnoDB;

CREATE TABLE payslips (
    payslip_id INT AUTO_INCREMENT PRIMARY KEY,
    payroll_run_id INT NOT NULL,
    staff_id INT NOT NULL,
    basic_salary DECIMAL(14,2) NOT NULL,
    total_allowances DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    total_deductions DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    net_pay DECIMAL(14,2) NOT NULL,
    status ENUM('pending','paid') NOT NULL DEFAULT 'pending',
    paid_at TIMESTAMP NULL DEFAULT NULL,
    FOREIGN KEY (payroll_run_id) REFERENCES payroll_runs(payroll_run_id) ON DELETE CASCADE,
    FOREIGN KEY (staff_id) REFERENCES staff(staff_id) ON DELETE CASCADE,
    UNIQUE KEY uq_payslip (payroll_run_id, staff_id)
) ENGINE=InnoDB;

CREATE TABLE payslip_items (
    payslip_item_id INT AUTO_INCREMENT PRIMARY KEY,
    payslip_id INT NOT NULL,
    item_type ENUM('allowance','deduction') NOT NULL,
    item_name VARCHAR(80) NOT NULL,
    amount DECIMAL(14,2) NOT NULL,
    FOREIGN KEY (payslip_id) REFERENCES payslips(payslip_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- School-level budget & expenses
CREATE TABLE budgets (
    budget_id INT AUTO_INCREMENT PRIMARY KEY,
    year_id INT NOT NULL,
    department_id INT DEFAULT NULL,
    category VARCHAR(100) NOT NULL,
    allocated_amount DECIMAL(14,2) NOT NULL,
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (year_id) REFERENCES academic_years(year_id) ON DELETE CASCADE,
    FOREIGN KEY (department_id) REFERENCES departments(department_id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE expenses (
    expense_id INT AUTO_INCREMENT PRIMARY KEY,
    budget_id INT DEFAULT NULL,
    department_id INT DEFAULT NULL,
    description VARCHAR(255) NOT NULL,
    amount DECIMAL(14,2) NOT NULL,
    expense_date DATE NOT NULL,
    status ENUM('pending_approval','approved','rejected','paid') NOT NULL DEFAULT 'pending_approval',
    requested_by INT DEFAULT NULL,
    approved_by INT DEFAULT NULL,
    receipt_path VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (budget_id) REFERENCES budgets(budget_id) ON DELETE SET NULL,
    FOREIGN KEY (department_id) REFERENCES departments(department_id) ON DELETE SET NULL,
    FOREIGN KEY (requested_by) REFERENCES users(user_id) ON DELETE SET NULL,
    FOREIGN KEY (approved_by) REFERENCES users(user_id) ON DELETE SET NULL,
    INDEX idx_expenses_status (status)
) ENGINE=InnoDB;

-- =====================================================================
-- 9. COMMUNICATION: Internal messaging, announcements, notifications
-- =====================================================================

CREATE TABLE messages (
    message_id BIGINT AUTO_INCREMENT PRIMARY KEY,
    sender_id INT NOT NULL,
    recipient_id INT NOT NULL,
    subject VARCHAR(150) DEFAULT NULL,
    body TEXT NOT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    read_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sender_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (recipient_id) REFERENCES users(user_id) ON DELETE CASCADE,
    INDEX idx_messages_recipient (recipient_id, is_read),
    INDEX idx_messages_sender (sender_id)
) ENGINE=InnoDB;

CREATE TABLE announcements (
    announcement_id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(150) NOT NULL,
    body TEXT NOT NULL,
    audience ENUM('all','staff','parents','students','board','specific_class') NOT NULL DEFAULT 'all',
    class_id INT DEFAULT NULL, -- used when audience = specific_class
    priority ENUM('normal','important','urgent') NOT NULL DEFAULT 'normal',
    posted_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (posted_by) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (class_id) REFERENCES classes(class_id) ON DELETE CASCADE,
    INDEX idx_announcements_audience (audience)
) ENGINE=InnoDB;

CREATE TABLE notifications (
    notification_id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(150) NOT NULL,
    body VARCHAR(500) DEFAULT NULL,
    type ENUM('result','fee','attendance','discipline','announcement','message','system','emergency') NOT NULL DEFAULT 'system',
    link VARCHAR(255) DEFAULT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    INDEX idx_notifications_user (user_id, is_read)
) ENGINE=InnoDB;

-- =====================================================================
-- 10. SYSTEM SETTINGS & ID SEQUENCES
-- =====================================================================

CREATE TABLE system_settings (
    setting_key VARCHAR(80) PRIMARY KEY,
    setting_value VARCHAR(500) DEFAULT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT INTO system_settings (setting_key, setting_value) VALUES
('school_name', 'Demo Secondary School'),
('school_motto', 'Knowledge, Discipline, Excellence'),
('school_logo_path', 'assets/img/logo.png'),
('current_academic_year_id', '1'),
('current_term_id', '1'),
('default_language', 'en'),
('session_timeout_minutes', '20'),
('two_factor_enforced_for_roles', 'director,bursar,system_admin');

-- Sequence helper for human-friendly ID generation (STU-2026-0001 / STF-2026-0001)
CREATE TABLE id_sequences (
    seq_key VARCHAR(30) PRIMARY KEY, -- e.g. 'STU-2026', 'STF-2026'
    last_value INT NOT NULL DEFAULT 0
) ENGINE=InnoDB;

SET FOREIGN_KEY_CHECKS = 1;
