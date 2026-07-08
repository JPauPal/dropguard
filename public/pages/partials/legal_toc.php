<?php
declare(strict_types=1);

/** @var string $dgLegalDoc 'privacy' | 'terms' */

return match ($dgLegalDoc ?? "") {
    "privacy" => [
        ["id" => "dg-legal-privacy-1", "label" => "1. Data collected"],
        ["id" => "dg-legal-privacy-2", "label" => "2. Sensitive data"],
        ["id" => "dg-legal-privacy-3", "label" => "3. Purpose"],
        ["id" => "dg-legal-privacy-4", "label" => "4. Processing"],
        ["id" => "dg-legal-privacy-5", "label" => "5. Controller"],
        ["id" => "dg-legal-privacy-6", "label" => "6. Retention"],
        ["id" => "dg-legal-privacy-7", "label" => "7. Security"],
        ["id" => "dg-legal-privacy-8", "label" => "8. Your rights"],
        ["id" => "dg-legal-privacy-9", "label" => "9. Contact"],
    ],
    "terms" => [
        ["id" => "dg-legal-terms-1", "label" => "1. Scope"],
        ["id" => "dg-legal-terms-2", "label" => "2. Accounts"],
        ["id" => "dg-legal-terms-3", "label" => "3. Acceptable use"],
        ["id" => "dg-legal-terms-4", "label" => "4. Prohibited"],
        ["id" => "dg-legal-terms-5", "label" => "5. Data duties"],
        ["id" => "dg-legal-terms-6", "label" => "6. Third parties"],
        ["id" => "dg-legal-terms-7", "label" => "7. IP"],
        ["id" => "dg-legal-terms-8", "label" => "8. Disclaimers"],
        ["id" => "dg-legal-terms-9", "label" => "9. Privacy / RA 10173"],
        ["id" => "dg-legal-terms-10", "label" => "10. Changes"],
        ["id" => "dg-legal-terms-11", "label" => "11. Governing law"],
        ["id" => "dg-legal-terms-12", "label" => "12. Severability"],
        ["id" => "dg-legal-terms-13", "label" => "13. Acceptance"],
    ],
    default => [],
};
