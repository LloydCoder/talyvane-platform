<?php
declare(strict_types=1);

/** Persist applicant response provenance and idempotency without creating a synthetic user identity. */
return [
    "ALTER TABLE model_assignments
        ADD COLUMN applicant_response_version VARCHAR(80) NULL AFTER model_decline_evidence_reference,
        ADD COLUMN applicant_response_request_fingerprint CHAR(64) NULL AFTER applicant_response_version,
        ADD COLUMN applicant_response_terms_hash CHAR(64) NULL AFTER applicant_response_request_fingerprint,
        ADD UNIQUE KEY model_assignment_applicant_response_request_unique (applicant_response_request_fingerprint)",
];
