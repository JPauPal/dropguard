<?php
declare(strict_types=1);

require_once __DIR__ . "/../../app/auth.php";
require_once __DIR__ . "/../../app/db.php";
require_once __DIR__ . "/../../app/marks.php";
require_once __DIR__ . "/../../app/batches.php";
require_once __DIR__ . "/../../app/workflow.php";
require_once __DIR__ . "/../../app/curriculum.php";
require_once __DIR__ . "/../../app/sections.php";
require_once __DIR__ . "/../../app/students.php";

require_login();
require_role(["Teacher", "Admin"]);
ensure_student_marks_table();
ensure_student_batches_table();
ensure_workflow_tables();
ensure_student_sections();
ensure_students_archived_column();
$currentUser = current_user();
// Teacher-only: used to disable referral button for students not yet added to class list.
$isTeacher = (($currentUser["role"] ?? "") === "Teacher");

// Admin already manages students in All Students. Keep one clean place.
if (($currentUser["role"] ?? "") === "Admin") {
    header("Location: student-list");
    exit;
}

$error = null;
$success = null;

function dg_build_full_name(string $first, string $middle, string $last): string
{
    $first = trim($first);
    $middle = trim($middle);
    $last = trim($last);
    $parts = [$first];
    if ($middle !== "") $parts[] = $middle;
    $parts[] = $last;
    return trim(preg_replace('/\s+/', ' ', implode(' ', array_filter($parts, static fn($p) => $p !== ""))) ?? "");
}

