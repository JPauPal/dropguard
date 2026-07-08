<?php
declare(strict_types=1);

/**
 * Optional symmetric encryption for sensitive database text (notes, audit payloads, user PII).
 *
 * Set app.db_field_encryption_key in config (or env DROPGUARD_DB_FIELD_KEY) to a long random secret.
 * Uses libsodium secretbox; ciphertext is prefixed with DGENC1: for legacy plaintext detection.
 *
 * Student names / LRN are intentionally not encrypted here: they drive LIKE search and UNIQUE(lrn).
 * Prefer OS / MySQL encryption-at-rest for full-disk protection of those columns.
 */

const DB_FIELD_CRYPTO_MAGIC = "DGENC1:";

/** @return array<string, mixed> */
function db_field_crypto_config(): array
{
    static $cfg = null;
    if ($cfg === null) {
        $cfg = require __DIR__ . "/config.php";
    }
    return $cfg;
}

function app_db_field_encryption_key_raw(): string
{
    $cfg = db_field_crypto_config();
    $k = (string)($cfg["db_field_encryption_key"] ?? "");
    return trim($k);
}

function db_field_encryption_enabled(): bool
{
    return app_db_field_encryption_key_raw() !== "";
}

/** @return non-empty-string */
function db_field_encryption_key_binary(): string
{
    $raw = app_db_field_encryption_key_raw();
    if ($raw === "") {
        throw new RuntimeException("DB field encryption key is not configured.");
    }
    // Derive a 32-byte key from any reasonable passphrase or base64 secret.
    if (preg_match('/^[A-Za-z0-9+\/]+=*$/', $raw) && strlen($raw) >= 44) {
        $bin = base64_decode($raw, true);
        if ($bin !== false && strlen($bin) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            return $bin;
        }
    }
    return hash("sha256", $raw, true);
}

/**
 * Encrypt a scalar string for storage. Null stays null; empty string stays empty.
 */
function db_field_encrypt(?string $plaintext): ?string
{
    if (!db_field_encryption_enabled()) {
        return $plaintext;
    }
    if (!function_exists("sodium_crypto_secretbox")) {
        throw new RuntimeException("PHP sodium extension is required when db_field_encryption_key is set.");
    }
    if ($plaintext === null) {
        return null;
    }
    if ($plaintext === "") {
        return "";
    }
    $key = db_field_encryption_key_binary();
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $cipher = sodium_crypto_secretbox($plaintext, $nonce, $key);
    return DB_FIELD_CRYPTO_MAGIC . base64_encode($nonce . $cipher);
}

/**
 * Decrypt a stored value. Legacy plaintext (no magic prefix) is returned unchanged.
 */
function db_field_decrypt(?string $stored): ?string
{
    if ($stored === null || $stored === "") {
        return $stored;
    }
    if (!str_starts_with($stored, DB_FIELD_CRYPTO_MAGIC)) {
        return $stored;
    }
    if (!function_exists("sodium_crypto_secretbox_open")) {
        return "[encrypted — enable PHP sodium extension]";
    }
    if (!db_field_encryption_enabled()) {
        return "[encrypted — set db_field_encryption_key to decrypt]";
    }
    $b64 = substr($stored, strlen(DB_FIELD_CRYPTO_MAGIC));
    $raw = base64_decode($b64, true);
    if ($raw === false || strlen($raw) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
        return $stored;
    }
    $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $plain = sodium_crypto_secretbox_open($cipher, $nonce, db_field_encryption_key_binary());
    if ($plain === false) {
        return $stored;
    }
    return $plain;
}

/**
 * Decrypt user PII columns if present (full_name, email, contact_number).
 *
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function db_decrypt_user_pii_row(array $row): array
{
    if (!db_field_encryption_enabled()) {
        return $row;
    }
    if (isset($row["full_name"]) && is_string($row["full_name"])) {
        $row["full_name"] = db_field_decrypt($row["full_name"]);
    }
    if (array_key_exists("email", $row) && $row["email"] !== null && $row["email"] !== "") {
        $row["email"] = db_field_decrypt((string)$row["email"]);
    } elseif (array_key_exists("email", $row) && $row["email"] === "") {
        $row["email"] = null;
    }
    if (array_key_exists("contact_number", $row) && $row["contact_number"] !== null && $row["contact_number"] !== "") {
        $row["contact_number"] = db_field_decrypt((string)$row["contact_number"]);
    }
    return $row;
}

/**
 * @param list<array<string, mixed>> $rows
 * @return list<array<string, mixed>>
 */
function db_decrypt_user_pii_rows(array $rows): array
{
    if (!db_field_encryption_enabled()) {
        return $rows;
    }
    return array_map(static fn(array $r): array => db_decrypt_user_pii_row($r), $rows);
}

/**
 * Widen columns so ciphertext fits; safe to call repeatedly.
 */
function database_encryption_ensure_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        $pdo = db();
        $hasCol = static function (PDO $p, string $table, string $col): bool {
            $st = $p->prepare(
                "SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
            );
            $st->execute([$table, $col]);
            return (int)(($st->fetch())["c"] ?? 0) > 0;
        };

        if ($hasCol($pdo, "users", "full_name")) {
            $pdo->exec("ALTER TABLE users MODIFY full_name VARCHAR(512) NOT NULL");
        }
        if ($hasCol($pdo, "users", "email")) {
            $pdo->exec("ALTER TABLE users MODIFY email VARCHAR(512) NULL");
        }
        if ($hasCol($pdo, "users", "contact_number")) {
            $pdo->exec("ALTER TABLE users MODIFY contact_number VARCHAR(255) NULL");
        }
        if ($hasCol($pdo, "manual_referrals", "reason")) {
            $pdo->exec("ALTER TABLE manual_referrals MODIFY reason TEXT NOT NULL");
        }
        if ($hasCol($pdo, "student_flags", "note")) {
            $pdo->exec("ALTER TABLE student_flags MODIFY note TEXT NULL");
        }
    } catch (Throwable) {
        // Best-effort migration (permissions / older MySQL); app may still run without widened columns.
    }
}
