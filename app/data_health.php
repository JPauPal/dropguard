<?php
declare(strict_types=1);

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/academic_period.php";
require_once __DIR__ . "/students_archive.php";
require_once __DIR__ . "/grading.php";
require_once __DIR__ . "/batches.php";

/**
 * Scan active students for incomplete records that weaken ML predictions (GIGO).
 *
 * @return array{
 *   summary: array{total_students:int, students_with_issues:int, issue_count:int},
 *   issues: list<array{student_id:int, name:string, grade_level:string, section:string, issues:list<string>}>
 * }
 */
function data_health_scan(): array
{
    ensure_grading_period_closures_table();
    ensure_grading_components_table();
    ensure_student_batches_table();
    ensure_students_archived_column();

    $activeSy = academic_period_active_school_year();

    $students = db()->query(
        "SELECT s.student_id, s.name, s.grade_level, s.section, s.gpa, s.absences,
                s.risk_level, s.risk_score
         FROM students s
         WHERE " . students_non_archived_sql("s") . "
         ORDER BY s.name ASC"
    )->fetchAll();

    $batchStmt = db()->prepare(
        "SELECT 1 FROM student_batches WHERE student_id = ? AND school_year = ? LIMIT 1"
    );
    $perfStmt = db()->prepare(
        "SELECT quarter, gpa, days_present, total_school_days, absences, failing_subjects
         FROM performance
         WHERE student_id = ? AND school_year <=> ?
         ORDER BY quarter DESC"
    );
    $gradeStmt = db()->prepare(
        "SELECT COUNT(*) AS finalized_count
         FROM grading_components
         WHERE student_id = ? AND subject_id <> 0 AND is_final = 1"
    );
    $attStmt = db()->prepare(
        "SELECT COUNT(*) AS mark_count
         FROM daily_attendance
         WHERE student_id = ?"
    );

    $issues = [];
    $issueCount = 0;

    foreach ($students as $row) {
        $sid = (int)($row["student_id"] ?? 0);
        if ($sid <= 0) {
            continue;
        }

        $studentIssues = [];

        $batchStmt->execute([$sid, $activeSy]);
        if (!$batchStmt->fetchColumn()) {
            $studentIssues[] = "No enrollment record for active school year ({$activeSy}).";
        }

        $perfStmt->execute([$sid, $activeSy]);
        $perfRows = $perfStmt->fetchAll();
        if ($perfRows === []) {
            $studentIssues[] = "No performance record for {$activeSy} (attendance/GPA not synced).";
        } else {
            $latest = $perfRows[0];
            $totalDays = (int)($latest["total_school_days"] ?? 0);
            $present = (int)($latest["days_present"] ?? 0);
            $abs = (int)($latest["absences"] ?? 0);
            if ($totalDays <= 0 && $present <= 0 && $abs <= 0) {
                $q = (int)($latest["quarter"] ?? 0);
                $studentIssues[] = "Q{$q}: no attendance data entered for {$activeSy}.";
            }
            if ((float)($latest["gpa"] ?? 0) <= 0.0) {
                $q = (int)($latest["quarter"] ?? 0);
                $studentIssues[] = "Q{$q}: GPA missing or zero in performance sync.";
            }
        }

        $gradeStmt->execute([$sid]);
        $finalized = (int)($gradeStmt->fetchColumn() ?: 0);
        if ($finalized <= 0) {
            $studentIssues[] = "No finalized subject grades on file.";
        }

        $attStmt->execute([$sid]);
        if ((int)($attStmt->fetchColumn() ?: 0) <= 0) {
            $studentIssues[] = "No daily attendance marks recorded.";
        }

        if ((float)($row["risk_score"] ?? 0) <= 0.0001 && (string)($row["risk_level"] ?? "Low") === "Low") {
            $studentIssues[] = "ML risk analysis has not been run (default Low score).";
        }

        if ($studentIssues !== []) {
            $issueCount += count($studentIssues);
            $issues[] = [
                "student_id" => $sid,
                "name" => (string)($row["name"] ?? ""),
                "grade_level" => (string)($row["grade_level"] ?? ""),
                "section" => (string)($row["section"] ?? ""),
                "risk_level" => (string)($row["risk_level"] ?? ""),
                "issues" => $studentIssues,
            ];
        }
    }

    return [
        "summary" => [
            "total_students" => count($students),
            "students_with_issues" => count($issues),
            "issue_count" => $issueCount,
            "active_school_year" => $activeSy,
        ],
        "issues" => $issues,
    ];
}
