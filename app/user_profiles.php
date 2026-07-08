<?php
declare(strict_types=1);

require_once __DIR__ . "/db.php";

function ensure_users_profile_photo_column(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $pdo = db();
    try {
        $has = $pdo->query("SHOW COLUMNS FROM `users` LIKE 'profile_photo_path'")->fetch();
        if (!$has) {
            $pdo->exec("ALTER TABLE `users` ADD COLUMN profile_photo_path VARCHAR(255) NULL AFTER `contact_number`");
        }
    } catch (Throwable) {
    }
    $done = true;
}

function user_profile_upload_dir_abs(): string
{
    $base = realpath(__DIR__ . "/../public");
    if ($base === false) {
        return "";
    }
    return $base . DIRECTORY_SEPARATOR . "uploads" . DIRECTORY_SEPARATOR . "staff";
}

function user_profile_upload_dir_rel(): string
{
    return "uploads/staff";
}

/**
 * Saves a profile photo for a staff user; returns relative path under public/.
 */
function save_user_profile_photo(int $userId, array $file): string
{
    ensure_users_profile_photo_column();
    if ($userId <= 0) {
        throw new RuntimeException("Invalid user.");
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

    $baseDir = user_profile_upload_dir_abs();
    if ($baseDir === "" || !is_dir($baseDir)) {
        $baseDir = __DIR__ . "/../public/" . user_profile_upload_dir_rel();
        if (!is_dir($baseDir) && !mkdir($baseDir, 0775, true) && !is_dir($baseDir)) {
            throw new RuntimeException("Failed to create uploads directory.");
        }
    }

    $name = "profile_" . $userId . "_" . date("Ymd_His") . "_" . bin2hex(random_bytes(4)) . "." . $ext;
    $destAbs = rtrim($baseDir, "\\/") . DIRECTORY_SEPARATOR . $name;
    if (!move_uploaded_file($tmp, $destAbs)) {
        throw new RuntimeException("Failed to save image.");
    }

    return user_profile_upload_dir_rel() . "/" . $name;
}

function delete_user_profile_photo_file(?string $relPath): void
{
    $relPath = trim((string)$relPath);
    if ($relPath === "" || str_contains($relPath, "..")) {
        return;
    }
    $prefix = user_profile_upload_dir_rel() . "/";
    if (!str_starts_with(str_replace("\\", "/", $relPath), $prefix)) {
        return;
    }
    $abs = realpath(__DIR__ . "/../public/" . str_replace("/", DIRECTORY_SEPARATOR, $relPath));
    $base = realpath(__DIR__ . "/../public/" . user_profile_upload_dir_rel());
    if ($abs && $base && str_starts_with($abs, $base) && is_file($abs)) {
        @unlink($abs);
    }
}

function user_profile_default_avatar_url(): string
{
    static $url = null;
    if (is_string($url)) {
        return $url;
    }
    if (!function_exists("base_url")) {
        require_once __DIR__ . "/url.php";
    }
    $url = base_url("assets/default-profile-avatar.svg");
    return $url;
}

function user_profile_avatar_url(?string $profilePhotoPath): string
{
    $path = trim((string)$profilePhotoPath);
    if ($path !== "") {
        return $path;
    }
    return user_profile_default_avatar_url();
}

function user_profile_has_custom_photo(?string $profilePhotoPath): bool
{
    return trim((string)$profilePhotoPath) !== "";
}
