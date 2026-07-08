<?php
declare(strict_types=1);
$dgLangDark = isset($dgLangForceDark) ? (bool)$dgLangForceDark : (bool) current_user();
?>
<form method="post" action="<?= h(base_url("set-language")) ?>" class="d-inline-flex align-items-center gap-2 flex-wrap dg-lang-switch">
  <?= csrf_field() ?>
  <input type="hidden" name="next" value="<?= h(i18n_current_route_segment()) ?>" />
  <label class="small mb-0 <?= $dgLangDark ? "text-white-50" : "text-muted" ?>" for="dgLangSelect"><?= h(t("layout.language")) ?></label>
  <select class="form-select form-select-sm dg-lang-select<?= $dgLangDark ? " dg-lang-select-dark" : "" ?>" name="lang" id="dgLangSelect" aria-label="<?= h(t("layout.language")) ?>">
    <?php foreach (i18n_supported_locales() as $code => $label): ?>
      <option value="<?= h($code) ?>"<?= i18n_locale() === $code ? " selected" : "" ?>><?= h($label) ?></option>
    <?php endforeach; ?>
  </select>
  <noscript><button type="submit" class="btn btn-sm <?= $dgLangDark ? "btn-outline-light" : "btn-outline-secondary" ?>"><?= h(t("layout.language")) ?></button></noscript>
</form>
<script>
  (function () {
    var sel = document.getElementById("dgLangSelect");
    if (!sel) return;
    sel.addEventListener("change", function () {
      sel.form && sel.form.submit();
    });
  })();
</script>
