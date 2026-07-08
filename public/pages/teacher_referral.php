<?php
declare(strict_types=1);

require_once __DIR__ . "/../../app/auth.php";
require_once __DIR__ . "/../../app/db.php";
require_once __DIR__ . "/../../app/workflow.php";
require_once __DIR__ . "/../../app/curriculum.php";
require_once __DIR__ . "/../../app/students_archive.php";

require_login();
require_role(["Teacher", "Admin"]);
ensure_workflow_tables();
ensure_students_archived_column();

$user = current_user();
$studentId = (int)($_GET["student_id"] ?? $_POST["student_id"] ?? 0);
if ($studentId <= 0) {
    http_response_code(400);
    echo "Invalid student.";
    exit;
}

$stmtStudent = db()->prepare(
    "SELECT student_id, name, grade_level, strand, COALESCE(is_archived,0) AS is_archived FROM students WHERE student_id = ? LIMIT 1"
);
$stmtStudent->execute([$studentId]);
$student = $stmtStudent->fetch();
if (!$student) {
    http_response_code(404);
    echo "Student not found.";
    exit;
}
if ((int)($student["is_archived"] ?? 0) === 1) {
    http_response_code(403);
    echo "This student is archived. Unarchive the record before creating a referral.";
    exit;
}

if (($user["role"] ?? "") === "Teacher") {
    $chk = db()->prepare("SELECT 1 FROM teacher_students WHERE teacher_user_id = ? AND student_id = ? LIMIT 1");
    $chk->execute([(int)$user["user_id"], $studentId]);
    if (!$chk->fetch()) {
        http_response_code(403);
        echo "Forbidden";
        exit;
    }
}

$error = null;
$success = null;
if (($_SERVER["REQUEST_METHOD"] ?? "GET") === "POST") {
    require_valid_csrf_token();
    $reason = trim((string)($_POST["reason"] ?? ""));
    $details = trim((string)($_POST["details"] ?? ""));
    if ($reason === "") {
        $error = "Please provide a referral reason.";
    } else {
        $stmt = db()->prepare(
            "INSERT INTO manual_referrals (student_id, teacher_user_id, reason, details, status)
             VALUES (?,?,?,?, 'New')"
        );
        $stmt->execute([
            $studentId,
            (int)$user["user_id"],
            db_field_encrypt($reason),
            $details !== "" ? db_field_encrypt($details) : null,
        ]);
        $success = "Referral submitted to Guidance Office.";
        audit_log("manual_referral_create", "success", "student", $studentId, "Teacher submitted manual referral.", [
            "reason" => $reason,
        ]);
    }
}

ob_start();
?>
<div class="dg-page-header mb-3">
  <h3 class="mb-0">Manual Referral</h3>
  <div class="text-muted small">
    <?= htmlspecialchars((string)$student["name"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>
    (<?= htmlspecialchars((string)$student["grade_level"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?><?php if (!empty($student["strand"]) && curriculum_is_senior_high_grade((string)($student["grade_level"] ?? ""))): ?>, <?= htmlspecialchars((string)$student["strand"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?><?php endif; ?>)
  </div>
</div>
<div class="card shadow-sm">
  <div class="card-body">
    <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></div><?php endif; ?>
    <form method="post" action="teacher-referral">
      <?= csrf_field() ?>
      <input type="hidden" name="student_id" value="<?= (int)$studentId ?>" />
      <div class="mb-2">
        <label class="form-label">Reason (required)</label>
        <input class="form-control" name="reason" placeholder="e.g., Sudden behavioral change" required />
      </div>
      <div class="mb-2">
        <label class="form-label">Details (optional)</label>
        <textarea class="form-control" rows="4" name="details" placeholder="Describe observations that grades may not show..."></textarea>
      </div>
      <div class="d-flex gap-2">
        <button class="btn btn-dark" type="submit">Submit Referral</button>
        <a class="btn btn-outline-secondary" href="teacher-overview">Back</a>
      </div>
    </form>
  </div>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . "/_layout.php";

