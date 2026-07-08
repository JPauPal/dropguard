<?php
declare(strict_types=1);

require_once __DIR__ . "/../../app/auth.php";
require_once __DIR__ . "/../../app/db.php";
require_once __DIR__ . "/../../app/curriculum.php";
require_once __DIR__ . "/../../app/attendance.php";
require_once __DIR__ . "/../../app/grading.php";
require_once __DIR__ . "/../../app/sections.php";
require_once __DIR__ . "/../../app/subjects.php";
require_once __DIR__ . "/../../app/batches.php";
require_once __DIR__ . "/../../app/workflow.php";
require_once __DIR__ . "/../../app/schedule.php";
require_once __DIR__ . "/../../app/section_schedule.php";
require_once __DIR__ . "/../../app/students_archive.php";
require_once __DIR__ . "/../../app/academic_period.php";

require_login();
require_role(["Teacher", "Admin", "Counselor"]);
ensure_workflow_tables();
curriculum_ensure_performance_schema();
ensure_daily_attendance_table();
ensure_grading_components_table();
ensure_grading_period_closures_table();
ensure_student_sections();
ensure_subjects_table();
ensure_teacher_schedule_table();
ensure_section_schedule_table();
ensure_students_archived_column();

function attendance_subject_label(array $row): string
{
    $code = trim((string)($row["subject_code"] ?? ""));
    $name = trim((string)($row["subject_name"] ?? $row["subject"] ?? ""));
    if ($code !== "" && $name !== "") {
        return $code . " - " . $name;
    }
    return $code !== "" ? $code : $name;
}

function sheet_grade_track_key(string $grade): ?string
{
    if ($grade === "" || $grade === "All") {
        return null;
    }
    if (in_array($grade, ["JHS", "Grade 7", "Grade 8", "Grade 9", "Grade 10"], true)) {
        return "junior_high_school";
    }
    if (in_array($grade, ["SHS", "Grade 11", "Grade 12"], true)) {
        return "senior_high_school";
    }
    return null;
}

function sheet_term_allowed_for_grade(string $grade, string $termId): bool
{
    if ($termId === "") {
        return true;
    }
    $tTrack = curriculum_track_for_term_id($termId);
    if ($tTrack === null) {
        return false;
    }
    $gTrack = sheet_grade_track_key($grade);
    return $gTrack !== null && $gTrack === $tTrack;
}

$user = current_user();
$isTeacher = (($user["role"] ?? "") === "Teacher");
$isCounselor = (($user["role"] ?? "") === "Counselor");
$teacherClassCount = 0;
if ($isTeacher) {
    $stmtTc = db()->prepare("SELECT COUNT(*) FROM teacher_students WHERE teacher_user_id = ?");
    $stmtTc->execute([(int)($user["user_id"] ?? 0)]);
    $teacherClassCount = (int)$stmtTc->fetchColumn();
}

start_app_session();
$flash = $_SESSION["flash"] ?? null;
unset($_SESSION["flash"]);
$error = null;

$view = trim((string)($_GET["view"] ?? "sheet"));
$view = in_array($view, ["sheet", "calendar", "grading"], true) ? $view : "sheet";
if ($view === "calendar") {
    // Backward compatibility: old calendar route now uses fused attendance view.
    $view = "sheet";
}

$termId = trim((string)($_GET["term_id"] ?? ""));
$termMeta = $termId !== "" ? curriculum_term_meta($termId) : null;

$month = trim((string)($_GET["month"] ?? date("Y-m"))); // legacy (kept for back-compat links)
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date("Y-m");
}

// Always include weekends (some classes are scheduled on weekends).
$includeWeekends = true;
$hasGradeParam = array_key_exists("grade", $_GET);
$hasSectionNameParam = array_key_exists("section_name", $_GET);
$grade = trim((string)($_GET["grade"] ?? ""));
$sectionName = trim((string)($_GET["section_name"] ?? ""));
$strandFilter = trim((string)($_GET["strand"] ?? ""));
$schoolYear = trim((string)($_GET["school_year"] ?? ""));
if ($schoolYear === "") {
    $schoolYear = academic_period_active_school_year();
}
if ($schoolYear !== "" && !is_valid_school_year_sequence($schoolYear)) {
    $schoolYear = academic_period_active_school_year();
}

// Internal anchor for day columns (D1…Dn) — set by admin in Settings, not shown to staff.
if ($termId !== "" && $schoolYear !== "") {
    $attendanceDate = academic_period_resolve_term_start_date($schoolYear, $termId);
} else {
    $attendanceDate = "";
}
// When opening Student Sheets from nav, default to an empty state (no students)
// until the teacher picks a filter.
if (!$hasGradeParam && !$hasSectionNameParam) {
    $grade = "";
    $sectionName = "";
}
// (Weekends toggle removed.)

$attendanceSubjectId = max(0, (int)($_GET["attendance_subject_id"] ?? 0));

$allTerms = [
    "junior_high_school" => curriculum_performance_terms("junior_high_school"),
    "senior_high_school" => curriculum_performance_terms("senior_high_school"),
];
$closedTermsForYear = [];
foreach (academic_period_list_closures($schoolYear, 50) as $closureRow) {
    $closedTermsForYear[(string)($closureRow["term_id"] ?? "")] = true;
}
$termClosed = ($termId !== "" && $schoolYear !== "" && academic_period_is_term_closed($schoolYear, $termId));
$gradingReadOnly = $isCounselor || $termClosed;

// Student list (scoped for teacher)
if ($grade === "" && $sectionName === "") {
    $students = [];
} elseif ($isTeacher) {
    $stmt = db()->prepare(
        "SELECT s.student_id, s.lrn, s.name, s.grade_level, s.strand, s.section, s.gpa, s.absences
         FROM teacher_students ts
         INNER JOIN students s ON s.student_id = ts.student_id
         WHERE ts.teacher_user_id = ? AND " . students_non_archived_sql("s") . "
         ORDER BY s.name ASC"
    );
    $stmt->execute([(int)$user["user_id"]]);
    $students = $stmt->fetchAll();
} else {
    $students = db()->query(
        "SELECT student_id, lrn, name, grade_level, strand, section, gpa, absences FROM students WHERE COALESCE(is_archived,0) = 0 ORDER BY name ASC LIMIT 300"
    )->fetchAll();
}

// Grade levels (by grade level, plus JHS/SHS buckets)
$grades = ["", "JHS", "SHS", "Grade 7", "Grade 8", "Grade 9", "Grade 10", "Grade 11", "Grade 12"];
if ($grade !== "" && $grade !== "All") {
    $students = array_values(array_filter($students, static function ($s) use ($grade) {
        $g = (string)($s["grade_level"] ?? "");
        if ($grade === "JHS") {
            return in_array($g, ["Grade 7", "Grade 8", "Grade 9", "Grade 10"], true);
        }
        if ($grade === "SHS") {
            return in_array($g, ["Grade 11", "Grade 12"], true);
        }
        return $g === $grade;
    }));
}

// Drop section if it does not belong to the selected grade bucket (e.g. Grade 8 vs "Grade 7 - …").
if ($grade !== "" && $sectionName !== "" && !section_matches_grade_filter($sectionName, $grade)) {
    $sectionName = "";
}
if ($strandFilter !== "" && !in_array($strandFilter, curriculum_shs_strands(), true)) {
    $strandFilter = "";
}
if (!curriculum_strand_filter_applies($grade !== "" ? $grade : null)) {
    $strandFilter = "";
}
if ($strandFilter !== "" && curriculum_strand_filter_applies($grade !== "" ? $grade : null) && $sectionName !== "" && !section_matches_strand_filter($sectionName, $strandFilter)) {
    $sectionName = "";
}

if ($grade === "" || $sectionName === "") {
    $termId = "";
    $termMeta = null;
} elseif ($termId !== "" && !sheet_term_allowed_for_grade($grade, $termId)) {
    $termId = "";
    $termMeta = null;
}
$termSelectEnabled = ($grade !== "" && $sectionName !== "");
$activeGradeTrack = sheet_grade_track_key($grade);
$showJhsTerms = $activeGradeTrack === "junior_high_school";
$showShsTerms = $activeGradeTrack === "senior_high_school";

// Sections (actual section labels encoded in students.section)
$sectionNames = array_values(array_unique(array_merge([""], list_sections($isTeacher ? (int)$user["user_id"] : null))));
if ($sectionName !== "") {
    $students = array_values(array_filter($students, static function ($s) use ($sectionName) {
        return (string)($s["section"] ?? "") === $sectionName;
    }));
}
if ($strandFilter !== "" && curriculum_strand_filter_applies($grade !== "" ? $grade : null)) {
    $students = array_values(array_filter($students, static function ($s) use ($strandFilter) {
        return (string)($s["strand"] ?? "") === $strandFilter;
    }));
}

