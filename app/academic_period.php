<?php
declare(strict_types=1);

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/settings.php";
require_once __DIR__ . "/curriculum.php";
require_once __DIR__ . "/batches.php";

function ensure_grading_period_closures_table(): void
{
    static $done = false;
    if ($done) {
        return;
    }

    db()->exec(
        "CREATE TABLE IF NOT EXISTS grading_period_closures (
            closure_id INT AUTO_INCREMENT PRIMARY KEY,
            school_year VARCHAR(9) NOT NULL,
            track_key VARCHAR(32) NOT NULL,
            term_id VARCHAR(32) NOT NULL,
            closed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            closed_by INT NULL,
            note VARCHAR(255) NULL,
            UNIQUE KEY uniq_period_closure (school_year, term_id),
            INDEX idx_closure_year (school_year),
            INDEX idx_closure_track (track_key)
        ) ENGINE=InnoDB"
    );

    ensure_app_settings_table();

    $defaults = [
        ["active_school_year", default_current_school_year()],
        ["active_term_jhs", "JHS_Q1"],
        ["active_term_shs", "SHS_S1_Q1"],
    ];
    $stmt = db()->prepare("INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES (?, ?)");
    foreach ($defaults as [$key, $value]) {
        $stmt->execute([$key, $value]);
    }

    $done = true;
}

/** @return array{school_year: string, active_term_jhs: string, active_term_shs: string} */
function academic_period_snapshot(): array
{
    return [
        "school_year" => academic_period_active_school_year(),
        "active_term_jhs" => academic_period_active_term_id("junior_high_school"),
        "active_term_shs" => academic_period_active_term_id("senior_high_school"),
    ];
}

function academic_period_active_school_year(): string
{
    ensure_grading_period_closures_table();
    $sy = trim((string)(get_setting("active_school_year", "") ?? ""));
    if ($sy !== "" && is_valid_school_year_sequence($sy)) {
        return $sy;
    }
    return default_current_school_year();
}

function academic_period_active_term_id(string $trackKey): string
{
    ensure_grading_period_closures_table();
    $key = $trackKey === "senior_high_school" ? "active_term_shs" : "active_term_jhs";
    $default = $trackKey === "senior_high_school" ? "SHS_S1_Q1" : "JHS_Q1";
    $termId = trim((string)(get_setting($key, $default) ?? $default));
    return curriculum_term_meta($termId) !== null ? $termId : $default;
}

function academic_period_set_active_school_year(string $schoolYear): void
{
    ensure_grading_period_closures_table();
    if (!is_valid_school_year_sequence($schoolYear)) {
        throw new InvalidArgumentException("Invalid school year sequence.");
    }
    set_setting("active_school_year", $schoolYear);
}

function academic_period_set_active_term(string $trackKey, string $termId): void
{
    ensure_grading_period_closures_table();
    $meta = curriculum_term_meta($termId);
    if ($meta === null || ($meta["track"] ?? "") !== $trackKey) {
        throw new InvalidArgumentException("Invalid term for track.");
    }
    $key = $trackKey === "senior_high_school" ? "active_term_shs" : "active_term_jhs";
    set_setting($key, $termId);
}

/**
 * Term IDs that belong to the same semester as the reference term.
 *
 * @return list<string>
 */
function academic_period_semester_term_ids(string $trackKey, string $referenceTermId): array
{
    if ($trackKey === "senior_high_school") {
        if (str_contains($referenceTermId, "S1")) {
            return ["SHS_S1_Q1", "SHS_S1_Q2"];
        }
        return ["SHS_S2_Q3", "SHS_S2_Q4"];
    }

    $meta = curriculum_term_meta($referenceTermId);
    $sort = (int)($meta["term_sort"] ?? 1);
    return $sort <= 2 ? ["JHS_Q1", "JHS_Q2"] : ["JHS_Q3", "JHS_Q4"];
}

function academic_period_is_second_school_semester(string $trackKey, ?string $activeTermId = null): bool
{
    $activeTermId = $activeTermId ?? academic_period_active_term_id($trackKey);
    $semesterTerms = academic_period_semester_term_ids($trackKey, $activeTermId);
    $lastInSemester = $semesterTerms[count($semesterTerms) - 1] ?? "";
    return in_array($lastInSemester, ["JHS_Q4", "SHS_S2_Q4"], true);
}

/**
 * @param list<string> $trackKeys
 * @return array{
 *   school_year: string,
 *   requires_end_of_school_year_confirmation: bool,
 *   tracks: array<string, array{active_term: string, terms_to_close: list<string>, is_end_of_school_year: bool}>
 * }
 */
