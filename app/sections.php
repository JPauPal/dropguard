<?php
declare(strict_types=1);

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/batches.php";
require_once __DIR__ . "/curriculum.php";
require_once __DIR__ . "/students_archive.php";

function ensure_sections_table(): void
{
    static $done = false;
    if ($done) return;
    ensure_student_sections();
    db()->exec(
        "CREATE TABLE IF NOT EXISTS sections (
            section_id BIGINT AUTO_INCREMENT PRIMARY KEY,
            section_name VARCHAR(80) NOT NULL UNIQUE,
            grade_level VARCHAR(20) NULL,
            section_short VARCHAR(80) NULL,
            strand VARCHAR(20) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB"
    );
    // Idempotent migration for existing installs
    try {
        $has = db()->query("SHOW COLUMNS FROM `sections` LIKE 'grade_level'")->fetch();
        if (!$has) {
            db()->exec("ALTER TABLE `sections` ADD COLUMN grade_level VARCHAR(20) NULL AFTER section_name");
        }
        $has2 = db()->query("SHOW COLUMNS FROM `sections` LIKE 'section_short'")->fetch();
        if (!$has2) {
            db()->exec("ALTER TABLE `sections` ADD COLUMN section_short VARCHAR(80) NULL AFTER grade_level");
        }
        $has3 = db()->query("SHOW COLUMNS FROM `sections` LIKE 'strand'")->fetch();
        if (!$has3) {
            db()->exec("ALTER TABLE `sections` ADD COLUMN strand VARCHAR(20) NULL AFTER section_short");
        }
    } catch (Throwable) {
    }
    $done = true;
}

/** @return list<string> */
function list_section_names(): array
{
    ensure_sections_table();
    $rows = db()->query(
        "SELECT section_name
         FROM sections
         ORDER BY
           CASE
             WHEN grade_level IS NULL OR grade_level = '' THEN 999
             WHEN grade_level LIKE 'Grade %' THEN CAST(SUBSTRING_INDEX(grade_level, ' ', -1) AS UNSIGNED)
             ELSE 998
           END,
           section_short ASC,
           section_name ASC"
    )->fetchAll();
    return array_values(array_filter(array_map(static fn($r) => (string)($r["section_name"] ?? ""), $rows)));
}

/**
 * @return list<array{section_name:string,grade_level:?string,section_short:?string,strand:?string}>
 */
function list_section_rows(): array
{
    ensure_sections_table();
    try {
        $rows = db()->query(
            "SELECT section_name, grade_level, section_short, strand
             FROM sections
             ORDER BY
               CASE
                 WHEN grade_level IS NULL OR grade_level = '' THEN 999
                 WHEN grade_level LIKE 'Grade %' THEN CAST(SUBSTRING_INDEX(grade_level, ' ', -1) AS UNSIGNED)
                 ELSE 998
               END,
               strand IS NULL, strand ASC,
               section_short ASC,
               section_name ASC"
        )->fetchAll();
    } catch (Throwable) {
        return [];
    }
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            "section_name" => (string)($r["section_name"] ?? ""),
            "grade_level" => isset($r["grade_level"]) ? (string)$r["grade_level"] : null,
            "section_short" => isset($r["section_short"]) ? (string)$r["section_short"] : null,
            "strand" => isset($r["strand"]) && $r["strand"] !== null && $r["strand"] !== "" ? (string)$r["strand"] : null,
        ];
    }
    return $out;
}

function add_section_name(string $name): void
{
    ensure_sections_table();
    $name = trim($name);
    if ($name === "") return;
    $stmt = db()->prepare("INSERT IGNORE INTO sections (section_name) VALUES (?)");
    $stmt->execute([$name]);
}

