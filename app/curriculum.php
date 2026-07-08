<?php
declare(strict_types=1);

require_once __DIR__ . "/db.php";

/**
 * K–12 curriculum: Junior High (quarterly) and Senior High (semestral + quarters) as separate JSON configs.
 */

function curriculum_config_dir(): string
{
    return __DIR__ . "/config/curriculum";
}

function curriculum_load_junior_high(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $path = curriculum_config_dir() . "/junior_high_school.json";
    $raw = is_readable($path) ? file_get_contents($path) : "[]";
    $data = json_decode((string)$raw, true);
    $cache = is_array($data) ? $data : [];
    return $cache;
}

function curriculum_load_senior_high(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $path = curriculum_config_dir() . "/senior_high_school.json";
    $raw = is_readable($path) ? file_get_contents($path) : "[]";
    $data = json_decode((string)$raw, true);
    $cache = is_array($data) ? $data : [];
    return $cache;
}

/** @return array{junior_high_school: array, senior_high_school: array} */
function curriculum_structure(): array
{
    return [
        "junior_high_school" => curriculum_load_junior_high(),
        "senior_high_school" => curriculum_load_senior_high(),
    ];
}

function curriculum_grade_number(?string $gradeLevel): ?int
{
    if ($gradeLevel === null || $gradeLevel === "") {
        return null;
    }
    $v = strtolower(trim((string)$gradeLevel));
    $v = preg_replace('/^grade\s*/', "", $v);
    if (!preg_match('/^\d{1,2}$/', $v)) {
        return null;
    }
    $n = (int)$v;
    return ($n >= 7 && $n <= 12) ? $n : null;
}

/**
 * Sort rows that include `grade_level` (e.g. SELECT DISTINCT grade_level) as 7…12, not alphabetically.
 *
 * @param list<array<string, mixed>> $rows
 * @return list<array<string, mixed>>
 */
function curriculum_sort_rows_by_grade_level(array $rows): array
{
    usort($rows, static function (array $a, array $b): int {
        $na = curriculum_grade_number((string)($a["grade_level"] ?? ""));
        $nb = curriculum_grade_number((string)($b["grade_level"] ?? ""));
        $ia = $na ?? 999;
        $ib = $nb ?? 999;
        if ($ia !== $ib) {
            return $ia <=> $ib;
        }
        return strcmp((string)($a["grade_level"] ?? ""), (string)($b["grade_level"] ?? ""));
    });
    return array_values($rows);
}

function curriculum_normalize_grade_level(string $raw): ?string
{
    $n = curriculum_grade_number($raw);
    if ($n === null) {
        return null;
    }
    return "Grade " . $n;
}

/** @return 'junior_high_school'|'senior_high_school'|null */
function curriculum_track_for_grade(?string $gradeLevel): ?string
{
    $n = curriculum_grade_number($gradeLevel);
    if ($n === null) {
        return null;
    }
    if ($n >= 7 && $n <= 10) {
        return "junior_high_school";
    }
    if ($n >= 11 && $n <= 12) {
        return "senior_high_school";
    }
    return null;
}

/**
 * SHS strand/track groups: legacy DepEd strands + Strengthened SHS (Education, TechVoc).
 *
 * @return list<array{program_key:string,display_name:string,strands:list<array{code:string,label:string}>}>
 */
