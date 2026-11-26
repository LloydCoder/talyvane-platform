<?php
declare(strict_types=1);

/** Records owner-reviewed evidence for external data processors before erasure fulfilment. */
return [
    "CREATE TABLE portal_privacy_erasure_external_evidence (
        id CHAR(36) PRIMARY KEY,
        request_id CHAR(36) NOT NULL,
        system_key VARCHAR(64) NOT NULL,
        decision VARCHAR(24) NOT NULL,
        evidence_reference VARCHAR(190) NOT NULL,
        legal_basis_reference VARCHAR(190) NULL,
        retention_until DATETIME NULL,
        reason VARCHAR(500) NOT NULL,
        reviewed_by CHAR(36) NOT NULL,
        reviewed_at DATETIME NOT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        UNIQUE KEY privacy_erasure_external_evidence_unique (request_id,system_key),
        KEY privacy_erasure_external_evidence_reviewer (reviewed_by,reviewed_at),
        CONSTRAINT privacy_erasure_external_evidence_request_fk
            FOREIGN KEY (request_id) REFERENCES portal_privacy_requests(id) ON DELETE RESTRICT,
        CONSTRAINT privacy_erasure_external_evidence_reviewer_fk
            FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE RESTRICT,
        CONSTRAINT privacy_erasure_external_evidence_decision_check
            CHECK (decision IN ('cleared','retained','not_applicable'))
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
];
