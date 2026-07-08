<?php
declare(strict_types=1);

require_once __DIR__ . "/db.php";

function ensure_app_settings_table(): void
{
    static $done = false;
    if ($done) {
        return;
    }

    db()->exec(
        "CREATE TABLE IF NOT EXISTS app_settings (
            setting_key VARCHAR(80) PRIMARY KEY,
            setting_value VARCHAR(255) NOT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB"
    );

    // Defaults
    db()->exec("INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES ('risk_low_max', '0.40')");
    db()->exec("INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES ('risk_high_min', '0.70')");
    $done = true;
}

function get_setting(string $key, ?string $default = null): ?string
{
    ensure_app_settings_table();
    $stmt = db()->prepare("SELECT setting_value FROM app_settings WHERE setting_key = ? LIMIT 1");
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    if (!$row) {
        return $default;
    }
    return (string)$row["setting_value"];
}

function set_setting(string $key, string $value): void
{
    ensure_app_settings_table();
    $stmt = db()->prepare(
        "INSERT INTO app_settings (setting_key, setting_value)
         VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
    );
    $stmt->execute([$key, $value]);
}

function get_risk_thresholds(): array
{
    $lowMax = (float)(get_setting("risk_low_max", "0.40") ?? "0.40");
    $highMin = (float)(get_setting("risk_high_min", "0.70") ?? "0.70");
    if ($lowMax < 0.0) $lowMax = 0.0;
    if ($highMin > 1.0) $highMin = 1.0;
    if ($highMin < $lowMax) {
        $highMin = $lowMax;
    }

    return [
        "low_max" => $lowMax,
        "high_min" => $highMin,
    ];
}
