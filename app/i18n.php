<?php
declare(strict_types=1);

/**
 * Lightweight UI translations (session locale).
 */
function i18n_supported_locales(): array
{
    return [
        "en" => "English",
        "fil" => "Filipino",
        "ceb" => "Cebuano",
    ];
}

function i18n_bootstrap(): void
{
    start_app_session();
    $loc = $_SESSION["locale"] ?? null;
    if (!is_string($loc) || !array_key_exists($loc, i18n_supported_locales())) {
        $_SESSION["locale"] = "en";
    }
}

function i18n_locale(): string
{
    start_app_session();
    $loc = $_SESSION["locale"] ?? "en";
    return is_string($loc) && array_key_exists($loc, i18n_supported_locales()) ? $loc : "en";
}

function i18n_set_locale(string $code): void
{
    start_app_session();
    if (array_key_exists($code, i18n_supported_locales())) {
        $_SESSION["locale"] = $code;
    }
}

function i18n_html_lang(): string
{
    $loc = i18n_locale();
    return match ($loc) {
        "fil" => "fil",
        "ceb" => "ceb",
        default => "en",
    };
}

function i18n_current_route_segment(): string
{
    if (!function_exists("app_normalize_request_path")) {
        require_once __DIR__ . "/url.php";
    }
    $path = app_normalize_request_path();
    return $path === "" ? "login" : $path;
}

function t(string $key): string
{
    static $cacheLocale = null;
    static $cacheBundle = null;
    $loc = i18n_locale();
    if ($cacheLocale !== $loc || $cacheBundle === null) {
        /** @var array<string, array<string, string>> $catalog */
        $catalog = require __DIR__ . "/lang/messages.php";
        $extraPath = __DIR__ . "/lang/messages_pages.php";
        if (is_file($extraPath)) {
            /** @var array<string, array<string, string>> $extra */
            $extra = require $extraPath;
            foreach (["en", "fil", "ceb"] as $lc) {
                if (!isset($catalog[$lc])) {
                    $catalog[$lc] = [];
                }
                if (isset($extra[$lc]) && is_array($extra[$lc])) {
                    $catalog[$lc] = array_merge($catalog[$lc], $extra[$lc]);
                }
            }
        }
        $en = $catalog["en"] ?? [];
        $over = $catalog[$loc] ?? [];
        $cacheBundle = array_merge($en, is_array($over) ? $over : []);
        $cacheLocale = $loc;
    }
    return $cacheBundle[$key] ?? $key;
}

/** HTML-escaped translation (for views). */
function h_t(string $key): string
{
    return htmlspecialchars(t($key), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");
}

/**
 * Translation with :name placeholders replaced (e.g. tr("k", ["count" => "3"]) for "Added :count items").
 */
function tr(string $key, array $vars = []): string
{
    $s = t($key);
    foreach ($vars as $k => $v) {
        $s = str_replace(":" . (string)$k, (string)$v, $s);
    }
    return $s;
}

function i18n_role_label(string $role): string
{
    $k = "role." . $role;
    $v = t($k);
    return $v !== $k ? $v : $role;
}

function i18n_role_badge_class(string $role): string
{
    return match ($role) {
        "Teacher" => "dg-role-badge dg-role-badge--teacher",
        "Counselor" => "dg-role-badge dg-role-badge--counselor",
        "Admin" => "dg-role-badge dg-role-badge--admin",
        default => "dg-role-badge",
    };
}
