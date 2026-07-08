<?php
declare(strict_types=1);

require_once __DIR__ . "/db.php";

function ensure_workflow_tables(): void
{
    static $done = false;
    if ($done) {
        return;
    }

    db()->exec(
        "CREATE TABLE IF NOT EXISTS teacher_students (
            teacher_user_id INT NOT NULL,
            student_id INT NOT NULL,
            assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (teacher_user_id, student_id),
            CONSTRAINT fk_ts_teacher FOREIGN KEY (teacher_user_id) REFERENCES users(user_id) ON DELETE CASCADE,
            CONSTRAINT fk_ts_student FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE
        ) ENGINE=InnoDB"
    );

    db()->exec(
        "CREATE TABLE IF NOT EXISTS manual_referrals (
            referral_id INT AUTO_INCREMENT PRIMARY KEY,
            student_id INT NOT NULL,
            teacher_user_id INT NOT NULL,
            reason VARCHAR(255) NOT NULL,
            details TEXT NULL,
            status ENUM('New','Reviewed','Closed') NOT NULL DEFAULT 'New',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_ref_student FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE,
            CONSTRAINT fk_ref_teacher FOREIGN KEY (teacher_user_id) REFERENCES users(user_id) ON DELETE CASCADE
        ) ENGINE=InnoDB"
    );

    db()->exec(
        "CREATE TABLE IF NOT EXISTS counseling_cases (
            case_id INT AUTO_INCREMENT PRIMARY KEY,
            student_id INT NOT NULL UNIQUE,
            status ENUM('Flagged','Ongoing Counseling','Resolved') NOT NULL DEFAULT 'Flagged',
            counselor_user_id INT NULL,
            notes TEXT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_case_student FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE,
            CONSTRAINT fk_case_counselor FOREIGN KEY (counselor_user_id) REFERENCES users(user_id) ON DELETE SET NULL
        ) ENGINE=InnoDB"
    );

    $done = true;
}

