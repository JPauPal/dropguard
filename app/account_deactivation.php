<?php
declare(strict_types=1);

require_once __DIR__ . "/db.php";

function ensure_account_deactivation_requests_table(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    db()->exec(
        "CREATE TABLE IF NOT EXISTS account_deactivation_requests (
            request_id BIGINT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            reason VARCHAR(500) NULL,
            status ENUM('Pending','Approved','Rejected','Cancelled') NOT NULL DEFAULT 'Pending',
            requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            acted_at TIMESTAMP NULL,
            acted_by INT NULL,
            INDEX idx_adr_status (status, requested_at),
            INDEX idx_adr_user (user_id, requested_at),
            CONSTRAINT fk_adr_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
            CONSTRAINT fk_adr_acted_by FOREIGN KEY (acted_by) REFERENCES users(user_id) ON DELETE SET NULL
        ) ENGINE=InnoDB"
    );
    $done = true;
}
