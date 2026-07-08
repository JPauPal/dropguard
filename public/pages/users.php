<?php
declare(strict_types=1);

require_once __DIR__ . "/../../app/auth.php";
require_once __DIR__ . "/../../app/db.php";
require_once __DIR__ . "/../../app/i18n.php";
require_once __DIR__ . "/../../app/password_change.php";
require_once __DIR__ . "/../../app/account_deactivation.php";
require_once __DIR__ . "/../../app/user_profiles.php";

require_login();
require_role(["Admin"]);
ensure_auth_security_table();
ensure_password_change_requests_table();
ensure_account_deactivation_requests_table();
ensure_users_profile_photo_column();

start_app_session();
$flash = $_SESSION["flash"] ?? null;
unset($_SESSION["flash"]);

$error = null;
$success = null;
$passwordUpdatedUserId = 0;

if (($_SERVER["REQUEST_METHOD"] ?? "GET") === "POST") {
    require_valid_csrf_token();
    $action = (string)($_POST["action"] ?? "create_user");

    if ($action === "approve_password_request" || $action === "reject_password_request") {
        $reqId = (int)($_POST["request_id"] ?? 0);
        if ($reqId <= 0) {
            $error = "Invalid password request.";
        } else {
            $pdo = db();
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare(
                    "SELECT request_id, user_id, new_password_hash, status
                     FROM password_change_requests
                     WHERE request_id = ? FOR UPDATE"
                );
                $stmt->execute([$reqId]);
                $req = $stmt->fetch();
                if (!$req) {
                    throw new RuntimeException("Request not found.");
                }
                if ((string)$req["status"] !== "Pending") {
                    throw new RuntimeException("Request already processed.");
                }

                $adminId = (int)(current_user()["user_id"] ?? 0);
                $newStatus = $action === "approve_password_request" ? "Approved" : "Rejected";
                $stmtUp = $pdo->prepare(
                    "UPDATE password_change_requests
                     SET status = ?, acted_at = NOW(), acted_by = ?
                     WHERE request_id = ?"
                );
                $stmtUp->execute([$newStatus, $adminId, $reqId]);

                if ($newStatus === "Approved") {
                    $stmtUserUp = $pdo->prepare("UPDATE users SET password_hash = ? WHERE user_id = ?");
                    $stmtUserUp->execute([(string)$req["new_password_hash"], (int)$req["user_id"]]);
                }

                $pdo->commit();
                audit_log("password_change_request_" . strtolower($newStatus), "success", "password_change_requests", $reqId, "Admin processed password change request.", [
                    "status" => $newStatus,
                    "target_user_id" => (int)$req["user_id"],
                ]);
                $success = $newStatus === "Approved" ? "Password request approved." : "Password request rejected.";
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error = $e->getMessage();
            }
        }
    } elseif ($action === "approve_deactivation_request" || $action === "reject_deactivation_request") {
        $reqId = (int)($_POST["request_id"] ?? 0);
        if ($reqId <= 0) {
            $error = "Invalid deactivation request.";
        } else {
            $pdo = db();
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare(
                    "SELECT request_id, user_id, status
                     FROM account_deactivation_requests
                     WHERE request_id = ? FOR UPDATE"
                );
                $stmt->execute([$reqId]);
                $req = $stmt->fetch();
                if (!$req) {
                    throw new RuntimeException("Request not found.");
                }
                if ((string)$req["status"] !== "Pending") {
                    throw new RuntimeException("Request already processed.");
                }
                $targetUserId = (int)$req["user_id"];
                $stmtU = $pdo->prepare("SELECT username, role, is_active FROM users WHERE user_id = ? LIMIT 1");
                $stmtU->execute([$targetUserId]);
                $tu = $stmtU->fetch();
                if (!$tu) {
                    throw new RuntimeException("User not found.");
                }
                if (!in_array((string)($tu["role"] ?? ""), ["Teacher", "Counselor"], true)) {
                    throw new RuntimeException("Only teacher/counselor accounts can be deactivated through this request.");
                }
                $adminId = (int)(current_user()["user_id"] ?? 0);
                $newStatus = $action === "approve_deactivation_request" ? "Approved" : "Rejected";
                $stmtUp = $pdo->prepare(
                    "UPDATE account_deactivation_requests
                     SET status = ?, acted_at = NOW(), acted_by = ?
                     WHERE request_id = ?"
                );
                $stmtUp->execute([$newStatus, $adminId, $reqId]);

                if ($newStatus === "Approved") {
                    $stmtDeact = $pdo->prepare("UPDATE users SET is_active = 0 WHERE user_id = ? AND role IN ('Teacher','Counselor')");
                    $stmtDeact->execute([$targetUserId]);
                }

                $pdo->commit();
                audit_log("account_deactivation_" . strtolower($newStatus), "success", "account_deactivation_requests", $reqId, "Admin processed account deactivation request.", [
                    "status" => $newStatus,
                    "target_user_id" => $targetUserId,
                ]);
                $success = $newStatus === "Approved" ? "Deactivation approved. The user account is now inactive." : "Deactivation request rejected.";
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = $e->getMessage();
            }
        }
    } elseif ($action === "update_password") {
        $userId = (int)($_POST["user_id"] ?? 0);
        $newPassword = (string)($_POST["new_password"] ?? "");
        $confirmPassword = (string)($_POST["confirm_password"] ?? "");

        if ($userId <= 0) {
            $error = "Invalid user selected for password update.";
        } else {
            $stmtUser = db()->prepare("SELECT username, role FROM users WHERE user_id = ? LIMIT 1");
            $stmtUser->execute([$userId]);
            $targetUser = $stmtUser->fetch();
            if (!$targetUser) {
                $error = "User not found.";
            } elseif ($newPassword === "" || $confirmPassword === "") {
                $error = "Please enter and confirm the new password.";
            } elseif ($newPassword !== $confirmPassword) {
                $error = "Passwords do not match.";
            } elseif (strlen($newPassword) < 8) {
                $error = "Password must be at least 8 characters.";
            } elseif (!preg_match('/[A-Z]/', $newPassword)) {
                $error = "Password must include at least 1 uppercase letter.";
            } elseif (!preg_match('/[a-z]/', $newPassword)) {
                $error = "Password must include at least 1 lowercase letter.";
            } elseif (!preg_match('/[0-9]/', $newPassword)) {
                $error = "Password must include at least 1 number.";
            } elseif (!preg_match('/[^a-zA-Z0-9]/', $newPassword)) {
                $error = "Password must include at least 1 special character.";
            } else {
                $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
                $stmtUpdate = db()->prepare("UPDATE users SET password_hash = ? WHERE user_id = ?");
                $stmtUpdate->execute([$newHash, $userId]);
                audit_log("user_password_update", "success", "user", $userId, "Admin updated a user password.", [
                    "username" => (string)$targetUser["username"],
                    "role" => (string)$targetUser["role"],
                ]);
                $passwordUpdatedUserId = $userId;
                $success = "Password updated for " . (string)$targetUser["username"] . ".";
            }
        }
    } elseif ($action === "delete_user") {
        $userId = (int)($_POST["user_id"] ?? 0);
        $selfId = (int)(current_user()["user_id"] ?? 0);
        $target = null;
        if ($userId <= 0) {
            $error = "Invalid user.";
        } elseif ($userId === $selfId) {
            $error = "You cannot delete your own account.";
        } else {
            $stmtT = db()->prepare("SELECT user_id, username, role FROM users WHERE user_id = ? LIMIT 1");
            $stmtT->execute([$userId]);
            $target = $stmtT->fetch();
            if (!$target) {
                $error = "User not found.";
            } elseif ((string)($target["role"] ?? "") === "Admin") {
                $adminCount = (int)(db()->query("SELECT COUNT(*) AS c FROM users WHERE role = 'Admin'")->fetch()["c"] ?? 0);
                if ($adminCount <= 1) {
                    $error = "Cannot delete the last Admin account.";
                }
            }
        }
        if ($error === null && is_array($target)) {
            try {
                db()->prepare("DELETE FROM users WHERE user_id = ?")->execute([$userId]);
                audit_log("user_delete", "success", "user", $userId, "Admin deleted user account.", [
                    "username" => (string)$target["username"],
                    "role" => (string)$target["role"],
                ]);
                start_app_session();
                $_SESSION["flash"] = "User @" . (string)$target["username"] . " was deleted.";
                header("Location: users");
                exit;
            } catch (Throwable $e) {
                $error = "Could not delete user. " . $e->getMessage();
            }
        }
    } elseif ($action === "create_user") {
        $username = strtolower(trim((string)($_POST["username"] ?? "")));
        $fullName = trim((string)($_POST["full_name"] ?? ""));
        $role = (string)($_POST["role"] ?? "Teacher");
        $password = (string)($_POST["password"] ?? "");
        $confirmPassword = (string)($_POST["confirm_password"] ?? "");
        $email = strtolower(trim((string)($_POST["email"] ?? "")));
        $contactNumber = trim((string)($_POST["contact_number"] ?? ""));

        $allowedRoles = ["Teacher", "Counselor", "Admin"];

        if ($username === "" || $fullName === "" || $password === "" || $confirmPassword === "") {
            $error = "Username, full name, password, and confirm password are required.";
        } elseif (!preg_match('/^[a-z0-9._-]{3,50}$/', $username)) {
            $error = "Username must be 3–50 chars and use letters/numbers/._-";
        } elseif (!in_array($role, $allowedRoles, true)) {
            $error = "Invalid role selected.";
        } elseif ($password !== $confirmPassword) {
            $error = "Password and confirm password do not match.";
        } elseif (strlen($password) < 8) {
            $error = "Password must be at least 8 characters.";
        } elseif (!preg_match('/[A-Z]/', $password)) {
            $error = "Password must include at least 1 uppercase letter.";
        } elseif (!preg_match('/[a-z]/', $password)) {
            $error = "Password must include at least 1 lowercase letter.";
        } elseif (!preg_match('/[0-9]/', $password)) {
            $error = "Password must include at least 1 number.";
        } elseif (!preg_match('/[^a-zA-Z0-9]/', $password)) {
            $error = "Password must include at least 1 special character.";
        } elseif ($email !== "" && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "Invalid email format.";
        } elseif ($contactNumber !== "" && !preg_match('/^[0-9+()\\-\\s]{7,30}$/', $contactNumber)) {
            $error = "Contact number may only include digits, spaces, +, -, and parentheses (7-30 chars).";
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = db()->prepare("INSERT INTO users (username, full_name, role, password_hash, email, contact_number) VALUES (?,?,?,?,?,?)");
            try {
                $stmt->execute([
                    $username,
                    db_field_encrypt($fullName),
                    $role,
                    $hash,
                    $email !== "" ? db_field_encrypt($email) : null,
                    $contactNumber !== "" ? db_field_encrypt($contactNumber) : null,
                ]);
                audit_log("user_create", "success", "user", (int)db()->lastInsertId(), "Admin created a new user.", [
                    "username" => $username,
                    "role" => $role,
                    "has_email" => $email !== "",
                    "has_contact_number" => $contactNumber !== "",
                ]);
                $success = "User created successfully.";
            } catch (Throwable $e) {
                audit_log("user_create", "failure", "user", null, "Failed to create user.", [
                    "username" => $username,
                    "role" => $role,
                    "error" => $e->getMessage(),
                ]);
                $error = "Could not create user. (Username may already exist.)";
            }
        }
    } else {
        $error = "Unknown action.";
    }
}

