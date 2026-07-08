<?php
declare(strict_types=1);

require_once __DIR__ . "/../../app/auth.php";
require_once __DIR__ . "/../../app/i18n.php";

start_app_session();

$dgLegalDoc = "terms";

ob_start();
require __DIR__ . "/partials/legal_page_shell.php";
$content = ob_get_clean();
$dg_body_class = "dg-legal-body";
require __DIR__ . "/_layout.php";
