<?php
declare(strict_types=1);

require_once __DIR__ . "/../../app/auth.php";
require_once __DIR__ . "/../../app/db.php";
require_once __DIR__ . "/../../app/sections.php";
require_once __DIR__ . "/../../app/section_schedule.php";
require_once __DIR__ . "/../../app/workflow.php";

require_login();
require_role(["Teacher", "Admin"]);
ensure_workflow_tables();
ensure_student_sections();
ensure_section_schedule_table();

$user = current_user();
$teacherId = (int)($user["user_id"] ?? 0);
$isAdmin = (string)($user["role"] ?? "") === "Admin";

$teacherUsers = [];
if ($isAdmin) {
    try {
        $teacherUsers = db_decrypt_user_pii_rows(db()->query(
            "SELECT user_id, full_name, username FROM users WHERE role = 'Teacher' ORDER BY username ASC"
        )->fetchAll());
    } catch (Throwable) {
        $teacherUsers = [];
    }
}

function teacher_label_from_users(int $teacherUserId, array $teacherUsers): string
{
    if ($teacherUserId <= 0) return "";
    foreach ($teacherUsers as $tu) {
        if ((int)($tu["user_id"] ?? 0) === $teacherUserId) {
            $label = (string)($tu["full_name"] ?? "");
            if ($label !== "") return $label;
            $label = (string)($tu["username"] ?? "");
            if ($label !== "") return $label;
            return "User " . (string)$teacherUserId;
        }
    }
    return "User " . (string)$teacherUserId;
}

start_app_session();
$flash = $_SESSION["flash"] ?? null;
unset($_SESSION["flash"]);
$error = null;

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

$sections = list_sections(null);
$selectedSection = trim((string)($_GET["section"] ?? ($sections[0] ?? "")));
$editId = (int)($_GET["edit"] ?? 0);

if (($_SERVER["REQUEST_METHOD"] ?? "GET") === "POST") {
    require_valid_csrf_token();
    $action = trim((string)($_POST["action"] ?? "add"));
    $selectedSection = trim((string)($_POST["section"] ?? $selectedSection));

    if ($action === "delete") {
        $sid = (int)($_POST["section_schedule_id"] ?? 0);
        if ($sid > 0) {
            $stmt = db()->prepare("DELETE FROM section_schedule WHERE section_schedule_id = ?");
            $stmt->execute([$sid]);
            audit_log("section_schedule_delete", "success", "section_schedule", $sid, "Deleted section schedule block.");
            $_SESSION["flash"] = "Section schedule entry removed.";
        }
        header("Location: section-schedule?section=" . urlencode($selectedSection));
        exit;
    }

    $dow = (int)($_POST["day_of_week"] ?? 1);
    $start = trim((string)($_POST["start_time"] ?? ""));
    $end = trim((string)($_POST["end_time"] ?? ""));
    $subject = trim((string)($_POST["subject"] ?? ""));
    $room = trim((string)($_POST["room"] ?? ""));
    $note = trim((string)($_POST["note"] ?? ""));
    $teacher = null;
    if ($isAdmin) {
        $teacherPosted = (int)($_POST["teacher_user_id"] ?? 0);
        $teacher = $teacherPosted > 0 ? $teacherPosted : null;
    } else {
        $teacher = (string)($_POST["use_me"] ?? "") === "1" ? $teacherId : null;
    }

    if ($selectedSection === "") {
        $error = "Please select a section.";
    } elseif ($dow < 1 || $dow > 7) {
        $error = "Please select a valid day.";
    } elseif (!preg_match('/^\d{2}:\d{2}$/', $start) || !preg_match('/^\d{2}:\d{2}$/', $end)) {
        $error = "Start/End time must be HH:MM.";
    } elseif (strtotime($end) <= strtotime($start)) {
        $error = "End time must be later than start time.";
    } else {
        if ($action === "update") {
            $ssid = (int)($_POST["section_schedule_id"] ?? 0);
            if ($ssid <= 0) {
                $error = "Invalid schedule entry.";
            } else {
                $stmt = db()->prepare(
                    "UPDATE section_schedule
                     SET section = ?, day_of_week = ?, start_time = ?, end_time = ?, subject = ?, room = ?, teacher_user_id = ?, note = ?
                     WHERE section_schedule_id = ?"
                );
                $stmt->execute([
                    $selectedSection,
                    $dow,
                    $start . ":00",
                    $end . ":00",
                    $subject !== "" ? $subject : null,
                    $room !== "" ? $room : null,
                    $teacher,
                    $note !== "" ? $note : null,
                    $ssid,
                ]);
                audit_log("section_schedule_update", "success", "section_schedule", $ssid, "Updated section schedule block.", [
                    "section" => $selectedSection,
                    "day_of_week" => $dow,
                ]);
                $_SESSION["flash"] = "Section schedule updated.";
                header("Location: section-schedule?section=" . urlencode($selectedSection));
                exit;
            }
        } else {
            $stmt = db()->prepare(
                "INSERT INTO section_schedule (section, day_of_week, start_time, end_time, subject, room, teacher_user_id, note)
                 VALUES (?,?,?,?,?,?,?,?)"
            );
            $stmt->execute([
                $selectedSection,
                $dow,
                $start . ":00",
                $end . ":00",
                $subject !== "" ? $subject : null,
                $room !== "" ? $room : null,
                $teacher,
                $note !== "" ? $note : null,
            ]);
            $newId = (int)db()->lastInsertId();
            audit_log("section_schedule_add", "success", "section_schedule", $newId, "Added section schedule block.", [
                "section" => $selectedSection,
                "day_of_week" => $dow,
            ]);
            $_SESSION["flash"] = "Section schedule saved.";
            header("Location: section-schedule?section=" . urlencode($selectedSection));
            exit;
        }
    }
}

