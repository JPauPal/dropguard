<?php
declare(strict_types=1);

require_once __DIR__ . "/../../app/auth.php";
require_once __DIR__ . "/../../app/db.php";
require_once __DIR__ . "/../../app/schedule.php";
require_once __DIR__ . "/../../app/sections.php";
require_once __DIR__ . "/../../app/section_schedule.php";
require_once __DIR__ . "/../../app/subjects.php";
require_once __DIR__ . "/../../app/workflow.php";

require_login();
require_role(["Teacher", "Admin", "Counselor"]);
ensure_workflow_tables();
ensure_teacher_schedule_table();
ensure_student_sections();
ensure_sections_table();
ensure_section_schedule_table();
ensure_subjects_table();

$user = current_user();
$teacherId = (int)($user["user_id"] ?? 0);
$isTeacher = (($user["role"] ?? "") === "Teacher");
$isAdmin = (($user["role"] ?? "") === "Admin");
$isCounselor = (($user["role"] ?? "") === "Counselor");
$isReadOnly = $isCounselor;

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

/** @return list<int> */
function parse_selected_days(mixed $input): array
{
    $vals = [];
    if (is_array($input)) {
        $vals = $input;
    } elseif ($input !== null && $input !== "") {
        $vals = [$input];
    }
    $out = [];
    foreach ($vals as $v) {
        $n = (int)$v;
        if ($n >= 1 && $n <= 7) {
            $out[$n] = true;
        }
    }
    $days = array_keys($out);
    sort($days);
    return $days;
}

function schedule_month_value(string $raw): string
{
    return preg_match('/^\d{4}-\d{2}$/', $raw) ? $raw : date("Y-m");
}

function schedule_month_label(string $month): string
{
    $dt = DateTimeImmutable::createFromFormat("Y-m-d", $month . "-01");
    return $dt ? $dt->format("F Y") : date("F Y");
}

/** @return list<string> */
function schedule_dates_for_weekday(string $month, int $dayOfWeek): array
{
    $start = DateTimeImmutable::createFromFormat("Y-m-d", $month . "-01");
    if (!$start || $dayOfWeek < 1 || $dayOfWeek > 7) {
        return [];
    }
    $dates = [];
    $endMonth = $start->format("m");
    for ($dt = $start; $dt->format("m") === $endMonth; $dt = $dt->modify("+1 day")) {
        if ((int)$dt->format("N") === $dayOfWeek) {
            $dates[] = $dt->format("M j");
        }
    }
    return $dates;
}

function schedule_block_summary(array $b, bool $includeSection = false, bool $includeTeacher = false, array $teacherUsers = []): string
{
    $time = substr((string)($b["start_time"] ?? ""), 0, 5) . "-" . substr((string)($b["end_time"] ?? ""), 0, 5);
    $subject = trim((string)($b["subject_code"] ?? "")) !== ""
        ? ((string)$b["subject_code"] . " - " . (string)($b["subject"] ?? ""))
        : (string)($b["subject"] ?? "Subject");
    $parts = [$time, $subject];
    if ($includeSection) {
        $section = trim((string)($b["section_label"] ?? ""));
        if ($section !== "") {
            $parts[] = section_display_short($section);
        }
    }
    if ($includeTeacher) {
        $tid = (int)($b["teacher_user_id"] ?? 0);
        foreach ($teacherUsers as $tu) {
            if ((int)($tu["user_id"] ?? 0) === $tid) {
                $parts[] = (string)($tu["full_name"] ?? $tu["username"] ?? ("User " . (string)$tid));
                break;
            }
        }
    }
    return implode(" • ", array_filter($parts, static fn($p) => trim((string)$p) !== ""));
}

function schedule_subject_name_for_code(array $subjects, string $code): string
{
    foreach ($subjects as $subject) {
        if (normalize_subject_code((string)($subject["subject_code"] ?? "")) === $code) {
            return (string)($subject["subject_name"] ?? "");
        }
    }
    return "";
}

// Section schedule state
$mode = trim((string)($_GET["mode"] ?? "section"));
$mode = in_array($mode, ["teacher", "section"], true) ? $mode : "section";
$scheduleMonth = schedule_month_value((string)($_GET["schedule_month"] ?? date("Y-m")));
$scheduleGradeLevels = ["", "Grade 7", "Grade 8", "Grade 9", "Grade 10", "Grade 11", "Grade 12"];
$today = new DateTimeImmutable("today");
$todayDow = (int)$today->format("N");

$scheduleGradeNorm = section_grade_filter_normalize(trim((string)($_GET["schedule_grade"] ?? $_POST["schedule_grade"] ?? "")));
$sectionsAll = list_sections(null);
$sections = $scheduleGradeNorm === ""
    ? $sectionsAll
    : array_values(array_filter($sectionsAll, static function (string $s) use ($scheduleGradeNorm): bool {
        return section_matches_grade_filter($s, $scheduleGradeNorm);
    }));
$selectedSection = trim((string)($_GET["section"] ?? ""));
if ($selectedSection !== "" && $scheduleGradeNorm !== "" && !section_matches_grade_filter($selectedSection, $scheduleGradeNorm)) {
    $selectedSection = "";
}
if ($selectedSection === "" && $sections !== []) {
    $selectedSection = $sections[0];
}
$sEditId = (int)($_GET["s_edit"] ?? 0);

$schedExtraQs = $scheduleGradeNorm !== "" ? "&schedule_grade=" . urlencode($scheduleGradeNorm) : "";
$sectionOptions = $sections;
$allSubjects = list_subjects(true);
$sectionSubjectOptions = $selectedSection !== "" ? list_section_subjects($selectedSection) : [];
if (!$sectionSubjectOptions) {
    $sectionSubjectOptions = $allSubjects;
}