function academic_period_preview_finish(array $trackKeys, string $schoolYear): array
{
    $requiresConfirm = false;
    $tracks = [];
    foreach ($trackKeys as $trackKey) {
        $activeTerm = academic_period_active_term_id($trackKey);
        $isEndOfYear = academic_period_is_second_school_semester($trackKey, $activeTerm);
        if ($isEndOfYear) {
            $requiresConfirm = true;
        }
        $tracks[$trackKey] = [
            "active_term" => $activeTerm,
            "terms_to_close" => academic_period_semester_term_ids($trackKey, $activeTerm),
            "is_end_of_school_year" => $isEndOfYear,
        ];
    }

    return [
        "school_year" => $schoolYear,
        "requires_end_of_school_year_confirmation" => $requiresConfirm,
        "tracks" => $tracks,
    ];
}

/**
 * @return ?array{term_id:string,name:string,semester_label:string}
 */
function academic_period_next_open_term(string $trackKey, string $lastClosedTermId): ?array
{
    $terms = curriculum_performance_terms($trackKey);
    usort($terms, static fn($a, $b) => ($a["term_sort"] ?? 0) <=> ($b["term_sort"] ?? 0));

    $semesterTerms = academic_period_semester_term_ids($trackKey, $lastClosedTermId);
    $lastInSemester = end($semesterTerms);
    if ($lastInSemester === false) {
        return null;
    }

    $found = false;
    foreach ($terms as $row) {
        if ($found) {
            return [
                "term_id" => (string)$row["term_id"],
                "name" => (string)$row["name"],
                "semester_label" => (string)($row["semester_name"] ?? $row["name"]),
            ];
        }
        if (($row["term_id"] ?? "") === $lastInSemester) {
            $found = true;
        }
    }

    return null;
}

function academic_period_is_term_closed(string $schoolYear, string $termId): bool
{
    if ($schoolYear === "" || $termId === "") {
        return false;
    }
    ensure_grading_period_closures_table();
    $stmt = db()->prepare(
        "SELECT 1 FROM grading_period_closures
         WHERE school_year = ? AND term_id = ?
         LIMIT 1"
    );
    $stmt->execute([$schoolYear, $termId]);
    return (bool)$stmt->fetchColumn();
}

/**
 * @return list<array{school_year:string,track_key:string,term_id:string,closed_at:string,note:?string}>
 */
