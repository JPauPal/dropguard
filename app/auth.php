<?php
declare(strict_types=1);

require_once __DIR__ . "/db.php";

function app_config(): array
{
    static $config = null;
    if (is_array($config)) {
        return $config;
    }
    $config = require __DIR__ . "/config.php";
    return $config;
}

function start_app_session(): void
{
    $config = app_config();
    if (session_status() === PHP_SESSION_NONE) {
        $savePath = $config["app"]["session_save_path"] ?? (__DIR__ . "/../storage/sessions");
        if (!is_dir($savePath)) {
            mkdir($savePath, 0700, true);
        }

        // Harden PHP session behavior and use private file storage.
        ini_set("session.save_path", $savePath);
        ini_set("session.use_only_cookies", "1");
        ini_set("session.use_strict_mode", "1");
        ini_set("session.cookie_httponly", "1");
        ini_set("session.cookie_samesite", "Lax");
        ini_set("session.cookie_secure", (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off") ? "1" : "0");
        ini_set("session.gc_maxlifetime", "7200");

        $cookiePath = "/";
        session_set_cookie_params([
            "lifetime" => 0,
            "path" => $cookiePath,
            "domain" => "",
            "secure" => (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off"),
            "httponly" => true,
            "samesite" => "Lax",
        ]);

        session_name($config["app"]["session_name"]);
        session_start();

        // Bind session to browser fingerprint to reduce hijacking risk.
        $fingerprint = hash("sha256", (string)($_SERVER["HTTP_USER_AGENT"] ?? "unknown"));
        if (!isset($_SESSION["fingerprint"])) {
            $_SESSION["fingerprint"] = $fingerprint;
        } elseif (!hash_equals((string)$_SESSION["fingerprint"], $fingerprint)) {
            logout();
            session_name($config["app"]["session_name"]);
            session_start();
            $_SESSION["fingerprint"] = $fingerprint;
        }
    }
}

function set_login_error_message(?string $msg): void
{
    start_app_session();
    $_SESSION["login_error_msg"] = $msg;
}

function csrf_token(): string
{
    start_app_session();
    $token = $_SESSION["csrf_token"] ?? null;
    if (!is_string($token) || strlen($token) < 32) {
        $token = bin2hex(random_bytes(32));
        $_SESSION["csrf_token"] = $token;
    }
    return $token;
}

function csrf_field(): string
{
    $token = htmlspecialchars(csrf_token(), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");
    return '<input type="hidden" name="csrf_token" value="' . $token . '" />';
}

function is_valid_csrf_token(?string $token): bool
{
    if (!is_string($token) || $token === "") {
        return false;
    }
    $sessionToken = csrf_token();
    return hash_equals($sessionToken, $token);
}

function require_valid_csrf_token(): void
{
    $token = isset($_POST["csrf_token"]) ? (string)$_POST["csrf_token"] : "";
    if (!is_valid_csrf_token($token)) {
        http_response_code(403);
        echo "Invalid CSRF token.";
        exit;
    }
}

function get_login_error_message(): ?string
{
    start_app_session();
    $msg = $_SESSION["login_error_msg"] ?? null;
    unset($_SESSION["login_error_msg"]);
    return is_string($msg) ? $msg : null;
}

function ensure_auth_security_table(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    db()->exec(
        "CREATE TABLE IF NOT EXISTS auth_login_attempts (
            attempt_id BIGINT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) NOT NULL,
            ip_address VARCHAR(45) NULL,
            success TINYINT(1) NOT NULL DEFAULT 0,
            user_agent VARCHAR(255) NULL,
            attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB"
    );

    // Ensure is_active exists on users for account activation/deactivation controls.
    $existsStmt = db()->query(
        "SELECT COUNT(*) AS c
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'users'
           AND COLUMN_NAME = 'is_active'"
    );
    $exists = (int)(($existsStmt->fetch())["c"] ?? 0);
    if ($exists === 0) {
        db()->exec("ALTER TABLE users ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1");
    }

    $emailColStmt = db()->query(
        "SELECT COUNT(*) AS c
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'users'
           AND COLUMN_NAME = 'email'"
    );
    $emailColExists = (int)(($emailColStmt->fetch())["c"] ?? 0);
    if ($emailColExists === 0) {
        db()->exec("ALTER TABLE users ADD COLUMN email VARCHAR(150) NULL");
    }

    $contactColStmt = db()->query(
        "SELECT COUNT(*) AS c
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'users'
           AND COLUMN_NAME = 'contact_number'"
    );
    $contactColExists = (int)(($contactColStmt->fetch())["c"] ?? 0);
    if ($contactColExists === 0) {
        db()->exec("ALTER TABLE users ADD COLUMN contact_number VARCHAR(30) NULL");
    }

    $done = true;
}

function login_rate_limit_window_seconds(): int
{
    return 10 * 60; // 10 minutes
}

function login_rate_limit_max_failures(): int
{
    return 3;
}

function login_lockout_base_seconds(): int
{
    return 10; // Start with a 10-second lockout.
}

function login_lockout_max_seconds(): int
{
    return 10 * 60; // Cap lockout at 10 minutes.
}

function get_login_wait_seconds(string $username, string $ip): int
{
    ensure_auth_security_table();
    $window = login_rate_limit_window_seconds();

    $stmt = db()->prepare(
        "SELECT COUNT(*) AS c,
                MAX(UNIX_TIMESTAMP(attempted_at)) AS last_attempt_ts,
                UNIX_TIMESTAMP(NOW()) AS now_ts
         FROM auth_login_attempts
         WHERE success = 0
           AND attempted_at >= (NOW() - INTERVAL ? SECOND)
           AND (username = ? OR ip_address = ?)"
    );
    $stmt->execute([$window, $username, $ip]);
    $row = $stmt->fetch();
    $count = (int)($row["c"] ?? 0);
    if ($count < login_rate_limit_max_failures()) {
        return 0;
    }

    // Progressive lockout:
    // - starts at 10 seconds on the threshold failure
    // - increases with additional failures
    // - capped at 10 minutes
    $over = max(0, $count - login_rate_limit_max_failures());
    $lockSeconds = (int)(login_lockout_base_seconds() * (2 ** $over));
    $lockSeconds = min(login_lockout_max_seconds(), $lockSeconds);

    $lastTs = (int)($row["last_attempt_ts"] ?? 0);
    $nowTs = (int)($row["now_ts"] ?? 0);
    if ($lastTs <= 0 || $nowTs <= 0) {
        return $lockSeconds;
    }

    // If lock duration has already elapsed, unlock immediately.
    $elapsed = max(0, $nowTs - $lastTs);
    $wait = $lockSeconds - $elapsed;
    if ($wait <= 0) {
        return 0;
    }

    // Guardrail for clock skew: never exceed the current lock duration.
    return min($lockSeconds, $wait);
}

function record_login_attempt(string $username, bool $success): void
{
    ensure_auth_security_table();
    $ip = (string)($_SERVER["REMOTE_ADDR"] ?? "");
    $ua = substr((string)($_SERVER["HTTP_USER_AGENT"] ?? ""), 0, 255);

    $stmt = db()->prepare(
        "INSERT INTO auth_login_attempts (username, ip_address, success, user_agent)
         VALUES (?, ?, ?, ?)"
    );
    $stmt->execute([$username, $ip !== "" ? $ip : null, $success ? 1 : 0, $ua !== "" ? $ua : null]);
}

function current_user(): ?array
{
    start_app_session();
    $maxIdle = 60 * 60 * 2; // 2 hours
    $last = (int)($_SESSION["last_activity"] ?? 0);
    if ($last > 0 && (time() - $last) > $maxIdle) {
        logout();
        return null;
    }
    $_SESSION["last_activity"] = time();

    $sessUser = $_SESSION["user"] ?? null;
    if (is_array($sessUser) && isset($sessUser["user_id"])) {
        $uid = (int)$sessUser["user_id"];
        if ($uid > 0 && !array_key_exists("profile_photo_path", $sessUser)) {
            try {
                require_once __DIR__ . "/user_profiles.php";
                ensure_users_profile_photo_column();
                $stPhoto = db()->prepare("SELECT profile_photo_path FROM users WHERE user_id = ? LIMIT 1");
                $stPhoto->execute([$uid]);
                $photoRow = $stPhoto->fetch();
                $_SESSION["user"]["profile_photo_path"] = trim((string)($photoRow["profile_photo_path"] ?? ""));
            } catch (Throwable) {
                $_SESSION["user"]["profile_photo_path"] = "";
            }
        }
        $checkedAt = (int)($_SESSION["user_active_checked_at"] ?? 0);
        if ($uid > 0 && (time() - $checkedAt) >= 60) {
            try {
                $st = db()->prepare("SELECT is_active FROM users WHERE user_id = ? LIMIT 1");
                $st->execute([$uid]);
                $live = $st->fetch();
                if (!$live || (int)($live["is_active"] ?? 0) !== 1) {
                    logout();
                    return null;
                }
            } catch (Throwable) {
            }
            $_SESSION["user_active_checked_at"] = time();
        }
    }

    return $_SESSION["user"] ?? null;
}

/**
 * First URL segment routed by public/index.php (after base_path /public strip).
 */
function app_request_route_segment(): string
{
    $path = app_normalize_request_path();
    if ($path === "") {
        return "";
    }
    return explode("/", $path, 2)[0];
}

function stash_login_intended_route(): void
{
    $seg = app_request_route_segment();
    if ($seg === "") {
        return;
    }
    $skip = [
        "landing",
        "login",
        "logout",
        "password-request",
        "privacy-notice",
        "terms",
        "legal-ack",
        "set-language",
        "health",
        "toggle-user",
        "import-template",
        "mark-student",
        "run-ml",
        "student-modal",
    ];
    if (in_array($seg, $skip, true)) {
        return;
    }
    $_SESSION["login_intended_route"] = $seg;
}

function take_login_intended_route(): string
{
    $r = (string)($_SESSION["login_intended_route"] ?? "");
    unset($_SESSION["login_intended_route"]);
    if ($r === "" || strcspn($r, "/\\?#:\0") !== strlen($r)) {
        return "";
    }
    return $r;
}

function require_login(): void
{
    if (!current_user()) {
        stash_login_intended_route();
        $_SESSION["login_required_notice"] = true;
        header("Location: landing");
        exit;
    }
}

function require_role(array $allowedRoles): void
{
    $user = current_user();
    if (!$user) {
        stash_login_intended_route();
        $_SESSION["login_required_notice"] = true;
        header("Location: landing");
        exit;
    }
    if (!in_array($user["role"], $allowedRoles, true)) {
        audit_log(
            "access_denied",
            "failure",
            "route",
            null,
            "User attempted to access forbidden route.",
            ["allowed_roles" => $allowedRoles, "actual_role" => $user["role"] ?? "unknown"]
        );
        http_response_code(403);
        echo "Forbidden";
        exit;
    }
}

function login(string $username, string $password): bool
{
    start_app_session();
    set_login_error_message(null);

    $ip = (string)($_SERVER["REMOTE_ADDR"] ?? "");
    $wait = get_login_wait_seconds($username, $ip);
    if ($wait > 0) {
        set_login_error_message("Too many login attempts. Try again in {$wait} seconds.");
        audit_log("login_rate_limited", "failure", "auth", null, "Login attempt blocked by rate limiter.", [
            "username" => $username,
            "wait_seconds" => $wait,
        ]);
        return false;
    }

    $stmt = db()->prepare("SELECT user_id, username, password_hash, role, full_name, profile_photo_path, is_active FROM users WHERE username = ? LIMIT 1");
    $stmt->execute([$username]);
    $row = $stmt->fetch();

    if (!$row) {
        record_login_attempt($username, false);
        audit_log("login_attempt", "failure", "auth", null, "Unknown username login attempt.", ["username" => $username]);
        $waitAfterFailure = get_login_wait_seconds($username, $ip);
        if ($waitAfterFailure > 0) {
            set_login_error_message("Too many login attempts. Try again in {$waitAfterFailure} seconds.");
        } else {
            set_login_error_message("Invalid username or password.");
        }
        return false;
    }

    if (!password_verify($password, $row["password_hash"])) {
        record_login_attempt($username, false);
        audit_log("login_attempt", "failure", "auth", (int)$row["user_id"], "Invalid password.", ["username" => $username]);
        $waitAfterFailure = get_login_wait_seconds($username, $ip);
        if ($waitAfterFailure > 0) {
            set_login_error_message("Too many login attempts. Try again in {$waitAfterFailure} seconds.");
        } else {
            set_login_error_message("Invalid username or password.");
        }
        return false;
    }

    if ((int)($row["is_active"] ?? 1) !== 1) {
        record_login_attempt($username, false);
        audit_log("login_attempt", "failure", "auth", (int)$row["user_id"], "Inactive account login attempt.", ["username" => $username]);
        set_login_error_message("Account is inactive. Please contact admin.");
        return false;
    }

    record_login_attempt($username, true);
    session_regenerate_id(true);

    $_SESSION["user"] = [
        "user_id" => (int) $row["user_id"],
        "username" => $row["username"],
        "role" => $row["role"],
        "full_name" => db_field_decrypt((string)($row["full_name"] ?? "")),
        "profile_photo_path" => trim((string)($row["profile_photo_path"] ?? "")),
    ];
    $_SESSION["last_activity"] = time();
    audit_log("login_success", "success", "user", (int)$row["user_id"], "User login successful.", ["username" => $row["username"]]);

    return true;
}

function logout(): void
{
    start_app_session();
    $u = $_SESSION["user"] ?? null;
    if (is_array($u)) {
        audit_log("logout", "success", "user", (int)($u["user_id"] ?? 0), "User logout.");
    }
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            "",
            time() - 42000,
            $params["path"] ?? "/",
            $params["domain"] ?? "",
            (bool)($params["secure"] ?? false),
            (bool)($params["httponly"] ?? true)
        );
    }
    session_destroy();
}

/**
 * Writes an audit event. If table is missing or insert fails, it silently continues.
 */
function audit_log(
    string $action,
    string $status = "info",
    ?string $targetType = null,
    ?int $targetId = null,
    ?string $description = null,
    array $details = []
): void {
    try {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $sessionUser = $_SESSION["user"] ?? null;
        $userId = is_array($sessionUser) ? (int)($sessionUser["user_id"] ?? 0) : null;
        if ($userId !== null && $userId <= 0) {
            $userId = null;
        }

        $ip = (string)($_SERVER["REMOTE_ADDR"] ?? "");
        $ua = (string)($_SERVER["HTTP_USER_AGENT"] ?? "");
        $ua = substr($ua, 0, 255);
        $json = $details ? json_encode($details, JSON_UNESCAPED_UNICODE) : null;

        $descStore = $description !== null && $description !== "" ? db_field_encrypt($description) : $description;
        $jsonStore = $json !== null && $json !== "" ? db_field_encrypt($json) : $json;

        $stmt = db()->prepare(
            "INSERT INTO audit_logs
                (user_id, action, status, target_type, target_id, description, details_json, ip_address, user_agent)
             VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $userId,
            $action,
            $status,
            $targetType,
            $targetId,
            $descStore,
            $jsonStore,
            $ip !== "" ? $ip : null,
            $ua !== "" ? $ua : null,
        ]);
    } catch (Throwable $e) {
        // No-op by design: audit logging should not break app flow.
    }
}

