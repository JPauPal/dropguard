<?php
declare(strict_types=1);

require_once __DIR__ . "/env.php";
dropguard_load_env();

$env = dropguard_env("DROPGUARD_ENV", "local");
$isProduction = $env === "production";

$config = [
    /**
     * Symmetric key for encrypting sensitive text at rest (user PII, notes, audit JSON).
     * Generate e.g. random 32 bytes base64: `php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"`
     * Or set env DROPGUARD_DB_FIELD_KEY. Leave empty to disable field encryption (default).
     */
    "db_field_encryption_key" => dropguard_env("DROPGUARD_DB_FIELD_KEY", "") ?: "",
    "db" => [
        "host" => dropguard_env("DB_HOST", "127.0.0.1") ?: "127.0.0.1",
        "port" => (int)(dropguard_env("DB_PORT", "3306") ?: "3306"),
        "name" => dropguard_env("DB_NAME", "dropguard") ?: "dropguard",
        "user" => dropguard_env("DB_USER", "root") ?: "root",
        "pass" => dropguard_env("DB_PASS", "") ?? "",
        "charset" => dropguard_env("DB_CHARSET", "utf8mb4") ?: "utf8mb4",
        /** Optional: path to CA bundle for TLS to MySQL (e.g. MariaDB/MySQL with ssl). */
        "ssl_ca" => dropguard_env("MYSQL_SSL_CA", "") ?: "",
        /** Set false only for local dev with self-signed DB TLS. */
        "ssl_verify" => !dropguard_env_bool("MYSQL_SSL_VERIFY", true) ? false : true,
    ],
    "app" => [
        "name" => dropguard_env("APP_NAME", "Drop Guard") ?: "Drop Guard",
        /**
         * URL prefix before routes/assets. Empty when document root is public/ (VPS root).
         * Override locally via DROPGUARD_BASE_PATH in .env if needed.
         */
        "base_path" => dropguard_env("DROPGUARD_BASE_PATH", "") ?? "",
        "env" => $env,
        "session_name" => dropguard_env("SESSION_NAME", "dropguard_session") ?: "dropguard_session",
        "session_save_path" => dropguard_env("SESSION_SAVE_PATH", "") ?: (__DIR__ . "/../storage/sessions"),
        "display_errors" => dropguard_env_bool("APP_DISPLAY_ERRORS", !$isProduction),
        "force_https" => dropguard_env_bool("DROPGUARD_FORCE_HTTPS", $isProduction),
        /** When false, /health returns minimal output (recommended on production). */
        "health_verbose" => dropguard_env_bool("DROPGUARD_HEALTH_VERBOSE", !$isProduction),
    ],
    "ml" => [
        /**
         * Python executable for predict.py. Empty on shared hosting uses PHP fallback.
         * Local default: python (PATH). Windows example: C:\Python311\python.exe
         */
        "python" => $isProduction
            ? (dropguard_env("ML_PYTHON", "") ?? "")
            : (dropguard_env("ML_PYTHON", "python") ?: "python"),
        "predict_script" => dropguard_env("ML_PREDICT_SCRIPT", "") ?: (__DIR__ . "/../ml/predict.py"),
    ],
];

$localPath = __DIR__ . "/config.local.php";
if (is_file($localPath)) {
    $local = require $localPath;
    if (is_array($local)) {
        $config = array_replace_recursive($config, $local);
    }
}

return $config;