function add_section_with_grade(string $gradeLevel, string $sectionShort, ?string $strand = null): void
{
    ensure_sections_table();
    $gradeLevel = trim($gradeLevel);
    $sectionShort = trim($sectionShort);
    if ($gradeLevel === "" || $sectionShort === "") {
        return;
    }
    $strandDb = null;
    if (curriculum_is_senior_high_grade($gradeLevel)) {
        $t = $strand !== null ? trim($strand) : "";
        if ($t === "" || !in_array($t, curriculum_shs_strands(), true)) {
            throw new InvalidArgumentException("Strand is required for Grade 11 and Grade 12 sections.");
        }
        $strandDb = $t;
    }
    $full = $gradeLevel . " - " . $sectionShort;
    $stmt = db()->prepare("INSERT IGNORE INTO sections (section_name, grade_level, section_short, strand) VALUES (?,?,?,?)");
    $stmt->execute([$full, $gradeLevel, $sectionShort, $strandDb]);
}

function delete_section_name(string $name): void
{
    ensure_sections_table();
    $stmt = db()->prepare("DELETE FROM sections WHERE section_name = ?");
    $stmt->execute([trim($name)]);
}

/** Grade level string (e.g. "Grade 7") for a section_name, from DB or parsed from "Grade 7 - …" pattern. */
function section_grade_level_for_name(string $sectionName): ?string
{
    $sectionName = trim($sectionName);
    if ($sectionName === "") {
        return null;
    }
    try {
        ensure_sections_table();
        $stmt = db()->prepare("SELECT grade_level FROM sections WHERE section_name = ? LIMIT 1");
        $stmt->execute([$sectionName]);
        $row = $stmt->fetch();
        $gl = trim((string)($row["grade_level"] ?? ""));
        if ($gl !== "") {
            return $gl;
        }
    } catch (Throwable) {
    }
    if (preg_match('/^(Grade\s+\d+)/iu', $sectionName, $m)) {
        return trim($m[1]);
    }
    return null;
}

/** Human-readable section only (e.g. "Hope" for "Grade 7 - Hope"); falls back to full name. */
function section_display_short(string $sectionName): string
{
    $sectionName = trim($sectionName);
    if ($sectionName === "") {
        return "";
    }
    try {
        ensure_sections_table();
        $stmt = db()->prepare("SELECT section_short FROM sections WHERE section_name = ? LIMIT 1");
        $stmt->execute([$sectionName]);
        $row = $stmt->fetch();
        $short = trim((string)($row["section_short"] ?? ""));
        if ($short !== "") {
            return $short;
        }
    } catch (Throwable) {
    }
    if (preg_match('/^Grade\s+\d+\s*-\s*(.+)$/iu', $sectionName, $m)) {
        return trim($m[1]);
    }
    return $sectionName;
}

/** Normalize UI grade filter: "7".."12" → "Grade 7".."Grade 12"; leaves JHS/SHS and "Grade N" as-is. */
function section_grade_filter_normalize(string $gradeFilter): string
{
    $gradeFilter = trim($gradeFilter);
    if ($gradeFilter === "") {
        return "";
    }
    if ($gradeFilter === "JHS" || $gradeFilter === "SHS") {
        return $gradeFilter;
    }
    if (preg_match('/^\d{1,2}$/', $gradeFilter)) {
        $n = (int)$gradeFilter;
        if ($n >= 1 && $n <= 12) {
            return "Grade " . $n;
        }
    }
    return $gradeFilter;
}

/** Whether a section belongs under the grade filter (JHS, SHS, or a specific Grade N). Empty filter = all. */
function section_matches_grade_filter(string $sectionName, string $gradeFilter): bool
{
    $nf = section_grade_filter_normalize($gradeFilter);
    if ($nf === "") {
        return true;
    }
    $gl = section_grade_level_for_name($sectionName);
    if ($gl === null || $gl === "") {
        return true;
    }
    if ($nf === "JHS") {
        return in_array($gl, ["Grade 7", "Grade 8", "Grade 9", "Grade 10"], true);
    }
    if ($nf === "SHS") {
        return in_array($gl, ["Grade 11", "Grade 12"], true);
    }
    return $gl === $nf;
}

/**
 * Strand for an SHS section: stored `sections.strand` if set, else inferred from naming (e.g. "STEM A" → STEM).
 * Returns null if not SHS or neither source yields a strand.
 */
