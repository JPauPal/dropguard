<?php
declare(strict_types=1);

require_once __DIR__ . "/../../app/auth.php";
require_once __DIR__ . "/../../app/subjects.php";
require_once __DIR__ . "/../../app/sections.php";
require_once __DIR__ . "/../../app/workflow.php";

require_login();
require_role(["Admin"]);
ensure_workflow_tables();
ensure_subjects_table();

start_app_session();
$flash = $_SESSION["flash"] ?? null;
unset($_SESSION["flash"]);
$error = null;

$gradeLevels = ["", "Grade 7", "Grade 8", "Grade 9", "Grade 10", "Grade 11", "Grade 12"];

if (($_SERVER["REQUEST_METHOD"] ?? "GET") === "POST") {
    require_valid_csrf_token();
    $action = trim((string)($_POST["action"] ?? ""));

    if ($action === "add_subject") {
        $code = normalize_subject_code((string)($_POST["subject_code"] ?? ""));
        $name = trim((string)($_POST["subject_name"] ?? ""));
        $gradeLevel = trim((string)($_POST["grade_level"] ?? ""));
        if ($code === "") {
            $error = "Subject code is required.";
        } elseif ($name === "") {
            $error = "Subject name is required.";
        } elseif (!in_array($gradeLevel, $gradeLevels, true)) {
            $error = "Invalid grade level.";
        } else {
            add_subject($code, $name, $gradeLevel !== "" ? $gradeLevel : null);
            audit_log("subject_save", "success", "subjects", null, "Saved subject.", ["subject_code" => $code]);
            $_SESSION["flash"] = "Subject saved.";
            header("Location: manage-subjects");
            exit;
        }
    } elseif ($action === "deactivate_subject") {
        $subjectId = (int)($_POST["subject_id"] ?? 0);
        if ($subjectId > 0) {
            deactivate_subject($subjectId);
            audit_log("subject_deactivate", "success", "subjects", $subjectId, "Deactivated subject.");
            $_SESSION["flash"] = "Subject deactivated.";
        }
        header("Location: manage-subjects");
        exit;
    } elseif ($action === "delete_subject") {
        $subjectId = (int)($_POST["subject_id"] ?? 0);
        if ($subjectId > 0) {
            $result = delete_subject($subjectId);
            if ($result["ok"]) {
                audit_log("subject_delete", "success", "subjects", $subjectId, "Deleted subject.", [
                    "subject_code" => (string)($result["subject_code"] ?? ""),
                ]);
                $_SESSION["flash"] = "Subject deleted.";
            } else {
                audit_log("subject_delete", "failure", "subjects", $subjectId, "Failed to delete subject.", [
                    "subject_code" => (string)($result["subject_code"] ?? ""),
                    "error" => (string)($result["error"] ?? ""),
                ]);
                $error = (string)($result["error"] ?? "Subject could not be deleted.");
            }
        }
        if ($error === null) {
            header("Location: manage-subjects");
            exit;
        }
    } elseif ($action === "assign_subject") {
        $sectionName = trim((string)($_POST["section_name"] ?? ""));
        $subjectId = (int)($_POST["subject_id"] ?? 0);
        if ($sectionName === "") {
            $error = "Section is required.";
        } elseif ($subjectId <= 0) {
            $error = "Subject is required.";
        } else {
            assign_subject_to_section($sectionName, $subjectId);
            audit_log("section_subject_assign", "success", "section_subjects", $subjectId, "Assigned subject to section.", [
                "section_name" => $sectionName,
            ]);
            $_SESSION["flash"] = "Subject assigned to section.";
            $gf = section_grade_filter_normalize(trim((string)($_POST["sections_grade"] ?? "")));
            $gq = $gf !== "" ? "&sections_grade=" . urlencode($gf) : "";
            header("Location: manage-subjects?section=" . urlencode($sectionName) . $gq);
            exit;
        }
    } elseif ($action === "remove_assignment") {
        $sectionName = trim((string)($_POST["section_name"] ?? ""));
        $subjectId = (int)($_POST["subject_id"] ?? 0);
        if ($sectionName !== "" && $subjectId > 0) {
            remove_subject_from_section($sectionName, $subjectId);
            audit_log("section_subject_remove", "success", "section_subjects", $subjectId, "Removed subject from section.", [
                "section_name" => $sectionName,
            ]);
            $_SESSION["flash"] = "Subject removed from section.";
        }
        $gf = section_grade_filter_normalize(trim((string)($_POST["sections_grade"] ?? "")));
        $gq = $gf !== "" ? "&sections_grade=" . urlencode($gf) : "";
        header("Location: manage-subjects?section=" . urlencode($sectionName) . $gq);
        exit;
    }
}

