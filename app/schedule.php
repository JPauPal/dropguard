<?php
declare(strict_types=1);

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/subjects.php";

function ensure_teacher_schedule_table(): void
{
    static $done = false;
    if ($done) return;

    ensure_subjects_table();

    db()->exec(
        "CREATE TABLE IF NOT EXISTS teacher_schedule (
            schedule_id BIGINT AUTO_INCREMENT PRIMARY KEY,
            teacher_user_id INT NOT NULL,
            day_of_week TINYINT NOT NULL, /* 1=Mon ... 7=Sun */
            start_time TIME NOT NULL,
            end_time TIME NOT NULL,
            section_label VARCHAR(80) NULL,
            subject_code VARCHAR(30) NULL,
            subject VARCHAR(120) NULL,
            room VARCHAR(60) NULL,
            note VARCHAR(255) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_sched_teacher FOREIGN KEY (teacher_user_id) REFERENCES users(user_id) ON DELETE CASCADE,
            INDEX idx_sched_teacher_day (teacher_user_id, day_of_week, start_time)
        ) ENGINE=InnoDB"
    );

    try {
        $has = db()->query("SHOW COLUMNS FROM `teacher_schedule` LIKE 'subject_code'")->fetch();
        if (!$has) {
            db()->exec("ALTER TABLE `teacher_schedule` ADD COLUMN `subject_code` VARCHAR(30) NULL AFTER `section_label`");
        }
    } catch (Throwable) {
    }

    $done = true;
}

/** @return list<array> */
function get_teacher_schedule(int $teacherUserId): array
{
    ensure_teacher_schedule_table();
    $stmt = db()->prepare(
        "SELECT schedule_id, teacher_user_id, day_of_week, start_time, end_time, section_label, subject_code, subject, room, note
         FROM teacher_schedule
         WHERE teacher_user_id = ?
         ORDER BY day_of_week ASC, start_time ASC"
    );
    $stmt->execute([$teacherUserId]);
    return $stmt->fetchAll();
}

