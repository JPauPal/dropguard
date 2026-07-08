<?php
declare(strict_types=1);

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/auth.php";
require_once __DIR__ . "/settings.php";
require_once __DIR__ . "/curriculum.php";
require_once __DIR__ . "/grading.php";
require_once __DIR__ . "/students.php";

function clampf(float $v, float $lo, float $hi): float
{
    return max($lo, min($hi, $v));
}

function ml_ensure_risk_factors_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $pdo = db();
    foreach (["students" => "risk_level", "risk_analysis" => "risk_level"] as $table => $afterCol) {
        $col = "risk_factors_json";
        $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE " . $pdo->quote($col));
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$col}` TEXT NULL AFTER `{$afterCol}`");
        }
    }
    $done = true;
}

/** @return list<string> */
function ml_parse_risk_factors(mixed $raw): array
{
    if (is_array($raw)) {
        return array_values(array_filter(array_map("strval", $raw), static fn($v) => trim($v) !== ""));
    }
    if (!is_string($raw) || trim($raw) === "") {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded)
        ? array_values(array_filter(array_map("strval", $decoded), static fn($v) => trim($v) !== ""))
        : [];
}

function ml_render_risk_factors(array $factors, int $maxVisible = 3): string
{
    if ($factors === []) {
        return "";
    }
    $html = '<ul class="dg-risk-factor-list mb-0">';
    $shown = 0;
    foreach ($factors as $factor) {
        if ($shown >= $maxVisible) {
            break;
        }
        $html .= "<li>" . htmlspecialchars($factor, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") . "</li>";
        $shown++;
    }
    $html .= "</ul>";
    if (count($factors) > $maxVisible) {
        $more = count($factors) - $maxVisible;
        $html .= '<div class="text-muted small">+' . (int)$more . " " . htmlspecialchars(t("ml.risk_factors_more"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") . "</div>";
    }
    return $html;
}

function classify_risk_level(float $score): string
{
    $t = get_risk_thresholds();
    if ($score < (float)$t["low_max"]) {
        return "Low";
    }
    if ($score <= (float)$t["high_min"]) {
        return "Moderate";
    }
    return "High";
}

/**
 * What-if estimator used in counselor simulator.
 * Mirrors the placeholder logic in ml/predict.py.
 */
function estimate_risk_score(
    float $gpa,
    int $absences,
    int $daysPresent = 0,
    int $totalSchoolDays = 0,
    int $consecutiveAbsences = 0,
    int $activeFlagCount = 0,
    int $maxFlagSeverity = 0,
    int $daysSinceFirstFlag = 0,
    int $daysSinceLastFlag = 0,
    int $failingSubjects = 0,
    float $subjectMin = 0.0
): float
{
    // Prioritize grade + attendance days + flag-days (recency/duration).
    $g = clampf($gpa, 0.0, 100.0);
    $gNorm = $g / 100.0;
    // Stronger failing signal when GPA < 75.
    $failSignal = ($g < 75.0) ? clampf((75.0 - $g) / 25.0, 0.0, 1.0) : 0.0; // 0..1
    $academicRisk = clampf((0.65 * (1.0 - $gNorm)) + (0.35 * $failSignal), 0.0, 1.0);

    $a = clampf((float)$absences, 0.0, 60.0) / 60.0;
    $attendanceRisk = $a;
    if ($totalSchoolDays > 0) {
        $attendance = clampf(((float)$daysPresent / (float)$totalSchoolDays), 0.0, 1.0);
        $attendanceRisk = clampf((0.70 * (1.0 - $attendance)) + (0.30 * $a), 0.0, 1.0);
    }

    // Chronic streak: higher weight if repeated absences.
    $streak = clampf((float)$consecutiveAbsences, 0.0, 10.0) / 10.0;

    // Combine with priority to grades + attendance.
    $score = (0.55 * $academicRisk) + (0.35 * $attendanceRisk) + (0.10 * $streak);

    // Non-academic issues (flags) also contribute to dropout risk.
    if ($activeFlagCount > 0) {
        $maxSev = max(0, min(3, $maxFlagSeverity));
        // Base bump by severity (Low/Moderate/High mapped to 1/2/3).
        $sevBump = $maxSev === 3 ? 0.18 : ($maxSev === 2 ? 0.12 : 0.06);
        // Additional small bump by count (capped).
        $countBump = min(0.12, 0.02 * $activeFlagCount);

        // Flag-days: very recent flags and long-running unresolved flags raise risk more.
        $recent = $daysSinceLastFlag > 0 ? clampf((30.0 - (float)$daysSinceLastFlag) / 30.0, 0.0, 1.0) : 0.0;
        $duration = $daysSinceFirstFlag > 0 ? clampf((float)$daysSinceFirstFlag / 60.0, 0.0, 1.0) : 0.0;
        $timeBump = (0.06 * $recent) + (0.06 * $duration);

        $score = clampf($score + $sevBump + $countBump + $timeBump, 0.0, 1.0);
    }

    // Academic Integration: per-subject failing (mirrors ml/predict.py).
    $subjectMin = clampf($subjectMin, 0.0, 100.0);
    $failingSubjects = max(0, $failingSubjects);
    if ($subjectMin > 0.0 && $subjectMin < 75.0) {
        $subjMinNorm = $subjectMin / 100.0;
        $score = clampf($score + 0.10 * (1.0 - $subjMinNorm), 0.0, 1.0);
    }
    if ($failingSubjects > 0) {
        $score = clampf($score + min(0.18, 0.06 * $failingSubjects), 0.0, 1.0);
    }

    $t = get_risk_thresholds();
    $lowMax = (float)($t["low_max"] ?? 0.40);
    $highMin = (float)($t["high_min"] ?? 0.70);

    // Failing cumulative GPA or multiple failing subjects → at least Moderate.
    if (($g > 0.0 && $g < 75.0) || $failingSubjects >= 2) {
        $score = max($score, $lowMax + 0.0001);
    }
    // If failing grades and any flags, ensure at least Moderate.
    if ($g < 75.0 && $activeFlagCount > 0) {
        $score = max($score, $lowMax + 0.0001);
    }
    // Severe academic profile: critically low GPA or many failing subjects → High floor.
    if (($g > 0.0 && $g < 65.0) || $failingSubjects >= 3) {
        $score = max($score, min(1.0, $highMin + 0.0001));
    }

    return clampf($score, 0.0, 1.0);
}

/**
 * Academic Integration signals derived from GPA and finalized subject grades.
 *
 * @return array{
 *   has_signal: bool,
 *   gpa: float,
 *   gpa_failing: bool,
 *   failing_subjects: int,
 *   subject_min: float,
 *   subject_min_failing: bool
 * }
 */
function ml_student_academic_failing_meta(array $student): array
{
    $gpa = (float)($student["gpa"] ?? 0);
    $failingSubjects = max(0, (int)($student["failing_subjects"] ?? 0));
    $subjectMin = (float)($student["subject_min"] ?? 0);
    $subjectMinFailing = $subjectMin > 0.0 && $subjectMin < 75.0;
    $gpaFailing = $gpa > 0.0 && $gpa < 75.0;

    return [
        "has_signal" => $gpaFailing || $failingSubjects > 0 || $subjectMinFailing,
        "gpa" => $gpa,
        "gpa_failing" => $gpaFailing,
        "failing_subjects" => $failingSubjects,
        "subject_min" => $subjectMin,
        "subject_min_failing" => $subjectMinFailing,
    ];
}

/**
 * Post-ML adjustment for Academic Integration (grades), parallel to counselor flag bumps.
 *
 * @param list<string> $riskFactors
 * @return array{0: float, 1: string, 2: list<string>}
 */
function ml_apply_academic_failing_post_adjustments(float $score, array $student, array $riskFactors): array
{
    $meta = ml_student_academic_failing_meta($student);
    if (!$meta["has_signal"]) {
        return [$score, classify_risk_level($score), $riskFactors];
    }

    $gpa = $meta["gpa"];
    $failingSubjects = $meta["failing_subjects"];
    $subjectMin = $meta["subject_min"];

    if ($meta["subject_min_failing"]) {
        $score = clampf($score + 0.08, 0.0, 1.0);
        $riskFactors[] = tr("ml.risk_factor_subject_failing", ["score" => number_format($subjectMin, 1)]);
    }
    if ($failingSubjects > 0) {
        $score = clampf($score + min(0.18, 0.06 * $failingSubjects), 0.0, 1.0);
        $riskFactors[] = tr("ml.risk_factor_failing_subjects", ["count" => (string)$failingSubjects]);
    }
    if ($meta["gpa_failing"]) {
        $failDepth = clampf((75.0 - $gpa) / 25.0, 0.0, 1.0);
        $score = clampf($score + 0.06 + (0.12 * $failDepth), 0.0, 1.0);
        $riskFactors[] = tr("ml.risk_factor_gpa_failing", ["gpa" => number_format($gpa, 1)]);
    }

    $t = get_risk_thresholds();
    $lowMax = (float)($t["low_max"] ?? 0.40);
    $highMin = (float)($t["high_min"] ?? 0.70);

    if ($meta["gpa_failing"] || $failingSubjects >= 2) {
        $score = max($score, $lowMax + 0.0001);
    }
    if (($meta["gpa_failing"] && $gpa < 65.0) || $failingSubjects >= 3) {
        $score = max($score, min(1.0, $highMin + 0.0001));
    }

    return [$score, classify_risk_level($score), array_values(array_unique($riskFactors))];
}

/** True when core academic/attendance signals are unset (mirrors ml/predict.py). */
function ml_student_is_empty_record(array $student): bool
{
    $quarter = $student["quarter"] ?? null;
    $hasQuarter = $quarter !== null && trim((string)$quarter) !== "";
    return !$hasQuarter
        && (float)($student["gpa"] ?? 0) <= 0.0
        && (int)($student["absences"] ?? 0) <= 0
        && (int)($student["failing_subjects"] ?? 0) <= 0
        && (float)($student["subject_avg"] ?? 0) <= 0.0
        && (float)($student["subject_min"] ?? 0) <= 0.0
        && (int)($student["days_present"] ?? 0) <= 0
        && (int)($student["total_school_days"] ?? 0) <= 0;
}

/**
 * PHP heuristic fallback when Python proc_open fails (Academic + Social Integration).
 * Uses estimate_risk_score() aligned with counselor what-if simulator.
 *
 * @return array{success: true, mode: string, metrics: null, predictions: list<array<string, mixed>>}
 */
function ml_build_php_heuristic_result(array $students): array
{
    $predictions = [];
    foreach ($students as $s) {
        $studentId = (int)($s["student_id"] ?? 0);
        if ($studentId <= 0) {
            continue;
        }
        if (ml_student_is_empty_record($s)) {
            $predictions[] = [
                "student_id" => $studentId,
                "grade_level" => (string)($s["grade_level"] ?? ""),
                "risk_score" => 0.0,
                "risk_level" => "Low",
                "quarter" => isset($s["quarter"]) ? (int)$s["quarter"] : null,
                "risk_factors" => [],
                "is_empty_record" => true,
            ];
            continue;
        }
        $score = estimate_risk_score(
            (float)($s["gpa"] ?? 0),
            (int)($s["absences"] ?? 0),
            (int)($s["days_present"] ?? 0),
            (int)($s["total_school_days"] ?? 0),
            (int)($s["consecutive_absences"] ?? 0),
            0,
            0,
            0,
            0,
            (int)($s["failing_subjects"] ?? 0),
            (float)($s["subject_min"] ?? 0)
        );
        $riskFactors = [t("ml.risk_factor_php_fallback")];
        [$score, $level, $riskFactors] = ml_apply_academic_failing_post_adjustments($score, $s, $riskFactors);
        $predictions[] = [
            "student_id" => $studentId,
            "grade_level" => (string)($s["grade_level"] ?? ""),
            "risk_score" => $score,
            "risk_level" => $level,
            "quarter" => isset($s["quarter"]) ? (int)$s["quarter"] : null,
            "risk_factors" => $riskFactors,
            "is_empty_record" => false,
        ];
    }
    return [
        "success" => true,
        "mode" => "php_heuristic_fallback",
        "metrics" => null,
        "predictions" => $predictions,
    ];
}

/**
 * Invoke predict.py via proc_open. Returns decoded JSON or error string.
 *
 * @return array{result: ?array, error: ?string}
 */
function ml_invoke_python_predictor(array $students, string $pythonCmd, string $scriptPath): array
{
    $payload = json_encode(["students" => $students, "thresholds" => get_risk_thresholds()], JSON_UNESCAPED_UNICODE);
    if ($payload === false) {
        return ["result" => null, "error" => "Failed to encode JSON payload."];
    }

    $cmd = escapeshellarg($pythonCmd) . " " . escapeshellarg($scriptPath);
    $descriptors = [
        0 => ["pipe", "r"],
        1 => ["pipe", "w"],
        2 => ["pipe", "w"],
    ];
    $cwd = dirname($scriptPath);
    if ($cwd === "" || !is_dir($cwd)) {
        return ["result" => null, "error" => "Invalid ML working directory."];
    }

    try {
        $proc = proc_open($cmd, $descriptors, $pipes, $cwd);
        if (!is_resource($proc)) {
            return ["result" => null, "error" => "Failed to start Python process."];
        }

        fwrite($pipes[0], $payload);
        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        $exitCode = proc_close($proc);
        if ($exitCode !== 0) {
            return ["result" => null, "error" => trim($stderr) ?: "Python exited with code {$exitCode}."];
        }

        $result = json_decode($stdout, true);
        if (!is_array($result)) {
            return ["result" => null, "error" => "Invalid response from Python predictor."];
        }
        if (isset($result["success"]) && $result["success"] === false) {
            return ["result" => null, "error" => (string)($result["error"] ?? "ML processing failed.")];
        }
        if (!isset($result["predictions"]) || !is_array($result["predictions"])) {
            return ["result" => null, "error" => "Invalid response from Python predictor."];
        }

        return ["result" => $result, "error" => null];
    } catch (Throwable $e) {
        return ["result" => null, "error" => $e->getMessage()];
    }
}

function run_predictions_and_update_db(): array
{
    @set_time_limit(120);

    $config = app_config();
    $python = (string)($config["ml"]["python"] ?? "python");
    $script = (string)($config["ml"]["predict_script"] ?? "");
    $scriptPath = $script !== "" ? (realpath($script) ?: "") : "";

    // If config points at a missing python.exe, fall back to PATH resolution.
    $pythonCmd = $python;
    if (preg_match('/[\\\\\\/]/', $pythonCmd) && !is_file($pythonCmd)) {
        $pythonCmd = "python";
    }

    // Latest performance per student (fall back to students.gpa/absences if no performance row exists).
    ensure_grading_components_table();
    ensure_students_archived_column();
    $students = db()->query(
        "SELECT s.student_id, s.name, s.grade_level,
                COALESCE(p.gpa, s.gpa) AS gpa,
                COALESCE(p.absences, s.absences) AS absences,
                COALESCE(p.consecutive_absences, 0) AS consecutive_absences,
                COALESCE(p.days_present, 0) AS days_present,
                COALESCE(p.total_school_days, 0) AS total_school_days,
                p.term_id AS term_id,
                p.quarter AS quarter,
                COALESCE(gc.subject_avg, gc_fb.subject_avg, 0) AS subject_avg,
                COALESCE(gc.subject_min, gc_fb.subject_min, 0) AS subject_min,
                COALESCE(NULLIF(p.failing_subjects, 0), gc.failing_subjects, gc_fb.failing_subjects, 0) AS failing_subjects
         FROM students s
         LEFT JOIN (
            SELECT p1.*
            FROM performance p1
            INNER JOIN (
              SELECT student_id, MAX(quarter) AS max_quarter
              FROM performance
              GROUP BY student_id
            ) latest ON latest.student_id = p1.student_id AND latest.max_quarter = p1.quarter
         ) p ON p.student_id = s.student_id
         LEFT JOIN (
            SELECT student_id, term_id,
                   AVG(final_score) AS subject_avg,
                   MIN(final_score) AS subject_min,
                   COUNT(DISTINCT CASE WHEN final_score < 75 THEN subject_id END) AS failing_subjects
            FROM grading_components
            WHERE subject_id <> 0 AND is_final = 1
            GROUP BY student_id, term_id
         ) gc ON gc.student_id = s.student_id AND gc.term_id = p.term_id
         LEFT JOIN (
            SELECT student_id,
                   AVG(final_score) AS subject_avg,
                   MIN(final_score) AS subject_min,
                   COUNT(DISTINCT CASE WHEN final_score < 75 THEN subject_id END) AS failing_subjects
            FROM grading_components
            WHERE subject_id <> 0 AND is_final = 1
            GROUP BY student_id
         ) gc_fb ON gc_fb.student_id = s.student_id
         WHERE " . students_non_archived_sql("s")
    )->fetchAll();
    if (!$students) {
        return ["updated" => 0, "error" => null];
    }

    $usedFallback = false;
    $pythonError = null;
    $result = null;

    if ($scriptPath !== "" && is_file($scriptPath) && trim($pythonCmd) !== "") {
        $invoke = ml_invoke_python_predictor($students, $pythonCmd, $scriptPath);
        $result = $invoke["result"];
        $pythonError = $invoke["error"];
    } elseif (trim($pythonCmd) === "") {
        $pythonError = "Python ML is disabled (ML_PYTHON empty); using PHP heuristic fallback.";
    } else {
        $pythonError = "Predict script was not found. Check app/config.php ml.predict_script.";
    }

    if ($result === null) {
        $usedFallback = true;
        audit_log("critical_ml_failure", "failure", "ml", null, "Python ML bridge failed; using PHP heuristic fallback.", [
            "error" => $pythonError,
            "student_count" => count($students),
        ]);
        $result = ml_build_php_heuristic_result($students);
    }

    $mlMode = (string)($result["mode"] ?? "heuristic");
    $mlMetrics = $result["metrics"] ?? null;

    // Ensure schema before starting a transaction. Some ALTER TABLE operations can implicitly
    // commit in MySQL, which would break an active transaction and cause commit() to fail.
    curriculum_ensure_performance_schema();
    ml_ensure_risk_factors_schema();

    $pdo = db();
    // Pull active flag signals to influence final risk.
    // This makes the system "detect flags" by treating them as an additional risk factor.
    $flagRows = $pdo->query(
        "SELECT student_id,
                COUNT(*) AS active_count,
                MAX(CASE severity WHEN 'High' THEN 3 WHEN 'Moderate' THEN 2 ELSE 1 END) AS max_sev,
                SUM(CASE WHEN severity = 'High' THEN 1 ELSE 0 END) AS high_count,
                SUM(CASE WHEN severity = 'Moderate' THEN 1 ELSE 0 END) AS moderate_count,
                MIN(created_at) AS first_flagged_at,
                MAX(created_at) AS last_flagged_at
         FROM student_flags
         WHERE is_active = 1
         GROUP BY student_id"
    )->fetchAll();
    $flagsByStudent = [];
    foreach ($flagRows as $fr) {
        $sid = (int)($fr["student_id"] ?? 0);
        if ($sid <= 0) continue;
        $flagsByStudent[$sid] = [
            "count" => (int)($fr["active_count"] ?? 0),
            "max_sev" => (int)($fr["max_sev"] ?? 0),
            "high_count" => (int)($fr["high_count"] ?? 0),
            "moderate_count" => (int)($fr["moderate_count"] ?? 0),
            "first_flagged_at" => (string)($fr["first_flagged_at"] ?? ""),
            "last_flagged_at" => (string)($fr["last_flagged_at"] ?? ""),
        ];
    }

    $studentsById = [];
    foreach ($students as $studentRow) {
        $sid = (int)($studentRow["student_id"] ?? 0);
        if ($sid > 0) {
            $studentsById[$sid] = $studentRow;
        }
    }

    $pdo->beginTransaction();
    $updated = 0;

    $thresholds = get_risk_thresholds();
    $highMin = (float)($thresholds["high_min"] ?? 0.70);

    $stmtUpdate = $pdo->prepare("UPDATE students SET risk_score = ?, risk_level = ?, risk_factors_json = ? WHERE student_id = ?");
    $stmtInsert = $pdo->prepare("INSERT INTO risk_analysis (student_id, term_id, quarter, probability_score, risk_level, risk_factors_json) VALUES (?,?,?,?,?,?)");
    foreach ($result["predictions"] as $pred) {
        $studentId = (int) ($pred["student_id"] ?? 0);
        $score = (float) ($pred["risk_score"] ?? $pred["score"] ?? 0.0);
        $level = $pred["risk_level"] ?? classify_risk_level($score);
        $riskFactors = ml_parse_risk_factors($pred["risk_factors"] ?? null);
        $quarter = isset($pred["quarter"]) ? (int)$pred["quarter"] : null;
        $gradeLevel = (string)($pred["grade_level"] ?? "");
        $track = $gradeLevel !== "" ? curriculum_track_for_grade($gradeLevel) : null;
        $termId = ($track !== null && $quarter !== null) ? curriculum_term_id_for_track_quarter($track, $quarter) : null;
        if ($studentId <= 0) {
            continue;
        }

        $flagMeta = $flagsByStudent[$studentId] ?? null;
        $hasFlags = is_array($flagMeta) && ((int)($flagMeta["count"] ?? 0) > 0);
        $studentRow = $studentsById[$studentId] ?? [];
        $academicMeta = ml_student_academic_failing_meta($studentRow);
        $hasAcademicFailing = $academicMeta["has_signal"];
        $isEmptyRecord = !empty($pred["is_empty_record"]);

        if ($isEmptyRecord && !$hasFlags && !$hasAcademicFailing) {
            continue;
        }

        // If a student has no performance row yet, the predictor receives all-zero/default inputs
        // and will often return a low score. Avoid overwriting existing risk levels in this case,
        // unless there are counselor flags or finalized failing grades on record.
        $hasSignal = ($quarter !== null)
            || $score > 0.001
            || !in_array($level, ["Low"], true)
            || $hasFlags
            || $hasAcademicFailing;
        if (!$hasSignal) {
            continue;
        }

        // Flag-aware adjustment with recency/duration. Also ensure failing students are prioritized.
        if (is_array($flagMeta)) {
            $firstAt = trim((string)($flagMeta["first_flagged_at"] ?? ""));
            $lastAt = trim((string)($flagMeta["last_flagged_at"] ?? ""));
            $daysSinceFirst = 0;
            $daysSinceLast = 0;
            try {
                if ($firstAt !== "") {
                    $daysSinceFirst = (int)floor((time() - strtotime($firstAt)) / 86400);
                }
                if ($lastAt !== "") {
                    $daysSinceLast = (int)floor((time() - strtotime($lastAt)) / 86400);
                }
            } catch (Throwable) {
            }

            // Use the same prioritization logic as the what-if estimator for consistency.
            // We don't have all features here from Python, so we only "nudge" based on flags + known thresholds.
            if (($flagMeta["count"] ?? 0) > 0) {
                $maxSev = (int)($flagMeta["max_sev"] ?? 0);
                $count = (int)($flagMeta["count"] ?? 0);
                $sevBump = $maxSev === 3 ? 0.18 : ($maxSev === 2 ? 0.12 : 0.06);
                $countBump = min(0.12, 0.02 * $count);
                $recent = $daysSinceLast > 0 ? clampf((30.0 - (float)$daysSinceLast) / 30.0, 0.0, 1.0) : 0.0;
                $duration = $daysSinceFirst > 0 ? clampf((float)$daysSinceFirst / 60.0, 0.0, 1.0) : 0.0;
                $timeBump = (0.06 * $recent) + (0.06 * $duration);
                $score = clampf($score + $sevBump + $countBump + $timeBump, 0.0, 1.0);
                $level = classify_risk_level($score);
                $riskFactors[] = tr("ml.risk_factor_flags", ["count" => (string)$count]);
            }
        }

        // Override: multiple severe issue flags force High risk.
        if (is_array($flagMeta)) {
            $highCount = (int)($flagMeta["high_count"] ?? 0);
            $modCount = (int)($flagMeta["moderate_count"] ?? 0);
            if ($highCount >= 3 || ($highCount >= 2 && $modCount >= 1)) {
                $score = max($score, min(1.0, $highMin + 0.0001));
                $level = "High";
                $riskFactors[] = t("ml.risk_factor_severe_flags");
            }
        }

        if ($studentRow !== []) {
            [$score, $level, $riskFactors] = ml_apply_academic_failing_post_adjustments($score, $studentRow, $riskFactors);
        }

        $riskFactors = array_values(array_unique($riskFactors));
        $factorsJson = $riskFactors !== [] ? json_encode($riskFactors, JSON_UNESCAPED_UNICODE) : null;

        $stmtUpdate->execute([$score, $level, $factorsJson, $studentId]);
        $stmtInsert->execute([$studentId, $termId, $quarter, $score, $level, $factorsJson]);
        $updated += $stmtUpdate->rowCount() ? 1 : 0;
    }

    if ($pdo->inTransaction()) {
        $pdo->commit();
    }

    audit_log("ml_run", "success", "ml", null, "ML predictions completed.", [
        "updated" => $updated,
        "mode" => $mlMode,
        "metrics" => $mlMetrics,
        "used_php_fallback" => $usedFallback,
    ]);

    return [
        "updated" => $updated,
        "error" => null,
        "mode" => $mlMode,
        "metrics" => $mlMetrics,
        "used_php_fallback" => $usedFallback,
        "fallback_reason" => $usedFallback ? $pythonError : null,
    ];
}

