<?php
declare(strict_types=1);

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/settings.php";
require_once __DIR__ . "/grading.php";
require_once __DIR__ . "/academic_period.php";
require_once __DIR__ . "/curriculum.php";
require_once __DIR__ . "/batches.php";
require_once __DIR__ . "/auth.php";
require_once __DIR__ . "/digest.php";

/**
 * Admin settings and academic calendar actions.
 *
 * Grade transmutation follows DepEd Order No. 8 via grading.php::deped_transmute_grade():
 *   initial < 60  → 60 + (initial × 0.25)
 *   initial ≥ 60  → 75 + ((initial − 60) × 0.625)
 */
final class SettingsController
{
    /**
     * @param array<string, mixed> $post
     * @param array<string, mixed> $user
     * @return array{error: ?string, success: ?string}
     */
    public static function handlePost(array $post, array $user): array
    {
        $formType = trim((string)($post["form_type"] ?? "settings"));

        return match ($formType) {
            "finish_semester" => self::handleFinishSemester($post, $user),
            "reopen_term" => self::handleReopenTerm($post, $user),
            "active_period" => self::handleActivePeriod($post, $user),
            "digest_settings" => self::handleDigestSettings($post, $user),
            default => self::handleSaveSettings($post, $user),
        };
    }

    /**
     * @param list<string> $trackKeys
     * @return array<string, mixed>
     */
    public static function previewFinishSemester(array $trackKeys, string $schoolYear): array
    {
        return academic_period_preview_finish($trackKeys, $schoolYear);
    }

    /**
     * @param array<string, mixed> $post
     * @param array<string, mixed> $user
     * @return array{error: ?string, success: ?string}
     */
    private static function handleSaveSettings(array $post, array $user): array
    {
        $parsed = self::parseSettingsInput($post);
        if ($parsed["error"] !== null) {
            return ["error" => $parsed["error"], "success" => null];
        }

        $input = $parsed["data"];
        $previous = self::currentSettingsSnapshot();

        set_setting("risk_low_max", number_format($input["risk_low_max"], 2, ".", ""));
        set_setting("risk_high_min", number_format($input["risk_high_min"], 2, ".", ""));
        set_setting("grade_weight_quiz", number_format($input["grade_weight_quiz"], 0, ".", ""));
        set_setting("grade_weight_exam", number_format($input["grade_weight_exam"], 0, ".", ""));
        set_setting("grade_weight_project", number_format($input["grade_weight_project"], 0, ".", ""));
        set_setting("grade_extracurricular_max", number_format($input["grade_extracurricular_max"], 0, ".", ""));
        set_setting("enable_transmutation", $input["enable_transmutation"] ? "1" : "0");
        set_setting("grade_use_deped_transmutation", $input["enable_transmutation"] ? "1" : "0");

        $newValue = self::currentSettingsSnapshot();

        self::auditStateChange(
            "SETTINGS_UPDATE",
            $user,
            $previous,
            $newValue,
            "Updated system settings and grading configuration."
        );

        if ($previous["enable_transmutation"] !== $newValue["enable_transmutation"]) {
            self::auditStateChange(
                "DEPED_TRANSMUTATION_TOGGLE",
                $user,
                ["enable_transmutation" => $previous["enable_transmutation"]],
                ["enable_transmutation" => $newValue["enable_transmutation"]],
                "Toggled DepEd Order No. 8 grade transmutation."
            );
        }

        return ["error" => null, "success" => "System settings updated."];
    }

    /**
     * @param array<string, mixed> $post
     * @param array<string, mixed> $user
     * @return array{error: ?string, success: ?string}
     */
    private static function handleFinishSemester(array $post, array $user): array
    {
        $track = trim((string)($post["track_key"] ?? ""));
        $schoolYear = trim((string)($post["school_year"] ?? academic_period_active_school_year()));
        $note = self::sanitizeNote((string)($post["closure_note"] ?? ""));
        $advanceYear = !empty($post["advance_school_year"]);
        $confirmEndOfYear = !empty($post["confirm_end_of_school_year"]);

        $tracks = self::resolveTrackKeys($track);
        if ($tracks === []) {
            return ["error" => "Select Junior High, Senior High, or Both.", "success" => null];
        }
        if (!self::isValidSchoolYear($schoolYear)) {
            return ["error" => "School year must be in YYYY-YYYY format with consecutive years (e.g. 2025-2026).", "success" => null];
        }

        $preview = academic_period_preview_finish($tracks, $schoolYear);
        if ($preview["requires_end_of_school_year_confirmation"] && !$confirmEndOfYear) {
            return [
                "error" => "This action closes the final semester of the school year. Check the end-of-year confirmation box to proceed.",
                "success" => null,
            ];
        }

        $actorUserId = (int)($user["user_id"] ?? 0);
        $previous = academic_period_snapshot();

        try {
            $batch = academic_period_finish_semesters(
                $tracks,
                $schoolYear,
                $actorUserId,
                $note !== "" ? $note : null,
                $advanceYear
            );
        } catch (Throwable $e) {
            self::auditStateChange(
                "CLOSE_SEMESTER",
                $user,
                $previous,
                $previous,
                "Failed to close semester: " . $e->getMessage(),
                "failure"
            );
            return ["error" => $e->getMessage(), "success" => null];
        }

        $newValue = $batch["after"];
        $summaryParts = [];
        foreach ($batch["results"] as $tk => $result) {
            $label = $tk === "senior_high_school" ? "SHS" : "JHS";
            $closed = implode(", ", $result["closed_terms"]);
            $next = (string)($result["next_term"] ?? "");
            $summaryParts[] = "{$label}: closed {$closed}" . ($next !== "" ? " → next {$next}" : "");
            if (!empty($result["next_school_year"])) {
                $summaryParts[] = "School year advanced to " . $result["next_school_year"];
            }
        }

        self::auditStateChange(
            "CLOSE_SEMESTER",
            $user,
            $previous,
            $newValue,
            "Closed semester grading periods.",
            "success",
            [
                "track_key" => $track,
                "school_year" => $schoolYear,
                "advance_school_year" => $advanceYear ? 1 : 0,
                "closed_batches" => $batch["results"],
                "note" => $note,
            ]
        );

        return [
            "error" => null,
            "success" => "Semester finished. " . implode("; ", $summaryParts),
        ];
    }

