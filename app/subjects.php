<?php
declare(strict_types=1);

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/sections.php";

function ensure_subjects_table(): void
{
    static $done = false;
    if ($done) return;

    ensure_sections_table();

    db()->exec(
        "CREATE TABLE IF NOT EXISTS subjects (
            subject_id BIGINT AUTO_INCREMENT PRIMARY KEY,
            subject_code VARCHAR(30) NOT NULL UNIQUE,
            subject_name VARCHAR(120) NOT NULL,
            grade_level VARCHAR(20) NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_subjects_grade (grade_level),
            INDEX idx_subjects_active (is_active, subject_code)
        ) ENGINE=InnoDB"
    );

    db()->exec(
        "CREATE TABLE IF NOT EXISTS section_subjects (
            section_id BIGINT NOT NULL,
            subject_id BIGINT NOT NULL,
            assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (section_id, subject_id),
            CONSTRAINT fk_section_subject_section FOREIGN KEY (section_id) REFERENCES sections(section_id) ON DELETE CASCADE,
            CONSTRAINT fk_section_subject_subject FOREIGN KEY (subject_id) REFERENCES subjects(subject_id) ON DELETE CASCADE
        ) ENGINE=InnoDB"
    );

    $done = true;
}

function normalize_subject_code(string $code): string
{
    $code = strtoupper(trim($code));
    $code = preg_replace('/\s+/', '', $code) ?? "";
    return preg_replace('/[^A-Z0-9_-]/', '', $code) ?? "";
}

/** @return list<array> */
function list_subjects(bool $activeOnly = true): array
{
    ensure_subjects_table();
    $where = $activeOnly ? "WHERE is_active = 1" : "";
    return db()->query(
        "SELECT subject_id, subject_code, subject_name, grade_level, is_active
         FROM subjects
         {$where}
         ORDER BY
           CASE
             WHEN grade_level IS NULL OR grade_level = '' THEN 999
             WHEN grade_level LIKE 'Grade %' THEN CAST(SUBSTRING_INDEX(grade_level, ' ', -1) AS UNSIGNED)
             ELSE 998
           END,
           subject_code ASC"
    )->fetchAll();
}

function subject_label(array $subject): string
{
    $code = trim((string)($subject["subject_code"] ?? ""));
    $name = trim((string)($subject["subject_name"] ?? ""));
    if ($code === "") return $name;
    if ($name === "") return $code;
    return $code . " - " . $name;
}

function add_subject(string $code, string $name, ?string $gradeLevel = null): void
{
    ensure_subjects_table();
    $code = normalize_subject_code($code);
    $name = trim($name);
    $gradeLevel = trim((string)$gradeLevel);
    if ($code === "" || $name === "") return;

    $stmt = db()->prepare(
        "INSERT INTO subjects (subject_code, subject_name, grade_level, is_active)
         VALUES (?, ?, NULLIF(?, ''), 1)
         ON DUPLICATE KEY UPDATE
           subject_name = VALUES(subject_name),
           grade_level = VALUES(grade_level),
           is_active = 1"
    );
    $stmt->execute([$code, $name, $gradeLevel]);
}

function deactivate_subject(int $subjectId): void
{
    ensure_subjects_table();
    $stmt = db()->prepare("UPDATE subjects SET is_active = 0 WHERE subject_id = ?");
    $stmt->execute([$subjectId]);
}

/** @return list<array> */
function list_section_subjects(string $sectionName): array
{
    ensure_subjects_table();
    $stmt = db()->prepare(
        "SELECT sub.subject_id, sub.subject_code, sub.subject_name, sub.grade_level, sub.is_active
         FROM sections sec
         INNER JOIN section_subjects ss ON ss.section_id = sec.section_id
         INNER JOIN subjects sub ON sub.subject_id = ss.subject_id
         WHERE sec.section_name = ? AND sub.is_active = 1
         ORDER BY sub.subject_code ASC"
    );
    $stmt->execute([trim($sectionName)]);
    return $stmt->fetchAll();
}

function assign_subject_to_section(string $sectionName, int $subjectId): void
{
    ensure_subjects_table();
    $stmt = db()->prepare("SELECT section_id FROM sections WHERE section_name = ? LIMIT 1");
    $stmt->execute([trim($sectionName)]);
    $section = $stmt->fetch();
    if (!$section || $subjectId <= 0) return;

    $stmt = db()->prepare(
        "INSERT IGNORE INTO section_subjects (section_id, subject_id)
         VALUES (?, ?)"
    );
    $stmt->execute([(int)$section["section_id"], $subjectId]);
}

function remove_subject_from_section(string $sectionName, int $subjectId): void
{
    ensure_subjects_table();
    $stmt = db()->prepare("SELECT section_id FROM sections WHERE section_name = ? LIMIT 1");
    $stmt->execute([trim($sectionName)]);
    $section = $stmt->fetch();
    if (!$section || $subjectId <= 0) return;

    $stmt = db()->prepare("DELETE FROM section_subjects WHERE section_id = ? AND subject_id = ?");
    $stmt->execute([(int)$section["section_id"], $subjectId]);
}