$subjects = list_subjects(false);
$activeSubjects = array_values(array_filter($subjects, static fn($s) => (int)($s["is_active"] ?? 0) === 1));
$sectionsGradeFilter = section_grade_filter_normalize(trim((string)($_GET["sections_grade"] ?? $_POST["sections_grade"] ?? "")));
$sectionsAll = list_section_names();
$sections = $sectionsGradeFilter === ""
    ? $sectionsAll
    : array_values(array_filter($sectionsAll, static function (string $s) use ($sectionsGradeFilter): bool {
        return section_matches_grade_filter($s, $sectionsGradeFilter);
    }));
$selectedSection = trim((string)($_GET["section"] ?? ""));
if ($selectedSection !== "" && $sectionsGradeFilter !== "" && !section_matches_grade_filter($selectedSection, $sectionsGradeFilter)) {
    $selectedSection = "";
}
if ($selectedSection === "" && $sections !== []) {
    $selectedSection = $sections[0];
}
$sectionSubjects = $selectedSection !== "" ? list_section_subjects($selectedSection) : [];

ob_start();
?>
<div class="dg-page-header mb-3">
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
    <div>
      <h3 class="mb-0"><?= htmlspecialchars(t("layout.nav.subjects"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></h3>
      <div class="text-muted small">Maintain subject codes and subscribe sections to the subjects they take.</div>
    </div>
  </div>
</div>

<?php if ($flash): ?><div class="alert alert-success"><?= htmlspecialchars((string)$flash, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></div><?php endif; ?>

<div class="row g-3">
  <div class="col-lg-5">
    <div class="card shadow-sm mb-3">
      <div class="card-body">
        <div class="fw-semibold mb-2">Add / Update Subject</div>
        <form method="post" action="manage-subjects" class="row g-2">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="add_subject" />
          <div class="col-md-4">
            <label class="form-label small">Code</label>
            <input class="form-control" name="subject_code" placeholder="MATH7" required />
          </div>
          <div class="col-md-8">
            <label class="form-label small">Subject Name</label>
            <input class="form-control" name="subject_name" placeholder="Mathematics" required />
          </div>
          <div class="col-12">
            <label class="form-label small">Grade Level</label>
            <select class="form-select" name="grade_level">
              <option value="">All / Shared</option>
              <?php foreach (array_slice($gradeLevels, 1) as $g): ?>
                <option value="<?= htmlspecialchars($g, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>"><?= htmlspecialchars($g, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-12">
            <button class="btn btn-dark w-100" type="submit">Save Subject</button>
          </div>
        </form>
      </div>
    </div>

    <div class="card shadow-sm">
      <div class="card-body">
        <div class="fw-semibold mb-2">Subject List</div>
        <?php if (!$subjects): ?>
          <div class="text-muted small">No subjects yet.</div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
              <thead>
                <tr>
                  <th>Code</th>
                  <th>Subject</th>
                  <th>Status</th>
                  <th class="text-end">Action</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($subjects as $subject): ?>
                  <tr>
                    <td class="fw-semibold"><?= htmlspecialchars((string)$subject["subject_code"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></td>
                    <td>
                      <?= htmlspecialchars((string)$subject["subject_name"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>
                      <div class="text-muted small"><?= htmlspecialchars((string)($subject["grade_level"] ?? "All / Shared"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></div>
                    </td>
                    <td><span class="badge text-bg-<?= (int)$subject["is_active"] === 1 ? "success" : "secondary" ?>"><?= (int)$subject["is_active"] === 1 ? "Active" : "Inactive" ?></span></td>
                    <td class="text-end">
                      <div class="d-flex flex-wrap justify-content-end gap-1">
                        <?php if ((int)$subject["is_active"] === 1): ?>
                          <form method="post" action="manage-subjects" onsubmit="return confirm('Deactivate this subject?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="deactivate_subject" />
                            <input type="hidden" name="subject_id" value="<?= (int)$subject["subject_id"] ?>" />
                            <button class="btn btn-sm btn-outline-warning" type="submit">Deactivate</button>
                          </form>
                        <?php endif; ?>
                        <form method="post" action="manage-subjects" onsubmit="return confirm('Permanently delete this subject? This cannot be undone.');">
                          <?= csrf_field() ?>
                          <input type="hidden" name="action" value="delete_subject" />
                          <input type="hidden" name="subject_id" value="<?= (int)$subject["subject_id"] ?>" />
                          <button class="btn btn-sm btn-outline-danger" type="submit">Delete</button>
                        </form>
                      </div>
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

  <div class="col-lg-7">
    <div class="card shadow-sm">
      <div class="card-body">
        <div class="fw-semibold mb-2">Subject Subscription</div>
        <?php if (!$sectionsAll): ?>
          <div class="text-muted small">Add sections first before assigning subjects.</div>
        <?php elseif (!$sections): ?>
          <div class="text-muted small">No sections for this year level.</div>
        <?php else: ?>
          <form method="get" action="manage-subjects" class="row g-2 align-items-end mb-3">
            <div class="col-md-6">
              <label class="form-label small">Year level</label>
              <select class="form-select" name="sections_grade" onchange="this.form.submit()">
                <option value="">All year levels</option>
                <?php foreach (array_slice($gradeLevels, 1) as $g): ?>
                  <option value="<?= htmlspecialchars($g, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" <?= $g === $sectionsGradeFilter ? "selected" : "" ?>>
                    <?= htmlspecialchars($g, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label small">Section</label>
              <select class="form-select" name="section" onchange="this.form.submit()">
                <?php foreach ($sections as $section): ?>
                  <option value="<?= htmlspecialchars($section, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" <?= $section === $selectedSection ? "selected" : "" ?>>
                    <?= htmlspecialchars(section_display_short($section), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
          </form>

          <form method="post" action="manage-subjects" class="row g-2 align-items-end mb-3">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="assign_subject" />
            <input type="hidden" name="section_name" value="<?= htmlspecialchars($selectedSection, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" />
            <?php if ($sectionsGradeFilter !== ""): ?>
              <input type="hidden" name="sections_grade" value="<?= htmlspecialchars($sectionsGradeFilter, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" />
            <?php endif; ?>
            <div class="col-md-8">
              <label class="form-label small">Add Subject</label>
              <select class="form-select" name="subject_id" required>
                <option value="">Select subject...</option>
                <?php foreach ($activeSubjects as $subject): ?>
                  <option value="<?= (int)$subject["subject_id"] ?>">
                    <?= htmlspecialchars(subject_label($subject), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <button class="btn btn-dark w-100" type="submit">Subscribe</button>
            </div>
          </form>

          <?php if (!$sectionSubjects): ?>
            <div class="text-muted small">No subjects subscribed to this section yet.</div>
          <?php else: ?>
            <div class="d-flex flex-wrap gap-2">
              <?php foreach ($sectionSubjects as $subject): ?>
                <form method="post" action="manage-subjects" class="d-inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="remove_assignment" />
                  <input type="hidden" name="section_name" value="<?= htmlspecialchars($selectedSection, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" />
                  <?php if ($sectionsGradeFilter !== ""): ?>
                    <input type="hidden" name="sections_grade" value="<?= htmlspecialchars($sectionsGradeFilter, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" />
                  <?php endif; ?>
                  <input type="hidden" name="subject_id" value="<?= (int)$subject["subject_id"] ?>" />
                  <button class="btn btn-sm btn-outline-secondary" type="submit" title="Remove from section">
                    <?= htmlspecialchars(subject_label($subject), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?> &times;
                  </button>
                </form>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . "/_layout.php";

