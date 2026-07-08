<?php
declare(strict_types=1);

require_once __DIR__ . "/../../app/auth.php";
require_once __DIR__ . "/../../app/db.php";

require_login();
require_role(["Admin"]);

function audit_status_row_class(string $status): string
{
    $s = strtolower(trim($status));
    return match ($s) {
        "success" => "dg-row-low",
        "failure" => "dg-row-high",
        "info" => "dg-row-mod",
        default => "",
    };
}

function audit_action_kind(string $action): string
{
    $a = strtolower(trim($action));
    if ($a === "") return "other";
    if (str_contains($a, "login") || str_contains($a, "logout") || str_contains($a, "access_denied") || str_contains($a, "password")) return "security";
    if (str_ends_with($a, "_add") || str_contains($a, "_create") || str_contains($a, "_assign")) return "add";
    if (str_ends_with($a, "_update") || str_contains($a, "_save") || str_contains($a, "_finalize") || str_contains($a, "_toggle") || str_contains($a, "status_update")) return "update";
    if (str_ends_with($a, "_delete") || str_contains($a, "_remove") || str_contains($a, "_deactivate") || str_contains($a, "_reject")) return "delete";
    return "other";
}

$auditKindLabels = [
    "add" => "Add",
    "update" => "Update",
    "delete" => "Delete",
    "security" => "Security",
    "other" => "Other",
];

$actionLabels = [
    "login_success" => "Login Success",
    "login_attempt" => "Login Failed",
    "login_rate_limited" => "Login Rate Limited",
    "logout" => "Logout",
    "access_denied" => "Access Denied",
    "user_create" => "User Created",
    "user_delete" => "User Deleted",
    "user_password_update" => "User Password Updated",
    "student_delete" => "Student Deleted",
    "student_update" => "Student Updated (Admin)",
    "user_toggle_active" => "User Activated/Deactivated",
    "student_data_save" => "Data Entry Saved",
    "password_change_request" => "Password Change Requested",
    "password_change_request_approved" => "Password Request Approved",
    "password_change_request_rejected" => "Password Request Rejected",
    "attendance_calendar_save" => "Calendar Attendance Saved",
    "attendance_sheet_save" => "Attendance Sheet Saved",
    "attendance_update" => "Attendance Updated (Overview)",
    "grading_sheet_save" => "Grading Sheet Saved",
    "grading_sheet_finalize" => "Grading Sheet Finalized",
    "teacher_schedule_add" => "Teacher Schedule Added",
    "teacher_schedule_update" => "Teacher Schedule Updated",
    "teacher_schedule_delete" => "Teacher Schedule Deleted",
    "section_schedule_add" => "Section Schedule Added",
    "section_schedule_update" => "Section Schedule Updated",
    "section_schedule_delete" => "Section Schedule Deleted",
    "subject_save" => "Subject Saved",
    "subject_deactivate" => "Subject Deactivated",
    "section_subject_assign" => "Subject Assigned to Section",
    "section_subject_remove" => "Subject Removed from Section",
    "section_add" => "Section Added",
    "section_delete" => "Section Deleted",
    "bulk_import" => "Bulk Import",
    "ml_run" => "ML Analysis Run",
    "critical_ml_failure" => "ML Python Failure (PHP Fallback Used)",
    "student_view" => "Student Profile Viewed",
    "student_modal_view" => "Student Modal Viewed",
    "student_mark" => "Student Flagged",
    "flag_add" => "Flag Added",
    "flag_resolve" => "Flag Resolved",
    "intervention_add" => "Intervention Note Added",
    "case_status_update" => "Case Status Updated",
    "manual_referral_create" => "Manual Referral Submitted",
    "teacher_students_import" => "Teacher Added Students (Class List)",
    "teacher_students_remove" => "Teacher Removed Student (Class List)",
    "what_if_simulation" => "What-If Simulation",
    "settings_update" => "Settings Updated",
];

$action = trim((string)($_GET["action"] ?? ""));
$status = trim((string)($_GET["status"] ?? ""));
$search = trim((string)($_GET["search"] ?? ""));

$clauses = [];
$params = [];
if ($action !== "") {
    $clauses[] = "a.action = ?";
    $params[] = $action;
}
if ($status !== "") {
    $clauses[] = "a.status = ?";
    $params[] = $status;
}
if ($search !== "") {
    if (db_field_encryption_enabled()) {
        // Encrypted description/details cannot be searched in SQL; match username and action only.
        $clauses[] = "(u.username LIKE ? OR a.action LIKE ?)";
        $params[] = "%" . $search . "%";
        $params[] = "%" . $search . "%";
    } else {
        $clauses[] = "(u.username LIKE ? OR a.description LIKE ? OR a.details_json LIKE ?)";
        $params[] = "%" . $search . "%";
        $params[] = "%" . $search . "%";
        $params[] = "%" . $search . "%";
    }
}
$where = $clauses ? ("WHERE " . implode(" AND ", $clauses)) : "";

$stmt = db()->prepare(
    "SELECT a.audit_id, a.user_id, a.action, a.status, a.target_type, a.target_id, a.description, a.details_json, a.ip_address, a.created_at,
            u.username, u.full_name
     FROM audit_logs a
     LEFT JOIN users u ON u.user_id = a.user_id
     {$where}
     ORDER BY a.audit_id DESC
     LIMIT 500"
);
$stmt->execute($params);
$rows = $stmt->fetchAll();
foreach ($rows as &$ar) {
    $ar = db_decrypt_user_pii_row($ar);
    if (isset($ar["description"]) && $ar["description"] !== null && $ar["description"] !== "") {
        $ar["description"] = db_field_decrypt((string)$ar["description"]);
    }
    if (isset($ar["details_json"]) && $ar["details_json"] !== null && $ar["details_json"] !== "") {
        $ar["details_json"] = db_field_decrypt((string)$ar["details_json"]);
    }
}
unset($ar);

