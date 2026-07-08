<?php
declare(strict_types=1);

require_once __DIR__ . "/../../app/auth.php";
require_once __DIR__ . "/../../app/url.php";
require_once __DIR__ . "/../../app/i18n.php";
$config = app_config();
$user = current_user();
$dg_body_class = isset($dg_body_class) ? (string)$dg_body_class : "";
$logoUrl = base_url("assets/drop-guard-favicon.png");
$faviconUrl = base_url("assets/drop-guard-favicon.png");

function h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");
}

?>
<!doctype html>
<html lang="<?= h(i18n_html_lang()) ?>">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title><?= h($config["app"]["name"]) ?></title>
    <link rel="icon" type="image/png" sizes="32x32" href="<?= h($faviconUrl) ?>">
    <link rel="icon" type="image/png" sizes="64x64" href="<?= h($faviconUrl) ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:ital,wght@0,400;0,500;0,600;0,700;0,800;1,400&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?= h(base_url("assets/theme.css")) ?>" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
  </head>
  <body class="bg-light<?= $dg_body_class !== "" ? " " . h($dg_body_class) : "" ?><?= $user ? " dg-app-body" : "" ?>">
    <?php
      $seg = app_normalize_request_path();
      $seg = $seg === "" ? "dashboard" : explode("/", $seg, 2)[0];

      $active = match ($seg) {
          "my-profile" => "my-profile",
          "landing" => "landing",
          "data-entry" => "data-entry",
          "student-list" => "student-list",
          "import" => "import",
          "teacher-overview" => "teacher-overview",
          "attendance-sheet" => "student-sheets",
          "student-sheets" => "student-sheets",
          "teacher-schedule" => "schedules",
          "section-schedule" => "schedules",
          "schedules" => "schedules",
          "reports" => "reports",
          "users" => "users",
          "audit-logs" => "audit-logs",
          "manage-sections" => "manage-sections",
          "manage-subjects" => "manage-subjects",
          "password-request" => "password-request",
          "settings" => "settings",
          "data-health" => "data-health",
          // Student profile pages should not break the highlighted tab.
          "student" => "student-list",
          default => "dashboard",
      };
      $role = (string)($user["role"] ?? "");
    ?>

    <?php if (!$user): ?>
      <div class="container pt-3 pb-0<?= ($dg_body_class === "dg-login-body" || $dg_body_class === "dg-landing-body" || $dg_body_class === "dg-legal-body") ? " dg-login-topbar" : "" ?>">
        <div class="d-flex justify-content-end align-items-center gap-2 flex-wrap">
          <?php require __DIR__ . "/partials/language_switcher.php"; ?>
        </div>
      </div>
      <main class="<?php
        if ($dg_body_class === "dg-login-body") {
            echo "container py-4 dg-login-main";
        } elseif ($dg_body_class === "dg-legal-body") {
            echo "container-fluid dg-legal-main";
        } elseif ($dg_body_class === "dg-landing-body") {
            echo "container py-4 dg-landing-main";
        } else {
            echo "container py-4";
        }
      ?>">
        <?= $content ?? "" ?>
      </main>
      <footer class="container pb-4">
        <div class="d-flex flex-wrap justify-content-center align-items-center gap-3 text-muted small">
          <button type="button" class="btn btn-link text-muted text-decoration-none p-0 border-0 small dg-footer-legal-link" data-bs-toggle="modal" data-bs-target="#dgPrivacyModal"><?= h(t("layout.legal_privacy")) ?></button>
          <span aria-hidden="true">•</span>
          <button type="button" class="btn btn-link text-muted text-decoration-none p-0 border-0 small dg-footer-legal-link" data-bs-toggle="modal" data-bs-target="#dgTermsModal"><?= h(t("layout.legal_terms")) ?></button>
        </div>
      </footer>
    <?php else: ?>
      <nav class="navbar navbar-expand-lg navbar-dark dg-navbar">
        <div class="container-fluid">
          <a class="navbar-brand d-flex align-items-center gap-2" href="<?= h(base_url("dashboard")) ?>">
            <img class="dg-brand-logo" src="<?= h($logoUrl) ?>" alt="<?= h(t("layout.alt_logo")) ?>">
            <span><?= h($config["app"]["name"]) ?></span>
          </a>
          <div class="d-flex align-items-center gap-2 gap-md-3 text-white flex-wrap justify-content-end">
            <?php require __DIR__ . "/partials/language_switcher.php"; ?>
            <?php
              $dgDisplayName = (string)($user["full_name"] ?? $user["username"] ?? "");
              $dgInitials = "";
              foreach (preg_split('/\s+/', trim($dgDisplayName), -1, PREG_SPLIT_NO_EMPTY) as $dgPart) {
                  $dgInitials .= mb_strtoupper(mb_substr($dgPart, 0, 1));
                  if (mb_strlen($dgInitials) >= 2) {
                      break;
                  }
              }
              if ($dgInitials === "") {
                  $dgInitials = mb_strtoupper(mb_substr((string)($user["username"] ?? "U"), 0, 1));
              }
              $dgProfilePhoto = trim((string)($user["profile_photo_path"] ?? ""));
            ?>
            <div class="dropdown dg-user-menu">
              <button
                type="button"
                class="dg-user-chip dropdown-toggle"
                id="dgUserMenuBtn"
                data-bs-toggle="dropdown"
                aria-expanded="false"
                aria-label="<?= h($dgDisplayName) ?>"
              >
                <?php
                  $staffInitials = $dgInitials;
                  $staffPhotoUrl = $dgProfilePhoto;
                  $staffAvatarClass = "dg-user-avatar";
                  $staffAvatarAlt = $dgDisplayName;
                  require __DIR__ . "/partials/staff_profile_avatar.php";
                ?>
                <span class="dg-user-meta d-none d-sm-flex">
                  <span class="dg-user-name"><?= h($dgDisplayName) ?></span>
                  <span class="dg-user-role"><?= h(i18n_role_label((string)($user["role"] ?? ""))) ?></span>
                </span>
              </button>
              <ul class="dropdown-menu dropdown-menu-end shadow dg-user-menu-panel" aria-labelledby="dgUserMenuBtn">
                <li class="dropdown-header dg-user-menu-header d-sm-none">
                  <div class="fw-semibold text-truncate"><?= h($dgDisplayName) ?></div>
                  <div class="small text-muted"><?= h(i18n_role_label((string)($user["role"] ?? ""))) ?></div>
                </li>
                <li class="d-sm-none"><hr class="dropdown-divider" /></li>
                <li>
                  <a class="dropdown-item<?= $active === "my-profile" ? " active" : "" ?>" href="<?= h(base_url("my-profile")) ?>">
                    <?= h(t("layout.nav.my_profile")) ?>
                  </a>
                </li>
                <li><hr class="dropdown-divider" /></li>
                <li>
                  <a class="dropdown-item dg-user-menu-logout" href="<?= h(base_url("logout")) ?>">
                    <?= h(t("layout.logout")) ?>
                  </a>
                </li>
              </ul>
            </div>
          </div>
        </div>
      </nav>

      <div class="d-flex dg-shell">
        <aside class="dg-sidebar p-3 d-flex flex-column">
          <div class="dg-brand mb-3">
            <img class="dg-sidebar-logo" src="<?= h($logoUrl) ?>" alt="<?= h(t("layout.alt_logo")) ?>">
          </div>
          <nav class="dg-nav d-grid gap-1 flex-grow-1">
            <?php if (in_array($role, ["Counselor", "Admin"], true)): ?>
              <div class="dg-nav-section-label"><?= h(t("layout.nav.section_insights")) ?></div>
              <a class="<?= $active === "dashboard" ? "active" : "" ?>" href="<?= h(base_url("dashboard")) ?>"><?= h(t("layout.nav.predictive_dashboard")) ?></a>
              <a class="<?= $active === "student-list" ? "active" : "" ?>" href="<?= h(base_url("student-list")) ?>"><?= h(t("layout.nav.all_students")) ?></a>
              <a class="<?= $active === "reports" ? "active" : "" ?>" href="<?= h(base_url("reports")) ?>"><?= h(t("layout.nav.reports")) ?></a>
              <div class="dg-nav-section-label"><?= h(t("layout.nav.section_classroom")) ?></div>
              <a class="<?= $active === "student-sheets" ? "active" : "" ?>" href="<?= h(base_url("student-sheets")) ?>"><?= h(t("layout.nav.student_sheets")) ?></a>
              <a class="<?= $active === "schedules" ? "active" : "" ?>" href="<?= h(base_url("schedules")) ?>"><?= h(t("layout.nav.schedules")) ?></a>
            <?php endif; ?>
            <?php if (in_array($role, ["Teacher"], true)): ?>
              <div class="dg-nav-section-label"><?= h(t("layout.nav.section_classroom")) ?></div>
              <a class="<?= $active === "data-entry" ? "active" : "" ?>" href="<?= h(base_url("data-entry")) ?>"><?= h(t("layout.nav.teacher_data_entry")) ?></a>
              <a class="<?= $active === "import" ? "active" : "" ?>" href="<?= h(base_url("import")) ?>"><?= h(t("layout.nav.import_class")) ?></a>
              <a class="<?= $active === "teacher-overview" ? "active" : "" ?>" href="<?= h(base_url("teacher-overview")) ?>"><?= h(t("layout.nav.class_overview")) ?></a>
              <a class="<?= $active === "student-sheets" ? "active" : "" ?>" href="<?= h(base_url("student-sheets")) ?>"><?= h(t("layout.nav.student_sheets")) ?></a>
              <a class="<?= $active === "schedules" ? "active" : "" ?>" href="<?= h(base_url("schedules")) ?>"><?= h(t("layout.nav.schedules")) ?></a>
            <?php endif; ?>
            <?php if ($role === "Admin"): ?>
              <div class="dg-nav-section-label"><?= h(t("layout.nav.section_admin")) ?></div>
              <a class="<?= $active === "users" ? "active" : "" ?>" href="<?= h(base_url("users")) ?>"><?= h(t("layout.nav.users")) ?></a>
              <a class="<?= $active === "manage-sections" ? "active" : "" ?>" href="<?= h(base_url("manage-sections")) ?>"><?= h(t("layout.nav.sections")) ?></a>
              <a class="<?= $active === "manage-subjects" ? "active" : "" ?>" href="<?= h(base_url("manage-subjects")) ?>"><?= h(t("layout.nav.subjects")) ?></a>
              <a class="<?= $active === "settings" ? "active" : "" ?>" href="<?= h(base_url("settings")) ?>"><?= h(t("layout.nav.settings")) ?></a>
              <a class="<?= $active === "data-health" ? "active" : "" ?>" href="<?= h(base_url("data-health")) ?>">Data Health</a>
              <a class="<?= $active === "audit-logs" ? "active" : "" ?>" href="<?= h(base_url("audit-logs")) ?>"><?= h(t("layout.nav.audit_logs")) ?></a>
            <?php endif; ?>
          </nav>
          <a class="dg-sidebar-user mt-3 text-decoration-none<?= $active === "my-profile" ? " dg-sidebar-user--active" : "" ?>" href="<?= h(base_url("my-profile")) ?>">
            <div class="dg-sidebar-user-label"><?= h(t("layout.signed_in_as")) ?></div>
            <div class="d-flex align-items-center gap-2 mt-1">
              <?php
                $staffInitials = $dgInitials;
                $staffPhotoUrl = trim((string)($user["profile_photo_path"] ?? ""));
                $staffAvatarClass = "dg-user-avatar dg-user-avatar-sm";
                $staffAvatarAlt = (string)($user["full_name"] ?? $user["username"] ?? "");
                require __DIR__ . "/partials/staff_profile_avatar.php";
              ?>
              <div class="min-w-0">
                <div class="dg-sidebar-user-name text-truncate"><?= h($user["full_name"] ?? $user["username"]) ?></div>
                <div class="dg-sidebar-user-role"><?= h(i18n_role_label((string)($user["role"] ?? ""))) ?></div>
              </div>
            </div>
          </a>
        </aside>

        <div class="dg-content">
          <?= $content ?? "" ?>
          <div class="px-3 pb-4">
            <div class="d-flex flex-wrap justify-content-center align-items-center gap-3 text-muted small">
              <button type="button" class="btn btn-link text-muted text-decoration-none p-0 border-0 small dg-footer-legal-link" data-bs-toggle="modal" data-bs-target="#dgPrivacyModal"><?= h(t("layout.legal_privacy")) ?></button>
              <span aria-hidden="true">•</span>
              <button type="button" class="btn btn-link text-muted text-decoration-none p-0 border-0 small dg-footer-legal-link" data-bs-toggle="modal" data-bs-target="#dgTermsModal"><?= h(t("layout.legal_terms")) ?></button>
            </div>
          </div>
        </div>
      </div>
    <?php endif; ?>
    <?php require __DIR__ . "/partials/legal_modals.php"; ?>
    <?php if (!empty($dg_page_modals ?? "")): ?>
      <?= $dg_page_modals ?>
    <?php endif; ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <?php if ($user): ?>
    <script>
      (function () {
        if (!window.history || typeof history.pushState !== "function" || typeof history.forward !== "function") {
          return;
        }
        try {
          history.pushState(null, "", window.location.href);
        } catch (e) {}
        window.addEventListener(
          "popstate",
          function () {
            try {
              history.forward();
            } catch (e) {}
          },
          false
        );
        window.addEventListener("pageshow", function (ev) {
          if (!ev.persisted) return;
          try {
            history.pushState(null, "", window.location.href);
          } catch (e) {}
        });
      })();
    </script>
    <?php endif; ?>
    <script>
      (function () {
        document.querySelectorAll("[data-dg-switch-modal]").forEach(function (btn) {
          btn.addEventListener("click", function (e) {
            e.preventDefault();
            var sel = btn.getAttribute("data-dg-switch-modal");
            if (!sel) return;
            var curModal = btn.closest(".modal");
            var elTarget = document.querySelector(sel);
            if (!curModal || !elTarget || !window.bootstrap) return;
            var instCur = bootstrap.Modal.getInstance(curModal);
            if (!instCur) return;
            function onHidden() {
              curModal.removeEventListener("hidden.bs.modal", onHidden);
              bootstrap.Modal.getOrCreateInstance(elTarget).show();
            }
            curModal.addEventListener("hidden.bs.modal", onHidden);
            instCur.hide();
          });
        });
        <?php
        $dgBootLegal = (string)($openLegalModal ?? "");
        if ($dgBootLegal === "privacy" || $dgBootLegal === "terms"):
          $dgBootId = $dgBootLegal === "privacy" ? "dgPrivacyModal" : "dgTermsModal";
        ?>
        document.addEventListener("DOMContentLoaded", function () {
          var el = document.getElementById(<?= json_encode($dgBootId) ?>);
          if (el && window.bootstrap) {
            bootstrap.Modal.getOrCreateInstance(el).show();
          }
        });
        <?php endif; ?>
      })();
    </script>
    <?php if (!empty($dg_login_legal_consent_gate)): ?>
    <script>
      (function () {
        document.addEventListener("DOMContentLoaded", function () {
          var consent = document.getElementById("consentCheck");
          var csrfEl = document.querySelector('form[action="login"] input[name="csrf_token"]');
          var gate = document.querySelector(".dg-consent-gate");
          var ackUrl = <?= json_encode(base_url("legal-ack")) ?>;
          if (!consent || !csrfEl) return;
          var ack = {
            privacy: <?= !empty($dg_login_legal_ack_privacy) ? "true" : "false" ?>,
            terms: <?= !empty($dg_login_legal_ack_terms) ? "true" : "false" ?>
          };
          try {
            localStorage.removeItem("dg_login_consent_checked");
          } catch (e) {}

          function clearLoginConsentCheckbox() {
            if (!consent) return;
            consent.checked = false;
            try {
              localStorage.removeItem("dg_login_consent_checked");
            } catch (e) {}
          }

          function updateLoginSubmitRules() {
            var submitBtn = document.getElementById("loginSubmitBtn");
            if (!submitBtn || !consent) return;
            if (submitBtn.getAttribute("data-dg-login-locked") === "1") {
              submitBtn.disabled = true;
              return;
            }
            submitBtn.disabled = !consent.checked || consent.disabled;
          }
          window.dgApplyLoginSubmitRules = updateLoginSubmitRules;

          function syncConsentControl() {
            var ok = ack.privacy && ack.terms;
            consent.disabled = !ok;
            if (gate) {
              if (ok) {
                gate.classList.add("dg-consent-gate-complete");
              } else {
                gate.classList.remove("dg-consent-gate-complete");
              }
            }
            updateLoginSubmitRules();
          }
          function postAck(doc) {
            var fd = new FormData();
            fd.append("csrf_token", csrfEl.value);
            fd.append("doc", doc);
            fetch(ackUrl, {
              method: "POST",
              body: fd,
              credentials: "same-origin",
              headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" }
            })
              .then(function (r) { return r.json().catch(function () { return null; }); })
              .then(function (data) {
                if (!data || !data.ok) return;
                ack.privacy = !!data.privacy;
                ack.terms = !!data.terms;
                syncConsentControl();
              });
          }
          function rememberDocOpened(doc) {
            try {
              if (doc === "privacy") localStorage.setItem("dg_login_legal_seen_privacy", "1");
              if (doc === "terms") localStorage.setItem("dg_login_legal_seen_terms", "1");
            } catch (e) {}
          }
          function restoreLegalFromStorage() {
            try {
              var seenP = localStorage.getItem("dg_login_legal_seen_privacy") === "1";
              var seenT = localStorage.getItem("dg_login_legal_seen_terms") === "1";
              // Re-apply acknowledgements into the new session after refresh/logout.
              if (seenP && !ack.privacy) postAck("privacy");
              if (seenT && !ack.terms) postAck("terms");
            } catch (e) {}
          }
          var pModal = document.getElementById("dgPrivacyModal");
          var tModal = document.getElementById("dgTermsModal");
          function bindTriggerAck(doc, targetId) {
            document.querySelectorAll('[data-bs-toggle="modal"][data-bs-target="#' + targetId + '"]').forEach(function (btn) {
              btn.addEventListener("click", function () {
                rememberDocOpened(doc);
                // Acknowledge immediately on click so the checkbox can unlock
                // even if the user closes quickly (no scrolling required).
                postAck(doc);
              });
            });
          }
          bindTriggerAck("privacy", "dgPrivacyModal");
          bindTriggerAck("terms", "dgTermsModal");
          if (pModal) {
            pModal.addEventListener("shown.bs.modal", function () {
              rememberDocOpened("privacy");
              postAck("privacy");
              clearLoginConsentCheckbox();
              updateLoginSubmitRules();
            });
          }
          if (tModal) {
            tModal.addEventListener("shown.bs.modal", function () {
              rememberDocOpened("terms");
              postAck("terms");
              clearLoginConsentCheckbox();
              updateLoginSubmitRules();
            });
          }
          consent.addEventListener("change", function () {
            updateLoginSubmitRules();
          });
          restoreLegalFromStorage();
          syncConsentControl();
        });
      })();
    </script>
    <?php endif; ?>
  </body>
</html>

