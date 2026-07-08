<?php
declare(strict_types=1);

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/curriculum.php";
require_once __DIR__ . "/subjects.php";
require_once __DIR__ . "/schedule.php";
require_once __DIR__ . "/section_schedule.php";

/**
 * @return array{array<string,bool>, array<string,bool>, bool}
 */
function attendance_teacher_subject_maps(int $teacherUserId): array
{
    ensure_teacher_schedule_table();
    ensure_section_schedule_table();
    $teacherSubjectCodes = [];
    $teacherSubjectNames = [];
    if ($teacherUserId <= 0) {
        return [$teacherSubjectCodes, $teacherSubjectNames, false];
    }
    $stmtTeacherSub = db()->prepare(
        "SELECT DISTINCT subject_code, subject
         FROM teacher_schedule
         WHERE teacher_user_id = ?"
    );
    $stmtTeacherSub->execute([$teacherUserId]);
    foreach ($stmtTeacherSub->fetchAll() as $row) {
        $code = normalize_subject_code((string)($row["subject_code"] ?? ""));
        $name = strtolower(trim((string)($row["subject"] ?? "")));
        if ($code !== "") {
            $teacherSubjectCodes[$code] = true;
        }
        if ($name !== "") {
            $teacherSubjectNames[$name] = true;
        }
    }
    $stmtTeacherSecSub = db()->prepare(
        "SELECT DISTINCT subject_code, subject
         FROM section_schedule
         WHERE teacher_user_id = ?"
    );
    $stmtTeacherSecSub->execute([$teacherUserId]);
    foreach ($stmtTeacherSecSub->fetchAll() as $row) {
        $code = normalize_subject_code((string)($row["subject_code"] ?? ""));
        $name = strtolower(trim((string)($row["subject"] ?? "")));
        if ($code !== "") {
            $teacherSubjectCodes[$code] = true;
        }
        if ($name !== "") {
            $teacherSubjectNames[$name] = true;
        }
    }
    $has = (count($teacherSubjectCodes) + count($teacherSubjectNames)) > 0;
    return [$teacherSubjectCodes, $teacherSubjectNames, $has];
}

/**
 * Distinct subjects assigned to any of the given section names.
 *
 * @param list<string>        $sectionNames
 * @param array<string,bool>  $teacherSubjectCodes normalized codes
 * @param array<string,bool>  $teacherSubjectNames lowercased names
 * @return list<array{subject_id:int,subject_code:string,subject_name:string}>
 */
function attendance_subject_rows_for_sections(array $sectionNames, bool $isTeacher, bool $teacherHasSubjectMap, array $teacherSubjectCodes, array $teacherSubjectNames): array
{
    $trimmed = array_map(static function ($s) {
        return trim((string)$s);
    }, $sectionNames);
    $sectionNames = array_values(array_unique(array_filter($trimmed, static function ($s) {
        return $s !== "";
    })));
    if ($sectionNames === []) {
        return [];
    }
    ensure_subjects_table();
    $ph = implode(",", array_fill(0, count($sectionNames), "?"));
    $stmt = db()->prepare(
        "SELECT DISTINCT sub.subject_id, sub.subject_code, sub.subject_name
         FROM sections sec
         INNER JOIN section_subjects ss ON ss.section_id = sec.section_id
         INNER JOIN subjects sub ON sub.subject_id = ss.subject_id
         WHERE sec.section_name IN ({$ph}) AND sub.is_active = 1
         ORDER BY sub.subject_code ASC, sub.subject_name ASC"
    );
    $stmt->execute($sectionNames);
    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $subId = (int)($row["subject_id"] ?? 0);
        if ($subId <= 0) {
            continue;
        }
        if ($isTeacher && $teacherHasSubjectMap) {
            $code = normalize_subject_code((string)($row["subject_code"] ?? ""));
            $nameLower = strtolower(trim((string)($row["subject_name"] ?? "")));
            $matches = ($code !== "" && isset($teacherSubjectCodes[$code])) || ($nameLower !== "" && isset($teacherSubjectNames[$nameLower]));
            if (!$matches) {
                continue;
            }
        }
        $out[] = [
            "subject_id" => $subId,
            "subject_code" => (string)($row["subject_code"] ?? ""),
            "subject_name" => (string)($row["subject_name"] ?? ""),
        ];
    }
    return $out;
}

/** @param list<array{subject_id:int,subject_code:string,subject_name:string}> $optionRows */
function attendance_normalize_subject_id(int $subjectId, array $optionRows): int
{
    if ($subjectId === 0) {
        return 0;
    }
    foreach ($optionRows as $row) {
        if ((int)$row["subject_id"] === $subjectId) {
            return $subjectId;
        }
    }
    return 0;
}

