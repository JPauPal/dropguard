<?php
declare(strict_types=1);

require_once __DIR__ . "/../../app/auth.php";
require_once __DIR__ . "/../../app/db.php";
require_once __DIR__ . "/../../app/marks.php";
require_once __DIR__ . "/../../app/batches.php";
require_once __DIR__ . "/../../app/ml.php";
require_once __DIR__ . "/../../app/workflow.php";
require_once __DIR__ . "/../../app/curriculum.php";
require_once __DIR__ . "/../../app/sections.php";
require_once __DIR__ . "/../../app/students.php";

require_login();
$u = current_user();
if (($u["role"] ?? "") === "Teacher") {
    header("Location: data-entry");
    exit;
}
require_role(["Counselor", "Admin"]);
ensure_student_marks_table();
ensure_student_batches_table();
ensure_workflow_tables();
ensure_student_sections();
ensure_students_archived_column();
ensure_grading_components_table();

$user = current_user();

start_app_session();
$flash = $_SESSION["flash"] ?? null;
unset($_SESSION["flash"]);
$mlRunMeta = $_SESSION["ml_run_meta"] ?? null;
unset($_SESSION["ml_run_meta"]);
ml_ensure_risk_factors_schema();

// Counselor/Admin: full dashboard. Teacher: read-only list.
$canRunMl = in_array($user["role"], ["Counselor", "Admin"], true);

$gradeFilter = trim((string)($_GET["grade_level"] ?? ""));
$sectionFilter = trim((string)($_GET["section"] ?? ""));
$strandFilter = trim((string)($_GET["strand"] ?? ""));
$schoolYearFilter = trim((string)($_GET["school_year"] ?? ""));
$search = trim((string)($_GET["search"] ?? ""));
if ($gradeFilter !== "" && $sectionFilter !== "" && !section_matches_grade_filter($sectionFilter, $gradeFilter)) {
    $sectionFilter = "";
}
if ($strandFilter !== "" && curriculum_is_senior_high_grade($gradeFilter !== "" ? $gradeFilter : null) && $sectionFilter !== "" && !section_matches_strand_filter($sectionFilter, $strandFilter)) {
    $sectionFilter = "";
}
if (!curriculum_is_senior_high_grade($gradeFilter !== "" ? $gradeFilter : null)) {
    $strandFilter = "";
}
if ($strandFilter !== "" && !in_array($strandFilter, curriculum_shs_strands(), true)) {
    $strandFilter = "";
}
$params = [];
$whereClauses = [];
if ($gradeFilter !== "") {
    $whereClauses[] = "s.grade_level = ?";
    $params[] = $gradeFilter;
}
if ($sectionFilter !== "") {
    $whereClauses[] = "s.section = ?";
    $params[] = $sectionFilter;
}
if ($strandFilter !== "" && curriculum_is_senior_high_grade($gradeFilter !== "" ? $gradeFilter : null)) {
    $whereClauses[] = "s.strand = ?";
    $params[] = $strandFilter;
}
if ($schoolYearFilter !== "") {
    $whereClauses[] = "sby.school_year = ?";
    $params[] = $schoolYearFilter;
}
if ($search !== "") {
    $whereClauses[] = "(s.name LIKE ? OR s.lrn LIKE ?)";
    $params[] = "%" . $search . "%";
    $params[] = "%" . $search . "%";
}
$whereClauses[] = students_non_archived_sql("s");
$where = "WHERE " . implode(" AND ", $whereClauses);

$rowsStmt = db()->prepare(
    "SELECT s.student_id, s.lrn, s.name, s.grade_level, s.strand, s.section,
            COALESCE(p.gpa, s.gpa) AS gpa,
            COALESCE(p.absences, s.absences) AS absences,
            COALESCE(p.consecutive_absences, 0) AS consecutive_absences,
            COALESCE(p.days_present, 0) AS days_present,
            COALESCE(p.total_school_days, 0) AS total_school_days,
            sby.school_year,
            CASE WHEN COALESCE(p.total_school_days,0) > 0
                 THEN (COALESCE(p.days_present,0) / COALESCE(p.total_school_days,1)) * 100
                 ELSE NULL END AS attendance_pct,
            s.risk_score, s.risk_level, s.risk_factors_json,
            COALESCE(cc.status, 'Flagged') AS case_status,
            COALESCE(gc_fb.subject_min, 0) AS subject_min,
            COALESCE(NULLIF(p.failing_subjects, 0), gc_fb.failing_subjects, 0) AS failing_subjects
     FROM students s
     LEFT JOIN (
        SELECT p1.*
        FROM performance p1
        INNER JOIN (
          SELECT student_id, MAX(quarter) AS max_quarter
          FROM performance
          GROUP BY student_id
        ) latest ON latest.student_id = p1.student_id AND latest.max_quarter = p1.quarter
     ) p ON p.student_id = s.student_id
     LEFT JOIN (
        SELECT b1.student_id, b1.school_year
        FROM student_batches b1
        INNER JOIN (
          SELECT student_id, MAX(batch_id) AS max_batch_id
          FROM student_batches
          GROUP BY student_id
        ) latestb ON latestb.student_id = b1.student_id AND latestb.max_batch_id = b1.batch_id
     ) sby ON sby.student_id = s.student_id
     LEFT JOIN (
        SELECT student_id,
               MIN(final_score) AS subject_min,
               COUNT(DISTINCT CASE WHEN final_score < 75 THEN subject_id END) AS failing_subjects
        FROM grading_components
        WHERE subject_id <> 0 AND is_final = 1
        GROUP BY student_id
     ) gc_fb ON gc_fb.student_id = s.student_id
     LEFT JOIN counseling_cases cc ON cc.student_id = s.student_id
     {$where}
     ORDER BY FIELD(s.risk_level,'High','Moderate','Low'), s.risk_score DESC, s.name ASC"
);
$rowsStmt->execute($params);
$rows = $rowsStmt->fetchAll();

