<?php
declare(strict_types=1);

require_once __DIR__ . "/../../app/auth.php";
require_once __DIR__ . "/../../app/url.php";
require_once __DIR__ . "/../../app/i18n.php";

start_app_session();
$config = app_config();

if (current_user()) {
    $u = current_user();
    $role = (string)($u["role"] ?? "");
    if ($role === "Teacher") {
        header("Location: data-entry");
    } else {
        header("Location: dashboard");
    }
    exit;
}

function format_countdown_mmss(int $seconds): string
{
    $seconds = max(0, $seconds);
    $mins = intdiv($seconds, 60);
    $secs = $seconds % 60;
    return str_pad((string)$mins, 2, "0", STR_PAD_LEFT) . ":" . str_pad((string)$secs, 2, "0", STR_PAD_LEFT);
}

function h_login(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");
}

$error = null;
$lockWaitSeconds = 0;
$username = "";
$ip = (string)($_SERVER["REMOTE_ADDR"] ?? "");
$flashCode = $_SESSION["login_flash_code"] ?? null;
unset($_SESSION["login_flash_code"]);
$loginFlashCodes = ["blank_creds", "legal_required", "consent_required", "invalid_credentials", "locked_wait"];
if (is_string($flashCode) && in_array($flashCode, $loginFlashCodes, true)) {
    $error = t("login.flash." . $flashCode);
}

$lockUserFromSession = (string)($_SESSION["login_lock_username"] ?? "");
if ($lockUserFromSession !== "") {
    $refreshWait = get_login_wait_seconds($lockUserFromSession, $ip);
    if ($refreshWait > 0) {
        $lockWaitSeconds = $refreshWait;
        $error = t("login.flash.locked_wait");
        $username = $lockUserFromSession;
    } else {
        unset($_SESSION["login_lock_username"]);
    }
}

if (($_SERVER["REQUEST_METHOD"] ?? "GET") === "POST") {
    require_valid_csrf_token();
    $username = trim((string) ($_POST["username"] ?? ""));
    $password = (string) ($_POST["password"] ?? "");
    $consent = (string)($_POST["consent"] ?? "");
    $_SESSION["login_last_username"] = $username;
    if ($username === "" || $password === "") {
        $_SESSION["login_flash_code"] = "blank_creds";
        header("Location: login");
        exit;
    } elseif (empty($_SESSION["login_legal_ack_privacy"]) || empty($_SESSION["login_legal_ack_terms"])) {
        $_SESSION["login_flash_code"] = "legal_required";
        header("Location: login");
        exit;
    } elseif ($consent !== "1") {
        $_SESSION["login_flash_code"] = "consent_required";
        header("Location: login");
        exit;
    } elseif (!login($username, $password)) {
        $lockWaitSeconds = get_login_wait_seconds($username, $ip);
        if ($lockWaitSeconds > 0) {
            $_SESSION["login_lock_username"] = $username;
            $_SESSION["login_flash_code"] = "locked_wait";
        } else {
            unset($_SESSION["login_lock_username"]);
            $_SESSION["login_flash_code"] = "invalid_credentials";
        }
        header("Location: login");
        exit;
    } else {
        unset(
            $_SESSION["login_lock_username"],
            $_SESSION["login_last_username"],
            $_SESSION["login_legal_ack_privacy"],
            $_SESSION["login_legal_ack_terms"]
        );
        $intended = take_login_intended_route();
        if ($intended !== "") {
            header("Location: " . $intended);
            exit;
        }
        $u = current_user();
        $role = (string)($u["role"] ?? "");
        if ($role === "Teacher") {
            header("Location: data-entry");
        } else {
            header("Location: dashboard");
        }
        exit;
    }
} elseif ($username === "" && isset($_SESSION["login_last_username"])) {
    $username = (string)$_SESSION["login_last_username"];
}

$dg_login_legal_ready = !empty($_SESSION["login_legal_ack_privacy"]) && !empty($_SESSION["login_legal_ack_terms"]);
$dg_login_legal_ack_privacy = !empty($_SESSION["login_legal_ack_privacy"]);
$dg_login_legal_ack_terms = !empty($_SESSION["login_legal_ack_terms"]);
$dg_login_legal_consent_gate = true;