$studentIds = array_map(static fn($r) => (int)$r["student_id"], $students);
$studentSubjectsById = [];
$sectionsByStudentId = [];
$attendanceSubjectOptionRows = [];
if ($students) {
    $sections = [];
    foreach ($students as $s) {
        $sid = (int)($s["student_id"] ?? 0);
        $sec = trim((string)($s["section"] ?? ""));
        if ($sid > 0) {
            $sectionsByStudentId[$sid] = $sec;
            if ($sec !== "") {
                $sections[$sec] = true;
            }
        }
    }

    $sectionSubjectsMap = [];
    if ($sections) {
        $sectionNamesForSubjects = array_keys($sections);
        if ($grade !== "") {
            $sectionNamesForSubjects = array_values(array_filter($sectionNamesForSubjects, static function ($sn) use ($grade) {
                return section_matches_grade_filter($sn, $grade);
            }));
        }
        if ($sectionNamesForSubjects !== []) {
            $ph = implode(",", array_fill(0, count($sectionNamesForSubjects), "?"));
            $stmtSecSub = db()->prepare(
                "SELECT sec.section_name, sub.subject_code, sub.subject_name
                 FROM sections sec
                 INNER JOIN section_subjects ss ON ss.section_id = sec.section_id
                 INNER JOIN subjects sub ON sub.subject_id = ss.subject_id
                 WHERE sec.section_name IN ({$ph}) AND sub.is_active = 1
                 ORDER BY sub.subject_code ASC"
            );
            $stmtSecSub->execute($sectionNamesForSubjects);
            foreach ($stmtSecSub->fetchAll() as $row) {
                $secName = trim((string)($row["section_name"] ?? ""));
                if ($secName === "") {
                    continue;
                }
                $sectionSubjectsMap[$secName][] = $row;
            }
        }
    }

    [$teacherSubjectCodes, $teacherSubjectNames, $teacherHasSubjectMap] = $isTeacher
        ? attendance_teacher_subject_maps((int)($user["user_id"] ?? 0))
        : [[], [], false];

    $attendanceSubjectOptionRows = attendance_subject_rows_for_sections(
        array_keys($sections),
        $isTeacher,
        $teacherHasSubjectMap,
        $teacherSubjectCodes,
        $teacherSubjectNames
    );

    foreach ($students as $s) {
        $sid = (int)($s["student_id"] ?? 0);
        if ($sid <= 0) {
            continue;
        }
        $sec = $sectionsByStudentId[$sid] ?? "";
        $items = $sec !== "" ? ($sectionSubjectsMap[$sec] ?? []) : [];
        $labels = [];
        foreach ($items as $item) {
            $code = normalize_subject_code((string)($item["subject_code"] ?? ""));
            $nameLower = strtolower(trim((string)($item["subject_name"] ?? "")));
            if ($isTeacher && !$teacherHasSubjectMap) {
                continue;
            }
            if ($isTeacher) {
                $matchesTeacher = ($code !== "" && isset($teacherSubjectCodes[$code])) || ($nameLower !== "" && isset($teacherSubjectNames[$nameLower]));
                if (!$matchesTeacher) {
                    continue;
                }
            }
            $label = attendance_subject_label($item);
            if ($label !== "") {
                $labels[$label] = true;
            }
        }
        $studentSubjectsById[$sid] = array_keys($labels);
    }
}

$attendanceSubjectId = attendance_normalize_subject_id($attendanceSubjectId, $attendanceSubjectOptionRows);

$attendanceSubjectCodeNorm = "";
$attendanceSubjectNameLower = "";
if ($attendanceSubjectId > 0) {
    foreach ($attendanceSubjectOptionRows as $ar) {
        if ((int)$ar["subject_id"] === $attendanceSubjectId) {
            $attendanceSubjectCodeNorm = normalize_subject_code((string)($ar["subject_code"] ?? ""));
            $attendanceSubjectNameLower = strtolower(trim((string)($ar["subject_name"] ?? "")));
            break;
        }
    }
}

// If we can infer the scheduled day(s) for this subject+section, only show those dates.
$scheduledDows = [];
if ($attendanceSubjectId > 0 && $sectionName !== "") {
    try {
        $params = [$sectionName];
        $sql = "SELECT day_of_week, subject_code, subject
                FROM section_schedule
                WHERE section = ?";
        if ($isTeacher) {
            $sql .= " AND teacher_user_id = ?";
            $params[] = (int)($user["user_id"] ?? 0);
        }
        $stmtD = db()->prepare($sql);
        $stmtD->execute($params);
        foreach ($stmtD->fetchAll() as $row) {
            $code = normalize_subject_code((string)($row["subject_code"] ?? ""));
            $nameLower = strtolower(trim((string)($row["subject"] ?? "")));
            $matches = ($attendanceSubjectCodeNorm !== "" && $code === $attendanceSubjectCodeNorm)
                || ($attendanceSubjectNameLower !== "" && $nameLower === $attendanceSubjectNameLower);
            if ($matches) {
                $dow = (int)($row["day_of_week"] ?? 0);
                if ($dow >= 1 && $dow <= 7) {
                    $scheduledDows[$dow] = true;
                }
            }
        }
        $scheduledDows = array_keys($scheduledDows);
        sort($scheduledDows);
    } catch (Throwable) {
        $scheduledDows = [];
    }
}

// Load students once Grade + Section are selected (subject 0 = General for attendance).
if ($grade === "" || $sectionName === "") {
    $students = [];
    $studentIds = [];
} elseif ($view === "grading" && $attendanceSubjectId <= 0) {
    $students = [];
    $studentIds = [];
}

$perfByStudent = [];
if ($studentIds && $termId !== "") {
    $ph = implode(",", array_fill(0, count($studentIds), "?"));
    $params = $studentIds;
    $params[] = $termId;
    $stmtP = db()->prepare(
        "SELECT student_id, term_id, quarter, gpa, days_present, total_school_days, absences
         FROM performance
         WHERE student_id IN ({$ph}) AND term_id = ?"
    );
    $stmtP->execute($params);
    foreach ($stmtP->fetchAll() as $p) {
        $perfByStudent[(int)$p["student_id"]] = $p;
    }
}

$weights = grading_weights();
$extracurricularMax = grading_extracurricular_max();
$componentsByStudent = [];
if ($studentIds && $termId !== "" && $attendanceSubjectId > 0) {
    $ph = implode(",", array_fill(0, count($studentIds), "?"));
    $params = $studentIds;
    $params[] = $termId;
    $params[] = $attendanceSubjectId;
    $stmtC = db()->prepare(
        "SELECT student_id, quiz, exam, project, extracurricular_score, academic_score, initial_score, final_score, is_final
         FROM grading_components
         WHERE student_id IN ({$ph}) AND term_id = ? AND subject_id = ?"
    );
    $stmtC->execute($params);
    foreach ($stmtC->fetchAll() as $r) {
        $componentsByStudent[(int)$r["student_id"]] = $r;
    }
}

$termSchoolDays = [];
$attSummaryByStudent = [];
if ($termMeta && $attendanceDate !== "" && preg_match('/^\d{4}-\d{2}-\d{2}$/', $attendanceDate)) {
    $termSchoolDays = attendance_build_school_days(
        $attendanceDate,
        attendance_term_day_count($termMeta),
        $scheduledDows
    );
}
if ($studentIds && $termSchoolDays !== []) {
    $attSubjectForSummary = $attendanceSubjectId > 0 ? $attendanceSubjectId : 0;
    $attSummaryByStudent = attendance_summarize_for_students($studentIds, $attSubjectForSummary, $termSchoolDays);
}

