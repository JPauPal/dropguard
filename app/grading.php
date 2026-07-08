<?php
declare(strict_types=1);

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/settings.php";
require_once __DIR__ . "/curriculum.php";
require_once __DIR__ . "/auth.php";

/** Default component weights (percent scale: 30 + 40 + 30 = 100). */
const GRADING_DEFAULT_WEIGHT_QUIZ = 30.0;
const GRADING_DEFAULT_WEIGHT_EXAM = 40.0;
const GRADING_DEFAULT_WEIGHT_PROJECT = 30.0;
const GRADING_DEFAULT_EXTRACURRICULAR_CAP = 10.0;
const GRADING_FAILING_THRESHOLD = 75.0;
/** Philippine K-12 schools use DepEd transmutation by default (DepEd Order No. 8, s. 2015). */
const GRADING_TRANSMUTATION_DEFAULT_ENABLED = true;

/**
 * Handles database schema installation and idempotent updates for grading_components.
 */
function ensure_grading_components_table(): void
{
    static $done = false;
    if ($done) {
        return;
    }

    db()->exec(
        "CREATE TABLE IF NOT EXISTS grading_components (
            component_id BIGINT AUTO_INCREMENT PRIMARY KEY,
            student_id INT NOT NULL,
            term_id VARCHAR(32) NOT NULL,
            subject_id BIGINT NOT NULL DEFAULT 0,
            quiz DECIMAL(6,2) NULL,
            exam DECIMAL(6,2) NULL,
            project DECIMAL(6,2) NULL,
            extracurricular_score DECIMAL(6,2) NOT NULL DEFAULT 0.00,
            academic_score DECIMAL(6,2) NOT NULL DEFAULT 0.00,
            initial_score DECIMAL(6,2) NOT NULL DEFAULT 0.00,
            final_score DECIMAL(6,2) NOT NULL DEFAULT 0.00,
            is_final TINYINT(1) NOT NULL DEFAULT 0,
            finalized_at TIMESTAMP NULL,
            finalized_by INT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_student_term_subject (student_id, term_id, subject_id),
            CONSTRAINT fk_gc_student FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE
        ) ENGINE=InnoDB"
    );

    try {
        $columns = db()->query("SHOW COLUMNS FROM `grading_components`")->fetchAll(PDO::FETCH_COLUMN);
        $columns = array_map("strtolower", $columns);

        if (!in_array("subject_id", $columns, true)) {
            db()->exec("ALTER TABLE `grading_components` ADD COLUMN `subject_id` BIGINT NOT NULL DEFAULT 0 AFTER `term_id`");
        }
        try {
            db()->exec("ALTER TABLE `grading_components` DROP INDEX `uniq_student_term`");
        } catch (Throwable) {
        }
        try {
            db()->exec("ALTER TABLE `grading_components` ADD UNIQUE KEY `uniq_student_term_subject` (`student_id`,`term_id`,`subject_id`)");
        } catch (Throwable) {
        }
        foreach (
            [
                "is_final" => "ALTER TABLE `grading_components` ADD COLUMN `is_final` TINYINT(1) NOT NULL DEFAULT 0 AFTER `project`",
                "extracurricular_score" => "ALTER TABLE `grading_components` ADD COLUMN `extracurricular_score` DECIMAL(6,2) NOT NULL DEFAULT 0.00 AFTER `project`",
                "academic_score" => "ALTER TABLE `grading_components` ADD COLUMN `academic_score` DECIMAL(6,2) NOT NULL DEFAULT 0.00 AFTER `extracurricular_score`",
                "initial_score" => "ALTER TABLE `grading_components` ADD COLUMN `initial_score` DECIMAL(6,2) NOT NULL DEFAULT 0.00 AFTER `academic_score`",
                "final_score" => "ALTER TABLE `grading_components` ADD COLUMN `final_score` DECIMAL(6,2) NOT NULL DEFAULT 0.00 AFTER `initial_score`",
                "finalized_at" => "ALTER TABLE `grading_components` ADD COLUMN `finalized_at` TIMESTAMP NULL AFTER `is_final`",
                "finalized_by" => "ALTER TABLE `grading_components` ADD COLUMN `finalized_by` INT NULL AFTER `finalized_at`",
            ] as $col => $sql
        ) {
            if (!in_array($col, $columns, true)) {
                db()->exec($sql);
            }
        }
    } catch (Throwable) {
        // Suppress migration exceptions for active runtimes.
    }

    ensure_app_settings_table();
    $defaults = [
        ["grade_weight_quiz", (string)(int)GRADING_DEFAULT_WEIGHT_QUIZ],
        ["grade_weight_exam", (string)(int)GRADING_DEFAULT_WEIGHT_EXAM],
        ["grade_weight_project", (string)(int)GRADING_DEFAULT_WEIGHT_PROJECT],
        ["grade_extracurricular_max", (string)(int)GRADING_DEFAULT_EXTRACURRICULAR_CAP],
        ["weight_quiz", (string)(int)GRADING_DEFAULT_WEIGHT_QUIZ],
        ["weight_exam", (string)(int)GRADING_DEFAULT_WEIGHT_EXAM],
        ["weight_project", (string)(int)GRADING_DEFAULT_WEIGHT_PROJECT],
        ["extracurricular_cap", (string)(int)GRADING_DEFAULT_EXTRACURRICULAR_CAP],
        ["enable_transmutation", GRADING_TRANSMUTATION_DEFAULT_ENABLED ? "1" : "0"],
        ["grade_use_deped_transmutation", GRADING_TRANSMUTATION_DEFAULT_ENABLED ? "1" : "0"],
        ["grading_allow_partial_gpa", "0"],
    ];
    $stmt = db()->prepare("INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES (?, ?)");
    foreach ($defaults as [$key, $value]) {
        $stmt->execute([$key, $value]);
    }

    grading_ensure_performance_sync_columns();
    $done = true;
}