$pendingPwRequests = db_decrypt_user_pii_rows(db()->query(
    "SELECT p.request_id, p.user_id, p.status, p.requested_at, u.username, u.full_name, u.role
     FROM password_change_requests p
     INNER JOIN users u ON u.user_id = p.user_id
     WHERE p.status = 'Pending'
     ORDER BY p.requested_at ASC
     LIMIT 50"
)->fetchAll());

$pendingDeactivationRequests = db_decrypt_user_pii_rows(db()->query(
    "SELECT d.request_id, d.user_id, d.reason, d.status, d.requested_at, u.username, u.full_name, u.role
     FROM account_deactivation_requests d
     INNER JOIN users u ON u.user_id = d.user_id
     WHERE d.status = 'Pending'
     ORDER BY d.requested_at ASC
     LIMIT 50"
)->fetchAll());

$users = db_decrypt_user_pii_rows(db()->query("SELECT user_id, username, full_name, role, email, contact_number, profile_photo_path, is_active, created_at FROM users ORDER BY user_id DESC")->fetchAll());

$filterRole = trim((string)($_GET["role"] ?? ""));
$filterStatus = trim((string)($_GET["status"] ?? ""));
$filterSearch = trim((string)($_GET["q"] ?? ""));
$allowedFilterRoles = ["Teacher", "Counselor", "Admin"];
if ($filterRole !== "" && !in_array($filterRole, $allowedFilterRoles, true)) {
    $filterRole = "";
}
if (!in_array($filterStatus, ["", "active", "inactive"], true)) {
    $filterStatus = "";
}

