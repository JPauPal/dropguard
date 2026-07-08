<?php
declare(strict_types=1);

require_once __DIR__ . "/../../app/auth.php";
require_once __DIR__ . "/../../app/data_health.php";
require_once __DIR__ . "/../../app/academic_period.php";

require_login();
require_role(["Admin"]);

$scan = data_health_scan();
$summary = $scan["summary"];
$issues = $scan["issues"];

ob_start();
?>
<div class="dg-page-header mb-3">
  <h3 class="mb-0">Data Health</h3>
  <div class="text-muted small">Missing or incomplete records that can weaken ML predictions (Garbage In, Garbage Out).</div>
</div>

<div class="row g-3 mb-4">
  <div class="col-md-4">
    <div class="card shadow-sm h-100">
      <div class="card-body">
        <div class="text-muted small">Active students</div>
        <div class="fs-3 fw-bold"><?= (int)$summary["total_students"] ?></div>
      </div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="card shadow-sm h-100 border-warning">
      <div class="card-body">
        <div class="text-muted small">Students with data gaps</div>
        <div class="fs-3 fw-bold text-warning"><?= (int)$summary["students_with_issues"] ?></div>
      </div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="card shadow-sm h-100">
      <div class="card-body">
        <div class="text-muted small">School year checked</div>
        <div class="fs-5 fw-semibold"><?= htmlspecialchars((string)$summary["active_school_year"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></div>
        <div class="small text-muted"><?= (int)$summary["issue_count"] ?> total issue(s)</div>
      </div>
    </div>
  </div>
</div>

<div class="card shadow-sm">
  <div class="card-body">
    <?php if ($issues === []): ?>
      <div class="alert alert-success mb-0">All active students have baseline attendance, grading, and enrollment data for the active school year.</div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-sm align-middle">
          <thead>
            <tr>
              <th>Student</th>
              <th>Grade</th>
              <th>Section</th>
              <th>Risk</th>
              <th>Data gaps</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($issues as $row): ?>
              <tr>
                <td class="fw-semibold"><?= htmlspecialchars((string)$row["name"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></td>
                <td><?= htmlspecialchars((string)$row["grade_level"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></td>
                <td class="small"><?= htmlspecialchars((string)$row["section"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></td>
                <td><span class="badge text-bg-secondary"><?= htmlspecialchars((string)$row["risk_level"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></span></td>
                <td>
                  <ul class="small mb-0 ps-3">
                    <?php foreach ($row["issues"] as $msg): ?>
                      <li><?= htmlspecialchars($msg, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></li>
                    <?php endforeach; ?>
                  </ul>
                </td>
                <td class="text-end">
                  <a class="btn btn-sm btn-outline-dark" href="student?id=<?= (int)$row["student_id"] ?>">Profile</a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . "/_layout.php";
