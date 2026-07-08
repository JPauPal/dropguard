<?php
declare(strict_types=1);

require_once __DIR__ . "/db.php";

function ensure_students_archived_column(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $pdo = db();
    try {
        $has = $pdo->query("SHOW COLUMNS FROM `students` LIKE 'is_archived'")->fetch();
        if (!$has) {
            $pdo->exec("ALTER TABLE `students` ADD COLUMN is_archived TINYINT(1) NOT NULL DEFAULT 0 AFTER `risk_level`");
        }
    } catch (Throwable) {
    }
    $done = true;
}

/** SQL fragment for non-archived students (table alias). */
function students_non_archived_sql(string $alias = "s"): string
{
    return "COALESCE({$alias}.is_archived,0) = 0";
}
