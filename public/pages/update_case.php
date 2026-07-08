<?php
declare(strict_types=1);

require_once __DIR__ . "/../../app/auth.php";
require_once __DIR__ . "/../../app/db.php";
require_once __DIR__ . "/../../app/workflow.php";

require_login();
require_role(["Counselor", "Admin"]);
ensure_workflow_tables();

if (($_SERVER["REQUEST_METHOD"] ?? "GET") !== "POST") {
    http_response_code(405);
    echo "Method Not Allowed";
    exit;
}
require_valid_csrf_token();

$studentId = (int)($_POST["student_id"] ?? 0);
$status = (string)($_POST["status"] ?? "Flagged");
$hasNotes = array_key_exists("notes", $_POST);
$notes = trim((string)($_POST["notes"] ?? ""));
$redirect = trim((string)($_POST["redirect"] ?? ("student?id=" . $studentId)));

$allowed = ["Flagged", "Ongoing Counseling", "Resolved"];
if ($studentId <= 0 || !in_array($status, $allowed, true)) {
    start_app_session();
    $_SESSION["flash"] = "Invalid case update.";
    header("Location: dashboard");
    exit;
}

if ($hasNotes) {
    $stmt = db()->prepare(
        "INSERT INTO counseling_cases (student_id, status, counselor_user_id, notes)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
           status = VALUES(status),
           counselor_user_id = VALUES(counselor_user_id),
           notes = VALUES(notes)"
    );
    $notesStore = $notes !== "" ? db_field_encrypt($notes) : null;
    $stmt->execute([$studentId, $status, (int)(current_user()["user_id"] ?? 0), $notesStore]);
} else {
    $stmt = db()->prepare(
        "INSERT INTO counseling_cases (student_id, status, counselor_user_id, notes)
         VALUES (?, ?, ?, NULL)
         ON DUPLICATE KEY UPDATE
           status = VALUES(status),
           counselor_user_id = VALUES(counselor_user_id)"
    );
    $stmt->execute([$studentId, $status, (int)(current_user()["user_id"] ?? 0)]);
}

audit_log("case_status_update", "success", "student", $studentId, "Counseling case status updated.", [
    "status" => $status,
]);

start_app_session();
$_SESSION["flash"] = "Case status updated to {$status}.";
header("Location: " . $redirect);
exit;

