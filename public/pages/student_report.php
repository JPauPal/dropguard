<?php
declare(strict_types=1);

require_once __DIR__ . "/../../app/auth.php";
require_once __DIR__ . "/../../app/db.php";
require_once __DIR__ . "/../../app/ml.php";
require_once __DIR__ . "/../../app/marks.php";
require_once __DIR__ . "/../../app/workflow.php";
require_once __DIR__ . "/../../app/sections.php";
require_once __DIR__ . "/../../app/grading.php";

require_login();
require_role(["Counselor", "Admin", "Teacher"]);
ensure_workflow_tables();
ensure_grading_components_table();
ml_ensure_risk_factors_schema();

$studentId = (int)($_GET["id"] ?? 0);
if ($studentId <= 0) {
    http_response_code(400);
    echo "Missing student id.";
    exit;
}

$user = current_user();
$role = (string)($user["role"] ?? "");

$stmt = db()->prepare(
    "SELECT student_id, lrn, name, grade_level, strand, section, gpa, absences,
            risk_score, risk_level, risk_factors_json
     FROM students WHERE student_id = ? AND COALESCE(is_archived,0) = 0"
);
$stmt->execute([$studentId]);
$student = $stmt->fetch();
if (!$student) {
    http_response_code(404);
    echo "Student not found.";
    exit;
}

if ($role === "Teacher") {
    $chk = db()->prepare("SELECT 1 FROM teacher_students WHERE teacher_user_id = ? AND student_id = ? LIMIT 1");
    $chk->execute([(int)($user["user_id"] ?? 0), $studentId]);
    if (!$chk->fetchColumn()) {
        http_response_code(403);
        echo "Forbidden.";
        exit;
    }
}

$perf = db()->prepare(
    "SELECT quarter, gpa, days_present, total_school_days, absences, consecutive_absences, failing_subjects
     FROM performance WHERE student_id = ? ORDER BY quarter DESC LIMIT 1"
);
$perf->execute([$studentId]);
$latestPerf = $perf->fetch() ?: [];

$notes = db()->prepare(
    "SELECT i.note, i.created_at, u.full_name
     FROM interventions i
     INNER JOIN users u ON u.user_id = i.created_by
     WHERE i.student_id = ?
     ORDER BY i.created_at DESC LIMIT 8"
);
$notes->execute([$studentId]);
$interventions = $notes->fetchAll();
foreach ($interventions as &$n) {
    $n = db_decrypt_user_pii_row($n);
    if (!empty($n["note"])) {
        $n["note"] = db_field_decrypt((string)$n["note"]);
    }
}
unset($n);

$flags = get_student_flags($studentId, true);
$riskFactors = ml_parse_risk_factors($student["risk_factors_json"] ?? null);
$generatedAt = date("F j, Y g:i A");
$config = app_config();
$appName = (string)($config["app"]["name"] ?? "Drop Guard");