if (($_SERVER["REQUEST_METHOD"] ?? "GET") === "POST") {
    if ($isCounselor) {
        $error = "Counselor access is view-only for Student Sheets.";
    } else {
    require_valid_csrf_token();
    $postView = trim((string)($_POST["view"] ?? "sheet"));
    $postView = in_array($postView, ["sheet", "calendar", "grading"], true) ? $postView : "sheet";
    if ($postView === "calendar") {
        $postView = "sheet";
    }
    $termId = trim((string)($_POST["term_id"] ?? ""));
    $termMeta = $termId !== "" ? curriculum_term_meta($termId) : null;
    $schoolYear = trim((string)($_POST["school_year"] ?? $schoolYear));
    if ($schoolYear !== "" && !is_valid_school_year_sequence($schoolYear)) {
        $schoolYear = "";
    }
    $grade = trim((string)($_POST["grade"] ?? $grade));
    $sectionName = trim((string)($_POST["section_name"] ?? $sectionName));
    $strandFilter = trim((string)($_POST["strand"] ?? $strandFilter));
    if ($grade !== "" && $sectionName !== "" && !section_matches_grade_filter($sectionName, $grade)) {
        $sectionName = "";
    }
    if ($strandFilter !== "" && !in_array($strandFilter, curriculum_shs_strands(), true)) {
        $strandFilter = "";
    }
    if (!curriculum_strand_filter_applies($grade !== "" ? $grade : null)) {
        $strandFilter = "";
    }
    if ($strandFilter !== "" && curriculum_strand_filter_applies($grade !== "" ? $grade : null) && $sectionName !== "" && !section_matches_strand_filter($sectionName, $strandFilter)) {
        $sectionName = "";
    }
    if ($termId === "" || !$termMeta) {
        $error = "Please select a grading period.";
    } elseif (($blockMsg = academic_period_grading_blocked_message($schoolYear, $termId)) !== null) {
        $error = $blockMsg;
    } elseif ($postView === "sheet") {
        if ($termId !== "" && $schoolYear !== "") {
            $attendanceDate = academic_period_resolve_term_start_date($schoolYear, $termId);
        }
        $includeWeekends = true;

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $markedBy = (int)($user["user_id"] ?? 0);

            // parse day inputs: days[YYYY-MM-DD][student_id] = Present|Late|Absent
            $days = $_POST["days"] ?? [];
            if (!is_array($days)) $days = [];

            // apply grade + section filters for saving
            $gradesAllowed = ["", "JHS", "SHS", "Grade 7", "Grade 8", "Grade 9", "Grade 10", "Grade 11", "Grade 12"];
            if (!in_array($grade, $gradesAllowed, true)) {
                $grade = "";
            }
            $sectionNamesAllowed = array_values(array_unique(array_merge([""], list_sections($isTeacher ? (int)$user["user_id"] : null))));
            if ($sectionName !== "" && !in_array($sectionName, $sectionNamesAllowed, true)) {
                $sectionName = "";
            }

            // Student list is reloaded from DB to ensure teacher scope + correct filtering
            if ($isTeacher) {
                $stmtS = $pdo->prepare(
                    "SELECT s.student_id, s.grade_level, s.strand, s.section
                     FROM teacher_students ts
                     INNER JOIN students s ON s.student_id = ts.student_id
                     WHERE ts.teacher_user_id = ? AND " . students_non_archived_sql("s") . "
                     ORDER BY s.name ASC"
                );
                $stmtS->execute([(int)$user["user_id"]]);
                $saveStudents = $stmtS->fetchAll();
            } else {
                $saveStudents = $pdo->query(
                    "SELECT student_id, grade_level, strand, section FROM students WHERE COALESCE(is_archived,0) = 0 ORDER BY name ASC LIMIT 300"
                )->fetchAll();
            }
            if ($grade !== "") {
                $saveStudents = array_values(array_filter($saveStudents, static function ($s) use ($grade) {
                    $g = (string)($s["grade_level"] ?? "");
                    if ($grade === "JHS") return in_array($g, ["Grade 7", "Grade 8", "Grade 9", "Grade 10"], true);
                    if ($grade === "SHS") return in_array($g, ["Grade 11", "Grade 12"], true);
                    return $g === $grade;
                }));
            }
            if ($sectionName !== "") {
                $saveStudents = array_values(array_filter($saveStudents, static function ($s) use ($sectionName) {
                    return (string)($s["section"] ?? "") === $sectionName;
                }));
            }
            if ($strandFilter !== "" && curriculum_strand_filter_applies($grade !== "" ? $grade : null)) {
                $saveStudents = array_values(array_filter($saveStudents, static function ($s) use ($strandFilter) {
                    return (string)($s["strand"] ?? "") === $strandFilter;
                }));
            }

            $postSaveSubjectRaw = max(0, (int)($_POST["attendance_subject_id"] ?? 0));
            $postSections = [];
            foreach ($saveStudents as $sx) {
                $sn = trim((string)($sx["section"] ?? ""));
                if ($sn !== "") {
                    $postSections[$sn] = true;
                }
            }
            [$postTCodes, $postTNames, $postTHas] = $isTeacher
                ? attendance_teacher_subject_maps((int)($user["user_id"] ?? 0))
                : [[], [], false];
            $postSubjectOptions = attendance_subject_rows_for_sections(array_keys($postSections), $isTeacher, $postTHas, $postTCodes, $postTNames);
            $saveAttendanceSubjectId = attendance_normalize_subject_id($postSaveSubjectRaw, $postSubjectOptions);
            if ($grade === "" || $sectionName === "") {
                throw new RuntimeException("Please select Grade and Section.");
            }
            if ($postSaveSubjectRaw > 0 && $saveAttendanceSubjectId <= 0) {
                throw new RuntimeException("Please select a valid attendance subject.");
            }

            $stmtUpAtt = $pdo->prepare(
                "INSERT INTO daily_attendance (student_id, subject_id, attendance_date, status, marked_by)
                 VALUES (?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE status = VALUES(status), marked_by = VALUES(marked_by)"
            );

            $allowed = ["Present" => true, "Late" => true, "Absent" => true];
            $termDays = (int)($termMeta["default_total_days"] ?? curriculum_default_total_school_days_per_quarter());
            if ($termDays <= 0) {
                $termDays = curriculum_default_total_school_days_per_quarter();
            }
            // Generate the next N class days starting from attendance_date.
            $schoolDays = [];
            $dt = DateTimeImmutable::createFromFormat("Y-m-d", $attendanceDate) ?: new DateTimeImmutable(date("Y-m-01"));
            $saveSubjectCodeNorm = "";
            $saveSubjectNameLower = "";
            foreach ($postSubjectOptions as $opt) {
                if ((int)$opt["subject_id"] === $saveAttendanceSubjectId) {
                    $saveSubjectCodeNorm = normalize_subject_code((string)($opt["subject_code"] ?? ""));
                    $saveSubjectNameLower = strtolower(trim((string)($opt["subject_name"] ?? "")));
                    break;
                }
            }
            $saveScheduledDows = [];
            try {
                $params2 = [$sectionName];
                $sql2 = "SELECT day_of_week, subject_code, subject
                         FROM section_schedule
                         WHERE section = ?";
                if ($isTeacher) {
                    $sql2 .= " AND teacher_user_id = ?";
                    $params2[] = (int)($user["user_id"] ?? 0);
                }
                $stmtD2 = $pdo->prepare($sql2);
                $stmtD2->execute($params2);
                foreach ($stmtD2->fetchAll() as $row) {
                    $code = normalize_subject_code((string)($row["subject_code"] ?? ""));
                    $nameLower = strtolower(trim((string)($row["subject"] ?? "")));
                    $matches = ($saveSubjectCodeNorm !== "" && $code === $saveSubjectCodeNorm)
                        || ($saveSubjectNameLower !== "" && $nameLower === $saveSubjectNameLower);
                    if ($matches) {
                        $dow = (int)($row["day_of_week"] ?? 0);
                        if ($dow >= 1 && $dow <= 7) {
                            $saveScheduledDows[$dow] = true;
                        }
                    }
                }
                $saveScheduledDows = array_keys($saveScheduledDows);
            } catch (Throwable) {
                $saveScheduledDows = [];
            }
            while (count($schoolDays) < $termDays) {
                $w = (int)$dt->format("N"); // 1..7
                $ok = true;
                if ($saveScheduledDows !== []) {
                    $ok = in_array($w, $saveScheduledDows, true);
                }
                if ($ok) {
                    $schoolDays[] = $dt->format("Y-m-d");
                }
                $dt = $dt->modify("+1 day");
            }

            // Save only cells that were explicitly set (Present/Late/Absent). Blank means "don't touch".
            foreach ($schoolDays as $date) {
                $dayMap = $days[$date] ?? [];
                if (!is_array($dayMap)) $dayMap = [];
                foreach ($saveStudents as $s) {
                    $sid = (int)($s["student_id"] ?? 0);
                    if ($sid <= 0) continue;
                    $val = (string)($dayMap[(string)$sid] ?? "");
                    if (!isset($allowed[$val])) {
                        continue;
                    }
                    $stmtUpAtt->execute([$sid, $saveAttendanceSubjectId, $date, $val, $markedBy]);
                }
            }

            $saveStudentIds = array_values(array_filter(array_map(
                static fn($s) => (int)($s["student_id"] ?? 0),
                $saveStudents
            ), static fn(int $id): bool => $id > 0));
            if ($saveStudentIds !== [] && $termId !== "" && $termMeta) {
                attendance_sync_performance_records(
                    $saveStudentIds,
                    $termId,
                    (int)$termMeta["term_sort"],
                    $schoolYear,
                    $saveAttendanceSubjectId,
                    $schoolDays
                );
            }

            $pdo->commit();
            audit_log("attendance_calendar_save", "success", "daily_attendance", null, "Saved calendar attendance.", [
                "term_id" => $termId,
                "start_date" => $attendanceDate,
                "grade" => $grade,
                "section_name" => $sectionName,
                "strand" => $strandFilter,
                "subject_id" => $saveAttendanceSubjectId,
            ]);
            $_SESSION["flash"] = "Attendance saved.";
            $calLoc = array_filter([
                "view" => "sheet",
                "term_id" => $termId,
                "grade" => $grade,
                "section_name" => $sectionName,
                "strand" => $strandFilter,
                "school_year" => $schoolYear,
                "attendance_subject_id" => $saveAttendanceSubjectId > 0 ? (string)$saveAttendanceSubjectId : "",
            ], static fn($v) => $v !== null && $v !== "");
            header("Location: student-sheets?" . http_build_query($calLoc));
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = "Failed to save calendar attendance: " . $e->getMessage();
        }
    } elseif ($postView === "grading") {
        $postSaveSubjectRaw = max(0, (int)($_POST["attendance_subject_id"] ?? 0));
        [$postTCodes, $postTNames, $postTHas] = $isTeacher
            ? attendance_teacher_subject_maps((int)($user["user_id"] ?? 0))
            : [[], [], false];
        // Validate the selected subject against the selected section (not the currently loaded student list),
        // because $students can be empty during POST depending on prior GET state.
        $postSubjectOptions = attendance_subject_rows_for_sections(
            $sectionName !== "" ? [$sectionName] : [],
            $isTeacher,
            $postTHas,
            $postTCodes,
            $postTNames
        );
        $saveGradeSubjectId = attendance_normalize_subject_id($postSaveSubjectRaw, $postSubjectOptions);
        if ($saveGradeSubjectId <= 0) {
            $error = "Please select a subject for the grading sheet.";
        } else {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $quarter = (int)$termMeta["term_sort"];

            // Update weights (optional) — must total 100%.
            $wQuiz = (float)($_POST["w_quiz"] ?? $weights["quiz"]);
            $wExam = (float)($_POST["w_exam"] ?? $weights["exam"]);
            $wProj = (float)($_POST["w_project"] ?? $weights["project"]);
            $sum = $wQuiz + $wExam + $wProj;
            if (abs($sum - 100.0) > 0.0001) {
                throw new RuntimeException("Grading weights total must be 100.");
            }
            if ($sum > 0) {
                set_setting("grade_weight_quiz", (string)$wQuiz);
                set_setting("grade_weight_exam", (string)$wExam);
                set_setting("grade_weight_project", (string)$wProj);
                set_setting("weight_quiz", (string)$wQuiz);
                set_setting("weight_exam", (string)$wExam);
                set_setting("weight_project", (string)$wProj);
                $weights = grading_weights();
            }

            $finalize = (string)($_POST["finalize"] ?? "") === "1";
            $actorId = (int)($user["user_id"] ?? 0);

            $stmtGrade = $pdo->prepare("SELECT grade_level, COALESCE(is_archived,0) AS is_archived FROM students WHERE student_id = ? LIMIT 1");
            $stmtPerfLookup = $pdo->prepare(
                "SELECT days_present, total_school_days, absences, consecutive_absences
                 FROM performance
                 WHERE student_id = ? AND term_id = ? AND school_year <=> ?
                 LIMIT 1"
            );

            $gradeSaveAttendanceDate = ($termId !== "" && $schoolYear !== "")
                ? academic_period_resolve_term_start_date($schoolYear, $termId)
                : "";
            $gradeSaveSchoolDays = [];
            if ($termMeta && preg_match('/^\d{4}-\d{2}-\d{2}$/', $gradeSaveAttendanceDate)) {
                $gradeSaveSchoolDays = attendance_build_school_days(
                    $gradeSaveAttendanceDate,
                    attendance_term_day_count($termMeta),
                    []
                );
            }

            $rowsIn = $_POST["rows"] ?? [];
            if (!is_array($rowsIn)) {
                $rowsIn = [];
            }

            foreach ($rowsIn as $sidStr => $row) {
                $sid = (int)$sidStr;
                if ($sid <= 0 || !is_array($row)) {
                    continue;
                }

                if ($isTeacher) {
                    $chk = $pdo->prepare("SELECT 1 FROM teacher_students WHERE teacher_user_id = ? AND student_id = ? LIMIT 1");
                    $chk->execute([(int)$user["user_id"], $sid]);
                    if (!$chk->fetch()) {
                        continue;
                    }
                }

                $stmtGrade->execute([$sid]);
                $stu = $stmtGrade->fetch();
                if (!$stu) {
                    continue;
                }
                if ((int)($stu["is_archived"] ?? 0) === 1) {
                    continue;
                }
                if (!curriculum_valid_term_for_grade((string)($stu["grade_level"] ?? ""), $termId)) {
                    continue;
                }

                $existing = $componentsByStudent[$sid] ?? null;
                $unlockRow = !empty($row["unlock"]) && in_array((string)($user["role"] ?? ""), ["Admin", "Counselor"], true);

                $quiz = (float)($row["quiz"] ?? 0);
                $exam = (float)($row["exam"] ?? 0);
                $project = (float)($row["project"] ?? 0);
                $extra = (float)($row["extracurricular_score"] ?? 0);

                $sy = $schoolYear !== "" ? $schoolYear : latest_school_year_for_student($sid);
                $attendanceOverride = null;
                $stmtPerfLookup->execute([$sid, $termId, $sy !== "" ? $sy : null]);
                $perfRow = $stmtPerfLookup->fetch();
                if ($perfRow && (int)($perfRow["total_school_days"] ?? 0) > 0) {
                    $attendanceOverride = [
                        "days_present" => (int)($perfRow["days_present"] ?? 0),
                        "total_school_days" => (int)($perfRow["total_school_days"] ?? 0),
                        "absences" => (int)($perfRow["absences"] ?? max(0, (int)($perfRow["total_school_days"] ?? 0) - (int)($perfRow["days_present"] ?? 0))),
                        "consecutive_absences" => (int)($perfRow["consecutive_absences"] ?? 0),
                    ];
                } elseif ($gradeSaveSchoolDays !== []) {
                    $sumAtt = attendance_summarize_for_students([$sid], $saveGradeSubjectId, $gradeSaveSchoolDays);
                    if (isset($sumAtt[$sid])) {
                        $attendanceOverride = [
                            "days_present" => (int)$sumAtt[$sid]["days_present"],
                            "total_school_days" => (int)$sumAtt[$sid]["total_school_days"],
                            "absences" => (int)$sumAtt[$sid]["absences"],
                            "consecutive_absences" => (int)$sumAtt[$sid]["consecutive_absences"],
                        ];
                    }
                }

                $result = grading_persist_student_subject(
                    $pdo,
                    $sid,
                    $termId,
                    $saveGradeSubjectId,
                    $quiz,
                    $exam,
                    $project,
                    $extra,
                    $sy !== "" ? $sy : null,
                    $quarter,
                    $finalize,
                    $actorId,
                    (string)($user["role"] ?? ""),
                    $weights,
                    $attendanceOverride,
                    $unlockRow
                );
                if (!$result["ok"]) {
                    if (is_array($existing) && (int)($existing["is_final"] ?? 0) === 1 && !$unlockRow) {
                        continue;
                    }
                    throw new RuntimeException((string)($result["error"] ?? "Invalid grading input."));
                }
            }

            $pdo->commit();
            audit_log("grading_sheet_save", "success", "grading_components", null, "Saved grading sheet.", [
                "term_id" => $termId,
                "quarter" => $quarter,
                "subject_id" => $saveGradeSubjectId,
                "finalized" => $finalize,
            ]);
            if ($finalize) {
                audit_log("grading_sheet_finalize", "success", "grading_components", null, "Finalized grading sheet.", [
                    "term_id" => $termId,
                    "quarter" => $quarter,
                    "subject_id" => $saveGradeSubjectId,
                ]);
            }
            $_SESSION["flash"] = "Grading sheet saved.";
            $gLoc = array_filter([
                "view" => "grading",
                "term_id" => $termId,
                "grade" => $grade,
                "section_name" => $sectionName,
                "strand" => $strandFilter,
                "school_year" => $schoolYear,
                "attendance_subject_id" => (string)$saveGradeSubjectId,
            ], static fn($v) => $v !== null && $v !== "");
            header("Location: student-sheets?" . http_build_query($gLoc));
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = $e->getMessage() === "Grading weights total must be 100."
                ? "Grading weights total must be 100."
                : "Failed to save grading sheet: " . $e->getMessage();
        }
        }
    } else {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $quarter = (int)$termMeta["term_sort"];
            $stmtGrade = $pdo->prepare("SELECT grade_level, gpa, COALESCE(is_archived,0) AS is_archived FROM students WHERE student_id = ? LIMIT 1");
            $stmtPerf = $pdo->prepare(
                "INSERT INTO performance (student_id, school_year, term_id, quarter, gpa, days_present, total_school_days, absences, consecutive_absences)
                 VALUES (?,?,?,?,?,?,?, ?,0)
                 ON DUPLICATE KEY UPDATE
                   term_id = VALUES(term_id),
                   days_present = VALUES(days_present),
                   total_school_days = VALUES(total_school_days),
                   absences = VALUES(absences)"
            );
            $stmtStuAbs = $pdo->prepare("UPDATE students SET absences = ? WHERE student_id = ?");

            $rowsIn = $_POST["rows"] ?? [];
            if (!is_array($rowsIn)) {
                $rowsIn = [];
            }

            foreach ($rowsIn as $sidStr => $row) {
                $sid = (int)$sidStr;
                if ($sid <= 0 || !is_array($row)) continue;

                // teacher scope check
                if ($isTeacher) {
                    $chk = $pdo->prepare("SELECT 1 FROM teacher_students WHERE teacher_user_id = ? AND student_id = ? LIMIT 1");
                    $chk->execute([(int)$user["user_id"], $sid]);
                    if (!$chk->fetch()) {
                        continue;
                    }
                }

                $stmtGrade->execute([$sid]);
                $stu = $stmtGrade->fetch();
                if (!$stu) continue;
                if ((int)($stu["is_archived"] ?? 0) === 1) {
                    continue;
                }
                if (!curriculum_valid_term_for_grade((string)($stu["grade_level"] ?? ""), $termId)) {
                    continue;
                }

                $total = max(0, (int)($row["total"] ?? 0));
                $present = max(0, (int)($row["present"] ?? 0));
                if ($total > 0 && $present > $total) {
                    $present = $total;
                }
                $abs = max(0, $total - $present);
                $gpa = (float)($stu["gpa"] ?? 0);

                $sy = $schoolYear !== "" ? $schoolYear : latest_school_year_for_student($sid);
                $stmtPerf->execute([$sid, $sy, $termId, $quarter, $gpa, $present, $total, $abs]);
                $stmtStuAbs->execute([$abs, $sid]);
            }

            $pdo->commit();
            audit_log("attendance_sheet_save", "success", "performance", null, "Saved attendance sheet.", [
                "term_id" => $termId,
                "quarter" => (int)$termMeta["term_sort"],
            ]);
            $_SESSION["flash"] = "Attendance sheet saved.";
            $sLoc = array_filter([
                "view" => "sheet",
                "term_id" => $termId,
                "grade" => $grade,
                "section_name" => $sectionName,
                "strand" => $strandFilter,
                "school_year" => $schoolYear,
            ], static fn($v) => $v !== null && $v !== "");
            header("Location: student-sheets?" . http_build_query($sLoc));
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = "Failed to save attendance sheet: " . $e->getMessage();
        }
    }
    }
}

