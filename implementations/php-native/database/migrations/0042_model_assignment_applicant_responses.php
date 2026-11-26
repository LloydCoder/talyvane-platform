<?php
declare(strict_types=1);

/** Persist applicant-originated assignment responses without treating intent as external execution evidence. */
return [
    "ALTER TABLE model_assignments
        ADD COLUMN applicant_response_status VARCHAR(40) NULL AFTER status,
        ADD COLUMN applicant_response_version VARCHAR(80) NULL AFTER applicant_response_status,
        ADD COLUMN applicant_response_at DATETIME NULL AFTER applicant_response_version,
        ADD COLUMN applicant_response_reason TEXT NULL AFTER applicant_response_at,
        ADD COLUMN applicant_response_request_fingerprint CHAR(64) NULL AFTER applicant_response_reason,
        ADD COLUMN applicant_response_terms_hash CHAR(64) NULL AFTER applicant_response_request_fingerprint,
        ADD UNIQUE KEY model_assignment_applicant_response_request_unique (applicant_response_request_fingerprint),
        ADD KEY model_assignment_applicant_response_status_idx (application_id,applicant_response_status,status),
        ADD CONSTRAINT model_assignment_applicant_response_status_check
            CHECK (applicant_response_status IS NULL OR applicant_response_status IN ('acceptance_pending_execution','accepted_executed','declined'))",
];