if (($_SERVER["REQUEST_METHOD"] ?? "GET") === "POST") {
    require_valid_csrf_token();
    $action = trim((string)($_POST["action"] ?? ""));

    if ($action === "import_students") {
        $idsIn = $_POST["student_ids"] ?? [];
        if (!is_array($idsIn)) $idsIn = [];
        $ids = [];
        foreach ($idsIn as $v) {
            $n = (int)$v;
            if ($n > 0) $ids[$n] = true;
        }
        $ids = array_keys($ids);
        if ($ids === []) {
            $error = t("data_entry.err_select_students");
        } else {
            $pdo = db();
            $pdo->beginTransaction();
            try {
                $stmtMap = $pdo->prepare(
                    "INSERT INTO teacher_students (teacher_user_id, student_id)
                     VALUES (?, ?)
                     ON DUPLICATE KEY UPDATE teacher_user_id = VALUES(teacher_user_id)"
                );
                foreach ($ids as $sid) {
                    $stmtMap->execute([(int)$currentUser["user_id"], $sid]);
                }
                $pdo->commit();
                $success = tr("data_entry.success_imported", ["count" => (string)count($ids)]);
                audit_log("teacher_students_import", "success", "teacher_students", null, "Teacher imported students to class list (Data Entry).", [
                    "count" => count($ids),
                ]);
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error = tr("data_entry.err_import_fail", ["reason" => $e->getMessage()]);
                audit_log("teacher_students_import", "failure", "teacher_students", null, "Failed to import students to class list (Data Entry).", [
                    "count" => count($ids),
                    "error" => $e->getMessage(),
                ]);
            }
        }
    } elseif ($action === "remove_from_class") {
        $studentId = (int)($_POST["student_id"] ?? 0);
        if ($studentId <= 0) {
            $error = t("data_entry.err_invalid_student");
        } else {
            try {
                db()->prepare("DELETE FROM teacher_students WHERE teacher_user_id = ? AND student_id = ?")
                    ->execute([(int)($currentUser["user_id"] ?? 0), $studentId]);
                $success = t("data_entry.success_removed");
                audit_log("teacher_students_remove", "success", "teacher_students", null, "Teacher removed student from class list (Data Entry).", [
                    "student_id" => $studentId,
                ]);
            } catch (Throwable $e) {
                $error = tr("data_entry.err_remove_fail", ["reason" => $e->getMessage()]);
                audit_log("teacher_students_remove", "failure", "teacher_students", null, "Failed to remove student from class list (Data Entry).", [
                    "student_id" => $studentId,
                    "error" => $e->getMessage(),
                ]);
            }
        }
    } else {
    $lrn = trim((string)($_POST["lrn"] ?? ""));
    $firstName = trim((string)($_POST["first_name"] ?? ""));
    $middleName = trim((string)($_POST["middle_name"] ?? ""));
    $lastName = trim((string)($_POST["last_name"] ?? ""));
    $name = dg_build_full_name($firstName, $middleName, $lastName);
    $gradeLevelRaw = trim((string)($_POST["grade_level"] ?? ""));
    $gradeLevel = curriculum_normalize_grade_level($gradeLevelRaw) ?? "";
    $strandRaw = trim((string)($_POST["strand"] ?? ""));
    [$strandNorm, $strandErr] = curriculum_validate_strand_input($gradeLevel !== "" ? $gradeLevel : null, $strandRaw);
    $section = trim((string)($_POST["section"] ?? ""));
    $schoolYear = trim((string)($_POST["school_year"] ?? ""));
    if ($schoolYear === "") {
        $schoolYear = default_current_school_year();
    }
    $allowedSections = list_sections(null);

    if ($firstName === "" || $lastName === "") {
        $error = t("data_entry.err_first_last");
    } elseif ($lrn === "") {
        $error = t("data_entry.err_lrn_required");
    } elseif ($strandErr !== null) {
        $error = $strandErr;
    } elseif ($gradeLevel === "") {
        $error = t("data_entry.err_grade_range");
    } elseif ($section === "") {
        $error = t("data_entry.err_section_required");
    } elseif (!in_array($section, $allowedSections, true)) {
        $error = t("data_entry.err_section_invalid");
    } elseif ($section !== "" && !section_matches_grade_filter($section, $gradeLevel)) {
        $error = t("data_entry.err_section_grade");
    } elseif (curriculum_is_senior_high_grade($gradeLevel) && ($strandNorm ?? "") !== "" && $section !== "" && !section_matches_strand_filter($section, (string)$strandNorm)) {
        $error = t("data_entry.err_section_strand");
    } elseif (!is_valid_school_year_sequence($schoolYear)) {
        $error = t("data_entry.err_school_year");
    } elseif (!preg_match('/^[A-Za-z0-9\\-]{4,30}$/', $lrn)) {
        $error = t("data_entry.err_lrn_format");
    } else {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            // Reuse existing student if LRN already exists, otherwise fallback to name + grade.
            $studentId = 0;
            if ($lrn !== "") {
                $stmtFindLrn = $pdo->prepare("SELECT student_id FROM students WHERE lrn = ? LIMIT 1");
                $stmtFindLrn->execute([$lrn]);
                $found = $stmtFindLrn->fetch();
                if ($found) {
                    $studentId = (int)$found["student_id"];
                }
            }
            if ($studentId <= 0) {
                $stmtFind = $pdo->prepare("SELECT student_id FROM students WHERE name = ? AND grade_level = ? ORDER BY student_id DESC LIMIT 1");
                $stmtFind->execute([$name, $gradeLevel]);
                $found = $stmtFind->fetch();
                if ($found) {
                    $studentId = (int)$found["student_id"];
                }
            }

            if ($studentId > 0) {
                $stmtArch = $pdo->prepare("SELECT COALESCE(is_archived,0) FROM students WHERE student_id = ?");
                $stmtArch->execute([$studentId]);
                if ((int)$stmtArch->fetchColumn() === 1) {
                    throw new RuntimeException("ARCHIVED_STUDENT");
                }
            }

            if ($studentId > 0 && $lrn !== "") {
                $stmtDupLrn = $pdo->prepare("SELECT student_id FROM students WHERE lrn = ? AND student_id <> ? LIMIT 1");
                $stmtDupLrn->execute([$lrn, $studentId]);
                if ($stmtDupLrn->fetch()) {
                    throw new RuntimeException("LRN already exists.");
                }
            }

            if ($studentId <= 0) {
                $stmtInsert = $pdo->prepare("INSERT INTO students (lrn, name, grade_level, strand, section, gpa, absences, risk_score, risk_level) VALUES (?,?,?,?,?,0,0,0,'Low')");
                $stmtInsert->execute([$lrn, $name, $gradeLevel, $strandNorm, ($section !== "" ? $section : null)]);
                $studentId = (int)$pdo->lastInsertId();
            } else {
                $stmtUpdate = $pdo->prepare("UPDATE students SET lrn = ?, name = ?, grade_level = ?, strand = ?, section = NULLIF(?, '') WHERE student_id = ?");
                $stmtUpdate->execute([$lrn, $name, $gradeLevel, $strandNorm, $section, $studentId]);
            }

            // Section lives on the student row for quick filtering.
            if ($section !== "") {
                $stmtSec = $pdo->prepare("UPDATE students SET section = ? WHERE student_id = ?");
                $stmtSec->execute([$section, $studentId]);
            }

            if (($currentUser["role"] ?? "") === "Teacher") {
                $stmtMap = $pdo->prepare(
                    "INSERT INTO teacher_students (teacher_user_id, student_id)
                     VALUES (?, ?)
                     ON DUPLICATE KEY UPDATE teacher_user_id = VALUES(teacher_user_id)"
                );
                $stmtMap->execute([(int)$currentUser["user_id"], $studentId]);
            }

            attach_student_to_school_year($studentId, $schoolYear, $gradeLevel, $section !== "" ? $section : null, $strandNorm);

            $pdo->commit();
            audit_log("student_data_save", "success", "student", $studentId, "Saved student data entry.", [
                "school_year" => $schoolYear,
                "grade_level" => $gradeLevel,
                "section" => $section,
            ]);
            $success = t("data_entry.success_saved");
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            audit_log("student_data_save", "failure", "student", null, "Failed to save data entry.", [
                "name" => $name,
                "grade_level" => $gradeLevel,
                "error" => $e->getMessage(),
            ]);
            $error = student_save_error_message($e);
        }
    }
    }
}

