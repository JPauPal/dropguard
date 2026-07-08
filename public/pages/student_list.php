<?php
declare(strict_types=1);

require_once __DIR__ . "/../../app/auth.php";
require_once __DIR__ . "/../../app/db.php";
require_once __DIR__ . "/../../app/marks.php";
require_once __DIR__ . "/../../app/sections.php";
require_once __DIR__ . "/../../app/batches.php";
require_once __DIR__ . "/../../app/curriculum.php";
require_once __DIR__ . "/../../app/students.php";

require_login();
ensure_students_face_column();
ensure_students_archived_column();
ensure_student_marks_table();
ensure_student_sections();
ensure_student_batches_table();

$user = current_user();
$isAdmin = (($user["role"] ?? "") === "Admin");
$role = (string)($user["role"] ?? "");
$canFlag = in_array($role, ["Counselor", "Admin"], true);

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

/** @return array{first:string,middle:string,last:string} */
function dg_split_full_name(string $full): array
{
    $full = trim(preg_replace('/\s+/', ' ', $full) ?? "");
    if ($full === "") return ["first" => "", "middle" => "", "last" => ""];
    $parts = preg_split('/\s+/', $full) ?: [];
    if (count($parts) === 1) return ["first" => $parts[0], "middle" => "", "last" => ""];
    $first = (string)array_shift($parts);
    $last = (string)array_pop($parts);
    $middle = trim(implode(' ', $parts));
    return ["first" => $first, "middle" => $middle, "last" => $last];
}