/**
 * Adds ML sync columns on performance when missing (failing_subjects rollup).
 */
function grading_ensure_performance_sync_columns(): void
{
    static $done = false;
    if ($done) {
        return;
    }

    curriculum_ensure_performance_schema();
    $pdo = db();
    $stmt = $pdo->query("SHOW COLUMNS FROM `performance` LIKE 'failing_subjects'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE `performance` ADD COLUMN `failing_subjects` INT NOT NULL DEFAULT 0 AFTER `gpa`");
    }
    $done = true;
}

function grading_get_setting(string $primaryKey, string $legacyKey, string $default): string
{
    $value = get_setting($primaryKey);
    if ($value === null || trim($value) === "") {
        $value = get_setting($legacyKey, $default);
    }
    return (string)($value ?? $default);
}

function grading_log_weight_error(string $message): void
{
    error_log("Drop Guard grading: {$message}");
}

function grading_clamp_percent(float $value): float
{
    return max(0.0, min(100.0, $value));
}

function grading_clamp_component(float $value, float $max): float
{
    if ($max <= 0.0) {
        return 0.0;
    }
    return max(0.0, min($max, $value));
}

/**
 * @return array{
 *   quiz: float,
 *   exam: float,
 *   project: float,
 *   sum: float,
 *   fractions: array{quiz: float, exam: float, project: float},
 *   used_defaults: bool
 * }
 */
