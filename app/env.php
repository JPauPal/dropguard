<?php
declare(strict_types=1);

/**
 * Lightweight .env loader and production runtime helpers (no Composer dependency).
 */

function dropguard_load_env(?string $projectRoot = null): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }
    $loaded = true;

    dropguard_trust_proxy_https();

    $root = $projectRoot ?? dirname(__DIR__);
    $path = $root . DIRECTORY_SEPARATOR . ".env";
    if (!is_file($path) || !is_readable($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim((string)$line);
        if ($line === "" || str_starts_with($line, "#")) {
            continue;
        }
        if (!str_contains($line, "=")) {
            continue;
        }

        [$name, $value] = explode("=", $line, 2);
        $name = trim($name);
        if ($name === "") {
            continue;
        }

        $value = trim($value);
        if (
            (str_starts_with($value, '"') && str_ends_with($value, '"'))
            || (str_starts_with($value, "'") && str_ends_with($value, "'"))
        ) {
            $value = substr($value, 1, -1);
        }

        if (getenv($name) === false) {
            putenv("{$name}={$value}");
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }
}

/** Hostinger and other reverse proxies often terminate TLS upstream. */
function dropguard_trust_proxy_https(): void
{
    if (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off") {
        return;
    }

    $proto = $_SERVER["HTTP_X_FORWARDED_PROTO"] ?? $_SERVER["HTTP_X_FORWARDED_SSL"] ?? "";
    if (is_string($proto) && strtolower($proto) === "https") {
        $_SERVER["HTTPS"] = "on";
    }
}

function dropguard_env(string $key, ?string $default = null): ?string
{
    $value = getenv($key);
    if ($value === false) {
        return $default;
    }
    return $value;
}

function dropguard_env_bool(string $key, bool $default = false): bool
{
    $value = dropguard_env($key);
    if ($value === null) {
        return $default;
    }
    return in_array(strtolower($value), ["1", "true", "yes", "on"], true);
}

/**
 * Apply display_errors and optional HTTPS redirect before any output.
 *
 * @param array<string, mixed> $config
 */
function dropguard_apply_runtime_config(array $config): void
{
    $displayErrors = (bool)($config["app"]["display_errors"] ?? false);
    ini_set("display_errors", $displayErrors ? "1" : "0");
    ini_set("log_errors", "1");
    error_reporting(E_ALL);

    $forceHttps = (bool)($config["app"]["force_https"] ?? false);
    if (!$forceHttps) {
        return;
    }

    $isHttps = !empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off";
    if ($isHttps) {
        return;
    }

    $host = (string)($_SERVER["HTTP_HOST"] ?? "");
    if ($host === "") {
        return;
    }

    $uri = (string)($_SERVER["REQUEST_URI"] ?? "/");
    header("Location: https://{$host}{$uri}", true, 301);
    exit;
}