function ensure_daily_attendance_table(): void
{
    static $done = false;
    if ($done) {
        return;
    }

    db()->exec(
        "CREATE TABLE IF NOT EXISTS daily_attendance (
            attendance_id BIGINT AUTO_INCREMENT PRIMARY KEY,
            student_id INT NOT NULL,
            subject_id BIGINT NOT NULL DEFAULT 0,
            attendance_date DATE NOT NULL,
            status ENUM('Present','Late','Absent') NOT NULL DEFAULT 'Present',
            marked_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_student_date_subject (student_id, attendance_date, subject_id),
            CONSTRAINT fk_daily_att_student FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE,
            CONSTRAINT fk_daily_att_user FOREIGN KEY (marked_by) REFERENCES users(user_id) ON DELETE SET NULL
        ) ENGINE=InnoDB"
    );

    // Add Late status (for older installs where enum was Present/Absent only).
    try {
        $col = db()->query("SHOW COLUMNS FROM `daily_attendance` LIKE 'status'")->fetch();
        $type = strtolower((string)($col["Type"] ?? ""));
        if ($type !== "" && strpos($type, "late") === false) {
            db()->exec("ALTER TABLE `daily_attendance` MODIFY COLUMN `status` ENUM('Present','Late','Absent') NOT NULL DEFAULT 'Present'");
        }
    } catch (Throwable) {
        // ignore
    }

    try {
        $hasSubject = db()->query("SHOW COLUMNS FROM `daily_attendance` LIKE 'subject_id'")->fetch();
        if (!$hasSubject) {
            db()->exec("ALTER TABLE `daily_attendance` ADD COLUMN `subject_id` BIGINT NOT NULL DEFAULT 0 AFTER `student_id`");
        }
    } catch (Throwable) {
        // ignore
    }

    try {
        $oldUniq = db()->query("SHOW INDEX FROM `daily_attendance` WHERE Key_name = 'uniq_student_date'")->fetch();
        if ($oldUniq) {
            db()->exec("ALTER TABLE `daily_attendance` DROP INDEX `uniq_student_date`");
        }
    } catch (Throwable) {
        // ignore
    }

    try {
        $newUniq = db()->query("SHOW INDEX FROM `daily_attendance` WHERE Key_name = 'uniq_student_date_subject'")->fetch();
        if (!$newUniq) {
            db()->exec("ALTER TABLE `daily_attendance` ADD UNIQUE KEY `uniq_student_date_subject` (`student_id`,`attendance_date`,`subject_id`)");
        }
    } catch (Throwable) {
        // ignore (e.g. index already present under another name)
    }

    $done = true;
}

function attendance_term_day_count(?array $termMeta): int
{
    if (!$termMeta) {
        return curriculum_default_total_school_days_per_quarter();
    }
    $termDays = (int)($termMeta["default_total_days"] ?? 0);
    if ($termDays > 0) {
        return $termDays;
    }
    $track = (string)($termMeta["track"] ?? "");
    return $track === "senior_high_school"
        ? curriculum_default_total_school_days_per_shs_quarter()
        : curriculum_default_total_school_days_per_quarter();
}

/**
 * Build ordered class dates for a grading period window.
 *
 * @param list<int> $scheduledDows 1=Mon … 7=Sun; empty = all days count
 * @return list<string> Y-m-d
 */
function attendance_build_school_days(string $startDate, int $termDays, array $scheduledDows = []): array
{
    if ($termDays <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
        return [];
    }
    $dt = DateTimeImmutable::createFromFormat("Y-m-d", $startDate);
    if (!$dt) {
        return [];
    }
    $allowedDows = [];
    foreach ($scheduledDows as $dow) {
        $dow = (int)$dow;
        if ($dow >= 1 && $dow <= 7) {
            $allowedDows[$dow] = true;
        }
    }
    $schoolDays = [];
    while (count($schoolDays) < $termDays) {
        $w = (int)$dt->format("N");
        $ok = $allowedDows === [] || isset($allowedDows[$w]);
        if ($ok) {
            $schoolDays[] = $dt->format("Y-m-d");
        }
        $dt = $dt->modify("+1 day");
    }
    return $schoolDays;
}

/**
 * @param array<string,string> $statusByDate date => Present|Late|Absent|
 * @return array{days_present:int,absences:int,total_school_days:int,consecutive_absences:int}
 */
function attendance_summarize_marks(array $schoolDays, array $statusByDate): array
{
    $total = count($schoolDays);
    $present = 0;
    $maxConsec = 0;
    $curConsec = 0;
    foreach ($schoolDays as $d) {
        $st = (string)($statusByDate[$d] ?? "");
        if ($st === "Present" || $st === "Late") {
            $present++;
            $curConsec = 0;
            continue;
        }
        $curConsec++;
        if ($st === "Absent" || $st === "") {
            $maxConsec = max($maxConsec, $curConsec);
        }
    }
    return [
        "days_present" => $present,
        "absences" => max(0, $total - $present),
        "total_school_days" => $total,
        "consecutive_absences" => $maxConsec,
    ];
}

