<?php
declare(strict_types=1);

require_once __DIR__ . "/../../app/auth.php";
require_once __DIR__ . "/../../app/db.php";
require_once __DIR__ . "/../../app/ml.php";
require_once __DIR__ . "/../../app/marks.php";
require_once __DIR__ . "/../../app/batches.php";
require_once __DIR__ . "/../../app/workflow.php";
require_once __DIR__ . "/../../app/students.php";
require_once __DIR__ . "/../../app/section_schedule.php";
require_once __DIR__ . "/../../app/curriculum.php";
require_once __DIR__ . "/../../app/grading.php";

require_login();
ensure_student_marks_table();
ensure_student_batches_table();
ensure_workflow_tables();
ensure_students_archived_column();

$user = current_user();
$role = $user["role"] ?? "";

ml_ensure_risk_factors_schema();

$studentId = (int)($_GET["id"] ?? 0);
if ($studentId <= 0) {
    http_response_code(400);
    echo "Missing student id.";
    exit;
}

$stmtStudent = db()->prepare(
    "SELECT student_id, lrn, name, grade_level, strand, section, face_image_path, gpa, absences, risk_score, risk_level, risk_factors_json,
            COALESCE(is_archived,0) AS is_archived
     FROM students WHERE student_id = ?"
);
$stmtStudent->execute([$studentId]);
$student = $stmtStudent->fetch();

if (!$student) {
    http_response_code(404);
    echo "Student not found.";
    exit;
}

audit_log("student_view", "info", "student", $studentId, "Viewed student profile.");

// Latest performance (by quarter desc)
$perfRows = db()->prepare(
    "SELECT quarter, gpa, days_present, total_school_days, absences, consecutive_absences, failing_subjects
     FROM performance
     WHERE student_id = ?
     ORDER BY quarter DESC
     LIMIT 6"
);
$perfRows->execute([$studentId]);
$performance = $perfRows->fetchAll();
$latestPerf = $performance[0] ?? null;
$simDefaults = [
    "gpa" => (float)($latestPerf["gpa"] ?? $student["gpa"] ?? 0),
    "absences" => (int)($latestPerf["absences"] ?? $student["absences"] ?? 0),
    "days_present" => (int)($latestPerf["days_present"] ?? 0),
    "total_school_days" => (int)($latestPerf["total_school_days"] ?? 0),
    "consecutive_absences" => (int)($latestPerf["consecutive_absences"] ?? 0),
    "failing_subjects" => (int)($latestPerf["failing_subjects"] ?? 0),
];
$currentRiskLevel = (string)($student["risk_level"] ?? "Low");
$currentRiskScore = (float)($student["risk_score"] ?? 0);

$riskRows = db()->prepare(
    "SELECT quarter, probability_score, risk_level, risk_factors_json, generated_at
     FROM risk_analysis
     WHERE student_id = ?
     ORDER BY generated_at DESC
     LIMIT 10"
);
$riskRows->execute([$studentId]);
$riskHistory = $riskRows->fetchAll();

$batchRows = db()->prepare(
    "SELECT school_year, grade_level, strand, is_active, created_at
     FROM student_batches
     WHERE student_id = ?
     ORDER BY school_year DESC"
);
$batchRows->execute([$studentId]);
$batches = $batchRows->fetchAll();

$caseStmt = db()->prepare("SELECT status, notes, updated_at FROM counseling_cases WHERE student_id = ? LIMIT 1");
$caseStmt->execute([$studentId]);
$caseInfo = $caseStmt->fetch();
if (is_array($caseInfo) && isset($caseInfo["notes"]) && $caseInfo["notes"] !== null && $caseInfo["notes"] !== "") {
    $caseInfo["notes"] = db_field_decrypt((string)$caseInfo["notes"]);
}

$refStmt = db()->prepare(
    "SELECT r.created_at, r.reason, r.details, r.status, u.full_name AS teacher_name
     FROM manual_referrals r
     INNER JOIN users u ON u.user_id = r.teacher_user_id
     WHERE r.student_id = ?
     ORDER BY r.created_at DESC
     LIMIT 10"
);
$refStmt->execute([$studentId]);
$manualRefs = $refStmt->fetchAll();
foreach ($manualRefs as &$mr) {
    if (isset($mr["teacher_name"]) && $mr["teacher_name"] !== "") {
        $mr["teacher_name"] = db_field_decrypt((string)$mr["teacher_name"]);
    }
    if (isset($mr["reason"]) && $mr["reason"] !== "") {
        $mr["reason"] = db_field_decrypt((string)$mr["reason"]);
    }
    if (isset($mr["details"]) && $mr["details"] !== null && $mr["details"] !== "") {
        $mr["details"] = db_field_decrypt((string)$mr["details"]);
    }
}
unset($mr);

