<?php
declare(strict_types=1);

require_once __DIR__ . "/../../app/auth.php";
require_once __DIR__ . "/../../app/db.php";
require_once __DIR__ . "/../../app/i18n.php";
require_once __DIR__ . "/../../app/password_change.php";
require_once __DIR__ . "/../../app/account_deactivation.php";
require_once __DIR__ . "/../../app/user_profiles.php";

require_login();
require_role(["Teacher", "Counselor", "Admin"]);
ensure_users_profile_photo_column();
ensure_password_change_requests_table();
ensure_account_deactivation_requests_table();

$user = current_user();
$userId = (int)($user["user_id"] ?? 0);
$username = (string)($user["username"] ?? "");

$stmt = db()->prepare("SELECT user_id, username, full_name, role, email, contact_number, profile_photo_path, is_active FROM users WHERE user_id = ? LIMIT 1");
$stmt->execute([$userId]);
$row = $stmt->fetch();
if (!$row || (int)($row["is_active"] ?? 0) !== 1) {
    http_response_code(403);
    echo htmlspecialchars(t("http.forbidden"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");
    exit;
}
$row = db_decrypt_user_pii_row($row);
$profileRole = (string)($row["role"] ?? "");

$error = null;
$success = null;

$latest = null;
try {
    $stmt = db()->prepare("SELECT request_id, status, requested_at, acted_at FROM password_change_requests WHERE user_id = ? ORDER BY request_id DESC LIMIT 1");
    $stmt->execute([$userId]);
    $latest = $stmt->fetch() ?: null;
} catch (Throwable) {
    $latest = null;
}

$latestDeactivation = null;
try {
    $stmt = db()->prepare(
        "SELECT request_id, status, reason, requested_at, acted_at
         FROM account_deactivation_requests
         WHERE user_id = ?
         ORDER BY request_id DESC
         LIMIT 1"
    );
    $stmt->execute([$userId]);
    $latestDeactivation = $stmt->fetch() ?: null;
} catch (Throwable) {
    $latestDeactivation = null;
}

if (($_SERVER["REQUEST_METHOD"] ?? "GET") === "POST") {
    require_valid_csrf_token();
    $action = trim((string)($_POST["action"] ?? ""));

    if ($action === "update_profile") {
        $fullName = trim((string)($_POST["full_name"] ?? ""));
        $email = strtolower(trim((string)($_POST["email"] ?? "")));
        $contactNumber = trim((string)($_POST["contact_number"] ?? ""));

        if ($fullName === "") {
            $error = t("profile.err_full_name");
        } elseif ($email !== "" && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = t("profile.err_email");
        } elseif ($contactNumber !== "" && !preg_match('/^[0-9+()\\-\\s]{7,30}$/', $contactNumber)) {
            $error = t("profile.err_contact");
        } else {
            try {
                $stmtUp = db()->prepare("UPDATE users SET full_name = ?, email = ?, contact_number = ? WHERE user_id = ?");
                $stmtUp->execute([
                    db_field_encrypt($fullName),
                    $email !== "" ? db_field_encrypt($email) : null,
                    $contactNumber !== "" ? db_field_encrypt($contactNumber) : null,
                    $userId,
                ]);
                $_SESSION["user"]["full_name"] = $fullName;
                audit_log("profile_update", "success", "user", $userId, "User updated profile fields.");
                $success = t("profile.success_profile");
                $row["full_name"] = $fullName;
                $row["email"] = $email !== "" ? $email : null;
                $row["contact_number"] = $contactNumber !== "" ? $contactNumber : null;
            } catch (Throwable $e) {
                $error = t("profile.err_save");
            }
        }
    } elseif ($action === "upload_profile_photo") {
        try {
            $oldPhoto = trim((string)($row["profile_photo_path"] ?? ""));
            $rel = save_user_profile_photo($userId, $_FILES["profile_photo"] ?? []);
            $stmtPhoto = db()->prepare("UPDATE users SET profile_photo_path = ? WHERE user_id = ?");
            $stmtPhoto->execute([$rel, $userId]);
            if ($oldPhoto !== "" && $oldPhoto !== $rel) {
                delete_user_profile_photo_file($oldPhoto);
            }
            $row["profile_photo_path"] = $rel;
            $_SESSION["user"]["profile_photo_path"] = $rel;
            audit_log("profile_photo_upload", "success", "user", $userId, "User updated profile photo.");
            $success = t("profile.success_photo");
        } catch (Throwable $e) {
            $error = t("profile.err_photo");
        }
    } elseif ($action === "remove_profile_photo") {
        try {
            $oldPhoto = trim((string)($row["profile_photo_path"] ?? ""));
            if ($oldPhoto !== "") {
                delete_user_profile_photo_file($oldPhoto);
            }
            db()->prepare("UPDATE users SET profile_photo_path = NULL WHERE user_id = ?")->execute([$userId]);
            $row["profile_photo_path"] = null;
            $_SESSION["user"]["profile_photo_path"] = "";
            audit_log("profile_photo_remove", "success", "user", $userId, "User removed profile photo.");
            $success = t("profile.success_photo_remove");
        } catch (Throwable $e) {
            $error = t("profile.err_photo");
        }
    } elseif ($action === "request_password_change") {
        $newPassword = (string)($_POST["new_password"] ?? "");
        $confirmPassword = (string)($_POST["confirm_password"] ?? "");
        $pwErr = password_change_validate_new_password_pair($newPassword, $confirmPassword);
        if ($pwErr !== null) {
            $error = $pwErr;
        } else {
            password_change_insert_pending_request($userId, $newPassword);
            $rid = (int)db()->lastInsertId();
            audit_log("password_change_request", "success", "password_change_requests", $rid, "User requested password change.");
            $success = t("profile.success_password_request");
            $stmt2 = db()->prepare("SELECT request_id, status, requested_at, acted_at FROM password_change_requests WHERE user_id = ? ORDER BY request_id DESC LIMIT 1");
            $stmt2->execute([$userId]);
            $latest = $stmt2->fetch() ?: null;
        }
    } elseif ($action === "request_account_deactivation") {
        if ($profileRole === "Admin") {
            $error = t("profile.err_deactivation_admin");
        } else {
            $reason = trim((string)($_POST["deactivation_reason"] ?? ""));
            if (strlen($reason) > 500) {
                $error = t("profile.err_deactivation_reason_len");
            } else {
                $stmtP = db()->prepare("SELECT request_id FROM account_deactivation_requests WHERE user_id = ? AND status = 'Pending' LIMIT 1");
                $stmtP->execute([$userId]);
                if ($stmtP->fetch()) {
                    $error = t("profile.err_deactivation_pending");
                } else {
                    try {
                        $stmtIns = db()->prepare("INSERT INTO account_deactivation_requests (user_id, reason) VALUES (?, ?)");
                        $stmtIns->execute([$userId, $reason !== "" ? $reason : null]);
                        $rid = (int)db()->lastInsertId();
                        audit_log("account_deactivation_request", "success", "account_deactivation_requests", $rid, "User requested account deactivation.", ["username" => $username]);
                        $success = t("profile.success_deactivation_request");
                        $stmtLd = db()->prepare(
                            "SELECT request_id, status, reason, requested_at, acted_at FROM account_deactivation_requests WHERE user_id = ? ORDER BY request_id DESC LIMIT 1"
                        );
                        $stmtLd->execute([$userId]);
                        $latestDeactivation = $stmtLd->fetch() ?: null;
                    } catch (Throwable $e) {
                        $error = t("profile.err_deactivation_request");
                    }
                }
            }
        }
    } elseif ($action === "cancel_account_deactivation") {
        if ($profileRole === "Admin") {
            $error = t("profile.err_deactivation_admin");
        } else {
            try {
                $stmtFind = db()->prepare(
                    "SELECT request_id FROM account_deactivation_requests WHERE user_id = ? AND status = 'Pending' ORDER BY request_id DESC LIMIT 1"
                );
                $stmtFind->execute([$userId]);
                $pend = $stmtFind->fetch();
                if ($pend) {
                    db()->prepare(
                        "UPDATE account_deactivation_requests SET status = 'Cancelled', acted_at = NOW() WHERE request_id = ?"
                    )->execute([(int)$pend["request_id"]]);
                    audit_log("account_deactivation_cancel", "success", "account_deactivation_requests", (int)$pend["request_id"], "User cancelled deactivation request.", ["username" => $username]);
                    $success = t("profile.success_deactivation_cancel");
                }
                $stmtLd = db()->prepare(
                    "SELECT request_id, status, reason, requested_at, acted_at FROM account_deactivation_requests WHERE user_id = ? ORDER BY request_id DESC LIMIT 1"
                );
                $stmtLd->execute([$userId]);
                $latestDeactivation = $stmtLd->fetch() ?: null;
            } catch (Throwable $e) {
                $error = t("profile.err_deactivation_cancel");
            }
        }
    } elseif ($action !== "") {
        $error = t("profile.err_unknown_action");
    }
}

$fullNameVal = (string)($row["full_name"] ?? "");
$emailVal = (string)($row["email"] ?? "");
$contactVal = (string)($row["contact_number"] ?? "");

$profileInitials = "";
foreach (preg_split('/\s+/', trim($fullNameVal), -1, PREG_SPLIT_NO_EMPTY) as $dgPart) {
    $profileInitials .= mb_strtoupper(mb_substr($dgPart, 0, 1));
    if (mb_strlen($profileInitials) >= 2) {
        break;
    }
}
if ($profileInitials === "") {
    $profileInitials = mb_strtoupper(mb_substr($username, 0, 1));
}
$profilePhotoUrl = trim((string)($row["profile_photo_path"] ?? ""));

ob_start();
?>
<div class="dg-page-header mb-3">
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
    <div>
      <h3 class="mb-0"><?= htmlspecialchars(t("profile.title"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></h3>
      <div class="text-muted small"><?= htmlspecialchars(t("profile.subtitle"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></div>
    </div>
  </div>
</div>

<?php if ($success): ?>
  <div class="alert alert-success"><?= htmlspecialchars($success, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></div>
<?php endif; ?>
<?php if ($error): ?>
  <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></div>
<?php endif; ?>

<div class="card shadow-sm mb-3 dg-staff-profile-identity">
  <div class="card-body">
    <div class="d-flex flex-wrap align-items-center gap-3">
      <?php
        $staffInitials = $profileInitials;
        $staffPhotoUrl = $profilePhotoUrl;
        $staffAvatarAlt = t("profile.photo_alt");
        require __DIR__ . "/partials/staff_profile_avatar.php";
      ?>
      <div class="flex-grow-1 min-w-0">
        <div class="dg-staff-profile-name text-truncate"><?= htmlspecialchars($fullNameVal !== "" ? $fullNameVal : $username, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></div>
        <div class="text-muted small">@<?= htmlspecialchars($username, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></div>
      </div>
      <div class="dg-staff-profile-role-wrap">
        <div class="text-muted small mb-1"><?= htmlspecialchars(t("profile.role"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></div>
        <?php $staffRole = $profileRole; require __DIR__ . "/partials/staff_role_badge.php"; ?>
      </div>
    </div>
    <div class="dg-staff-profile-photo-panel mt-3 pt-3 border-top">
      <div class="fw-semibold mb-1"><?= htmlspecialchars(t("profile.section_photo"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></div>
      <p class="text-muted small mb-2"><?= htmlspecialchars(t("profile.photo_hint"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></p>
      <form method="post" action="my-profile" enctype="multipart/form-data" class="d-flex flex-wrap align-items-end gap-2">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="upload_profile_photo" />
        <div class="flex-grow-1" style="min-width: 220px">
          <input class="form-control form-control-sm" type="file" name="profile_photo" accept="image/jpeg,image/png,image/webp" required />
        </div>
        <button class="btn btn-sm btn-dark" type="submit"><?= htmlspecialchars(t("profile.upload_photo"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></button>
      </form>
      <?php if ($profilePhotoUrl !== ""): ?>
        <form method="post" action="my-profile" class="mt-2" onsubmit="return confirm(<?= json_encode(t("profile.remove_photo_confirm"), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>);">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="remove_profile_photo" />
          <button class="btn btn-sm btn-outline-secondary" type="submit"><?= htmlspecialchars(t("profile.remove_photo"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></button>
        </form>
      <?php endif; ?>
      <div class="small text-muted mt-2"><?= htmlspecialchars(t("profile.photo_rules"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></div>
    </div>
  </div>
</div>

<div class="row g-3">
  <div class="col-lg-6">
    <div class="card shadow-sm h-100">
      <div class="card-body">
        <h5 class="card-title"><?= htmlspecialchars(t("profile.section_profile"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></h5>
        <p class="text-muted small"><?= htmlspecialchars(t("profile.section_profile_hint"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></p>
        <form method="post" action="my-profile" class="row g-2">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="update_profile" />
          <div class="col-12">
            <label class="form-label"><?= htmlspecialchars(t("profile.username"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></label>
            <input class="form-control" value="<?= htmlspecialchars($username, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" disabled />
          </div>
          <div class="col-12">
            <label class="form-label"><?= htmlspecialchars(t("profile.role"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></label>
            <input class="form-control" value="<?= htmlspecialchars(i18n_role_label($profileRole), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" disabled />
            <div class="form-text"><?= htmlspecialchars(t("profile.role_note"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></div>
          </div>
          <div class="col-12">
            <label class="form-label"><?= htmlspecialchars(t("profile.full_name"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></label>
            <input class="form-control" name="full_name" value="<?= htmlspecialchars($fullNameVal, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" required />
          </div>
          <div class="col-12">
            <label class="form-label"><?= htmlspecialchars(t("profile.email"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></label>
            <input class="form-control" type="email" name="email" value="<?= htmlspecialchars($emailVal, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" placeholder="<?= htmlspecialchars(t("profile.email_ph"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" />
          </div>
          <div class="col-12">
            <label class="form-label"><?= htmlspecialchars(t("profile.contact"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></label>
            <input class="form-control" name="contact_number" value="<?= htmlspecialchars($contactVal, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" placeholder="<?= htmlspecialchars(t("profile.contact_ph"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" />
          </div>
          <div class="col-12">
            <button class="btn btn-dark" type="submit"><?= htmlspecialchars(t("profile.save_profile"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="card shadow-sm mb-3" id="password">
      <div class="card-body">
        <h5 class="card-title"><?= htmlspecialchars(t("profile.section_password"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></h5>
        <p class="text-muted small"><?= htmlspecialchars(t("profile.password_note"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></p>
        <?php if ($latest): ?>
          <div class="small text-muted mb-3">
            <?= htmlspecialchars(t("profile.latest_request"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>
            <span class="fw-semibold"><?= htmlspecialchars((string)$latest["status"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></span>
            · <?= htmlspecialchars((string)$latest["requested_at"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>
            <?php if (!empty($latest["acted_at"])): ?>
              · <?= htmlspecialchars((string)$latest["acted_at"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>
            <?php endif; ?>
          </div>
        <?php endif; ?>
        <form method="post" action="my-profile#password" class="row g-2">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="request_password_change" />
          <div class="col-md-6">
            <label class="form-label"><?= htmlspecialchars(t("profile.new_password"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></label>
            <input class="form-control" type="password" name="new_password" minlength="8" required autocomplete="new-password" />
          </div>
          <div class="col-md-6">
            <label class="form-label"><?= htmlspecialchars(t("profile.confirm_password"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></label>
            <input class="form-control" type="password" name="confirm_password" minlength="8" required autocomplete="new-password" />
          </div>
          <div class="col-12">
            <button class="btn btn-outline-dark" type="submit"><?= htmlspecialchars(t("profile.submit_password_request"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></button>
          </div>
        </form>
        <div class="small text-muted mt-2"><?= htmlspecialchars(t("profile.password_rules"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></div>
      </div>
    </div>

    <?php if ($profileRole !== "Admin"): ?>
    <div class="card shadow-sm border-danger" id="deactivation">
      <div class="card-body">
        <h5 class="card-title text-danger"><?= htmlspecialchars(t("profile.section_danger"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></h5>
        <p class="small text-muted"><?= htmlspecialchars(t("profile.deactivation_request_help"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></p>
        <?php if ($latestDeactivation): ?>
          <div class="small text-muted mb-3">
            <?= htmlspecialchars(t("profile.latest_deactivation"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>
            <span class="fw-semibold"><?= htmlspecialchars((string)$latestDeactivation["status"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></span>
            · <?= htmlspecialchars((string)$latestDeactivation["requested_at"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>
            <?php if (!empty($latestDeactivation["acted_at"])): ?>
              · <?= htmlspecialchars((string)$latestDeactivation["acted_at"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>
            <?php endif; ?>
            <?php if (!empty($latestDeactivation["reason"])): ?>
              <div class="mt-1"><?= htmlspecialchars(t("profile.deactivation_reason_label"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>
                <?= htmlspecialchars((string)$latestDeactivation["reason"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></div>
            <?php endif; ?>
          </div>
        <?php endif; ?>
        <?php
          $deactPending = $latestDeactivation && (string)($latestDeactivation["status"] ?? "") === "Pending";
        ?>
        <?php if ($deactPending): ?>
          <form method="post" action="my-profile#deactivation" class="mb-3">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="cancel_account_deactivation" />
            <button class="btn btn-outline-secondary btn-sm" type="submit"><?= htmlspecialchars(t("profile.cancel_deactivation_request"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></button>
          </form>
        <?php else: ?>
          <form method="post" action="my-profile#deactivation" class="row g-2">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="request_account_deactivation" />
            <div class="col-12">
              <label class="form-label"><?= htmlspecialchars(t("profile.deactivation_reason_field"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></label>
              <textarea class="form-control" name="deactivation_reason" rows="2" maxlength="500" placeholder="<?= htmlspecialchars(t("profile.deactivation_reason_ph"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>"></textarea>
            </div>
            <div class="col-12">
              <button class="btn btn-outline-danger" type="submit"><?= htmlspecialchars(t("profile.submit_deactivation_request"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></button>
            </div>
          </form>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php
$content = ob_get_clean();
require __DIR__ . "/_layout.php";
