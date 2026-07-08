<?php
declare(strict_types=1);

require_once __DIR__ . "/../../app/auth.php";
require_once __DIR__ . "/../../app/url.php";
require_once __DIR__ . "/../../app/i18n.php";

start_app_session();
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

$config = app_config();
$loginRequired = !empty($_SESSION["login_required_notice"]);
unset($_SESSION["login_required_notice"]);

function h_lp(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");
}

ob_start();
?>
<div class="dg-lp">
  <header class="dg-lp-nav" aria-label="Top navigation">
    <div class="dg-lp-nav-inner">
      <a class="dg-lp-brand" href="landing">
        <span class="dg-lp-brand-dot" aria-hidden="true"></span>
        <span class="dg-lp-brand-name"><?= htmlspecialchars((string)($config["app"]["name"] ?? "Drop Guard"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></span>
      </a>

      <nav class="dg-lp-nav-links" aria-label="Landing navigation">
        <a href="#dg-landing-why-heading"><?= htmlspecialchars(t("landing.nav_overview"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></a>
        <a href="#dg-landing-audience-heading"><?= htmlspecialchars(t("landing.nav_roles"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></a>
        <a href="#dg-landing-modules-heading"><?= htmlspecialchars(t("landing.nav_modules"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></a>
        <a href="#dg-landing-faq-heading"><?= htmlspecialchars(t("landing.nav_faq"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></a>
        <a href="#dg-landing-legal-heading"><?= htmlspecialchars(t("landing.nav_legal"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></a>
      </nav>

      <div class="dg-lp-nav-right">
        <div class="d-none d-md-flex">
          <form method="post" action="<?= h_lp(base_url("set-language")) ?>" class="d-inline-flex align-items-center gap-2 flex-wrap dg-lang-switch">
            <?= csrf_field() ?>
            <input type="hidden" name="next" value="<?= h_lp(i18n_current_route_segment()) ?>" />
            <label class="small mb-0 text-muted" for="dgLpLangSelect"><?= h_lp(t("layout.language")) ?></label>
            <select class="form-select form-select-sm dg-lang-select" name="lang" id="dgLpLangSelect" aria-label="<?= h_lp(t("layout.language")) ?>">
              <?php foreach (i18n_supported_locales() as $code => $label): ?>
                <option value="<?= h_lp($code) ?>"<?= i18n_locale() === $code ? " selected" : "" ?>><?= h_lp($label) ?></option>
              <?php endforeach; ?>
            </select>
            <noscript><button type="submit" class="btn btn-sm btn-outline-secondary"><?= h_lp(t("layout.language")) ?></button></noscript>
          </form>
          <script>
            (function () {
              var sel = document.getElementById("dgLpLangSelect");
              if (!sel) return;
              sel.addEventListener("change", function () {
                sel.form && sel.form.submit();
              });
            })();
          </script>
        </div>
        <a class="btn btn-sm btn-dark" href="login"><?= htmlspecialchars(t("landing.nav_sign_in"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></a>
      </div>
    </div>
  </header>

  <section class="dg-lp-hero" aria-labelledby="dgLpHeroTitle">
    <div class="dg-lp-hero-inner">
      <div class="dg-lp-hero-copy">
        <?php if ($loginRequired): ?>
          <div class="alert alert-warning border-0 shadow-sm mb-3" role="status">
            <?= htmlspecialchars(t("landing.sign_in_to_continue"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>
          </div>
        <?php endif; ?>

        <div class="dg-lp-eyebrow"><?= htmlspecialchars(t("login.brand_subtitle"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></div>
        <h1 class="dg-lp-title" id="dgLpHeroTitle"><?= htmlspecialchars(t("landing.title"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></h1>
        <p class="dg-lp-lead"><?= htmlspecialchars(t("landing.lead"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></p>

        <p class="dg-lp-note">
          <span class="dg-lp-note-mark" aria-hidden="true">&#9432;</span>
          <span><?= htmlspecialchars(t("landing.hero_note"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></span>
        </p>

        <div class="dg-lp-cta-row">
          <a class="btn btn-dark px-4" href="login"><?= htmlspecialchars(t("landing.cta_sign_in"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></a>
          <a class="btn btn-outline-dark px-4" href="#dg-landing-modules-heading"><?= htmlspecialchars(t("landing.cta_learn_more"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></a>
        </div>

        <div class="dg-lp-social">
          <span class="dg-lp-social-chip"><?= htmlspecialchars(t("landing.social_chip_1"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></span>
          <span class="dg-lp-social-chip"><?= htmlspecialchars(t("landing.social_chip_2"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></span>
          <span class="dg-lp-social-chip"><?= htmlspecialchars(t("landing.social_chip_3"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></span>
        </div>
      </div>

      <div class="dg-lp-hero-media" aria-hidden="false">
        <div class="dg-lp-media-badge">
          <span class="dg-lp-media-dot" aria-hidden="true"></span>
          <?= htmlspecialchars(t("landing.media_badge"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>
        </div>
        <div class="dg-lp-media-card">
          <img class="dg-lp-media-img" src="<?= h_lp(base_url("assets/landing-hero.svg")) ?>" alt="<?= htmlspecialchars(t("landing.media_alt"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>">
        </div>
      </div>
    </div>
  </section>

  <div class="d-md-none mt-3">
    <form method="post" action="<?= h_lp(base_url("set-language")) ?>" class="d-inline-flex align-items-center gap-2 flex-wrap dg-lang-switch">
      <?= csrf_field() ?>
      <input type="hidden" name="next" value="<?= h_lp(i18n_current_route_segment()) ?>" />
      <label class="small mb-0 text-muted" for="dgLpLangSelectMobile"><?= h_lp(t("layout.language")) ?></label>
      <select class="form-select form-select-sm dg-lang-select" name="lang" id="dgLpLangSelectMobile" aria-label="<?= h_lp(t("layout.language")) ?>">
        <?php foreach (i18n_supported_locales() as $code => $label): ?>
          <option value="<?= h_lp($code) ?>"<?= i18n_locale() === $code ? " selected" : "" ?>><?= h_lp($label) ?></option>
        <?php endforeach; ?>
      </select>
      <noscript><button type="submit" class="btn btn-sm btn-outline-secondary"><?= h_lp(t("layout.language")) ?></button></noscript>
    </form>
    <script>
      (function () {
        var sel = document.getElementById("dgLpLangSelectMobile");
        if (!sel) return;
        sel.addEventListener("change", function () {
          sel.form && sel.form.submit();
        });
      })();
    </script>
  </div>
</div>

<div class="dg-lp-sections">
  <section class="dg-lp-card" aria-labelledby="dg-landing-why-heading">
    <h2 id="dg-landing-why-heading" class="h4 dg-landing-section-title mb-3"><?= htmlspecialchars(t("landing.section_why_title"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></h2>
    <p class="dg-landing-section-text mb-3"><?= htmlspecialchars(t("landing.section_why_p1"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></p>
    <p class="dg-landing-section-text mb-0"><?= htmlspecialchars(t("landing.section_why_p2"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></p>
  </section>

  <section class="dg-lp-card" aria-labelledby="dg-landing-audience-heading">
    <h2 id="dg-landing-audience-heading" class="h4 dg-landing-section-title mb-2"><?= htmlspecialchars(t("landing.section_audience_title"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></h2>
    <p class="dg-landing-section-lead mb-4"><?= htmlspecialchars(t("landing.section_audience_lead"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></p>
    <div class="row g-3">
      <div class="col-md-4">
        <div class="dg-landing-audience-card">
          <h3 class="h6 dg-landing-audience-card-title mb-2"><?= htmlspecialchars(t("landing.audience_teacher_title"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></h3>
          <p class="dg-landing-section-text mb-0 small"><?= htmlspecialchars(t("landing.audience_teacher_body"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></p>
        </div>
      </div>
      <div class="col-md-4">
        <div class="dg-landing-audience-card">
          <h3 class="h6 dg-landing-audience-card-title mb-2"><?= htmlspecialchars(t("landing.audience_counselor_title"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></h3>
          <p class="dg-landing-section-text mb-0 small"><?= htmlspecialchars(t("landing.audience_counselor_body"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></p>
        </div>
      </div>
      <div class="col-md-4">
        <div class="dg-landing-audience-card">
          <h3 class="h6 dg-landing-audience-card-title mb-2"><?= htmlspecialchars(t("landing.audience_admin_title"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></h3>
          <p class="dg-landing-section-text mb-0 small"><?= htmlspecialchars(t("landing.audience_admin_body"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></p>
        </div>
      </div>
    </div>
  </section>

  <section class="dg-lp-card" aria-labelledby="dg-landing-how-heading">
    <h2 id="dg-landing-how-heading" class="h4 dg-landing-section-title mb-4"><?= htmlspecialchars(t("landing.section_how_title"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></h2>
    <ol class="dg-landing-steps list-unstyled mb-0">
      <li class="dg-landing-step">
        <span class="dg-landing-step-num" aria-hidden="true">1</span>
        <div>
          <h3 class="h6 dg-landing-step-title mb-1"><?= htmlspecialchars(t("landing.how_step1_title"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></h3>
          <p class="dg-landing-section-text mb-0 small"><?= htmlspecialchars(t("landing.how_step1_body"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></p>
        </div>
      </li>
      <li class="dg-landing-step">
        <span class="dg-landing-step-num" aria-hidden="true">2</span>
        <div>
          <h3 class="h6 dg-landing-step-title mb-1"><?= htmlspecialchars(t("landing.how_step2_title"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></h3>
          <p class="dg-landing-section-text mb-0 small"><?= htmlspecialchars(t("landing.how_step2_body"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></p>
        </div>
      </li>
      <li class="dg-landing-step">
        <span class="dg-landing-step-num" aria-hidden="true">3</span>
        <div>
          <h3 class="h6 dg-landing-step-title mb-1"><?= htmlspecialchars(t("landing.how_step3_title"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></h3>
          <p class="dg-landing-section-text mb-0 small"><?= htmlspecialchars(t("landing.how_step3_body"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></p>
        </div>
      </li>
      <li class="dg-landing-step mb-0">
        <span class="dg-landing-step-num" aria-hidden="true">4</span>
        <div>
          <h3 class="h6 dg-landing-step-title mb-1"><?= htmlspecialchars(t("landing.how_step4_title"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></h3>
          <p class="dg-landing-section-text mb-0 small"><?= htmlspecialchars(t("landing.how_step4_body"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></p>
        </div>
      </li>
    </ol>
  </section>

  <section class="dg-lp-card" aria-labelledby="dg-landing-modules-heading">
    <h2 id="dg-landing-modules-heading" class="h4 dg-landing-section-title mb-2"><?= htmlspecialchars(t("landing.section_modules_title"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></h2>
    <p class="dg-landing-section-lead mb-4"><?= htmlspecialchars(t("landing.section_modules_lead"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></p>
    <div class="row g-3">
      <div class="col-md-6">
        <div class="dg-landing-audience-card">
          <h3 class="h6 dg-landing-audience-card-title mb-2"><?= htmlspecialchars(t("landing.module_1_title"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></h3>
          <p class="dg-landing-section-text mb-0 small"><?= htmlspecialchars(t("landing.module_1_body"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></p>
        </div>
      </div>
      <div class="col-md-6">
        <div class="dg-landing-audience-card">
          <h3 class="h6 dg-landing-audience-card-title mb-2"><?= htmlspecialchars(t("landing.module_2_title"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></h3>
          <p class="dg-landing-section-text mb-0 small"><?= htmlspecialchars(t("landing.module_2_body"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></p>
        </div>
      </div>
      <div class="col-md-6">
        <div class="dg-landing-audience-card">
          <h3 class="h6 dg-landing-audience-card-title mb-2"><?= htmlspecialchars(t("landing.module_3_title"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></h3>
          <p class="dg-landing-section-text mb-0 small"><?= htmlspecialchars(t("landing.module_3_body"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></p>
        </div>
      </div>
      <div class="col-md-6">
        <div class="dg-landing-audience-card">
          <h3 class="h6 dg-landing-audience-card-title mb-2"><?= htmlspecialchars(t("landing.module_4_title"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></h3>
          <p class="dg-landing-section-text mb-0 small"><?= htmlspecialchars(t("landing.module_4_body"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></p>
        </div>
      </div>
      <div class="col-12">
        <div class="dg-landing-audience-card">
          <h3 class="h6 dg-landing-audience-card-title mb-2"><?= htmlspecialchars(t("landing.module_5_title"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></h3>
          <p class="dg-landing-section-text mb-0 small"><?= htmlspecialchars(t("landing.module_5_body"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></p>
        </div>
      </div>
    </div>
  </section>

  <section class="dg-lp-card" aria-labelledby="dg-landing-faq-heading">
    <h2 id="dg-landing-faq-heading" class="h4 dg-landing-section-title mb-4"><?= htmlspecialchars(t("landing.section_faq_title"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></h2>
    <div class="accordion" id="dgLandingFaq">
      <div class="accordion-item">
        <h3 class="accordion-header" id="dgFaqH1">
          <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#dgFaqC1" aria-expanded="false" aria-controls="dgFaqC1">
            <?= htmlspecialchars(t("landing.faq_q1"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>
          </button>
        </h3>
        <div id="dgFaqC1" class="accordion-collapse collapse" aria-labelledby="dgFaqH1" data-bs-parent="#dgLandingFaq">
          <div class="accordion-body small">
            <?= htmlspecialchars(t("landing.faq_a1"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>
          </div>
        </div>
      </div>
      <div class="accordion-item">
        <h3 class="accordion-header" id="dgFaqH2">
          <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#dgFaqC2" aria-expanded="false" aria-controls="dgFaqC2">
            <?= htmlspecialchars(t("landing.faq_q2"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>
          </button>
        </h3>
        <div id="dgFaqC2" class="accordion-collapse collapse" aria-labelledby="dgFaqH2" data-bs-parent="#dgLandingFaq">
          <div class="accordion-body small">
            <?= htmlspecialchars(t("landing.faq_a2"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>
          </div>
        </div>
      </div>
      <div class="accordion-item">
        <h3 class="accordion-header" id="dgFaqH3">
          <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#dgFaqC3" aria-expanded="false" aria-controls="dgFaqC3">
            <?= htmlspecialchars(t("landing.faq_q3"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>
          </button>
        </h3>
        <div id="dgFaqC3" class="accordion-collapse collapse" aria-labelledby="dgFaqH3" data-bs-parent="#dgLandingFaq">
          <div class="accordion-body small">
            <?= htmlspecialchars(t("landing.faq_a3"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>
          </div>
        </div>
      </div>
      <div class="accordion-item">
        <h3 class="accordion-header" id="dgFaqH4">
          <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#dgFaqC4" aria-expanded="false" aria-controls="dgFaqC4">
            <?= htmlspecialchars(t("landing.faq_q4"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>
          </button>
        </h3>
        <div id="dgFaqC4" class="accordion-collapse collapse" aria-labelledby="dgFaqH4" data-bs-parent="#dgLandingFaq">
          <div class="accordion-body small">
            <?= htmlspecialchars(t("landing.faq_a4"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>
          </div>
        </div>
      </div>
      <div class="accordion-item">
        <h3 class="accordion-header" id="dgFaqH5">
          <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#dgFaqC5" aria-expanded="false" aria-controls="dgFaqC5">
            <?= htmlspecialchars(t("landing.faq_q5"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>
          </button>
        </h3>
        <div id="dgFaqC5" class="accordion-collapse collapse" aria-labelledby="dgFaqH5" data-bs-parent="#dgLandingFaq">
          <div class="accordion-body small">
            <?= htmlspecialchars(t("landing.faq_a5"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>
          </div>
        </div>
      </div>
      <div class="accordion-item">
        <h3 class="accordion-header" id="dgFaqH6">
          <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#dgFaqC6" aria-expanded="false" aria-controls="dgFaqC6">
            <?= htmlspecialchars(t("landing.faq_q6"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>
          </button>
        </h3>
        <div id="dgFaqC6" class="accordion-collapse collapse" aria-labelledby="dgFaqH6" data-bs-parent="#dgLandingFaq">
          <div class="accordion-body small">
            <?= htmlspecialchars(t("landing.faq_a6"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>
          </div>
        </div>
      </div>
    </div>
  </section>

  <section class="dg-lp-card dg-lp-card-legal" aria-labelledby="dg-landing-legal-heading">
    <h2 id="dg-landing-legal-heading" class="h4 dg-landing-section-title mb-2"><?= htmlspecialchars(t("landing.section_legal_title"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></h2>
    <p class="dg-landing-section-lead mb-4"><?= htmlspecialchars(t("landing.section_legal_lead"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></p>
    <div class="row g-3">
      <div class="col-md-6">
        <article class="dg-legal-tile dg-legal-tile--privacy h-100">
          <div class="dg-legal-tile-icon" aria-hidden="true">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" focusable="false">
              <path d="M12 2L4 5.5V11.5C4 16.55 7.16 21.35 12 23C16.84 21.35 20 16.55 20 11.5V5.5L12 2Z" stroke="currentColor" stroke-width="1.75" stroke-linejoin="round"/>
              <path d="M9.5 12L11 13.5L14.5 10" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
          </div>
          <h3 class="h6 dg-legal-tile-title mb-2"><?= htmlspecialchars(t("landing.legal_card_privacy_title"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></h3>
          <p class="dg-legal-tile-body mb-3"><?= htmlspecialchars(t("landing.legal_card_privacy_desc"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></p>
          <div class="dg-legal-tile-actions mt-auto">
            <a class="btn btn-sm btn-warning text-dark fw-semibold" href="privacy-notice"><?= htmlspecialchars(t("landing.legal_card_read_full"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></a>
            <button type="button" class="btn btn-sm btn-outline-light" data-bs-toggle="modal" data-bs-target="#dgPrivacyModal"><?= htmlspecialchars(t("landing.legal_card_open_modal"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></button>
          </div>
        </article>
      </div>
      <div class="col-md-6">
        <article class="dg-legal-tile dg-legal-tile--terms h-100">
          <div class="dg-legal-tile-icon" aria-hidden="true">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" focusable="false">
              <path d="M7 3H17C18.1 3 19 3.9 19 5V21L12 17.5L5 21V5C5 3.9 5.9 3 7 3Z" stroke="currentColor" stroke-width="1.75" stroke-linejoin="round"/>
              <path d="M9 8H15M9 12H15" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"/>
            </svg>
          </div>
          <h3 class="h6 dg-legal-tile-title mb-2"><?= htmlspecialchars(t("landing.legal_card_terms_title"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></h3>
          <p class="dg-legal-tile-body mb-3"><?= htmlspecialchars(t("landing.legal_card_terms_desc"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></p>
          <div class="dg-legal-tile-actions mt-auto">
            <a class="btn btn-sm btn-warning text-dark fw-semibold" href="terms"><?= htmlspecialchars(t("landing.legal_card_read_full"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></a>
            <button type="button" class="btn btn-sm btn-outline-light" data-bs-toggle="modal" data-bs-target="#dgTermsModal"><?= htmlspecialchars(t("landing.legal_card_open_modal"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></button>
          </div>
        </article>
      </div>
    </div>
  </section>

  <section class="dg-lp-card" aria-labelledby="dg-landing-trust-heading">
    <h2 id="dg-landing-trust-heading" class="h4 dg-landing-section-title mb-3"><?= htmlspecialchars(t("landing.section_trust_title"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></h2>
    <ul class="dg-landing-trust-list mb-4">
      <li><?= htmlspecialchars(t("landing.trust_li_1"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></li>
      <li><?= htmlspecialchars(t("landing.trust_li_2"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></li>
      <li><?= htmlspecialchars(t("landing.trust_li_3"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></li>
      <li><?= htmlspecialchars(t("landing.trust_li_4"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></li>
      <li class="mb-0"><?= htmlspecialchars(t("landing.trust_li_5"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></li>
    </ul>
  </section>

  <section class="dg-lp-card dg-lp-card-cta text-center" aria-labelledby="dg-landing-cta-heading">
    <h2 id="dg-landing-cta-heading" class="h4 dg-landing-section-title mb-3"><?= htmlspecialchars(t("landing.section_cta_title"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></h2>
    <p class="dg-landing-section-lead mb-2 mx-auto dg-landing-cta-narrow"><?= htmlspecialchars(t("landing.section_cta_lead"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></p>
    <p class="dg-landing-section-text small mb-4 mx-auto dg-landing-cta-narrow"><?= htmlspecialchars(t("landing.cta_secondary_hint"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></p>
    <a class="btn btn-warning text-dark fw-semibold px-5" href="login"><?= htmlspecialchars(t("landing.cta_sign_in"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></a>
  </section>

  <footer class="dg-lp-footer">
    <div class="dg-lp-footer-inner">
      <div class="text-muted small">
        <?= htmlspecialchars((string)($config["app"]["name"] ?? "Drop Guard"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>
        <span aria-hidden="true">•</span>
        <?= htmlspecialchars(t("login.brand_subtitle"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>
      </div>
      <div class="d-flex flex-wrap justify-content-center align-items-center gap-2 gap-md-3 small dg-lp-footer-legal">
        <a class="dg-lp-footer-legal-btn" href="privacy-notice"><?= htmlspecialchars(t("layout.legal_privacy"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></a>
        <span class="text-muted" aria-hidden="true">•</span>
        <a class="dg-lp-footer-legal-btn" href="terms"><?= htmlspecialchars(t("layout.legal_terms"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></a>
      </div>
    </div>
  </footer>
</div>
<?php
$content = ob_get_clean();
$dg_body_class = "dg-landing-body";
require __DIR__ . "/_layout.php";
