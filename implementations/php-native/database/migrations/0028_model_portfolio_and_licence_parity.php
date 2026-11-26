<?php
declare(strict_types=1);

/** Bring private model portfolio assets and purpose-specific image licences to Laravel parity. */
return [
    "ALTER TABLE model_portfolio_assets
        MODIFY private_storage_key VARCHAR(500) NULL,
        ADD COLUMN uploaded_by CHAR(36) NULL AFTER application_id,
        ADD COLUMN width INT UNSIGNED NULL AFTER mime_type,
        ADD COLUMN height INT UNSIGNED NULL AFTER width,
        ADD COLUMN status VARCHAR(24) NOT NULL DEFAULT 'pending_review' AFTER review_status,
        ADD COLUMN source_description TEXT NULL AFTER status,
        ADD COLUMN consent_scope TEXT NULL AFTER source_description,
        ADD COLUMN rights_evidence_reference TEXT NULL AFTER consent_scope,
        ADD COLUMN consent_evidence_reference TEXT NULL AFTER rights_evidence_reference,
        ADD COLUMN consent_policy_version VARCHAR(80) NULL AFTER consent_evidence_reference,
        ADD COLUMN consent_recorded_at DATETIME NULL AFTER consent_policy_version,
        ADD COLUMN consent_withdrawn_at DATETIME NULL AFTER consent_recorded_at,
        ADD COLUMN withdrawal_evidence_reference TEXT NULL AFTER consent_withdrawn_at,
        ADD COLUMN withdrawal_reason TEXT NULL AFTER withdrawal_evidence_reference,
        ADD COLUMN review_note TEXT NULL AFTER rejected_reason,
        ADD COLUMN updated_at DATETIME NULL AFTER created_at,
        ADD UNIQUE KEY model_portfolio_application_sha_unique (application_id,sha256),
        ADD CONSTRAINT model_portfolio_uploader_fk FOREIGN KEY (uploaded_by) REFERENCES users(id)",
    "UPDATE model_portfolio_assets
        SET status = CASE
            WHEN withdrawn_at IS NOT NULL THEN 'withdrawn'
            WHEN review_status = 'approved' THEN 'approved'
            WHEN review_status = 'rejected' THEN 'rejected'
            ELSE 'pending_review'
        END",
    "ALTER TABLE model_image_licences
        ADD COLUMN application_id CHAR(36) NULL AFTER id,
        ADD COLUMN licensee_name TEXT NULL AFTER purpose,
        ADD COLUMN licensee_reference TEXT NULL AFTER licensee_name,
        ADD COLUMN channels JSON NULL AFTER distribution_channel,
        ADD COLUMN purpose_details TEXT NULL AFTER channels,
        ADD COLUMN starts_on DATE NULL AFTER valid_from,
        ADD COLUMN ends_on DATE NULL AFTER starts_on,
        ADD COLUMN exclusivity TINYINT(1) NOT NULL DEFAULT 0 AFTER ends_on,
        ADD COLUMN compensation_amount DECIMAL(20,4) NULL AFTER exclusivity,
        ADD COLUMN currency_code CHAR(3) NULL AFTER compensation_amount,
        ADD COLUMN licence_policy_version VARCHAR(80) NULL AFTER currency_code,
        ADD COLUMN activated_by CHAR(36) NULL AFTER created_by,
        ADD COLUMN activated_at DATETIME NULL AFTER activated_by,
        ADD COLUMN execution_evidence_reference TEXT NULL AFTER external_execution_reference,
        ADD COLUMN model_consent_evidence_reference TEXT NULL AFTER execution_evidence_reference,
        ADD COLUMN revoked_by CHAR(36) NULL AFTER revoked_at,
        ADD COLUMN revocation_reason TEXT NULL AFTER revoked_by,
        ADD COLUMN expired_at DATETIME NULL AFTER revocation_reason,
        ADD COLUMN updated_at DATETIME NULL AFTER created_at",
    "UPDATE model_image_licences l
        INNER JOIN model_portfolio_assets a ON a.id = l.portfolio_asset_id
        SET l.application_id = a.application_id,
            l.starts_on = DATE(l.valid_from),
            l.ends_on = COALESCE(DATE(l.valid_until),DATE(l.valid_from))",
    "ALTER TABLE model_image_licences
        MODIFY application_id CHAR(36) NOT NULL,
        MODIFY starts_on DATE NOT NULL,
        MODIFY ends_on DATE NOT NULL,
        ADD KEY model_image_licence_application_status_idx (application_id,status),
        ADD KEY model_image_licence_territory_purpose_idx (territory_code,purpose,status),
        ADD KEY model_image_licence_expiry_idx (status,ends_on),
        ADD CONSTRAINT model_image_licence_application_fk FOREIGN KEY (application_id) REFERENCES model_talent_applications(id),
        ADD CONSTRAINT model_image_licence_currency_fk FOREIGN KEY (currency_code) REFERENCES currencies(code),
        ADD CONSTRAINT model_image_licence_activator_fk FOREIGN KEY (activated_by) REFERENCES users(id),
        ADD CONSTRAINT model_image_licence_revoker_fk FOREIGN KEY (revoked_by) REFERENCES users(id)"
];
