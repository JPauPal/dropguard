<?php
declare(strict_types=1);

require_once __DIR__ . "/../../app/auth.php";
require_once __DIR__ . "/../../app/db.php";
require_once __DIR__ . "/../../app/batches.php";
require_once __DIR__ . "/../../app/workflow.php";
require_once __DIR__ . "/../../app/curriculum.php";
require_once __DIR__ . "/../../app/sections.php";
require_once __DIR__ . "/../../app/students_archive.php";

require_login();
require_role(["Teacher", "Admin"]);
ensure_student_batches_table();
ensure_workflow_tables();
curriculum_ensure_performance_schema();
ensure_student_sections();
ensure_students_archived_column();

$user = current_user();

$error = null;
$success = null;
$imported = 0;
$skipped = 0;
$details = [];

$defaultSchoolYear = default_current_school_year();

if (($_SERVER["REQUEST_METHOD"] ?? "GET") === "POST") {
    require_valid_csrf_token();
    $schoolYear = trim((string)($_POST["school_year"] ?? ""));
    if ($schoolYear === "") {
        $schoolYear = default_current_school_year();
    }
    $quarter = (int)($_POST["quarter"] ?? 1);

    if (!is_valid_school_year_sequence($schoolYear)) {
        $error = "School year must be in sequence (e.g., 2025-2026).";
    } elseif ($quarter < 1 || $quarter > 4) {
        $error = "Quarter must be between 1 and 4.";
    } elseif (!isset($_FILES["csv_file"]) || $_FILES["csv_file"]["error"] !== UPLOAD_ERR_OK) {
        $error = "Please select a CSV file to upload.";
    } else {
        $tmpPath = (string)$_FILES["csv_file"]["tmp_name"];
        $ext = strtolower(pathinfo((string)$_FILES["csv_file"]["name"], PATHINFO_EXTENSION));

        if (!in_array($ext, ["csv", "txt"], true)) {
            $error = "Only .csv or .txt files are supported. Export from Google Sheets or Excel as CSV first.";
        } else {
            $handle = fopen($tmpPath, "r");
            if (!$handle) {
                $error = "Could not read uploaded file.";
            } else {
                $headerRow = fgetcsv($handle);
                if (!$headerRow) {
                    $error = "File is empty or not valid CSV.";
                    fclose($handle);
                } else {
                    $headerMap = [];
                    foreach ($headerRow as $i => $col) {
                        $headerMap[strtolower(trim((string)$col))] = $i;
                    }

                    $required = ["lrn", "name", "grade_level", "section", "gpa", "absences"];
                    $missing = [];
                    foreach ($required as $req) {
                        if (!isset($headerMap[$req])) {
                            $missing[] = $req;
                        }
                    }

                    if ($missing) {
                        $error = "Missing required columns: " . implode(", ", $missing) . ". Required: lrn, name, grade_level, section, gpa, absences.";
                        fclose($handle);
                    } else {
                        $pdo = db();
                        $allowedSections = list_sections(null);
                        $pdo->beginTransaction();
                        $lineNum = 1;

                        try {
                            while (($row = fgetcsv($handle)) !== false) {
                                $lineNum++;
                                try {
                                $name = trim((string)($row[$headerMap["name"]] ?? ""));
                                $gradeLevelRaw = trim((string)($row[$headerMap["grade_level"]] ?? ""));
                                $gradeLevel = curriculum_normalize_grade_level($gradeLevelRaw);
                                $gpaRaw = trim((string)($row[$headerMap["gpa"]] ?? ""));
                                if ($gpaRaw === "" || !is_numeric($gpaRaw)) {
                                    $skipped++;
                                    $details[] = "Line {$lineNum}: skipped (invalid GPA " . ($gpaRaw === "" ? "— empty" : "'{$gpaRaw}'") . ").";
                                    continue;
                                }
                                $gpa = (float)$gpaRaw;
                                $absencesRaw = trim((string)($row[$headerMap["absences"]] ?? ""));
                                if ($absencesRaw !== "" && !ctype_digit($absencesRaw) && !is_numeric($absencesRaw)) {
                                    $skipped++;
                                    $details[] = "Line {$lineNum}: skipped (invalid absences '{$absencesRaw}').";
                                    continue;
                                }
                                $absences = (int)$absencesRaw;
                                $lrn = trim((string)($row[$headerMap["lrn"]] ?? ""));
                                $section = isset($headerMap["section"]) ? trim((string)($row[$headerMap["section"]] ?? "")) : "";
                                $daysPresent = isset($headerMap["days_present"]) ? (int)($row[$headerMap["days_present"]] ?? 0) : 0;
                                $totalSchoolDays = isset($headerMap["total_school_days"]) ? (int)($row[$headerMap["total_school_days"]] ?? 0) : 0;
                                $consecutiveAbsences = isset($headerMap["consecutive_absences"]) ? (int)($row[$headerMap["consecutive_absences"]] ?? 0) : 0;
                                $strandRaw = isset($headerMap["strand"]) ? trim((string)($row[$headerMap["strand"]] ?? "")) : "";
                                [$strandNorm, $strandErr] = curriculum_validate_strand_input($gradeLevel, $strandRaw);

                                if ($name === "" || $gradeLevelRaw === "") {
                                    $skipped++;
                                    $details[] = "Line {$lineNum}: skipped (empty name or grade_level).";
                                    continue;
                                }
                                if ($lrn === "") {
                                    $skipped++;
                                    $details[] = "Line {$lineNum}: skipped (LRN is required).";
                                    continue;
                                }
                                if (!preg_match('/^[A-Za-z0-9\\-]{4,30}$/', $lrn)) {
                                    $skipped++;
                                    $details[] = "Line {$lineNum}: skipped (invalid LRN '{$lrn}').";
                                    continue;
                                }
                                if ($section === "") {
                                    $skipped++;
                                    $details[] = "Line {$lineNum}: skipped (section is required).";
                                    continue;
                                }
                                if (!in_array($section, $allowedSections, true)) {
                                    $skipped++;
                                    $details[] = "Line {$lineNum}: skipped (unknown section '{$section}'; add it in Sections first).";
                                    continue;
                                }
                                if ($gradeLevel === null) {
                                    $skipped++;
                                    $details[] = "Line {$lineNum}: skipped (invalid grade_level '{$gradeLevelRaw}'; use 7-12 or 'Grade 7'..'Grade 12').";
                                    continue;
                                }
                                if ($strandErr !== null) {
                                    $skipped++;
                                    $details[] = "Line {$lineNum}: skipped ({$strandErr})";
                                    continue;
                                }
                                if ($gpa < 0 || $gpa > 100) {
                                    $skipped++;
                                    $details[] = "Line {$lineNum}: skipped (invalid GPA {$gpa}).";
                                    continue;
                                }

                                $studentId = 0;
                                $stmt = $pdo->prepare("SELECT student_id FROM students WHERE lrn = ? LIMIT 1");
                                $stmt->execute([$lrn]);
                                $found = $stmt->fetch();
                                if ($found) {
                                    $studentId = (int)$found["student_id"];
                                }
                                if ($studentId <= 0) {
                                    $stmt = $pdo->prepare("SELECT student_id FROM students WHERE name = ? AND grade_level = ? ORDER BY student_id DESC LIMIT 1");
                                    $stmt->execute([$name, $gradeLevel]);
                                    $found = $stmt->fetch();
                                    if ($found) {
                                        $studentId = (int)$found["student_id"];
                                    }
                                }

                                if ($studentId <= 0) {
                                    $stmt = $pdo->prepare("INSERT INTO students (lrn, name, grade_level, strand, section, gpa, absences, risk_score, risk_level) VALUES (?,?,?,?,?,?,?,0,'Low')");
                                    $stmt->execute([$lrn, $name, $gradeLevel, $strandNorm, $section, $gpa, $absences]);
                                    $studentId = (int)$pdo->lastInsertId();
                                } else {
                                    $stmtArch = $pdo->prepare("SELECT COALESCE(is_archived,0) FROM students WHERE student_id = ?");
                                    $stmtArch->execute([$studentId]);
                                    if ((int)$stmtArch->fetchColumn() === 1) {
                                        $skipped++;
                                        $details[] = "Line {$lineNum}: skipped (student is archived; unarchive before importing).";
                                        continue;
                                    }
                                    $stmt = $pdo->prepare("UPDATE students SET lrn = ?, name = ?, grade_level = ?, strand = ?, gpa = ?, absences = ? WHERE student_id = ?");
                                    $stmt->execute([$lrn, $name, $gradeLevel, $strandNorm, $gpa, $absences, $studentId]);
                                }

                                $stmtSec = $pdo->prepare("UPDATE students SET section = ? WHERE student_id = ?");
                                $stmtSec->execute([$section, $studentId]);

                                if (($user["role"] ?? "") === "Teacher") {
                                    $stmtMap = $pdo->prepare(
                                        "INSERT INTO teacher_students (teacher_user_id, student_id) VALUES (?,?)
                                         ON DUPLICATE KEY UPDATE teacher_user_id = VALUES(teacher_user_id)"
                                    );
                                    $stmtMap->execute([(int)$user["user_id"], $studentId]);
                                }

                                $stmtBatch = $pdo->prepare(
                                    "INSERT INTO student_batches (student_id, school_year, grade_level, section, strand, is_active) VALUES (?,?,?,?,?,1)
                                     ON DUPLICATE KEY UPDATE grade_level = VALUES(grade_level), section = VALUES(section), strand = VALUES(strand), is_active = 1"
                                );
                                $stmtBatch->execute([$studentId, $schoolYear, $gradeLevel, $section !== "" ? $section : null, $strandNorm]);

                                $track = curriculum_track_for_grade($gradeLevel);
                                $termId = $track ? curriculum_term_id_for_track_quarter($track, $quarter) : null;
                                if ($termId === null) {
                                    $skipped++;
                                    $details[] = "Line {$lineNum}: skipped (could not map grade_level '{$gradeLevel}' to a valid grading period).";
                                    continue;
                                }

                                $stmtPerf = $pdo->prepare(
                                    "INSERT INTO performance (student_id, school_year, term_id, quarter, gpa, days_present, total_school_days, absences, consecutive_absences)
                                     VALUES (?,?,?,?,?,?,?,?,?)
                                     ON DUPLICATE KEY UPDATE
                                       term_id = VALUES(term_id),
                                       gpa = VALUES(gpa),
                                       days_present = VALUES(days_present),
                                       total_school_days = VALUES(total_school_days),
                                       absences = VALUES(absences),
                                       consecutive_absences = VALUES(consecutive_absences)"
                                );
                                $stmtPerf->execute([$studentId, $schoolYear, $termId, $quarter, $gpa, $daysPresent, $totalSchoolDays, $absences, $consecutiveAbsences]);

                                $imported++;
                                } catch (Throwable $rowEx) {
                                    $skipped++;
                                    $details[] = "Line {$lineNum}: skipped (" . $rowEx->getMessage() . ").";
                                }
                            }

                            fclose($handle);
                            $pdo->commit();

                            audit_log("bulk_import", "success", "student", null, "Bulk CSV import completed.", [
                                "imported" => $imported,
                                "skipped" => $skipped,
                                "school_year" => $schoolYear,
                                "quarter" => $quarter,
                            ]);

                            $success = "Import complete: {$imported} imported, {$skipped} skipped.";
                        } catch (Throwable $e) {
                            fclose($handle);
                            if ($pdo->inTransaction()) {
                                $pdo->rollBack();
                            }
                            $error = "Import failed on line {$lineNum}: " . $e->getMessage();
                            audit_log("bulk_import", "failure", "student", null, "Bulk CSV import failed.", [
                                "line" => $lineNum,
                                "error" => $e->getMessage(),
                            ]);
                        }
                    }
                }
            }
        }
    }
}

