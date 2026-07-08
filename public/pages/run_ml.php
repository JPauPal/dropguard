<?php
declare(strict_types=1);

require_once __DIR__ . "/../../app/auth.php";
require_once __DIR__ . "/../../app/ml.php";

require_login();
require_role(["Counselor", "Admin"]);

$result = run_predictions_and_update_db();

start_app_session();

if ($result["error"]) {
    http_response_code(500);
    $msg = tr("run_ml.flash.fail", ["reason" => (string)$result["error"]]);
    if (stripos((string)$result["error"], "python was not found") !== false) {
        $msg .= t("run_ml.flash.python_hint");
    }
    audit_log("ml_run", "failure", "risk_analysis", null, "ML run failed.", ["error" => $result["error"]]);
} else {
    $mode = (string)($result["mode"] ?? "heuristic");
    $msg = tr("run_ml.flash.success", ["count" => (string)(int)$result["updated"]]);
    if ($mode === "random_forest") {
        $msg .= " " . t("run_ml.flash.mode_rf");
    } elseif ($mode === "php_heuristic_fallback") {
        $msg .= " " . t("run_ml.flash.mode_php_fallback");
    } else {
        $msg .= " " . t("run_ml.flash.mode_heuristic");
    }
    $_SESSION["ml_run_meta"] = [
        "mode" => $mode,
        "metrics" => $result["metrics"] ?? null,
        "updated" => (int)$result["updated"],
        "used_php_fallback" => !empty($result["used_php_fallback"]),
    ];
    audit_log("ml_run", "success", "risk_analysis", null, "ML run completed.", [
        "updated_records" => (int)$result["updated"],
        "mode" => $mode,
    ]);
}

$_SESSION["flash"] = $msg;

header("Location: dashboard");
exit;
