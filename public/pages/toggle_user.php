<?php
declare(strict_types=1);

require_once __DIR__ . "/../../app/auth.php";
require_once __DIR__ . "/../../app/db.php";

require_login();
require_role(["Admin"]);

if (($_SERVER["REQUEST_METHOD"] ?? "GET") !== "POST") {
    http_response_code(405);
    echo "Method Not Allowed";
    exit;
}
require_valid_csrf_token();

$userId = (int)($_POST["user_id"] ?? 0);
$activate = (int)($_POST["activate"] ?? 0) === 1 ? 1 : 0;

if ($userId <= 0) {
    start_app_session();
    $_SESSION["flash"] = "Invalid user.";
    header("Location: users");
    exit;
}

$stmt = db()->prepare("UPDATE users SET is_active = ? WHERE user_id = ?");
$stmt->execute([$activate, $userId]);

audit_log(
    "user_toggle_active",
    "success",
    "user",
    $userId,
    $activate === 1 ? "User account activated." : "User account deactivated."
);

start_app_session();
$_SESSION["flash"] = $activate === 1 ? "User activated." : "User deactivated.";
header("Location: users");
exit;

