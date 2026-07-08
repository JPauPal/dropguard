<?php
declare(strict_types=1);

require_once __DIR__ . "/../../app/env.php";
dropguard_load_env();
$config = require __DIR__ . "/../../app/config.php";

header("Content-Type: text/plain; charset=utf-8");

$verbose = (bool)($config["app"]["health_verbose"] ?? false);

echo "Drop Guard health check\n";
echo "======================\n\n";

try {
    require_once __DIR__ . "/../../app/db.php";
    $pdo = db();
    echo "DB: OK (connected)\n";

    if ($verbose) {
        $dbName = $pdo->query("SELECT DATABASE() AS db")->fetch()["db"] ?? "(unknown)";
        echo "Database: {$dbName}\n";

        $tables = $pdo->query("SHOW TABLES")->fetchAll();
        $tableNames = array_map(static fn($r) => array_values($r)[0] ?? "", $tables);
        echo "Tables: " . implode(", ", array_filter($tableNames)) . "\n";

        $stmt = $pdo->prepare("SELECT user_id, username, role FROM users WHERE username = 'admin' LIMIT 1");
        $stmt->execute();
        $admin = $stmt->fetch();

        if ($admin) {
            echo "Admin user: FOUND (user_id={$admin['user_id']}, role={$admin['role']})\n";
        } else {
            echo "Admin user: NOT FOUND\n";
        }
    } else {
        echo "Environment: " . (string)($config["app"]["env"] ?? "unknown") . "\n";
        echo "Status: OK\n";
    }
} catch (Throwable $e) {
    echo "DB: ERROR\n";
    if ($verbose) {
        echo $e->getMessage() . "\n";
    } else {
        echo "Check database credentials in .env\n";
    }
}
