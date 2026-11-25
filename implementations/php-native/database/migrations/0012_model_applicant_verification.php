<?php
declare(strict_types=1);

/**
 * Independent, evidence-referenced adult eligibility checks for model-agency
 * applicants. No identity documents, DOBs or raw provider payloads are stored.
 */
return [
"CREATE TABLE IF NOT EXISTS model_applicant_verifications (
    id CHAR(36) NOT NULL PRIMARY KEY,
    application_id CHAR(36) NOT NULL,
    verifier_user_id CHAR(36) NOT NULL,
    verification_method VARCHAR(64) NOT NULL,
    provider_reference_hash CHAR(64) NOT NULL,
    outcome VARCHAR(24) NOT NULL,
    policy_version VARCHAR(64) NOT NULL,
    verified_at DATETIME NOT NULL,
    expires_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    KEY model_applicant_verification_current_idx (application_id, outcome, verified_at),
    CONSTRAINT model_applicant_verification_application_fk FOREIGN KEY (application_id) REFERENCES model_talent_applications(id),
    CONSTRAINT model_applicant_verification_verifier_fk FOREIGN KEY (verifier_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
];
