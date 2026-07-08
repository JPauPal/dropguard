<?php
declare(strict_types=1);

require_once __DIR__ . "/../../app/auth.php";
require_once __DIR__ . "/../../app/db.php";
require_once __DIR__ . "/../../app/workflow.php";
require_once __DIR__ . "/../../app/curriculum.php";
require_once __DIR__ . "/../../app/batches.php";
require_once __DIR__ . "/../../app/sections.php";
require_once __DIR__ . "/../../app/students_archive.php";

require_login();
require_role(["Teacher", "Admin"]);
ensure_workflow_tables();
curriculum_ensure_performance_schema();
ensure_students_archived_column();

$user = current_user();
$isTeacher = (($user["role"] ?? "") === "Teacher");

if ($isTeacher) {
    $stmt = db()->prepare(
        "SELECT s.student_id, s.lrn, s.name, s.grade_level, s.strand, s.section, s.gpa, s.absences, s.risk_level
         FROM teacher_students ts
         INNER JOIN students s ON s.student_id = ts.student_id
         WHERE ts.teacher_user_id = ? AND " . students_non_archived_sql("s") . "
         ORDER BY s.name ASC"
    );
    $stmt->execute([(int)$user["user_id"]]);
    $rows = $stmt->fetchAll();
} else {
    $rows = db()->query(
        "SELECT student_id, lrn, name, grade_level, strand, section, gpa, absences, risk_level FROM students WHERE COALESCE(is_archived,0) = 0 ORDER BY name ASC LIMIT 300"
    )->fetchAll();
}

start_app_session();
$flash = $_SESSION["flash"] ?? null;
unset($_SESSION["flash"]);
$error = null;

if (($_SERVER["REQUEST_METHOD"] ?? "GET") === "POST") {
    require_valid_csrf_token();
    $action = trim((string)($_POST["action"] ?? ""));
    $studentId = (int)($_POST["student_id"] ?? 0);

    if ($action === "remove_from_class") {
        if (!$isTeacher) {
            http_response_code(403);
            echo "Forbidden";
            exit;
        }
        if ($studentId <= 0) {
            $error = "Invalid student.";
        } else {
            try {
                db()->prepare("DELETE FROM teacher_students WHERE teacher_user_id = ? AND student_id = ?")
                    ->execute([(int)($user["user_id"] ?? 0), $studentId]);
                audit_log("teacher_students_remove", "success", "teacher_students", null, "Teacher removed student from class list.", [
                    "student_id" => $studentId,
                ]);
                $_SESSION["flash"] = "Student removed from your class list.";
                header("Location: teacher-overview");
                exit;
            } catch (Throwable $e) {
                $error = "Failed to remove student: " . $e->getMessage();
                audit_log("teacher_students_remove", "failure", "teacher_students", null, "Failed to remove student from class list.", [
                    "student_id" => $studentId,
                    "error" => $e->getMessage(),
                ]);
            }
        }
    } elseif ($action !== "") {
        $error = "Unknown action.";
    }
}

ob_start();
?>
<div class="dg-page-header mb-3">
  <h3 class="mb-0">Class Overview</h3>
  <div class="text-muted small">Simplified student list for academic monitoring</div>
</div>

<?php if ($flash): ?>
  <div class="alert alert-info"><?= htmlspecialchars((string)$flash, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></div>
<?php endif; ?>
<?php if ($error): ?>
  <div class="alert alert-danger"><?= htmlspecialchars((string)$error, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></div>
<?php endif; ?>

<div class="card shadow-sm">
  <div class="card-body">
    <div class="table-responsive">
      <table class="table table-sm dg-table align-middle">
        <thead>
          <tr>
            <th>LRN</th>
            <th>Student</th>
            <th>Grade</th>
            <th>Strand</th>
            <th>Section</th>
            <th class="text-end">GPA</th>
            <th>Academic</th>
            <th class="text-end">Absences</th>
            <th>Risk</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <?php
              $lvl = (string)($r["risk_level"] ?? "Low");
              $chip = $lvl === "High" ? "dg-risk-high" : ($lvl === "Moderate" ? "dg-risk-mod" : "dg-risk-low");
            ?>
            <tr>
              <td class="small text-muted"><?= htmlspecialchars((string)($r["lrn"] ?? ""), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></td>
              <td><?= htmlspecialchars((string)$r["name"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></td>
              <td><?= htmlspecialchars((string)$r["grade_level"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></td>
              <td class="small"><?= !empty($r["strand"]) ? htmlspecialchars((string)$r["strand"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") : "—" ?></td>
              <td class="small"><?= !empty($r["section"]) ? htmlspecialchars(section_display_short((string)$r["section"]), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") : "—" ?></td>
              <td class="text-end"><?= number_format((float)$r["gpa"], 2) ?></td>
              <td>
                <?php $isFailing = ((float)$r["gpa"] < 75.0); ?>
                <span class="dg-academic-chip <?= $isFailing ? "dg-academic-fail" : "dg-academic-pass" ?>">
                  <?= $isFailing ? "Failing" : "Passing" ?>
                </span>
              </td>
              <td class="text-end"><?= (int)$r["absences"] ?></td>
              <td><span class="dg-risk-chip <?= $chip ?>"><span class="dot"></span><?= htmlspecialchars($lvl, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></span></td>
              <td>
                <div class="d-flex flex-wrap gap-1">
                  <button class="btn btn-sm btn-outline-dark" type="button" onclick="window.location.href='teacher-referral?student_id=<?= (int)$r["student_id"] ?>'">Manual Referral</button>
                  <?php if ($isTeacher): ?>
                    <form method="post" action="teacher-overview" onsubmit="return confirm('Remove this student from your class list?');" class="d-inline">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="remove_from_class" />
                      <input type="hidden" name="student_id" value="<?= (int)$r["student_id"] ?>" />
                      <button class="btn btn-sm btn-outline-danger" type="submit">Remove</button>
                    </form>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . "/_layout.php";

