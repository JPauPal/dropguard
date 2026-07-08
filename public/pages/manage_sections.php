<?php
declare(strict_types=1);

require_once __DIR__ . "/../../app/auth.php";
require_once __DIR__ . "/../../app/sections.php";
require_once __DIR__ . "/../../app/workflow.php";

require_login();
require_role(["Admin"]);
ensure_workflow_tables();
ensure_sections_table();

start_app_session();
$flash = $_SESSION["flash"] ?? null;
unset($_SESSION["flash"]);
$error = null;

if (($_SERVER["REQUEST_METHOD"] ?? "GET") === "POST") {
    require_valid_csrf_token();
    $action = trim((string)($_POST["action"] ?? ""));
    if ($action === "add") {
        $gradeLevel = trim((string)($_POST["grade_level"] ?? ""));
        $short = trim((string)($_POST["section_short"] ?? ""));
        $strand = trim((string)($_POST["strand"] ?? ""));
        $allowedGrades = ["Grade 7", "Grade 8", "Grade 9", "Grade 10", "Grade 11", "Grade 12"];
        if (!in_array($gradeLevel, $allowedGrades, true)) {
            $error = t("manage_sections.err_pick_grade");
        } elseif ($short === "") {
            $error = t("manage_sections.err_section_name");
        } elseif (curriculum_is_senior_high_grade($gradeLevel) && ($strand === "" || !in_array($strand, curriculum_shs_strands(), true))) {
            $error = t("curriculum.err_strand_required_shs");
        } else {
            try {
                add_section_with_grade($gradeLevel, $short, curriculum_is_senior_high_grade($gradeLevel) ? $strand : null);
                $full = $gradeLevel . " - " . $short;
                audit_log("section_add", "success", "sections", null, "Added section.", [
                    "section_name" => $full,
                    "strand" => curriculum_is_senior_high_grade($gradeLevel) ? $strand : "",
                ]);
                $_SESSION["flash"] = t("manage_sections.flash_saved");
                header("Location: manage-sections");
                exit;
            } catch (InvalidArgumentException $e) {
                $error = $e->getMessage();
            }
        }
    } elseif ($action === "delete") {
        $name = trim((string)($_POST["section_name"] ?? ""));
        if ($name !== "") {
            delete_section_name($name);
            audit_log("section_delete", "success", "sections", null, "Deleted section.", ["section_name" => $name]);
            $_SESSION["flash"] = t("manage_sections.flash_removed");
            header("Location: manage-sections");
            exit;
        }
    }
}

$listGradeFilter = section_grade_filter_normalize(trim((string)($_GET["list_grade"] ?? "")));
$sectionsAll = list_section_rows();
$sections = $listGradeFilter === ""
    ? $sectionsAll
    : array_values(array_filter($sectionsAll, static function (array $r) use ($listGradeFilter): bool {
        return section_matches_grade_filter((string)($r["section_name"] ?? ""), $listGradeFilter);
    }));
$gradeLevels = ["Grade 7", "Grade 8", "Grade 9", "Grade 10", "Grade 11", "Grade 12"];

