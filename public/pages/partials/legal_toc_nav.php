<?php
declare(strict_types=1);

/** @var string $dgLegalDoc 'privacy' | 'terms' */

$dgLegalToc = require __DIR__ . "/legal_toc.php";
if ($dgLegalToc === []) {
    return;
}
?>
<nav class="dg-legal-toc dg-legal-toc--modal" aria-label="<?= htmlspecialchars(t("legal.toc_aria"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>">
  <div class="dg-legal-toc-label"><?= htmlspecialchars(t("legal.toc_label"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></div>
  <div class="dg-legal-toc-track">
    <?php foreach ($dgLegalToc as $item): ?>
      <a class="dg-legal-toc-pill" href="#<?= htmlspecialchars((string)$item["id"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>"><?= htmlspecialchars((string)$item["label"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></a>
    <?php endforeach; ?>
  </div>
</nav>