function academic_period_list_closures(?string $schoolYear = null, int $limit = 50): array
{
    ensure_grading_period_closures_table();
    if ($schoolYear !== null && $schoolYear !== "") {
        $stmt = db()->prepare(
            "SELECT school_year, track_key, term_id, closed_at, note
             FROM grading_period_closures
             WHERE school_year = ?
             ORDER BY closed_at DESC, term_id ASC
             LIMIT " . max(1, min(200, $limit))
        );
        $stmt->execute([$schoolYear]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    return db()->query(
        "SELECT school_year, track_key, term_id, closed_at, note
         FROM grading_period_closures
         ORDER BY closed_at DESC, term_id ASC
         LIMIT " . max(1, min(200, $limit))
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Close one or more tracks in a single ACID transaction.
 *
 * @param list<string> $trackKeys
 * @return array{
 *   results: array<string, array{closed_terms: list<string>, next_term: ?string, next_school_year: ?string, summer_break: bool}>,
 *   before: array<string, mixed>,
 *   after: array<string, mixed>
 * }
 */
function academic_period_finish_semesters(
    array $trackKeys,
    string $schoolYear,
    int $actorUserId,
    ?string $note = null,
    bool $advanceSchoolYearOnEnd = true
): array {
    ensure_grading_period_closures_table();
    if (!is_valid_school_year_sequence($schoolYear)) {
        throw new InvalidArgumentException("Invalid school year.");
    }

    $trackKeys = array_values(array_unique(array_filter(
        $trackKeys,
        static fn($tk) => in_array($tk, ["junior_high_school", "senior_high_school"], true)
    )));
    if ($trackKeys === []) {
        throw new InvalidArgumentException("No valid tracks selected.");
    }

    $before = academic_period_snapshot();
    $pdo = db();
    $pdo->beginTransaction();

    try {
        $results = [];
        $currentSchoolYear = $schoolYear;
        foreach ($trackKeys as $trackKey) {
            $results[$trackKey] = academic_period_finish_semester_tx(
                $pdo,
                $trackKey,
                $currentSchoolYear,
                $actorUserId,
                $note,
                $advanceSchoolYearOnEnd
            );
            if (!empty($results[$trackKey]["next_school_year"])) {
                $currentSchoolYear = (string)$results[$trackKey]["next_school_year"];
            }
        }

        if ($pdo->inTransaction()) {
            $pdo->commit();
        }

        return [
            "results" => $results,
            "before" => $before,
            "after" => academic_period_snapshot(),
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * @return array{
 *   closed_terms: list<string>,
 *   next_term: ?string,
 *   next_school_year: ?string,
 *   summer_break: bool
 * }
 */
function academic_period_finish_semester_tx(
    PDO $pdo,
    string $trackKey,
    string $schoolYear,
    int $actorUserId,
    ?string $note = null,
    bool $advanceSchoolYearOnEnd = true
): array {
    if (!in_array($trackKey, ["junior_high_school", "senior_high_school"], true)) {
        throw new InvalidArgumentException("Invalid track.");
    }

    $activeTerm = academic_period_active_term_id($trackKey);
    $termsToClose = academic_period_semester_term_ids($trackKey, $activeTerm);

    $stmt = $pdo->prepare(
        "INSERT INTO grading_period_closures (school_year, track_key, term_id, closed_by, note)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
           closed_at = CURRENT_TIMESTAMP,
           closed_by = VALUES(closed_by),
           note = VALUES(note)"
    );

    foreach ($termsToClose as $termId) {
        $stmt->execute([$schoolYear, $trackKey, $termId, $actorUserId > 0 ? $actorUserId : null, $note]);
    }

    $lastClosed = $termsToClose[count($termsToClose) - 1];
    $next = academic_period_next_open_term($trackKey, $lastClosed);
    $nextSchoolYear = null;
    $summerBreak = false;

    if ($next !== null) {
        academic_period_set_active_term($trackKey, $next["term_id"]);
    } elseif ($advanceSchoolYearOnEnd) {
        $summerBreak = true;
        if (preg_match('/^(\d{4})-(\d{4})$/', $schoolYear, $m)) {
            $nextSchoolYear = ((int)$m[1] + 1) . "-" . ((int)$m[2] + 1);
            academic_period_set_active_school_year($nextSchoolYear);
        }
        $firstTerm = $trackKey === "senior_high_school" ? "SHS_S1_Q1" : "JHS_Q1";
        academic_period_set_active_term($trackKey, $firstTerm);
        $next = [
            "term_id" => $firstTerm,
            "name" => curriculum_term_meta($firstTerm)["name"] ?? $firstTerm,
            "semester_label" => curriculum_term_meta($firstTerm)["semester_name"] ?? "1st Period",
        ];
    }

    return [
        "closed_terms" => $termsToClose,
        "next_term" => $next["term_id"] ?? null,
        "next_school_year" => $nextSchoolYear,
        "summer_break" => $summerBreak,
    ];
}

/** @deprecated Use academic_period_finish_semesters() for transactional batch closes. */
function academic_period_finish_semester(
    string $trackKey,
    string $schoolYear,
    int $actorUserId,
    ?string $note = null,
    bool $advanceSchoolYearOnEnd = true
): array {
    $batch = academic_period_finish_semesters(
        [$trackKey],
        $schoolYear,
        $actorUserId,
        $note,
        $advanceSchoolYearOnEnd
    );
    return $batch["results"][$trackKey] ?? [
        "closed_terms" => [],
        "next_term" => null,
        "next_school_year" => null,
        "summer_break" => false,
    ];
}

function academic_period_reopen_term(string $schoolYear, string $termId, ?PDO $pdo = null): bool
{
    ensure_grading_period_closures_table();
    $conn = $pdo ?? db();
    $stmt = $conn->prepare(
        "DELETE FROM grading_period_closures WHERE school_year = ? AND term_id = ?"
    );
    $stmt->execute([$schoolYear, $termId]);
    return $stmt->rowCount() > 0;
}

function academic_period_status_summary(): array
{
    $sy = academic_period_active_school_year();
    $jhsTerm = academic_period_active_term_id("junior_high_school");
    $shsTerm = academic_period_active_term_id("senior_high_school");

    return [
        "school_year" => $sy,
        "active_term_jhs" => $jhsTerm,
        "active_term_jhs_label" => curriculum_term_label($jhsTerm),
        "active_term_shs" => $shsTerm,
        "active_term_shs_label" => curriculum_term_label($shsTerm),
        "jhs_end_of_school_year_semester" => academic_period_is_second_school_semester("junior_high_school", $jhsTerm),
        "shs_end_of_school_year_semester" => academic_period_is_second_school_semester("senior_high_school", $shsTerm),
        "closures" => academic_period_list_closures($sy, 20),
    ];
}

function academic_period_grading_blocked_message(string $schoolYear, string $termId): ?string
{
    if (!academic_period_is_term_closed($schoolYear, $termId)) {
        return null;
    }
    return "This grading period is closed for school year {$schoolYear}. Contact an administrator to reopen it or use the active period.";
}