ob_start();
?>
<div class="dg-page-header mb-3">
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
    <div>
      <h3 class="mb-0"><?= htmlspecialchars(t("layout.nav.sections"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></h3>
      <div class="text-muted small"><?= h_t("manage_sections.subtitle") ?></div>
    </div>
  </div>
</div>

<?php if ($flash): ?>
  <div class="alert alert-success"><?= htmlspecialchars((string)$flash, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></div>
<?php endif; ?>
<?php if ($error): ?>
  <div class="alert alert-danger"><?= htmlspecialchars((string)$error, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-lg-5">
    <div class="card shadow-sm">
      <div class="card-body">
        <div class="fw-semibold mb-2"><?= h_t("manage_sections.add_title") ?></div>
        <form method="post" action="manage-sections" class="row g-2 align-items-end">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="add" />
          <div class="col-12">
            <label class="form-label"><?= h_t("manage_sections.year_level") ?></label>
            <select class="form-select" name="grade_level" id="dgManageSectionGrade" required>
              <option value=""><?= h_t("manage_sections.select_year") ?></option>
              <?php foreach ($gradeLevels as $g): ?>
                <option value="<?= htmlspecialchars($g, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>"><?= htmlspecialchars($g, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-12 d-none" id="dgManageSectionStrandWrap">
            <label class="form-label"><?= h_t("data_entry.strand") ?></label>
            <select class="form-select" name="strand" id="dgManageSectionStrand">
              <option value=""><?= h_t("data_entry.select_strand") ?></option>
              <?php curriculum_echo_shs_strand_options(); ?>
            </select>
            <div class="form-text"><?= h_t("manage_sections.strand_hint_long") ?></div>
          </div>
          <div class="col-12">
            <label class="form-label"><?= h_t("manage_sections.section_name") ?></label>
            <input class="form-control" name="section_short" placeholder="<?= h_t("manage_sections.section_ph") ?>" required />
            <div class="form-text"><?= t("manage_sections.section_help") ?></div>
          </div>
          <div class="col-12">
            <button class="btn btn-dark w-100" type="submit"><?= h_t("common.save") ?></button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="col-lg-7">
    <div class="card shadow-sm">
      <div class="card-body">
        <form method="get" action="manage-sections" class="row g-2 align-items-end mb-3">
          <div class="col-md-6">
            <label class="form-label small"><?= h_t("manage_sections.list_filter_label") ?></label>
            <select class="form-select form-select-sm" name="list_grade" onchange="this.form.submit()">
              <option value=""><?= h_t("manage_sections.list_all_years") ?></option>
              <?php foreach ($gradeLevels as $g): ?>
                <option value="<?= htmlspecialchars($g, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" <?= $g === $listGradeFilter ? "selected" : "" ?>>
                  <?= htmlspecialchars($g, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        </form>
        <div class="d-flex justify-content-between align-items-center mb-2">
          <div class="fw-semibold"><?= h_t("manage_sections.existing") ?></div>
          <div class="text-muted small"><?= htmlspecialchars($listGradeFilter !== "" ? tr("manage_sections.count_filtered", ["count" => (string)(int)count($sections)]) : tr("manage_sections.count_total", ["count" => (string)(int)count($sections)]), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></div>
        </div>
        <?php if (!$sectionsAll): ?>
          <div class="text-muted"><?= h_t("manage_sections.empty_all") ?></div>
        <?php elseif (!$sections): ?>
          <div class="text-muted"><?= h_t("manage_sections.empty_filter") ?></div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
              <thead>
                <tr>
                  <th><?= h_t("manage_sections.th_year") ?></th>
                  <th><?= h_t("manage_sections.th_section") ?></th>
                  <th><?= h_t("manage_sections.th_strand") ?></th>
                  <th class="text-end"><?= h_t("manage_sections.th_actions") ?></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($sections as $row): ?>
                  <?php
                    $sn = (string)($row["section_name"] ?? "");
                    $gl = trim((string)($row["grade_level"] ?? ""));
                    $st = $row["strand"] ?? null;
                  ?>
                  <tr>
                    <td class="text-muted small"><?= $gl !== "" ? htmlspecialchars($gl, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") : h_t("common.em_dash") ?></td>
                    <td><?= htmlspecialchars(section_display_short($sn), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></td>
                    <td class="small"><?= $st !== null && $st !== "" ? htmlspecialchars((string)$st, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") : h_t("common.em_dash") ?></td>
                    <td class="text-end">
                      <form class="d-inline" method="post" action="manage-sections" onsubmit="return confirm(<?= json_encode(t("manage_sections.confirm_delete"), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>);">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete" />
                        <input type="hidden" name="section_name" value="<?= htmlspecialchars($sn, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" />
                        <button class="btn btn-sm btn-outline-danger" type="submit"><?= h_t("common.delete") ?></button>
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
  </div>
</div>

<script>
  (function () {
    const g = document.getElementById("dgManageSectionGrade");
    const wrap = document.getElementById("dgManageSectionStrandWrap");
    const st = document.getElementById("dgManageSectionStrand");
    if (!g || !wrap || !st) return;
    function sync() {
      const shs = g.value === "Grade 11" || g.value === "Grade 12";
      wrap.classList.toggle("d-none", !shs);
      st.required = shs;
      if (!shs) st.value = "";
    }
    g.addEventListener("change", sync);
    sync();
  })();
</script>

<?php
$content = ob_get_clean();
require __DIR__ . "/_layout.php";

