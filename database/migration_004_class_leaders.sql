-- =====================================================================
-- ASMS Migration 004: Class Leaders Table
-- Added: 2026-06-24
-- =====================================================================

SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS class_leaders (
    leader_id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    class_id INT NOT NULL,
    position VARCHAR(60) NOT NULL, -- 'Head Boy', 'Head Girl', 'Prefect', 'Class Monitor', etc.
    term_id INT NOT NULL,
    year_id INT NOT NULL,
    assigned_by INT DEFAULT NULL, -- users.user_id (class teacher)
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE,
    FOREIGN KEY (class_id) REFERENCES classes(class_id) ON DELETE CASCADE,
    FOREIGN KEY (term_id) REFERENCES terms(term_id) ON DELETE CASCADE,
    FOREIGN KEY (year_id) REFERENCES academic_years(year_id) ON DELETE CASCADE,
    FOREIGN KEY (assigned_by) REFERENCES users(user_id) ON DELETE SET NULL,
    UNIQUE KEY uq_class_leader (student_id, term_id, year_id, position),
    INDEX idx_classleaders_class (class_id),
    INDEX idx_classleaders_active (is_active)
) ENGINE=InnoDB;

SET FOREIGN_KEY_CHECKS = 1;