$totalUsers = count($users);
$activeCount = 0;
foreach ($users as $uStat) {
    if ((int)($uStat["is_active"] ?? 1) === 1) {
        $activeCount++;
    }
}
$inactiveCount = $totalUsers - $activeCount;
$pendingPwCount = count($pendingPwRequests);
$pendingDeactCount = count($pendingDeactivationRequests);
$pendingCount = $pendingPwCount + $pendingDeactCount;
$selfId = (int)(current_user()["user_id"] ?? 0);

$usersFiltered = array_values(array_filter($users, static function (array $u) use ($filterRole, $filterStatus, $filterSearch): bool {
    if ($filterRole !== "" && (string)($u["role"] ?? "") !== $filterRole) {
        return false;
    }
    $isActive = (int)($u["is_active"] ?? 1) === 1;
    if ($filterStatus === "active" && !$isActive) {
        return false;
    }
    if ($filterStatus === "inactive" && $isActive) {
        return false;
    }
    if ($filterSearch !== "") {
        $hay = strtolower(
            (string)($u["username"] ?? "") . " "
            . (string)($u["full_name"] ?? "") . " "
            . (string)($u["email"] ?? "") . " "
            . (string)($u["contact_number"] ?? "")
        );
        if (!str_contains($hay, strtolower($filterSearch))) {
            return false;
        }
    }
    return true;
}));

