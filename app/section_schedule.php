<?php
declare(strict_types=1);

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/sections.php";
require_once __DIR__ . "/subjects.php";

function ensure_section_schedule_table(): void
{
    static $done = false;
    if ($done) return;

    ensure_student_sections();
    ensure_subjects_table();
    db()->exec(
        "CREATE TABLE IF NOT EXISTS section_schedule (
            section_schedule_id BIGINT AUTO_INCREMENT PRIMARY KEY,
            section VARCHAR(80) NOT NULL,
            day_of_week TINYINT NOT NULL, /* 1=Mon ... 7=Sun */
            start_time TIME NOT NULL,
            end_time TIME NOT NULL,
            subject_code VARCHAR(30) NULL,
            subject VARCHAR(120) NULL,
            room VARCHAR(60) NULL,
            teacher_user_id INT NULL,
            note VARCHAR(255) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_section_day (section, day_of_week, start_time),
            CONSTRAINT fk_ss_teacher FOREIGN KEY (teacher_user_id) REFERENCES users(user_id) ON DELETE SET NULL
        ) ENGINE=InnoDB"
    );

    try {
        $has = db()->query("SHOW COLUMNS FROM `section_schedule` LIKE 'subject_code'")->fetch();
        if (!$has) {
            db()->exec("ALTER TABLE `section_schedule` ADD COLUMN `subject_code` VARCHAR(30) NULL AFTER `end_time`");
        }
    } catch (Throwable) {
    }

    $done = true;
}

/** @return list<array> */
function get_section_schedule(string $section): array
{
    ensure_section_schedule_table();
    $stmt = db()->prepare(
        "SELECT section_schedule_id, section, day_of_week, start_time, end_time, subject_code, subject, room, teacher_user_id, note
         FROM section_schedule
         WHERE section = ?
         ORDER BY day_of_week ASC, start_time ASC"
    );
    $stmt->execute([$section]);
    return $stmt->fetchAll();
}