$studentIds = array_map(static fn($r) => (int)$r["student_id"], $rows);
$flagsByStudent = [];
if ($studentIds) {
    $placeholders = implode(",", array_fill(0, count($studentIds), "?"));
    $flagStmt = db()->prepare(
        "SELECT flag_id, student_id, issue_type, severity, note
         FROM student_flags
         WHERE student_id IN ({$placeholders}) AND is_active = 1
         ORDER BY created_at DESC"
    );
    $flagStmt->execute($studentIds);
    foreach ($flagStmt->fetchAll() as $fl) {
        $sid = (int)$fl["student_id"];
        if (!isset($flagsByStudent[$sid])) {
            $flagsByStudent[$sid] = [];
        }
        $flagsByStudent[$sid][] = $fl;
    }
}

$gradeLevels = curriculum_sort_rows_by_grade_level(
    db()->query("SELECT DISTINCT grade_level FROM students WHERE COALESCE(is_archived,0) = 0")->fetchAll()
);
$sectionsAll = list_sections(null);
$schoolYears = db()->query("SELECT DISTINCT school_year FROM student_batches ORDER BY school_year DESC")->fetchAll();

$dashFilterQuery = http_build_query(array_filter([
    "grade_level" => $gradeFilter,
    "section" => $sectionFilter,
    "strand" => $strandFilter,
    "school_year" => $schoolYearFilter,
    "search" => $search,
], static fn($v) => $v !== null && $v !== ""));
$dashRedirect = "dashboard" . ($dashFilterQuery !== "" ? "?" . $dashFilterQuery : "");

$trendRows = db()->query(
    "SELECT DATE_FORMAT(generated_at, '%Y-%m') AS ym,
            SUM(CASE WHEN risk_level = 'High' THEN 1 ELSE 0 END) AS high_count
     FROM risk_analysis
     GROUP BY DATE_FORMAT(generated_at, '%Y-%m')
     ORDER BY ym ASC
     LIMIT 12"
)->fetchAll();
$trendLabels = array_map(static fn($r) => (string)$r["ym"], $trendRows);
$trendHigh = array_map(static fn($r) => (int)$r["high_count"], $trendRows);

$referrals = db()->query(
    "SELECT r.referral_id, r.created_at, s.name AS student_name, s.grade_level, s.strand, u.full_name AS teacher_name, r.reason, r.status
     FROM manual_referrals r
     INNER JOIN students s ON s.student_id = r.student_id
     INNER JOIN users u ON u.user_id = r.teacher_user_id
     WHERE r.status IN ('New','Reviewed') AND COALESCE(s.is_archived,0) = 0
     ORDER BY r.created_at DESC
     LIMIT 20"
)->fetchAll();
foreach ($referrals as &$rf) {
    if (isset($rf["teacher_name"]) && $rf["teacher_name"] !== "") {
        $rf["teacher_name"] = db_field_decrypt((string)$rf["teacher_name"]);
    }
    if (isset($rf["reason"]) && $rf["reason"] !== "") {
        $rf["reason"] = db_field_decrypt((string)$rf["reason"]);
    }
}
unset($rf);

