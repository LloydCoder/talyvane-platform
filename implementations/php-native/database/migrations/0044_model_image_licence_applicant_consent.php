<?php
declare(strict_types=1);

/**
 * Model-originated, terms-bound consent for executed image-licence offers.
 * Existing licences remain valid as recorded; new offers require an applicant
 * decision in the OTP-verified model workspace.
 */
return [
    "ALTER TABLE model_image_licences
        ADD COLUMN consent_offered_by CHAR(36) NULL AFTER activated_at,
        ADD COLUMN consent_offered_at DATETIME NULL AFTER consent_offered_by,
        ADD COLUMN applicant_consent_decision VARCHAR(16) NULL AFTER consent_offered_at,
        ADD COLUMN applicant_consent_statement TEXT NULL AFTER applicant_consent_decision,
        ADD COLUMN applicant_consent_at DATETIME NULL AFTER applicant_consent_statement,
        ADD COLUMN applicant_consent_request_fingerprint CHAR(64) NULL AFTER applicant_consent_at,
        ADD COLUMN applicant_consent_terms_hash CHAR(64) NULL AFTER applicant_consent_request_fingerprint,
        ADD COLUMN applicant_consent_protocol_version VARCHAR(80) NULL AFTER applicant_consent_terms_hash,
        ADD COLUMN applicant_consent_evidence_hash CHAR(64) NULL AFTER applicant_consent_protocol_version,
        ADD UNIQUE KEY model_licence_applicant_consent_request_unique (applicant_consent_request_fingerprint),
        ADD KEY model_licence_consent_offer_idx (application_id,status,consent_offered_at),
        ADD CONSTRAINT model_licence_consent_offerer_fk FOREIGN KEY (consent_offered_by) REFERENCES users(id) ON DELETE RESTRICT"
];