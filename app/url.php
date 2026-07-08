<?php
declare(strict_types=1);

/** Configured URL prefix (empty when document root is public/). */
function app_base_path(): string
{
    if (!function_exists("app_config")) {
        require_once __DIR__ . "/auth.php";
    }
    $config = app_config();
    return rtrim((string)($config["app"]["base_path"] ?? ""), "/");
}

/**
 * Build a root-relative URL for routes and static assets.
 * Examples (base_path empty): base_url("assets/theme.css") → /assets/theme.css
 */
function base_url(string $path = ""): string
{
    $base = app_base_path();
    $path = ltrim($path, "/");
    if ($base === "") {
        return $path === "" ? "/" : "/" . $path;
    }
    return $path === "" ? $base : $base . "/" . $path;
}

/**
 * Normalize REQUEST_URI to a route segment path (no leading/trailing slashes).
 */
function app_normalize_request_path(?string $uri = null): string
{
    $raw = $uri ?? (parse_url($_SERVER["REQUEST_URI"] ?? "/", PHP_URL_PATH) ?? "/");
    $path = is_string($raw) ? $raw : "/";

    $base = app_base_path();
    if ($base !== "" && (str_starts_with($path, $base . "/") || $path === $base)) {
        $path = $path === $base ? "/" : substr($path, strlen($base));
    }

    // Legacy XAMPP subfolder installs (before moving to web root).
    foreach (["/Thesis%20Project/public/", "/Thesis Project/public/"] as $legacyPrefix) {
        $pos = stripos($path, $legacyPrefix);
        if ($pos !== false) {
            $path = substr($path, $pos + strlen($legacyPrefix) - 1);
            if ($path === "") {
                $path = "/";
            }
            break;
        }
    }

    $publicPos = stripos($path, "/public/");
    if ($publicPos !== false) {
        $path = substr($path, $publicPos + strlen("/public/"));
        if ($path === "" || !str_starts_with($path, "/")) {
            $path = "/" . ltrim((string)$path, "/");
        }
    }

    $path = trim($path, "/");
    if (strcasecmp($path, "index.php") === 0) {
        return "";
    }
    return $path;
}