ob_start();
?>
<div class="dg-page-header mb-3">
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
    <div>
      <h3 class="mb-0">Import Class Data</h3>
      <div class="text-muted small">Upload a CSV file exported from Google Sheets or Excel.</div>
    </div>
    <a class="btn btn-outline-secondary" href="data-entry">Back to Data Entry</a>
  </div>
</div>

<div class="row g-3">
  <div class="col-lg-6">
    <div class="card shadow-sm">
      <div class="card-body">
        <h5 class="mb-3">Upload CSV</h5>
        <?php if ($error): ?>
          <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
          <div class="alert alert-success"><?= htmlspecialchars($success, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></div>
          <?php if ($details): ?>
            <details class="mb-2">
              <summary class="small text-muted">Show details (<?= count($details) ?> notes)</summary>
              <pre class="small mb-0"><?= htmlspecialchars(implode("\n", $details), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></pre>
            </details>
          <?php endif; ?>
        <?php endif; ?>
        <form method="post" action="import" enctype="multipart/form-data">
          <?= csrf_field() ?>
          <input type="hidden" name="school_year" value="<?= htmlspecialchars($defaultSchoolYear, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" />
          <div class="mb-2">
            <label class="form-label">Quarter</label>
            <select class="form-select" name="quarter">
              <option value="1">1</option>
              <option value="2">2</option>
              <option value="3">3</option>
              <option value="4">4</option>
            </select>
          </div>
          <div class="mb-2">
            <label class="form-label">CSV File</label>
            <input class="form-control" type="file" name="csv_file" accept=".csv,.txt" required />
          </div>
          <button class="btn btn-dark w-100 mt-2" type="submit">Import</button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="card shadow-sm">
      <div class="card-body">
        <h5 class="mb-3">How to prepare your file</h5>
        <div class="small">
          <p class="fw-semibold">From Google Sheets:</p>
          <ol>
            <li>Open your class spreadsheet in Google Sheets</li>
            <li>File &gt; Download &gt; Comma Separated Values (.csv)</li>
            <li>Upload the downloaded .csv here</li>
          </ol>
          <p class="fw-semibold">From Excel:</p>
          <ol>
            <li>Open your class spreadsheet in Excel</li>
            <li>File &gt; Save As &gt; choose "CSV (Comma delimited) (*.csv)"</li>
            <li>Upload the saved .csv here</li>
          </ol>
          <p class="fw-semibold mb-1">Required columns (header row):</p>
          <code>name, grade_level, section, gpa, absences</code>
          <p class="small mt-2 mb-1">Grade level must be <strong>7-12</strong> (or <strong>Grade 7</strong> to <strong>Grade 12</strong>).</p>
          <p class="fw-semibold mt-2 mb-1">Optional columns:</p>
          <code>lrn, strand, days_present, total_school_days, consecutive_absences</code>
          <p class="small mt-1 mb-0">For <strong>Grade 11</strong> and <strong>Grade 12</strong>, include <code>strand</code> (legacy: STEM, ABM, HUMSS, TVL; strengthened: Education, TechVoc) or the row will be skipped.</p>
          <p class="mt-2"><a href="import-template" class="btn btn-sm btn-outline-dark">Download CSV Template</a></p>
        </div>
      </div>
    </div>
  </div>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . "/_layout.php";
