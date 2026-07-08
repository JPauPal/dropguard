<?php

declare(strict_types=1);



require_once __DIR__ . "/db.php";

require_once __DIR__ . "/settings.php";

require_once __DIR__ . "/students_archive.php";

require_once __DIR__ . "/auth.php";



function digest_ensure_settings(): void

{

    ensure_app_settings_table();

    db()->exec("INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES ('digest_enabled', '0')");

    db()->exec("INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES ('digest_email', '')");

    db()->exec("INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES ('digest_last_run', '')");

}



function digest_is_enabled(): bool

{

    digest_ensure_settings();

    return in_array(strtolower(trim((string)(get_setting("digest_enabled", "0") ?? "0"))), ["1", "true", "yes", "on"], true);

}



function digest_recipient_email(): string

{

    digest_ensure_settings();

    return trim((string)(get_setting("digest_email", "") ?? ""));

}



/**

 * High-risk students flagged by analysis since the given datetime (inclusive).

 *

 * @return list<array{student_id:int, name:string, grade_level:string, section:string, risk_score:float, generated_at:string}>

 */

function digest_new_high_risk_students(?string $since = null): array

{

    $sinceDt = $since ?? date("Y-m-d 00:00:00");

    $stmt = db()->prepare(

        "SELECT s.student_id, s.name, s.grade_level, s.section, ra.probability_score AS risk_score, ra.generated_at

         FROM risk_analysis ra

         INNER JOIN students s ON s.student_id = ra.student_id

         INNER JOIN (

            SELECT student_id, MAX(generated_at) AS max_at

            FROM risk_analysis

            WHERE risk_level = 'High' AND generated_at >= ?

            GROUP BY student_id

         ) latest ON latest.student_id = ra.student_id AND latest.max_at = ra.generated_at

         WHERE ra.risk_level = 'High'

           AND " . students_non_archived_sql("s") . "

         ORDER BY s.name ASC"

    );

    $stmt->execute([$sinceDt]);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];



    $out = [];

    foreach ($rows as $row) {

        $out[] = [

            "student_id" => (int)($row["student_id"] ?? 0),

            "name" => (string)($row["name"] ?? ""),

            "grade_level" => (string)($row["grade_level"] ?? ""),

            "section" => (string)($row["section"] ?? ""),

            "risk_score" => (float)($row["risk_score"] ?? 0),

            "generated_at" => (string)($row["generated_at"] ?? ""),

        ];

    }

    return $out;

}



/**

 * @return array{sent: bool, recipient: string, count: int, error: ?string, students: list<array<string, mixed>>}

 */

function digest_run_daily(): array

{

    digest_ensure_settings();



    if (!digest_is_enabled()) {

        return ["sent" => false, "recipient" => "", "count" => 0, "error" => "Digest is disabled.", "students" => []];

    }



    $to = digest_recipient_email();

    if ($to === "" || !filter_var($to, FILTER_VALIDATE_EMAIL)) {

        return ["sent" => false, "recipient" => $to, "count" => 0, "error" => "Invalid or missing counselor email.", "students" => []];

    }



    $since = date("Y-m-d 00:00:00");

    $students = digest_new_high_risk_students($since);



    $subject = "Drop Guard Early Warning — " . count($students) . " new High Risk student(s) — " . date("M j, Y");

    $lines = [

        "Drop Guard Daily Early Warning Digest",

        "Date: " . date("Y-m-d H:i"),

        "",

    ];



    if ($students === []) {

        $lines[] = "No new High Risk students were detected today.";

    } else {

        $lines[] = "The following students were flagged High Risk today:";

        $lines[] = "";

        foreach ($students as $st) {

            $lines[] = sprintf(

                "- %s (%s, %s) — score %.2f at %s",

                $st["name"],

                $st["grade_level"],

                $st["section"] !== "" ? $st["section"] : "—",

                $st["risk_score"],

                $st["generated_at"]

            );

        }

        $lines[] = "";

        $lines[] = "Log in to Drop Guard for full profiles and intervention tools.";

    }



    $body = implode("\r\n", $lines);

    $headers = "From: Drop Guard <noreply@dropguard.local>\r\nContent-Type: text/plain; charset=UTF-8";



    $sent = @mail($to, $subject, $body, $headers);

    set_setting("digest_last_run", date("Y-m-d H:i:s"));



    if ($sent) {

        audit_log("digest_send", "success", "config", null, "Daily high-risk digest sent.", [

            "recipient" => $to,

            "count" => count($students),

        ]);

    } else {

        audit_log("digest_send", "failure", "config", null, "Daily digest mail() failed.", [

            "recipient" => $to,

            "count" => count($students),

        ]);

    }



    return [

        "sent" => $sent,

        "recipient" => $to,

        "count" => count($students),

        "error" => $sent ? null : "mail() failed — configure SMTP/sendmail on the server.",

        "students" => $students,

    ];

}

