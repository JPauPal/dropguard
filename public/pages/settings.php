<?php
declare(strict_types=1);

require_once __DIR__ . "/../../app/auth.php";
require_once __DIR__ . "/../../app/settings.php";
require_once __DIR__ . "/../../app/grading.php";
require_once __DIR__ . "/../../app/academic_period.php";
require_once __DIR__ . "/../../app/curriculum.php";
require_once __DIR__ . "/../../app/SettingsController.php";
require_once __DIR__ . "/../../app/digest.php";

require_login();
require_role(["Admin"]);
ensure_grading_components_table();
ensure_grading_period_closures_table();
digest_ensure_settings();

$error = null;
$success = null;
$user = current_user();

if (($_SERVER["REQUEST_METHOD"] ?? "GET") === "POST") {
    require_valid_csrf_token();
    $result = SettingsController::handlePost($_POST, is_array($user) ? $user : []);
    $error = $result["error"];
    $success = $result["success"];
}

$t = get_risk_thresholds();
$weights = grading_weights();
$extraMax = grading_extracurricular_cap();
$transmutationEnabled = grading_transmutation_enabled();
$periodStatus = academic_period_status_summary();
$jhsTerms = curriculum_performance_terms("junior_high_school");
$shsTerms = curriculum_performance_terms("senior_high_school");
$termStartDates = academic_period_term_start_dates_for_year($periodStatus["school_year"]);
$requiresEoyConfirm = ($periodStatus["jhs_end_of_school_year_semester"] ?? false)
    || ($periodStatus["shs_end_of_school_year_semester"] ?? false);
$digestEnabled = digest_is_enabled();
$digestEmail = digest_recipient_email();
$digestLastRun = trim((string)(get_setting("digest_last_run", "") ?? ""));

ob_start();
?>
<div class="dg-page-header mb-3">
  <h3 class="mb-0">System Settings</h3>
  <div class="text-muted small">Maintain risk thresholds, DepEd-compliant grading, and academic calendar cutoffs.</div>
</div>

<div class="card shadow-sm">
  <div class="card-body">
    <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></div><?php endif; ?>
    <form method="post" action="settings" class="row g-3">
      <?= csrf_field() ?>
      <input type="hidden" name="form_type" value="settings" />
      <div class="col-12">
        <div class="fw-semibold">Risk Thresholds</div>
      </div>
      <div class="col-md-4">
        <label class="form-label">Low Risk Max (0-1)</label>
        <input class="form-control" type="number" step="0.01" min="0" max="1" name="risk_low_max" value="<?= htmlspecialchars((string)$t["low_max"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" required />
        <div class="small text-muted">Below this value = Low</div>
      </div>
      <div class="col-md-4">
        <label class="form-label">High Risk Min (0-1)</label>
        <input class="form-control" type="number" step="0.01" min="0" max="1" name="risk_high_min" value="<?= htmlspecialchars((string)$t["high_min"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" required />
        <div class="small text-muted">Above this value = High</div>
      </div>
      <div class="col-12 mt-2">
        <div class="fw-semibold">Grading System</div>
        <div class="small text-muted">Component weights must total 100 points and define the maximum raw score for quiz, exam, and project. When enabled, final scores use DepEd Order No. 8 transmutation via <code>60 + (initial × 0.25)</code> below 60 and <code>75 + ((initial − 60) × 0.625)</code> at or above 60.</div>
      </div>
      <div class="col-md-4">
        <label class="form-label">Quiz</label>
        <input class="form-control" type="number" step="1" min="0" max="100" name="grade_weight_quiz" value="<?= htmlspecialchars((string)$weights["quiz"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" required />
      </div>
      <div class="col-md-4">
        <label class="form-label">Exam</label>
        <input class="form-control" type="number" step="1" min="0" max="100" name="grade_weight_exam" value="<?= htmlspecialchars((string)$weights["exam"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" required />
      </div>
      <div class="col-md-4">
        <label class="form-label">Project</label>
        <input class="form-control" type="number" step="1" min="0" max="100" name="grade_weight_project" value="<?= htmlspecialchars((string)$weights["project"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" required />
      </div>
      <div class="col-md-4">
        <label class="form-label">Extracurricular Max Bonus</label>
        <input class="form-control" type="number" step="1" min="0" max="100" name="grade_extracurricular_max" value="<?= htmlspecialchars((string)$extraMax, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" required />
        <div class="small text-muted">Finalized score is capped at 100.</div>
      </div>
      <div class="col-12">
        <div class="form-check">
          <input class="form-check-input" type="checkbox" name="enable_transmutation" id="enable_transmutation" value="1" <?= $transmutationEnabled ? "checked" : "" ?> />
          <label class="form-check-label" for="enable_transmutation">
            Enable DepEd grade transmutation (Order No. 8, s. 2015)
          </label>
          <div class="small text-muted">On by default for Philippine K-12 schools. Toggling this setting is audit-logged.</div>
        </div>
      </div>
      <div class="col-md-4 d-flex align-items-end">
        <button class="btn btn-dark w-100" type="submit">Save Settings</button>
      </div>
    </form>
  </div>
