<?php
declare(strict_types=1);

/**
 * Full-page legal document shell.
 *
 * @var string $dgLegalDoc 'privacy' | 'terms'
 */

require_once __DIR__ . "/../../../app/auth.php";
require_once __DIR__ . "/../../../app/url.php";
require_once __DIR__ . "/../../../app/i18n.php";

$config = app_config();
$isPrivacy = ($dgLegalDoc ?? "") === "privacy";
$titleKey = $isPrivacy ? "legal.privacy_title" : "legal.terms_title";
$otherHref = $isPrivacy ? "terms" : "privacy-notice";
$otherTitleKey = $isPrivacy ? "legal.terms_title" : "legal.privacy_title";
$dgLegalBodyPartial = $isPrivacy ? "legal_privacy_body.php" : "legal_terms_body.php";
$dgLegalToc = require __DIR__ . "/legal_toc.php";

function h_legal_page(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");
}
?>
<div class="dg-legal-page">
  <header class="dg-legal-page-nav" aria-label="<?= h_legal_page(t("legal.page_nav_aria")) ?>">
    <div class="dg-legal-page-nav-inner">
      <a class="dg-legal-page-brand" href="landing">
        <span class="dg-legal-page-brand-dot" aria-hidden="true"></span>
        <span><?= h_legal_page((string)($config["app"]["name"] ?? "Drop Guard")) ?></span>
      </a>
      <div class="dg-legal-page-nav-actions">
        <?php $dgLangForceDark = true; require __DIR__ . "/language_switcher.php"; unset($dgLangForceDark); ?>
        <a class="btn btn-sm btn-outline-light dg-legal-page-back" href="landing"><?= h_legal_page(t("legal.page_back_landing")) ?></a>
        <a class="btn btn-sm btn-warning text-dark fw-semibold" href="login"><?= h_legal_page(t("legal.page_sign_in")) ?></a>
      </div>
    </div>
  </header>

  <article class="dg-legal-page-sheet" aria-labelledby="dgLegalPageTitle">
    <header class="dg-legal-page-hero<?= $isPrivacy ? " dg-legal-page-hero--privacy" : " dg-legal-page-hero--terms" ?>">
      <div class="dg-legal-page-hero-icon" aria-hidden="true">
        <?php if ($isPrivacy): ?>
          <svg width="28" height="28" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" focusable="false">
            <path d="M12 2L4 5.5V11.5C4 16.55 7.16 21.35 12 23C16.84 21.35 20 16.55 20 11.5V5.5L12 2Z" stroke="currentColor" stroke-width="1.75" stroke-linejoin="round"/>
            <path d="M9.5 12L11 13.5L14.5 10" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
        <?php else: ?>
          <svg width="28" height="28" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" focusable="false">
            <path d="M7 3H17C18.1 3 19 3.9 19 5V21L12 17.5L5 21V5C5 3.9 5.9 3 7 3Z" stroke="currentColor" stroke-width="1.75" stroke-linejoin="round"/>
            <path d="M9 8H15M9 12H15" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"/>
          </svg>
        <?php endif; ?>
      </div>
      <div class="dg-legal-page-hero-copy">
        <div class="dg-legal-page-badges">
          <span class="dg-legal-badge dg-legal-badge--gold"><?= h_legal_page(t("legal.ra_badge")) ?></span>
          <span class="dg-legal-badge"><?= h_legal_page(t("login.brand_subtitle")) ?></span>
        </div>
        <h1 class="dg-legal-page-title" id="dgLegalPageTitle"><?= h_legal_page(t($titleKey)) ?></h1>
        <p class="dg-legal-page-subtitle mb-0"><?= h_legal_page(t("legal.modal_subtitle")) ?></p>
      </div>
      <div class="dg-legal-page-toolbar">
        <a class="dg-legal-page-toolbar-link" href="<?= h_legal_page($otherHref) ?>">
          <?= h_legal_page(t("legal.page_switch_to")) ?> <?= h_legal_page(t($otherTitleKey)) ?> &rarr;
        </a>
        <button type="button" class="btn btn-sm btn-outline-secondary dg-legal-page-print" onclick="window.print()">
          <?= h_legal_page(t("legal.page_print")) ?>
        </button>
      </div>
    </header>

    <?php if ($dgLegalToc !== []): ?>
      <nav class="dg-legal-toc" aria-label="<?= h_legal_page(t("legal.toc_aria")) ?>">
        <div class="dg-legal-toc-label"><?= h_legal_page(t("legal.toc_label")) ?></div>
        <div class="dg-legal-toc-track">
          <?php foreach ($dgLegalToc as $item): ?>
            <a class="dg-legal-toc-pill" href="#<?= h_legal_page((string)$item["id"]) ?>"><?= h_legal_page((string)$item["label"]) ?></a>
          <?php endforeach; ?>
        </div>
      </nav>
    <?php endif; ?>

    <div class="dg-legal-page-body">
      <?php if (i18n_locale() !== "en"): ?>
        <p class="dg-legal-locale-note"><?= h_legal_page(t("legal.locale_note")) ?></p>
      <?php endif; ?>
      <?php require __DIR__ . "/" . $dgLegalBodyPartial; ?>
    </div>

    <footer class="dg-legal-page-foot">
      <a class="btn btn-outline-secondary btn-sm" href="landing"><?= h_legal_page(t("legal.page_back_landing")) ?></a>
      <a class="btn btn-dark btn-sm" href="login"><?= h_legal_page(t("legal.page_sign_in")) ?></a>
    </footer>
  </article>
</div>