$sqlRows = "SELECT s.student_id, s.lrn, s.name, s.grade_level, s.strand, s.section, s.risk_level,
            (SELECT COUNT(*) FROM student_flags sf WHERE sf.student_id = s.student_id AND sf.is_active = 1) AS flag_count,
            sb.school_year
     FROM students s
     LEFT JOIN (
        SELECT b1.student_id, b1.school_year
        FROM student_batches b1
        INNER JOIN (
          SELECT student_id, MAX(batch_id) AS max_batch_id
          FROM student_batches
          GROUP BY student_id
        ) latest ON latest.student_id = b1.student_id AND latest.max_batch_id = b1.batch_id
     ) sb ON sb.student_id = s.student_id
     WHERE " . students_non_archived_sql("s") . "
     ";

$sqlRows .= " ORDER BY s.student_id DESC LIMIT 200";
$rows = db()->query($sqlRows)->fetchAll();

$teacherStudentSet = [];
if ($isTeacher && $rows) {
    $ids = [];
    foreach ($rows as $r) {
        $sid = (int)($r["student_id"] ?? 0);
        if ($sid > 0) $ids[$sid] = true;
    }
    $ids = array_keys($ids);
    if ($ids) {
        $ph = implode(",", array_fill(0, count($ids), "?"));
        $stmtTS = db()->prepare(
            "SELECT student_id
             FROM teacher_students
             WHERE teacher_user_id = ? AND student_id IN ({$ph})"
        );
        $stmtTS->execute(array_merge([(int)($currentUser["user_id"] ?? 0)], $ids));
        foreach ($stmtTS->fetchAll() as $tr) {
            $teacherStudentSet[(int)($tr["student_id"] ?? 0)] = true;
        }
    }
}

$sectionOptions = list_sections(null);
$sectionOptionsAll = $sectionOptions;

$dgJs = [
    "selectedTpl" => tr("data_entry.js_selected", ["count" => "__DG_COUNT__"]),
    "confirmRemove" => t("data_entry.confirm_remove_class"),
    "fetchingHtml" => '<div class="text-muted small">' . h_t("data_entry.fetching_profile") . '</div>',
    "failHtml" => '<div class="text-danger small">' . h_t("data_entry.js_profile_load_fail") . '</div>',
    "defaultStudent" => t("common.student"),
];

