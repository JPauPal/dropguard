<?php
declare(strict_types=1);

require_once __DIR__ . "/../../app/auth.php";
require_once __DIR__ . "/../../app/db.php";
require_once __DIR__ . "/../../app/marks.php";
require_once __DIR__ . "/../../app/batches.php";
require_once __DIR__ . "/../../app/curriculum.php";
require_once __DIR__ . "/../../app/students.php";
require_once __DIR__ . "/../../app/sections.php";
require_once __DIR__ . "/../../app/grading.php";
require_once __DIR__ . "/../../app/ml.php";

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

require_login();
ensure_student_marks_table();
ensure_student_batches_table();
curriculum_ensure_performance_schema();
ensure_students_archived_column();
ml_ensure_risk_factors_schema();

$studentId = (int)($_GET["id"] ?? 0);
if ($studentId <= 0) {
    http_response_code(400);
    echo "<div class='text-danger'>Missing student id.</div>";
    exit;
}

$user = current_user();
$role = $user["role"] ?? "";
$isTeacher = (string)$role === "Teacher";
$teacherCanRefer = true;
if ($isTeacher) {
    $teacherCanRefer = false;
    try {
        $chk = db()->prepare("SELECT 1 FROM teacher_students WHERE teacher_user_id = ? AND student_id = ? LIMIT 1");
        $chk->execute([(int)($user["user_id"] ?? 0), $studentId]);
        if ($chk->fetch()) {
            $teacherCanRefer = true;
        }
    } catch (Throwable) {
        $teacherCanRefer = false;
    }
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
    echo "<div class='text-danger'>Student not found.</div>";
    exit;
}

if (!empty((int)($student["is_archived"] ?? 0))) {
    $teacherCanRefer = false;
}

audit_log("student_modal_view", "info", "student", $studentId, "Viewed student profile modal.");

$perfRows = db()->prepare(
    "SELECT term_id, quarter, gpa, days_present, total_school_days, absences
     FROM performance
     WHERE student_id = ?
     ORDER BY quarter DESC
     LIMIT 6"
);
$perfRows->execute([$studentId]);
$performance = $perfRows->fetchAll();

$riskRows = db()->prepare(
    "SELECT term_id, quarter, probability_score, risk_level, risk_factors_json, generated_at
     FROM risk_analysis
     WHERE student_id = ?
     ORDER BY generated_at DESC
     LIMIT 6"
);
$riskRows->execute([$studentId]);
$riskHistory = $riskRows->fetchAll();