?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <title><?= htmlspecialchars($appName, ENT_QUOTES, "UTF-8") ?> — Student Report</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body { font-size: 11pt; color: #111; }
    .dg-report-header { border-bottom: 2px solid #0b5d1e; padding-bottom: 12px; margin-bottom: 20px; }
    .dg-report-section { margin-bottom: 18px; }
    .dg-risk-high { color: #b02a37; font-weight: 700; }
    .dg-risk-mod { color: #997404; font-weight: 700; }
    .dg-risk-low { color: #146c43; font-weight: 700; }
    @media print {
      .no-print { display: none !important; }
      body { padding: 0; }
    }
  </style>
</head>
<body class="p-4">
  <div class="no-print mb-3 d-flex gap-2">
    <button class="btn btn-dark btn-sm" type="button" onclick="window.print()">Print / Save as PDF</button>
    <a class="btn btn-outline-secondary btn-sm" href="student?id=<?= $studentId ?>">Back to profile</a>
  </div>

  <div class="dg-report-header">
    <div class="text-muted small"><?= htmlspecialchars($appName, ENT_QUOTES, "UTF-8") ?> — Early Warning Student Report</div>
    <h1 class="h4 mb-1"><?= htmlspecialchars((string)$student["name"], ENT_QUOTES, "UTF-8") ?></h1>
    <div class="small">
      <?= htmlspecialchars((string)$student["grade_level"], ENT_QUOTES, "UTF-8") ?>
      <?php if (!empty($student["section"])): ?> • <?= htmlspecialchars(section_display_short((string)$student["section"]), ENT_QUOTES, "UTF-8") ?><?php endif; ?>
      <?php if (!empty($student["lrn"])): ?> • LRN <?= htmlspecialchars((string)$student["lrn"], ENT_QUOTES, "UTF-8") ?><?php endif; ?>
    </div>
    <div class="small text-muted">Generated <?= htmlspecialchars($generatedAt, ENT_QUOTES, "UTF-8") ?></div>
  </div>

  <div class="row dg-report-section">
    <div class="col-md-6">
      <h2 class="h6 text-uppercase text-muted">Risk Summary</h2>
      <?php
        $lvl = (string)($student["risk_level"] ?? "Low");
        $lvlClass = $lvl === "High" ? "dg-risk-high" : ($lvl === "Moderate" ? "dg-risk-mod" : "dg-risk-low");
      ?>
      <p class="mb-1">Level: <span class="<?= $lvlClass ?>"><?= htmlspecialchars($lvl, ENT_QUOTES, "UTF-8") ?></span></p>
      <p class="mb-1">Score: <strong><?= number_format((float)($student["risk_score"] ?? 0), 4) ?></strong></p>
      <p class="mb-1">GPA: <strong><?= number_format((float)($student["gpa"] ?? 0), 2) ?></strong></p>
      <p class="mb-0">Absences: <strong><?= (int)($student["absences"] ?? 0) ?></strong></p>
    </div>
    <div class="col-md-6">
      <h2 class="h6 text-uppercase text-muted">Latest Quarter Metrics</h2>
      <?php if ($latestPerf): ?>
        <p class="mb-1">Quarter: Q<?= (int)($latestPerf["quarter"] ?? 0) ?></p>
        <p class="mb-1">Attendance: <?= (int)($latestPerf["days_present"] ?? 0) ?> / <?= (int)($latestPerf["total_school_days"] ?? 0) ?> days</p>
        <p class="mb-1">Failing subjects: <?= (int)($latestPerf["failing_subjects"] ?? 0) ?></p>
        <p class="mb-0">Consecutive absences: <?= (int)($latestPerf["consecutive_absences"] ?? 0) ?></p>
      <?php else: ?>
        <p class="text-muted mb-0">No performance data synced.</p>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($riskFactors !== []): ?>
    <div class="dg-report-section">
      <h2 class="h6 text-uppercase text-muted">Risk Signals</h2>
      <ul class="mb-0">
        <?php foreach ($riskFactors as $rf): ?>
          <li><?= htmlspecialchars($rf, ENT_QUOTES, "UTF-8") ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <?php if ($flags !== []): ?>
    <div class="dg-report-section">
      <h2 class="h6 text-uppercase text-muted">Active Flags</h2>
      <ul class="mb-0">
        <?php foreach ($flags as $fl): ?>
          <li><?= htmlspecialchars((string)($fl["issue_type"] ?? ""), ENT_QUOTES, "UTF-8") ?> (<?= htmlspecialchars((string)($fl["severity"] ?? ""), ENT_QUOTES, "UTF-8") ?>)</li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <div class="dg-report-section">
    <h2 class="h6 text-uppercase text-muted">Intervention History</h2>
    <?php if ($interventions === []): ?>
      <p class="text-muted mb-0">No intervention notes recorded.</p>
    <?php else: ?>
      <table class="table table-sm table-bordered">
        <thead><tr><th>Date</th><th>Counselor</th><th>Note</th></tr></thead>
        <tbody>
          <?php foreach ($interventions as $iv): ?>
            <tr>
              <td class="small text-nowrap"><?= htmlspecialchars((string)$iv["created_at"], ENT_QUOTES, "UTF-8") ?></td>
              <td class="small"><?= htmlspecialchars((string)($iv["full_name"] ?? ""), ENT_QUOTES, "UTF-8") ?></td>
              <td><?= nl2br(htmlspecialchars((string)($iv["note"] ?? ""), ENT_QUOTES, "UTF-8")) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <p class="small text-muted mt-4 mb-0">Decision support only — final retention and intervention decisions remain with school counselors and administrators.</p>
</body>
</html>
