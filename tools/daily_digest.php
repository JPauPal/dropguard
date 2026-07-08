<?php

declare(strict_types=1);



/**

 * CLI cron: php tools/daily_digest.php

 * Schedule daily on the server (e.g. Windows Task Scheduler or cron at 7:00 AM).

 */



require_once __DIR__ . "/../app/env.php";

dropguard_load_env();

dropguard_apply_runtime_config(require __DIR__ . "/../app/config.php");



require_once __DIR__ . "/../app/db.php";

require_once __DIR__ . "/../app/digest.php";



$result = digest_run_daily();



echo "Drop Guard daily digest\n";

echo "Recipient: " . ($result["recipient"] !== "" ? $result["recipient"] : "(none)") . "\n";

echo "High-risk count: " . (int)$result["count"] . "\n";

echo "Sent: " . ($result["sent"] ? "yes" : "no") . "\n";

if (!empty($result["error"])) {

    echo "Error: " . $result["error"] . "\n";

    exit(1);

}

exit(0);