if (($_SERVER["REQUEST_METHOD"] ?? "GET") === "POST") {
    require_valid_csrf_token();
    $action = trim((string)($_POST["action"] ?? ""));
    $isArchiveAction = ($action === "archive_student" || $action === "unarchive_student");
    // Counselors may archive/unarchive from this list; all other POST actions stay Admin-only.
    if (!$isAdmin) {
        if (!$isArchiveAction || $role !== "Counselor") {
            http_response_code(403);
            echo htmlspecialchars(t("http.forbidden"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");
            exit;
        }
    }

    if ($action === "add_student") {
        $dgAjaxAddStudent = strtolower((string)($_SERVER["HTTP_X_REQUESTED_WITH"] ?? "")) === "xmlhttprequest";
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
        } elseif (!is_valid_school_year_sequence($schoolYear)) {
            $error = t("data_entry.err_school_year");
        } elseif (!preg_match('/^[A-Za-z0-9\\-]{4,30}$/', $lrn)) {
            $error = t("data_entry.err_lrn_format");
        } elseif (!in_array($section, $allowedSections, true)) {
            $error = t("data_entry.err_section_invalid");
        } elseif ($section !== "" && !section_matches_grade_filter($section, $gradeLevel)) {
            $error = t("data_entry.err_section_grade");
        } elseif (curriculum_is_senior_high_grade($gradeLevel) && ($strandNorm ?? "") !== "" && $section !== "" && !section_matches_strand_filter($section, (string)$strandNorm)) {
            $error = t("data_entry.err_section_strand");
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

                attach_student_to_school_year($studentId, $schoolYear, $gradeLevel, $section !== "" ? $section : null, $strandNorm);

                $pdo->commit();
                audit_log("student_data_save", "success", "student", $studentId, "Admin added student (All Students).", [
                    "school_year" => $schoolYear,
                    "grade_level" => $gradeLevel,
                    "section" => $section,
                ]);
                $success = t("data_entry.success_saved");
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error = student_save_error_message($e);
            }
        }
        if ($dgAjaxAddStudent) {
            header("Content-Type: application/json; charset=utf-8");
            if ($error !== null) {
                echo json_encode(["ok" => false, "error" => $error], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            } elseif ($success !== null) {
                echo json_encode(["ok" => true, "message" => $success], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            } else {
                echo json_encode(["ok" => false, "error" => t("student_list.err_generic_save")], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            }
            exit;
        }
    } elseif ($action === "update_student") {
        $studentId = (int)($_POST["student_id"] ?? 0);
        $firstName = trim((string)($_POST["first_name"] ?? ""));
        $middleName = trim((string)($_POST["middle_name"] ?? ""));
        $lastName = trim((string)($_POST["last_name"] ?? ""));
        $name = dg_build_full_name($firstName, $middleName, $lastName);
        $lrn = trim((string)($_POST["lrn"] ?? ""));
        $gradeLevel = trim((string)($_POST["grade_level"] ?? ""));
        $strandRaw = trim((string)($_POST["strand"] ?? ""));
        [$strandNorm, $strandErr] = curriculum_validate_strand_input($gradeLevel !== "" ? $gradeLevel : null, $strandRaw);
        $section = trim((string)($_POST["section"] ?? ""));
        $schoolYear = trim((string)($_POST["school_year"] ?? ""));
        $gpa = (float)($_POST["gpa"] ?? 0);

        $allowedGrades = ["Grade 7", "Grade 8", "Grade 9", "Grade 10", "Grade 11", "Grade 12"];
        $allowedSections = list_sections(null);
        if ($studentId <= 0) {
            $error = t("data_entry.err_invalid_student");
        } elseif ($firstName === "" || $lastName === "") {
            $error = t("data_entry.err_first_last");
        } elseif ($lrn === "") {
            $error = t("data_entry.err_lrn_required");
        } elseif ($strandErr !== null) {
            $error = $strandErr;
        } elseif (!in_array($gradeLevel, $allowedGrades, true)) {
            $error = t("student_list.err_invalid_grade");
        } elseif ($section === "") {
            $error = t("data_entry.err_section_required");
        } elseif ($schoolYear !== "" && !is_valid_school_year_sequence($schoolYear)) {
            $error = t("data_entry.err_school_year");
        } elseif (!preg_match('/^[A-Za-z0-9\\-]{4,30}$/', $lrn)) {
            $error = t("data_entry.err_lrn_format");
        } elseif (!in_array($section, $allowedSections, true)) {
            $error = t("data_entry.err_section_invalid");
        } elseif ($section !== "" && !section_matches_grade_filter($section, $gradeLevel)) {
            $error = t("data_entry.err_section_grade");
        } elseif (curriculum_is_senior_high_grade($gradeLevel) && ($strandNorm ?? "") !== "" && $section !== "" && !section_matches_strand_filter($section, (string)$strandNorm)) {
            $error = t("data_entry.err_section_strand");
        } elseif ($gpa < 0 || $gpa > 100) {
            $error = t("student_list.err_gpa_range");
        } else {
            $pdo = db();
            $pdo->beginTransaction();
            try {
                // Ensure LRN uniqueness (except current student)
                if ($lrn !== "") {
                    $stmtDup = $pdo->prepare("SELECT student_id FROM students WHERE lrn = ? AND student_id <> ? LIMIT 1");
                    $stmtDup->execute([$lrn, $studentId]);
                    if ($stmtDup->fetch()) {
                        throw new RuntimeException("LRN already exists.");
                    }
                }

                $stmtUp = $pdo->prepare("UPDATE students SET lrn = ?, name = ?, grade_level = ?, strand = ?, section = NULLIF(?, ''), gpa = ? WHERE student_id = ?");
                $stmtUp->execute([$lrn, $name, $gradeLevel, $strandNorm, $section, $gpa, $studentId]);

                // Update a specific school year mapping (or fall back to latest).
                if ($schoolYear !== "") {
                    $stmtUpsertBatch = $pdo->prepare(
                        "INSERT INTO student_batches (student_id, school_year, grade_level, section, strand, is_active)
                         VALUES (?,?,?,?,?,1)
                         ON DUPLICATE KEY UPDATE grade_level = VALUES(grade_level), section = VALUES(section), strand = VALUES(strand)"
                    );
                    $stmtUpsertBatch->execute([$studentId, $schoolYear, $gradeLevel, ($section !== "" ? $section : null), $strandNorm]);
                } else {
                    $stmtLatest = $pdo->prepare(
                        "SELECT batch_id FROM student_batches WHERE student_id = ? ORDER BY batch_id DESC LIMIT 1"
                    );
                    $stmtLatest->execute([$studentId]);
                    $latest = $stmtLatest->fetch();
                    if ($latest) {
                        $stmtUpB = $pdo->prepare("UPDATE student_batches SET grade_level = ?, strand = ?, section = NULLIF(?, '') WHERE batch_id = ?");
                        $stmtUpB->execute([$gradeLevel, $strandNorm, $section, (int)$latest["batch_id"]]);
                    }
                }

                // Keep latest performance GPA in sync (if any)
                $stmtLatestPerf = $pdo->prepare("SELECT performance_id FROM performance WHERE student_id = ? ORDER BY quarter DESC LIMIT 1");
                $stmtLatestPerf->execute([$studentId]);
                $lp = $stmtLatestPerf->fetch();
                if ($lp) {
                    $stmtUpPerf = $pdo->prepare("UPDATE performance SET gpa = ? WHERE performance_id = ?");
                    $stmtUpPerf->execute([$gpa, (int)$lp["performance_id"]]);
                }

                $pdo->commit();
                audit_log("student_update", "success", "student", $studentId, "Updated student profile fields.", [
                    "lrn" => $lrn,
                    "name" => $name,
                    "grade_level" => $gradeLevel,
                    "section" => $section,
                    "school_year" => $schoolYear,
                    "gpa" => $gpa,
                ]);
                $success = t("student_list.success_updated");
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error = student_save_error_message($e);
            }
        }
        if ($error === null) {
            $redirect = trim((string)($_POST["redirect"] ?? ""));
            if ($redirect !== "") {
                header("Location: " . $redirect);
                exit;
            }
        }
    } elseif ($action === "delete_student") {
        $studentId = (int)($_POST["student_id"] ?? 0);
        $redirect = trim((string)($_POST["redirect"] ?? ""));
        if ($studentId <= 0) {
            $error = t("data_entry.err_invalid_student");
        } else {
            $stmtDel = db()->prepare("SELECT student_id, name, lrn, face_image_path FROM students WHERE student_id = ? LIMIT 1");
            $stmtDel->execute([$studentId]);
            $delRow = $stmtDel->fetch();
            if (!$delRow) {
                $error = t("student_list.err_not_found");
            } else {
                try {
                    $relFace = trim((string)($delRow["face_image_path"] ?? ""));
                    if ($relFace !== "") {
                        $pubRoot = realpath(__DIR__ . "/../public");
                        if ($pubRoot !== false) {
                            $absFace = $pubRoot . DIRECTORY_SEPARATOR . str_replace(["/", "\\"], DIRECTORY_SEPARATOR, $relFace);
                            if (is_file($absFace)) {
                                @unlink($absFace);
                            }
                        }
                    }
                    db()->prepare("DELETE FROM students WHERE student_id = ?")->execute([$studentId]);
                    audit_log("student_delete", "success", "student", $studentId, "Admin deleted student record.", [
                        "name" => (string)$delRow["name"],
                        "lrn" => (string)($delRow["lrn"] ?? ""),
                    ]);
                    start_app_session();
                    $_SESSION["flash"] = tr("student_list.flash_deleted", ["name" => (string)$delRow["name"]]);
                    $safeLoc = "student-list";
                    if ($redirect !== "" && preg_match('#^student-list(\\?.*)?$#', $redirect)) {
                        $safeLoc = $redirect;
                    }
                    header("Location: " . $safeLoc);
                    exit;
                } catch (Throwable $e) {
                    $error = tr("student_list.err_delete", ["reason" => $e->getMessage()]);
                }
            }
        }
    } elseif ($action === "archive_student" || $action === "unarchive_student") {
        $sid = (int)($_POST["student_id"] ?? 0);
        if ($sid <= 0) {
            $error = t("data_entry.err_invalid_student");
        } else {
            $val = $action === "archive_student" ? 1 : 0;
            db()->prepare("UPDATE students SET is_archived = ? WHERE student_id = ?")->execute([$val, $sid]);
            audit_log($val ? "student_archive" : "student_unarchive", "success", "student", $sid, $val ? "Student archived." : "Student unarchived.");
            $success = $val ? t("student.archive_success") : t("student.unarchive_success");
        }
    }
}

$search = trim((string)($_GET["search"] ?? ""));
$gradeFilter = trim((string)($_GET["grade_level"] ?? ""));
$sectionFilter = trim((string)($_GET["section"] ?? ""));
$schoolYearFilter = trim((string)($_GET["school_year"] ?? ""));
$strandFilter = trim((string)($_GET["strand"] ?? ""));
$openStudentId = (int)($_GET["open_student_id"] ?? 0);
$includeArchived = (($_GET["include_archived"] ?? "") === "1");
$canArchiveStudent = in_array($role, ["Counselor", "Admin"], true);

if (!curriculum_is_senior_high_grade($gradeFilter !== "" ? $gradeFilter : null)) {
    $strandFilter = "";
}
if ($strandFilter !== "" && !in_array($strandFilter, curriculum_shs_strands(), true)) {
    $strandFilter = "";
}

if ($gradeFilter !== "" && $sectionFilter !== "" && !section_matches_grade_filter($sectionFilter, $gradeFilter)) {
    $sectionFilter = "";
}
if ($strandFilter !== "" && curriculum_is_senior_high_grade($gradeFilter !== "" ? $gradeFilter : null) && $sectionFilter !== "" && !section_matches_strand_filter($sectionFilter, $strandFilter)) {
    $sectionFilter = "";
}

$clauses = [];
$params = [];
if ($gradeFilter !== "") {
    $clauses[] = "s.grade_level = ?";
    $params[] = $gradeFilter;
}
if ($sectionFilter !== "") {
    $clauses[] = "s.section = ?";
    $params[] = $sectionFilter;
}
if ($search !== "") {
    $clauses[] = "(s.name LIKE ? OR s.lrn LIKE ?)";
    $params[] = "%" . $search . "%";
    $params[] = "%" . $search . "%";
}
if ($schoolYearFilter !== "") {
    $clauses[] = "EXISTS (SELECT 1 FROM student_batches sb WHERE sb.student_id = s.student_id AND sb.school_year = ?)";
    $params[] = $schoolYearFilter;
}
if ($strandFilter !== "" && curriculum_is_senior_high_grade($gradeFilter !== "" ? $gradeFilter : null)) {
    $clauses[] = "s.strand = ?";
    $params[] = $strandFilter;
}
if (!$includeArchived) {
    $clauses[] = students_non_archived_sql("s");
}
$where = $clauses ? ("WHERE " . implode(" AND ", $clauses)) : "";

$stmt = db()->prepare(
    "SELECT s.student_id, s.lrn, s.name, s.grade_level, s.strand, s.section, s.gpa, s.absences, s.risk_score, s.risk_level,
            s.is_archived,
            (SELECT sb2.school_year FROM student_batches sb2 WHERE sb2.student_id = s.student_id ORDER BY sb2.batch_id DESC LIMIT 1) AS latest_school_year
     FROM students s
     {$where}
     ORDER BY s.name ASC
     LIMIT 500"
);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$studentIds = array_map(static fn($r) => (int)$r["student_id"], $rows);
$flagsByStudent = [];
if ($studentIds) {
    $ph = implode(",", array_fill(0, count($studentIds), "?"));
    $fStmt = db()->prepare(
        "SELECT flag_id, student_id, issue_type, severity, note
         FROM student_flags
         WHERE student_id IN ({$ph}) AND is_active = 1
         ORDER BY created_at DESC"
    );
    $fStmt->execute($studentIds);
    foreach ($fStmt->fetchAll() as $fl) {
        $sid = (int)$fl["student_id"];
        if (!isset($flagsByStudent[$sid])) {
            $flagsByStudent[$sid] = [];
        }
        $flagsByStudent[$sid][] = $fl;
    }
}

$gradeLevels = curriculum_sort_rows_by_grade_level(
    db()->query(
        "SELECT DISTINCT grade_level FROM students" . ($includeArchived ? "" : " WHERE COALESCE(is_archived,0) = 0")
    )->fetchAll()
);
$sectionsAll = list_sections(null);
$schoolYears = db()->query("SELECT DISTINCT school_year FROM student_batches ORDER BY school_year DESC")->fetchAll();

$listRedirectParts = [
    "grade_level" => $gradeFilter,
    "section" => $sectionFilter,
    "strand" => $strandFilter,
    "school_year" => $schoolYearFilter,
    "search" => $search,
    "include_archived" => $includeArchived ? "1" : "",
];
if ($openStudentId > 0) {
    $listRedirectParts["open_student_id"] = (string)$openStudentId;
}
$listQs = http_build_query(array_filter($listRedirectParts, static fn($v) => $v !== null && $v !== ""));
$listRedirect = $listQs !== "" ? "student-list?" . $listQs : "student-list";

// Default school year for Add Student form
$defaultSchoolYear = default_current_school_year();

$slJs = [
    "defaultStudent" => t("common.student"),
    "fetchingHtml" => '<div class="text-muted small">' . h_t("data_entry.fetching_profile") . '</div>',
    "failHtml" => '<div class="text-danger small">' . h_t("data_entry.js_profile_load_fail") . '</div>',
    "ajaxSession" => t("student_list.ajax_session_failed"),
    "ajaxUnexpected" => t("student_list.ajax_unexpected"),
    "ajaxRequestFailed" => t("student_list.ajax_request_failed"),
    "ajaxNetwork" => t("student_list.ajax_network"),
    "savedMsg" => t("data_entry.success_saved"),
    "savingBtn" => t("common.saving"),
];

start_app_session();
$flash = $_SESSION["flash"] ?? null;
unset($_SESSION["flash"]);

ob_start();
?>
<div class="dg-page-fill">
<div id="dgToastStack" class="dg-toast-stack" aria-live="polite" aria-atomic="true"></div>
<?php if ($flash): ?>
  <div class="alert alert-info"><?= htmlspecialchars((string)$flash, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></div>
<?php endif; ?>
<?php if ($success): ?>
  <div class="alert alert-success"><?= htmlspecialchars((string)$success, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></div>
<?php endif; ?>
<?php if ($error): ?>
  <div class="alert alert-danger"><?= htmlspecialchars((string)$error, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></div>
<?php endif; ?>

<div class="dg-page-header mb-3">
  <h3 class="mb-0"><?= h_t("student_list.title") ?></h3>
  <div class="text-muted small"><?= h_t("student_list.subtitle") ?></div>
</div>

<?php if ($isAdmin): ?>
  <div class="card shadow-sm mb-3">
    <div class="card-body">
      <div class="fw-semibold mb-2"><?= h_t("student_list.add_title") ?></div>
        <form id="dgAddStudentForm" method="post" action="student-list" class="row g-2 align-items-end">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="add_student" />
          <input type="hidden" name="school_year" value="<?= htmlspecialchars($defaultSchoolYear, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" />
          <div class="col-md-3">
            <label class="form-label mb-1"><?= h_t("data_entry.lrn") ?></label>
            <input class="form-control" name="lrn" placeholder="<?= h_t("data_entry.lrn_ph") ?>" required />
          </div>
          <div class="col-md-3">
            <label class="form-label mb-1"><?= h_t("data_entry.student_name") ?></label>
            <div class="row g-1">
              <div class="col-4">
                <input class="form-control" name="first_name" placeholder="<?= h_t("data_entry.first_name") ?>" required />
              </div>
              <div class="col-4">
                <input class="form-control" name="middle_name" placeholder="<?= h_t("data_entry.middle_name") ?>" />
              </div>
              <div class="col-4">
                <input class="form-control" name="last_name" placeholder="<?= h_t("data_entry.last_name") ?>" required />
              </div>
            </div>
          </div>
          <div class="col-md-2">
            <label class="form-label mb-1"><?= h_t("data_entry.grade_level") ?></label>
            <select class="form-select" name="grade_level" id="dgStudentAddGrade" required>
              <option value=""><?= h_t("common.select") ?></option>
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
          <div class="col-md-2 d-none" id="dgStudentAddStrandRow">
            <label class="form-label mb-1"><?= h_t("data_entry.strand") ?></label>
            <select class="form-select" name="strand" id="dgStudentAddStrand">
              <option value=""><?= h_t("common.select") ?></option>
              <?php curriculum_echo_shs_strand_options(); ?>
            </select>
          </div>
          <div class="col-md-2 d-none" id="dgStudentAddSectionWrap">
            <label class="form-label mb-1"><?= h_t("data_entry.section") ?></label>
            <select class="form-select" name="section" id="dgStudentAddSection" required>
              <option value=""><?= h_t("common.select") ?></option>
              <?php foreach ($sectionsAll as $sec): ?>
                <?php
                  $secGl = section_grade_level_for_name($sec) ?? "";
                  $secSt = section_infer_strand_from_name($sec) ?? "";
                ?>
                <option value="<?= htmlspecialchars($sec, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" data-section-grade="<?= htmlspecialchars($secGl, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" data-section-strand="<?= htmlspecialchars($secSt, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>">
                  <?= htmlspecialchars(section_display_short($sec), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-2">
            <button class="btn btn-dark w-100" type="submit"><?= h_t("common.save") ?></button>
          </div>
      </form>
    </div>
  </div>
<?php endif; ?>

<div class="card shadow-sm mb-3">
  <div class="card-body">
    <form id="dgStudentListFilter" class="row g-2 align-items-end" method="get" action="student-list">
      <?php if ($openStudentId > 0): ?>
        <input type="hidden" name="open_student_id" value="<?= (int)$openStudentId ?>" />
      <?php endif; ?>
      <div class="col-6 col-md-2">
        <label class="form-label mb-1"><?= h_t("dashboard.filter_grade") ?></label>
        <select class="form-select" name="grade_level" id="dgListFilterGrade">
          <option value=""><?= h_t("common.all") ?></option>
          <?php foreach ($gradeLevels as $g): ?>
            <?php $v = (string)($g["grade_level"] ?? ""); ?>
            <option value="<?= htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" <?= $v === $gradeFilter ? "selected" : "" ?>>
              <?= htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-6 col-md-2 <?= curriculum_is_senior_high_grade($gradeFilter !== "" ? $gradeFilter : null) ? "" : "d-none" ?>" id="dgListStrandFilterWrap">
        <label class="form-label mb-1"><?= h_t("dashboard.filter_strand") ?></label>
        <select class="form-select" name="strand" id="dgListFilterStrand" <?= curriculum_is_senior_high_grade($gradeFilter !== "" ? $gradeFilter : null) ? "" : "disabled" ?>>
          <option value=""><?= h_t("common.all") ?></option>
          <?php curriculum_echo_shs_strand_options($strandFilter); ?>
        </select>
      </div>
      <div class="col-12 col-md-3">
        <label class="form-label mb-1"><?= h_t("dashboard.filter_section") ?></label>
        <select class="form-select" name="section" id="dgListFilterSection">
          <option value=""><?= h_t("common.all") ?></option>
          <?php foreach ($sectionsAll as $sec): ?>
            <?php
              $secGl = section_grade_level_for_name($sec) ?? "";
              $secSt = section_infer_strand_from_name($sec) ?? "";
            ?>
            <option
              value="<?= htmlspecialchars($sec, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>"
              data-section-grade="<?= htmlspecialchars($secGl, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>"
              data-section-strand="<?= htmlspecialchars($secSt, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>"
              <?= $sec === $sectionFilter ? "selected" : "" ?>
            ><?= htmlspecialchars(section_display_short($sec), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label mb-1"><?= h_t("dashboard.filter_school_year") ?></label>
        <select class="form-select" name="school_year" id="dgListFilterSchoolYear">
          <option value=""><?= h_t("common.all") ?></option>
          <?php foreach ($schoolYears as $sy): ?>
            <?php $v = (string)($sy["school_year"] ?? ""); ?>
            <option value="<?= htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" <?= $v === $schoolYearFilter ? "selected" : "" ?>>
              <?= htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-12 col-md-3">
        <label class="form-label mb-1"><?= h_t("common.search") ?></label>
        <input class="form-control" name="search" id="dgListFilterSearch" value="<?= htmlspecialchars($search, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" placeholder="<?= h_t("dashboard.search_placeholder") ?>" />
      </div>
      <div class="col-6 col-md-2">
        <button class="btn btn-dark w-100" type="submit"><?= h_t("common.apply_search") ?></button>
      </div>
      <?php if ($canArchiveStudent): ?>
      <div class="col-12 col-md-3 d-flex align-items-end">
        <div class="form-check">
          <input class="form-check-input" type="checkbox" name="include_archived" value="1" id="dgIncludeArchived" <?= $includeArchived ? "checked" : "" ?> />
          <label class="form-check-label small" for="dgIncludeArchived"><?= h_t("student.include_archived") ?></label>
        </div>
      </div>
      <?php endif; ?>
    </form>
    <div class="form-text small mt-1"><?= t("student_list.filter_hint") ?></div>
  </div>
</div>

<div class="card shadow-sm">
  <div class="card-body dg-flex-1 d-flex flex-column">
    <div class="fw-semibold mb-2"><?= htmlspecialchars(tr("student_list.students_count", ["count" => (string)count($rows)]), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></div>
    <div class="table-responsive dg-table-scroll">
      <table class="table table-sm align-middle dg-table">
        <thead>
          <tr>
            <th><?= h_t("student_list.th_student") ?></th>
            <th><?= h_t("data_entry.th_lrn") ?></th>
            <th><?= h_t("student_list.th_grade") ?></th>
            <th><?= h_t("data_entry.strand") ?></th>
            <th><?= h_t("data_entry.th_section") ?></th>
            <th><?= h_t("data_entry.th_school_year") ?></th>
            <th class="text-end"><?= h_t("dashboard.th_gpa") ?></th>
            <th><?= h_t("dashboard.th_academic") ?></th>
            <th class="text-end"><?= h_t("dashboard.th_absences") ?></th>
            <th><?= h_t("data_entry.th_risk") ?></th>
            <?php if ($canFlag): ?>
              <th><?= h_t("student_list.th_active_flags") ?></th>
              <th><?= h_t("issue.add_flag") ?></th>
            <?php endif; ?>
            <th><?= h_t("data_entry.th_profile") ?></th>
            <?php if ($canArchiveStudent): ?><th><?= h_t("student.archive_col") ?></th><?php endif; ?>
            <?php if ($isAdmin): ?><th><?= h_t("common.delete") ?></th><?php endif; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <?php
              $lvl = (string)($r["risk_level"] ?? "Low");
              $chip = $lvl === "High" ? "dg-risk-high" : ($lvl === "Moderate" ? "dg-risk-mod" : "dg-risk-low");
              $sflags = $flagsByStudent[(int)$r["student_id"]] ?? [];
            ?>
            <tr>
              <td>
                <a
                  class="fw-semibold text-decoration-none student-link"
                  href="student?id=<?= (int)$r["student_id"] ?>"
                  data-student-id="<?= (int)$r["student_id"] ?>"
                  data-student-name="<?= htmlspecialchars((string)$r["name"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>"
                >
                  <?= htmlspecialchars((string)$r["name"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>
                </a>
                <?php if (!empty($r["is_archived"])): ?>
                  <span class="badge text-bg-secondary ms-1"><?= h_t("student.archived_badge") ?></span>
                <?php endif; ?>
              </td>
              <td class="small text-muted"><?= htmlspecialchars((string)($r["lrn"] ?? ""), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></td>
              <td><?= htmlspecialchars((string)$r["grade_level"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></td>
              <td class="small"><?= !empty($r["strand"]) ? htmlspecialchars((string)$r["strand"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") : h_t("common.em_dash") ?></td>
              <td><?= htmlspecialchars((string)($r["section"] ?? "-"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></td>
              <td class="small"><?= htmlspecialchars((string)($r["latest_school_year"] ?? "-"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></td>
              <td class="text-end"><?= number_format((float)$r["gpa"], 2) ?></td>
              <td>
                <?php $isFailing = ((float)$r["gpa"] < 75.0); ?>
                <span class="dg-academic-chip <?= $isFailing ? "dg-academic-fail" : "dg-academic-pass" ?>">
                  <?= $isFailing ? h_t("academic.failing") : h_t("academic.passing") ?>
                </span>
              </td>
              <td class="text-end"><?= (int)$r["absences"] ?></td>
              <td><?php
                $lvlKey = $lvl === "High" ? "risk.high" : ($lvl === "Moderate" ? "risk.moderate" : "risk.low");
                $lvlDisp = t($lvlKey);
              ?><span class="dg-risk-chip <?= $chip ?>"><span class="dot"></span><?= htmlspecialchars($lvlDisp, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></span></td>
              <?php if ($canFlag): ?>
                <td>
                  <?php if ($sflags): ?>
                    <?php foreach ($sflags as $fl): ?>
                      <?php $fCol = ($fl["severity"] ?? "Moderate") === "High" ? "danger" : (($fl["severity"] ?? "") === "Moderate" ? "warning" : "success"); ?>
                      <div class="d-inline-flex align-items-center gap-1 mb-1">
                        <?php
                          $itRaw = strtolower((string)($fl["issue_type"] ?? "other"));
                          $itKey = "issue." . $itRaw;
                          $itLabel = t($itKey);
                          if ($itLabel === $itKey) {
                              $itLabel = (string)$fl["issue_type"];
                          }
                        ?>
                        <span class="badge text-bg-<?= $fCol ?>"><?= htmlspecialchars($itLabel, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></span>
                        <form method="post" action="mark-student" class="d-inline">
                          <?= csrf_field() ?>
                          <input type="hidden" name="action" value="resolve" />
                          <input type="hidden" name="flag_id" value="<?= (int)$fl["flag_id"] ?>" />
                          <input type="hidden" name="redirect" value="<?= htmlspecialchars($listRedirect, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" />
                          <button class="btn btn-sm p-0 text-muted" type="submit" title="<?= htmlspecialchars(t("common.resolve"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" style="font-size:.7rem">&#x2715;</button>
                        </form>
                      </div>
                    <?php endforeach; ?>
                  <?php else: ?>
                    <span class="text-muted small">-</span>
                  <?php endif; ?>
                </td>
                <td>
                  <details class="small">
                    <summary class="text-muted"><?= h_t("issue.add_flag") ?></summary>
                    <form method="post" action="mark-student" class="mt-1 d-grid gap-1" style="min-width:130px">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="add" />
                      <input type="hidden" name="student_id" value="<?= (int)$r["student_id"] ?>" />
                      <input type="hidden" name="redirect" value="<?= htmlspecialchars($listRedirect, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" />
                      <select class="form-select form-select-sm" name="issue_type" required>
                        <option value=""><?= h_t("issue.placeholder_type") ?></option>
                        <option value="Health"><?= h_t("issue.health") ?></option>
                        <option value="Financial"><?= h_t("issue.financial") ?></option>
                        <option value="Behavioral"><?= h_t("issue.behavioral") ?></option>
                        <option value="Academic"><?= h_t("issue.academic") ?></option>
                        <option value="Family"><?= h_t("issue.family") ?></option>
                        <option value="Other"><?= h_t("issue.other") ?></option>
                      </select>
                      <select class="form-select form-select-sm" name="severity">
                        <option value="Low"><?= h_t("severity.low") ?></option>
                        <option value="Moderate" selected><?= h_t("severity.moderate") ?></option>
                        <option value="High"><?= h_t("severity.high") ?></option>
                      </select>
                      <input class="form-control form-control-sm" name="note" placeholder="<?= h_t("issue.details_placeholder") ?>" />
                      <button class="btn btn-sm btn-outline-danger" type="submit"><?= h_t("issue.flag_btn") ?></button>
                    </form>
                  </details>
                </td>
              <?php endif; ?>
              <td>
                <button
                  type="button"
                  class="btn btn-sm btn-outline-secondary student-open"
                  data-student-id="<?= (int)$r["student_id"] ?>"
                  data-student-name="<?= htmlspecialchars((string)$r["name"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>"
                >
                  <?= h_t("common.open") ?>
                </button>
              </td>
              <?php if ($canArchiveStudent): ?>
                <td class="small">
                  <?php if (!empty($r["is_archived"])): ?>
                    <form method="post" action="student-list" class="d-inline">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="unarchive_student" />
                      <input type="hidden" name="student_id" value="<?= (int)$r["student_id"] ?>" />
                      <input type="hidden" name="redirect" value="<?= htmlspecialchars($listRedirect, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" />
                      <button class="btn btn-sm btn-outline-success" type="submit"><?= h_t("student.unarchive_btn") ?></button>
                    </form>
                  <?php else: ?>
                    <form method="post" action="student-list" onsubmit="return confirm(<?= json_encode(t("student.confirm_archive"), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>);">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="archive_student" />
                      <input type="hidden" name="student_id" value="<?= (int)$r["student_id"] ?>" />
                      <input type="hidden" name="redirect" value="<?= htmlspecialchars($listRedirect, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" />
                      <button class="btn btn-sm btn-outline-secondary" type="submit"><?= h_t("student.archive_btn") ?></button>
                    </form>
                  <?php endif; ?>
                </td>
              <?php endif; ?>
              <?php if ($isAdmin): ?>
                <td>
                  <form method="post" action="student-list" onsubmit="return confirm(<?= json_encode(t("student_list.confirm_delete"), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>);">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete_student" />
                    <input type="hidden" name="student_id" value="<?= (int)$r["student_id"] ?>" />
                    <input type="hidden" name="redirect" value="<?= htmlspecialchars($listRedirect, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" />
                    <button class="btn btn-sm btn-outline-danger" type="submit"><?= h_t("common.delete") ?></button>
                  </form>
                </td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
</div>

<!-- Student profile+edit modal -->
<div class="modal fade" id="studentModal" tabindex="-1" aria-labelledby="studentModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <div>
          <div class="small text-muted"><?= h_t("common.student") ?></div>
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
  (function () {
    const form = document.getElementById("dgStudentListFilter");
    const gEl = document.getElementById("dgListFilterGrade");
    const sEl = document.getElementById("dgListFilterSection");
    const strandWrap = document.getElementById("dgListStrandFilterWrap");
    const strandSel = document.getElementById("dgListFilterStrand");
    const syEl = document.getElementById("dgListFilterSchoolYear");
    if (!form || !gEl || !sEl) return;

    function canonGrade(v) {
      return v ? String(v) : "";
    }
    function isShs(g) {
      return g === "Grade 11" || g === "Grade 12";
    }
    function syncSectionOptions() {
      const g = canonGrade(gEl.value);
      const strand = strandSel && !strandSel.disabled ? String(strandSel.value || "") : "";
      for (let i = 0; i < sEl.options.length; i++) {
        const opt = sEl.options[i];
        if (!opt.value) {
          opt.hidden = false;
          continue;
        }
        const og = opt.getAttribute("data-section-grade") || "";
        const hideByGrade = !g ? !!opt.value : !!(og && og !== g);
        const st = opt.getAttribute("data-section-strand") || "";
        const hideByStrand = !!(strand && isShs(g) && st !== strand);
        opt.hidden = hideByGrade || hideByStrand;
      }
      const sel = sEl.selectedOptions[0];
      if (sel && sel.hidden) sEl.value = "";
      sEl.disabled = !g;
    }
    function syncStrandUi() {
      if (!strandWrap || !strandSel) return;
      const g = canonGrade(gEl.value);
      const shs = isShs(g);
      strandWrap.classList.toggle("d-none", !shs);
      strandSel.disabled = !shs;
      if (!shs) strandSel.value = "";
    }
    function submitFilter() {
      if (typeof form.requestSubmit === "function") {
        form.requestSubmit();
      } else {
        form.submit();
      }
    }
    function onGradeChange() {
      syncStrandUi();
      syncSectionOptions();
      submitFilter();
    }
    function onStrandChange() {
      syncSectionOptions();
      submitFilter();
    }
    gEl.addEventListener("change", onGradeChange);
    if (strandSel) strandSel.addEventListener("change", onStrandChange);
    [sEl, syEl].forEach(function (el) {
      if (!el) return;
      el.addEventListener("change", submitFilter);
    });
    syncStrandUi();
    syncSectionOptions();
  })();

  (function(){
    const SL = <?= json_encode($slJs, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
    const modalEl = document.getElementById('studentModal');
    let modal = null;
    const modalLabel = document.getElementById('studentModalLabel');

    function setBody(html){
      const body = modalEl.querySelector('.modal-body');
      body.innerHTML = html;
    }

    function ensureModal(){
      if (modal) return modal;
      if (!window.bootstrap || !bootstrap.Modal) return null;
      modal = bootstrap.Modal.getOrCreateInstance(modalEl);
      return modal;
    }

    async function openStudent(id, name){
      const m = ensureModal();
      if (!m) return;
      modalLabel.textContent = name || SL.defaultStudent;
      setBody(SL.fetchingHtml);
      m.show();
      try{
        const redirect = new URLSearchParams(window.location.search);
        redirect.set('open_student_id', String(id));
        const res = await fetch('student-modal?id=' + encodeURIComponent(id) + '&redirect=' + encodeURIComponent('student-list?' + redirect.toString()), { credentials: 'same-origin' });
        const html = await res.text();
        setBody(html);
      } catch(e){
        setBody(SL.failHtml);
      }
    }

    document.querySelectorAll('.student-link').forEach((link) => {
      link.addEventListener('click', (e) => {
        if (!ensureModal()) return;
        e.preventDefault();
        openStudent(link.getAttribute('data-student-id'), link.getAttribute('data-student-name'));
      });
    });
    document.querySelectorAll('.student-open').forEach((btn) => {
      btn.addEventListener('click', () => openStudent(btn.getAttribute('data-student-id'), btn.getAttribute('data-student-name')));
    });

    const openId = <?= (int)$openStudentId ?>;
    if (openId > 0) {
      const rowLink = document.querySelector('.student-link[data-student-id="' + openId + '"]');
      const nm = rowLink ? rowLink.getAttribute('data-student-name') : SL.defaultStudent;
      // Open once Bootstrap is available.
      window.addEventListener('load', () => openStudent(openId, nm), { once: true });
    }

    (function () {
      const gEl = document.getElementById("dgStudentAddGrade");
      const sEl = document.getElementById("dgStudentAddSection");
      const sectionWrap = document.getElementById("dgStudentAddSectionWrap");
      const strandRow = document.getElementById("dgStudentAddStrandRow");
      const strandSel = document.getElementById("dgStudentAddStrand");
      if (!gEl || !sEl) return;
      function canon(v) {
        if (!v) return "";
        const s = String(v);
        if (/^\d{1,2}$/.test(s)) {
          const n = parseInt(s, 10);
          if (n >= 7 && n <= 12) return "Grade " + n;
        }
        return s;
      }
      function syncStrand() {
        if (!strandRow || !strandSel) return;
        const g = canon(gEl.value);
        const shs = g === "Grade 11" || g === "Grade 12";
        strandRow.classList.toggle("d-none", !shs);
        strandSel.required = shs;
        if (!shs) strandSel.value = "";
      }
      function sync() {
        const g = canon(gEl.value);
        const shs = g === "Grade 11" || g === "Grade 12";
        const strand = strandSel && shs ? String(strandSel.value || "") : "";
        if (sectionWrap) sectionWrap.classList.toggle("d-none", !g);
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
        syncStrand();
      }
      gEl.addEventListener("change", sync);
      if (strandSel) strandSel.addEventListener("change", sync);
      sync();
    })();

    (function () {
      var stack = document.getElementById("dgToastStack");
      var form = document.getElementById("dgAddStudentForm");
      if (!form) return;
      function dgShowCloudToast(type, message) {
        if (!stack || !message) return;
        var el = document.createElement("div");
        el.className = "dg-cloud-toast dg-cloud-toast--" + type;
        el.setAttribute("role", "alert");
        el.textContent = message;
        stack.appendChild(el);
        requestAnimationFrame(function () {
          el.classList.add("dg-cloud-toast--show");
        });
        var ms = type === "error" ? 7200 : 4200;
        setTimeout(function () {
          el.classList.remove("dg-cloud-toast--show");
          setTimeout(function () {
            el.remove();
          }, 380);
        }, ms);
      }
      form.addEventListener("submit", function (e) {
        e.preventDefault();
        var btn = form.querySelector('button[type="submit"]');
        var prev = btn ? btn.textContent : "";
        if (btn) {
          btn.disabled = true;
          btn.textContent = SL.savingBtn;
        }
        fetch(form.getAttribute("action") || "student-list", {
          method: "POST",
          headers: { "X-Requested-With": "XMLHttpRequest" },
          body: new FormData(form),
          credentials: "same-origin",
        })
          .then(function (res) {
            var ct = (res.headers.get("content-type") || "").toLowerCase();
            if (!res.ok) {
              return res.text().then(function (t) {
                var msg =
                  res.status === 403
                    ? SL.ajaxSession
                    : (t && t.trim().slice(0, 240)) || SL.ajaxRequestFailed;
                throw new Error(msg);
              });
            }
            if (ct.indexOf("application/json") === -1) {
              throw new Error(SL.ajaxUnexpected);
            }
            return res.json();
          })
          .then(function (data) {
            if (data && data.ok) {
              dgShowCloudToast("success", data.message || SL.savedMsg);
              setTimeout(function () {
                window.location.reload();
              }, 900);
            } else {
              dgShowCloudToast("error", (data && data.error) || SL.ajaxRequestFailed);
            }
          })
          .catch(function (err) {
            dgShowCloudToast("error", err && err.message ? err.message : SL.ajaxNetwork);
          })
          .finally(function () {
            if (btn) {
              btn.disabled = false;
              btn.textContent = prev;
            }
          });
      });
    })();
  })();
</script>
<?php
$content = ob_get_clean();
require __DIR__ . "/_layout.php";
