<?php
declare(strict_types=1);

/** Give repeated adult-verification decisions a deterministic, monotonic order. */
return [
    "ALTER TABLE model_applicant_verifications
     ADD COLUMN decision_order BIGINT UNSIGNED NOT NULL AUTO_INCREMENT UNIQUE AFTER id"
];