$activeFlags = get_student_flags($studentId, true);
$hasFlags = !empty($activeFlags);
$enrolledSubjects = list_enrolled_subjects_for_student($studentId);

// Latest subject grades (per-subject grading_components)
ensure_grading_components_table();
$latestTermId = "";
try {
    $stmtLatestTerm = db()->prepare(
        "SELECT term_id
         FROM grading_components
         WHERE student_id = ? AND subject_id <> 0
         ORDER BY updated_at DESC
         LIMIT 1"
    );
    $stmtLatestTerm->execute([$studentId]);
    $lt = $stmtLatestTerm->fetch();
    $latestTermId = trim((string)($lt["term_id"] ?? ""));
} catch (Throwable) {
    $latestTermId = "";
}
$subjectGrades = [];
if ($latestTermId !== "") {
    try {
        $stmtSG = db()->prepare(
            "SELECT gc.subject_id, gc.initial_score, gc.final_score, gc.is_final,
                    s.subject_code, s.subject_name
             FROM grading_components gc
             LEFT JOIN subjects s ON s.subject_id = gc.subject_id
             WHERE gc.student_id = ? AND gc.term_id = ? AND gc.subject_id <> 0
             ORDER BY COALESCE(s.subject_code, ''), COALESCE(s.subject_name, ''), gc.subject_id"
        );
        $stmtSG->execute([$studentId, $latestTermId]);
        $subjectGrades = $stmtSG->fetchAll();
    } catch (Throwable) {
        $subjectGrades = [];
    }
}

// Intervention notes (action logs)
$noteRows = db()->prepare(
    "SELECT i.intervention_id, i.note, i.created_at, u.full_name, u.role
     FROM interventions i
     INNER JOIN users u ON u.user_id = i.created_by
     WHERE i.student_id = ?
     ORDER BY i.created_at DESC
     LIMIT 20"
);
$noteRows->execute([$studentId]);
$notes = $noteRows->fetchAll();
foreach ($notes as &$nr) {
    $nr = db_decrypt_user_pii_row($nr);
    if (isset($nr["note"]) && $nr["note"] !== "") {
        $nr["note"] = db_field_decrypt((string)$nr["note"]);
    }
}
unset($nr);

$flash = null;
$error = null;
$simResult = null;

