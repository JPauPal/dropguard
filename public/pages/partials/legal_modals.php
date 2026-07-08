<?php
declare(strict_types=1);
?>
<div class="modal fade" id="dgPrivacyModal" tabindex="-1" aria-labelledby="dgPrivacyModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-scrollable modal-xl modal-dialog-centered dg-legal-dialog">
    <div class="modal-content dg-legal-modal shadow-lg">
      <div class="modal-header dg-legal-modal-header dg-legal-modal-header--privacy">
        <div class="dg-legal-modal-header-main">
          <div class="dg-legal-modal-icon" aria-hidden="true">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" focusable="false">
              <path d="M12 2L4 5.5V11.5C4 16.55 7.16 21.35 12 23C16.84 21.35 20 16.55 20 11.5V5.5L12 2Z" stroke="currentColor" stroke-width="1.75" stroke-linejoin="round"/>
              <path d="M9.5 12L11 13.5L14.5 10" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
          </div>
          <div>
            <div class="dg-legal-modal-badges mb-2">
              <span class="dg-legal-badge dg-legal-badge--gold"><?= htmlspecialchars(t("legal.ra_badge"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></span>
            </div>
            <h2 class="modal-title fs-4 mb-1" id="dgPrivacyModalLabel"><?= htmlspecialchars(t("legal.privacy_title"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></h2>
            <div class="dg-legal-modal-subtitle"><?= htmlspecialchars(t("legal.modal_subtitle"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></div>
          </div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= htmlspecialchars(t("legal.close_aria"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>"></button>
      </div>
      <div class="modal-body dg-legal-modal-body p-0">
        <?php $dgLegalDoc = "privacy"; require __DIR__ . "/legal_toc_nav.php"; ?>
        <div class="dg-legal-modal-content px-3 px-md-4 pb-3">
          <?php if (i18n_locale() !== "en"): ?>
            <p class="dg-legal-locale-note mt-3"><?= htmlspecialchars(t("legal.locale_note"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></p>
          <?php endif; ?>
          <?php require __DIR__ . "/legal_privacy_body.php"; ?>
        </div>
      </div>
      <div class="modal-footer dg-legal-modal-footer flex-wrap gap-2">
        <a class="btn btn-outline-secondary btn-sm" href="privacy-notice" target="_blank" rel="noopener"><?= htmlspecialchars(t("legal.open_full_page"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></a>
        <button type="button" class="btn btn-outline-secondary btn-sm" data-dg-switch-modal="#dgTermsModal"><?= htmlspecialchars(t("legal.view_terms"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></button>
        <button type="button" class="btn btn-dark btn-sm" data-bs-dismiss="modal"><?= htmlspecialchars(t("legal.close"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></button>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="dgTermsModal" tabindex="-1" aria-labelledby="dgTermsModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-scrollable modal-xl modal-dialog-centered dg-legal-dialog">
    <div class="modal-content dg-legal-modal shadow-lg">
      <div class="modal-header dg-legal-modal-header dg-legal-modal-header--terms">
        <div class="dg-legal-modal-header-main">
          <div class="dg-legal-modal-icon" aria-hidden="true">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" focusable="false">
              <path d="M7 3H17C18.1 3 19 3.9 19 5V21L12 17.5L5 21V5C5 3.9 5.9 3 7 3Z" stroke="currentColor" stroke-width="1.75" stroke-linejoin="round"/>
              <path d="M9 8H15M9 12H15" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"/>
            </svg>
          </div>
          <div>
            <div class="dg-legal-modal-badges mb-2">
              <span class="dg-legal-badge dg-legal-badge--gold"><?= htmlspecialchars(t("legal.ra_badge"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></span>
            </div>
            <h2 class="modal-title fs-4 mb-1" id="dgTermsModalLabel"><?= htmlspecialchars(t("legal.terms_title"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></h2>
            <div class="dg-legal-modal-subtitle"><?= htmlspecialchars(t("legal.modal_subtitle"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></div>
          </div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= htmlspecialchars(t("legal.close_aria"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>"></button>
      </div>
      <div class="modal-body dg-legal-modal-body p-0">
        <?php $dgLegalDoc = "terms"; require __DIR__ . "/legal_toc_nav.php"; ?>
        <div class="dg-legal-modal-content px-3 px-md-4 pb-3">
          <?php if (i18n_locale() !== "en"): ?>
            <p class="dg-legal-locale-note mt-3"><?= htmlspecialchars(t("legal.locale_note"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></p>
          <?php endif; ?>
          <?php require __DIR__ . "/legal_terms_body.php"; ?>
        </div>
      </div>
      <div class="modal-footer dg-legal-modal-footer flex-wrap gap-2">
        <a class="btn btn-outline-secondary btn-sm" href="terms" target="_blank" rel="noopener"><?= htmlspecialchars(t("legal.open_full_page"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></a>
        <button type="button" class="btn btn-outline-secondary btn-sm" data-dg-switch-modal="#dgPrivacyModal"><?= htmlspecialchars(t("legal.view_privacy"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></button>
        <button type="button" class="btn btn-dark btn-sm" data-bs-dismiss="modal"><?= htmlspecialchars(t("legal.close"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></button>
      </div>
    </div>
  </div>
</div>
