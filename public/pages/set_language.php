<?php
declare(strict_types=1);

require_once __DIR__ . "/../../app/auth.php";
require_once __DIR__ . "/../../app/i18n.php";

start_app_session();

if (($_SERVER["REQUEST_METHOD"] ?? "") !== "POST") {
    http_response_code(405);
    header("Allow: POST");
    echo "Method not allowed";
    exit;
}

require_valid_csrf_token();

$lang = (string)($_POST["lang"] ?? "");
i18n_set_locale($lang);

$next = (string)($_POST["next"] ?? "login");
if (!preg_match('/^[a-z0-9\-]+$/', $next)) {
    $next = "login";
}
$blocked = ["set-language", "legal-ack", "logout"];
if (in_array($next, $blocked, true)) {
    $next = "login";
}

header("Location: " . $next);
exit;