function grading_weights(): array
{
    ensure_grading_components_table();

    $quizRaw = (float)grading_get_setting("weight_quiz", "grade_weight_quiz", (string)GRADING_DEFAULT_WEIGHT_QUIZ);
    $examRaw = (float)grading_get_setting("weight_exam", "grade_weight_exam", (string)GRADING_DEFAULT_WEIGHT_EXAM);
    $projectRaw = (float)grading_get_setting("weight_project", "grade_weight_project", (string)GRADING_DEFAULT_WEIGHT_PROJECT);
    $sumRaw = $quizRaw + $examRaw + $projectRaw;

    $defaults = [
        "quiz" => GRADING_DEFAULT_WEIGHT_QUIZ,
        "exam" => GRADING_DEFAULT_WEIGHT_EXAM,
        "project" => GRADING_DEFAULT_WEIGHT_PROJECT,
        "sum" => 100.0,
        "fractions" => [
            "quiz" => GRADING_DEFAULT_WEIGHT_QUIZ / 100.0,
            "exam" => GRADING_DEFAULT_WEIGHT_EXAM / 100.0,
            "project" => GRADING_DEFAULT_WEIGHT_PROJECT / 100.0,
        ],
        "used_defaults" => true,
    ];

    if ($sumRaw <= 0.0) {
        grading_log_weight_error("Component weights sum to zero or less; using system defaults.");
        return $defaults;
    }

    if ($sumRaw > 1.5) {
        if (abs($sumRaw - 100.0) > 0.01) {
            grading_log_weight_error("Component weights sum to {$sumRaw}; expected 100. Using defaults.");
            return $defaults;
        }
        return [
            "quiz" => $quizRaw,
            "exam" => $examRaw,
            "project" => $projectRaw,
            "sum" => $sumRaw,
            "fractions" => [
                "quiz" => $quizRaw / 100.0,
                "exam" => $examRaw / 100.0,
                "project" => $projectRaw / 100.0,
            ],
            "used_defaults" => false,
        ];
    }

    if (abs($sumRaw - 1.0) > 0.01) {
        grading_log_weight_error("Fraction weights sum to {$sumRaw}; expected 1.00. Using defaults.");
        return $defaults;
    }

    return [
        "quiz" => $quizRaw * 100.0,
        "exam" => $examRaw * 100.0,
        "project" => $projectRaw * 100.0,
        "sum" => 100.0,
        "fractions" => [
            "quiz" => $quizRaw,
            "exam" => $examRaw,
            "project" => $projectRaw,
        ],
        "used_defaults" => false,
    ];
}

function grading_extracurricular_cap(): float
{
    ensure_grading_components_table();
    $max = (float)grading_get_setting("extracurricular_cap", "grade_extracurricular_max", (string)GRADING_DEFAULT_EXTRACURRICULAR_CAP);
    return grading_clamp_percent($max);
}

/** @deprecated Use grading_extracurricular_cap() */
function grading_extracurricular_max(): float
{
    return grading_extracurricular_cap();
}

/**
 * DepEd Order No. 8, s. 2015 transmutation toggle (enable_transmutation / legacy key).
 * Default ON for Philippine K-12 deployments; admins may disable in Settings if needed.
 */
function grading_transmutation_enabled(): bool
{
    ensure_grading_components_table();
    $default = GRADING_TRANSMUTATION_DEFAULT_ENABLED ? "1" : "0";
    $value = strtolower(trim(grading_get_setting("enable_transmutation", "grade_use_deped_transmutation", $default)));
    return !in_array($value, ["0", "false", "no", "off"], true);
}

/** @deprecated Use grading_transmutation_enabled() */
function grading_use_deped_transmutation(): bool
{
    return grading_transmutation_enabled();
}

/**
 * Whether GPA may be computed when not all enrolled subjects are finalized.
 */
function grading_allow_partial_gpa_rollup(): bool
{
    $value = strtolower(trim(grading_get_setting("grading_allow_partial_gpa", "grading_allow_partial_gpa", "0")));
    return in_array($value, ["1", "true", "yes", "on"], true);
}

/**
 * Academic score from raw points per component (0 to each weight max).
 * Academic = Σ (score_i / W_i) × W_i = sum of clamped raw points when W_i is the component cap.
 */
function grading_compute_academic_score(float $quiz, float $exam, float $project, ?array $weights = null): float
{
    $weights = $weights ?? grading_weights();
    $wQuiz = (float)($weights["quiz"] ?? GRADING_DEFAULT_WEIGHT_QUIZ);
    $wExam = (float)($weights["exam"] ?? GRADING_DEFAULT_WEIGHT_EXAM);
    $wProject = (float)($weights["project"] ?? GRADING_DEFAULT_WEIGHT_PROJECT);

    $academic = grading_component_contribution($quiz, $wQuiz)
        + grading_component_contribution($exam, $wExam)
        + grading_component_contribution($project, $wProject);

    return (float)round(grading_clamp_percent($academic), 2);
}

