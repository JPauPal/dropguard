<?php
declare(strict_types=1);

require_once __DIR__ . "/../app/env.php";
dropguard_load_env();
$config = require __DIR__ . "/../app/config.php";
dropguard_apply_runtime_config($config);

require_once __DIR__ . "/../app/auth.php";
require_once __DIR__ . "/../app/url.php";
require_once __DIR__ . "/../app/i18n.php";

start_app_session();
i18n_bootstrap();

// Baseline security headers for all routed pages.
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Permissions-Policy: camera=(), microphone=(), geolocation=()");

$path = app_normalize_request_path();
if ($path === "") {
    $u = current_user();
    if (!$u) {
        $path = "landing";
    } else {
        $role = (string)($u["role"] ?? "");
        if ($role === "Teacher") {
            $path = "data-entry";
        } else {
            $path = "dashboard";
        }
    }
}

switch ($path) {
    case "landing":
        require __DIR__ . "/pages/landing.php";
        break;
    case "login":
        require __DIR__ . "/pages/login.php";
        break;
    case "my-profile":
        require __DIR__ . "/pages/my_profile.php";
        break;
    case "privacy-notice":
        require __DIR__ . "/pages/privacy_notice.php";
        break;
    case "terms":
        require __DIR__ . "/pages/terms.php";
        break;
    case "legal-ack":
        require __DIR__ . "/pages/legal_ack.php";
        break;
    case "set-language":
        require __DIR__ . "/pages/set_language.php";
        break;
    case "health":
        require __DIR__ . "/pages/health.php";
        break;
    case "users":
        require __DIR__ . "/pages/users.php";
        break;
    case "password-request":
        require __DIR__ . "/pages/password_request.php";
        break;
    case "toggle-user":
        require __DIR__ . "/pages/toggle_user.php";
        break;
    case "settings":
        require __DIR__ . "/pages/settings.php";
        break;
    case "audit-logs":
        require __DIR__ . "/pages/audit_logs.php";
        break;
    case "manage-sections":
        require __DIR__ . "/pages/manage_sections.php";
        break;
    case "manage-subjects":
        require __DIR__ . "/pages/manage_subjects.php";
        break;
    case "teacher-overview":
        require __DIR__ . "/pages/teacher_overview.php";
        break;
    case "student-sheets":
        require __DIR__ . "/pages/student_sheets.php";
        break;
    case "attendance-sheet":
        // Backwards-compatible alias
        header("Location: student-sheets" . (!empty($_SERVER["QUERY_STRING"]) ? ("?" . $_SERVER["QUERY_STRING"]) : ""));
        exit;
    case "schedules":
        require __DIR__ . "/pages/schedules.php";
        break;
    case "teacher-schedule":
        header("Location: schedules?mode=teacher");
        exit;
    case "section-schedule":
        $qs = $_SERVER["QUERY_STRING"] ?? "";
        $suffix = $qs !== "" ? ("&" . $qs) : "";
        header("Location: schedules?mode=section" . $suffix);
        exit;
    case "teacher-referral":
        require __DIR__ . "/pages/teacher_referral.php";
        break;
    case "update-case":
        require __DIR__ . "/pages/update_case.php";
        break;
    case "reports":
        require __DIR__ . "/pages/reports.php";
        break;
    case "data-health":
        require __DIR__ . "/pages/data_health.php";
        break;
    case "student-report":
        require __DIR__ . "/pages/student_report.php";
        break;
    case "logout":
        require __DIR__ . "/pages/logout.php";
        break;
    case "dashboard":
        require __DIR__ . "/pages/dashboard.php";
        break;
    case "data-entry":
        require __DIR__ . "/pages/data_entry.php";
        break;
    case "run-ml":
        require __DIR__ . "/pages/run_ml.php";
        break;
    case "student":
        require __DIR__ . "/pages/student_profile.php";
        break;
    case "student-list":
        require __DIR__ . "/pages/student_list.php";
        break;
    case "student-modal":
        require __DIR__ . "/pages/student_modal.php";
        break;
    case "mark-student":
        require __DIR__ . "/pages/mark_student.php";
        break;
    case "import":
        require __DIR__ . "/pages/import.php";
        break;
    case "import-template":
        require __DIR__ . "/pages/import_template.php";
        break;
    default:
        http_response_code(404);
        echo "Not Found";
        break;
}

