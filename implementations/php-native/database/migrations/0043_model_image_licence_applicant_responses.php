<?php
declare(strict_types=1);

/** Record applicant-owned image-licence consent decisions with encrypted evidence and idempotency. */
return [
    "ALTER TABLE model_image_licences
        ADD COLUMN applicant_response_decision VARCHAR(16) NULL AFTER model_consent_evidence_reference,
        ADD COLUMN applicant_response_evidence TEXT NULL AFTER applicant_response_decision,
        ADD COLUMN applicant_response_version VARCHAR(80) NULL AFTER applicant_response_evidence,
        ADD COLUMN applicant_response_request_fingerprint CHAR(64) NULL AFTER applicant_response_version,
        ADD COLUMN applicant_response_terms_hash CHAR(64) NULL AFTER applicant_response_request_fingerprint,
        ADD COLUMN applicant_responded_at DATETIME NULL AFTER applicant_response_terms_hash,
        ADD UNIQUE KEY model_image_licence_applicant_response_request_unique (applicant_response_request_fingerprint),
        ADD CONSTRAINT model_image_licence_applicant_response_decision_check
            CHECK (applicant_response_decision IS NULL OR applicant_response_decision IN ('accepted','declined'))",
];
