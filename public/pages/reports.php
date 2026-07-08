<?php
declare(strict_types=1);

require_once __DIR__ . "/../../app/auth.php";
require_once __DIR__ . "/../../app/db.php";
require_once __DIR__ . "/../../app/workflow.php";
require_once __DIR__ . "/../../app/sections.php";

require_login();
require_role(["Counselor", "Admin"]);
ensure_workflow_tables();

$from = trim((string)($_GET["from"] ?? date("Y-m-01")));
$to = trim((string)($_GET["to"] ?? date("Y-m-d")));
$format = trim((string)($_GET["format"] ?? ""));

$stmt = db()->prepare(
    "SELECT i.intervention_id, i.created_at, s.name AS student_name, s.grade_level, s.strand, s.section, u.full_name AS counselor_name, i.note
     FROM interventions i
     INNER JOIN students s ON s.student_id = i.student_id
     INNER JOIN users u ON u.user_id = i.created_by
     WHERE DATE(i.created_at) BETWEEN ? AND ?
     ORDER BY i.created_at DESC"
);
$stmt->execute([$from, $to]);
$rows = $stmt->fetchAll();
foreach ($rows as &$repRow) {
    if (isset($repRow["note"]) && $repRow["note"] !== "") {
        $repRow["note"] = db_field_decrypt((string)$repRow["note"]);
    }
    if (isset($repRow["counselor_name"]) && $repRow["counselor_name"] !== "") {
        $repRow["counselor_name"] = db_field_decrypt((string)$repRow["counselor_name"]);
    }
}
unset($repRow);

if ($format === "csv") {
    header("Content-Type: text/csv; charset=utf-8");
    header("Content-Disposition: attachment; filename=interventions_{$from}_to_{$to}.csv");
    $out = fopen("php://output", "w");
    fputcsv($out, ["Date", "Student", "Grade Level", "Strand", "Section", "Counselor", "Action/Note"]);
    foreach ($rows as $r) {
        $sec = trim((string)($r["section"] ?? ""));
        fputcsv($out, [
            (string)$r["created_at"],
            (string)$r["student_name"],
            (string)$r["grade_level"],
            (string)($r["strand"] ?? ""),
            $sec !== "" ? section_display_short($sec) : "",
            (string)$r["counselor_name"],
            (string)$r["note"],
        ]);
    }
    fclose($out);
    exit;
}

ob_start();
?>
<div class="dg-page-header mb-3">
  <h3 class="mb-0">Intervention Reports</h3>
  <div class="text-muted small">Monthly review export for Principal</div>
</div>

<div class="card shadow-sm mb-3">
  <div class="card-body">
    <form class="row g-2" method="get" action="reports">
      <div class="col-md-3">
        <label class="form-label mb-1">From</label>
        <input class="form-control" type="date" name="from" value="<?= htmlspecialchars($from, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" />
      </div>
      <div class="col-md-3">
        <label class="form-label mb-1">To</label>
        <input class="form-control" type="date" name="to" value="<?= htmlspecialchars($to, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" />
      </div>
      <div class="col-md-2 d-flex align-items-end">
        <button class="btn btn-dark w-100" type="submit">Apply</button>
      </div>
      <div class="col-md-2 d-flex align-items-end">
        <a class="btn btn-outline-secondary w-100" href="reports?from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>&format=csv">Export CSV</a>
      </div>
      <div class="col-md-2 d-flex align-items-end">
        <button type="button" class="btn btn-outline-secondary w-100" onclick="window.print()">Print/PDF</button>
      </div>
    </form>
  </div>
</div>

<div class="card shadow-sm">
  <div class="card-body">
    <div class="fw-semibold mb-2">Interventions (<?= count($rows) ?>)</div>
    <div class="table-responsive">
      <table class="table table-sm dg-table align-middle">
        <thead>
          <tr>
            <th>Date</th>
            <th>Student</th>
            <th>Grade</th>
            <th>Strand</th>
            <th>Section</th>
            <th>Counselor</th>
            <th>Action/Note</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td class="small text-muted"><?= htmlspecialchars((string)$r["created_at"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></td>
              <td><?= htmlspecialchars((string)$r["student_name"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></td>
              <td><?= htmlspecialchars((string)$r["grade_level"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></td>
              <td class="small"><?= !empty($r["strand"]) ? htmlspecialchars((string)$r["strand"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") : "—" ?></td>
              <td class="small"><?= !empty($r["section"]) ? htmlspecialchars(section_display_short((string)$r["section"]), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") : "—" ?></td>
              <td><?= htmlspecialchars((string)$r["counselor_name"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></td>
              <td><?= nl2br(htmlspecialchars((string)$r["note"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8")) ?></td>
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