ob_start();
?>
<div class="dg-page-header mb-3">
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
    <div>
      <h3 class="mb-0"><?= h_t("layout.nav.users") ?></h3>
      <div class="text-muted small"><?= h_t("users.subtitle") ?></div>
    </div>
  </div>
</div>

<?php if ($flash): ?>
  <div class="alert alert-info dg-users-alert"><?= htmlspecialchars((string)$flash, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></div>
<?php endif; ?>
<?php if ($error): ?>
  <div class="alert alert-danger dg-users-alert"><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></div>
<?php endif; ?>
<?php if ($success): ?>
  <div class="alert alert-success dg-users-alert"><?= htmlspecialchars($success, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></div>
<?php endif; ?>

<div class="row g-3 mb-3 dg-users-kpi-row">
  <div class="col-6 col-lg-3">
    <div class="dg-kpi dg-kpi--total p-3 h-100">
      <div class="dg-kpi-label"><span class="dg-kpi-dot"></span><?= h_t("users.kpi_total") ?></div>
      <div class="dg-kpi-value"><?= (int)$totalUsers ?></div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="dg-kpi dg-kpi--low p-3 h-100">
      <div class="dg-kpi-label"><span class="dg-kpi-dot"></span><?= h_t("users.kpi_active") ?></div>
      <div class="dg-kpi-value"><?= (int)$activeCount ?></div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="dg-kpi p-3 h-100">
      <div class="dg-kpi-label"><span class="dg-kpi-dot"></span><?= h_t("users.kpi_inactive") ?></div>
      <div class="dg-kpi-value"><?= (int)$inactiveCount ?></div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="dg-kpi dg-kpi--mod p-3 h-100">
      <div class="dg-kpi-label"><span class="dg-kpi-dot"></span><?= h_t("users.kpi_pending") ?></div>
      <div class="dg-kpi-value"><?= (int)$pendingCount ?></div>
    </div>
  </div>
</div>

<?php if ($pendingCount > 0): ?>
  <div class="card shadow-sm mb-3 dg-users-queue">
    <div class="card-body">
      <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div>
          <div class="dg-users-section-title mb-0"><?= h_t("users.queue_title") ?></div>
          <div class="text-muted small"><?= h_t("users.queue_hint") ?></div>
        </div>
        <span class="badge rounded-pill text-bg-warning"><?= (int)$pendingCount ?> <?= h_t("users.queue_pending_badge") ?></span>
      </div>

      <?php if ($pendingPwCount > 0): ?>
        <div class="dg-users-queue-block mb-3">
          <div class="fw-semibold small text-uppercase text-muted mb-2"><?= h_t("users.queue_pw_title") ?></div>
          <div class="table-responsive">
            <table class="table table-sm align-middle dg-table mb-0">
              <thead>
                <tr>
                  <th><?= h_t("users.th_user") ?></th>
                  <th><?= h_t("users.th_role") ?></th>
                  <th><?= h_t("common.date") ?></th>
                  <th class="text-end"><?= h_t("users.th_actions") ?></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($pendingPwRequests as $r): ?>
                  <tr>
                    <td>
                      <div class="dg-users-user-cell">
                        <?php
                          $staffPhotoUrl = "";
                          $staffAvatarClass = "dg-staff-profile-photo";
                          $staffAvatarAlt = (string)($r["full_name"] ?? $r["username"]);
                          require __DIR__ . "/partials/staff_profile_avatar.php";
                        ?>
                        <div class="min-w-0">
                          <div class="fw-semibold text-truncate"><?= htmlspecialchars((string)($r["full_name"] ?? $r["username"]), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></div>
                          <div class="text-muted small">@<?= htmlspecialchars((string)$r["username"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></div>
                        </div>
                      </div>
                    </td>
                    <td><?php $staffRole = (string)$r["role"]; require __DIR__ . "/partials/staff_role_badge.php"; ?></td>
                    <td class="small text-muted"><?= htmlspecialchars((string)$r["requested_at"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></td>
                    <td class="text-end">
                      <div class="d-flex flex-wrap justify-content-end gap-1">
                        <form class="d-inline" method="post" action="users" onsubmit="return confirm(<?= json_encode(t("users.confirm_approve_pw"), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>);">
                          <?= csrf_field() ?>
                          <input type="hidden" name="action" value="approve_password_request" />
                          <input type="hidden" name="request_id" value="<?= (int)$r["request_id"] ?>" />
                          <button class="btn btn-sm btn-success" type="submit"><?= h_t("users.approve") ?></button>
                        </form>
                        <form class="d-inline" method="post" action="users" onsubmit="return confirm(<?= json_encode(t("users.confirm_reject_pw"), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>);">
                          <?= csrf_field() ?>
                          <input type="hidden" name="action" value="reject_password_request" />
                          <input type="hidden" name="request_id" value="<?= (int)$r["request_id"] ?>" />
                          <button class="btn btn-sm btn-outline-danger" type="submit"><?= h_t("users.reject") ?></button>
                        </form>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      <?php endif; ?>

      <?php if ($pendingDeactCount > 0): ?>
        <div class="dg-users-queue-block">
          <div class="fw-semibold small text-uppercase text-muted mb-2"><?= h_t("users.queue_deact_title") ?></div>
          <div class="table-responsive">
            <table class="table table-sm align-middle dg-table mb-0">
              <thead>
                <tr>
                  <th><?= h_t("users.th_user") ?></th>
                  <th><?= h_t("users.th_role") ?></th>
                  <th><?= h_t("common.reason") ?></th>
                  <th><?= h_t("common.date") ?></th>
                  <th class="text-end"><?= h_t("users.th_actions") ?></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($pendingDeactivationRequests as $r): ?>
                  <tr>
                    <td>
                      <div class="dg-users-user-cell">
                        <?php
                          $staffPhotoUrl = "";
                          $staffAvatarClass = "dg-staff-profile-photo";
                          $staffAvatarAlt = (string)($r["full_name"] ?? $r["username"]);
                          require __DIR__ . "/partials/staff_profile_avatar.php";
                        ?>
                        <div class="min-w-0">
                          <div class="fw-semibold text-truncate"><?= htmlspecialchars((string)($r["full_name"] ?? $r["username"]), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></div>
                          <div class="text-muted small">@<?= htmlspecialchars((string)$r["username"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></div>
                        </div>
                      </div>
                    </td>
                    <td><?php $staffRole = (string)$r["role"]; require __DIR__ . "/partials/staff_role_badge.php"; ?></td>
                    <td class="small"><?= htmlspecialchars((string)($r["reason"] ?? h_t("common.em_dash")), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></td>
                    <td class="small text-muted"><?= htmlspecialchars((string)$r["requested_at"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></td>
                    <td class="text-end">
                      <div class="d-flex flex-wrap justify-content-end gap-1">
                        <form class="d-inline" method="post" action="users" onsubmit="return confirm(<?= json_encode(t("users.confirm_approve_deact"), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>);">
                          <?= csrf_field() ?>
                          <input type="hidden" name="action" value="approve_deactivation_request" />
                          <input type="hidden" name="request_id" value="<?= (int)$r["request_id"] ?>" />
                          <button class="btn btn-sm btn-success" type="submit"><?= h_t("users.approve") ?></button>
                        </form>
                        <form class="d-inline" method="post" action="users" onsubmit="return confirm(<?= json_encode(t("users.confirm_reject_deact"), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>);">
                          <?= csrf_field() ?>
                          <input type="hidden" name="action" value="reject_deactivation_request" />
                          <input type="hidden" name="request_id" value="<?= (int)$r["request_id"] ?>" />
                          <button class="btn btn-sm btn-outline-danger" type="submit"><?= h_t("users.reject") ?></button>
                        </form>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-xl-4">
    <div class="card shadow-sm dg-users-create-card h-100">
      <div class="card-body">
        <div class="dg-users-section-title"><?= h_t("users.create_title") ?></div>
        <p class="text-muted small mb-3"><?= h_t("users.create_hint") ?></p>
        <form method="post" action="users" class="dg-users-create-form">
          <input type="hidden" name="action" value="create_user" />
          <?= csrf_field() ?>
          <div class="mb-2">
            <label class="form-label"><?= h_t("users.field_username") ?></label>
            <input class="form-control form-control-sm" name="username" placeholder="<?= h_t("users.field_username_ph") ?>" required />
          </div>
          <div class="mb-2">
            <label class="form-label"><?= h_t("users.field_full_name") ?></label>
            <input class="form-control form-control-sm" name="full_name" placeholder="<?= h_t("users.field_full_name_ph") ?>" required />
          </div>
          <div class="mb-2">
            <label class="form-label"><?= h_t("users.field_role") ?></label>
            <select class="form-select form-select-sm" name="role" required>
              <option value="Teacher"><?= htmlspecialchars(i18n_role_label("Teacher"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></option>
              <option value="Counselor"><?= htmlspecialchars(i18n_role_label("Counselor"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></option>
              <option value="Admin"><?= htmlspecialchars(i18n_role_label("Admin"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></option>
            </select>
          </div>
          <div class="mb-2">
            <label class="form-label"><?= h_t("users.field_email") ?></label>
            <input class="form-control form-control-sm" type="email" name="email" placeholder="<?= h_t("users.field_email_ph") ?>" />
          </div>
          <div class="mb-2">
            <label class="form-label"><?= h_t("users.field_contact") ?></label>
            <input class="form-control form-control-sm" name="contact_number" placeholder="<?= h_t("users.field_contact_ph") ?>" />
          </div>
          <div class="mb-2">
            <label class="form-label"><?= h_t("users.field_password") ?></label>
            <input
              class="form-control form-control-sm"
              type="password"
              id="dgCreateUserPassword"
              name="password"
              minlength="8"
              pattern="(?=.*[a-z])(?=.*[A-Z])(?=.*[0-9])(?=.*[^A-Za-z0-9]).{8,}"
              title="<?= h_t("users.password_rules_hint") ?>"
              required
            />
            <div class="small text-muted mt-1"><?= h_t("users.password_rules_hint") ?></div>
            <ul class="list-unstyled small mt-2 mb-0" id="dgCreateUserPasswordRules">
              <li class="d-flex align-items-center gap-2" data-rule="len"><span class="dg-rule-ico text-danger">✗</span><span><?= h_t("users.rule_len") ?></span></li>
              <li class="d-flex align-items-center gap-2" data-rule="upper"><span class="dg-rule-ico text-danger">✗</span><span><?= h_t("users.rule_upper") ?></span></li>
              <li class="d-flex align-items-center gap-2" data-rule="lower"><span class="dg-rule-ico text-danger">✗</span><span><?= h_t("users.rule_lower") ?></span></li>
              <li class="d-flex align-items-center gap-2" data-rule="num"><span class="dg-rule-ico text-danger">✗</span><span><?= h_t("users.rule_num") ?></span></li>
              <li class="d-flex align-items-center gap-2" data-rule="spec"><span class="dg-rule-ico text-danger">✗</span><span><?= h_t("users.rule_spec") ?></span></li>
            </ul>
          </div>
          <div class="mb-3">
            <label class="form-label"><?= h_t("users.field_confirm_password") ?></label>
            <input
              class="form-control form-control-sm"
              type="password"
              id="dgCreateUserConfirmPassword"
              name="confirm_password"
              minlength="8"
              required
            />
            <ul class="list-unstyled small mt-2 mb-0" id="dgCreateUserConfirmRules">
              <li class="d-flex align-items-center gap-2" data-rule="match"><span class="dg-rule-ico text-danger">✗</span><span><?= h_t("users.rule_match") ?></span></li>
              <li class="d-flex align-items-center gap-2" data-rule="len"><span class="dg-rule-ico text-danger">✗</span><span><?= h_t("users.rule_len") ?></span></li>
              <li class="d-flex align-items-center gap-2" data-rule="upper"><span class="dg-rule-ico text-danger">✗</span><span><?= h_t("users.rule_upper") ?></span></li>
              <li class="d-flex align-items-center gap-2" data-rule="lower"><span class="dg-rule-ico text-danger">✗</span><span><?= h_t("users.rule_lower") ?></span></li>
              <li class="d-flex align-items-center gap-2" data-rule="num"><span class="dg-rule-ico text-danger">✗</span><span><?= h_t("users.rule_num") ?></span></li>
              <li class="d-flex align-items-center gap-2" data-rule="spec"><span class="dg-rule-ico text-danger">✗</span><span><?= h_t("users.rule_spec") ?></span></li>
            </ul>
          </div>
          <button class="btn btn-dark w-100" type="submit"><?= h_t("users.create_btn") ?></button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-xl-8">
    <div class="card shadow-sm dg-users-directory h-100">
      <div class="card-body">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
          <div>
            <div class="dg-users-section-title mb-0"><?= h_t("users.directory_title") ?></div>
            <div class="text-muted small"><?= h_t("users.directory_hint") ?></div>
          </div>
          <div class="text-muted small">
            <?= htmlspecialchars(
                $filterRole !== "" || $filterStatus !== "" || $filterSearch !== ""
                    ? tr("users.count_filtered", ["count" => (string)count($usersFiltered)])
                    : tr("users.count_total", ["count" => (string)$totalUsers]),
                ENT_QUOTES | ENT_SUBSTITUTE,
                "UTF-8"
            ) ?>
          </div>
        </div>

        <form class="row g-2 align-items-end mb-3" method="get" action="users">
          <div class="col-md-4">
            <label class="form-label small mb-1"><?= h_t("common.search") ?></label>
            <input class="form-control form-control-sm" name="q" value="<?= htmlspecialchars($filterSearch, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" placeholder="<?= h_t("users.filter_search_ph") ?>" />
          </div>
          <div class="col-md-3">
            <label class="form-label small mb-1"><?= h_t("users.filter_role") ?></label>
            <select class="form-select form-select-sm" name="role">
              <option value=""><?= h_t("common.all") ?></option>
              <?php foreach ($allowedFilterRoles as $rOpt): ?>
                <option value="<?= htmlspecialchars($rOpt, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" <?= $filterRole === $rOpt ? "selected" : "" ?>>
                  <?= htmlspecialchars(i18n_role_label($rOpt), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-3">
            <label class="form-label small mb-1"><?= h_t("common.status") ?></label>
            <select class="form-select form-select-sm" name="status">
              <option value=""><?= h_t("common.all") ?></option>
              <option value="active" <?= $filterStatus === "active" ? "selected" : "" ?>><?= h_t("users.status_active") ?></option>
              <option value="inactive" <?= $filterStatus === "inactive" ? "selected" : "" ?>><?= h_t("users.status_inactive") ?></option>
            </select>
          </div>
          <div class="col-md-2">
            <button class="btn btn-sm btn-dark w-100" type="submit"><?= h_t("common.apply") ?></button>
          </div>
        </form>

        <?php if (!$users): ?>
          <div class="dg-users-empty text-muted"><?= h_t("users.empty_all") ?></div>
        <?php elseif (!$usersFiltered): ?>
          <div class="dg-users-empty text-muted"><?= h_t("users.empty_filter") ?></div>
        <?php else: ?>
          <div class="table-responsive dg-table-scroll">
            <table class="table table-sm align-middle dg-table mb-0">
              <thead>
                <tr>
                  <th><?= h_t("users.th_user") ?></th>
                  <th><?= h_t("users.th_role") ?></th>
                  <th><?= h_t("users.th_contact") ?></th>
                  <th><?= h_t("common.status") ?></th>
                  <th><?= h_t("users.th_joined") ?></th>
                  <th class="text-end"><?= h_t("users.th_actions") ?></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($usersFiltered as $u): ?>
                  <?php
                    $uid = (int)$u["user_id"];
                    $isActive = (int)($u["is_active"] ?? 1) === 1;
                    $isSelf = $uid === $selfId;
                    $rowClass = $uid === $passwordUpdatedUserId ? "table-success" : "";
                  ?>
                  <tr class="<?= htmlspecialchars($rowClass, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>">
                    <td>
                      <div class="dg-users-user-cell">
                        <?php
                          $staffPhotoUrl = trim((string)($u["profile_photo_path"] ?? ""));
                          $staffAvatarClass = "dg-staff-profile-photo";
                          $staffAvatarAlt = (string)($u["full_name"] ?? $u["username"]);
                          require __DIR__ . "/partials/staff_profile_avatar.php";
                        ?>
                        <div class="min-w-0">
                          <div class="fw-semibold text-truncate"><?= htmlspecialchars((string)$u["full_name"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></div>
                          <div class="text-muted small">@<?= htmlspecialchars((string)$u["username"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></div>
                          <?php if ($isSelf): ?>
                            <span class="badge rounded-pill dg-users-you-badge"><?= h_t("users.current_account") ?></span>
                          <?php endif; ?>
                        </div>
                      </div>
                    </td>
                    <td><?php $staffRole = (string)$u["role"]; require __DIR__ . "/partials/staff_role_badge.php"; ?></td>
                    <td class="small">
                      <?php if ((string)($u["email"] ?? "") !== ""): ?>
                        <div class="text-truncate" style="max-width: 180px"><?= htmlspecialchars((string)$u["email"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></div>
                      <?php endif; ?>
                      <?php if ((string)($u["contact_number"] ?? "") !== ""): ?>
                        <div class="text-muted"><?= htmlspecialchars((string)$u["contact_number"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></div>
                      <?php endif; ?>
                      <?php if ((string)($u["email"] ?? "") === "" && (string)($u["contact_number"] ?? "") === ""): ?>
                        <span class="text-muted"><?= h_t("common.em_dash") ?></span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <?php if ($isActive): ?>
                        <span class="dg-users-status dg-users-status--active"><?= h_t("users.status_active") ?></span>
                      <?php else: ?>
                        <span class="dg-users-status dg-users-status--inactive"><?= h_t("users.status_inactive") ?></span>
                      <?php endif; ?>
                    </td>
                    <td class="text-muted small"><?= htmlspecialchars((string)$u["created_at"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></td>
                    <td class="text-end">
                      <div class="dropdown dg-users-actions">
                        <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                          <?= h_t("users.manage") ?>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                          <?php if ((string)($u["role"] ?? "") === "Teacher"): ?>
                            <li>
                              <a class="dropdown-item" href="schedules?mode=teacher&teacher_user_id=<?= $uid ?>"><?= h_t("users.action_schedule") ?></a>
                            </li>
                            <li><hr class="dropdown-divider" /></li>
                          <?php endif; ?>
                          <?php if (!$isSelf): ?>
                            <li>
                              <form method="post" action="toggle-user">
                                <?= csrf_field() ?>
                                <input type="hidden" name="user_id" value="<?= $uid ?>" />
                                <?php if ($isActive): ?>
                                  <input type="hidden" name="activate" value="0" />
                                  <button class="dropdown-item text-warning" type="submit"><?= h_t("users.action_deactivate") ?></button>
                                <?php else: ?>
                                  <input type="hidden" name="activate" value="1" />
                                  <button class="dropdown-item text-success" type="submit"><?= h_t("users.action_activate") ?></button>
                                <?php endif; ?>
                              </form>
                            </li>
                          <?php endif; ?>
                          <li>
                            <button
                              class="dropdown-item"
                              type="button"
                              data-bs-toggle="modal"
                              data-bs-target="#dgUsersPasswordModal"
                              data-user-id="<?= $uid ?>"
                              data-username="<?= htmlspecialchars((string)$u["username"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>"
                            ><?= h_t("users.action_reset_password") ?></button>
                          </li>
                          <?php if (!$isSelf): ?>
                            <li><hr class="dropdown-divider" /></li>
                            <li>
                              <form method="post" action="users" onsubmit="return confirm(<?= json_encode(t("users.confirm_delete"), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>);">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete_user" />
                                <input type="hidden" name="user_id" value="<?= $uid ?>" />
                                <button class="dropdown-item text-danger" type="submit"><?= h_t("users.action_delete") ?></button>
                              </form>
                            </li>
                          <?php endif; ?>
                        </ul>
                      </div>
                      <?php if ($uid === $passwordUpdatedUserId): ?>
                        <div class="text-success small fw-semibold mt-1"><?= h_t("users.password_updated") ?></div>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="dgUsersPasswordModal" tabindex="-1" aria-labelledby="dgUsersPasswordModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form method="post" action="users">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update_password" />
        <input type="hidden" name="user_id" id="dgUsersPasswordUserId" value="" />
        <div class="modal-header">
          <h5 class="modal-title" id="dgUsersPasswordModalLabel"><?= h_t("users.modal_reset_title") ?></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <p class="text-muted small mb-3" id="dgUsersPasswordModalUser">@username</p>
          <div class="mb-2">
            <label class="form-label"><?= h_t("users.field_password") ?></label>
            <input
              class="form-control"
              type="password"
              name="new_password"
              minlength="8"
              pattern="(?=.*[a-z])(?=.*[A-Z])(?=.*[0-9])(?=.*[^A-Za-z0-9]).{8,}"
              required
            />
          </div>
          <div class="mb-0">
            <label class="form-label"><?= h_t("users.field_confirm_password") ?></label>
            <input class="form-control" type="password" name="confirm_password" minlength="8" required />
          </div>
          <div class="small text-muted mt-2"><?= h_t("users.password_rules_hint") ?></div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?= h_t("common.cancel") ?></button>
          <button type="submit" class="btn btn-dark"><?= h_t("users.action_reset_password") ?></button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
  (function () {
    function evalRules(pw) {
      var s = String(pw || "");
      return {
        len: s.length >= 8,
        upper: /[A-Z]/.test(s),
        lower: /[a-z]/.test(s),
        num: /[0-9]/.test(s),
        spec: /[^a-zA-Z0-9]/.test(s)
      };
    }

    function applyRules(root, rules, extra) {
      if (!root) return;
      root.querySelectorAll("[data-rule]").forEach(function (li) {
        var key = li.getAttribute("data-rule") || "";
        var ok = false;
        if (key === "match" && extra && typeof extra.match === "boolean") ok = extra.match;
        else ok = !!(rules && rules[key]);

        var ico = li.querySelector(".dg-rule-ico");
        if (ico) {
          ico.textContent = ok ? "✓" : "✗";
          ico.classList.toggle("text-success", ok);
          ico.classList.toggle("text-danger", !ok);
        }
      });
    }

    var pwEl = document.getElementById("dgCreateUserPassword");
    var cfEl = document.getElementById("dgCreateUserConfirmPassword");
    var pwRulesRoot = document.getElementById("dgCreateUserPasswordRules");
    var cfRulesRoot = document.getElementById("dgCreateUserConfirmRules");

    function sync() {
      var pw = pwEl ? pwEl.value : "";
      var cf = cfEl ? cfEl.value : "";
      applyRules(pwRulesRoot, evalRules(pw), null);
      applyRules(cfRulesRoot, evalRules(cf), { match: cf !== "" && pw !== "" && cf === pw });
    }

    if (pwEl) pwEl.addEventListener("input", sync);
    if (cfEl) cfEl.addEventListener("input", sync);
    sync();

    var modal = document.getElementById("dgUsersPasswordModal");
    if (modal) {
      modal.addEventListener("show.bs.modal", function (ev) {
        var btn = ev.relatedTarget;
        if (!btn) return;
        var uid = btn.getAttribute("data-user-id") || "";
        var uname = btn.getAttribute("data-username") || "";
        var uidEl = document.getElementById("dgUsersPasswordUserId");
        var userEl = document.getElementById("dgUsersPasswordModalUser");
        if (uidEl) uidEl.value = uid;
        if (userEl) userEl.textContent = <?= json_encode(t("users.modal_reset_for"), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>.replace(":username", uname);
      });
    }
  })();
</script>
<?php
$content = ob_get_clean();
require __DIR__ . "/_layout.php";

