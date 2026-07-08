<?php
declare(strict_types=1);

/** @var string $staffRole */

$staffRole = trim((string)($staffRole ?? ""));
if ($staffRole === "") {
    return;
}
?>
<span class="<?= htmlspecialchars(i18n_role_badge_class($staffRole), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>">
  <?= htmlspecialchars(i18n_role_label($staffRole), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>
</span>
