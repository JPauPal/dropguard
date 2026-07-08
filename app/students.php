<?php
declare(strict_types=1);

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/students_archive.php";
require_once __DIR__ . "/section_schedule.php";
require_once __DIR__ . "/subjects.php";

function ensure_students_face_column(): void
{
    static $done = false;
    if ($done) return;
    $pdo = db();
    try {
        $has = $pdo->query("SHOW COLUMNS FROM `students` LIKE 'face_image_path'")->fetch();
        if (!$has) {
            $pdo->exec("ALTER TABLE `students` ADD COLUMN face_image_path VARCHAR(255) NULL AFTER `section`");
        }
    } catch (Throwable) {
    }
    $done = true;
}

function student_upload_dir_abs(): string
{
    return realpath(__DIR__ . "/../public") . DIRECTORY_SEPARATOR . "uploads" . DIRECTORY_SEPARATOR . "students";
}

function student_upload_dir_rel(): string
{
    return "uploads/students";
}

/**
 * Saves a face photo for a student and returns relative path, e.g. "uploads/students/face_123_...jpg".
 */
function save_student_face_photo(int $studentId, array $file): string
{
    ensure_students_face_column();
    if ($studentId <= 0) {
        throw new RuntimeException("Invalid student.");
    }
    if (!isset($file["error"]) || (int)$file["error"] !== UPLOAD_ERR_OK) {
        throw new RuntimeException("Upload failed.");
    }
    $tmp = (string)($file["tmp_name"] ?? "");
    if ($tmp === "" || !is_file($tmp)) {
        throw new RuntimeException("Upload missing temp file.");
    }
    $size = (int)($file["size"] ?? 0);
    if ($size <= 0 || $size > 5 * 1024 * 1024) {
        throw new RuntimeException("Image must be <= 5MB.");
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($tmp) ?: "";
    $ext = match ($mime) {
        "image/jpeg" => "jpg",
        "image/png" => "png",
        "image/webp" => "webp",
        default => "",
    };
    if ($ext === "") {
        throw new RuntimeException("Only JPG, PNG, or WebP images are allowed.");
    }

    $baseDir = student_upload_dir_abs();
    if ($baseDir === "" || !is_dir($baseDir)) {
        // Create at runtime if missing
        $baseDir = __DIR__ . "/../public/" . student_upload_dir_rel();
        if (!is_dir($baseDir)) {
            if (!mkdir($baseDir, 0775, true) && !is_dir($baseDir)) {
                throw new RuntimeException("Failed to create uploads directory.");
            }
        }
    }

    $name = "face_" . $studentId . "_" . date("Ymd_His") . "_" . bin2hex(random_bytes(4)) . "." . $ext;
    $destAbs = rtrim($baseDir, "\\/") . DIRECTORY_SEPARATOR . $name;
    if (!move_uploaded_file($tmp, $destAbs)) {
        throw new RuntimeException("Failed to save image.");
    }

    return student_upload_dir_rel() . "/" . $name;
}

/** @return list<string> */
function list_enrolled_subjects_for_student(int $studentId): array
{
    $studentId = (int)$studentId;
    if ($studentId <= 0) return [];

    ensure_section_schedule_table();
    ensure_subjects_table();

    $stmt = db()->prepare("SELECT section FROM students WHERE student_id = ? LIMIT 1");
    $stmt->execute([$studentId]);
    $row = $stmt->fetch();
    $section = trim((string)($row["section"] ?? ""));
    if ($section === "") return [];

    $out = [];
    foreach (list_section_subjects($section) as $subject) {
        $label = subject_label($subject);
        if ($label !== "") $out[] = $label;
    }
    if ($out) {
        return $out;
    }

    $rows = db()->prepare(
        "SELECT DISTINCT COALESCE(NULLIF(subject_code, ''), subject) AS subject_label
         FROM section_schedule
         WHERE section = ? AND COALESCE(NULLIF(subject_code, ''), NULLIF(subject, '')) IS NOT NULL
         ORDER BY subject_label ASC"
    );
    $rows->execute([$section]);
    foreach ($rows->fetchAll() as $r) {
        $s = trim((string)($r["subject_label"] ?? ""));
        if ($s !== "") $out[] = $s;
    }
    return $out;
}

/**
 * Maps DB/runtime errors from student save flows to a clear UI message.
 */
function student_save_error_message(Throwable $e): string
{
    require_once __DIR__ . "/i18n.php";
    $msg = $e->getMessage();
    if ($msg === "ARCHIVED_STUDENT") {
        return t("data_entry.err_archived_student");
    }
    if ($msg === "LRN already exists." || str_starts_with($msg, "LRN already exists")) {
        return t("data_entry.err_lrn_duplicate");
    }
    if ($e instanceof PDOException) {
        $mysqlErr = (int)($e->errorInfo[1] ?? 0);
        $sqlState = (string)($e->errorInfo[0] ?? "");
        if ($mysqlErr === 1062 || $sqlState === "23000") {
            if (stripos($msg, "lrn") !== false) {
                return t("data_entry.err_lrn_duplicate");
            }
            return t("data_entry.err_save_conflict");
        }
    }
    return tr("data_entry.err_save_generic", ["reason" => $msg]);
}

