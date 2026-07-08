<?php
declare(strict_types=1);

require_once __DIR__ . "/db.php";

function ensure_student_marks_table(): void
{
    static $done = false;
    if ($done) {
        return;
    }

    db()->exec(
        "CREATE TABLE IF NOT EXISTS student_flags (
            flag_id INT AUTO_INCREMENT PRIMARY KEY,
            student_id INT NOT NULL,
            issue_type ENUM('Health','Financial','Behavioral','Academic','Family','Other') NOT NULL,
            severity ENUM('Low','Moderate','High') NOT NULL DEFAULT 'Moderate',
            note VARCHAR(255) NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            flagged_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            resolved_at TIMESTAMP NULL,
            CONSTRAINT fk_flags_student FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE,
            CONSTRAINT fk_flags_user FOREIGN KEY (flagged_by) REFERENCES users(user_id) ON DELETE SET NULL
        ) ENGINE=InnoDB"
    );

    $done = true;
}

function get_student_flags(int $studentId, bool $activeOnly = true): array
{
    ensure_student_marks_table();
    $sql = "SELECT flag_id, student_id, issue_type, severity, note, is_active, flagged_by, created_at, resolved_at
            FROM student_flags
            WHERE student_id = ?";
    if ($activeOnly) {
        $sql .= " AND is_active = 1";
    }
    $sql .= " ORDER BY created_at DESC";
    $stmt = db()->prepare($sql);
    $stmt->execute([$studentId]);
    $rows = $stmt->fetchAll();
    if (!db_field_encryption_enabled()) {
        return $rows;
    }
    foreach ($rows as &$r) {
        if (isset($r["note"]) && $r["note"] !== null && $r["note"] !== "") {
            $r["note"] = db_field_decrypt((string)$r["note"]);
        }
    }
    unset($r);
    return $rows;
}

function student_has_active_flags(int $studentId): bool
{
    ensure_student_marks_table();
    $stmt = db()->prepare("SELECT 1 FROM student_flags WHERE student_id = ? AND is_active = 1 LIMIT 1");
    $stmt->execute([$studentId]);
    return (bool)$stmt->fetch();
}

function add_student_flag(int $studentId, string $issueType, string $severity, ?string $note, int $flaggedBy): int
{
    ensure_student_marks_table();
    $stmt = db()->prepare(
        "INSERT INTO student_flags (student_id, issue_type, severity, note, is_active, flagged_by)
         VALUES (?, ?, ?, ?, 1, ?)"
    );
    $noteStore = $note !== null && $note !== "" ? db_field_encrypt($note) : $note;
    $stmt->execute([$studentId, $issueType, $severity, $noteStore, $flaggedBy]);
    return (int)db()->lastInsertId();
}

function resolve_student_flag(int $flagId): void
{
    ensure_student_marks_table();
    $stmt = db()->prepare("UPDATE student_flags SET is_active = 0, resolved_at = NOW() WHERE flag_id = ?");
    $stmt->execute([$flagId]);
}
