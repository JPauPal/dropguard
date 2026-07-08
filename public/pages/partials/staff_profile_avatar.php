<?php
declare(strict_types=1);

require_once __DIR__ . "/../../../app/user_profiles.php";

/**
 * @var string $staffInitials Kept for accessibility fallbacks (optional)
 * @var string $staffPhotoUrl Relative custom photo path, empty when using default avatar
 * @var string $staffAvatarClass Extra CSS classes (optional)
 */

$staffInitials = (string)($staffInitials ?? "");
$staffPhotoUrl = trim((string)($staffPhotoUrl ?? ""));
$staffAvatarClass = trim((string)($staffAvatarClass ?? "dg-staff-profile-avatar"));
$staffAvatarAlt = trim((string)($staffAvatarAlt ?? "Profile photo"));
$staffHasCustomPhoto = user_profile_has_custom_photo($staffPhotoUrl);
$staffDisplayPhotoUrl = user_profile_avatar_url($staffPhotoUrl);
?>
<img
  class="<?= htmlspecialchars($staffAvatarClass, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?> dg-staff-profile-photo<?= $staffHasCustomPhoto ? "" : " dg-staff-profile-photo--default" ?>"
  src="<?= htmlspecialchars($staffDisplayPhotoUrl, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>"
  alt="<?= htmlspecialchars($staffAvatarAlt, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>"
  loading="lazy"
  decoding="async"
/>