ob_start();
?>
<div class="dg-login-page">
  <div class="dg-login-shell">
    <aside class="dg-login-hero" aria-hidden="false">
      <div class="dg-login-hero-bg" aria-hidden="true">
        <span class="dg-login-hero-orb dg-login-hero-orb--gold"></span>
        <span class="dg-login-hero-orb dg-login-hero-orb--green"></span>
      </div>
      <div class="dg-login-hero-inner">
        <p class="dg-login-hero-school"><?= h_login(t("login.hero_school")) ?></p>

        <div class="dg-login-hero-brand">
          <div class="dg-login-hero-logo-wrap">
            <img class="dg-login-hero-logo" src="<?= h_login(base_url("assets/drop-guard-favicon.png")) ?>" alt="<?= h_login(t("layout.alt_logo")) ?>">
          </div>
          <div>
            <div class="dg-login-hero-title">Drop Guard</div>
            <div class="dg-login-hero-subtitle"><?= h_login(t("login.brand_subtitle")) ?></div>
          </div>
        </div>

        <p class="dg-login-hero-lead"><?= h_login(t("login.hero_lead")) ?></p>

        <ul class="dg-login-hero-features" aria-label="Key capabilities">
          <li class="dg-login-hero-feature">
            <span class="dg-login-hero-feature-ico" aria-hidden="true">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M4 19V5M4 19H20M8 15V11M12 15V8M16 15V13" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </span>
            <?= h_login(t("login.hero_feat_grades")) ?>
          </li>
          <li class="dg-login-hero-feature">
            <span class="dg-login-hero-feature-ico" aria-hidden="true">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M8 2V5M16 2V5M3.5 9.5H20.5M5 5H19C20.1 5 21 5.9 21 7V19C21 20.1 20.1 21 19 21H5C3.9 21 3 20.1 3 19V7C3 5.9 3.9 5 5 5Z" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </span>
            <?= h_login(t("login.hero_feat_attendance")) ?>
          </li>
          <li class="dg-login-hero-feature">
            <span class="dg-login-hero-feature-ico" aria-hidden="true">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12 9V13M12 17H12.01M10.3 3.6L2.7 17.1C2.1 18.2 2.9 19.5 4.2 19.5H19.8C21.1 19.5 21.9 18.2 21.3 17.1L13.7 3.6C13.1 2.5 11.9 2.5 11.3 3.6H10.3Z" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </span>
            <?= h_login(t("login.hero_feat_risk")) ?>
          </li>
        </ul>

        <div class="dg-login-hero-roles" role="list" aria-label="Supported roles">
          <article class="dg-login-role-card" role="listitem">
            <span class="dg-login-role-card-mark" aria-hidden="true">T</span>
            <div>
              <div class="dg-login-role-card-title"><?= h_login(i18n_role_label("Teacher")) ?></div>
              <div class="dg-login-role-card-hint"><?= h_login(t("login.role_teacher_hint")) ?></div>
            </div>
          </article>
          <article class="dg-login-role-card" role="listitem">
            <span class="dg-login-role-card-mark dg-login-role-card-mark--counselor" aria-hidden="true">C</span>
            <div>
              <div class="dg-login-role-card-title"><?= h_login(i18n_role_label("Counselor")) ?></div>
              <div class="dg-login-role-card-hint"><?= h_login(t("login.role_counselor_hint")) ?></div>
            </div>
          </article>
          <article class="dg-login-role-card" role="listitem">
            <span class="dg-login-role-card-mark dg-login-role-card-mark--admin" aria-hidden="true">A</span>
            <div>
              <div class="dg-login-role-card-title"><?= h_login(i18n_role_label("Admin")) ?></div>
              <div class="dg-login-role-card-hint"><?= h_login(t("login.role_admin_hint")) ?></div>
            </div>
          </article>
        </div>

        <div class="dg-login-hero-foot">
          <span class="dg-login-hero-secure" aria-hidden="true">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M7 11V8C7 5.24 9.24 3 12 3C14.76 3 17 5.24 17 8V11M6 11H18C19.1 11 20 11.9 20 13V20C20 21.1 19.1 22 18 22H6C4.9 22 4 21.1 4 20V13C4 11.9 4.9 11 6 11Z" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/></svg>
          </span>
          <?= h_login(t("login.hero_secure")) ?>
        </div>
      </div>
    </aside>

    <section class="dg-login-panel" aria-labelledby="dgLoginHeading">
      <div class="dg-login-panel-card">
        <div class="dg-login-panel-head">
          <h1 class="dg-login-panel-title" id="dgLoginHeading"><?= h_login(t("login.heading_sign_in")) ?></h1>
          <p class="dg-login-panel-subtitle mb-0"><?= h_login(t("login.hint_roles")) ?></p>
        </div>

        <?php if ($error): ?>
          <div class="alert alert-danger dg-login-alert" id="<?= $lockWaitSeconds > 0 ? "lockErrorBanner" : "" ?>" role="alert">
            <?= h_login($error) ?>
          </div>
        <?php endif; ?>
        <?php if ($lockWaitSeconds > 0): ?>
          <div class="alert alert-warning dg-login-alert small py-2 px-3" id="lockCountdownBanner" role="status">
            <?= h_login(t("login.lock_intro")) ?>
            <span class="fw-bold" id="lockCountdownValue" data-seconds="<?= (int)$lockWaitSeconds ?>"><?= h_login(format_countdown_mmss((int)$lockWaitSeconds)) ?></span>.
          </div>
        <?php endif; ?>

        <form method="post" action="login" class="dg-login-form">
          <?= csrf_field() ?>
          <div class="dg-login-field">
            <label class="form-label" for="dgLoginUsername"><?= h_login(t("login.label_username")) ?></label>
            <div class="dg-login-input-wrap">
              <span class="dg-login-input-icon" aria-hidden="true">&#128100;</span>
              <input class="form-control dg-login-input" id="dgLoginUsername" name="username" value="<?= h_login((string)($username ?? "")) ?>" autocomplete="username" required placeholder="<?= h_login(t("login.label_username")) ?>" />
            </div>
          </div>
          <div class="dg-login-field">
            <label class="form-label" for="dgLoginPassword"><?= h_login(t("login.label_password")) ?></label>
            <div class="dg-login-input-wrap">
              <span class="dg-login-input-icon" aria-hidden="true">&#128274;</span>
              <input class="form-control dg-login-input" id="dgLoginPassword" type="password" name="password" autocomplete="current-password" required placeholder="••••••••" />
            </div>
            <div class="form-check mt-2 mb-0">
              <input class="form-check-input" type="checkbox" id="dgRevealPassword">
              <label class="form-check-label small" for="dgRevealPassword"><?= h_login(t("common.show_password")) ?></label>
            </div>
          </div>

          <div class="dg-consent-gate dg-login-consent rounded-3 px-3 py-3 mb-3<?= $dg_login_legal_ready ? " dg-consent-gate-complete" : "" ?>">
            <div class="form-check mb-0 d-flex align-items-start gap-2">
              <input class="form-check-input mt-1" type="checkbox" value="1" id="consentCheck" name="consent" required<?= $dg_login_legal_ready ? "" : " disabled" ?>>
              <div class="small lh-base">
                <label class="form-check-label d-inline" for="consentCheck"><?= h_login(t("login.consent_intro")) ?></label>
                <button type="button" class="btn btn-link p-0 align-baseline border-0 dg-consent-legal-link" data-bs-toggle="modal" data-bs-target="#dgPrivacyModal"><?= h_login(t("login.link_privacy")) ?></button>
                <label class="form-check-label d-inline" for="consentCheck"><?= h_login(t("login.consent_and")) ?></label>
                <button type="button" class="btn btn-link p-0 align-baseline border-0 dg-consent-legal-link" data-bs-toggle="modal" data-bs-target="#dgTermsModal"><?= h_login(t("login.link_terms")) ?></button>
                <label class="form-check-label d-inline" for="consentCheck">.</label>
              </div>
            </div>
          </div>

          <button class="btn btn-primary w-100 dg-login-submit" id="loginSubmitBtn" type="submit" disabled>
            <?= h_login(t("login.submit")) ?>
          </button>
        </form>

        <p class="dg-login-panel-note text-muted small mb-0"><?= h_login(t("login.admin_footer")) ?></p>

        <div class="dg-login-panel-footer">
          <a class="dg-login-back-link" href="landing">
            <span aria-hidden="true">&#8592;</span>
            <?= h_login(t("login.back_landing")) ?>
          </a>
        </div>
      </div>
    </section>
  </div>