// Teacher schedule state
$selectedTeacherId = $teacherId;
if ($isAdmin) {
    $selectedTeacherId = (int)($_GET["teacher_user_id"] ?? $teacherId);
    if ($selectedTeacherId <= 0) $selectedTeacherId = $teacherId;
}
$tEditId = (int)($_GET["t_edit"] ?? 0);
$tEditRow = null;

if (($_SERVER["REQUEST_METHOD"] ?? "GET") === "POST") {
    if ($isReadOnly) {
        $error = "Counselor access is view-only for schedules.";
    } else {
    require_valid_csrf_token();
    $scope = trim((string)($_POST["scope"] ?? ""));
    $action = trim((string)($_POST["action"] ?? "add"));
    $scheduleMonth = schedule_month_value((string)($_POST["schedule_month"] ?? $scheduleMonth));

    if ($scope === "teacher") {
        $targetTeacherId = $teacherId;
        if ($isAdmin) {
            $targetTeacherId = (int)($_POST["teacher_user_id"] ?? $selectedTeacherId);
            if ($targetTeacherId <= 0) $targetTeacherId = $selectedTeacherId;
        }
        if ($action === "delete") {
            $sid = (int)($_POST["schedule_id"] ?? 0);
            if ($sid > 0) {
                if ($isAdmin) {
                    $stmt = db()->prepare("DELETE FROM teacher_schedule WHERE schedule_id = ?");
                    $stmt->execute([$sid]);
                } else {
                    $stmt = db()->prepare("DELETE FROM teacher_schedule WHERE schedule_id = ? AND teacher_user_id = ?");
                    $stmt->execute([$sid, $teacherId]);
                }
                audit_log("teacher_schedule_delete", "success", "teacher_schedule", $sid, "Deleted teacher schedule block.");
                $_SESSION["flash"] = "Schedule entry removed.";
            }
            $q = $isAdmin ? ("&teacher_user_id=" . urlencode((string)$targetTeacherId)) : "";
            $q .= "&schedule_month=" . urlencode($scheduleMonth);
            if ($scheduleGradeNorm !== "") {
                $q .= "&schedule_grade=" . urlencode($scheduleGradeNorm);
            }
            header("Location: schedules?mode=teacher" . $q);
            exit;
        }

        $sid = (int)($_POST["schedule_id"] ?? 0);
        $dow = (int)($_POST["day_of_week"] ?? 1);
        $start = trim((string)($_POST["start_time"] ?? ""));
        $end = trim((string)($_POST["end_time"] ?? ""));
        $sectionLabel = trim((string)($_POST["section_label"] ?? ""));
        $subjectCode = normalize_subject_code((string)($_POST["subject_code"] ?? ""));
        $subject = $subjectCode !== "" ? schedule_subject_name_for_code($allSubjects, $subjectCode) : trim((string)($_POST["subject"] ?? ""));
        $room = trim((string)($_POST["room"] ?? ""));
        $note = trim((string)($_POST["note"] ?? ""));

        if ($dow < 1 || $dow > 7) {
            $error = "Invalid day selection.";
        } elseif ($start === "" || $end === "") {
            $error = "Start and end time are required.";
        } else {
            if ($action === "update" && $sid > 0) {
                if ($isAdmin) {
                    $stmt = db()->prepare(
                        "UPDATE teacher_schedule
                         SET teacher_user_id = ?, day_of_week = ?, start_time = ?, end_time = ?, section_label = NULLIF(?,''), subject_code = NULLIF(?,''), subject = NULLIF(?,''), room = NULLIF(?,''), note = NULLIF(?, '')
                         WHERE schedule_id = ?"
                    );
                    $stmt->execute([$targetTeacherId, $dow, $start, $end, $sectionLabel, $subjectCode, $subject, $room, $note, $sid]);
                } else {
                    $stmt = db()->prepare(
                        "UPDATE teacher_schedule
                         SET day_of_week = ?, start_time = ?, end_time = ?, section_label = NULLIF(?,''), subject_code = NULLIF(?,''), subject = NULLIF(?,''), room = NULLIF(?,''), note = NULLIF(?, '')
                         WHERE schedule_id = ? AND teacher_user_id = ?"
                    );
                    $stmt->execute([$dow, $start, $end, $sectionLabel, $subjectCode, $subject, $room, $note, $sid, $teacherId]);
                }
                audit_log("teacher_schedule_update", "success", "teacher_schedule", $sid, "Updated teacher schedule block.");
                $_SESSION["flash"] = "Schedule entry updated.";
            } else {
                $stmt = db()->prepare(
                    "INSERT INTO teacher_schedule (teacher_user_id, day_of_week, start_time, end_time, section_label, subject_code, subject, room, note)
                     VALUES (?,?,?,?,NULLIF(?,''),NULLIF(?,''),NULLIF(?,''),NULLIF(?,''),NULLIF(?,''))"
                );
                $stmt->execute([$targetTeacherId, $dow, $start, $end, $sectionLabel, $subjectCode, $subject, $room, $note]);
                audit_log("teacher_schedule_add", "success", "teacher_schedule", (int)db()->lastInsertId(), "Added teacher schedule block.");
                $_SESSION["flash"] = "Schedule entry saved.";
            }
            $q = $isAdmin ? ("&teacher_user_id=" . urlencode((string)$targetTeacherId)) : "";
            $q .= "&schedule_month=" . urlencode($scheduleMonth);
            if ($scheduleGradeNorm !== "") {
                $q .= "&schedule_grade=" . urlencode($scheduleGradeNorm);
            }
            header("Location: schedules?mode=teacher" . $q);
            exit;
        }
        $mode = "teacher";
    }

    if ($scope === "section") {
        $selectedSection = trim((string)($_POST["section"] ?? $selectedSection));

        if ($action === "delete") {
            $sid = (int)($_POST["section_schedule_id"] ?? 0);
            if ($sid > 0) {
                if ($isAdmin) {
                    $stmt = db()->prepare("DELETE FROM section_schedule WHERE section_schedule_id = ?");
                    $stmt->execute([$sid]);
                } else {
                    $stmt = db()->prepare("DELETE FROM section_schedule WHERE section_schedule_id = ? AND teacher_user_id = ?");
                    $stmt->execute([$sid, $teacherId]);
                }
                audit_log("section_schedule_delete", "success", "section_schedule", $sid, "Deleted section schedule block.");
                $_SESSION["flash"] = "Section schedule entry removed.";
            }
            header("Location: schedules?mode=section&section=" . urlencode($selectedSection) . "&schedule_month=" . urlencode($scheduleMonth) . $schedExtraQs);
            exit;
        }

        $sid = (int)($_POST["section_schedule_id"] ?? 0);
        $days = parse_selected_days($_POST["days_of_week"] ?? ($_POST["day_of_week"] ?? null));
        $start = trim((string)($_POST["start_time"] ?? ""));
        $end = trim((string)($_POST["end_time"] ?? ""));
        $sectionSubjectOptions = $selectedSection !== "" ? list_section_subjects($selectedSection) : [];
        if (!$sectionSubjectOptions) {
            $sectionSubjectOptions = $allSubjects;
        }
        $subjectCode = normalize_subject_code((string)($_POST["subject_code"] ?? ""));
        $subject = $subjectCode !== "" ? schedule_subject_name_for_code($sectionSubjectOptions, $subjectCode) : trim((string)($_POST["subject"] ?? ""));
        $room = trim((string)($_POST["room"] ?? ""));
        $teacherUserId = $isAdmin ? (int)($_POST["teacher_user_id"] ?? 0) : $teacherId;
        $note = trim((string)($_POST["note"] ?? ""));

        if ($selectedSection === "") {
            $error = "Please select a section.";
        } elseif (!$days) {
            $error = "Please select at least one day.";
        } elseif ($start === "" || $end === "") {
            $error = "Start and end time are required.";
        } elseif ($subjectCode === "" && $subject === "") {
            $error = "Subject is required.";
        } else {
            if ($action === "update" && $sid > 0) {
                // Update the current row to the first selected day, and create additional rows for the other days.
                $firstDow = (int)$days[0];
                if ($isAdmin) {
                    $stmt = db()->prepare(
                        "UPDATE section_schedule
                         SET section = ?, day_of_week = ?, start_time = ?, end_time = ?, subject_code = NULLIF(?,''), subject = ?, room = NULLIF(?,''), teacher_user_id = NULLIF(?,0), note = NULLIF(?, '')
                         WHERE section_schedule_id = ?"
                    );
                    $stmt->execute([$selectedSection, $firstDow, $start, $end, $subjectCode, $subject, $room, $teacherUserId, $note, $sid]);
                } else {
                    $stmt = db()->prepare(
                        "UPDATE section_schedule
                         SET section = ?, day_of_week = ?, start_time = ?, end_time = ?, subject_code = NULLIF(?,''), subject = ?, room = NULLIF(?,''), teacher_user_id = ?, note = NULLIF(?, '')
                         WHERE section_schedule_id = ? AND teacher_user_id = ?"
                    );
                    $stmt->execute([$selectedSection, $firstDow, $start, $end, $subjectCode, $subject, $room, $teacherId, $note, $sid, $teacherId]);
                }

                if (count($days) > 1) {
                    $stmtIns = db()->prepare(
                        "INSERT INTO section_schedule (section, day_of_week, start_time, end_time, subject_code, subject, room, teacher_user_id, note)
                         VALUES (?,?,?,?,NULLIF(?,''),?,NULLIF(?,''),NULLIF(?,0),NULLIF(?,''))"
                    );
                    foreach (array_slice($days, 1) as $dow) {
                        $stmtIns->execute([$selectedSection, (int)$dow, $start, $end, $subjectCode, $subject, $room, $teacherUserId, $note]);
                    }
                }

                audit_log("section_schedule_update", "success", "section_schedule", $sid, "Updated section schedule block (multi-day).", ["days" => $days]);
                $_SESSION["flash"] = count($days) > 1 ? "Section schedule updated for multiple days." : "Section schedule entry updated.";
            } else {
                $stmt = db()->prepare(
                    "INSERT INTO section_schedule (section, day_of_week, start_time, end_time, subject_code, subject, room, teacher_user_id, note)
                     VALUES (?,?,?,?,NULLIF(?,''),?,NULLIF(?,''),NULLIF(?,0),NULLIF(?,''))"
                );
                $newId = null;
                foreach ($days as $dow) {
                    $stmt->execute([$selectedSection, (int)$dow, $start, $end, $subjectCode, $subject, $room, $teacherUserId, $note]);
                    $newId = $newId ?? (int)db()->lastInsertId();
                }
                audit_log("section_schedule_add", "success", "section_schedule", $newId, "Added section schedule block (multi-day).", ["days" => $days]);
                $_SESSION["flash"] = count($days) > 1 ? "Section schedule saved for multiple days." : "Section schedule entry saved.";
            }
            header("Location: schedules?mode=section&section=" . urlencode($selectedSection) . "&schedule_month=" . urlencode($scheduleMonth) . $schedExtraQs);
            exit;
        }
    }
}
    }