function curriculum_shs_strand_groups(): array
{
    $c = curriculum_load_senior_high();
    $out = [];
    foreach ($c["curriculum_programs"] ?? [] as $row) {
        if (!is_array($row) || empty($row["program_key"])) {
            continue;
        }
        $strands = [];
        foreach ($row["strands"] ?? [] as $st) {
            if (!is_array($st) || !isset($st["code"])) {
                continue;
            }
            $code = trim((string)$st["code"]);
            if ($code === "") {
                continue;
            }
            $label = trim((string)($st["label"] ?? $code));
            $strands[] = [
                "code" => $code,
                "label" => $label !== "" ? $label : $code,
            ];
        }
        if (!$strands) {
            continue;
        }
        $out[] = [
            "program_key" => (string)$row["program_key"],
            "display_name" => trim((string)($row["display_name"] ?? $row["program_key"])),
            "strands" => $strands,
        ];
    }
    if ($out) {
        return $out;
    }
    return [
        [
            "program_key" => "legacy",
            "display_name" => "Legacy SHS (Strands)",
            "strands" => [
                ["code" => "STEM", "label" => "STEM"],
                ["code" => "ABM", "label" => "ABM"],
                ["code" => "HUMSS", "label" => "HUMSS"],
                ["code" => "TVL", "label" => "TVL"],
            ],
        ],
        [
            "program_key" => "strengthened",
            "display_name" => "Strengthened SHS (SSHS)",
            "strands" => [
                ["code" => "Education", "label" => "Education (Academic)"],
                ["code" => "TechVoc", "label" => "TechVoc (Technical-Professional)"],
            ],
        ],
    ];
}

/** Flat list of valid SHS strand/track codes for validation and filters. */
function curriculum_shs_strands(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $codes = [];
    foreach (curriculum_shs_strand_groups() as $group) {
        foreach ($group["strands"] as $st) {
            $codes[] = $st["code"];
        }
    }
    $cache = array_values(array_unique($codes));
    return $cache;
}

function curriculum_shs_strand_label(string $code): string
{
    $code = trim($code);
    foreach (curriculum_shs_strand_groups() as $group) {
        foreach ($group["strands"] as $st) {
            if ($st["code"] === $code) {
                return $st["label"];
            }
        }
    }
    return $code;
}

/** Normalize import/UI strand input (aliases, case) to a canonical code or null. */
function curriculum_normalize_shs_strand(string $raw): ?string
{
    $t = trim($raw);
    if ($t === "") {
        return null;
    }
    foreach (curriculum_shs_strands() as $code) {
        if (strcasecmp($t, $code) === 0) {
            return $code;
        }
    }
    $aliases = curriculum_load_senior_high()["strand_aliases"] ?? [];
    if (is_array($aliases)) {
        $upper = strtoupper($t);
        foreach ($aliases as $from => $to) {
            if (strtoupper((string)$from) === $upper) {
                $to = trim((string)$to);
                if ($to !== "" && in_array($to, curriculum_shs_strands(), true)) {
                    return $to;
                }
            }
        }
    }
    return null;
}

