<?php
declare(strict_types=1);

require_once __DIR__ . "/../../app/auth.php";
require_once __DIR__ . "/../../app/db.php";
require_once __DIR__ . "/../../app/password_change.php";
require_once __DIR__ . "/../../app/workflow.php";

require_login();
require_role(["Teacher", "Counselor", "Admin"]);
ensure_workflow_tables();
ensure_password_change_requests_table();

$user = current_user();
$userId = (int)($user["user_id"] ?? 0);

if (($_SERVER["REQUEST_METHOD"] ?? "GET") === "GET" && in_array((string)($user["role"] ?? ""), ["Teacher", "Counselor", "Admin"], true)) {
    header("Location: my-profile#password");
    exit;
}

start_app_session();
$flash = $_SESSION["flash"] ?? null;
unset($_SESSION["flash"]);
$error = null;
$success = null;

// Latest request
$latest = null;
try {
    $stmt = db()->prepare("SELECT request_id, status, requested_at, acted_at FROM password_change_requests WHERE user_id = ? ORDER BY request_id DESC LIMIT 1");
    $stmt->execute([$userId]);
    $latest = $stmt->fetch() ?: null;
} catch (Throwable) {
    $latest = null;
}

if (($_SERVER["REQUEST_METHOD"] ?? "GET") === "POST") {
    require_valid_csrf_token();
    $newPassword = (string)($_POST["new_password"] ?? "");
    $confirmPassword = (string)($_POST["confirm_password"] ?? "");

    $pwErr = password_change_validate_new_password_pair($newPassword, $confirmPassword);
    if ($pwErr !== null) {
        $error = $pwErr;
    } else {
        password_change_insert_pending_request($userId, $newPassword);
        audit_log("password_change_request", "success", "password_change_requests", (int)db()->lastInsertId(), "User requested password change.");
        $success = "Password change request submitted. Please wait for Admin approval.";
        // reload latest for display
        $stmt2 = db()->prepare("SELECT request_id, status, requested_at, acted_at FROM password_change_requests WHERE user_id = ? ORDER BY request_id DESC LIMIT 1");
        $stmt2->execute([$userId]);
        $latest = $stmt2->fetch() ?: null;
    }
}

ob_start();
?>
<div class="dg-page-header mb-3">
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
    <div>
      <h3 class="mb-0">Password change</h3>
      <div class="text-muted small">Submit a request. Admin must approve before it takes effect.</div>
    </div>
  </div>
</div>

<?php if ($flash): ?>
  <div class="alert alert-info"><?= htmlspecialchars((string)$flash, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></div>
<?php endif; ?>
<?php if ($success): ?>
  <div class="alert alert-success"><?= htmlspecialchars((string)$success, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></div>
<?php endif; ?>
<?php if ($error): ?>
  <div class="alert alert-danger"><?= htmlspecialchars((string)$error, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></div>
<?php endif; ?>

<?php if ($latest): ?>
  <div class="card shadow-sm mb-3">
    <div class="card-body">
      <div class="fw-semibold mb-1">Latest request</div>
      <div class="small text-muted">
        Status: <span class="fw-semibold"><?= htmlspecialchars((string)$latest["status"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></span>
        • Requested: <?= htmlspecialchars((string)$latest["requested_at"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>
        <?php if (!empty($latest["acted_at"])): ?>
          • Acted: <?= htmlspecialchars((string)$latest["acted_at"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
<?php endif; ?>

<div class="card shadow-sm">
  <div class="card-body">
    <div class="fw-semibold mb-2">Request a new password</div>
    <form method="post" action="password-request" class="row g-2">
      <?= csrf_field() ?>
      <div class="col-md-6">
        <label class="form-label">New password</label>
        <input class="form-control" type="password" name="new_password" minlength="8" required />
      </div>
      <div class="col-md-6">
        <label class="form-label">Confirm password</label>
        <input class="form-control" type="password" name="confirm_password" minlength="8" required />
      </div>
      <div class="col-12">
        <button class="btn btn-dark" type="submit">Submit request</button>
      </div>
    </form>
    <div class="small text-muted mt-2">Must include uppercase, lowercase, number, and special character.</div>
  </div>
</div>

<?php
$content = ob_get_clean();
require __DIR__ . "/_layout.php";