</div>
<?php if ($lockWaitSeconds > 0): ?>
<?php
$dgLockI18n = [
    "signIn" => t("login.submit"),
    "lockedPrefix" => t("login.locked_button_prefix"),
    "unlockBanner" => t("login.unlock_ready"),
];
?>
<script>
  (function () {
    const countdownEl = document.getElementById("lockCountdownValue");
    const bannerEl = document.getElementById("lockCountdownBanner");
    const submitBtn = document.getElementById("loginSubmitBtn");
    const lockErrorBanner = document.getElementById("lockErrorBanner");
    const i18n = <?= json_encode($dgLockI18n, JSON_UNESCAPED_UNICODE) ?>;
    if (!countdownEl || !bannerEl || !submitBtn) return;

    let seconds = Number(countdownEl.getAttribute("data-seconds") || "0");
    if (!Number.isFinite(seconds) || seconds <= 0) return;

    function fmt(v) {
      const mins = Math.floor(v / 60);
      const secs = v % 60;
      return String(mins).padStart(2, "0") + ":" + String(secs).padStart(2, "0");
    }

    submitBtn.setAttribute("data-dg-login-locked", "1");
    submitBtn.disabled = true;
    submitBtn.textContent = i18n.lockedPrefix + " (" + fmt(seconds) + ")";

    const timer = setInterval(() => {
      seconds -= 1;
      if (seconds <= 0) {
        clearInterval(timer);
        countdownEl.textContent = "00:00";
        bannerEl.textContent = i18n.unlockBanner;
        if (lockErrorBanner) {
          lockErrorBanner.style.display = "none";
        }
        submitBtn.removeAttribute("data-dg-login-locked");
        submitBtn.textContent = i18n.signIn;
        if (typeof window.dgApplyLoginSubmitRules === "function") {
          window.dgApplyLoginSubmitRules();
        } else {
          submitBtn.disabled = false;
        }
        return;
      }
      countdownEl.textContent = fmt(seconds);
      submitBtn.textContent = i18n.lockedPrefix + " (" + fmt(seconds) + ")";
    }, 1000);
  })();
</script>
<?php endif; ?>

<script>
  (function () {
    const pw = document.getElementById("dgLoginPassword");
    const cb = document.getElementById("dgRevealPassword");
    if (!pw || !cb) return;
    cb.addEventListener("change", function () {
      pw.type = cb.checked ? "text" : "password";
    });
  })();
</script>

<?php
$content = ob_get_clean();
$dg_body_class = "dg-login-body";
require __DIR__ . "/_layout.php";