function section_infer_strand_from_name(string $sectionName): ?string
{
    $sectionName = trim($sectionName);
    if ($sectionName === "") {
        return null;
    }
    $gl = section_grade_level_for_name($sectionName);
    if ($gl === null || !curriculum_is_senior_high_grade($gl)) {
        return null;
    }
    try {
        ensure_sections_table();
        $stmt = db()->prepare("SELECT strand FROM sections WHERE section_name = ? LIMIT 1");
        $stmt->execute([$sectionName]);
        $row = $stmt->fetch();
        if ($row) {
            $dbSt = trim((string)($row["strand"] ?? ""));
            if ($dbSt !== "") {
                $norm = curriculum_normalize_shs_strand($dbSt);
                if ($norm !== null && in_array($norm, curriculum_shs_strands(), true)) {
                    return $norm;
                }
            }
        }
    } catch (Throwable) {
    }
    $short = section_display_short($sectionName);
    if ($short === "") {
        return null;
    }
    foreach (curriculum_shs_strands() as $st) {
        if ($short === $st) {
            return $st;
        }
        $q = preg_quote($st, "/");
        if (preg_match("/^" . $q . "(?=[\\s\\-_]|$)/iu", $short)) {
            return $st;
        }
    }
    $norm = curriculum_normalize_shs_strand($short);
    if ($norm !== null) {
        return $norm;
    }
    return null;
}

/**
 * When strand filter / field is set, section name must not imply a different strand.
 * If the name does not imply any strand, returns true (do not block legacy labels).
 */
function section_matches_strand_filter(string $sectionName, string $strandFilter): bool
{
    $strandFilter = trim($strandFilter);
    if ($strandFilter === "") {
        return true;
    }
    $inf = section_infer_strand_from_name($sectionName);
    if ($inf === null) {
        return true;
    }
    return $inf === $strandFilter;
}

function ensure_student_sections(): void
{
    static $done = false;
    if ($done) return;

    $pdo = db();

    // students.section
    $has = $pdo->query("SHOW COLUMNS FROM `students` LIKE 'section'")->fetch();
    if (!$has) {
        $pdo->exec("ALTER TABLE `students` ADD COLUMN `section` VARCHAR(80) NULL AFTER `grade_level`");
        try {
            $pdo->exec("CREATE INDEX idx_students_section ON students (section)");
        } catch (Throwable) {
            // ignore if already exists
        }
    }

    // student_batches.section (so per-year section can be tracked)
    ensure_student_batches_table();
    $hasB = $pdo->query("SHOW COLUMNS FROM `student_batches` LIKE 'section'")->fetch();
    if (!$hasB) {
        $pdo->exec("ALTER TABLE `student_batches` ADD COLUMN `section` VARCHAR(80) NULL AFTER `grade_level`");
        try {
            $pdo->exec("CREATE INDEX idx_batches_section ON student_batches (section)");
        } catch (Throwable) {
        }
    }

    $done = true;
}

/** @return list<string> */
function list_sections(?int $teacherUserId = null): array
{
    ensure_students_archived_column();
    ensure_student_sections();
    // Prefer admin-managed sections list if present; otherwise fall back to distinct student sections.
    try {
        $names = list_section_names();
        if ($names) return $names;
    } catch (Throwable) {
    }
    $pdo = db();
    if ($teacherUserId !== null) {
        $stmt = $pdo->prepare(
            "SELECT DISTINCT s.section
             FROM teacher_students ts
             INNER JOIN students s ON s.student_id = ts.student_id
             WHERE ts.teacher_user_id = ? AND s.section IS NOT NULL AND s.section <> ''
               AND " . students_non_archived_sql("s") . "
             ORDER BY s.section ASC"
        );
        $stmt->execute([$teacherUserId]);
        return array_values(array_filter(array_map(static fn($r) => (string)($r["section"] ?? ""), $stmt->fetchAll())));
    }
    $rows = $pdo->query(
        "SELECT DISTINCT section FROM students WHERE section IS NOT NULL AND section <> '' AND COALESCE(is_archived,0) = 0 ORDER BY section ASC"
    )->fetchAll();
    return array_values(array_filter(array_map(static fn($r) => (string)($r["section"] ?? ""), $rows)));
}