$dist = ["Low" => 0, "Moderate" => 0, "High" => 0];
$byGrade = [];
$absByGrade = [];
$absCountByGrade = [];
$flagsCountByGrade = [];
foreach ($rows as $r) {
    $storedScore = (float)($r["risk_score"] ?? 0.0);
    $storedLevel = (string)($r["risk_level"] ?? "");
    $hasAnySignal = ((float)($r["gpa"] ?? 0.0) > 0.0)
        || ((int)($r["absences"] ?? 0) > 0)
        || ((int)($r["total_school_days"] ?? 0) > 0)
        || ((int)($r["consecutive_absences"] ?? 0) > 0)
        || ml_student_academic_failing_meta($r)["has_signal"];

    // If ML hasn't been run yet (default score/level), estimate risk from current metrics
    // so charts don't misleadingly show everything as Low.
    $sid = (int)($r["student_id"] ?? 0);
    $flagMeta = $flagsByStudent[$sid] ?? [];
    $activeFlagCount = is_array($flagMeta) ? count($flagMeta) : 0;
    $maxFlagSeverity = 0;
    $highFlagCount = 0;
    $moderateFlagCount = 0;
    if (is_array($flagMeta)) {
        foreach ($flagMeta as $fl) {
            $sev = (string)($fl["severity"] ?? "Moderate");
            $v = $sev === "High" ? 3 : ($sev === "Moderate" ? 2 : 1);
            if ($v > $maxFlagSeverity) $maxFlagSeverity = $v;
            if ($sev === "High") $highFlagCount += 1;
            if ($sev === "Moderate") $moderateFlagCount += 1;
        }
    }

    $score = ($storedScore <= 0.0001 && $storedLevel === "Low" && ($hasAnySignal || $activeFlagCount > 0))
        ? estimate_risk_score(
            (float)($r["gpa"] ?? 0.0),
            (int)($r["absences"] ?? 0),
            (int)($r["days_present"] ?? 0),
            (int)($r["total_school_days"] ?? 0),
            (int)($r["consecutive_absences"] ?? 0),
            $activeFlagCount,
            $maxFlagSeverity,
            0,
            0,
            (int)($r["failing_subjects"] ?? 0),
            (float)($r["subject_min"] ?? 0)
        )
        : $storedScore;

    $lvl = $storedLevel !== "" ? $storedLevel : classify_risk_level($score);
    if ($storedLevel === "" || ($storedLevel === "Low" && $storedScore <= 0.0001)) {
        if ($highFlagCount >= 3 || ($highFlagCount >= 2 && $moderateFlagCount >= 1)) {
            $t = get_risk_thresholds();
            $hiMin = (float)($t["high_min"] ?? 0.70);
            $score = max($score, min(1.0, $hiMin + 0.0001));
            $lvl = "High";
        }
    }
    if (!isset($dist[$lvl])) {
        $dist[$lvl] = 0;
    }
    $dist[$lvl] += 1;
    $g = $r["grade_level"] ?: "Unknown";
    if (!isset($byGrade[$g])) {
        $byGrade[$g] = ["Low" => 0, "Moderate" => 0, "High" => 0];
    }
    if (!isset($byGrade[$g][$lvl])) {
        $byGrade[$g][$lvl] = 0;
    }
    $byGrade[$g][$lvl] += 1;

    if (!isset($absByGrade[$g])) {
        $absByGrade[$g] = 0;
        $absCountByGrade[$g] = 0;
        $flagsCountByGrade[$g] = 0;
    }
    $absByGrade[$g] += (int)($r["absences"] ?? 0);
    $absCountByGrade[$g] += 1;
    $flagsCountByGrade[$g] += isset($flagsByStudent[$sid]) ? count($flagsByStudent[$sid]) : 0;
}