$actions = db()->query("SELECT DISTINCT action FROM audit_logs ORDER BY action ASC")->fetchAll();

ob_start();
?>
<div class="dg-page-header mb-3">
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
    <div>
      <h3 class="mb-0">Audit Logs</h3>
      <div class="text-muted small">Admin activity and security event history</div>
    </div>
  </div>
</div>

<div class="card shadow-sm mb-3">
  <div class="card-body">
    <form class="row g-2" method="get" action="audit-logs">
      <div class="col-md-3">
        <label class="form-label mb-1">Action</label>
        <select class="form-select" name="action">
          <option value="">All</option>
          <?php foreach ($actions as $a): ?>
            <?php $val = (string)($a["action"] ?? ""); $label = $actionLabels[$val] ?? ucwords(str_replace("_", " ", $val)); ?>
            <option value="<?= htmlspecialchars($val, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" <?= $val === $action ? "selected" : "" ?>>
              <?= htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label mb-1">Status</label>
        <select class="form-select" name="status">
          <option value="">All</option>
          <option value="success" <?= $status === "success" ? "selected" : "" ?>>Success</option>
          <option value="failure" <?= $status === "failure" ? "selected" : "" ?>>Failure</option>
          <option value="info" <?= $status === "info" ? "selected" : "" ?>>Info</option>
        </select>
      </div>
      <div class="col-md-5">
        <label class="form-label mb-1">Search</label>
        <input class="form-control" name="search" value="<?= htmlspecialchars($search, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" placeholder="<?= db_field_encryption_enabled() ? "username or action (encrypted fields are not searchable)" : "username, description, or details..." ?>" />
      </div>
      <div class="col-md-2 d-flex align-items-end">
        <button class="btn btn-dark w-100" type="submit">Apply</button>
      </div>
    </form>
  </div>
</div>

<div class="card shadow-sm">
  <div class="card-body">
    <div class="table-responsive">
      <table class="table table-sm align-middle dg-table">
        <thead>
          <tr>
            <th>ID</th>
            <th>When</th>
            <th>User</th>
            <th>Action</th>
            <th>Status</th>
            <th>Target</th>
            <th>Description</th>
            <th>IP</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <?php
              $pill = "secondary";
              if ($r["status"] === "success") $pill = "success";
              if ($r["status"] === "failure") $pill = "danger";
              if ($r["status"] === "info") $pill = "primary";
              $actionKey = (string)($r["action"] ?? "");
              $actionKind = audit_action_kind($actionKey);
              $rowClass = audit_status_row_class((string)($r["status"] ?? ""));
            ?>
            <tr class="<?= htmlspecialchars($rowClass, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>">
              <td><?= (int)$r["audit_id"] ?></td>
              <td class="text-muted small"><?= htmlspecialchars((string)$r["created_at"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></td>
              <td>
                <div class="fw-semibold"><?= htmlspecialchars((string)($r["username"] ?? "system"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></div>
                <div class="text-muted small"><?= htmlspecialchars((string)($r["full_name"] ?? ""), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></div>
              </td>
              <?php $actionLabel = $actionLabels[$actionKey] ?? ucwords(str_replace("_", " ", $actionKey)); ?>
              <td>
                <div class="d-flex flex-wrap align-items-center gap-1">
                  <span class="badge dg-audit-kind dg-audit-kind-<?= htmlspecialchars($actionKind, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>">
                    <?= htmlspecialchars((string)($auditKindLabels[$actionKind] ?? "Other"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>
                  </span>
                  <span class="fw-semibold small"><?= htmlspecialchars($actionLabel, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></span>
                </div>
              </td>
              <td><span class="badge text-bg-<?= $pill ?>"><?= htmlspecialchars((string)$r["status"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></span></td>
              <?php $tt = (string)($r["target_type"] ?? ""); $ttLabel = ucwords(str_replace("_", " ", $tt)); ?>
              <td class="small">
                <?= htmlspecialchars($ttLabel ?: "-", ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>
                <?php if (!empty($r["target_id"])): ?>
                  #<?= (int)$r["target_id"] ?>
                <?php endif; ?>
              </td>
              <td class="small">
                <?= htmlspecialchars((string)($r["description"] ?? ""), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>
                <?php if (!empty($r["details_json"])): ?>
                  <?php $dj = json_decode((string)$r["details_json"], true); ?>
                  <?php if (is_array($dj) && $dj): ?>
                    <details class="mt-1">
                      <summary class="small text-muted">details</summary>
                      <table class="table table-sm table-borderless mb-0 small">
                        <?php foreach ($dj as $dk => $dv): ?>
                          <?php if ($dv === null || $dv === "") continue; ?>
                          <?php
                            if (is_string($dv)) {
                                $dv = db_field_decrypt($dv);
                            }
                          ?>
                          <tr>
                            <td class="text-muted pe-2" style="white-space:nowrap"><?= htmlspecialchars(ucwords(str_replace("_", " ", (string)$dk)), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></td>
                            <td><?= htmlspecialchars(is_array($dv) ? json_encode($dv) : (string)$dv, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></td>
                          </tr>
                        <?php endforeach; ?>
                      </table>
                    </details>
                  <?php endif; ?>
                <?php endif; ?>
              </td>
              <td class="small text-muted"><?= htmlspecialchars((string)($r["ip_address"] ?? ""), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></td>
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

