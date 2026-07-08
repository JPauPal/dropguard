<?php
declare(strict_types=1);

require_once __DIR__ . "/db.php";

/** @return string|null Error message, or null if the pair is acceptable */
function password_change_validate_new_password_pair(string $newPassword, string $confirmPassword): ?string
{
    if ($newPassword === "" || $confirmPassword === "") {
        return "Please enter and confirm the new password.";
    }
    if ($newPassword !== $confirmPassword) {
        return "Passwords do not match.";
    }
    if (strlen($newPassword) < 8) {
        return "Password must be at least 8 characters.";
    }
    if (!preg_match('/[A-Z]/', $newPassword)) {
        return "Password must include at least 1 uppercase letter.";
    }
    if (!preg_match('/[a-z]/', $newPassword)) {
        return "Password must include at least 1 lowercase letter.";
    }
    if (!preg_match('/[0-9]/', $newPassword)) {
        return "Password must include at least 1 number.";
    }
    if (!preg_match('/[^a-zA-Z0-9]/', $newPassword)) {
        return "Password must include at least 1 special character.";
    }
    return null;
}

function password_change_insert_pending_request(int $userId, string $plainNewPassword): void
{
    ensure_password_change_requests_table();
    $hash = password_hash($plainNewPassword, PASSWORD_DEFAULT);
    $stmt = db()->prepare("INSERT INTO password_change_requests (user_id, new_password_hash) VALUES (?, ?)");
    $stmt->execute([$userId, $hash]);
}

function ensure_password_change_requests_table(): void
{
    static $done = false;
    if ($done) return;
    db()->exec(
        "CREATE TABLE IF NOT EXISTS password_change_requests (
            request_id BIGINT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            new_password_hash VARCHAR(255) NOT NULL,
            status ENUM('Pending','Approved','Rejected') NOT NULL DEFAULT 'Pending',
            requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            acted_at TIMESTAMP NULL,
            acted_by INT NULL,
            INDEX idx_pcr_status (status, requested_at),
            INDEX idx_pcr_user (user_id, requested_at),
            CONSTRAINT fk_pcr_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
            CONSTRAINT fk_pcr_acted_by FOREIGN KEY (acted_by) REFERENCES users(user_id) ON DELETE SET NULL
        ) ENGINE=InnoDB"
    );
    $done = true;
}