ob_start();
$defaultSchoolYear = default_current_school_year();
?>
<div class="dg-page-fill">
  <div class="dg-page-header mb-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
      <div>
        <h3 class="mb-0"><?= h_t("data_entry.title") ?></h3>
        <div class="text-muted small"><?= h_t("data_entry.subtitle") ?></div>
      </div>
    </div>
  </div>

  <div class="row g-3 dg-page-split dg-flex-1">
  <div class="col-lg-5">
    <div class="card shadow-sm">
      <div class="card-body">
        <h5 class="mb-2"><?= h_t("data_entry.encode_title") ?></h5>
        <div class="text-muted small mb-3"><?= h_t("data_entry.encode_hint") ?></div>
        <?php if ($error): ?>
          <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
          <div class="alert alert-success"><?= htmlspecialchars($success, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></div>
        <?php endif; ?>
        <form method="post" action="data-entry">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="encode_student" />
          <input type="hidden" name="school_year" value="<?= htmlspecialchars($defaultSchoolYear, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" />
          <div class="mb-2">
            <label class="form-label"><?= h_t("data_entry.lrn") ?></label>
            <input class="form-control" name="lrn" placeholder="<?= h_t("data_entry.lrn_ph") ?>" required />
          </div>
          <div class="mb-2">
            <label class="form-label"><?= h_t("data_entry.student_name") ?></label>
            <div class="row g-2">
              <div class="col-md-4">
                <input class="form-control" name="first_name" placeholder="<?= h_t("data_entry.first_name") ?>" required />
              </div>
              <div class="col-md-4">
                <input class="form-control" name="middle_name" placeholder="<?= h_t("data_entry.middle_name") ?>" />
              </div>
              <div class="col-md-4">
                <input class="form-control" name="last_name" placeholder="<?= h_t("data_entry.last_name") ?>" required />
              </div>
            </div>
          </div>
          <div class="mb-2">
            <label class="form-label"><?= h_t("data_entry.grade_level") ?></label>
            <select class="form-select" name="grade_level" id="dgDataEntryGrade" required>
              <option value=""><?= h_t("data_entry.select_grade") ?></option>
              <optgroup label="<?= htmlspecialchars(t("data_entry.optgroup_jhs"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>">
                <?php foreach (["7", "8", "9", "10"] as $gn): ?>
                <option value="<?= htmlspecialchars($gn, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>"><?= htmlspecialchars(tr("data_entry.grade_n", ["n" => $gn]), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></option>
                <?php endforeach; ?>
              </optgroup>
              <optgroup label="<?= htmlspecialchars(t("data_entry.optgroup_shs"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>">
                <?php foreach (["11", "12"] as $gn): ?>
                <option value="<?= htmlspecialchars($gn, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>"><?= htmlspecialchars(tr("data_entry.grade_n", ["n" => $gn]), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></option>
                <?php endforeach; ?>
              </optgroup>
            </select>
          </div>
          <div class="mb-2 d-none" id="dgDataEntryStrandRow">
            <label class="form-label"><?= h_t("data_entry.strand") ?></label>
            <select class="form-select" name="strand" id="dgDataEntryStrand">
              <option value=""><?= h_t("data_entry.select_strand") ?></option>
              <?php curriculum_echo_shs_strand_options(); ?>
            </select>
            <div class="form-text"><?= h_t("data_entry.strand_hint") ?></div>
          </div>
          <div class="mb-2 d-none" id="dgDataEntrySectionWrap">
            <label class="form-label"><?= h_t("data_entry.section") ?></label>
            <?php if ($sectionOptions): ?>
              <select class="form-select" name="section" id="dgDataEntrySection" required>
                <option value=""><?= h_t("data_entry.select_section") ?></option>
                <?php foreach ($sectionOptionsAll as $sec): ?>
                  <?php
                    $secGl = section_grade_level_for_name($sec) ?? "";
                    $secSt = section_infer_strand_from_name($sec) ?? "";
                  ?>
                  <option value="<?= htmlspecialchars($sec, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" data-section-grade="<?= htmlspecialchars($secGl, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" data-section-strand="<?= htmlspecialchars($secSt, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>">
                    <?= htmlspecialchars(section_display_short($sec), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>
                  </option>
                <?php endforeach; ?>
              </select>
            <?php else: ?>
              <input class="form-control" name="section" placeholder="<?= h_t("data_entry.section_disabled_ph") ?>" disabled />
              <div class="form-text text-danger"><?= h_t("data_entry.no_sections") ?></div>
            <?php endif; ?>
          </div>
          <button class="btn btn-dark w-100 mt-2" type="submit"><?= h_t("common.save") ?></button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-lg-7">
    <div class="card shadow-sm dg-flex-1">
      <div class="card-body d-flex flex-column dg-flex-1">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <div class="fw-semibold"><?= h_t("data_entry.enrolled_title") ?></div>
          <div class="text-muted small"><?= h_t("data_entry.enrolled_hint") ?></div>
        </div>
        <form id="dgImportStudentsForm" method="post" action="data-entry" class="d-flex flex-wrap gap-2 align-items-center mb-2">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="import_students" />
          <button class="btn btn-sm btn-outline-dark" type="submit" id="dgImportStudentsBtn" disabled><?= h_t("data_entry.add_selected") ?></button>
          <div class="text-muted small" id="dgImportStudentsCount"><?= htmlspecialchars(tr("data_entry.js_selected", ["count" => "0"]), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></div>
        </form>
        <div class="mb-2">
          <input id="studentTableSearch" class="form-control form-control-sm" placeholder="<?= h_t("data_entry.table_search_ph") ?>" />
        </div>
        <div class="table-responsive dg-table-scroll">
          <table class="table table-sm align-middle dg-table">
            <thead>
              <tr>
                <th style="width:38px">
                  <input class="form-check-input" type="checkbox" id="dgSelectAllStudents" />
                </th>
                <th><?= h_t("data_entry.th_id") ?></th>
                <th><?= h_t("data_entry.th_lrn") ?></th>
                <th><?= h_t("data_entry.th_name") ?></th>
                <th><?= h_t("data_entry.th_grade_level") ?></th>
                <th><?= h_t("data_entry.th_school_year") ?></th>
                <th><?= h_t("data_entry.th_section") ?></th>
                <th><?= h_t("data_entry.th_risk") ?></th>
                <th><?= h_t("data_entry.th_flags") ?></th>
                <th><?= h_t("data_entry.th_profile") ?></th>
                <th><?= h_t("data_entry.th_referral") ?></th>
                <th><?= h_t("data_entry.th_class") ?></th>
              </tr>
            </thead>
            <tbody id="studentTableBody">
              <?php foreach ($rows as $r): ?>
                <tr>
                  <td>
                    <input class="form-check-input dg-student-pick" type="checkbox" form="dgImportStudentsForm" name="student_ids[]" value="<?= (int)$r["student_id"] ?>" />
                  </td>
                  <td><?= (int)$r["student_id"] ?></td>
                  <td class="text-muted small"><?= htmlspecialchars((string)($r["lrn"] ?? ""), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></td>
                  <td>
                    <a
                      href="student?id=<?= (int)$r["student_id"] ?>"
                      class="fw-semibold text-decoration-none student-link"
                      data-student-id="<?= (int)$r["student_id"] ?>"
                      data-student-name="<?= htmlspecialchars($r["name"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>"
                    >
                      <?= htmlspecialchars($r["name"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>
                    </a>
                  </td>
                  <td>
                    <?= htmlspecialchars($r["grade_level"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>
                    <?php if (!empty($r["strand"]) && curriculum_is_senior_high_grade((string)$r["grade_level"])): ?>
                      <div class="text-muted small"><?= htmlspecialchars((string)$r["strand"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></div>
                    <?php endif; ?>
                  </td>
                  <td class="small"><?= htmlspecialchars((string)($r["school_year"] ?? "-"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></td>
                  <td><?= htmlspecialchars($r["section"] ? section_display_short((string)$r["section"]) : "-", ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></td>
                  <td>
                    <?php
                      $lvl = $r["risk_level"] ?: "Low";
                      $chipClass = "dg-risk-low";
                      if ($lvl === "Moderate") $chipClass = "dg-risk-mod";
                      if ($lvl === "High") $chipClass = "dg-risk-high";
                      $lvlKey = $lvl === "High" ? "risk.high" : ($lvl === "Moderate" ? "risk.moderate" : "risk.low");
                      $lvlLabel = t($lvlKey);
                    ?>
                    <span class="dg-risk-chip <?= $chipClass ?>"><span class="dot"></span><?= htmlspecialchars($lvlLabel, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></span>
                  </td>
                  <td>
                    <?php if ((int)($r["flag_count"] ?? 0) > 0): ?>
                      <span class="badge text-bg-danger"><?= htmlspecialchars(tr("data_entry.flags_count", ["count" => (string)(int)$r["flag_count"]]), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></span>
                    <?php else: ?>
                      <span class="text-muted small">-</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <button class="btn btn-sm btn-outline-secondary" type="button" onclick="window.location.href='student?id=<?= (int)$r["student_id"] ?>'"><?= h_t("common.open") ?></button>
                  </td>
                  <td>
                    <?php
                      $sid = (int)($r["student_id"] ?? 0);
                      $canReferral = !$isTeacher || isset($teacherStudentSet[$sid]);
                    ?>
                    <?php if ($canReferral): ?>
                      <button class="btn btn-sm btn-outline-dark" type="button" onclick="window.location.href='teacher-referral?student_id=<?= (int)$sid ?>'"><?= h_t("data_entry.manual_referral") ?></button>
                    <?php else: ?>
                      <span
                        class="d-inline-block"
                        tabindex="0"
                        data-bs-toggle="tooltip"
                        data-bs-title="<?= htmlspecialchars(t("data_entry.referral_need_class_tooltip"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>"
                      >
                        <button class="btn btn-sm btn-outline-secondary" type="button" disabled>
                          <?= h_t("data_entry.manual_referral") ?>
                        </button>
                      </span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <?php
                      $sid = (int)($r["student_id"] ?? 0);
                      $inClass = $isTeacher && isset($teacherStudentSet[$sid]);
                    ?>
                    <?php if ($inClass): ?>
                      <form method="post" action="data-entry" class="d-inline" onsubmit="return confirm(<?= json_encode(t("data_entry.confirm_remove_class"), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>);">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="remove_from_class" />
                        <input type="hidden" name="student_id" value="<?= (int)$sid ?>" />
                        <button class="btn btn-sm btn-outline-danger" type="submit"><?= h_t("common.remove") ?></button>
                      </form>
                    <?php else: ?>
                      <span class="text-muted small">—</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>
<?php /* end .dg-page-fill */ ?>

<!-- Student profile modal -->
<div class="modal fade" id="studentModal" tabindex="-1" aria-labelledby="studentModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <div>
          <div class="small text-muted"><?= h_t("data_entry.profile_modal_title") ?></div>
          <div class="h5 mb-0" id="studentModalLabel"><?= h_t("data_entry.modal_loading") ?></div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= htmlspecialchars(t("legal.close_aria"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>"></button>
      </div>
      <div class="modal-body">
        <div class="text-muted small"><?= h_t("data_entry.fetching_profile") ?></div>
      </div>
    </div>
  </div>
</div>

<script>
  (function(){
    const DG = <?= json_encode($dgJs, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
    const modalEl = document.getElementById('studentModal');
    const hasBootstrap = typeof window.bootstrap !== 'undefined';
    const modal = hasBootstrap ? new bootstrap.Modal(modalEl) : null;
    const modalLabel = document.getElementById('studentModalLabel');

    function setBody(html){
      const body = modalEl.querySelector('.modal-body');
      body.innerHTML = html;
    }

    document.querySelectorAll('.student-link').forEach((link) => {
      link.addEventListener('click', async (e) => {
        if (!hasBootstrap) {
          return; // fallback to normal link navigation
        }
        e.preventDefault();
        const id = link.getAttribute('data-student-id');
        const name = link.getAttribute('data-student-name') || DG.defaultStudent;
        modalLabel.textContent = name;
        setBody(DG.fetchingHtml);
        modal.show();

        try{
          const res = await fetch('student-modal?id=' + encodeURIComponent(id), { credentials: 'same-origin' });
          const html = await res.text();
          setBody(html);
        } catch(e){
          setBody(DG.failHtml);
        }
      });
    });

    const searchInput = document.getElementById('studentTableSearch');
    const tbody = document.getElementById('studentTableBody');
    if (searchInput && tbody) {
      searchInput.addEventListener('input', () => {
        const q = searchInput.value.trim().toLowerCase();
        tbody.querySelectorAll('tr').forEach((row) => {
          const text = row.textContent.toLowerCase();
          row.style.display = q === '' || text.includes(q) ? '' : 'none';
        });
      });
    }

    const gEl = document.getElementById("dgDataEntryGrade");
    const sEl = document.getElementById("dgDataEntrySection");
    const sectionWrap = document.getElementById("dgDataEntrySectionWrap");
    const strandRow = document.getElementById("dgDataEntryStrandRow");
    const strandSel = document.getElementById("dgDataEntryStrand");
    if (gEl && sectionWrap) {
      function canon(v) {
        if (!v) return "";
        const s = String(v);
        if (/^\d{1,2}$/.test(s)) {
          const n = parseInt(s, 10);
          if (n >= 7 && n <= 12) return "Grade " + n;
        }
        return s;
      }
      function isShsGrade(g) {
        return g === "Grade 11" || g === "Grade 12";
      }
      function syncStrandRow() {
        if (!strandRow || !strandSel) return;
        const g = canon(gEl.value);
        const shs = isShsGrade(g);
        strandRow.classList.toggle("d-none", !shs);
        strandSel.required = shs;
        if (!shs) strandSel.value = "";
      }
      function syncSections() {
        const g = canon(gEl.value);
        const shs = isShsGrade(g);
        const strand = strandSel && shs ? String(strandSel.value || "") : "";
        sectionWrap.classList.toggle("d-none", !g);
        if (sEl && sEl.options) {
          for (let i = 0; i < sEl.options.length; i++) {
            const opt = sEl.options[i];
            if (!opt.value) {
              opt.hidden = false;
              continue;
            }
            const og = opt.getAttribute("data-section-grade") || "";
            const hideByGrade = !g ? !!opt.value : !!(og && og !== g);
            const st = opt.getAttribute("data-section-strand") || "";
            const hideByStrand = !!(strand && shs && st !== strand);
            opt.hidden = hideByGrade || hideByStrand;
          }
          const sel = sEl.selectedOptions[0];
          if (sel && sel.hidden) sEl.value = "";
          sEl.disabled = !g;
        }
        syncStrandRow();
      }
      gEl.addEventListener("change", syncSections);
      if (strandSel) strandSel.addEventListener("change", syncSections);
      syncSections();
    }
  })();

  (function(){
    const DG = <?= json_encode($dgJs, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
    const form = document.getElementById('dgImportStudentsForm');
    const btn = document.getElementById('dgImportStudentsBtn');
    const countEl = document.getElementById('dgImportStudentsCount');
    const all = document.getElementById('dgSelectAllStudents');
    if (!form || !btn || !countEl) return;
    function selectedCount(){
      return document.querySelectorAll('input.dg-student-pick:checked').length;
    }
    function sync(){
      const n = selectedCount();
      btn.disabled = n <= 0;
      countEl.textContent = DG.selectedTpl.replace('__DG_COUNT__', String(n));
      if (all) {
        const picks = document.querySelectorAll('input.dg-student-pick');
        const total = picks.length;
        all.checked = total > 0 && n === total;
        all.indeterminate = n > 0 && n < total;
      }
    }
    document.querySelectorAll('input.dg-student-pick').forEach((el) => el.addEventListener('change', sync));
    if (all) {
      all.addEventListener('change', () => {
        const on = all.checked;
        document.querySelectorAll('input.dg-student-pick').forEach((el) => { el.checked = on; });
        sync();
      });
    }
    form.addEventListener('submit', (e) => {
      if (selectedCount() <= 0) {
        e.preventDefault();
      }
    });
    sync();
  })();

  (function () {
    if (!window.bootstrap) return;
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) {
      try { bootstrap.Tooltip.getOrCreateInstance(el); } catch (e) {}
    });
  })();
</script>

<?php
$content = ob_get_clean();
require __DIR__ . "/_layout.php";

