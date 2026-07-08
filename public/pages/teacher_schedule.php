<?php
declare(strict_types=1);

require_once __DIR__ . "/../../app/auth.php";
require_once __DIR__ . "/../../app/db.php";
require_once __DIR__ . "/../../app/schedule.php";
require_once __DIR__ . "/../../app/workflow.php";

require_login();
require_role(["Teacher", "Admin"]);
ensure_workflow_tables();
ensure_teacher_schedule_table();

$user = current_user();
$teacherId = (int)($user["user_id"] ?? 0);

start_app_session();
$flash = $_SESSION["flash"] ?? null;
unset($_SESSION["flash"]);
$error = null;
$editId = (int)($_GET["edit"] ?? 0);
$editRow = null;

function dow_label(int $n): string
{
    return match ($n) {
        1 => "Monday",
        2 => "Tuesday",
        3 => "Wednesday",
        4 => "Thursday",
        5 => "Friday",
        6 => "Saturday",
        7 => "Sunday",
        default => "Day",
    };
}

if (($_SERVER["REQUEST_METHOD"] ?? "GET") === "POST") {
    require_valid_csrf_token();
    $action = trim((string)($_POST["action"] ?? "add"));

    if ($action === "delete") {
        $sid = (int)($_POST["schedule_id"] ?? 0);
        if ($sid > 0) {
            $stmt = db()->prepare("DELETE FROM teacher_schedule WHERE schedule_id = ? AND teacher_user_id = ?");
            $stmt->execute([$sid, $teacherId]);
            audit_log("teacher_schedule_delete", "success", "teacher_schedule", $sid, "Deleted teacher schedule block.");
            $_SESSION["flash"] = "Schedule entry removed.";
        }
        header("Location: teacher-schedule");
        exit;
    }

    if ($action === "update") {
        $sid = (int)($_POST["schedule_id"] ?? 0);
        $dow = (int)($_POST["day_of_week"] ?? 1);
        $start = trim((string)($_POST["start_time"] ?? ""));
        $end = trim((string)($_POST["end_time"] ?? ""));
        $sectionLabel = trim((string)($_POST["section_label"] ?? ""));
        $subject = trim((string)($_POST["subject"] ?? ""));
        $room = trim((string)($_POST["room"] ?? ""));
        $note = trim((string)($_POST["note"] ?? ""));

        if ($sid <= 0) {
            $error = "Invalid schedule entry.";
        } elseif ($dow < 1 || $dow > 7) {
            $error = "Please select a valid day.";
        } elseif (!preg_match('/^\d{2}:\d{2}$/', $start) || !preg_match('/^\d{2}:\d{2}$/', $end)) {
            $error = "Start/End time must be HH:MM.";
        } elseif (strtotime($end) <= strtotime($start)) {
            $error = "End time must be later than start time.";
        } else {
            $stmt = db()->prepare(
                "UPDATE teacher_schedule
                 SET day_of_week = ?, start_time = ?, end_time = ?, section_label = ?, subject = ?, room = ?, note = ?
                 WHERE schedule_id = ? AND teacher_user_id = ?"
            );
            $stmt->execute([
                $dow,
                $start . ":00",
                $end . ":00",
                $sectionLabel !== "" ? $sectionLabel : null,
                $subject !== "" ? $subject : null,
                $room !== "" ? $room : null,
                $note !== "" ? $note : null,
                $sid,
                $teacherId,
            ]);
            audit_log("teacher_schedule_update", "success", "teacher_schedule", $sid, "Updated teacher schedule block.", [
                "day_of_week" => $dow,
                "start_time" => $start,
                "end_time" => $end,
            ]);
            $_SESSION["flash"] = "Schedule updated.";
            header("Location: teacher-schedule");
            exit;
        }
    }

    $dow = (int)($_POST["day_of_week"] ?? 1);
    $start = trim((string)($_POST["start_time"] ?? ""));
    $end = trim((string)($_POST["end_time"] ?? ""));
    $sectionLabel = trim((string)($_POST["section_label"] ?? ""));
    $subject = trim((string)($_POST["subject"] ?? ""));
    $room = trim((string)($_POST["room"] ?? ""));
    $note = trim((string)($_POST["note"] ?? ""));

    if ($dow < 1 || $dow > 7) {
        $error = "Please select a valid day.";
    } elseif (!preg_match('/^\d{2}:\d{2}$/', $start) || !preg_match('/^\d{2}:\d{2}$/', $end)) {
        $error = "Start/End time must be HH:MM.";
    } elseif (strtotime($end) <= strtotime($start)) {
        $error = "End time must be later than start time.";
    } else {
        $stmt = db()->prepare(
            "INSERT INTO teacher_schedule (teacher_user_id, day_of_week, start_time, end_time, section_label, subject, room, note)
             VALUES (?,?,?,?,?,?,?,?)"
        );
        $stmt->execute([
            $teacherId,
            $dow,
            $start . ":00",
            $end . ":00",
            $sectionLabel !== "" ? $sectionLabel : null,
            $subject !== "" ? $subject : null,
            $room !== "" ? $room : null,
            $note !== "" ? $note : null,
        ]);

        $newId = (int)db()->lastInsertId();
        audit_log("teacher_schedule_add", "success", "teacher_schedule", $newId, "Added teacher schedule block.", [
            "day_of_week" => $dow,
            "start_time" => $start,
            "end_time" => $end,
        ]);
        $_SESSION["flash"] = "Schedule saved.";
        header("Location: teacher-schedule");
        exit;
    }
}

