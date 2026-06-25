# Advanced School Management System (ASMS)

A full-stack school management system built with **PHP 8 + MySQL + Bootstrap 5**, designed to run on any classic LAMP stack (Apache/Nginx + PHP + MySQL) — including shared hosting, XAMPP/WAMP/MAMP, or a VPS.

This build implements the **core** of the larger specification you provided: authentication & RBAC, student/staff management, the full academic results workflow (mark entry → submission → verification → computation → publishing), attendance, finance (fees/invoices/payments/payroll/budget), discipline, and internal communication (messages, announcements, notifications).

---

## 1. What's included

| Module | Roles | Key Features |
|---|---|---|
| **Authentication** | Everyone | Single smart login, role auto-detect, account lockout, forced password change on first login, password reset flow, full login audit trail |
| **Director (Super Admin)** | Director, School Board, System Admin | School-wide dashboard, user & role management, audit/security logs, system settings, finance & performance reports |
| **Head of School** | Head of School, Department Head | Operations dashboard, student/staff management, discipline log, attendance overview, school reports |
| **Bursar (Finance)** | Bursar | Fee structures, bulk invoice generation, payment recording, fee reminders, payroll runs & payslips, budget vs. expense tracking, financial reports |
| **Academic Department** | Academic Officer | Classes/subjects/timetable setup, exam scheduling, mark verification, result computation, report card publishing, promotions, transcripts |
| **Subject Teacher** | Subject Teacher | Mark entry & submission per exam/class/subject, lesson attendance, personal timetable |
| **Class Teacher** | Class Teacher | Homeroom roster, daily attendance register, discipline logging, student progress view |
| **Parent Portal** | Parent | Per-child dashboard: results, attendance, fees, discipline, school calendar — strictly scoped to their own linked children |
| **Student Portal** | Student | Own results, timetable, attendance, fee status, school notices |
| **Communication** | All roles | Announcements (audience-targeted), internal direct messaging, in-app notifications |

### The academic results workflow (the heart of the system)

1. **Subject teacher** enters marks for their class/subject/exam (`teacher/enter_marks.php`) — saved as drafts, editable.
2. **Subject teacher submits** the batch to the Academic Department — marks lock for editing.
3. **Academic Department verifies** (or rejects with a reason, sending it back) — `academic/verify_marks.php`.
4. On verification, the **system automatically computes** each student's subject grade/GPA into `term_results`.
5. The **Academic Department computes and publishes report cards** (`academic/publish_results.php`) — this aggregates all verified subjects into an overall average, GPA, and class rank, and notifies parents/students the moment it's published.

---

## 2. Requirements

- PHP **8.0 or higher** with the `pdo_mysql`, `mbstring`, and `openssl` extensions (all standard in most PHP installs)
- MySQL **5.7+** or MariaDB **10.3+**
- Apache with `mod_rewrite` and `mod_headers` enabled (for `.htaccess` to work), or Nginx (see note below)

---

## 3. Installation

### Step 1 — Upload the files
Upload the entire `asms/` folder to your server (e.g. `public_html/asms` or your web root).

### Step 2 — Create the database
```bash
mysql -u root -p -e "CREATE DATABASE asms_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p asms_db < database/schema.sql
```