</div>

<div class="card shadow-sm mt-4">
  <div class="card-body">
    <div class="dg-page-header mb-3">
      <h4 class="mb-1">Academic Calendar &amp; Grading Cutoff</h4>
      <div class="text-muted small">Close the current semester so teachers cannot edit grades for those quarters. All closure and school-year changes run in a single database transaction.</div>
    </div>

    <div class="row g-3 mb-4">
      <div class="col-md-4">
        <div class="border rounded p-3 h-100">
          <div class="text-muted small">Active school year</div>
          <div class="fw-semibold"><?= htmlspecialchars($periodStatus["school_year"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></div>
        </div>
      </div>
      <div class="col-md-4">
        <div class="border rounded p-3 h-100">
          <div class="text-muted small">Junior High open period</div>
          <div class="fw-semibold"><?= htmlspecialchars($periodStatus["active_term_jhs_label"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></div>
          <?php if (!empty($periodStatus["jhs_end_of_school_year_semester"])): ?>
            <div class="small text-warning mt-1">Currently in final semester (Q3–Q4)</div>
          <?php endif; ?>
        </div>
      </div>
      <div class="col-md-4">
        <div class="border rounded p-3 h-100">
          <div class="text-muted small">Senior High open period</div>
          <div class="fw-semibold"><?= htmlspecialchars($periodStatus["active_term_shs_label"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></div>
          <?php if (!empty($periodStatus["shs_end_of_school_year_semester"])): ?>
            <div class="small text-warning mt-1">Currently in final semester (2nd sem)</div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <?php if ($requiresEoyConfirm): ?>
      <div class="alert alert-warning">
        <strong>End-of-school-year semester is active.</strong> Finishing the semester for a track currently in Q3–Q4 (JHS) or 2nd semester (SHS) requires explicit confirmation in the dialog. This prevents accidental school-year rollover.
      </div>
    <?php endif; ?>

    <form method="post" action="settings" class="row g-3 border rounded p-3 mb-4" id="dgFinishSemesterForm">
      <?= csrf_field() ?>
      <input type="hidden" name="form_type" value="finish_semester" />
      <div class="col-12">
        <div class="fw-semibold">Finish Semester</div>
        <div class="small text-muted">Closes both quarters in the current semester (JHS: Q1–Q2 or Q3–Q4; SHS: 1st or 2nd semester) for the school year below.</div>
      </div>
      <div class="col-md-3">
        <label class="form-label">Track</label>
        <select class="form-select" name="track_key" required>
          <option value="both">Both JHS &amp; SHS</option>
          <option value="junior_high_school">Junior High only</option>
          <option value="senior_high_school">Senior High only</option>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label">School year</label>
        <input class="form-control" type="text" name="school_year" pattern="^\d{4}-\d{4}$" title="Format: YYYY-YYYY (e.g. 2025-2026)" value="<?= htmlspecialchars($periodStatus["school_year"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" required />
      </div>
      <div class="col-md-4">
        <label class="form-label">Note (optional)</label>
        <input class="form-control" type="text" name="closure_note" maxlength="255" placeholder="e.g. End of 1st semester" />
      </div>
      <div class="col-md-2 d-flex align-items-end">
        <button class="btn btn-warning w-100" type="button" data-bs-toggle="modal" data-bs-target="#dgFinishSemesterModal">Finish Semester</button>
      </div>
      <div class="col-12">
        <div class="form-check">
          <input class="form-check-input" type="checkbox" name="advance_school_year" id="advance_school_year" value="1" checked />
          <label class="form-check-label" for="advance_school_year">
            After 2nd semester, advance to the next school year and open Q1 / 1st semester
          </label>
          <div class="small text-muted">Uncheck if the school is entering summer break and you will open the next year manually later.</div>
        </div>
      </div>
      <input type="hidden" name="confirm_end_of_school_year" id="confirm_end_of_school_year" value="" />
    </form>

    <form method="post" action="settings" class="row g-3 border rounded p-3 mb-4">
      <?= csrf_field() ?>
      <input type="hidden" name="form_type" value="active_period" />
      <div class="col-12">
        <div class="fw-semibold">Set Active Grading Period</div>
        <div class="small text-muted">Use after summer break or to correct the open period without closing terms.</div>
      </div>
      <div class="col-md-3">
        <label class="form-label">School year</label>
        <input class="form-control" type="text" name="active_school_year" pattern="^\d{4}-\d{4}$" title="Format: YYYY-YYYY" value="<?= htmlspecialchars($periodStatus["school_year"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" required />
      </div>
      <div class="col-md-3">
        <label class="form-label">Junior High period</label>
        <select class="form-select" name="active_term_jhs" required>
          <?php foreach ($jhsTerms as $termRow): ?>
            <?php $tid = (string)$termRow["term_id"]; ?>
            <option value="<?= htmlspecialchars($tid, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" <?= $tid === $periodStatus["active_term_jhs"] ? "selected" : "" ?>>
              <?= htmlspecialchars((string)$termRow["name"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label">Senior High period</label>
        <select class="form-select" name="active_term_shs" required>
          <?php foreach ($shsTerms as $termRow): ?>
            <?php $tid = (string)$termRow["term_id"]; ?>
            <option value="<?= htmlspecialchars($tid, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" <?= $tid === $periodStatus["active_term_shs"] ? "selected" : "" ?>>
              <?= htmlspecialchars(curriculum_term_label($tid), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3 d-flex align-items-end">
        <button class="btn btn-outline-dark w-100" type="submit">Update Active Period</button>
      </div>
    </form>

    <form method="post" action="settings" class="row g-3 border rounded p-3 mb-4">
      <?= csrf_field() ?>
      <input type="hidden" name="form_type" value="term_start_dates" />
      <div class="col-12">
        <div class="fw-semibold">Attendance Day 1 Dates</div>
        <div class="small text-muted">
          Set the <strong>first official class day</strong> for each grading period. Teachers use this as Day 1 on Student Sheets — it is <strong>not</strong> the date Drop Guard was installed or went online.
          Leave a field blank to keep using the system estimate until you save a date.
        </div>
      </div>
      <div class="col-md-3">
        <label class="form-label">School year</label>
        <input class="form-control" type="text" name="term_start_school_year" pattern="^\d{4}-\d{4}$" title="Format: YYYY-YYYY" value="<?= htmlspecialchars($periodStatus["school_year"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" required />
      </div>
      <?php foreach ($termStartDates as $termId => $termStart): ?>
        <div class="col-md-3">
          <label class="form-label"><?= htmlspecialchars((string)$termStart["label"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></label>
          <input
            class="form-control"
            type="date"
            name="term_start[<?= htmlspecialchars($termId, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>]"
            value="<?= htmlspecialchars((string)($termStart["configured"] ?? $termStart["effective"]), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>"
          />
          <?php if ($termStart["configured"] === null): ?>
            <div class="small text-muted">Estimated until saved</div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
      <div class="col-12">
        <button class="btn btn-outline-dark" type="submit">Save Day 1 Dates</button>
      </div>
    </form>

    <?php if ($periodStatus["closures"] !== []): ?>
      <div class="fw-semibold mb-2">Closed periods (<?= htmlspecialchars($periodStatus["school_year"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>)</div>
      <div class="table-responsive">
        <table class="table table-sm align-middle">
          <thead>
            <tr>
              <th>Term</th>
              <th>Track</th>
              <th>Closed at</th>
              <th>Note</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($periodStatus["closures"] as $closure): ?>
              <tr>
                <td><?= htmlspecialchars(curriculum_term_label((string)$closure["term_id"]), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></td>
                <td class="small"><?= htmlspecialchars((string)$closure["track_key"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></td>
                <td class="small text-muted"><?= htmlspecialchars((string)$closure["closed_at"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></td>
                <td class="small"><?= htmlspecialchars((string)($closure["note"] ?? ""), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></td>
                <td class="text-end">
                  <form method="post" action="settings" class="d-inline" onsubmit="return confirm('Reopen this period for editing?');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="form_type" value="reopen_term" />
                    <input type="hidden" name="reopen_school_year" value="<?= htmlspecialchars((string)$closure["school_year"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" />
                    <input type="hidden" name="reopen_term_id" value="<?= htmlspecialchars((string)$closure["term_id"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" />
                    <button class="btn btn-link btn-sm p-0" type="submit">Reopen</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php else: ?>
      <div class="text-muted small">No closed grading periods for the active school year yet.</div>
    <?php endif; ?>
  </div>
</div>

<div class="card shadow-sm mt-4">
  <div class="card-body">
    <div class="fw-semibold mb-1">Early Warning Daily Digest</div>
    <div class="text-muted small mb-3">Sends a daily email summary when new High Risk students are detected. Schedule <code>php tools/daily_digest.php</code> on the server (e.g. 7:00 AM).</div>
  <?php if ($digestLastRun !== ""): ?>
    <div class="small text-muted mb-3">Last run: <?= htmlspecialchars($digestLastRun, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></div>
  <?php endif; ?>
    <form method="post" action="settings" class="row g-3">
      <?= csrf_field() ?>
      <input type="hidden" name="form_type" value="digest_settings" />
      <div class="col-md-5">
        <label class="form-label">Counselor email</label>
        <input class="form-control" type="email" name="digest_email" value="<?= htmlspecialchars($digestEmail, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" placeholder="counselor@school.edu" />
      </div>
      <div class="col-md-3 d-flex align-items-end">
        <div class="form-check mb-2">
          <input class="form-check-input" type="checkbox" name="digest_enabled" id="digest_enabled" value="1" <?= $digestEnabled ? "checked" : "" ?> />
          <label class="form-check-label" for="digest_enabled">Enable daily digest</label>
        </div>
      </div>
      <div class="col-md-2 d-flex align-items-end">
        <button class="btn btn-dark w-100" type="submit">Save</button>
      </div>
      <div class="col-md-2 d-flex align-items-end">
        <button class="btn btn-outline-secondary w-100" type="submit" name="digest_run_now" value="1">Send test now</button>
      </div>
    </form>
  </div>
</div>
<?php
$content = ob_get_clean();

ob_start();
?>
<div class="modal fade" id="dgFinishSemesterModal" tabindex="-1" aria-labelledby="dgFinishSemesterModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header border-warning">
        <h5 class="modal-title" id="dgFinishSemesterModalLabel">Finish Semester</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p class="mb-3">Teachers will <strong>no longer be able to save attendance or grades</strong> for the closed quarters. This action is logged and can be reversed only by reopening individual periods.</p>
        <dl class="row mb-0 small">
          <dt class="col-sm-4">Track</dt>
          <dd class="col-sm-8" id="dgFinishModalTrack">—</dd>
          <dt class="col-sm-4">School year</dt>
          <dd class="col-sm-8" id="dgFinishModalYear">—</dd>
          <dt class="col-sm-4">Advance year</dt>
          <dd class="col-sm-8" id="dgFinishModalAdvance">—</dd>
        </dl>
        <div class="alert alert-warning small mt-3 mb-0 d-none" id="dgFinishModalEoyWarn">
          <div class="form-check mb-0">
            <input class="form-check-input" type="checkbox" id="dgFinishModalEoyConfirm" value="1" />
            <label class="form-check-label" for="dgFinishModalEoyConfirm">
              I confirm this closes the <strong>final semester</strong> of the school year and understand grading will be cut off for those quarters.
            </label>
          </div>
        </div>
        <div class="alert alert-danger small mt-3 mb-0 d-none" id="dgFinishModalError" role="alert"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-warning" id="dgFinishModalConfirm">Yes, finish semester</button>
      </div>
    </div>
  </div>
</div>
<script>
  (function () {
    const form = document.getElementById("dgFinishSemesterForm");
    const modalEl = document.getElementById("dgFinishSemesterModal");
    if (!form || !modalEl) return;

    const trackLabels = {
      both: "Both JHS & SHS",
      junior_high_school: "Junior High only",
      senior_high_school: "Senior High only",
    };
    const jhsEoy = <?= !empty($periodStatus["jhs_end_of_school_year_semester"]) ? "true" : "false" ?>;
    const shsEoy = <?= !empty($periodStatus["shs_end_of_school_year_semester"]) ? "true" : "false" ?>;

    function trackNeedsEoy(trackKey) {
      if (trackKey === "both") return jhsEoy || shsEoy;
      if (trackKey === "junior_high_school") return jhsEoy;
      if (trackKey === "senior_high_school") return shsEoy;
      return false;
    }

    modalEl.addEventListener("show.bs.modal", function () {
      const trackEl = form.querySelector('[name="track_key"]');
      const yearEl = form.querySelector('[name="school_year"]');
      const advanceEl = form.querySelector('[name="advance_school_year"]');
      const trackKey = trackEl ? trackEl.value : "";
      const year = yearEl ? yearEl.value : "";
      const advance = advanceEl && advanceEl.checked;

      document.getElementById("dgFinishModalTrack").textContent = trackLabels[trackKey] || trackKey || "—";
      document.getElementById("dgFinishModalYear").textContent = year || "—";
      document.getElementById("dgFinishModalAdvance").textContent = advance
        ? "Yes — open next school year after 2nd semester"
        : "No — summer break; open next year manually";

      const eoyWarn = document.getElementById("dgFinishModalEoyWarn");
      const errBox = document.getElementById("dgFinishModalError");
      if (eoyWarn) {
        eoyWarn.classList.toggle("d-none", !trackNeedsEoy(trackKey));
        const eoyCb = document.getElementById("dgFinishModalEoyConfirm");
        if (eoyCb) eoyCb.checked = false;
      }
      if (errBox) {
        errBox.classList.add("d-none");
        errBox.textContent = "";
      }
    });

    const confirmBtn = document.getElementById("dgFinishModalConfirm");
    if (confirmBtn) {
      confirmBtn.addEventListener("click", function () {
        const trackEl = form.querySelector('[name="track_key"]');
        const trackKey = trackEl ? trackEl.value : "";
        const errBox = document.getElementById("dgFinishModalError");
        const eoyCheck = document.getElementById("dgFinishModalEoyConfirm");
        const eoyHidden = document.getElementById("confirm_end_of_school_year");

        if (!form.checkValidity()) {
          if (errBox) {
            errBox.textContent = "Please complete all required fields in the form (valid school year, track, etc.).";
            errBox.classList.remove("d-none");
          }
          form.reportValidity();
          return;
        }

        if (trackNeedsEoy(trackKey) && eoyCheck && !eoyCheck.checked) {
          if (errBox) {
            errBox.textContent = "Check the end-of-school-year confirmation box to proceed.";
            errBox.classList.remove("d-none");
          }
          return;
        }

        if (eoyHidden) {
          eoyHidden.value = trackNeedsEoy(trackKey) && eoyCheck && eoyCheck.checked ? "1" : "";
        }

        if (typeof bootstrap !== "undefined" && modalEl) {
          const instance = bootstrap.Modal.getInstance(modalEl);
          if (instance) instance.hide();
        }
        form.submit();
      });
    }
  })();
</script>
<?php
$dg_page_modals = ob_get_clean();
require __DIR__ . "/_layout.php";