    /**
     * @param array<string, mixed> $post
     * @param array<string, mixed> $user
     * @return array{error: ?string, success: ?string}
     */
    private static function handleReopenTerm(array $post, array $user): array
    {
        $schoolYear = trim((string)($post["reopen_school_year"] ?? ""));
        $termId = trim((string)($post["reopen_term_id"] ?? ""));

        if (!self::isValidSchoolYear($schoolYear)) {
            return ["error" => "School year must be in YYYY-YYYY format.", "success" => null];
        }
        if ($termId === "" || curriculum_term_meta($termId) === null) {
            return ["error" => "A valid grading period is required to reopen.", "success" => null];
        }

        $previous = [
            "school_year" => $schoolYear,
            "term_id" => $termId,
            "closed" => true,
        ];

        $pdo = db();
        $pdo->beginTransaction();
        try {
            if (!academic_period_reopen_term($schoolYear, $termId, $pdo)) {
                $pdo->rollBack();
                return ["error" => "No closure found for that period.", "success" => null];
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ["error" => $e->getMessage(), "success" => null];
        }

        $newValue = ["school_year" => $schoolYear, "term_id" => $termId, "closed" => false];
        self::auditStateChange(
            "REOPEN_GRADING_PERIOD",
            $user,
            $previous,
            $newValue,
            "Reopened grading period " . curriculum_term_label($termId) . " for {$schoolYear}."
        );

        return [
            "error" => null,
            "success" => "Reopened " . curriculum_term_label($termId) . " for {$schoolYear}.",
        ];
    }

    /**
     * @param array<string, mixed> $post
     * @param array<string, mixed> $user
     * @return array{error: ?string, success: ?string}
     */
    private static function handleActivePeriod(array $post, array $user): array
    {
        $schoolYear = trim((string)($post["active_school_year"] ?? ""));
        $jhsTerm = trim((string)($post["active_term_jhs"] ?? ""));
        $shsTerm = trim((string)($post["active_term_shs"] ?? ""));

        if (!self::isValidSchoolYear($schoolYear)) {
            return ["error" => "School year must be in YYYY-YYYY format.", "success" => null];
        }
        if (curriculum_term_meta($jhsTerm) === null || (curriculum_term_meta($jhsTerm)["track"] ?? "") !== "junior_high_school") {
            return ["error" => "Invalid Junior High grading period.", "success" => null];
        }
        if (curriculum_term_meta($shsTerm) === null || (curriculum_term_meta($shsTerm)["track"] ?? "") !== "senior_high_school") {
            return ["error" => "Invalid Senior High grading period.", "success" => null];
        }

        $previous = academic_period_snapshot();
        $pdo = db();
        $pdo->beginTransaction();
        try {
            academic_period_set_active_school_year($schoolYear);
            academic_period_set_active_term("junior_high_school", $jhsTerm);
            academic_period_set_active_term("senior_high_school", $shsTerm);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ["error" => $e->getMessage(), "success" => null];
        }

        $newValue = academic_period_snapshot();
        self::auditStateChange(
            "UPDATE_ACTIVE_PERIOD",
            $user,
            $previous,
            $newValue,
            "Updated active grading period."
        );

        return ["error" => null, "success" => "Active grading period updated."];
    }

    /**
     * @param array<string, mixed> $post
     * @param array<string, mixed> $user
     * @return array{error: ?string, success: ?string}
     */
    private static function handleDigestSettings(array $post, array $user): array
    {
        $email = trim((string)($post["digest_email"] ?? ""));
        $enabled = !empty($post["digest_enabled"]);
        $runNow = !empty($post["digest_run_now"]);

        if ($email !== "" && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ["error" => "Enter a valid counselor email address.", "success" => null];
        }

        $previous = [
            "digest_enabled" => digest_is_enabled(),
            "digest_email" => digest_recipient_email(),
        ];

        set_setting("digest_enabled", $enabled ? "1" : "0");
        set_setting("digest_email", $email);

        $newValue = [
            "digest_enabled" => $enabled,
            "digest_email" => $email,
        ];

        self::auditStateChange("DIGEST_SETTINGS_UPDATE", $user, $previous, $newValue, "Updated daily digest settings.");

        $success = "Daily digest settings saved.";
        if ($runNow) {
            $result = digest_run_daily();
            if (!empty($result["error"])) {
                return ["error" => (string)$result["error"], "success" => null];
            }
            $success .= " Test digest sent to " . $result["recipient"] . " (" . (int)$result["count"] . " student(s)).";
        }

        return ["error" => null, "success" => $success];
    }

    /**
     * @param array<string, mixed> $post
     * @return array{error: ?string, data: ?array<string, mixed>}
     */
    private static function parseSettingsInput(array $post): array
    {
        if (!is_numeric($post["risk_low_max"] ?? null) || !is_numeric($post["risk_high_min"] ?? null)) {
            return ["error" => "Risk thresholds must be numeric.", "data" => null];
        }

        $low = (float)$post["risk_low_max"];
        $high = (float)$post["risk_high_min"];
        $wQuiz = (float)($post["grade_weight_quiz"] ?? 30);
        $wExam = (float)($post["grade_weight_exam"] ?? 40);
        $wProject = (float)($post["grade_weight_project"] ?? 30);
        $extraMax = (float)($post["grade_extracurricular_max"] ?? 10);
        $weightSum = $wQuiz + $wExam + $wProject;

        if ($low < 0 || $low > 1 || $high < 0 || $high > 1 || $high < $low) {
            return ["error" => "Invalid thresholds. Ensure 0<=low<=high<=1.", "data" => null];
        }
        if ($wQuiz < 0 || $wExam < 0 || $wProject < 0 || abs($weightSum - 100.0) > 0.001) {
            return ["error" => "Invalid grading weights. Quiz, exam, and project must be non-negative and total 100.", "data" => null];
        }
        if ($extraMax < 0 || $extraMax > 100) {
            return ["error" => "Invalid extracurricular max. Use a value from 0 to 100.", "data" => null];
        }

        return [
            "error" => null,
            "data" => [
                "risk_low_max" => $low,
                "risk_high_min" => $high,
                "grade_weight_quiz" => $wQuiz,
                "grade_weight_exam" => $wExam,
                "grade_weight_project" => $wProject,
                "grade_extracurricular_max" => $extraMax,
                "enable_transmutation" => !empty($post["enable_transmutation"]),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function currentSettingsSnapshot(): array
    {
        $weights = grading_weights();
        return [
            "risk_low_max" => (float)(get_setting("risk_low_max", "0.40") ?? "0.40"),
            "risk_high_min" => (float)(get_setting("risk_high_min", "0.70") ?? "0.70"),
            "grade_weight_quiz" => (float)($weights["quiz"] ?? 30),
            "grade_weight_exam" => (float)($weights["exam"] ?? 40),
            "grade_weight_project" => (float)($weights["project"] ?? 30),
            "grade_extracurricular_max" => (float)grading_extracurricular_cap(),
            "enable_transmutation" => grading_transmutation_enabled(),
        ];
    }

    public static function isValidSchoolYear(string $schoolYear): bool
    {
        return is_valid_school_year_sequence(trim($schoolYear));
    }

    private static function sanitizeNote(string $note): string
    {
        $note = trim($note);
        if ($note === "") {
            return "";
        }
        return mb_substr($note, 0, 255);
    }

    /**
     * @return list<string>
     */
    private static function resolveTrackKeys(string $track): array
    {
        return match ($track) {
            "both" => ["junior_high_school", "senior_high_school"],
            "junior_high_school", "senior_high_school" => [$track],
            default => [],
        };
    }

    /**
     * @param array<string, mixed> $previous
     * @param array<string, mixed> $new
     * @param array<string, mixed> $extra
     */
    private static function auditStateChange(
        string $action,
        array $user,
        array $previous,
        array $new,
        string $description,
        string $status = "success",
        array $extra = []
    ): void {
        audit_log(
            $action,
            $status,
            "config",
            isset($user["user_id"]) ? (int)$user["user_id"] : null,
            $description,
            array_merge($extra, [
                "user_id" => (int)($user["user_id"] ?? 0),
                "previous_value" => $previous,
                "new_value" => $new,
                "ip_address" => (string)($_SERVER["REMOTE_ADDR"] ?? ""),
            ])
        );
    }
}