/** Render <option> / <optgroup> markup for SHS strand selects. */
function curriculum_echo_shs_strand_options(?string $selected = null): void
{
    $selected = $selected !== null ? trim($selected) : "";
    $selectedNorm = $selected !== "" ? (curriculum_normalize_shs_strand($selected) ?? $selected) : "";
    foreach (curriculum_shs_strand_groups() as $group) {
        $label = trim((string)($group["display_name"] ?? ""));
        if ($label === "") {
            $label = (string)($group["program_key"] ?? "SHS");
        }
        echo "<optgroup label=\"" . htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") . "\">\n";
        foreach ($group["strands"] as $st) {
            $code = (string)$st["code"];
            $sel = $selectedNorm !== "" && strcasecmp($selectedNorm, $code) === 0 ? " selected" : "";
            echo "<option value=\"" . htmlspecialchars($code, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") . "\"{$sel}>"
                . htmlspecialchars((string)$st["label"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8")
                . "</option>\n";
        }
        echo "</optgroup>\n";
    }
}

function curriculum_is_senior_high_grade(?string $gradeLevel): bool
{
    $n = curriculum_grade_number($gradeLevel);
    return $n === 11 || $n === 12;
}

/** Whether UI / filters should expose strand (SHS bucket or Grade 11/12). */
function curriculum_strand_filter_applies(?string $gradeBucket): bool
{
    if ($gradeBucket === null || $gradeBucket === "") {
        return false;
    }
    if ($gradeBucket === "SHS") {
        return true;
    }
    return curriculum_is_senior_high_grade($gradeBucket);
}

/**
 * Validates strand for the given grade; JHS always returns [null, null].
 *
 * @return array{0: ?string, 1: ?string} [normalized strand or null, error message or null]
 */
function curriculum_validate_strand_input(?string $gradeLevel, string $rawStrand): array
{
    if (!curriculum_is_senior_high_grade($gradeLevel)) {
        return [null, null];
    }
    $t = trim($rawStrand);
    if ($t === "") {
        return [null, function_exists("t") ? t("curriculum.err_strand_required_shs") : "Strand is required for Grade 11 and Grade 12."];
    }
    $norm = curriculum_normalize_shs_strand($t);
    if ($norm === null || !in_array($norm, curriculum_shs_strands(), true)) {
        return [null, function_exists("t") ? t("curriculum.err_strand_invalid") : "Invalid strand. Choose a listed Senior High strand."];
    }
    return [$norm, null];
}

/** @return 'junior_high_school'|'senior_high_school'|null */
function curriculum_track_for_term_id(string $termId): ?string
{
    return curriculum_term_meta($termId)["track"] ?? null;
}

function curriculum_default_total_school_days_per_quarter(): int
{
    return 50; // ~10 weeks
}

function curriculum_default_total_school_days_per_shs_quarter(): int
{
    // SHS: two quarters per semester; ~90–100+ class days per semester.
    // Using 50 per quarter keeps semester totals ~100 (Q1+Q2 or Q3+Q4).
    return 50;
}

function curriculum_default_total_school_days_per_semester(): int
{
    return 100; // ~20 weeks
}

/**
 * @return list<array{term_id:string,name:string,term_sort:int,semester_name:?string,default_total_days:int}>
 */
function curriculum_performance_terms(string $trackKey): array
{
    if ($trackKey === "junior_high_school") {
        $c = curriculum_load_junior_high();
        $out = [];
        foreach ($c["grading_periods"] ?? [] as $row) {
            if (!is_array($row) || !isset($row["term_id"], $row["name"])) {
                continue;
            }
            $out[] = [
                "term_id" => (string)$row["term_id"],
                "name" => (string)$row["name"],
                "term_sort" => (int)($row["term_sort"] ?? count($out) + 1),
                "semester_name" => null,
                "default_total_days" => curriculum_default_total_school_days_per_quarter(),
            ];
        }
        return $out;
    }
    if ($trackKey === "senior_high_school") {
        $c = curriculum_load_senior_high();
        $out = [];
        foreach ($c["semesters"] ?? [] as $sem) {
            if (!is_array($sem)) {
                continue;
            }
            $semName = (string)($sem["name"] ?? "");
            foreach ($sem["grading_periods"] ?? [] as $row) {
                if (!is_array($row) || !isset($row["term_id"], $row["name"])) {
                    continue;
                }
                $out[] = [
                    "term_id" => (string)$row["term_id"],
                    "name" => (string)$row["name"],
                    "term_sort" => (int)($row["term_sort"] ?? count($out) + 1),
                    "semester_name" => $semName !== "" ? $semName : null,
                    "default_total_days" => curriculum_default_total_school_days_per_shs_quarter(),
                ];
            }
        }
        return $out;
    }
    return [];
}

/** @return ?array{term_id:string,name:string,term_sort:int,semester_name:?string,track:string} */
function curriculum_term_meta(string $termId): ?array
{
    foreach (["junior_high_school", "senior_high_school"] as $tk) {
        foreach (curriculum_performance_terms($tk) as $row) {
            if ($row["term_id"] === $termId) {
                return $row + ["track" => $tk];
            }
        }
    }
    return null;
}

function curriculum_term_label(?string $termId): string
{
    if ($termId === null || $termId === "") {
        return "—";
    }
    $m = curriculum_term_meta($termId);
    if (!$m) {
        return $termId;
    }
    if (!empty($m["semester_name"])) {
        return $m["semester_name"] . " — " . $m["name"];
    }
    return $m["name"];
}

function curriculum_term_id_for_track_quarter(string $trackKey, int $quarter): ?string
{
    if ($quarter < 1 || $quarter > 4) {
        return null;
    }
    if ($trackKey === "junior_high_school") {
        return "JHS_Q" . $quarter;
    }
    if ($trackKey === "senior_high_school") {
        if ($quarter <= 2) {
            return "SHS_S1_Q" . $quarter;
        }
        return "SHS_S2_Q" . $quarter;
    }
    return null;
}

function curriculum_valid_term_for_grade(?string $gradeLevel, string $termId): bool
{
    $gTrack = curriculum_track_for_grade($gradeLevel);
    $tTrack = curriculum_track_for_term_id($termId);
    return $gTrack !== null && $gTrack === $tTrack;
}

/**
 * Add performance.term_id (semantic period) while keeping quarter (1–4) for ML / legacy joins.
 */
function curriculum_ensure_performance_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $pdo = db();
    $stmt = $pdo->query("SHOW COLUMNS FROM `performance` LIKE 'term_id'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE `performance` ADD COLUMN `term_id` VARCHAR(32) NULL AFTER `student_id`");
    }
    $pdo->exec(
        "UPDATE `performance` SET `term_id` = CONCAT('JHS_Q', `quarter`)
         WHERE (`term_id` IS NULL OR `term_id` = '') AND `quarter` BETWEEN 1 AND 4"
    );
    $pdo->exec("UPDATE `performance` SET `term_id` = 'JHS_Q1' WHERE `term_id` IS NULL OR `term_id` = ''");

    // Add school_year to performance so multiple semesters/years can coexist (for totals).
    $stmtSy = $pdo->query("SHOW COLUMNS FROM `performance` LIKE 'school_year'");
    if (!$stmtSy->fetch()) {
        $pdo->exec("ALTER TABLE `performance` ADD COLUMN `school_year` VARCHAR(9) NULL AFTER `student_id`");
    }
    // Backfill school_year from the latest student_batches row per student when possible.
    try {
        $pdo->exec(
            "UPDATE performance p
             LEFT JOIN (
               SELECT b1.student_id, b1.school_year
               FROM student_batches b1
               INNER JOIN (
                 SELECT student_id, MAX(batch_id) AS max_batch_id
                 FROM student_batches
                 GROUP BY student_id
               ) latestb ON latestb.student_id = b1.student_id AND latestb.max_batch_id = b1.batch_id
             ) sb ON sb.student_id = p.student_id
             SET p.school_year = COALESCE(NULLIF(p.school_year,''), sb.school_year)"
        );
    } catch (Throwable) {
        // ignore if student_batches doesn't exist yet
    }

    // Ensure unique keys allow multiple years/semesters.
    try { $pdo->exec("ALTER TABLE `performance` DROP INDEX `uniq_student_quarter`"); } catch (Throwable) {}
    try { $pdo->exec("ALTER TABLE `performance` DROP INDEX `uniq_student_term`"); } catch (Throwable) {}
    try {
        $pdo->exec("ALTER TABLE `performance` ADD UNIQUE KEY `uniq_student_term_year` (`student_id`, `school_year`, `term_id`)");
    } catch (Throwable) {
        // already exists
    }
    try {
        $pdo->exec("ALTER TABLE `performance` ADD UNIQUE KEY `uniq_student_quarter_year` (`student_id`, `school_year`, `quarter`)");
    } catch (Throwable) {
    }
    $stmtR = $pdo->query("SHOW COLUMNS FROM `risk_analysis` LIKE 'term_id'");
    if (!$stmtR->fetch()) {
        $pdo->exec("ALTER TABLE `risk_analysis` ADD COLUMN `term_id` VARCHAR(32) NULL AFTER `student_id`");
    }
    $stmtRsy = $pdo->query("SHOW COLUMNS FROM `risk_analysis` LIKE 'school_year'");
    if (!$stmtRsy->fetch()) {
        $pdo->exec("ALTER TABLE `risk_analysis` ADD COLUMN `school_year` VARCHAR(9) NULL AFTER `student_id`");
    }
    $pdo->exec(
        "UPDATE `risk_analysis` SET `term_id` = CONCAT('JHS_Q', `quarter`)
         WHERE (`term_id` IS NULL OR `term_id` = '') AND `quarter` IS NOT NULL"
    );
    $done = true;
}