if (($_SERVER["REQUEST_METHOD"] ?? "GET") === "POST" && array_key_exists("attendance_subject_id", $_POST)) {
    $attendanceSubjectId = attendance_normalize_subject_id(
        max(0, (int)($_POST["attendance_subject_id"] ?? 0)),
        $attendanceSubjectOptionRows
    );
}

$dgSheetNav = static function (string $viewTarget) use ($termId, $grade, $sectionName, $strandFilter, $schoolYear, $attendanceSubjectId): string {
    $pairs = [
        "view" => $viewTarget,
        "term_id" => $termId,
        "grade" => $grade,
        "section_name" => $sectionName,
        "strand" => $strandFilter,
        "school_year" => $schoolYear,
    ];
    $pairs = array_filter($pairs, static fn($v) => $v !== null && $v !== "");
    if ($viewTarget === "sheet" && $attendanceSubjectId > 0) {
        $pairs["attendance_subject_id"] = (string)$attendanceSubjectId;
    }
    return "student-sheets?" . http_build_query($pairs);
};

ob_start();
?>
<div class="dg-page-fill">
<div class="dg-page-header mb-3">
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
    <div>
      <h3 class="mb-0">Student Sheets</h3>
      <div class="text-muted small">Attendance and grading sheets per grading period</div>
    </div>
  </div>