$noteRows = db()->prepare(
    "SELECT i.note, i.created_at, u.full_name, u.role
     FROM interventions i
     INNER JOIN users u ON u.user_id = i.created_by
     WHERE i.student_id = ?
     ORDER BY i.created_at DESC
     LIMIT 5"
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

$isAdmin = (($role ?? "") === "Admin");
$sectionsAll = $isAdmin ? list_sections(null) : [];
$stuGrade = trim((string)($student["grade_level"] ?? ""));
$stuStrand = trim((string)($student["strand"] ?? ""));
$showStrandRow = curriculum_is_senior_high_grade($stuGrade);
$sections = $stuGrade !== ""
    ? array_values(array_filter($sectionsAll, static function (string $sec) use ($stuGrade): bool {
        return section_matches_grade_filter($sec, $stuGrade);
    }))
    : $sectionsAll;
$schoolYears = $isAdmin ? db()->query("SELECT DISTINCT school_year FROM student_batches ORDER BY school_year DESC")->fetchAll() : [];

$riskLevel = (string)($student["risk_level"] ?? "Low");
$chipClass = $riskLevel === "High" ? "dg-risk-high" : ($riskLevel === "Moderate" ? "dg-risk-mod" : "dg-risk-low");
$currentRiskFactors = ml_parse_risk_factors($student["risk_factors_json"] ?? null);

$studentName = (string)$student["name"];
$faceUrl = "";
if (!empty($student["face_image_path"])) {
    $faceUrl = (string)$student["face_image_path"];
}
$initials = "";
foreach (preg_split('/\s+/', trim($studentName)) as $p) {
    if ($p === "") continue;
    $initials .= strtoupper(mb_substr($p, 0, 1, "UTF-8"));
    if (mb_strlen($initials, "UTF-8") >= 2) break;
}
$initials = $initials !== "" ? $initials : "S";

$gpaNow = (float)($student["gpa"] ?? 0);
$absNow = (int)($student["absences"] ?? 0);
$riskScoreNow = isset($student["risk_score"]) ? (float)$student["risk_score"] : null;

ob_start();
?>
<div class="student-modal">
  <div class="dg-student-modal-hero mb-3">
    <div class="d-flex justify-content-between align-items-start gap-3">
      <div class="d-flex align-items-start gap-3">
        <?php if ($faceUrl !== ""): ?>
          <img class="dg-student-avatar" src="<?= htmlspecialchars($faceUrl, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" alt="Face photo" style="object-fit:cover" />
        <?php else: ?>
          <div class="dg-student-avatar" aria-hidden="true"><?= htmlspecialchars($initials, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></div>
        <?php endif; ?>
        <div>
          <div class="small text-muted mb-1">Student Profile</div>
          <div class="h5 mb-0">
            <?= htmlspecialchars($studentName, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>
            <?php if (!empty((int)($student["is_archived"] ?? 0))): ?>
              <span class="badge text-bg-secondary ms-1"><?= htmlspecialchars(t("student.archived_badge"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></span>
            <?php endif; ?>
          </div>
          <div class="text-muted small">
            <span class="me-2">Grade: <span class="fw-semibold"><?= htmlspecialchars((string)$student["grade_level"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></span></span>
            <?php if ($stuStrand !== ""): ?>
              <span class="me-2">Strand: <span class="fw-semibold"><?= htmlspecialchars($stuStrand, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></span></span>
            <?php endif; ?>
            <?php if (!empty($student["lrn"])): ?>
              <span>LRN: <span class="fw-semibold"><?= htmlspecialchars((string)$student["lrn"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></span></span>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <div class="text-end">
        <span class="dg-risk-chip <?= $chipClass ?>"><span class="dot"></span><?= htmlspecialchars($riskLevel, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></span>
        <?php $isFailingAcademic = ($gpaNow < 75.0); ?>
        <div class="mt-1 d-flex justify-content-end gap-2 flex-wrap">
          <span class="dg-academic-chip <?= $isFailingAcademic ? "dg-academic-fail" : "dg-academic-pass" ?>">
            <?= $isFailingAcademic ? "Failing" : "Passing" ?>
          </span>
          <?php if ($hasFlags): ?>
            <span class="badge text-bg-danger">Flagged</span>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="row g-2 mt-3">
      <div class="col-6 col-lg-3">
        <div class="dg-student-stat">
          <div class="dg-student-stat-label">GPA</div>
          <div class="dg-student-stat-value"><?= number_format($gpaNow, 2) ?></div>
        </div>
      </div>
      <div class="col-6 col-lg-3">
        <div class="dg-student-stat">
          <div class="dg-student-stat-label">Absences</div>
          <div class="dg-student-stat-value"><?= (int)$absNow ?></div>
        </div>
      </div>
      <div class="col-6 col-lg-3">
        <div class="dg-student-stat">
          <div class="dg-student-stat-label">Risk Score</div>
          <div class="dg-student-stat-value"><?= $riskScoreNow !== null ? number_format($riskScoreNow, 2) : "—" ?></div>
        </div>
      </div>
      <div class="col-6 col-lg-3">
        <div class="dg-student-stat">
          <div class="dg-student-stat-label">Active Flags</div>
          <div class="dg-student-stat-value"><?= count($activeFlags) ?></div>
        </div>
      </div>
    </div>

    <?php if ($hasFlags): ?>
      <div class="mt-2 d-flex flex-wrap gap-1">
        <?php foreach ($activeFlags as $af): ?>
          <?php $afCol = ($af["severity"] ?? "Moderate") === "High" ? "danger" : (($af["severity"] ?? "") === "Moderate" ? "warning" : "success"); ?>
          <span class="badge text-bg-<?= $afCol ?>"><?= htmlspecialchars((string)$af["issue_type"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></span>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if ($currentRiskFactors): ?>
      <div class="mt-3">
        <div class="small text-muted mb-1"><?= h_t("ml.risk_factors_title") ?></div>
        <?= ml_render_risk_factors($currentRiskFactors, 4) ?>
      </div>
    <?php endif; ?>
  </div>

  <div class="row g-3">
    <div class="col-lg-6">
      <div class="card shadow-sm mb-3">
        <div class="card-body">
          <div class="fw-semibold mb-2">Recent Performance</div>
          <?php if (!$performance): ?>
            <div class="text-muted small">No performance records yet.</div>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table table-sm mb-0 dg-table">
                <thead>
                  <tr>
                    <th>Period</th>
                    <th class="text-end">GPA</th>
                    <th class="text-end">Abs.</th>
                    <th class="text-end">Attend.</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($performance as $p): ?>
                    <?php
                      $daysP = (int)($p["days_present"] ?? 0);
                      $daysT = (int)($p["total_school_days"] ?? 0);
                      $att = ($daysT > 0) ? (($daysP / $daysT) * 100.0) : null;
                    ?>
                    <tr>
                      <td><?= htmlspecialchars(curriculum_term_label((string)($p["term_id"] ?? "")), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></td>
                      <td class="text-end"><?= number_format((float)$p["gpa"], 2) ?></td>
                      <td class="text-end"><?= (int)($p["absences"] ?? 0) ?></td>
                      <td class="text-end"><?= $att !== null ? number_format($att, 1) . "%" : "—" ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="col-lg-6">
      <div class="card shadow-sm mb-3">
        <div class="card-body">
          <div class="fw-semibold mb-2">Risk History</div>
          <?php if (!$riskHistory): ?>
            <div class="text-muted small">Run analysis to generate risk history.</div>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table table-sm mb-0 dg-table">
                <thead>
                  <tr>
                    <th>Generated</th>
                    <th class="text-end">Prob.</th>
                    <th><?= h_t("ml.risk_factors_title") ?></th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($riskHistory as $r): ?>
                    <?php
                      $lvl = $r["risk_level"] ?: "Low";
                      $c = $lvl === "High" ? "dg-risk-high" : ($lvl === "Moderate" ? "dg-risk-mod" : "dg-risk-low");
                      $histFactors = ml_parse_risk_factors($r["risk_factors_json"] ?? null);
                    ?>
                    <tr>
                      <td class="text-muted small"><?= htmlspecialchars((string)$r["generated_at"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></td>
                      <td class="text-end">
                        <?= number_format((float)$r["probability_score"], 4) ?>
                        <div>
                          <span class="dg-risk-chip <?= $c ?>" style="padding:4px 8px; font-size:.8rem;">
                            <span class="dot"></span><?= htmlspecialchars($lvl, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>
                          </span>
                        </div>
                        <?php if (!empty($r["term_id"])): ?>
                          <div class="text-muted small mt-1"><?= htmlspecialchars(curriculum_term_label((string)$r["term_id"]), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></div>
                        <?php endif; ?>
                      </td>
                      <td class="small">
                        <?php if ($histFactors): ?>
                          <?= ml_render_risk_factors($histFactors, 2) ?>
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
    </div>
  </div>

  <div class="card shadow-sm">
    <div class="card-body">
      <div class="fw-semibold mb-2">Enrolled Subjects</div>
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
  </div>

  <div class="card shadow-sm mt-3">
    <div class="card-body">
      <div class="fw-semibold mb-2">Subject Grades<?= $latestTermId !== "" ? " • " . htmlspecialchars(curriculum_term_label($latestTermId), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") : "" ?></div>
      <?php if (!$subjectGrades): ?>
        <div class="text-muted small">No subject grades recorded yet.</div>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
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
  </div>

  <?php if ($isAdmin): ?>
    <div class="card shadow-sm mt-3">
      <div class="card-body">
        <div class="fw-semibold mb-2">Edit Student</div>
        <?php $nm = dg_split_full_name((string)($student["name"] ?? "")); ?>
        <form method="post" action="student-list" class="d-grid gap-2">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="update_student" />
          <input type="hidden" name="student_id" value="<?= (int)$studentId ?>" />
          <input type="hidden" name="redirect" value="<?= htmlspecialchars((string)($_GET["redirect"] ?? ""), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" />
          <div class="row g-2">
            <div class="col-4">
              <input class="form-control form-control-sm" name="first_name" value="<?= htmlspecialchars($nm["first"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" placeholder="First name" required />
            </div>
            <div class="col-4">
              <input class="form-control form-control-sm" name="middle_name" value="<?= htmlspecialchars($nm["middle"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" placeholder="Middle name (optional)" />
            </div>
            <div class="col-4">
              <input class="form-control form-control-sm" name="last_name" value="<?= htmlspecialchars($nm["last"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" placeholder="Last name" required />
            </div>
          </div>
          <input class="form-control form-control-sm" name="lrn" value="<?= htmlspecialchars((string)($student["lrn"] ?? ""), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" placeholder="LRN (optional)" />
          <input class="form-control form-control-sm" type="number" name="gpa" min="0" max="100" step="1" value="<?= (int)round((float)($student["gpa"] ?? 0)) ?>" />
          <select class="form-select form-select-sm" name="school_year">
            <option value="">(Update latest)</option>
            <?php foreach ($schoolYears as $sy): ?>
              <?php $v = (string)($sy["school_year"] ?? ""); ?>
              <option value="<?= htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>">
                <?= htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>
              </option>
            <?php endforeach; ?>
          </select>
          <select class="form-select form-select-sm" name="grade_level" required id="dgModalGradeLevel">
            <?php foreach (["Grade 7","Grade 8","Grade 9","Grade 10","Grade 11","Grade 12"] as $g): ?>
              <option value="<?= htmlspecialchars($g, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" <?= $g === (string)$student["grade_level"] ? "selected" : "" ?>>
                <?= htmlspecialchars($g, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>
              </option>
            <?php endforeach; ?>
          </select>
          <div id="dgModalStrandRow" class="<?= $showStrandRow ? "" : "d-none" ?>">
            <label class="form-label small mb-0">Strand</label>
            <select class="form-select form-select-sm" name="strand" id="dgModalStrandSelect" <?= $showStrandRow ? "required" : "" ?>>
              <option value="">Select strand...</option>
              <?php curriculum_echo_shs_strand_options($stuStrand); ?>
            </select>
          </div>
          <select class="form-select form-select-sm" name="section" required id="dgModalSection">
            <option value="">Select section...</option>
            <?php foreach ($sections as $sec): ?>
              <?php
                $secGl = section_grade_level_for_name($sec) ?? "";
                $secSt = section_infer_strand_from_name($sec) ?? "";
              ?>
              <option value="<?= htmlspecialchars($sec, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" data-section-grade="<?= htmlspecialchars($secGl, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" data-section-strand="<?= htmlspecialchars($secSt, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" <?= $sec === (string)($student["section"] ?? "") ? "selected" : "" ?>>
                <?= htmlspecialchars(section_display_short($sec), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>
              </option>
            <?php endforeach; ?>
          </select>
          <button class="btn btn-sm btn-outline-primary" type="submit">Save</button>
        </form>
        <script>
          (function () {
            const gEl = document.getElementById("dgModalGradeLevel");
            const strandSel = document.getElementById("dgModalStrandSelect");
            const sEl = document.getElementById("dgModalSection");
            const strandRow = document.getElementById("dgModalStrandRow");
            if (!gEl || !sEl) return;
            function isShs(g) {
              return g === "Grade 11" || g === "Grade 12";
            }
            function syncStrandRow() {
              if (!strandRow || !strandSel) return;
              const g = gEl.value;
              const shs = isShs(g);
              strandRow.classList.toggle("d-none", !shs);
              strandSel.required = shs;
              if (!shs) strandSel.value = "";
            }
            function syncSections() {
              const g = gEl.value;
              const strand = strandSel && isShs(g) ? String(strandSel.value || "") : "";
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
            function syncAll() {
              syncStrandRow();
              syncSections();
            }
            gEl.addEventListener("change", syncAll);
            if (strandSel) strandSel.addEventListener("change", syncSections);
            syncAll();
          })();
        </script>
      </div>
    </div>
  <?php endif; ?>

  <div class="card shadow-sm">
    <div class="card-body">
      <div class="fw-semibold mb-2">Action Logs (Interventions)</div>
      <?php if (!$notes): ?>
        <div class="text-muted small">No intervention notes yet.</div>
      <?php else: ?>
        <div class="table-responsive mb-3">
          <table class="table table-sm mb-0 dg-table">
            <thead>
              <tr>
                <th>Date</th>
                <th>Note</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($notes as $n): ?>
                <tr>
                  <td class="text-muted small"><?= htmlspecialchars((string)$n["created_at"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></td>
                  <td><?= nl2br(htmlspecialchars((string)$n["note"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8")) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>

      <?php if (in_array($role, ["Counselor", "Admin"], true)): ?>
        <?php if (!empty((int)($student["is_archived"] ?? 0))): ?>
          <div class="text-muted small mb-2"><?= htmlspecialchars(t("student.modal_archived_hint"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></div>
        <?php endif; ?>
        <form method="post" action="student?id=<?= (int)$studentId ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="form_type" value="intervention" />
          <div class="mb-2">
            <label class="form-label">Add Intervention Note</label>
            <textarea class="form-control" name="note" rows="2" required minlength="3" placeholder="Add a short note..." <?= !empty((int)($student["is_archived"] ?? 0)) ? "disabled" : "" ?>></textarea>
          </div>
          <button class="btn btn-primary" type="submit" <?= !empty((int)($student["is_archived"] ?? 0)) ? "disabled" : "" ?>>Save Note</button>
          <button class="btn btn-outline-secondary ms-2" type="button" onclick="window.location.href='student?id=<?= (int)$studentId ?>'">Full page</button>
          <?php if (!$isTeacher || $teacherCanRefer): ?>
            <button class="btn btn-outline-dark ms-2" type="button" onclick="window.location.href='teacher-referral?student_id=<?= (int)$studentId ?>'">Manual Referral</button>
          <?php else: ?>
            <span
              class="d-inline-block ms-2"
              tabindex="0"
              data-bs-toggle="tooltip"
              data-bs-title="Add/import this student to your class list first."
            >
              <button class="btn btn-outline-secondary" type="button" disabled>Manual Referral</button>
            </span>
          <?php endif; ?>
        </form>
      <?php else: ?>
        <div class="text-muted small">
          Only Counselor/Admin can add intervention notes. View only.
        </div>
        <div class="mt-2">
          <button class="btn btn-outline-secondary" type="button" onclick="window.location.href='student?id=<?= (int)$studentId ?>'">Full page</button>
          <?php if (!$isTeacher || $teacherCanRefer): ?>
            <button class="btn btn-outline-dark ms-2" type="button" onclick="window.location.href='teacher-referral?student_id=<?= (int)$studentId ?>'">Manual Referral</button>
          <?php else: ?>
            <span
              class="d-inline-block ms-2"
              tabindex="0"
              data-bs-toggle="tooltip"
              data-bs-title="Add/import this student to your class list first."
            >
              <button class="btn btn-outline-secondary" type="button" disabled>Manual Referral</button>
            </span>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<script>
  (function () {
    if (!window.bootstrap) return;
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) {
      try { bootstrap.Tooltip.getOrCreateInstance(el); } catch (e) {}
    });
  })();
</script>
<?php

$content = ob_get_clean();
header("Content-Type: text/html; charset=utf-8");
echo $content;

