<?php
declare(strict_types=1);

require_once __DIR__ . "/db.php";

function ensure_student_batches_table(): void
{
    static $done = false;
    if ($done) {
        return;
    }

    db()->exec(
        "CREATE TABLE IF NOT EXISTS student_batches (
            batch_id INT AUTO_INCREMENT PRIMARY KEY,
            student_id INT NOT NULL,
            school_year VARCHAR(9) NOT NULL,
            grade_level VARCHAR(20) NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_student_year (student_id, school_year),
            CONSTRAINT fk_batches_student FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE
        ) ENGINE=InnoDB"
    );

    try {
        db()->exec("CREATE INDEX idx_batches_year ON student_batches (school_year)");
    } catch (Throwable) {
    }
    try {
        db()->exec("CREATE INDEX idx_batches_student ON student_batches (student_id)");
    } catch (Throwable) {
    }

    try {
        $hasS = db()->query("SHOW COLUMNS FROM `students` LIKE 'strand'")->fetch();
        if (!$hasS) {
            db()->exec("ALTER TABLE `students` ADD COLUMN `strand` VARCHAR(40) NULL AFTER `grade_level`");
        }
    } catch (Throwable) {
    }
    try {
        $hasB = db()->query("SHOW COLUMNS FROM `student_batches` LIKE 'strand'")->fetch();
        if (!$hasB) {
            db()->exec("ALTER TABLE `student_batches` ADD COLUMN `strand` VARCHAR(40) NULL AFTER `grade_level`");
        }
    } catch (Throwable) {
    }

    $done = true;
}

function attach_student_to_school_year(int $studentId, string $schoolYear, string $gradeLevel, ?string $section = null, ?string $strand = null): void
{
    ensure_student_batches_table();
    $pdo = db();

    $stmt = $pdo->prepare(
        "INSERT INTO student_batches (student_id, school_year, grade_level, section, strand, is_active)
         VALUES (?, ?, ?, ?, ?, 1)
         ON DUPLICATE KEY UPDATE
           grade_level = VALUES(grade_level),
           section = VALUES(section),
           strand = VALUES(strand),
           is_active = 1"
    );
    $stmt->execute([$studentId, $schoolYear, $gradeLevel, $section, $strand]);
}

function is_valid_school_year_sequence(string $schoolYear): bool
{
    if (!preg_match('/^(\d{4})-(\d{4})$/', trim($schoolYear), $m)) {
        return false;
    }
    return ((int)$m[2]) === ((int)$m[1] + 1);
}

/** Default school year for new enrollments (e.g. 2026-2027 when the server date is in 2026). */
function default_current_school_year(): string
{
    $y = (int)date("Y");
    return $y . "-" . ($y + 1);
}

function latest_school_year_for_student(int $studentId): ?string
{
    ensure_student_batches_table();
    $stmt = db()->prepare(
        "SELECT school_year
         FROM student_batches
         WHERE student_id = ?
         ORDER BY batch_id DESC
         LIMIT 1"
    );
    $stmt->execute([$studentId]);
    $row = $stmt->fetch();
    $v = (string)($row["school_year"] ?? "");
    return $v !== "" ? $v : null;
}

