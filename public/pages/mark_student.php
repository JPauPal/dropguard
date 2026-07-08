<?php
declare(strict_types=1);

require_once __DIR__ . "/../../app/auth.php";
require_once __DIR__ . "/../../app/db.php";
require_once __DIR__ . "/../../app/marks.php";

require_login();
require_role(["Counselor", "Admin"]);

if (($_SERVER["REQUEST_METHOD"] ?? "GET") !== "POST") {
    http_response_code(405);
    echo "Method Not Allowed";
    exit;
}
require_valid_csrf_token();

$action = trim((string)($_POST["action"] ?? "add"));
$redirect = trim((string)($_POST["redirect"] ?? "dashboard"));
if ($redirect === "") {
    $redirect = "dashboard";
}

ensure_student_marks_table();

if ($action === "resolve") {
    $flagId = (int)($_POST["flag_id"] ?? 0);
    if ($flagId > 0) {
        $meta = null;
        try {
            $stmt = db()->prepare(
                "SELECT flag_id, student_id, issue_type, severity, note, is_active
                 FROM student_flags
                 WHERE flag_id = ?
                 LIMIT 1"
            );
            $stmt->execute([$flagId]);
            $meta = $stmt->fetch();
        } catch (Throwable) {
            $meta = null;
        }
        resolve_student_flag($flagId);
        audit_log(
            "flag_resolve",
            "success",
            "student_flag",
            $flagId,
            "Flag resolved.",
            [
                "student_id" => isset($meta["student_id"]) ? (int)$meta["student_id"] : null,
                "issue_type" => isset($meta["issue_type"]) ? (string)$meta["issue_type"] : null,
                "severity" => isset($meta["severity"]) ? (string)$meta["severity"] : null,
                "note" => isset($meta["note"]) ? (string)$meta["note"] : null,
            ]
        );
    }
    start_app_session();
    $_SESSION["flash"] = "Flag resolved.";
    header("Location: " . $redirect);
    exit;
}

// Default: add new flag
$studentId = (int)($_POST["student_id"] ?? 0);
$issueType = trim((string)($_POST["issue_type"] ?? ""));
$severity = trim((string)($_POST["severity"] ?? "Moderate"));
$note = trim((string)($_POST["note"] ?? ""));

$allowedIssues = ["Health", "Financial", "Behavioral", "Academic", "Family", "Other"];
$allowedSeverity = ["Low", "Moderate", "High"];

if ($studentId <= 0) {
    start_app_session();
    $_SESSION["flash"] = "Invalid student.";
    header("Location: " . $redirect);
    exit;
}

if (!in_array($issueType, $allowedIssues, true)) {
    start_app_session();
    $_SESSION["flash"] = "Please select an issue type.";
    header("Location: " . $redirect);
    exit;
}

if (!in_array($severity, $allowedSeverity, true)) {
    $severity = "Moderate";
}

$flagId = add_student_flag(
    $studentId,
    $issueType,
    $severity,
    $note !== "" ? $note : null,
    (int)(current_user()["user_id"] ?? 0)
);

audit_log(
    "flag_add",
    "success",
    "student",
    $studentId,
    "Student flagged: {$issueType} ({$severity}).",
    ["flag_id" => $flagId, "issue_type" => $issueType, "severity" => $severity, "note" => $note]
);

start_app_session();
$_SESSION["flash"] = "Flag added: {$issueType} ({$severity}).";
header("Location: " . $redirect);
exit;