function attendance_pct(int $present, int $total): ?float
{
    if ($total <= 0) {
        return null;
    }
    return round(($present / $total) * 100.0, 1);
}

/**
 * @param list<int> $studentIds
 * @param list<string> $schoolDays
 * @return array<int,array{days_present:int,absences:int,total_school_days:int,consecutive_absences:int}>
 */
function attendance_summarize_for_students(array $studentIds, int $subjectId, array $schoolDays): array
{
    $studentIds = array_values(array_filter(array_map("intval", $studentIds), static fn(int $id): bool => $id > 0));
    if ($studentIds === [] || $schoolDays === []) {
        return [];
    }
    ensure_daily_attendance_table();
    $ph = implode(",", array_fill(0, count($studentIds), "?"));
    $params = $studentIds;
    $params[] = $schoolDays[0];
    $params[] = $schoolDays[count($schoolDays) - 1];
    $params[] = $subjectId;
    $stmt = db()->prepare(
        "SELECT student_id, attendance_date, status
         FROM daily_attendance
         WHERE student_id IN ({$ph})
           AND attendance_date BETWEEN ? AND ?
           AND subject_id = ?"
    );
    $stmt->execute($params);
    $byStudent = [];
    foreach ($studentIds as $sid) {
        $byStudent[$sid] = [];
    }
    foreach ($stmt->fetchAll() as $row) {
        $sid = (int)($row["student_id"] ?? 0);
        if ($sid <= 0) {
            continue;
        }
        $byStudent[$sid][(string)$row["attendance_date"]] = (string)($row["status"] ?? "");
    }
    $out = [];
    foreach ($studentIds as $sid) {
        $out[$sid] = attendance_summarize_marks($schoolDays, $byStudent[$sid] ?? []);
    }
    return $out;
}

/**
 * @return array{days_present:int,total_school_days:int,absences:int,attendance_pct:?float}
 */
function attendance_display_stats(?array $perf, ?array $computed): array
{
    if (is_array($perf) && (int)($perf["total_school_days"] ?? 0) > 0) {
        $present = (int)($perf["days_present"] ?? 0);
        $total = (int)($perf["total_school_days"] ?? 0);
        return [
            "days_present" => $present,
            "total_school_days" => $total,
            "absences" => (int)($perf["absences"] ?? max(0, $total - $present)),
            "attendance_pct" => attendance_pct($present, $total),
        ];
    }
    if (is_array($computed)) {
        $present = (int)($computed["days_present"] ?? 0);
        $total = (int)($computed["total_school_days"] ?? 0);
        return [
            "days_present" => $present,
            "total_school_days" => $total,
            "absences" => (int)($computed["absences"] ?? max(0, $total - $present)),
            "attendance_pct" => attendance_pct($present, $total),
        ];
    }
    return [
        "days_present" => 0,
        "total_school_days" => 0,
        "absences" => 0,
        "attendance_pct" => null,
    ];
}

/**
 * Push summarized calendar attendance into performance + students.absences.
 *
 * @param list<int> $studentIds
 * @param list<string> $schoolDays
 */
function attendance_sync_performance_records(array $studentIds, string $termId, int $quarter, string $schoolYear, int $subjectId, array $schoolDays): void
{
    if ($termId === "" || $quarter <= 0 || $schoolDays === []) {
        return;
    }
    curriculum_ensure_performance_schema();
    require_once __DIR__ . "/batches.php";
    $summaries = attendance_summarize_for_students($studentIds, $subjectId, $schoolDays);
    if ($summaries === []) {
        return;
    }
    $stmtPerf = db()->prepare(
        "INSERT INTO performance (student_id, school_year, term_id, quarter, gpa, days_present, total_school_days, absences, consecutive_absences)
         VALUES (?,?,?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE
           term_id = VALUES(term_id),
           days_present = VALUES(days_present),
           total_school_days = VALUES(total_school_days),
           absences = VALUES(absences),
           consecutive_absences = VALUES(consecutive_absences)"
    );
    $stmtGpa = db()->prepare("SELECT gpa FROM students WHERE student_id = ? LIMIT 1");
    $stmtStuAbs = db()->prepare("UPDATE students SET absences = ? WHERE student_id = ?");
    foreach ($summaries as $sid => $sum) {
        $sid = (int)$sid;
        $stmtGpa->execute([$sid]);
        $gpaRow = $stmtGpa->fetch();
        $gpa = (float)($gpaRow["gpa"] ?? 0.0);
        $sy = $schoolYear !== "" ? $schoolYear : latest_school_year_for_student($sid);
        $stmtPerf->execute([
            $sid,
            $sy,
            $termId,
            $quarter,
            $gpa,
            (int)$sum["days_present"],
            (int)$sum["total_school_days"],
            (int)$sum["absences"],
            (int)$sum["consecutive_absences"],
        ]);
        $stmtStuAbs->execute([(int)$sum["absences"], $sid]);
    }
}
