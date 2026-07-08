<?php
declare(strict_types=1);

require_once __DIR__ . "/../../app/auth.php";

start_app_session();

header("Content-Type: application/json; charset=utf-8");

if (($_SERVER["REQUEST_METHOD"] ?? "") !== "POST") {
    http_response_code(405);
    header("Allow: POST");
    echo json_encode(["ok" => false, "error" => "method"]);
    exit;
}

$token = isset($_POST["csrf_token"]) ? (string)$_POST["csrf_token"] : "";
if (!is_valid_csrf_token($token)) {
    http_response_code(403);
    echo json_encode(["ok" => false, "error" => "csrf"]);
    exit;
}

$doc = (string)($_POST["doc"] ?? "");
if ($doc === "privacy") {
    $_SESSION["login_legal_ack_privacy"] = true;
} elseif ($doc === "terms") {
    $_SESSION["login_legal_ack_terms"] = true;
} else {
    http_response_code(400);
    echo json_encode(["ok" => false, "error" => "doc"]);
    exit;
}

echo json_encode([
    "ok" => true,
    "privacy" => !empty($_SESSION["login_legal_ack_privacy"]),
    "terms" => !empty($_SESSION["login_legal_ack_terms"]),
]);