if ($tEditId > 0) {
    if ($isAdmin) {
        $stmt = db()->prepare("SELECT * FROM teacher_schedule WHERE schedule_id = ?");
        $stmt->execute([$tEditId]);
    } else {
        $stmt = db()->prepare("SELECT * FROM teacher_schedule WHERE schedule_id = ? AND teacher_user_id = ?");
        $stmt->execute([$tEditId, $teacherId]);
    }
    $tEditRow = $stmt->fetch() ?: null;
}

// Section schedule edit record
$sEditRow = null;
if ($sEditId > 0) {
    $stmt = db()->prepare("SELECT * FROM section_schedule WHERE section_schedule_id = ?");
    $stmt->execute([$sEditId]);
    $sEditRow = $stmt->fetch() ?: null;
    if ($sEditRow) {
        $selectedSection = (string)($sEditRow["section"] ?? $selectedSection);
    }
}
$sectionSubjectOptions = $selectedSection !== "" ? list_section_subjects($selectedSection) : [];
if (!$sectionSubjectOptions) {
    $sectionSubjectOptions = $allSubjects;
}

// Data lists
$teacherBlocks = get_teacher_schedule($isAdmin ? $selectedTeacherId : $teacherId);
$sectionBlocksAll = $selectedSection !== "" ? get_section_schedule($selectedSection) : [];
$sectionBlocks = $isAdmin ? $sectionBlocksAll : array_values(array_filter($sectionBlocksAll, static fn($b) => (int)($b["teacher_user_id"] ?? 0) === $teacherId));