function grading_component_contribution(float $score, float $weightMax): float
{
    if ($weightMax <= 0.0) {
        return 0.0;
    }
    return grading_clamp_component($score, $weightMax);
}

/** @deprecated Use grading_compute_academic_score() */
function grading_academic_score(float $quiz, float $exam, float $project): float
{
    return grading_compute_academic_score($quiz, $exam, $project);
}

/**
 * Initial Score = min(100, Academic Score + min(S_extra, extracurricular_cap))
 */
function grading_compute_initial_score(float $academicScore, float $extracurricularScore): float
{
    $academic = grading_clamp_percent($academicScore);
    $extra = min(grading_clamp_percent($extracurricularScore), grading_extracurricular_cap());
    return (float)round(min(100.0, $academic + $extra), 2);
}

/** @deprecated Use grading_compute_initial_score() */
function grading_final_score(float $academicScore, float $extracurricularScore): float
{
    return grading_compute_initial_score($academicScore, $extracurricularScore);
}

/**
 * DepEd Order No. 8, s. 2015 linear transmutation (60→75, 100→100).
 *
 * - Initial 100 → Final 100
 * - Initial ≥ 60 → round(75 + (Initial − 60) × 0.625)
 * - Initial < 60 → round(60 + (Initial × 0.25))
 */
function deped_transmute_grade(float $initialGrade): float
{
    $initial = grading_clamp_percent($initialGrade);

    if ($initial >= 100.0) {
        return 100.0;
    }
    if ($initial >= 60.0) {
        return (float)round(75.0 + ($initial - 60.0) * 0.625);
    }

    return (float)round(60.0 + ($initial * 0.25));
}

function grading_compute_final_score(float $initialScore): float
{
    $initial = grading_clamp_percent($initialScore);
    if (grading_transmutation_enabled()) {
        return deped_transmute_grade($initial);
    }
    return (float)round($initial, 2);
}

function grading_validate_component_input(
    float $quiz,
    float $exam,
    float $project,
    float $extracurricular,
    ?array $weights = null
): ?string {
    $weights = $weights ?? grading_weights();
    foreach (
        [
            "Quiz" => [$quiz, (float)($weights["quiz"] ?? GRADING_DEFAULT_WEIGHT_QUIZ)],
            "Exam" => [$exam, (float)($weights["exam"] ?? GRADING_DEFAULT_WEIGHT_EXAM)],
            "Project" => [$project, (float)($weights["project"] ?? GRADING_DEFAULT_WEIGHT_PROJECT)],
        ] as $label => [$value, $max]
    ) {
        if ($value < 0.0) {
            return "{$label} score cannot be negative.";
        }
        if ($max > 0.0 && $value > $max) {
            return "{$label} score must be between 0 and {$max}.";
        }
    }

    if ($extracurricular < 0.0 || $extracurricular > 100.0) {
        return "Extracurricular score must be between 0 and 100.";
    }

    if ($extracurricular > grading_extracurricular_cap()) {
        return "Extracurricular score cannot exceed " . grading_extracurricular_cap() . ".";
    }

    return null;
}

/**
 * @return array{
 *   quiz: float,
 *   exam: float,
 *   project: float,
 *   extracurricular: float,
 *   academic_score: float,
 *   initial_score: float,
 *   final_score: float,
 *   is_failing: bool
 * }
 */