ob_start();
?>
<?php if ($flash): ?>
  <div class="alert alert-info"><?= htmlspecialchars($flash, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></div>
<?php endif; ?>
<?php if (is_array($mlRunMeta)): ?>
  <?php
    $mlMode = (string)($mlRunMeta["mode"] ?? "heuristic");
    $mlModeLabel = $mlMode === "random_forest" ? t("ml.mode_rf") : t("ml.mode_heuristic");
    $mlModeClass = $mlMode === "random_forest" ? "dg-ml-mode-rf" : "dg-ml-mode-heuristic";
    $mlMetrics = is_array($mlRunMeta["metrics"] ?? null) ? $mlRunMeta["metrics"] : null;
  ?>
  <div class="alert alert-light border dg-ml-run-banner mb-3">
    <div class="d-flex flex-wrap align-items-center gap-2">
      <span class="dg-ml-mode-badge <?= htmlspecialchars($mlModeClass, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>">
        <?= htmlspecialchars($mlModeLabel, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>
      </span>
      <span class="small text-muted">
        <?= htmlspecialchars(tr("ml.run_banner_updated", ["count" => (string)(int)($mlRunMeta["updated"] ?? 0)]), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>
      </span>
      <?php if ($mlMetrics): ?>
        <span class="small text-muted ms-md-2">
          <?= h_t("ml.metrics_accuracy") ?>: <?= htmlspecialchars((string)($mlMetrics["accuracy"] ?? "—"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>
          · F1: <?= htmlspecialchars((string)($mlMetrics["f1_score"] ?? "—"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>
        </span>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>
<?php
$dgRefStatusLabel = static function (string $st): string {
    return match ($st) {
        "New" => t("referral.status_new"),
        "Reviewed" => t("referral.status_reviewed"),
        default => $st !== "" ? $st : t("referral.status_other"),
    };
};
?>
<div class="dg-page-header mb-3">
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
    <div>
      <h3 class="mb-0"><?= h_t("dashboard.title") ?></h3>
      <div class="text-muted small"><?= h_t("dashboard.legend_risk") ?></div>
    </div>
    <div class="d-flex gap-2">
      <?php if ($canRunMl): ?>
        <form method="get" action="run-ml" class="d-inline" onsubmit="return confirm(<?= json_encode(t("dashboard.run_analysis_confirm"), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>);">
          <button type="submit" class="btn btn-primary"><?= h_t("dashboard.run_analysis") ?></button>
        </form>
      <?php endif; ?>
      <?php if (($user["role"] ?? "") === "Admin"): ?>
        <a class="btn btn-outline-secondary" href="data-entry"><?= h_t("dashboard.encode_data") ?></a>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php
  $total = count($rows);
  $high = $dist["High"] ?? 0;
  $mod = $dist["Moderate"] ?? 0;
  $low = $dist["Low"] ?? 0;
?>
<div class="row g-3 mb-3 dg-kpi-row">
  <div class="col-6 col-md-3">
    <div class="dg-kpi dg-kpi--total p-3">
      <div class="dg-kpi-label"><?= h_t("dashboard.kpi_total") ?></div>
      <div class="dg-kpi-value"><?= (int)$total ?></div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="dg-kpi dg-kpi--high p-3">
      <div class="dg-kpi-label"><span class="dg-kpi-dot" aria-hidden="true"></span> <?= h_t("dashboard.kpi_high") ?></div>
      <div class="dg-kpi-value"><?= (int)$high ?></div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="dg-kpi dg-kpi--mod p-3">
      <div class="dg-kpi-label"><span class="dg-kpi-dot" aria-hidden="true"></span> <?= h_t("dashboard.kpi_mod") ?></div>
      <div class="dg-kpi-value"><?= (int)$mod ?></div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="dg-kpi dg-kpi--low p-3">
      <div class="dg-kpi-label"><span class="dg-kpi-dot" aria-hidden="true"></span> <?= h_t("dashboard.kpi_low") ?></div>
      <div class="dg-kpi-value"><?= (int)$low ?></div>
    </div>
  </div>
</div>

<div class="card shadow-sm mb-3 dg-filter-panel">
  <div class="card-body">
    <form id="dgDashboardFilter" class="row g-2 align-items-end" method="get" action="dashboard">
      <div class="col-6 col-md-2">
        <label class="form-label mb-1"><?= h_t("dashboard.filter_grade") ?></label>
        <select class="form-select" name="grade_level" id="dgDashFilterGrade">
          <option value=""><?= h_t("common.all") ?></option>
          <?php foreach ($gradeLevels as $g): ?>
            <?php $val = (string)($g["grade_level"] ?? ""); ?>
            <option value="<?= htmlspecialchars($val, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" <?= $val === $gradeFilter ? "selected" : "" ?>>
              <?= htmlspecialchars($val, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-6 col-md-2 <?= curriculum_is_senior_high_grade($gradeFilter !== "" ? $gradeFilter : null) ? "" : "d-none" ?>" id="dgDashStrandWrap">
        <label class="form-label mb-1"><?= h_t("dashboard.filter_strand") ?></label>
        <select class="form-select" name="strand" id="dgDashStrand" <?= curriculum_is_senior_high_grade($gradeFilter !== "" ? $gradeFilter : null) ? "" : "disabled" ?>>
          <option value=""><?= h_t("common.all") ?></option>
          <?php curriculum_echo_shs_strand_options($strandFilter); ?>
        </select>
      </div>
      <div class="col-12 col-md-3">
        <label class="form-label mb-1"><?= h_t("dashboard.filter_section") ?></label>
        <select class="form-select" name="section" id="dgDashSection">
          <option value=""><?= h_t("common.all") ?></option>
          <?php foreach ($sectionsAll as $sec): ?>
            <?php
              $secGl = section_grade_level_for_name($sec) ?? "";
              $secSt = section_infer_strand_from_name($sec) ?? "";
            ?>
            <option value="<?= htmlspecialchars($sec, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" data-section-grade="<?= htmlspecialchars($secGl, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" data-section-strand="<?= htmlspecialchars($secSt, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" <?= $sec === $sectionFilter ? "selected" : "" ?>>
              <?= htmlspecialchars(section_display_short($sec), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label mb-1"><?= h_t("dashboard.filter_school_year") ?></label>
        <select class="form-select" name="school_year" id="dgDashSchoolYear">
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
        <label class="form-label mb-1"><?= h_t("dashboard.filter_search_student") ?></label>
        <input class="form-control" name="search" id="dgDashSearch" value="<?= htmlspecialchars($search, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" placeholder="<?= h_t("dashboard.search_placeholder") ?>" />
      </div>
      <div class="col-6 col-md-2">
        <button class="btn btn-dark w-100" type="submit"><?= h_t("common.apply_search") ?></button>
      </div>
      <?php if (($user["role"] ?? "") === "Counselor"): ?>
        <div class="col-12 col-md-1">
          <div class="dg-counselor-note small text-muted"><?= h_t("dashboard.counselor_view") ?></div>
        </div>
      <?php endif; ?>
    </form>
    <div class="form-text small mt-1"><?= h_t("dashboard.filter_hint") ?></div>
  </div>
</div>

<div class="row g-3 mb-3">
  <div class="col-md-4">
    <div class="card dg-chart-card">
      <div class="card-body">
        <div class="fw-semibold mb-2"><?= h_t("dashboard.chart_overall") ?></div>
        <canvas id="overallChart" height="200"></canvas>
      </div>
    </div>
  </div>
  <div class="col-md-8">
    <div class="card dg-chart-card">
      <div class="card-body">
        <div class="fw-semibold mb-2"><?= h_t("dashboard.chart_grade") ?></div>
        <canvas id="gradeChart" height="200"></canvas>
      </div>
    </div>
  </div>
</div>

<div class="row g-3 mb-3">
  <div class="col-md-6">
    <div class="card shadow-sm dg-chart-card">
      <div class="card-body">
        <div class="fw-semibold mb-2"><?= h_t("dashboard.chart_absences") ?></div>
        <div class="text-muted small mb-2"><?= h_t("dashboard.chart_absences_sub") ?></div>
        <canvas id="absencesChart" height="160"></canvas>
      </div>
    </div>
  </div>
  <div class="col-md-6">
    <div class="card shadow-sm dg-chart-card">
      <div class="card-body">
        <div class="fw-semibold mb-2"><?= h_t("dashboard.chart_flags") ?></div>
        <div class="text-muted small mb-2"><?= h_t("dashboard.chart_flags_sub") ?></div>
        <canvas id="flagsChart" height="160"></canvas>
      </div>
    </div>
  </div>
</div>

<div class="card shadow-sm mb-3 dg-chart-card">
  <div class="card-body">
    <div class="fw-semibold mb-2"><?= h_t("dashboard.chart_trend") ?></div>
    <canvas id="highTrendChart" height="110"></canvas>
  </div>
</div>

<div class="card shadow-sm mb-3 dg-chart-card">
  <div class="card-body">
    <div class="fw-semibold mb-2"><?= h_t("dashboard.referrals_title") ?></div>
    <?php if (!$referrals): ?>
      <div class="text-muted small"><?= h_t("dashboard.referrals_empty") ?></div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-sm dg-table align-middle mb-0">
          <thead>
            <tr>
              <th><?= h_t("common.date") ?></th>
              <th><?= h_t("dashboard.th_student") ?></th>
              <th><?= h_t("dashboard.th_teacher") ?></th>
              <th><?= h_t("common.reason") ?></th>
              <th><?= h_t("common.status") ?></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($referrals as $rf): ?>
              <tr>
                <td class="small text-muted"><?= htmlspecialchars((string)$rf["created_at"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></td>
                <td>
                  <?= htmlspecialchars((string)$rf["student_name"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>
                  <span class="text-muted small">(<?= htmlspecialchars((string)$rf["grade_level"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?><?php if (!empty($rf["strand"])): ?>, <?= htmlspecialchars((string)$rf["strand"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?><?php endif; ?>)</span>
                </td>
                <td><?= htmlspecialchars((string)$rf["teacher_name"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></td>
                <td><?= htmlspecialchars((string)$rf["reason"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></td>
                <?php
                  $st = (string)($rf["status"] ?? "New");
                  $stClass = $st === "New" ? "danger" : ($st === "Reviewed" ? "warning" : "secondary");
                ?>
                <td><span class="badge text-bg-<?= $stClass ?>"><?= htmlspecialchars($dgRefStatusLabel($st), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></span></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<div class="card shadow-sm dg-chart-card">
  <div class="card-body">
    <div class="fw-semibold mb-2"><?= h_t("dashboard.alert_list") ?></div>
    <div class="table-responsive">
      <table class="table table-sm align-middle dg-table">
        <thead>
          <tr>
            <th><?= h_t("dashboard.th_student") ?></th>
            <th><?= h_t("dashboard.th_lrn") ?></th>
            <th><?= h_t("dashboard.filter_grade") ?></th>
            <th><?= h_t("dashboard.filter_strand") ?></th>
            <th><?= h_t("dashboard.filter_section") ?></th>
            <th><?= h_t("dashboard.filter_school_year") ?></th>
            <th class="text-end"><?= h_t("dashboard.th_gpa") ?></th>
            <th><?= h_t("dashboard.th_academic") ?></th>
            <th class="text-end"><?= h_t("dashboard.th_absences") ?></th>
            <th class="text-end"><?= h_t("dashboard.th_consec_abs") ?></th>
            <th class="text-end"><?= h_t("dashboard.th_attendance_pct") ?></th>
            <th class="text-end"><?= h_t("dashboard.th_risk_score") ?></th>
            <th><?= h_t("dashboard.th_risk_level") ?></th>
            <th><?= h_t("dashboard.th_risk_factors") ?></th>
            <th><?= h_t("dashboard.th_case_status") ?></th>
            <th><?= h_t("dashboard.th_flag_issue") ?></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <?php
            $storedScore = (float)($r["risk_score"] ?? 0.0);
            $storedLevel = (string)($r["risk_level"] ?? "");
            $hasAnySignal = ((float)($r["gpa"] ?? 0.0) > 0.0)
                || ((int)($r["absences"] ?? 0) > 0)
                || ((int)($r["total_school_days"] ?? 0) > 0)
                || ((int)($r["consecutive_absences"] ?? 0) > 0)
                || ml_student_academic_failing_meta($r)["has_signal"];

            $sid = (int)($r["student_id"] ?? 0);
            $flagMeta = $flagsByStudent[$sid] ?? [];
            $activeFlagCount = is_array($flagMeta) ? count($flagMeta) : 0;
            $maxFlagSeverity = 0;
            $highFlagCount = 0;
            $moderateFlagCount = 0;
            if (is_array($flagMeta)) {
                foreach ($flagMeta as $fl) {
                    $sev = (string)($fl["severity"] ?? "Moderate");
                    $v = $sev === "High" ? 3 : ($sev === "Moderate" ? 2 : 1);
                    if ($v > $maxFlagSeverity) $maxFlagSeverity = $v;
                    if ($sev === "High") $highFlagCount += 1;
                    if ($sev === "Moderate") $moderateFlagCount += 1;
                }
            }

            $scoreToShow = ($storedScore <= 0.0001 && $storedLevel === "Low" && ($hasAnySignal || $activeFlagCount > 0))
                ? estimate_risk_score(
                    (float)($r["gpa"] ?? 0.0),
                    (int)($r["absences"] ?? 0),
                    (int)($r["days_present"] ?? 0),
                    (int)($r["total_school_days"] ?? 0),
                    (int)($r["consecutive_absences"] ?? 0),
                    $activeFlagCount,
                    $maxFlagSeverity,
                    0,
                    0,
                    (int)($r["failing_subjects"] ?? 0),
                    (float)($r["subject_min"] ?? 0)
                )
                : $storedScore;

            $lvl = $storedLevel !== "" ? $storedLevel : classify_risk_level($scoreToShow);
            if ($storedLevel === "" || ($storedLevel === "Low" && $storedScore <= 0.0001)) {
                if ($highFlagCount >= 3 || ($highFlagCount >= 2 && $moderateFlagCount >= 1)) {
                    $t = get_risk_thresholds();
                    $hiMin = (float)($t["high_min"] ?? 0.70);
                    $scoreToShow = max($scoreToShow, min(1.0, $hiMin + 0.0001));
                    $lvl = "High";
                }
            }
            $lvlLabel = match ($lvl) {
                "High" => t("risk.high"),
                "Moderate" => t("risk.moderate"),
                default => t("risk.low"),
            };
            $rowClass = "dg-row-low";
            $chipClass = "dg-risk-low";
            if ($lvl === "Moderate") { $rowClass = "dg-row-mod"; $chipClass = "dg-risk-mod"; }
            if ($lvl === "High") { $rowClass = "dg-row-high"; $chipClass = "dg-risk-high"; }
            $riskFactors = ml_parse_risk_factors($r["risk_factors_json"] ?? null);
          ?>
          <tr class="<?= $rowClass ?>">
            <td>
              <a class="fw-semibold text-decoration-none" href="student?id=<?= (int)$r["student_id"] ?>">
                <?= htmlspecialchars($r["name"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>
              </a>
              <div class="text-muted small d-md-none"><?= h_t("dashboard.mobile_id") ?>: <?= (int)$r["student_id"] ?></div>
            </td>
            <td class="small text-muted"><?= htmlspecialchars((string)($r["lrn"] ?? ""), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></td>
            <td><?= htmlspecialchars($r["grade_level"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></td>
            <td class="small"><?= !empty($r["strand"]) ? htmlspecialchars((string)$r["strand"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") : h_t("common.em_dash") ?></td>
            <td class="small"><?= !empty($r["section"]) ? htmlspecialchars(section_display_short((string)$r["section"]), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") : h_t("common.em_dash") ?></td>
            <td class="small"><?= htmlspecialchars((string)($r["school_year"] ?? "-"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></td>
            <td class="text-end"><?= number_format((float)$r["gpa"], 2) ?></td>
            <td>
              <?php $isFailing = ((float)$r["gpa"] < 75.0); ?>
              <span class="dg-academic-chip <?= $isFailing ? "dg-academic-fail" : "dg-academic-pass" ?>">
                <?= $isFailing ? h_t("academic.failing") : h_t("academic.passing") ?>
              </span>
            </td>
            <td class="text-end"><?= (int)$r["absences"] ?></td>
            <td class="text-end"><?= (int)($r["consecutive_absences"] ?? 0) ?></td>
            <td class="text-end"><?= $r["attendance_pct"] !== null ? number_format((float)$r["attendance_pct"], 1) . "%" : "-" ?></td>
            <td class="text-end"><?= number_format((float)$scoreToShow, 2) ?></td>
            <td>
              <span class="dg-risk-chip <?= $chipClass ?>">
                <span class="dot"></span>
                <?= htmlspecialchars($lvlLabel, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>
              </span>
            </td>
            <td class="small" style="min-width:180px;max-width:260px">
              <?php if ($riskFactors): ?>
                <?= ml_render_risk_factors($riskFactors, 2) ?>
              <?php else: ?>
                <span class="text-muted"><?= h_t("ml.risk_factors_none") ?></span>
              <?php endif; ?>
            </td>
            <td>
              <form method="post" action="update-case" class="dg-case-form">
                <?= csrf_field() ?>
                <input type="hidden" name="student_id" value="<?= (int)$r["student_id"] ?>" />
                <input type="hidden" name="redirect" value="<?= htmlspecialchars($dashRedirect, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" />
                <select class="form-select form-select-sm dg-case-select" name="status" style="min-width:150px">
                  <option value="Flagged" <?= ($r["case_status"] === "Flagged") ? "selected" : "" ?>><?= h_t("case.flagged") ?></option>
                  <option value="Ongoing Counseling" <?= ($r["case_status"] === "Ongoing Counseling") ? "selected" : "" ?>><?= h_t("case.ongoing") ?></option>
                  <option value="Resolved" <?= ($r["case_status"] === "Resolved") ? "selected" : "" ?>><?= h_t("case.resolved") ?></option>
                </select>
                <button class="btn btn-sm btn-outline-secondary dg-case-btn" type="submit"><?= h_t("common.update") ?></button>
              </form>
            </td>
            <td>
              <?php $sflags = $flagsByStudent[(int)$r["student_id"]] ?? []; ?>
              <?php if ($sflags): ?>
                <div class="mb-1">
                  <?php foreach ($sflags as $fl): ?>
                    <?php $fSev = (string)($fl["severity"] ?? "Moderate"); $fCol = $fSev === "High" ? "danger" : ($fSev === "Moderate" ? "warning" : "success"); ?>
                    <div class="d-inline-flex align-items-center gap-1 mb-1">
                      <span class="badge text-bg-<?= $fCol ?>"><?= htmlspecialchars((string)$fl["issue_type"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></span>
                      <form method="post" action="mark-student" class="d-inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="resolve" />
                        <input type="hidden" name="flag_id" value="<?= (int)$fl["flag_id"] ?>" />
                        <input type="hidden" name="redirect" value="<?= htmlspecialchars($dashRedirect, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" />
                        <button class="btn btn-sm p-0 text-muted" type="submit" title="<?= h_t("issue.resolve_title") ?>" style="font-size:.7rem">&#x2715;</button>
                      </form>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
              <details class="small">
                <summary class="text-muted"><?= h_t("issue.add_flag") ?></summary>
                <form method="post" action="mark-student" class="mt-1 d-grid gap-1" style="min-width:140px">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="add" />
                  <input type="hidden" name="student_id" value="<?= (int)$r["student_id"] ?>" />
                  <input type="hidden" name="redirect" value="<?= htmlspecialchars($dashRedirect, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" />
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
                  <input class="form-control form-control-sm" name="note" placeholder="<?= h_t("common.details") ?>" />
                  <button class="btn btn-sm btn-outline-danger" type="submit"><?= h_t("issue.flag_btn") ?></button>
                </form>
              </details>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script>
  (function () {
    const form = document.getElementById("dgDashboardFilter");
    const gEl = document.getElementById("dgDashFilterGrade");
    const sEl = document.getElementById("dgDashSection");
    const strandWrap = document.getElementById("dgDashStrandWrap");
    const strandSel = document.getElementById("dgDashStrand");
    const syEl = document.getElementById("dgDashSchoolYear");
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
</script>

<script>
  const overall = <?= json_encode(array_values($dist)) ?>;
  new Chart(document.getElementById('overallChart'), {
    type: 'doughnut',
    data: {
      labels: <?= json_encode([t("risk.low"), t("risk.moderate"), t("risk.high")], JSON_UNESCAPED_UNICODE) ?>,
      datasets: [{ data: overall, backgroundColor: ['#198754', '#ffc107', '#dc3545'] }]
    }
  });

  const gradeLabels = <?= json_encode(array_keys($byGrade)) ?>;
  const gradeLow = <?= json_encode(array_map(fn($g) => $byGrade[$g]['Low'] ?? 0, array_keys($byGrade))) ?>;
  const gradeMod = <?= json_encode(array_map(fn($g) => $byGrade[$g]['Moderate'] ?? 0, array_keys($byGrade))) ?>;
  const gradeHigh = <?= json_encode(array_map(fn($g) => $byGrade[$g]['High'] ?? 0, array_keys($byGrade))) ?>;

  new Chart(document.getElementById('gradeChart'), {
    type: 'bar',
    data: {
      labels: gradeLabels,
      datasets: [
        { label: <?= json_encode(t("risk.low"), JSON_UNESCAPED_UNICODE) ?>, data: gradeLow, backgroundColor: '#198754' },
        { label: <?= json_encode(t("risk.moderate"), JSON_UNESCAPED_UNICODE) ?>, data: gradeMod, backgroundColor: '#ffc107' },
        { label: <?= json_encode(t("risk.high"), JSON_UNESCAPED_UNICODE) ?>, data: gradeHigh, backgroundColor: '#dc3545' },
      ]
    },
    options: {
      responsive: true,
      scales: { x: { stacked: true }, y: { stacked: true, beginAtZero: true, ticks: { precision: 0 } } }
    }
  });

  const absLabels = <?= json_encode(array_keys($absByGrade)) ?>;
  const absAvg = <?= json_encode(array_map(function ($g) use ($absByGrade, $absCountByGrade) {
      $c = (int)($absCountByGrade[$g] ?? 0);
      if ($c <= 0) return 0;
      return round(((int)($absByGrade[$g] ?? 0)) / $c, 2);
  }, array_keys($absByGrade))) ?>;
  new Chart(document.getElementById('absencesChart'), {
    type: 'bar',
    data: {
      labels: absLabels,
      datasets: [{ label: <?= json_encode(t("chart.avg_absences"), JSON_UNESCAPED_UNICODE) ?>, data: absAvg, backgroundColor: 'rgba(201,162,39,.55)', borderColor: 'rgba(201,162,39,.85)' }]
    },
    options: {
      responsive: true,
      scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
    }
  });

  const flagsLabels = <?= json_encode(array_keys($flagsCountByGrade)) ?>;
  const flagsTotal = <?= json_encode(array_map(fn($g) => (int)($flagsCountByGrade[$g] ?? 0), array_keys($flagsCountByGrade))) ?>;
  new Chart(document.getElementById('flagsChart'), {
    type: 'bar',
    data: {
      labels: flagsLabels,
      datasets: [{ label: <?= json_encode(t("chart.active_flags"), JSON_UNESCAPED_UNICODE) ?>, data: flagsTotal, backgroundColor: 'rgba(11,93,30,.45)', borderColor: 'rgba(11,93,30,.85)' }]
    },
    options: {
      responsive: true,
      scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
    }
  });

  const trendLabels = <?= json_encode($trendLabels) ?>;
  const trendHigh = <?= json_encode($trendHigh) ?>;
  new Chart(document.getElementById('highTrendChart'), {
    type: 'line',
    data: {
      labels: trendLabels,
      datasets: [{
        label: <?= json_encode(t("chart.high_risk_count"), JSON_UNESCAPED_UNICODE) ?>,
        data: trendHigh,
        borderColor: '#dc3545',
        backgroundColor: 'rgba(220,53,69,.15)',
        fill: true,
        tension: 0.25
      }]
    },
    options: {
      responsive: true,
      scales: {
        y: { beginAtZero: true, ticks: { precision: 0 } }
      }
    }
  });
</script>
<?php
$content = ob_get_clean();
require __DIR__ . "/_layout.php";