$rows = $selectedSection !== "" ? get_section_schedule($selectedSection) : [];
$editRow = null;
if ($editId > 0) {
    foreach ($rows as $r) {
        if ((int)($r["section_schedule_id"] ?? 0) === $editId) {
            $editRow = $r;
            break;
        }
    }
}

$byDay = [];
foreach ($rows as $r) {
    $d = (int)($r["day_of_week"] ?? 1);
    $byDay[$d][] = $r;
}

ob_start();
?>
<div class="dg-page-header mb-3">
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
    <div>
      <h3 class="mb-0">Section Schedule</h3>
      <div class="text-muted small">Set actual class schedules per section (Thu/Fri, weekend classes, etc.)</div>
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
        <form method="get" action="section-schedule" class="mb-3">
          <label class="form-label mb-1">Section</label>
          <select class="form-select" name="section" onchange="this.form.submit()">
            <?php foreach ($sections as $sec): ?>
              <option value="<?= htmlspecialchars($sec, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" <?= $sec === $selectedSection ? "selected" : "" ?>>
                <?= htmlspecialchars($sec, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>
              </option>
            <?php endforeach; ?>
          </select>
        </form>

        <div class="fw-semibold mb-2"><?= $editRow ? "Edit Class Block" : "Add Class Block" ?></div>
        <form method="post" action="section-schedule" class="d-grid gap-2">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="<?= $editRow ? "update" : "add" ?>" />
          <input type="hidden" name="section" value="<?= htmlspecialchars($selectedSection, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" />
          <?php if ($editRow): ?>
            <input type="hidden" name="section_schedule_id" value="<?= (int)$editRow["section_schedule_id"] ?>" />
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
            <label class="form-label mb-1">Subject (optional)</label>
            <input class="form-control" name="subject" value="<?= $editRow ? htmlspecialchars((string)($editRow["subject"] ?? ""), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") : "" ?>" />
          </div>
          <div>
            <label class="form-label mb-1">Room (optional)</label>
            <input class="form-control" name="room" value="<?= $editRow ? htmlspecialchars((string)($editRow["room"] ?? ""), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") : "" ?>" />
          </div>
          <div>
            <?php if ($isAdmin): ?>
              <label class="form-label mb-1">Teacher (optional)</label>
              <select class="form-select" name="teacher_user_id">
                <option value="0">—</option>
                <?php foreach ($teacherUsers as $tu): ?>
                  <?php
                    $label = (string)($tu["full_name"] ?? "");
                    if ($label === "") $label = (string)($tu["username"] ?? ("User " . (string)$tu["user_id"]));
                    $isSelected = $editRow && (int)($editRow["teacher_user_id"] ?? 0) === (int)($tu["user_id"] ?? 0);
                  ?>
                  <option value="<?= (int)($tu["user_id"] ?? 0) ?>" <?= $isSelected ? "selected" : "" ?>>
                    <?= htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>
                  </option>
                <?php endforeach; ?>
              </select>
            <?php else: ?>
              <label class="form-label mb-1">Assign to me (optional)</label>
              <div class="form-check">
                <input class="form-check-input" type="checkbox" id="useMe" name="use_me" value="1" <?= !empty($editRow["teacher_user_id"]) ? "checked" : "" ?> />
                <label class="form-check-label small" for="useMe">Use my account as the teacher for this block</label>
              </div>
            <?php endif; ?>
          </div>
          <div>
            <label class="form-label mb-1">Note (optional)</label>
            <input class="form-control" name="note" value="<?= $editRow ? htmlspecialchars((string)($editRow["note"] ?? ""), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") : "" ?>" />
          </div>

          <div class="d-flex gap-2">
            <button class="btn btn-dark flex-fill" type="submit"><?= $editRow ? "Save Changes" : "Save Block" ?></button>
            <?php if ($editRow): ?>
              <a class="btn btn-outline-secondary" href="section-schedule?section=<?= urlencode($selectedSection) ?>">Cancel</a>
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
        <?php if ($selectedSection === ""): ?>
          <div class="text-muted small">No sections yet. Add `students.section` first (Data Entry / Import).</div>
        <?php elseif (!$rows): ?>
          <div class="text-muted small">No class blocks for this section yet.</div>
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
                                <?= !empty($it["subject"]) ? htmlspecialchars((string)$it["subject"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") : "Class" ?>
                                <?= !empty($it["room"]) ? " • " . htmlspecialchars((string)$it["room"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") : "" ?>
                              </div>
                              <?php
                                $tid = (int)($it["teacher_user_id"] ?? 0);
                                $tLabel = $isAdmin ? teacher_label_from_users($tid, $teacherUsers) : ($tid === $teacherId ? "Me" : "");
                              ?>
                              <?php if ($tLabel !== ""): ?>
                                <div class="text-muted small">Teacher: <?= htmlspecialchars($tLabel, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></div>
                              <?php endif; ?>
                              <?php if (!empty($it["note"])): ?>
                                <div class="text-muted small"><?= htmlspecialchars((string)$it["note"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></div>
                              <?php endif; ?>
                            </div>
                            <div class="d-flex flex-column gap-1">
                              <a class="btn btn-sm btn-outline-secondary" href="section-schedule?section=<?= urlencode($selectedSection) ?>&edit=<?= (int)$it["section_schedule_id"] ?>">Edit</a>
                              <form method="post" action="section-schedule">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete" />
                                <input type="hidden" name="section" value="<?= htmlspecialchars($selectedSection, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" />
                                <input type="hidden" name="section_schedule_id" value="<?= (int)$it["section_schedule_id"] ?>" />
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
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php
$content = ob_get_clean();
require __DIR__ . "/_layout.php";