// Teachers list (admin-only assignment display / edit)
$teacherUsers = [];
try {
    $teacherUsers = db_decrypt_user_pii_rows(db()->query(
        "SELECT user_id, full_name, username FROM users WHERE role = 'Teacher' ORDER BY username ASC"
    )->fetchAll());
} catch (Throwable) {
    $teacherUsers = [];
}

ob_start();
?>
<div class="dg-page-header mb-3">
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
    <div>
      <h3 class="mb-0">Schedules</h3>
      <div class="text-muted small">Teacher schedule and section schedules.</div>
    </div>
  </div>
</div>

<?php if ($flash): ?>
  <div class="alert alert-success"><?= htmlspecialchars((string)$flash, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></div>
<?php endif; ?>
<?php if ($error): ?>
  <div class="alert alert-danger"><?= htmlspecialchars((string)$error, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></div>
<?php endif; ?>

<ul class="nav nav-pills mb-3">
  <li class="nav-item">
    <a class="nav-link <?= $mode === "teacher" ? "active" : "" ?>" href="schedules?mode=teacher&schedule_month=<?= urlencode($scheduleMonth) ?><?= htmlspecialchars($schedExtraQs, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>"><?= htmlspecialchars(t("tabs.teacher_schedule"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $mode === "section" ? "active" : "" ?>" href="schedules?mode=section&section=<?= urlencode($selectedSection) ?>&schedule_month=<?= urlencode($scheduleMonth) ?><?= htmlspecialchars($schedExtraQs, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>"><?= htmlspecialchars(t("tabs.section_schedule"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></a>
  </li>
</ul>

<div class="card shadow-sm mb-3">
  <div class="card-body">
    <form method="get" action="schedules" class="row g-2 align-items-end">
      <input type="hidden" name="mode" value="<?= htmlspecialchars($mode, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" />
      <?php if ($mode === "section"): ?>
        <input type="hidden" name="section" value="<?= htmlspecialchars($selectedSection, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" />
      <?php endif; ?>
      <?php if ($mode === "teacher" && $isAdmin): ?>
        <input type="hidden" name="teacher_user_id" value="<?= (int)$selectedTeacherId ?>" />
      <?php endif; ?>
      <?php if ($scheduleGradeNorm !== ""): ?>
        <input type="hidden" name="schedule_grade" value="<?= htmlspecialchars($scheduleGradeNorm, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" />
      <?php endif; ?>
      <div class="col-md-4">
        <label class="form-label mb-1">Schedule month</label>
        <input class="form-control" type="month" name="schedule_month" value="<?= htmlspecialchars($scheduleMonth, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" />
      </div>
      <div class="col-md-3">
        <button class="btn btn-dark w-100" type="submit">Update dates</button>
      </div>
      <div class="col-md-5 text-muted small">
        Automation uses the selected weekday and lists the matching dates in <?= htmlspecialchars(schedule_month_label($scheduleMonth), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>. Today is <?= htmlspecialchars($today->format("F j, Y") . " (" . dow_label($todayDow) . ")", ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>.
      </div>
    </form>
  </div>
</div>

<?php if ($mode === "teacher"): ?>
  <?php if ($isAdmin): ?>
    <form method="get" action="schedules" class="row g-2 align-items-end mb-3">
      <input type="hidden" name="mode" value="teacher" />
      <input type="hidden" name="schedule_month" value="<?= htmlspecialchars($scheduleMonth, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" />
      <?php if ($scheduleGradeNorm !== ""): ?>
        <input type="hidden" name="schedule_grade" value="<?= htmlspecialchars($scheduleGradeNorm, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" />
      <?php endif; ?>
      <div class="col-md-6">
        <label class="form-label mb-1">Teacher</label>
        <select class="form-select" name="teacher_user_id" onchange="this.form.submit()">
          <?php foreach ($teacherUsers as $tu): ?>
            <?php
              $label = (string)($tu["full_name"] ?? "");
              if ($label === "") $label = (string)($tu["username"] ?? ("User " . (string)$tu["user_id"]));
            ?>
            <option value="<?= (int)$tu["user_id"] ?>" <?= (int)$tu["user_id"] === (int)$selectedTeacherId ? "selected" : "" ?>>
              <?= htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    </form>
  <?php endif; ?>

  <div class="row g-3">
    <div class="col-lg-5">
      <div class="card shadow-sm">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <div class="fw-semibold"><?= $tEditRow ? "Edit block" : "Add block" ?></div>
            <?php if ($tEditRow): ?>
              <a class="btn btn-sm btn-outline-secondary" href="schedules?mode=teacher<?= $isAdmin ? ("&teacher_user_id=" . urlencode((string)$selectedTeacherId)) : "" ?>&schedule_month=<?= urlencode($scheduleMonth) ?><?= htmlspecialchars($schedExtraQs, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>">Cancel</a>
            <?php endif; ?>
          </div>
          <form method="post" action="schedules?mode=teacher<?= $isAdmin ? ("&teacher_user_id=" . urlencode((string)$selectedTeacherId)) : "" ?>&schedule_month=<?= urlencode($scheduleMonth) ?><?= htmlspecialchars($schedExtraQs, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="scope" value="teacher" />
            <input type="hidden" name="action" value="<?= $tEditRow ? "update" : "add" ?>" />
            <input type="hidden" name="schedule_id" value="<?= (int)($tEditRow["schedule_id"] ?? 0) ?>" />
            <input type="hidden" name="schedule_month" value="<?= htmlspecialchars($scheduleMonth, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" />
            <?php if ($scheduleGradeNorm !== ""): ?>
              <input type="hidden" name="schedule_grade" value="<?= htmlspecialchars($scheduleGradeNorm, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" />
            <?php endif; ?>
            <?php if ($isAdmin): ?>
              <input type="hidden" name="teacher_user_id" value="<?= (int)$selectedTeacherId ?>" />
            <?php endif; ?>
            <div class="row g-2">
              <div class="col-5">
                <label class="form-label small">Day</label>
                <select class="form-select" name="day_of_week">
                  <?php for ($d = 1; $d <= 7; $d++): ?>
                    <option value="<?= $d ?>" <?= (int)($tEditRow["day_of_week"] ?? 1) === $d ? "selected" : "" ?>><?= dow_label($d) ?></option>
                  <?php endfor; ?>
                </select>
              </div>
              <div class="col-3">
                <label class="form-label small">Start</label>
                <input class="form-control" type="time" name="start_time" value="<?= htmlspecialchars((string)($tEditRow["start_time"] ?? ""), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" required />
              </div>
              <div class="col-4">
                <label class="form-label small">End</label>
                <input class="form-control" type="time" name="end_time" value="<?= htmlspecialchars((string)($tEditRow["end_time"] ?? ""), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" required />
              </div>
            </div>
            <div class="row g-2 mt-1">
              <div class="col-6">
                <label class="form-label small">Section (optional)</label>
                <?php $curSection = (string)($tEditRow["section_label"] ?? ""); ?>
                <select class="form-select" name="section_label">
                  <option value="">—</option>
                  <?php foreach ($sectionOptions as $sec): ?>
                    <option value="<?= htmlspecialchars($sec, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" <?= $sec === $curSection ? "selected" : "" ?>>
                      <?= htmlspecialchars(section_display_short($sec), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-6">
                <label class="form-label small">Subject (optional)</label>
                <?php $curSubjectCode = normalize_subject_code((string)($tEditRow["subject_code"] ?? "")); ?>
                <?php if ($allSubjects): ?>
                  <select class="form-select" name="subject_code">
                    <option value="">—</option>
                    <?php foreach ($allSubjects as $subject): ?>
                      <?php $code = normalize_subject_code((string)$subject["subject_code"]); ?>
                      <option value="<?= htmlspecialchars($code, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" <?= $code === $curSubjectCode ? "selected" : "" ?>>
                        <?= htmlspecialchars(subject_label($subject), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                <?php else: ?>
                  <input class="form-control" name="subject" value="<?= htmlspecialchars((string)($tEditRow["subject"] ?? ""), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" />
                <?php endif; ?>
              </div>
            </div>
            <div class="row g-2 mt-1">
              <div class="col-6">
                <label class="form-label small">Room (optional)</label>
                <input class="form-control" name="room" value="<?= htmlspecialchars((string)($tEditRow["room"] ?? ""), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" />
              </div>
              <div class="col-6">
                <label class="form-label small">Note (optional)</label>
                <input class="form-control" name="note" value="<?= htmlspecialchars((string)($tEditRow["note"] ?? ""), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" />
              </div>
            </div>
            <button class="btn btn-dark w-100 mt-3" type="submit"><?= $tEditRow ? "Save changes" : "Save block" ?></button>
          </form>
        </div>
      </div>
    </div>
    <div class="col-lg-7">
      <div class="card shadow-sm">
        <div class="card-body">
          <?php $teacherTodayBlocks = array_values(array_filter($teacherBlocks, static fn($b) => (int)$b["day_of_week"] === $todayDow)); ?>
          <div class="border rounded p-3 mb-3 bg-light">
            <div class="fw-semibold">Today — <?= htmlspecialchars(dow_label($todayDow), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></div>
            <?php if (!$teacherTodayBlocks): ?>
              <div class="text-muted small">No scheduled blocks for today.</div>
            <?php else: ?>
              <ul class="mb-0 small">
                <?php foreach ($teacherTodayBlocks as $b): ?>
                  <li><?= htmlspecialchars(schedule_block_summary($b, true), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
          </div>
          <div class="fw-semibold mb-2">Weekly view with <?= htmlspecialchars(schedule_month_label($scheduleMonth), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?> dates</div>
          <div class="row g-2">
            <?php for ($d = 1; $d <= 7; $d++): ?>
              <div class="col-12">
                <div class="border rounded p-2">
                  <div class="small text-muted mb-1">
                    <?= dow_label($d) ?>
                    <span class="ms-2"><?= htmlspecialchars(implode(", ", schedule_dates_for_weekday($scheduleMonth, $d)), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></span>
                  </div>
                  <?php
                    $blocks = array_values(array_filter($teacherBlocks, static fn($b) => (int)$b["day_of_week"] === $d));
                    usort($blocks, static fn($a, $b) => strcmp((string)$a["start_time"], (string)$b["start_time"]));
                  ?>
                  <?php if (!$blocks): ?>
                    <div class="text-muted small">No blocks</div>
                  <?php else: ?>
                    <div class="table-responsive">
                      <table class="table table-sm align-middle mb-0">
                        <thead>
                          <tr>
                            <th>Time</th>
                            <th>Section</th>
                            <th>Subject</th>
                            <th>Room</th>
                            <th class="text-end">Actions</th>
                          </tr>
                        </thead>
                        <tbody>
                          <?php foreach ($blocks as $b): ?>
                            <tr>
                              <td class="small"><?= htmlspecialchars((string)$b["start_time"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>–<?= htmlspecialchars((string)$b["end_time"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></td>
                              <td class="small"><?php $sl = trim((string)($b["section_label"] ?? "")); ?><?= $sl !== "" ? htmlspecialchars(section_display_short($sl), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") : "-" ?></td>
                              <td class="small"><?= htmlspecialchars(trim((string)($b["subject_code"] ?? "")) !== "" ? ((string)$b["subject_code"] . " - " . (string)($b["subject"] ?? "")) : (string)($b["subject"] ?? "-"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></td>
                              <td class="small"><?= htmlspecialchars((string)($b["room"] ?? "-"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></td>
                              <td class="text-end">
                                <a class="btn btn-sm btn-outline-secondary" href="schedules?mode=teacher&t_edit=<?= (int)$b["schedule_id"] ?><?= $isAdmin ? ("&teacher_user_id=" . urlencode((string)$selectedTeacherId)) : "" ?>&schedule_month=<?= urlencode($scheduleMonth) ?><?= htmlspecialchars($schedExtraQs, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>">Edit</a>
                                <form class="d-inline" method="post" action="schedules?mode=teacher<?= $isAdmin ? ("&teacher_user_id=" . urlencode((string)$selectedTeacherId)) : "" ?>&schedule_month=<?= urlencode($scheduleMonth) ?><?= htmlspecialchars($schedExtraQs, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" onsubmit="return confirm('Remove this schedule block?');">
                                  <?= csrf_field() ?>
                                  <input type="hidden" name="scope" value="teacher" />
                                  <input type="hidden" name="action" value="delete" />
                                  <input type="hidden" name="schedule_id" value="<?= (int)$b["schedule_id"] ?>" />
                                  <input type="hidden" name="schedule_month" value="<?= htmlspecialchars($scheduleMonth, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" />
                                  <?php if ($scheduleGradeNorm !== ""): ?>
                                    <input type="hidden" name="schedule_grade" value="<?= htmlspecialchars($scheduleGradeNorm, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" />
                                  <?php endif; ?>
                                  <?php if ($isAdmin): ?>
                                    <input type="hidden" name="teacher_user_id" value="<?= (int)$selectedTeacherId ?>" />
                                  <?php endif; ?>
                                  <button class="btn btn-sm btn-outline-danger" type="submit">Delete</button>
                                </form>
                              </td>
                            </tr>
                          <?php endforeach; ?>
                        </tbody>
                      </table>
                    </div>
                  <?php endif; ?>
                </div>
              </div>
            <?php endfor; ?>
          </div>
        </div>
      </div>
    </div>
  </div>
<?php else: ?>
  <div class="row g-3">
    <div class="col-lg-5">
      <div class="card shadow-sm">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <div class="fw-semibold"><?= $sEditRow ? "Edit section block" : "Add section block" ?></div>
            <?php if ($sEditRow): ?>
              <a class="btn btn-sm btn-outline-secondary" href="schedules?mode=section&section=<?= urlencode($selectedSection) ?>&schedule_month=<?= urlencode($scheduleMonth) ?><?= htmlspecialchars($schedExtraQs, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>">Cancel</a>
            <?php endif; ?>
          </div>
          <form method="get" action="schedules" class="mb-2 row g-2">
            <input type="hidden" name="mode" value="section" />
            <input type="hidden" name="schedule_month" value="<?= htmlspecialchars($scheduleMonth, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" />
            <div class="col-md-6">
              <label class="form-label small">Year level</label>
              <select class="form-select" name="schedule_grade" onchange="this.form.submit()">
                <option value="">All year levels</option>
                <?php foreach (array_slice($scheduleGradeLevels, 1) as $g): ?>
                  <option value="<?= htmlspecialchars($g, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" <?= $g === $scheduleGradeNorm ? "selected" : "" ?>>
                    <?= htmlspecialchars($g, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label small">Section</label>
              <select class="form-select" name="section" onchange="this.form.submit()" <?= $sections === [] ? "disabled" : "" ?>>
                <?php foreach ($sections as $sec): ?>
                  <option value="<?= htmlspecialchars((string)$sec, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" <?= $sec === $selectedSection ? "selected" : "" ?>>
                    <?= htmlspecialchars(section_display_short((string)$sec), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <?php if ($sections === [] && $scheduleGradeNorm !== ""): ?>
                <div class="form-text text-muted">No sections for this year level.</div>
              <?php endif; ?>
            </div>
          </form>

          <form method="post" action="schedules?mode=section&section=<?= urlencode($selectedSection) ?>&schedule_month=<?= urlencode($scheduleMonth) ?><?= htmlspecialchars($schedExtraQs, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="scope" value="section" />
            <input type="hidden" name="action" value="<?= $sEditRow ? "update" : "add" ?>" />
            <input type="hidden" name="section_schedule_id" value="<?= (int)($sEditRow["section_schedule_id"] ?? 0) ?>" />
            <input type="hidden" name="section" value="<?= htmlspecialchars((string)$selectedSection, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" />
            <input type="hidden" name="schedule_month" value="<?= htmlspecialchars($scheduleMonth, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" />
            <?php if ($scheduleGradeNorm !== ""): ?>
              <input type="hidden" name="schedule_grade" value="<?= htmlspecialchars($scheduleGradeNorm, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" />
            <?php endif; ?>

            <div class="row g-2">
              <div class="col-5">
                <label class="form-label small">Days</label>
                <?php $selDays = [(int)($sEditRow["day_of_week"] ?? 1)]; ?>
                <div class="dropdown w-100 dg-days-dropdown" data-selected-days="<?= htmlspecialchars(implode(",", $selDays), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>">
                  <button class="btn btn-outline-secondary dropdown-toggle w-100 text-start" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <span class="dg-days-label">Select days…</span>
                  </button>
                  <div class="dropdown-menu p-2 w-100" style="max-height: 240px; overflow:auto;">
                    <?php for ($d = 1; $d <= 7; $d++): ?>
                      <label class="dropdown-item d-flex align-items-center gap-2 m-0">
                        <input class="form-check-input m-0 dg-day-check" type="checkbox" value="<?= $d ?>" />
                        <span><?= htmlspecialchars(dow_label($d), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></span>
                      </label>
                    <?php endfor; ?>
                  </div>
                  <div class="dg-days-hidden"></div>
                </div>
                <div class="form-text">Click to pick multiple days.</div>
              </div>
              <div class="col-3">
                <label class="form-label small">Start</label>
                <input class="form-control" type="time" name="start_time" value="<?= htmlspecialchars((string)($sEditRow["start_time"] ?? ""), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" required />
              </div>
              <div class="col-4">
                <label class="form-label small">End</label>
                <input class="form-control" type="time" name="end_time" value="<?= htmlspecialchars((string)($sEditRow["end_time"] ?? ""), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" required />
              </div>
            </div>

            <div class="row g-2 mt-1">
              <div class="col-6">
                <label class="form-label small">Subject</label>
                <?php $curSectionSubjectCode = normalize_subject_code((string)($sEditRow["subject_code"] ?? "")); ?>
                <?php if ($sectionSubjectOptions): ?>
                  <select class="form-select" name="subject_code" required>
                    <option value="">Select subject...</option>
                    <?php foreach ($sectionSubjectOptions as $subject): ?>
                      <?php $code = normalize_subject_code((string)$subject["subject_code"]); ?>
                      <option value="<?= htmlspecialchars($code, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" <?= $code === $curSectionSubjectCode ? "selected" : "" ?>>
                        <?= htmlspecialchars(subject_label($subject), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                  <div class="form-text">Subjects come from the section subscription list.</div>
                <?php else: ?>
                  <input class="form-control" name="subject" value="<?= htmlspecialchars((string)($sEditRow["subject"] ?? ""), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" required />
                <?php endif; ?>
              </div>
              <div class="col-6">
                <label class="form-label small">Room (optional)</label>
                <input class="form-control" name="room" value="<?= htmlspecialchars((string)($sEditRow["room"] ?? ""), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" />
              </div>
            </div>

            <div class="row g-2 mt-1">
              <div class="col-12">
                <label class="form-label small">Note (optional)</label>
                <input class="form-control" name="note" value="<?= htmlspecialchars((string)($sEditRow["note"] ?? ""), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" />
              </div>
            </div>

            <?php if ($isAdmin): ?>
              <div class="mt-2">
                <label class="form-label small">Teacher (Admin only)</label>
                <select class="form-select" name="teacher_user_id">
                  <option value="0">—</option>
                  <?php foreach ($teacherUsers as $tu): ?>
                    <?php
                      $label = (string)($tu["full_name"] ?? "");
                      if ($label === "") $label = (string)($tu["username"] ?? ("User " . (string)$tu["user_id"]));
                    ?>
                    <option value="<?= (int)$tu["user_id"] ?>" <?= (int)($sEditRow["teacher_user_id"] ?? 0) === (int)$tu["user_id"] ? "selected" : "" ?>>
                      <?= htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
            <?php endif; ?>

            <button class="btn btn-dark w-100 mt-3" type="submit"><?= $sEditRow ? "Save changes" : "Save block" ?></button>
          </form>
        </div>
      </div>
    </div>

    <div class="col-lg-7">
      <div class="card shadow-sm">
        <div class="card-body">
          <?php $sectionTodayBlocks = array_values(array_filter($sectionBlocks, static fn($b) => (int)$b["day_of_week"] === $todayDow)); ?>
          <div class="border rounded p-3 mb-3 bg-light">
            <div class="fw-semibold">Today — <?= htmlspecialchars(dow_label($todayDow), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></div>
            <?php if ($selectedSection === ""): ?>
              <div class="text-muted small">Select a section to show today's schedule.</div>
            <?php elseif (!$sectionTodayBlocks): ?>
              <div class="text-muted small">No scheduled blocks for today.</div>
            <?php else: ?>
              <ul class="mb-0 small">
                <?php foreach ($sectionTodayBlocks as $b): ?>
                  <li><?= htmlspecialchars(schedule_block_summary($b, false, $isAdmin, $teacherUsers), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
          </div>
          <div class="fw-semibold mb-2">Weekly view — <?= htmlspecialchars($selectedSection !== "" ? section_display_short($selectedSection) : "No section", ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?> (<?= htmlspecialchars(schedule_month_label($scheduleMonth), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>)</div>
          <?php if ($selectedSection === ""): ?>
            <div class="text-muted">No sections found yet. Add sections on Data Entry or Import first.</div>
          <?php else: ?>
            <div class="row g-2">
              <?php for ($d = 1; $d <= 7; $d++): ?>
                <div class="col-12">
                  <div class="border rounded p-2">
                    <div class="small text-muted mb-1">
                      <?= dow_label($d) ?>
                      <span class="ms-2"><?= htmlspecialchars(implode(", ", schedule_dates_for_weekday($scheduleMonth, $d)), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></span>
                    </div>
                    <?php
                      $blocks = array_values(array_filter($sectionBlocks, static fn($b) => (int)$b["day_of_week"] === $d));
                      usort($blocks, static fn($a, $b) => strcmp((string)$a["start_time"], (string)$b["start_time"]));
                    ?>
                    <?php if (!$blocks): ?>
                      <div class="text-muted small">No blocks</div>
                    <?php else: ?>
                      <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                          <thead>
                            <tr>
                              <th>Time</th>
                              <th>Subject</th>
                              <th>Room</th>
                              <?php if ($isAdmin): ?><th>Teacher</th><?php endif; ?>
                              <th class="text-end">Actions</th>
                            </tr>
                          </thead>
                          <tbody>
                            <?php foreach ($blocks as $b): ?>
                              <?php
                                $teacherName = "-";
                                $tid = (int)($b["teacher_user_id"] ?? 0);
                                if ($tid > 0) {
                                    foreach ($teacherUsers as $tu) {
                                        if ((int)$tu["user_id"] === $tid) {
                                            $teacherName = (string)($tu["full_name"] ?? $tu["username"] ?? ("User " . (string)$tid));
                                            break;
                                        }
                                    }
                                }
                              ?>
                              <tr>
                                <td class="small"><?= htmlspecialchars((string)$b["start_time"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>–<?= htmlspecialchars((string)$b["end_time"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></td>
                                <td class="small"><?= htmlspecialchars(trim((string)($b["subject_code"] ?? "")) !== "" ? ((string)$b["subject_code"] . " - " . (string)($b["subject"] ?? "")) : (string)$b["subject"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></td>
                                <td class="small"><?= htmlspecialchars((string)($b["room"] ?? "-"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></td>
                                <?php if ($isAdmin): ?><td class="small"><?= htmlspecialchars($teacherName, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></td><?php endif; ?>
                                <td class="text-end">
                                  <a class="btn btn-sm btn-outline-secondary" href="schedules?mode=section&section=<?= urlencode($selectedSection) ?>&s_edit=<?= (int)$b["section_schedule_id"] ?>&schedule_month=<?= urlencode($scheduleMonth) ?><?= htmlspecialchars($schedExtraQs, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>">Edit</a>
                                  <form class="d-inline" method="post" action="schedules?mode=section&section=<?= urlencode($selectedSection) ?>&schedule_month=<?= urlencode($scheduleMonth) ?><?= htmlspecialchars($schedExtraQs, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" onsubmit="return confirm('Remove this schedule block?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="scope" value="section" />
                                    <input type="hidden" name="action" value="delete" />
                                    <input type="hidden" name="section" value="<?= htmlspecialchars((string)$selectedSection, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" />
                                    <input type="hidden" name="section_schedule_id" value="<?= (int)$b["section_schedule_id"] ?>" />
                                    <input type="hidden" name="schedule_month" value="<?= htmlspecialchars($scheduleMonth, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" />
                                    <?php if ($scheduleGradeNorm !== ""): ?>
                                      <input type="hidden" name="schedule_grade" value="<?= htmlspecialchars($scheduleGradeNorm, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" />
                                    <?php endif; ?>
                                    <button class="btn btn-sm btn-outline-danger" type="submit">Delete</button>
                                  </form>
                                </td>
                              </tr>
                            <?php endforeach; ?>
                          </tbody>
                        </table>
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

<?php endif; ?>

<script>
  (function () {
    function dowLabel(n) {
      switch (Number(n)) {
        case 1: return 'Mon';
        case 2: return 'Tue';
        case 3: return 'Wed';
        case 4: return 'Thu';
        case 5: return 'Fri';
        case 6: return 'Sat';
        case 7: return 'Sun';
        default: return String(n || '');
      }
    }

    document.querySelectorAll('.dg-days-dropdown').forEach((root) => {
      const hidden = root.querySelector('.dg-days-hidden');
      const label = root.querySelector('.dg-days-label');
      const checks = Array.from(root.querySelectorAll('.dg-day-check'));
      if (!hidden || !label || !checks.length) return;

      const init = (root.getAttribute('data-selected-days') || '')
        .split(',')
        .map((s) => Number(s.trim()))
        .filter((n) => Number.isFinite(n) && n >= 1 && n <= 7);
      const initSet = new Set(init);
      checks.forEach((c) => { c.checked = initSet.has(Number(c.value)); });

      function sync() {
        const selected = checks
          .filter((c) => c.checked)
          .map((c) => Number(c.value))
          .sort((a, b) => a - b);

        hidden.innerHTML = selected.map((d) => (
          '<input type="hidden" name="days_of_week[]" value="' + String(d) + '"/>'
        )).join('');

        label.textContent = selected.length
          ? selected.map(dowLabel).join(', ')
          : 'Select days…';
      }

      checks.forEach((c) => c.addEventListener('change', sync));
      sync();
    });
  })();
</script>

<?php
$content = ob_get_clean();
require __DIR__ . "/_layout.php";