function grading_compute_row_scores(float $quiz, float $exam, float $project, float $extracurricular, ?array $weights = null): array
{
    $weights = $weights ?? grading_weights();
    $wQuiz = (float)($weights["quiz"] ?? GRADING_DEFAULT_WEIGHT_QUIZ);
    $wExam = (float)($weights["exam"] ?? GRADING_DEFAULT_WEIGHT_EXAM);
    $wProject = (float)($weights["project"] ?? GRADING_DEFAULT_WEIGHT_PROJECT);

    $academic = grading_compute_academic_score($quiz, $exam, $project, $weights);
    $initial = grading_compute_initial_score($academic, $extracurricular);
    $final = grading_compute_final_score($initial);

    return [
        "quiz" => grading_clamp_component($quiz, $wQuiz),
        "exam" => grading_clamp_component($exam, $wExam),
        "project" => grading_clamp_component($project, $wProject),
        "extracurricular" => grading_clamp_percent($extracurricular),
        "academic_score" => $academic,
        "initial_score" => $initial,
        "final_score" => $final,
        "is_failing" => $final < GRADING_FAILING_THRESHOLD,
    ];
}

/**
 * Curriculum scope for GPA rollup:
 * - JHS (Grades 7–10): current quarter term only
 * - SHS (Grades 11–12): all quarter terms within the same semester
 *
 * @return list<string>
 */
function grading_term_ids_for_rollup(string $termId): array
{
    $meta = curriculum_term_meta($termId);
    if ($meta === null || ($meta["track"] ?? "") !== "senior_high_school") {
        return [$termId];
    }

    $config = curriculum_load_senior_high();
    foreach ($config["semesters"] ?? [] as $semester) {
        if (!is_array($semester)) {
            continue;
        }
        $termIds = [];
        foreach ($semester["grading_periods"] ?? [] as $period) {
            if (!is_array($period) || empty($period["term_id"])) {
                continue;
            }
            $termIds[] = (string)$period["term_id"];
        }
        if (in_array($termId, $termIds, true)) {
            return $termIds !== [] ? $termIds : [$termId];
        }
    }

    return [$termId];
}

/**
 * Count distinct subjects assigned to the student's section (enrollment expectation).
 */
