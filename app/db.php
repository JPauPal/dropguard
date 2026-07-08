<?php
declare(strict_types=1);

require_once __DIR__ . "/db_field_crypto.php";

function db(): PDO
{
    static $pdo = null;
    static $ensuredEncryptionSchema = false;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $config = require __DIR__ . "/config.php";
    $db = $config["db"];

    $dsn = sprintf(
        "mysql:host=%s;port=%d;dbname=%s;charset=%s",
        $db["host"],
        (int) $db["port"],
        $db["name"],
        $db["charset"]
    );

    $opts = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    $sslCa = trim((string)($db["ssl_ca"] ?? ""));
    if ($sslCa !== "" && defined("PDO::MYSQL_ATTR_SSL_CA")) {
        $opts[PDO::MYSQL_ATTR_SSL_CA] = $sslCa;
        if (defined("PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT")) {
            $verify = (bool)($db["ssl_verify"] ?? true);
            $opts[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = $verify;
        }
    }

    $pdo = new PDO($dsn, $db["user"], $db["pass"], $opts);

    if (!$ensuredEncryptionSchema) {
        $ensuredEncryptionSchema = true;
        database_encryption_ensure_schema();
    }

    return $pdo;
}
