<?php
declare(strict_types=1);

/** Verified client/companion correction and erasure request intake; fulfilment remains human-governed. */
return [
    "CREATE TABLE IF NOT EXISTS portal_privacy_requests (
        id CHAR(36) NOT NULL PRIMARY KEY,
        user_id CHAR(36) NOT NULL,
        account_type VARCHAR(16) NOT NULL,
        request_type VARCHAR(24) NOT NULL,
        status VARCHAR(24) NOT NULL DEFAULT 'pending',
        payload_ciphertext MEDIUMTEXT NULL,
        otp_hash CHAR(64) NOT NULL,
        expires_at DATETIME NOT NULL,
        attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
        verified_at DATETIME NULL,
        reviewed_by CHAR(36) NULL,
        reviewed_at DATETIME NULL,
        decision_reason VARCHAR(500) NULL,
        completed_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        KEY portal_privacy_user_history_idx (user_id, created_at),
        KEY portal_privacy_queue_idx (status, created_at),
        CONSTRAINT portal_privacy_user_fk FOREIGN KEY (user_id) REFERENCES users(id),
        CONSTRAINT portal_privacy_reviewer_fk FOREIGN KEY (reviewed_by) REFERENCES users(id),
        CONSTRAINT portal_privacy_attempts_check CHECK (attempts <= 5),
        CONSTRAINT portal_privacy_account_type_check CHECK (account_type IN ('client','companion')),
        CONSTRAINT portal_privacy_request_type_check CHECK (request_type IN ('correction','erasure')),
        CONSTRAINT portal_privacy_status_check CHECK (status IN ('pending','in_review','fulfilled','rejected','deferred','delivery_failed','superseded','locked'))
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
];