### Step 3 — Generate the demo password hash, then seed data
The seed file ships with demo accounts (director, bursar, teacher, parent, etc.) all sharing one password for easy testing — but the bcrypt hash must be generated on **your** server (hashes include a random salt, so one generated elsewhere won't verify):

```bash
php database/generate_seed_hash.php
```
Copy the printed hash, open `database/seed_data.sql`, and replace the placeholder value in this line:
```sql
SET @pwd_hash = '$2y$10$wQqYV1Hw0v0o8nE8s0K9F.examplehashreplaceme0000000000000';
```
Then import it:
```bash
mysql -u root -p asms_db < database/seed_data.sql
```

### Step 4 — Configure database credentials
Open `config/database.php` and either:
- set real environment variables (`ASMS_DB_HOST`, `ASMS_DB_NAME`, `ASMS_DB_USER`, `ASMS_DB_PASS`), **or**
- edit the fallback defaults directly in the file (fine for local testing, not recommended for production).

### Step 5 — Set folder permissions
```bash
chmod -R 755 asms/
chmod -R 775 asms/uploads/
```

### Step 6 — Visit the site
Navigate to `https://yourdomain.com/asms/` (or wherever you uploaded it). You'll land on the login page.

> **Nginx users:** the included `.htaccess` files are Apache-specific. If you're on Nginx, replicate the same rules (deny `/config`, `/includes`, `/database`; disable PHP execution inside `/uploads`) in your server block — see comments inside `.htaccess` for what to replicate.

---

## 4. Demo login credentials

All seeded demo accounts share the password **`Passw0rd!2026`** (or whatever you set in `generate_seed_hash.php`) and will be **forced to change it on first login** — that's expected behavior, not a bug.

| Role | Username |
|---|---|
| Director | `director.demo` |
| Head of School | `headofschool.demo` |
| Bursar | `bursar.demo` |
| Academic Officer | `academic.demo` |
| Subject Teacher | `teacher.demo` |
| Class Teacher | `classteacher.demo` |
| System Admin | `sysadmin.demo` |
| Parent | `parent.demo` |
| Student | `student.demo` |

Login is unified — just go to `/auth/login.php` and the system detects the role and redirects to the right dashboard automatically.

---

## 5. Project structure

```
asms/
├── auth/                 Login, logout, password change/reset
├── config/               Database connection + app bootstrap (session, constants)
├── includes/             Shared PHP: functions, auth/RBAC, CSRF, audit log, header/footer/sidebar
├── director/             Director (super admin) + School Board + System Admin pages
├── head_of_school/       Head of School / Department Head pages
├── bursar/               Finance module
├── academic/             Academic Department module (exams, results workflow)
├── teacher/              Subject teacher module
├── class_teacher/        Class teacher (homeroom) module
├── parent/               Parent portal
├── student/              Student portal
├── communication/        Announcements, messaging, notifications (shared)
├── assets/               CSS, JS, images
├── database/             schema.sql, seed_data.sql, hash generator
├── uploads/               Runtime file storage (documents/photos/report_cards)
└── index.php             Entry point — redirects to login or role dashboard
```

---

## 6. Security notes

- Passwords are hashed with **bcrypt** (`password_hash()` / `password_verify()`), never stored in plain text.
- All forms are protected with **CSRF tokens** (`includes/csrf.php`).
- All database queries use **PDO prepared statements** — no string-concatenated SQL anywhere.
- **Account lockout** after 5 failed login attempts (15-minute cooldown) — configurable in `config/config.php`.
- Every login attempt (success/failure/lockout) and every sensitive action (create user, record payment, publish results, etc.) is written to an **audit trail** (`audit_logs`, `login_activity` tables), viewable at `/director/audit_logs.php`.
- Session cookies are `HttpOnly`, `SameSite=Lax`, and `Secure` automatically when served over HTTPS.
- Parent and student portals enforce **row-level ownership checks** (`verify_guardian_owns_student()` in `includes/functions.php`) — a parent cannot view another family's child by guessing a `student_id` in the URL.
- The `uploads/` folder has its own `.htaccess` disabling PHP execution, so even if a malicious file were uploaded, it cannot run as a script.

### Before going to production
- Replace every default/demo password.
- Set real environment variables for DB credentials instead of the fallback defaults in `config/database.php`.
- Enable HTTPS and uncomment the force-HTTPS rule in `.htaccess`.
- Review `config/config.php` → `SESSION_TIMEOUT_SECONDS`, `MAX_LOGIN_ATTEMPTS`, `LOCKOUT_MINUTES` for your policy needs.
- Wire up real SMS/Email delivery for fee reminders and password resets (currently these are in-app notifications only, with the reset link shown on-screen in `forgot_password.php` since no mail server is configured — replace that with actual email sending in production).

---

## 7. What's intentionally out of scope for this build

This build focused on making the **core workflow genuinely functional** rather than spreading thin across every module mentioned in the original specification. Not included (but the schema and architecture are designed to make adding them straightforward): library management, transport/hostel modules, document management beyond uploads, AI-driven analytics, and a dedicated mobile app. The role/permission system, audit logging, and database schema are all built to extend cleanly if you'd like these added later.

---

## 8. Support

This codebase is intentionally explicit and commented — every file states its purpose at the top, and the database schema (`database/schema.sql`) is the single source of truth for all data relationships. If you extend this system, keep new tables/columns consistent with the existing naming conventions (snake_case, singular FK columns like `student_id`, plural table names).