</div>

<?php if ($isTeacher && $teacherClassCount === 0): ?>
  <div class="alert alert-info mb-3">
    Your class list is empty. Add students on <a href="<?= htmlspecialchars(base_url("data-entry"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" class="alert-link">Data Entry</a>
    (encode a new student or check existing ones and use <strong>Add selected to my class</strong>). They will then appear here on Student Sheets.
  </div>
<?php endif; ?>
<?php if ($flash): ?>
  <div class="alert alert-info"><?= htmlspecialchars((string)$flash, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></div>
<?php endif; ?>
<?php if ($error): ?>
  <div class="alert alert-danger"><?= htmlspecialchars((string)$error, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></div>
<?php endif; ?>
<?php if ($termClosed): ?>
  <div class="alert alert-warning">This grading period is closed for <?= htmlspecialchars($schoolYear, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>. Attendance and grades are read-only. Contact an administrator to reopen the period or switch to the active semester.</div>
<?php endif; ?>

<div class="card shadow-sm mb-3 dg-sheet-filter-card">
  <div class="card-body">
    <ul class="nav nav-pills gap-2 mb-3">
      <li class="nav-item">
        <a class="nav-link <?= $view === "sheet" ? "active" : "" ?>" href="<?= htmlspecialchars($dgSheetNav("sheet"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>"><?= htmlspecialchars(t("tabs.attendance"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></a>
      </li>
      <li class="nav-item">
        <a class="nav-link <?= $view === "grading" ? "active" : "" ?>" href="<?= htmlspecialchars($dgSheetNav("grading"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>"><?= htmlspecialchars(t("tabs.grading_sheet"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></a>
      </li>
    </ul>

    <form id="dgStudentSheetsFilter" class="dg-sheet-filter" method="get" action="student-sheets">
      <input type="hidden" name="view" value="<?= htmlspecialchars($view, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" />
      <div class="dg-sheet-filter-row">
        <div class="dg-sheet-filter-field dg-sheet-filter-field--grade">
          <label class="form-label mb-1">Grade level</label>
          <select class="form-select" name="grade" id="dgSheetFilterGrade">
            <?php foreach ($grades as $g): ?>
              <?php $gTrack = sheet_grade_track_key($g); ?>
              <option value="<?= htmlspecialchars($g, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>"<?= $gTrack !== null ? ' data-grade-track="' . htmlspecialchars($gTrack, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") . '"' : "" ?> <?= $g === $grade ? "selected" : "" ?>>
                <?= htmlspecialchars($g === "" ? "Select grade level..." : $g, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="dg-sheet-filter-field dg-sheet-filter-field--strand <?= curriculum_strand_filter_applies($grade !== "" ? $grade : null) ? "" : "d-none" ?>" id="dgSheetStrandWrap">
          <label class="form-label mb-1">Strand</label>
          <select class="form-select" name="strand" id="dgSheetStrand" <?= curriculum_strand_filter_applies($grade !== "" ? $grade : null) ? "" : "disabled" ?>>
            <option value="">All</option>
            <?php curriculum_echo_shs_strand_options($strandFilter); ?>
          </select>
        </div>
        <div class="dg-sheet-filter-field dg-sheet-filter-field--section">
          <label class="form-label mb-1">Section</label>
          <select class="form-select" name="section_name" id="dgSheetFilterSection" <?= $grade !== "" ? "" : "disabled" ?>>
            <?php foreach ($sectionNames as $sn): ?>
              <?php if ($sn === ""): ?>
                <option value=""><?= htmlspecialchars($grade !== "" ? "Select section..." : "Choose grade level first", ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></option>
              <?php else: ?>
                <?php
                  $secGl = section_grade_level_for_name($sn) ?? "";
                  $secSt = section_infer_strand_from_name($sn) ?? "";
                ?>
                <option value="<?= htmlspecialchars($sn, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" data-section-grade="<?= htmlspecialchars($secGl, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" data-section-strand="<?= htmlspecialchars($secSt, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" <?= $sn === $sectionName ? "selected" : "" ?>>
                  <?= htmlspecialchars(section_display_short($sn), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>
                </option>
              <?php endif; ?>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="dg-sheet-filter-field dg-sheet-filter-field--term">
          <label class="form-label mb-1">Grading period</label>
          <select class="form-select" name="term_id" id="dgSheetTermId" <?= $termSelectEnabled ? "" : "disabled" ?> title="<?= $termSelectEnabled ? "" : "Select grade level and section first" ?>">
            <option value=""><?= htmlspecialchars($termSelectEnabled ? "Select grading period..." : "Choose grade level and section first", ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></option>
            <optgroup id="dgSheetTermJhs" label="JHS (Grades 7-10)"<?= $showJhsTerms ? "" : " hidden" ?>>
              <?php foreach ($allTerms["junior_high_school"] as $t): ?>
                <?php
                  $tid = (string)$t["term_id"];
                  $termLabel = (string)$t["name"] . (isset($closedTermsForYear[$tid]) ? " (Closed)" : "");
                ?>
                <option value="<?= htmlspecialchars($tid, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" data-term-track="junior_high_school" <?= $tid === $termId ? "selected" : "" ?>>
                  <?= htmlspecialchars($termLabel, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>
                </option>
              <?php endforeach; ?>
            </optgroup>
            <optgroup id="dgSheetTermShs" label="SHS (Grades 11-12)"<?= $showShsTerms ? "" : " hidden" ?>>
              <?php foreach ($allTerms["senior_high_school"] as $t): ?>
                <?php
                  $tid = (string)$t["term_id"];
                  $baseLabel = ($t["semester_name"] ? ($t["semester_name"] . " — ") : "") . $t["name"];
                  $termLabel = $baseLabel . (isset($closedTermsForYear[$tid]) ? " (Closed)" : "");
                ?>
                <option value="<?= htmlspecialchars($tid, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" data-term-track="senior_high_school" <?= $tid === $termId ? "selected" : "" ?>>
                  <?= htmlspecialchars($termLabel, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>
                </option>
              <?php endforeach; ?>
            </optgroup>
          </select>
        </div>
      </div>
      <div class="dg-sheet-filter-row">
        <div class="dg-sheet-filter-field dg-sheet-filter-field--sy">
          <label class="form-label mb-1">School year</label>
          <?php
            $schoolYears = db()->query("SELECT DISTINCT school_year FROM student_batches ORDER BY school_year DESC")->fetchAll();
          ?>
          <select class="form-select" name="school_year" id="dgSheetSchoolYear">
            <option value="">All</option>
            <?php foreach ($schoolYears as $syRow): ?>
              <?php $v = (string)($syRow["school_year"] ?? ""); ?>
              <option value="<?= htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" <?= $v === $schoolYear ? "selected" : "" ?>>
                <?= htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php if (in_array($view, ["sheet", "grading"], true)): ?>
          <div class="dg-sheet-filter-field dg-sheet-filter-field--subject">
            <label class="form-label mb-1">Subject</label>
            <select class="form-select" name="attendance_subject_id" id="dgSheetAttendanceSubject">
              <option value="0" <?= $attendanceSubjectId === 0 ? "selected" : "" ?>>General (whole day / not split by subject)</option>
              <?php foreach ($attendanceSubjectOptionRows as $arow): ?>
                <?php $sidOpt = (int)$arow["subject_id"]; ?>
                <option value="<?= $sidOpt ?>" <?= $sidOpt === $attendanceSubjectId ? "selected" : "" ?>>
                  <?= htmlspecialchars(attendance_subject_label(["subject_code" => $arow["subject_code"], "subject_name" => $arow["subject_name"]]), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        <?php endif; ?>
        <div class="dg-sheet-filter-field dg-sheet-filter-field--load">
          <label class="form-label mb-1 d-none d-md-block" aria-hidden="true">&nbsp;</label>
          <button class="btn btn-dark w-100" type="submit">Load</button>
        </div>
      </div>
    </form>
    <div class="form-text small mt-2">Choose <strong>grade level</strong> and <strong>section</strong> first, then <strong>grading period</strong>. Attendance uses school days <strong>D1</strong> through <strong>D<?= (int)curriculum_default_total_school_days_per_quarter() ?></strong> for the term.</div>
  </div>
</div>

<script>
  (function () {
    const form = document.getElementById("dgStudentSheetsFilter");
    const gEl = document.getElementById("dgSheetFilterGrade");
    const sEl = document.getElementById("dgSheetFilterSection");
    const strandWrap = document.getElementById("dgSheetStrandWrap");
    const strandSel = document.getElementById("dgSheetStrand");
    const syEl = document.getElementById("dgSheetSchoolYear");
    const termEl = document.getElementById("dgSheetTermId");
    const attSubEl = document.getElementById("dgSheetAttendanceSubject");
    if (!form || !gEl || !sEl) return;

    function termTrackForGrade(g) {
      if (!g) return "";
      if (g === "JHS" || g === "Grade 7" || g === "Grade 8" || g === "Grade 9" || g === "Grade 10") {
        return "junior_high_school";
      }
      if (g === "SHS" || g === "Grade 11" || g === "Grade 12") {
        return "senior_high_school";
      }
      return "";
    }
    const jhsTermGroup = document.getElementById("dgSheetTermJhs");
    const shsTermGroup = document.getElementById("dgSheetTermShs");
    function syncGradeLevelOptions() {
      const cur = gEl.value || "";
      const track = termTrackForGrade(cur);
      for (let i = 0; i < gEl.options.length; i++) {
        const opt = gEl.options[i];
        if (!opt.value) {
          opt.hidden = false;
          continue;
        }
        const optTrack = opt.getAttribute("data-grade-track") || "";
        if (!track) {
          opt.hidden = false;
          continue;
        }
        opt.hidden = optTrack !== "" && optTrack !== track;
      }
    }
    function syncTermOptions() {
      if (!termEl) return;
      const g = gEl.value || "";
      const sec = sEl.value || "";
      const track = termTrackForGrade(g);
      const enabled = !!(g && sec);
      termEl.disabled = !enabled;
      if (!enabled) {
        termEl.value = "";
        termEl.title = "Select grade level and section first";
      } else {
        termEl.title = "";
      }
      const placeholder = termEl.options[0];
      if (placeholder && !placeholder.value) {
        placeholder.textContent = enabled ? "Select grading period..." : "Choose grade level and section first";
      }
      if (jhsTermGroup) {
        jhsTermGroup.hidden = track !== "junior_high_school";
      }
      if (shsTermGroup) {
        shsTermGroup.hidden = track !== "senior_high_school";
      }
      for (let i = 0; i < termEl.options.length; i++) {
        const opt = termEl.options[i];
        if (!opt.value) continue;
        const optTrack = opt.getAttribute("data-term-track") || "";
        opt.hidden = track !== "" && optTrack !== "" && optTrack !== track;
      }
      const sel = termEl.selectedOptions[0];
      if (sel && (sel.hidden || (sel.value && track && sel.getAttribute("data-term-track") !== track))) {
        termEl.value = "";
      }
    }
    function syncSectionPlaceholder() {
      const g = gEl.value || "";
      const blank = sEl.options[0];
      if (blank && !blank.value) {
        blank.textContent = g ? "Select section..." : "Choose grade level first";
      }
    }
    function sectionVisibleForGrade(g, og) {
      if (!g) return false;
      if (!og) return true;
      if (g === "JHS") return ["Grade 7", "Grade 8", "Grade 9", "Grade 10"].indexOf(og) !== -1;
      if (g === "SHS") return ["Grade 11", "Grade 12"].indexOf(og) !== -1;
      return og === g;
    }
    function strandApplies(g) {
      return g === "SHS" || g === "Grade 11" || g === "Grade 12";
    }
    function syncSectionOptions() {
      const g = gEl.value || "";
      const strand = strandSel && !strandSel.disabled ? String(strandSel.value || "") : "";
      for (let i = 0; i < sEl.options.length; i++) {
        const opt = sEl.options[i];
        if (!opt.value) {
          opt.hidden = false;
          continue;
        }
        const og = opt.getAttribute("data-section-grade") || "";
        const hideByGrade = !sectionVisibleForGrade(g, og);
        const st = opt.getAttribute("data-section-strand") || "";
        const hideByStrand = !!(strand && strandApplies(g) && st !== strand);
        opt.hidden = hideByGrade || hideByStrand;
      }
      const sel = sEl.selectedOptions[0];
      if (sel && sel.hidden) sEl.value = "";
      sEl.disabled = !g;
      syncSectionPlaceholder();
      syncTermOptions();
    }
    function syncStrandUi() {
      if (!strandWrap || !strandSel) return;
      const g = gEl.value || "";
      const on = strandApplies(g);
      strandWrap.classList.toggle("d-none", !on);
      strandSel.disabled = !on;
      if (!on) strandSel.value = "";
    }
    function submitFilter() {
      if (typeof form.requestSubmit === "function") form.requestSubmit();
      else form.submit();
    }
    function onGradeChange() {
      syncGradeLevelOptions();
      syncStrandUi();
      syncSectionOptions();
      submitFilter();
    }
    function onSectionChange() {
      syncTermOptions();
      submitFilter();
    }
    function onStrandChange() {
      syncSectionOptions();
      submitFilter();
    }
    gEl.addEventListener("change", onGradeChange);
    if (strandSel) strandSel.addEventListener("change", onStrandChange);
    sEl.addEventListener("change", onSectionChange);
    [syEl, termEl, attSubEl].forEach(function (el) {
      if (!el) return;
      el.addEventListener("change", submitFilter);
    });
    syncGradeLevelOptions();
    syncStrandUi();
    syncSectionOptions();
    syncTermOptions();
  })();
</script>

<?php if ($grade === "" || $sectionName === "" || ($view === "grading" && $attendanceSubjectId <= 0)): ?>
  <div class="alert alert-secondary">
    <?php if ($view === "grading" && $grade !== "" && $sectionName !== "" && $attendanceSubjectId <= 0): ?>
      Select a <strong>Subject</strong> for the grading sheet, then click <strong>Load</strong>.
    <?php else: ?>
      Select <strong>Grade level</strong>, then <strong>Section</strong>, then <strong>Grading period</strong><?= $view === "sheet" ? "" : ", plus a <strong>Subject</strong>," ?> and click <strong>Load</strong> to show students.
    <?php endif; ?>
  </div>
<?php else: ?>

<div class="dg-sheet-data-panel dg-flex-1">

<?php if ($view === "sheet"): ?>
  <?php
    $termDays = (int)($termMeta["default_total_days"] ?? curriculum_default_total_school_days_per_quarter());
    if ($termDays <= 0) {
        $termDays = curriculum_default_total_school_days_per_quarter();
    }
    // Generate N class days (term view) starting from attendance_date.
    $schoolDays = [];
    $dt = DateTimeImmutable::createFromFormat("Y-m-d", $attendanceDate) ?: new DateTimeImmutable(date("Y-m-01"));
    while (count($schoolDays) < $termDays) {
        $w = (int)$dt->format("N");
        $ok = true;
        if ($scheduledDows !== []) {
            $ok = in_array($w, $scheduledDows, true);
        }
        if ($ok) {
            $schoolDays[] = $dt->format("Y-m-d");
        }
        $dt = $dt->modify("+1 day");
    }
    $calendarDayMeta = [];
    foreach ($schoolDays as $i => $d) {
        $calendarDayMeta[] = [
            "date" => $d,
            "idx" => $i + 1,
        ];
    }

    $attByDateStudent = [];
    if ($studentIds && $schoolDays) {
        $ph = implode(",", array_fill(0, count($studentIds), "?"));
        $params = $studentIds;
        $params[] = $schoolDays[0];
        $params[] = $schoolDays[count($schoolDays) - 1];
        $params[] = $attendanceSubjectId;
        $stmtA = db()->prepare(
            "SELECT student_id, attendance_date, status
             FROM daily_attendance
             WHERE student_id IN ({$ph}) AND attendance_date BETWEEN ? AND ? AND subject_id = ?"
        );
        $stmtA->execute($params);
        foreach ($stmtA->fetchAll() as $r) {
            $d = (string)$r["attendance_date"];
            $sid = (int)$r["student_id"];
            $attByDateStudent[$d][$sid] = (string)$r["status"];
        }
    }
    $calendarStudentGroups = [
        "jhs" => [],
        "shs" => [],
        "other" => [],
    ];
    foreach ($students as $studentRow) {
        $g = (string)($studentRow["grade_level"] ?? "");
        if (in_array($g, ["Grade 7", "Grade 8", "Grade 9", "Grade 10"], true)) {
            $calendarStudentGroups["jhs"][] = $studentRow;
        } elseif (in_array($g, ["Grade 11", "Grade 12"], true)) {
            $calendarStudentGroups["shs"][] = $studentRow;
        } else {
            $calendarStudentGroups["other"][] = $studentRow;
        }
    }
  ?>

  <div class="card shadow-sm dg-calendar-panel">
    <div class="card-body dg-calendar-panel-body">
      <form method="post" action="student-sheets" class="d-flex flex-column flex-grow-1 min-h-0">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 dg-calendar-toolbar flex-shrink-0">
          <div>
            <div class="h5 mb-0">Attendance Sheet</div>
            <div class="text-muted small">
              <?= (int)$termDays ?> school days (D1–D<?= (int)$termDays ?>)
              • <?= htmlspecialchars($grade !== "" ? $grade : "All", ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>
              <?php if ($strandFilter !== "" && curriculum_strand_filter_applies($grade !== "" ? $grade : null)): ?>
                • <?= htmlspecialchars($strandFilter, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>
              <?php endif; ?>
              • <?= htmlspecialchars($sectionName !== "" ? section_display_short($sectionName) : "All sections", ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>
              <?php
                $attSubLabel = "";
                if ($attendanceSubjectId > 0) {
                    foreach ($attendanceSubjectOptionRows as $ar) {
                        if ((int)$ar["subject_id"] === $attendanceSubjectId) {
                            $attSubLabel = attendance_subject_label(["subject_code" => $ar["subject_code"], "subject_name" => $ar["subject_name"]]);
                            break;
                        }
                    }
                }
              ?>
              <?php if ($attSubLabel !== ""): ?>
                • Subject: <?= htmlspecialchars($attSubLabel, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>
              <?php else: ?>
                • Subject: <span class="text-muted">General (whole day)</span>
              <?php endif; ?>
            </div>
          </div>
          <div class="d-flex align-items-center gap-2">
            <div class="btn-group btn-group-sm" role="group" aria-label="Calendar horizontal scroll controls">
              <button type="button" class="btn btn-outline-secondary" id="dgCalScrollLeft" title="Slide left">←</button>
              <button type="button" class="btn btn-outline-secondary" id="dgCalScrollRight" title="Slide right">→</button>
            </div>
            <button class="btn btn-primary btn-sm" type="submit" <?= ($termId === "" || $gradingReadOnly) ? "disabled" : "" ?>>Save Calendar</button>
          </div>
        </div>
            <?= csrf_field() ?>
            <input type="hidden" name="view" value="sheet" />
            <input type="hidden" name="term_id" value="<?= htmlspecialchars($termId, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" />
            <input type="hidden" name="grade" value="<?= htmlspecialchars($grade, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" />
            <input type="hidden" name="section_name" value="<?= htmlspecialchars($sectionName, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" />
            <input type="hidden" name="strand" value="<?= htmlspecialchars($strandFilter, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" />
            <input type="hidden" name="school_year" value="<?= htmlspecialchars($schoolYear, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" />
            <input type="hidden" name="attendance_subject_id" value="<?= (int)$attendanceSubjectId ?>" />

            <div class="dg-sheet-wrap dg-sheet-wrap-calendar" id="dgCalendarScrollArea">
              <table class="dg-sheet dg-sheet-calendar">
                <thead>
                  <tr>
                    <th style="min-width:240px">
                      <div class="fw-semibold">Student</div>
                      <div class="text-muted small">Mark Present, Late, or Absent per school day</div>
                    </th>
                    <?php foreach ($calendarDayMeta as $meta): ?>
                      <th class="dg-sheet-day dg-sheet-calendar-day">
                        <div class="dg-cal-day-index fw-semibold">D<?= (int)$meta["idx"] ?></div>
                      </th>
                    <?php endforeach; ?>
                    <th class="text-end dg-sheet-summary-col" style="min-width:88px">Present</th>
                    <th class="text-end dg-sheet-summary-col" style="min-width:72px">Total</th>
                    <th class="text-end dg-sheet-summary-col" style="min-width:72px">Att %</th>
                  </tr>
                </thead>
                <tbody>
                  <?php
                    $renderCalendarGroup = static function (string $groupKey, string $label, array $groupRows) use ($calendarDayMeta, $attByDateStudent, $attSummaryByStudent): void {
                        if (!$groupRows) {
                            return;
                        }
                        $groupClass = match ($groupKey) {
                            "jhs" => "dg-cal-group-jhs",
                            "shs" => "dg-cal-group-shs",
                            default => "dg-cal-group-other",
                        };
                        ?>
                        <tr class="dg-cal-group-row <?= $groupClass ?>">
                          <td class="dg-cal-group-label"><?= htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></td>
                          <?php foreach ($calendarDayMeta as $meta): ?>
                            <td class="dg-sheet-day"></td>
                          <?php endforeach; ?>
                          <td class="dg-sheet-summary-col"></td>
                          <td class="dg-sheet-summary-col"></td>
                          <td class="dg-sheet-summary-col"></td>
                        </tr>
                        <?php foreach ($groupRows as $s): ?>
                          <?php
                            $sid = (int)$s["student_id"];
                            $attStats = attendance_display_stats(null, $attSummaryByStudent[$sid] ?? null);
                          ?>
                          <tr>
                            <td class="dg-cal-student-cell">
                              <div class="fw-semibold"><?= htmlspecialchars((string)$s["name"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></div>
                              <div class="text-muted small"><?= htmlspecialchars((string)$s["grade_level"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?> • <?= htmlspecialchars(section_display_short((string)($s["section"] ?? "")), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></div>
                            </td>
                            <?php foreach ($calendarDayMeta as $meta): ?>
                              <?php $d = (string)$meta["date"]; ?>
                              <?php $st = $attByDateStudent[$d][$sid] ?? ""; ?>
                              <td class="dg-sheet-day">
                                <select class="form-select form-select-sm dg-att-status" name="days[<?= htmlspecialchars($d, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>][<?= $sid ?>]">
                                  <option value="" <?= $st === "" ? "selected" : "" ?>>—</option>
                                  <option value="Present" <?= $st === "Present" ? "selected" : "" ?>>P</option>
                                  <option value="Late" <?= $st === "Late" ? "selected" : "" ?>>L</option>
                                  <option value="Absent" <?= $st === "Absent" ? "selected" : "" ?>>A</option>
                                </select>
                              </td>
                            <?php endforeach; ?>
                            <td class="text-end small dg-sheet-summary-col"><?= (int)$attStats["days_present"] ?></td>
                            <td class="text-end small dg-sheet-summary-col"><?= (int)$attStats["total_school_days"] ?></td>
                            <td class="text-end small dg-sheet-summary-col"><?= $attStats["attendance_pct"] !== null ? number_format((float)$attStats["attendance_pct"], 1) . "%" : "—" ?></td>
                          </tr>
                        <?php endforeach;
                    };
                    $renderCalendarGroup("jhs", "Junior High School (Grades 7-10)", $calendarStudentGroups["jhs"]);
                    $renderCalendarGroup("shs", "Senior High School (Grades 11-12)", $calendarStudentGroups["shs"]);
                    $renderCalendarGroup("other", "Other Grade Levels", $calendarStudentGroups["other"]);
                  ?>
                </tbody>
              </table>
            </div>

            <div class="text-muted small mt-2 d-flex flex-wrap gap-3">
              <span><strong>Legend:</strong> select Present/Late/Absent (leave blank to not set).</span>
              <span>Rows are grouped by Junior High and Senior High.</span>
              <span>Use mouse wheel over the calendar to slide horizontally.</span>
              <?php if ($attendanceSubjectId > 0): ?>
                <span>Present + Late count as present. Blank days count as absent for term totals. Saving updates term performance records used by the dashboard and risk analysis.</span>
              <?php endif; ?>
            </div>
      </form>
    </div>
  </div>
<?php else: ?>
<?php if ($view === "grading"): ?>
<div class="card shadow-sm dg-sheet-data-card h-100">
  <div class="card-body d-flex flex-column min-h-0">
    <form method="post" action="student-sheets" class="d-flex flex-column flex-grow-1 min-h-0">
      <?= csrf_field() ?>
      <input type="hidden" name="term_id" value="<?= htmlspecialchars($termId, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" />
      <input type="hidden" name="view" value="grading" />
      <input type="hidden" name="grade" value="<?= htmlspecialchars($grade, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" />
      <input type="hidden" name="section_name" value="<?= htmlspecialchars($sectionName, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" />
      <input type="hidden" name="strand" value="<?= htmlspecialchars($strandFilter, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" />
      <input type="hidden" name="school_year" value="<?= htmlspecialchars($schoolYear, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" />
      <input type="hidden" name="attendance_subject_id" value="<?= (int)$attendanceSubjectId ?>" />
        <div class="d-flex justify-content-between align-items-center mb-2 flex-shrink-0">
        <div class="fw-semibold">
          Grading Sheet (<?= count($students) ?>)
          <?php if ($termId !== "" && $termMeta): ?>
            <span class="text-muted small ms-2">• <?= htmlspecialchars(curriculum_term_label($termId), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></span>
          <?php endif; ?>
        </div>
        <div class="d-flex gap-2 align-items-center">
          <div class="text-muted small d-none d-md-block">Weights:</div>
          <div class="input-group input-group-sm" style="max-width:380px">
            <span class="input-group-text">Quiz</span>
            <input class="form-control text-end" type="number" step="1" min="0" max="100" name="w_quiz" value="<?= htmlspecialchars((string)$weights["quiz"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" />
            <span class="input-group-text">Exam</span>
            <input class="form-control text-end" type="number" step="1" min="0" max="100" name="w_exam" value="<?= htmlspecialchars((string)$weights["exam"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" />
            <span class="input-group-text">Proj</span>
            <input class="form-control text-end" type="number" step="1" min="0" max="100" name="w_project" value="<?= htmlspecialchars((string)$weights["project"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" />
          </div>
          <div class="small text-muted" id="dgWeightTotalWrap">
            Total: <span class="fw-semibold" id="dgWeightTotal">100</span>
          </div>
        <button class="btn btn-primary btn-sm" id="dgGradeSaveBtn" type="submit" <?= ($termId === "" || $gradingReadOnly) ? "disabled" : "" ?>>Save</button>
        <button class="btn btn-outline-dark btn-sm" id="dgGradeFinalizeBtn" type="submit" name="finalize" value="1" <?= ($termId === "" || $gradingReadOnly) ? "disabled" : "" ?> onclick="return confirm('Finalize this grading period? This will lock grades for this term.');">Finalize</button>
        </div>
      </div>

      <div class="dg-sheet-wrap dg-flex-1">
        <table class="dg-sheet">
          <thead>
            <tr>
              <th>Student</th>
              <th>Grade</th>
              <th class="text-end" style="min-width:110px">Quiz</th>
              <th class="text-end" style="min-width:110px">Exam</th>
              <th class="text-end" style="min-width:110px">Project</th>
              <th class="text-end" style="min-width:120px">Extra</th>
              <th class="text-end" style="min-width:140px">Academic</th>
              <th class="text-end" style="min-width:110px">Present</th>
              <th class="text-end" style="min-width:110px">Total</th>
              <th class="text-end" style="min-width:110px">Abs.</th>
              <th class="text-end" style="min-width:100px">Att %</th>
              <th class="text-end" style="min-width:160px">Finalized Score</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($students as $s): ?>
              <?php
                $sid = (int)$s["student_id"];
                $p = $perfByStudent[$sid] ?? null;
                $c = $componentsByStudent[$sid] ?? null;
                $isFinal = (int)($c["is_final"] ?? 0) === 1;
                $quiz = (float)($c["quiz"] ?? 0);
                $exam = (float)($c["exam"] ?? 0);
                $project = (float)($c["project"] ?? 0);
                $extra = (float)($c["extracurricular_score"] ?? 0);
                $rowScores = grading_compute_row_scores($quiz, $exam, $project, $extra, $weights);
                $academicScore = $rowScores["academic_score"];
                $initial = $rowScores["initial_score"];
                $gpa = $rowScores["final_score"];
                $isFailing = $rowScores["is_failing"];
                $attStats = attendance_display_stats($p, $attSummaryByStudent[$sid] ?? null);
              ?>
              <tr data-row="grade" data-student-id="<?= $sid ?>" <?= $isFinal ? 'data-final="1"' : '' ?>>
                <td>
                  <div class="fw-semibold"><?= htmlspecialchars((string)$s["name"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></div>
                  <div class="text-muted small"><?= htmlspecialchars((string)($s["lrn"] ?? ""), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></div>
                </td>
                <td class="text-muted small"><?= htmlspecialchars((string)$s["grade_level"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></td>
                <td class="text-end">
                  <input class="form-control form-control-sm text-end dg-sheet-cell-input dg-grade-quiz" type="number" step="0.01" min="0" max="100" name="rows[<?= $sid ?>][quiz]" value="<?= htmlspecialchars((string)$quiz, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" <?= ($termId === "" || $isFinal || $gradingReadOnly) ? "disabled" : "" ?> />
                </td>
                <td class="text-end">
                  <input class="form-control form-control-sm text-end dg-sheet-cell-input dg-grade-exam" type="number" step="0.01" min="0" max="100" name="rows[<?= $sid ?>][exam]" value="<?= htmlspecialchars((string)$exam, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" <?= ($termId === "" || $isFinal || $gradingReadOnly) ? "disabled" : "" ?> />
                </td>
                <td class="text-end">
                  <input class="form-control form-control-sm text-end dg-sheet-cell-input dg-grade-project" type="number" step="0.01" min="0" max="100" name="rows[<?= $sid ?>][project]" value="<?= htmlspecialchars((string)$project, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" <?= ($termId === "" || $isFinal || $gradingReadOnly) ? "disabled" : "" ?> />
                </td>
                <td class="text-end">
                  <input class="form-control form-control-sm text-end dg-sheet-cell-input dg-grade-extra" type="number" step="1" min="0" max="<?= (int)$extracurricularMax ?>" name="rows[<?= $sid ?>][extracurricular_score]" value="<?= (int)$extra ?>" <?= ($termId === "" || $isFinal || $gradingReadOnly) ? "disabled" : "" ?> />
                  <div class="text-muted small">Max <?= (int)$extracurricularMax ?></div>
                </td>
                <td class="text-end">
                  <span class="fw-semibold dg-grade-academic"><?= (int)$academicScore ?></span>
                </td>
                <td class="text-end small"><?= (int)$attStats["days_present"] ?></td>
                <td class="text-end small"><?= (int)$attStats["total_school_days"] ?></td>
                <td class="text-end small"><?= (int)$attStats["absences"] ?></td>
                <td class="text-end small"><?= $attStats["attendance_pct"] !== null ? number_format((float)$attStats["attendance_pct"], 1) . "%" : "—" ?></td>
                <td class="text-end">
                  <span class="fw-semibold dg-grade-final"><?= (int)$gpa ?></span>
                  <?php if ($isFinal): ?>
                    <span class="badge text-bg-secondary ms-1">Final</span>
                  <?php endif; ?>
                  <div class="text-muted small">IG <span class="dg-grade-ig"><?= (int)$initial ?></span></div>
                </td>
                <td>
                  <span class="dg-academic-chip dg-grade-chip <?= $isFailing ? "dg-academic-fail" : "dg-academic-pass" ?>">
                    <span class="dg-grade-chip-text"><?= $isFailing ? "Failing (<75)" : "Passing (>=75)" ?></span>
                  </span>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>
<?php endif; ?>

</div>

<script>
  (function () {
    const dgTransmutationEnabled = <?= grading_transmutation_enabled() ? "true" : "false" ?>;

    // DepEd Order No. 8, s. 2015 linear transmutation (matches app/grading.php).
    function depedTransmute(ig) {
      const v = clampPercent(ig);
      if (!dgTransmutationEnabled) return Math.round(v);
      if (v >= 100) return 100;
      if (v >= 60) return Math.round(75 + (v - 60) * 0.625);
      return Math.round(60 + (v * 0.25));
    }
    function clampInt(v, hi) {
      const x = Number(v);
      const n = Number.isFinite(x) ? Math.floor(x) : 0;
      return Math.max(0, Math.min(hi, n));
    }
    function clampPercent(v) {
      const x = Number(v);
      const n = Number.isFinite(x) ? x : 0;
      return Math.max(0, Math.min(100, n));
    }
    function syncGradeRow(tr, w) {
      const qEl = tr.querySelector('.dg-grade-quiz');
      const eEl = tr.querySelector('.dg-grade-exam');
      const pEl = tr.querySelector('.dg-grade-project');
      const xEl = tr.querySelector('.dg-grade-extra');
      const academicEl = tr.querySelector('.dg-grade-academic');
      const finalEl = tr.querySelector('.dg-grade-final');
      const igEl = tr.querySelector('.dg-grade-ig');
      const chip = tr.querySelector('.dg-grade-chip');
      const chipText = tr.querySelector('.dg-grade-chip-text');
      if (!qEl || !eEl || !pEl || !xEl || !academicEl || !finalEl || !chip || !chipText) return;
      const q = clampPercent(qEl.value);
      const e = clampPercent(eEl.value);
      const p = clampPercent(pEl.value);
      const x = clampPercent(xEl.value);
      qEl.value = String(q);
      eEl.value = String(e);
      pEl.value = String(p);
      xEl.value = String(x);
      const wq = (Number(w.quiz) || 0) / 100;
      const we = (Number(w.exam) || 0) / 100;
      const wp = (Number(w.project) || 0) / 100;
      const academic = Math.round(Math.max(0, Math.min(100, (q * wq) + (e * we) + (p * wp))));
      const extraCap = Number(w.extra) || 0;
      const ig = Math.round(Math.max(0, Math.min(100, academic + Math.min(x, extraCap))));
      const score = depedTransmute(ig);
      academicEl.textContent = String(academic);
      finalEl.textContent = String(score);
      if (igEl) igEl.textContent = String(ig);
      const failing = score < 75;
      chip.classList.toggle('dg-academic-fail', failing);
      chip.classList.toggle('dg-academic-pass', !failing);
      chipText.textContent = failing ? 'Failing (<75)' : 'Passing (>=75)';
    }
    function getWeights() {
      const wq = Number(document.querySelector('input[name="w_quiz"]')?.value || 0) || 0;
      const we = Number(document.querySelector('input[name="w_exam"]')?.value || 0) || 0;
      const wp = Number(document.querySelector('input[name="w_project"]')?.value || 0) || 0;
      const extra = Number(document.querySelector('.dg-grade-extra')?.getAttribute('max') || 0) || 0;
      return { quiz: wq, exam: we, project: wp, extra: extra };
    }
    const gradeRows = document.querySelectorAll('tr[data-row="grade"]');
    if (gradeRows.length) {
      function syncAll() {
        const w = getWeights();
        const sumW = (w.quiz + w.exam + w.project);
        const totalEl = document.getElementById('dgWeightTotal');
        const totalWrap = document.getElementById('dgWeightTotalWrap');
        const saveBtn = document.getElementById('dgGradeSaveBtn');
        const finBtn = document.getElementById('dgGradeFinalizeBtn');
        if (totalEl) totalEl.textContent = String(Math.round(sumW));
        const ok = Math.abs(sumW - 100) < 0.0001;
        if (totalWrap) {
          totalWrap.classList.toggle('text-danger', !ok);
          totalWrap.classList.toggle('text-muted', ok);
        }
        // Only enable save/finalize when weights total is 100.
        if (saveBtn) saveBtn.disabled = !ok || saveBtn.hasAttribute('data-locked');
        if (finBtn) finBtn.disabled = !ok || finBtn.hasAttribute('data-locked');
        gradeRows.forEach((tr) => syncGradeRow(tr, w));
      }
      document.querySelectorAll('input[name="w_quiz"], input[name="w_exam"], input[name="w_project"]').forEach((el) => {
        el.addEventListener('input', syncAll);
      });
      gradeRows.forEach((tr) => {
        if (tr.getAttribute('data-final') === '1') return;
        tr.querySelectorAll('.dg-grade-quiz, .dg-grade-exam, .dg-grade-project, .dg-grade-extra').forEach((el) => {
          el.addEventListener('input', syncAll);
        });
      });
      syncAll();
    }

    // Calendar horizontal slide controls.
    const calWrap = document.getElementById('dgCalendarScrollArea');
    const calLeft = document.getElementById('dgCalScrollLeft');
    const calRight = document.getElementById('dgCalScrollRight');
    if (calWrap && calLeft && calRight) {
      const step = () => Math.max(280, Math.floor(calWrap.clientWidth * 0.6));
      const scrollByStep = (dir) => calWrap.scrollBy({ left: dir * step(), behavior: 'smooth' });
      calLeft.addEventListener('click', () => scrollByStep(-1));
      calRight.addEventListener('click', () => scrollByStep(1));
      // Use wheel over the calendar area to slide horizontally.
      calWrap.addEventListener('wheel', (ev) => {
        ev.preventDefault();
        const delta = Math.abs(ev.deltaX) > Math.abs(ev.deltaY) ? ev.deltaX : ev.deltaY;
        calWrap.scrollLeft += delta;
      }, { passive: false });
    }

    // Attendance status colors (P=green, L=yellow, A=red).
    function syncAttSelect(el) {
      if (!el) return;
      el.classList.remove('dg-att-present', 'dg-att-late', 'dg-att-absent');
      const v = String(el.value || '');
      if (v === 'Present') el.classList.add('dg-att-present');
      else if (v === 'Late') el.classList.add('dg-att-late');
      else if (v === 'Absent') el.classList.add('dg-att-absent');
    }
    document.querySelectorAll('select.dg-att-status').forEach((el) => {
      syncAttSelect(el);
      el.addEventListener('change', () => syncAttSelect(el));
    });
  })();
</script>

<?php endif; ?>

</div>

<?php
$content = ob_get_clean();
require __DIR__ . "/_layout.php";