$rows = get_teacher_schedule($teacherId);
if ($editId > 0) {
    foreach ($rows as $r) {
        if ((int)($r["schedule_id"] ?? 0) === $editId) {
            $editRow = $r;
            break;
        }
    }
    if (!$editRow) {
        $editId = 0;
    }
}

// Group by day for display.
$byDay = [];
foreach ($rows as $r) {
    $d = (int)($r["day_of_week"] ?? 1);
    if (!isset($byDay[$d])) $byDay[$d] = [];
    $byDay[$d][] = $r;
}

ob_start();
?>
<div class="dg-page-header mb-3">
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
    <div>
      <h3 class="mb-0">Teacher Schedule</h3>
      <div class="text-muted small">Set when you are in-class / out-of-class (e.g., Thu/Fri classes)</div>
    </div>
  </div>
</div>

<?php if ($flash): ?>
  <div class="alert alert-info"><?= htmlspecialchars((string)$flash, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></div>
<?php endif; ?>
<?php if ($error): ?>
  <div class="alert alert-danger"><?= htmlspecialchars((string)$error, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-lg-4">
    <div class="card shadow-sm">
      <div class="card-body">
        <div class="fw-semibold mb-2"><?= $editRow ? "Edit Class Block" : "Add Class Block" ?></div>
        <form method="post" action="teacher-schedule" class="d-grid gap-2">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="<?= $editRow ? "update" : "add" ?>" />
          <?php if ($editRow): ?>
            <input type="hidden" name="schedule_id" value="<?= (int)$editRow["schedule_id"] ?>" />
          <?php endif; ?>

          <div>
            <label class="form-label mb-1">Day</label>
            <select class="form-select" name="day_of_week" required>
              <?php for ($d = 1; $d <= 7; $d++): ?>
                <option value="<?= $d ?>" <?= $editRow && (int)$editRow["day_of_week"] === $d ? "selected" : "" ?>>
                  <?= htmlspecialchars(dow_label($d), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>
                </option>
              <?php endfor; ?>
            </select>
          </div>

          <div class="row g-2">
            <div class="col-6">
              <label class="form-label mb-1">Start</label>
              <input class="form-control" type="time" name="start_time" value="<?= $editRow ? htmlspecialchars(substr((string)$editRow["start_time"], 0, 5), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") : "" ?>" required />
            </div>
            <div class="col-6">
              <label class="form-label mb-1">End</label>
              <input class="form-control" type="time" name="end_time" value="<?= $editRow ? htmlspecialchars(substr((string)$editRow["end_time"], 0, 5), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") : "" ?>" required />
            </div>
          </div>

          <div>
            <label class="form-label mb-1">Section (optional)</label>
            <input class="form-control" name="section_label" placeholder="e.g., Grade 9 – Section A" value="<?= $editRow ? htmlspecialchars((string)($editRow["section_label"] ?? ""), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") : "" ?>" />
          </div>
          <div>
            <label class="form-label mb-1">Subject (optional)</label>
            <input class="form-control" name="subject" placeholder="e.g., Mathematics" value="<?= $editRow ? htmlspecialchars((string)($editRow["subject"] ?? ""), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") : "" ?>" />
          </div>
          <div>
            <label class="form-label mb-1">Room (optional)</label>
            <input class="form-control" name="room" placeholder="e.g., Room 204" value="<?= $editRow ? htmlspecialchars((string)($editRow["room"] ?? ""), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") : "" ?>" />
          </div>
          <div>
            <label class="form-label mb-1">Note (optional)</label>
            <input class="form-control" name="note" placeholder="e.g., Homeroom / Club / Advisory" value="<?= $editRow ? htmlspecialchars((string)($editRow["note"] ?? ""), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") : "" ?>" />
          </div>

          <div class="d-flex gap-2">
            <button class="btn btn-dark flex-fill" type="submit"><?= $editRow ? "Save Changes" : "Save Block" ?></button>
            <?php if ($editRow): ?>
              <a class="btn btn-outline-secondary" href="teacher-schedule">Cancel</a>
            <?php endif; ?>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="col-lg-8">
    <div class="card shadow-sm">
      <div class="card-body">
        <div class="fw-semibold mb-2">Weekly View</div>
        <?php if (!$rows): ?>
          <div class="text-muted small">No schedule blocks yet. Add your Thursday/Friday classes on the left.</div>
        <?php else: ?>
          <div class="row g-2">
            <?php for ($d = 1; $d <= 7; $d++): ?>
              <div class="col-md-6 col-xl-4">
                <div class="border rounded-3 p-2 bg-white h-100">
                  <div class="fw-semibold mb-2"><?= htmlspecialchars(dow_label($d), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></div>
                  <?php $items = $byDay[$d] ?? []; ?>
                  <?php if (!$items): ?>
                    <div class="text-muted small">No classes</div>
                  <?php else: ?>
                    <div class="d-grid gap-2">
                      <?php foreach ($items as $it): ?>
                        <div class="p-2 rounded-3" style="border:1px solid rgba(11,93,30,.12); background:rgba(11,93,30,.04);">
                          <div class="d-flex justify-content-between align-items-start gap-2">
                            <div>
                              <div class="fw-semibold">
                                <?= htmlspecialchars(substr((string)$it["start_time"], 0, 5) . "–" . substr((string)$it["end_time"], 0, 5), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>
                              </div>
                              <div class="text-muted small">
                                <?= htmlspecialchars((string)($it["section_label"] ?? ""), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>
                                <?= !empty($it["subject"]) ? " • " . htmlspecialchars((string)$it["subject"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") : "" ?>
                                <?= !empty($it["room"]) ? " • " . htmlspecialchars((string)$it["room"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") : "" ?>
                              </div>
                              <?php if (!empty($it["note"])): ?>
                                <div class="text-muted small"><?= htmlspecialchars((string)$it["note"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></div>
                              <?php endif; ?>
                            </div>
                            <div class="d-flex flex-column gap-1">
                              <a class="btn btn-sm btn-outline-secondary" href="teacher-schedule?edit=<?= (int)$it["schedule_id"] ?>">Edit</a>
                              <form method="post" action="teacher-schedule">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete" />
                                <input type="hidden" name="schedule_id" value="<?= (int)$it["schedule_id"] ?>" />
                                <button class="btn btn-sm btn-outline-danger w-100" type="submit">Remove</button>
                              </form>
                            </div>
                          </div>
                        </div>
                      <?php endforeach; ?>
                    </div>
                  <?php endif; ?>
                </div>
              </div>
            <?php endfor; ?>
          </div>
          <div class="text-muted small mt-3">
            Tip: Add only the days you teach. Days without blocks are considered “out of class”.
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php
$content = ob_get_clean();
require __DIR__ . "/_layout.php";