function grading_expected_subject_count(PDO $pdo, int $studentId): int
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(DISTINCT ss.subject_id) AS subject_count
         FROM students s
         INNER JOIN sections sec ON sec.section_name = s.section
         INNER JOIN section_subjects ss ON ss.section_id = sec.section_id
         WHERE s.student_id = ?"
    );
    $stmt->execute([$studentId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return max(0, (int)($row["subject_count"] ?? 0));
}

function grading_role_can_unlock(string $role): bool
{
    return in_array($role, ["Admin", "Counselor"], true);
}

function grading_component_is_final(PDO $pdo, int $studentId, string $termId, int $subjectId): bool
{
    $stmt = $pdo->prepare(
        "SELECT is_final
         FROM grading_components
         WHERE student_id = ? AND term_id = ? AND subject_id = ?
         LIMIT 1"
    );
    $stmt->execute([$studentId, $termId, $subjectId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row !== false && (int)($row["is_final"] ?? 0) === 1;
}

/**
 * Only Admin/Counselor may unlock a finalized grade row.
 */
function grading_unlock_component(
    PDO $pdo,
    int $studentId,
    string $termId,
    int $subjectId,
    int $actorUserId,
    string $role
): bool {
    if (!grading_role_can_unlock($role)) {
        return false;
    }

    $stmt = $pdo->prepare(
        "UPDATE grading_components
         SET is_final = 0, finalized_at = NULL, finalized_by = NULL
         WHERE student_id = ? AND term_id = ? AND subject_id = ? AND is_final = 1"
    );
    $stmt->execute([$studentId, $termId, $subjectId]);
    if ($stmt->rowCount() <= 0) {
        return false;
    }

    audit_log(
        "grading_unlock",
        "success",
        "student",
        $studentId,
        "Unlocked finalized grade for Student ID: {$studentId}, term {$termId}, subject {$subjectId}.",
        ["term_id" => $termId, "subject_id" => $subjectId, "actor_user_id" => $actorUserId]
    );

    return true;
}

/**
 * Curriculum-aware rollup of finalized subject grades.
 *
 * @return array{
 *   gpa: ?float,
 *   failing_subjects: int,
 *   finalized_count: int,
 *   expected_count: int,
 *   pending: bool,
 *   subject_avg: float,
 *   subject_min: float,
 *   term_ids: list<string>
 * }
 */
function grading_rollup_term(PDO $pdo, int $studentId, string $termId, bool $allowPartialOverride = false): array
{
    $termIds = grading_term_ids_for_rollup($termId);
    $placeholders = implode(",", array_fill(0, count($termIds), "?"));
    $params = array_merge([GRADING_FAILING_THRESHOLD, $studentId], $termIds);

    $stmt = $pdo->prepare(
        "SELECT AVG(gc.final_score) AS subject_avg,
                MIN(gc.final_score) AS subject_min,
                COUNT(DISTINCT gc.subject_id) AS finalized_count,
                COUNT(DISTINCT CASE WHEN gc.final_score < ? THEN gc.subject_id END) AS failing_subjects
         FROM grading_components gc
         WHERE gc.student_id = ?
           AND gc.term_id IN ({$placeholders})
           AND gc.subject_id <> 0
           AND gc.is_final = 1"
    );
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $finalizedCount = (int)($row["finalized_count"] ?? 0);
    $expectedCount = grading_expected_subject_count($pdo, $studentId);
    $allowPartial = $allowPartialOverride || grading_allow_partial_gpa_rollup();
    $pending = false;

    if ($finalizedCount <= 0) {
        return [
            "gpa" => null,
            "failing_subjects" => 0,
            "finalized_count" => 0,
            "expected_count" => $expectedCount,
            "pending" => true,
            "subject_avg" => 0.0,
            "subject_min" => 0.0,
            "term_ids" => $termIds,
        ];
    }

    if ($expectedCount > 0 && $finalizedCount < $expectedCount && !$allowPartial) {
        $pending = true;
        grading_log_weight_error(
            "Student {$studentId}: {$finalizedCount}/{$expectedCount} subjects finalized for rollup scope; GPA withheld."
        );
        return [
            "gpa" => null,
            "failing_subjects" => (int)($row["failing_subjects"] ?? 0),
            "finalized_count" => $finalizedCount,
            "expected_count" => $expectedCount,
            "pending" => true,
            "subject_avg" => (float)round((float)($row["subject_avg"] ?? 0.0), 2),
            "subject_min" => (float)round((float)($row["subject_min"] ?? 0.0), 2),
            "term_ids" => $termIds,
        ];
    }

    return [
        "gpa" => (float)round((float)($row["subject_avg"] ?? 0.0), 2),
        "failing_subjects" => (int)($row["failing_subjects"] ?? 0),
        "finalized_count" => $finalizedCount,
        "expected_count" => $expectedCount,
        "pending" => $pending,
        "subject_avg" => (float)round((float)($row["subject_avg"] ?? 0.0), 2),
        "subject_min" => (float)round((float)($row["subject_min"] ?? 0.0), 2),
        "term_ids" => $termIds,
    ];
}

/**
 * @return array{
 *   gpa: ?float,
 *   failing_subjects: int,
 *   finalized_count: int,
 *   pending: bool,
 *   expected_count: int
 * }
 */
function grading_sync_performance(
    PDO $pdo,
    int $studentId,
    string $termId,
    ?string $schoolYear,
    int $quarter,
    ?array $attendanceOverride = null,
    bool $allowPartialOverride = false
): array {
    grading_ensure_performance_sync_columns();

    $rollup = grading_rollup_term($pdo, $studentId, $termId, $allowPartialOverride);

    $lookup = $pdo->prepare(
        "SELECT days_present, total_school_days, absences, consecutive_absences, gpa, failing_subjects
         FROM performance
         WHERE student_id = ?
           AND term_id = ?
           AND school_year <=> ?
         LIMIT 1"
    );
    $lookup->execute([$studentId, $termId, $schoolYear !== "" ? $schoolYear : null]);
    $existing = $lookup->fetch(PDO::FETCH_ASSOC) ?: [];

    $daysPresent = (int)($existing["days_present"] ?? 0);
    $totalDays = (int)($existing["total_school_days"] ?? 0);
    $absences = (int)($existing["absences"] ?? 0);
    $consecutive = (int)($existing["consecutive_absences"] ?? 0);

    if ($attendanceOverride !== null) {
        if (array_key_exists("days_present", $attendanceOverride)) {
            $daysPresent = max(0, (int)$attendanceOverride["days_present"]);
        }
        if (array_key_exists("total_school_days", $attendanceOverride)) {
            $totalDays = max(0, (int)$attendanceOverride["total_school_days"]);
        }
        if (array_key_exists("absences", $attendanceOverride)) {
            $absences = max(0, (int)$attendanceOverride["absences"]);
        }
        if (array_key_exists("consecutive_absences", $attendanceOverride)) {
            $consecutive = max(0, (int)$attendanceOverride["consecutive_absences"]);
        }
        if ($totalDays > 0 && $daysPresent > $totalDays) {
            $daysPresent = $totalDays;
        }
        if ($totalDays > 0 && $absences === 0 && $daysPresent <= $totalDays) {
            $absences = max(0, $totalDays - $daysPresent);
        }
    }

    $gpaToStore = $rollup["gpa"];
    $failingToStore = $rollup["failing_subjects"];

    if ($rollup["gpa"] === null || $rollup["pending"]) {
        $gpaToStore = isset($existing["gpa"]) ? (float)$existing["gpa"] : null;
        if ($rollup["finalized_count"] <= 0) {
            $failingToStore = (int)($existing["failing_subjects"] ?? 0);
        }
    }

    $stmt = $pdo->prepare(
        "INSERT INTO performance
            (student_id, school_year, term_id, quarter, gpa, failing_subjects,
             days_present, total_school_days, absences, consecutive_absences)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
           failing_subjects = VALUES(failing_subjects),
           quarter = VALUES(quarter),
           term_id = VALUES(term_id),
           days_present = IF(VALUES(total_school_days) > 0, VALUES(days_present), days_present),
           total_school_days = IF(VALUES(total_school_days) > 0, VALUES(total_school_days), total_school_days),
           absences = IF(VALUES(total_school_days) > 0, VALUES(absences), absences),
           consecutive_absences = IF(VALUES(total_school_days) > 0, VALUES(consecutive_absences), consecutive_absences)"
    );
    $stmt->execute([
        $studentId,
        $schoolYear !== "" ? $schoolYear : null,
        $termId,
        $quarter,
        $gpaToStore ?? 0.0,
        $failingToStore,
        $daysPresent,
        $totalDays,
        $absences,
        $consecutive,
    ]);

    if ($rollup["gpa"] !== null && !$rollup["pending"]) {
        $gpaStmt = $pdo->prepare(
            "UPDATE performance
             SET gpa = ?
             WHERE student_id = ? AND term_id = ? AND school_year <=> ?"
        );
        $gpaStmt->execute([$rollup["gpa"], $studentId, $termId, $schoolYear !== "" ? $schoolYear : null]);
    }

    if ($rollup["gpa"] !== null && !$rollup["pending"]) {
        $upd = $pdo->prepare("UPDATE students SET gpa = ? WHERE student_id = ?");
        $upd->execute([$rollup["gpa"], $studentId]);
    }

    return [
        "gpa" => $rollup["gpa"],
        "failing_subjects" => $rollup["failing_subjects"],
        "finalized_count" => $rollup["finalized_count"],
        "pending" => $rollup["pending"],
        "expected_count" => $rollup["expected_count"],
    ];
}

function grading_upsert_component(
    PDO $pdo,
    int $studentId,
    string $termId,
    int $subjectId,
    array $scores
): void {
    $stmt = $pdo->prepare(
        "INSERT INTO grading_components
            (student_id, term_id, subject_id, quiz, exam, project, extracurricular_score,
             academic_score, initial_score, final_score, is_final, finalized_at, finalized_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, NULL, NULL)
         ON DUPLICATE KEY UPDATE
           quiz = VALUES(quiz),
           exam = VALUES(exam),
           project = VALUES(project),
           extracurricular_score = VALUES(extracurricular_score),
           academic_score = VALUES(academic_score),
           initial_score = VALUES(initial_score),
           final_score = VALUES(final_score)"
    );
    $stmt->execute([
        $studentId,
        $termId,
        $subjectId,
        $scores["quiz"],
        $scores["exam"],
        $scores["project"],
        $scores["extracurricular"],
        $scores["academic_score"],
        $scores["initial_score"],
        $scores["final_score"],
    ]);
}

function grading_finalize_component(
    PDO $pdo,
    int $studentId,
    string $termId,
    int $subjectId,
    int $finalizedBy
): void {
    $stmt = $pdo->prepare(
        "UPDATE grading_components
         SET is_final = 1,
             finalized_at = COALESCE(finalized_at, NOW()),
             finalized_by = COALESCE(finalized_by, ?)
         WHERE student_id = ?
           AND term_id = ?
           AND subject_id = ?"
    );
    $stmt->execute([$finalizedBy > 0 ? $finalizedBy : null, $studentId, $termId, $subjectId]);
}

/**
 * Persist one subject grade inside an open PDO transaction.
 *
 * @return array{ok: bool, error?: string, scores?: array, sync?: array}
 */
function grading_persist_student_subject(
    PDO $pdo,
    int $studentId,
    string $termId,
    int $subjectId,
    float $quiz,
    float $exam,
    float $project,
    float $extracurricular,
    ?string $schoolYear,
    int $quarter,
    bool $finalize,
    int $actorUserId,
    string $userRole,
    ?array $weights = null,
    ?array $attendanceOverride = null,
    bool $unlock = false,
    bool $allowPartialOverride = false
): array {
    if (grading_component_is_final($pdo, $studentId, $termId, $subjectId)) {
        if ($unlock && grading_unlock_component($pdo, $studentId, $termId, $subjectId, $actorUserId, $userRole)) {
            // Unlocked — proceed with correction entry.
        } elseif (!grading_role_can_unlock($userRole)) {
            return ["ok" => false, "error" => "Grade is finalized and cannot be edited by Teachers."];
        } else {
            return ["ok" => false, "error" => "Grade is finalized. Unlock the record before editing."];
        }
    }

    $validationError = grading_validate_component_input($quiz, $exam, $project, $extracurricular, $weights);
    if ($validationError !== null) {
        return ["ok" => false, "error" => $validationError];
    }

    $scores = grading_compute_row_scores($quiz, $exam, $project, $extracurricular, $weights);
    grading_upsert_component($pdo, $studentId, $termId, $subjectId, $scores);

    if ($finalize) {
        grading_finalize_component($pdo, $studentId, $termId, $subjectId, $actorUserId);
    }

    $sync = grading_sync_performance(
        $pdo,
        $studentId,
        $termId,
        $schoolYear,
        $quarter,
        $attendanceOverride,
        $allowPartialOverride
    );

    if ($finalize && $sync["gpa"] !== null && !$sync["pending"]) {
        audit_log(
            "grading_finalize",
            "success",
            "student",
            $studentId,
            "Finalized grades for Student ID: {$studentId}, Quarter: {$quarter}. GPA set to {$sync["gpa"]}.",
            [
                "term_id" => $termId,
                "subject_id" => $subjectId,
                "failing_subjects" => $sync["failing_subjects"],
                "final_score" => $scores["final_score"],
                "finalized_count" => $sync["finalized_count"],
                "expected_count" => $sync["expected_count"],
            ]
        );
    }

    return ["ok" => true, "scores" => $scores, "sync" => $sync];
}

/** @deprecated Use grading_compute_academic_score() */
function grading_compute(float $quiz, float $exam, float $project, array $w): float
{
    return grading_compute_academic_score($quiz, $exam, $project, $w);
}
