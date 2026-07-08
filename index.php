<?php
declare(strict_types=1);

// Bootstrap the app when Apache document root is the project folder (not recommended).
// Preferred VPS setup: point DocumentRoot at public/ (see deploy/apache-edudropguard.conf).
require __DIR__ . "/public/index.php";

