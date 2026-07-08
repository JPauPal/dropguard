<?php
declare(strict_types=1);

require_once __DIR__ . "/../../app/auth.php";

logout();
header("Location: landing");
exit;