// Counselor/Admin actions
if (($_SERVER["REQUEST_METHOD"] ?? "GET") === "POST") {
    require_valid_csrf_token();
    if (!in_array($role, ["Counselor", "Admin"], true)) {
        http_response_code(403);
        echo "Forbidden.";
        exit;
    }
    $formType = (string)($_POST["form_type"] ?? "intervention");
    if ($formType === "archive_student" || $formType === "unarchive_student") {
        $val = $formType === "archive_student" ? 1 : 0;
        db()->prepare("UPDATE students SET is_archived = ? WHERE student_id = ?")->execute([$val, $studentId]);
        audit_log($val ? "student_archive" : "student_unarchive", "success", "student", $studentId, $val ? "Student archived from profile." : "Student unarchived from profile.");
        start_app_session();
        $_SESSION["flash"] = t($val ? "student.archive_success" : "student.unarchive_success");
        header("Location: student?id=" . urlencode((string)$studentId));
        exit;
    }
    if ($formType === "upload_face") {
        if ($role !== "Admin") {
            http_response_code(403);
            echo "Forbidden.";
            exit;
        }
        try {
            $rel = save_student_face_photo($studentId, $_FILES["face_image"] ?? []);
            $stmt = db()->prepare("UPDATE students SET face_image_path = ? WHERE student_id = ?");
            $stmt->execute([$rel, $studentId]);
            audit_log("student_face_upload", "success", "student", $studentId, "Uploaded student face photo.");
            start_app_session();
            $_SESSION["flash"] = "Face photo uploaded.";
            header("Location: student?id=" . urlencode((string)$studentId));
            exit;
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    } elseif ($formType === "simulator") {
        if (!empty((int)($student["is_archived"] ?? 0))) {
            $error = t("student.profile_action_blocked_archived");
        } else {
            $sgpa = (float)($_POST["sim_gpa"] ?? $simDefaults["gpa"]);
            $sabs = (int)($_POST["sim_absences"] ?? $simDefaults["absences"]);
            $spresent = (int)($_POST["sim_days_present"] ?? $simDefaults["days_present"]);
            $stotal = (int)($_POST["sim_total_school_days"] ?? $simDefaults["total_school_days"]);
            $sconsec = (int)($_POST["sim_consecutive_absences"] ?? $simDefaults["consecutive_absences"]);
            $sfailing = (int)($_POST["sim_failing_subjects"] ?? $simDefaults["failing_subjects"]);

            $score = estimate_risk_score($sgpa, $sabs, $spresent, $stotal, $sconsec, 0, 0, 0, 0, $sfailing);
            $newLevel = classify_risk_level($score);
            $simResult = [
                "score" => $score,
                "level" => $newLevel,
                "message" => $newLevel === $currentRiskLevel
                    ? "At these values, risk stays at {$currentRiskLevel}."
                    : "If these metrics were true, risk would change from {$currentRiskLevel} to {$newLevel}.",
            ];
            audit_log("what_if_simulation", "success", "student", (int)$studentId, "Ran what-if simulation.", [
                "gpa" => $sgpa,
                "absences" => $sabs,
                "days_present" => $spresent,
                "total_school_days" => $stotal,
                "consecutive_absences" => $sconsec,
                "failing_subjects" => $sfailing,
                "sim_score" => $score,
                "sim_level" => $newLevel,
                "previous_level" => $currentRiskLevel,
            ]);
        }
    } elseif ($formType === "intervention") {
        if (!empty((int)($student["is_archived"] ?? 0))) {
            $error = t("student.profile_intervention_archived");
        } else {
            $note = trim((string)($_POST["note"] ?? ""));
            if ($note === "") {
                $error = "Note cannot be empty.";
            } else {
                $stmt = db()->prepare("INSERT INTO interventions (student_id, created_by, note) VALUES (?,?,?)");
                $stmt->execute([(int)$studentId, (int) $user["user_id"], db_field_encrypt($note)]);
                audit_log("intervention_add", "success", "student", (int)$studentId, "Intervention note added.", [
                    "note_preview" => substr($note, 0, 120),
                ]);
                $flash = "Intervention note saved.";

                $noteRows->execute([$studentId]);
                $notes = $noteRows->fetchAll();
            }
        }
    }
}

$riskLevel = (string)($student["risk_level"] ?? "Low");
$riskChip = "dg-risk-chip " . ($riskLevel === "High" ? "dg-risk-high" : ($riskLevel === "Moderate" ? "dg-risk-mod" : "dg-risk-low"));
$currentRiskFactors = ml_parse_risk_factors($student["risk_factors_json"] ?? null);
$isStudentArchived = !empty((int)($student["is_archived"] ?? 0));
$canArchiveStudent = in_array($role, ["Counselor", "Admin"], true);

ob_start();
?>

<div class="dg-page-header mb-3">
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
    <div>
      <div class="small text-muted mb-1">Student Profile</div>
      <h3 class="mb-0"><?= htmlspecialchars($student["name"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></h3>
      <div class="text-muted small">
        Grade Level: <span class="fw-semibold"><?= htmlspecialchars((string)$student["grade_level"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></span>
        <?php if (!empty($student["strand"]) && curriculum_is_senior_high_grade((string)$student["grade_level"])): ?>
          | Strand: <span class="fw-semibold"><?= htmlspecialchars((string)$student["strand"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></span>
        <?php endif; ?>
        <?php if (!empty($student["lrn"])): ?>
          | LRN: <span class="fw-semibold"><?= htmlspecialchars((string)$student["lrn"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></span>
        <?php endif; ?>
      </div>
    </div>
    <div class="d-flex align-items-center gap-2 flex-wrap">
      <?php if ($isStudentArchived): ?>
        <span class="badge text-bg-secondary"><?= htmlspecialchars(t("student.archived_badge"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></span>
      <?php endif; ?>
      <span class="<?= htmlspecialchars($riskChip, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>">
        <span class="dot"></span>
        <?= htmlspecialchars($riskLevel, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>
      </span>
      <?php if (in_array($role, ["Counselor", "Admin"], true)): ?>
        <a class="btn btn-sm btn-outline-dark" href="student-report?id=<?= (int)$studentId ?>" target="_blank" rel="noopener">Print Report</a>
      <?php endif; ?>
      <?php if ($canArchiveStudent): ?>
        <form method="post" action="student?id=<?= (int)$studentId ?>" class="m-0" onsubmit="return confirm(<?= json_encode($isStudentArchived ? t("student.confirm_unarchive") : t("student.confirm_archive"), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>);">
          <?= csrf_field() ?>
          <input type="hidden" name="form_type" value="<?= $isStudentArchived ? "unarchive_student" : "archive_student" ?>" />
          <button type="submit" class="btn btn-sm <?= $isStudentArchived ? "btn-outline-success" : "btn-outline-secondary" ?>">
            <?= htmlspecialchars($isStudentArchived ? t("student.unarchive_btn") : t("student.archive_btn"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>
          </button>
        </form>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php if ($isStudentArchived): ?>
  <div class="alert alert-secondary mb-3"><?= htmlspecialchars(t("student.profile_archived_banner"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></div>
<?php endif; ?>

<?php
  $facePath = (string)($student["face_image_path"] ?? "");
  $faceUrl = $facePath !== "" ? $facePath : "";
?>
<div class="card shadow-sm mb-3">
  <div class="card-body">
    <div class="d-flex flex-wrap gap-3 align-items-start">
      <div style="width:120px">
        <?php if ($faceUrl !== ""): ?>
          <img src="<?= htmlspecialchars($faceUrl, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" alt="Face photo" class="img-thumbnail" style="width:120px;height:120px;object-fit:cover" />
        <?php else: ?>
          <div class="border rounded d-flex align-items-center justify-content-center text-muted" style="width:120px;height:120px">
            No photo
          </div>
        <?php endif; ?>
      </div>
      <div class="flex-grow-1">
        <div class="fw-semibold mb-1">Face Identification Photo</div>
        <div class="text-muted small">Upload a clear front-facing student photo.</div>
        <?php if ($role === "Admin"): ?>
          <form class="mt-2" method="post" action="student?id=<?= (int)$studentId ?>" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="form_type" value="upload_face" />
            <input class="form-control" type="file" name="face_image" accept="image/jpeg,image/png,image/webp" required />
            <button class="btn btn-sm btn-outline-primary mt-2" type="submit">Upload photo</button>
          </form>
          <div class="small text-muted mt-2">Allowed: JPG/PNG/WebP • Max 5MB</div>
        <?php else: ?>
          <div class="small text-muted mt-2">Admin uploads the official face photo.</div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php if ($flash): ?>
  <div class="alert alert-success"><?= htmlspecialchars($flash, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></div>
<?php endif; ?>
<?php if ($error): ?>
  <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-lg-4">
    <div class="card shadow-sm">
      <div class="card-body">
        <div class="fw-semibold mb-2">Overview</div>
        <div class="d-flex justify-content-between mb-2">
          <div class="text-muted small">GPA</div>
          <div class="fw-semibold"><?= number_format((float)$student["gpa"], 2) ?></div>
        </div>
        <div class="d-flex justify-content-between mb-2">
          <div class="text-muted small">Academic Status</div>
          <?php $isFailingAcademic = ((float)$student["gpa"] < 75.0); ?>
          <div>
            <span class="dg-academic-chip <?= $isFailingAcademic ? "dg-academic-fail" : "dg-academic-pass" ?>">
              <?= $isFailingAcademic ? "Failing (<75)" : "Passing (>=75)" ?>
            </span>
          </div>
        </div>
        <div class="d-flex justify-content-between mb-2">
          <div class="text-muted small">Absences</div>
          <div class="fw-semibold"><?= (int)$student["absences"] ?></div>
        </div>
        <div class="d-flex justify-content-between mb-2">
          <div class="text-muted small">Risk Score (latest)</div>
          <div class="fw-semibold"><?= number_format((float)$student["risk_score"], 2) ?></div>
        </div>
        <?php if ($currentRiskFactors): ?>
        <div class="mb-2">
          <div class="text-muted small mb-1"><?= h_t("ml.risk_factors_title") ?></div>
          <?= ml_render_risk_factors($currentRiskFactors, 6) ?>
        </div>
        <?php endif; ?>
        <div class="mb-2">
          <div class="text-muted small mb-1">Issue Flags</div>
          <?php if ($hasFlags): ?>
            <?php foreach ($activeFlags as $af): ?>
              <?php $afSev = (string)($af["severity"] ?? "Moderate"); $afCol = $afSev === "High" ? "danger" : ($afSev === "Moderate" ? "warning" : "success"); ?>
              <div class="mb-1">
                <span class="badge text-bg-<?= $afCol ?>"><?= htmlspecialchars((string)$af["issue_type"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></span>
                <span class="badge text-bg-secondary"><?= htmlspecialchars($afSev, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></span>
                <?php if (!empty($af["note"])): ?>
                  <div class="small text-muted"><?= htmlspecialchars((string)$af["note"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></div>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          <?php else: ?>
            <span class="badge text-bg-light">No Issues</span>
          <?php endif; ?>
        </div>
        <div class="mt-3">
          <div class="text-muted small mb-1">Enrolled Subjects</div>
          <?php if (!$enrolledSubjects): ?>
            <div class="text-muted small">No subjects assigned to this section yet.</div>
          <?php else: ?>
            <div class="d-flex flex-wrap gap-1">
              <?php foreach ($enrolledSubjects as $sub): ?>
                <span class="badge text-bg-secondary"><?= htmlspecialchars($sub, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></span>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>

        <div class="mt-3">
          <div class="text-muted small mb-1">Subject Grades<?= $latestTermId !== "" ? " • " . htmlspecialchars(curriculum_term_label($latestTermId), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") : "" ?></div>
          <?php if (!$subjectGrades): ?>
            <div class="text-muted small">No subject grades recorded yet.</div>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table table-sm mb-0">
                <thead>
                  <tr>
                    <th>Subject</th>
                    <th class="text-end">IG</th>
                    <th class="text-end">Grade</th>
                    <th class="text-end">Final</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($subjectGrades as $sg): ?>
                    <?php
                      $label = trim((string)($sg["subject_code"] ?? ""));
                      $nm = trim((string)($sg["subject_name"] ?? ""));
                      $subLabel = $label !== "" && $nm !== "" ? ($label . " - " . $nm) : ($label !== "" ? $label : $nm);
                    ?>
                    <tr>
                      <td class="small"><?= htmlspecialchars($subLabel !== "" ? $subLabel : ("Subject #" . (int)$sg["subject_id"]), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></td>
                      <td class="text-end small text-muted"><?= (int)round((float)($sg["initial_score"] ?? 0)) ?></td>
                      <td class="text-end fw-semibold"><?= (int)round((float)($sg["final_score"] ?? 0)) ?></td>
                      <td class="text-end">
                        <?php if ((int)($sg["is_final"] ?? 0) === 1): ?>
                          <span class="badge text-bg-secondary">Yes</span>
                        <?php else: ?>
                          <span class="text-muted small">No</span>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
        <div class="text-muted small mt-3">
          Click Back in your browser or use Sidebar to return.
        </div>
      </div>
    </div>
    <div class="card shadow-sm mt-3">
      <div class="card-body">
        <div class="fw-semibold mb-2">School Year Batches</div>
        <?php if (!$batches): ?>
          <div class="text-muted small">No school-year batch records yet.</div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-sm dg-table mb-0">
              <thead>
                <tr>
                  <th>School Year</th>
                  <th>Grade Level</th>
                  <th>Strand</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($batches as $b): ?>
                  <tr>
                    <td><?= htmlspecialchars((string)$b["school_year"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></td>
                    <td><?= htmlspecialchars((string)$b["grade_level"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></td>
                    <td class="small"><?= !empty($b["strand"]) ? htmlspecialchars((string)$b["strand"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") : "—" ?></td>
                    <td><?= (int)($b["is_active"] ?? 0) === 1 ? "Active" : "Archived" ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-lg-8">
    <div class="card shadow-sm mb-3">
      <div class="card-body">
        <div class="fw-semibold mb-2">Recent Performance (by Quarter)</div>
        <?php if (!$performance): ?>
          <div class="text-muted small">No performance records yet. Use Data Entry first.</div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-sm dg-table align-middle">
              <thead>
                <tr>
                  <th>Quarter</th>
                  <th class="text-end">GPA</th>
                  <th class="text-end">Days Present</th>
                  <th class="text-end">Total Days</th>
                  <th class="text-end">Absences</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($performance as $p): ?>
                  <tr>
                    <td><?= (int)$p["quarter"] ?></td>
                    <td class="text-end"><?= number_format((float)$p["gpa"], 2) ?></td>
                    <td class="text-end"><?= (int)($p["days_present"] ?? 0) ?></td>
                    <td class="text-end"><?= (int)($p["total_school_days"] ?? 0) ?></td>
                    <td class="text-end"><?= (int)$p["absences"] ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="card shadow-sm mb-3">
      <div class="card-body">
        <div class="fw-semibold mb-2">Risk Analysis History</div>
        <?php if (!$riskHistory): ?>
          <div class="text-muted small">Run analysis to generate risk history.</div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-sm dg-table align-middle">
              <thead>
                <tr>
                  <th>Generated</th>
                  <th>Quarter</th>
                  <th class="text-end">Probability</th>
                  <th>Risk Level</th>
                  <th><?= h_t("ml.risk_factors_title") ?></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($riskHistory as $r): ?>
                  <?php
                    $lvl = $r["risk_level"] ?: "Low";
                    $chipClass = $lvl === "High" ? "dg-risk-high" : ($lvl === "Moderate" ? "dg-risk-mod" : "dg-risk-low");
                    $histFactors = ml_parse_risk_factors($r["risk_factors_json"] ?? null);
                  ?>
                  <tr>
                    <td class="text-muted small"><?= htmlspecialchars((string)$r["generated_at"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></td>
                    <td><?= isset($r["quarter"]) && $r["quarter"] !== null ? (int)$r["quarter"] : "-" ?></td>
                    <td class="text-end"><?= number_format((float)$r["probability_score"], 4) ?></td>
                    <td>
                      <span class="dg-risk-chip <?= $chipClass ?>">
                        <span class="dot"></span>
                        <?= htmlspecialchars($lvl, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>
                      </span>
                    </td>
                    <td class="small">
                      <?php if ($histFactors): ?>
                        <?= ml_render_risk_factors($histFactors, 3) ?>
                      <?php else: ?>
                        <span class="text-muted">—</span>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="card shadow-sm">
      <div class="card-body">
        <?php if (in_array($role, ["Counselor", "Admin"], true)): ?>
          <div class="card bg-light border-0 mb-3">
            <div class="card-body">
              <div class="fw-semibold mb-2">Case Management</div>
              <form method="post" action="update-case" class="row g-2">
                <?= csrf_field() ?>
                <input type="hidden" name="student_id" value="<?= (int)$studentId ?>" />
                <input type="hidden" name="redirect" value="student?id=<?= (int)$studentId ?>" />
                <div class="col-md-5">
                  <label class="form-label small">Case Status</label>
                  <select class="form-select form-select-sm" name="status">
                    <option value="Flagged" <?= (($caseInfo["status"] ?? "Flagged") === "Flagged") ? "selected" : "" ?>>Flagged</option>
                    <option value="Ongoing Counseling" <?= (($caseInfo["status"] ?? "") === "Ongoing Counseling") ? "selected" : "" ?>>Ongoing Counseling</option>
                    <option value="Resolved" <?= (($caseInfo["status"] ?? "") === "Resolved") ? "selected" : "" ?>>Resolved</option>
                  </select>
                </div>
                <div class="col-md-5">
                  <label class="form-label small">Case Notes</label>
                  <input class="form-control form-control-sm" name="notes" value="<?= htmlspecialchars((string)($caseInfo["notes"] ?? ""), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" />
                </div>
                <div class="col-md-2 d-flex align-items-end">
                  <button class="btn btn-sm btn-dark w-100" type="submit">Update</button>
                </div>
              </form>
            </div>
          </div>

          <div class="fw-semibold mb-2">Issue Flags</div>
          <?php if ($activeFlags): ?>
            <div class="table-responsive mb-2">
              <table class="table table-sm dg-table mb-0">
                <thead><tr><th>Type</th><th>Severity</th><th>Note</th><th>Action</th></tr></thead>
                <tbody>
                  <?php foreach ($activeFlags as $af): ?>
                    <?php $afCol = ($af["severity"] ?? "Moderate") === "High" ? "danger" : (($af["severity"] ?? "") === "Moderate" ? "warning" : "success"); ?>
                    <tr>
                      <td><span class="badge text-bg-<?= $afCol ?>"><?= htmlspecialchars((string)$af["issue_type"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></span></td>
                      <td><?= htmlspecialchars((string)($af["severity"] ?? ""), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></td>
                      <td class="small"><?= htmlspecialchars((string)($af["note"] ?? ""), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></td>
                      <td>
                        <form method="post" action="mark-student" class="d-inline">
                          <?= csrf_field() ?>
                          <input type="hidden" name="action" value="resolve" />
                          <input type="hidden" name="flag_id" value="<?= (int)$af["flag_id"] ?>" />
                          <input type="hidden" name="redirect" value="student?id=<?= (int)$studentId ?>" />
                          <button class="btn btn-sm btn-outline-secondary" type="submit">Resolve</button>
                        </form>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php else: ?>
            <div class="text-muted small mb-2">No active flags.</div>
          <?php endif; ?>
          <div class="fw-semibold small mb-1">Add New Flag</div>
          <form method="post" action="mark-student" class="mb-3">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add" />
            <input type="hidden" name="student_id" value="<?= (int)$studentId ?>" />
            <input type="hidden" name="redirect" value="student?id=<?= (int)$studentId ?>" />
            <div class="row g-2 mb-2">
              <div class="col-md-4">
                <select class="form-select form-select-sm" name="issue_type" required>
                  <option value="">Issue type...</option>
                  <?php foreach (["Health","Financial","Behavioral","Academic","Family","Other"] as $it): ?>
                    <option value="<?= $it ?>"><?= $it ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-3">
                <select class="form-select form-select-sm" name="severity">
                  <option value="Low">Low</option>
                  <option value="Moderate" selected>Moderate</option>
                  <option value="High">High</option>
                </select>
              </div>
              <div class="col-md-3">
                <input class="form-control form-control-sm" name="note" placeholder="Details..." />
              </div>
              <div class="col-md-2">
                <button class="btn btn-sm btn-outline-danger w-100" type="submit">Flag</button>
              </div>
            </div>
          </form>
        <?php endif; ?>

        <div class="fw-semibold mb-2">Action Logs (Interventions)</div>
        <?php if (!$notes): ?>
          <div class="text-muted small">No intervention notes yet.</div>
        <?php else: ?>
          <div class="table-responsive mb-3">
            <table class="table table-sm dg-table align-middle">
              <thead>
                <tr>
                  <th>Date</th>
                  <th>By</th>
                  <th>Role</th>
                  <th>Note</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($notes as $n): ?>
                  <tr>
                    <td class="text-muted small"><?= htmlspecialchars((string)$n["created_at"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></td>
                    <td class="small"><?= htmlspecialchars((string)$n["full_name"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></td>
                    <td class="small">
                      <?php $staffRole = (string)($n["role"] ?? ""); require __DIR__ . "/partials/staff_role_badge.php"; ?>
                    </td>
                    <td><?= nl2br(htmlspecialchars((string)$n["note"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8")) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>

        <div class="fw-semibold mb-2">Manual Referrals (Teacher)</div>
        <?php if (!$manualRefs): ?>
          <div class="text-muted small mb-3">No manual referrals from teacher.</div>
        <?php else: ?>
          <div class="table-responsive mb-3">
            <table class="table table-sm dg-table align-middle">
              <thead>
                <tr>
                  <th>Date</th>
                  <th>Teacher</th>
                  <th>Reason</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($manualRefs as $mr): ?>
                  <tr>
                    <td class="small text-muted"><?= htmlspecialchars((string)$mr["created_at"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></td>
                    <td><?= htmlspecialchars((string)$mr["teacher_name"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></td>
                    <td>
                      <div class="fw-semibold"><?= htmlspecialchars((string)$mr["reason"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></div>
                      <?php if (!empty($mr["details"])): ?>
                        <div class="small text-muted"><?= htmlspecialchars((string)$mr["details"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></div>
                      <?php endif; ?>
                    </td>
                    <td><span class="badge text-bg-secondary"><?= htmlspecialchars((string)$mr["status"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></span></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>

        <?php if (in_array($role, ["Counselor", "Admin"], true)): ?>
          <div class="card bg-light border-0 mb-3" id="dgWhatIfSimulator">
            <div class="card-body">
              <div class="fw-semibold mb-2">What-If Risk Simulator</div>
              <div class="text-muted small mb-2">Adjust hypothetical GPA or attendance to preview how risk might change — e.g. show a student that improving math could lower risk from High to Moderate.</div>
              <?php if ($isStudentArchived): ?>
                <div class="text-muted small"><?= htmlspecialchars(t("student.profile_action_blocked_archived"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></div>
              <?php else: ?>
              <form method="post" action="student?id=<?= (int)$studentId ?>" id="dgWhatIfForm">
                <?= csrf_field() ?>
                <input type="hidden" name="form_type" value="simulator" />
                <div class="row g-2">
                  <div class="col-md-3">
                    <label class="form-label small">GPA</label>
                    <input class="form-control form-control-sm" type="number" step="0.01" min="0" max="100" name="sim_gpa" value="<?= number_format((float)$simDefaults["gpa"], 2, ".", "") ?>" />
                  </div>
                  <div class="col-md-3">
                    <label class="form-label small">Absences</label>
                    <input class="form-control form-control-sm" type="number" min="0" name="sim_absences" value="<?= (int)$simDefaults["absences"] ?>" />
                  </div>
                  <div class="col-md-3">
                    <label class="form-label small">Days Present</label>
                    <input class="form-control form-control-sm" type="number" min="0" name="sim_days_present" value="<?= (int)$simDefaults["days_present"] ?>" />
                  </div>
                  <div class="col-md-3">
                    <label class="form-label small">Total School Days</label>
                    <input class="form-control form-control-sm" type="number" min="0" name="sim_total_school_days" value="<?= (int)$simDefaults["total_school_days"] ?>" />
                  </div>
                  <div class="col-md-3">
                    <label class="form-label small">Consecutive Abs.</label>
                    <input class="form-control form-control-sm" type="number" min="0" name="sim_consecutive_absences" value="<?= (int)$simDefaults["consecutive_absences"] ?>" />
                  </div>
                  <div class="col-md-3">
                    <label class="form-label small">Failing Subjects</label>
                    <input class="form-control form-control-sm" type="number" min="0" max="20" name="sim_failing_subjects" value="<?= (int)$simDefaults["failing_subjects"] ?>" />
                  </div>
                  <div class="col-md-3 d-flex align-items-end">
                    <button class="btn btn-sm btn-dark w-100" type="submit">Simulate</button>
                  </div>
                </div>
              </form>
              <script>
                (function () {
                  const form = document.getElementById('dgWhatIfForm');
                  if (!form) return;
                  form.addEventListener('submit', function () {
                    try {
                      sessionStorage.setItem('dg_restore_scroll', String(window.scrollY || 0));
                      sessionStorage.setItem('dg_restore_scroll_anchor', 'dgWhatIfSimulator');
                    } catch (e) {}
                  });
                  document.addEventListener('DOMContentLoaded', function () {
                    try {
                      const y = sessionStorage.getItem('dg_restore_scroll');
                      const anchor = sessionStorage.getItem('dg_restore_scroll_anchor');
                      if (anchor !== 'dgWhatIfSimulator' || y === null) return;
                      sessionStorage.removeItem('dg_restore_scroll');
                      sessionStorage.removeItem('dg_restore_scroll_anchor');
                      // Restore after layout paints (avoid "jump then jump back").
                      setTimeout(function () {
                        window.scrollTo({ top: Number(y) || 0, left: 0, behavior: 'instant' });
                      }, 0);
                    } catch (e) {}
                  });
                })();
              </script>
              <?php if ($simResult): ?>
                <?php
                  $slvl = $simResult["level"];
                  $schip = $slvl === "High" ? "dg-risk-high" : ($slvl === "Moderate" ? "dg-risk-mod" : "dg-risk-low");
                ?>
                <div class="mt-2 small">
                  Estimated Risk: <strong><?= number_format((float)$simResult["score"], 4) ?></strong>
                  <span class="dg-risk-chip <?= $schip ?>" style="padding:4px 8px; font-size:.8rem;"><span class="dot"></span><?= htmlspecialchars($slvl, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></span>
                  <div class="text-muted mt-1"><?= htmlspecialchars((string)($simResult["message"] ?? ""), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></div>
                </div>
              <?php endif; ?>
              <?php endif; ?>
            </div>
          </div>

          <?php if ($isStudentArchived): ?>
            <div class="text-muted small mb-3"><?= htmlspecialchars(t("student.profile_intervention_archived"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></div>
          <?php endif; ?>
          <form method="post" action="student?id=<?= (int)$studentId ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="form_type" value="intervention" />
            <div class="mb-2">
              <label class="form-label">Add Intervention Note</label>
              <textarea class="form-control" name="note" rows="3" placeholder="e.g., Called parents on March 15, arranged counseling session..." required minlength="3" <?= $isStudentArchived ? "disabled" : "" ?>></textarea>
            </div>
            <button class="btn btn-primary w-100" id="saveInterventionBtn" type="submit" disabled>Save Note</button>
          </form>
          <script>
            (function () {
              const form = document.currentScript && document.currentScript.previousElementSibling;
              if (!form) return;
              const note = form.querySelector('textarea[name="note"]');
              const btn = document.getElementById('saveInterventionBtn');
              if (!note || !btn) return;
              const archived = <?= $isStudentArchived ? "true" : "false" ?>;
              function sync() {
                if (archived) {
                  btn.disabled = true;
                  return;
                }
                const v = (note.value || '').trim();
                btn.disabled = v.length < 3;
              }
              note.addEventListener('input', sync);
              sync();
            })();
          </script>
        <?php else: ?>
          <div class="text-muted small">
            Only Counselor/Admin can add intervention notes. You can still view the profile.
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php
$content = ob_get_clean();
require __DIR__ . "/_layout.php";

