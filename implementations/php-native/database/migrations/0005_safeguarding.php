<?php
declare(strict_types=1);

/** Restricted safeguarding case data; encrypted payloads and metadata-only governance. */
return [
"CREATE TABLE IF NOT EXISTS safeguarding_cases (
    id CHAR(36) NOT NULL PRIMARY KEY,
    case_reference VARCHAR(64) NOT NULL UNIQUE,
    assigned_specialist_id CHAR(36) NOT NULL,
    reporter_user_id CHAR(36) NOT NULL,
    category VARCHAR(80) NOT NULL,
    severity VARCHAR(24) NOT NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'open',
    encrypted_case_key MEDIUMTEXT NOT NULL,
    key_version VARCHAR(64) NOT NULL,
    encryption_provider VARCHAR(32) NOT NULL DEFAULT 'application',
    summary_ciphertext MEDIUMTEXT NOT NULL,
    details_ciphertext MEDIUMTEXT NOT NULL,
    subject_reference_ciphertext MEDIUMTEXT NULL,
    legal_hold TINYINT(1) NOT NULL DEFAULT 0,
    legal_hold_reason_ciphertext MEDIUMTEXT NULL,
    legal_hold_actor_id CHAR(36) NULL,
    legal_hold_at DATETIME NULL,
    retention_due_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    KEY safeguarding_assigned_status_idx (assigned_specialist_id, status, created_at),
    KEY safeguarding_governance_idx (status, severity, legal_hold, created_at),
    CONSTRAINT safeguarding_assignee_fk FOREIGN KEY (assigned_specialist_id) REFERENCES users(id),
    CONSTRAINT safeguarding_reporter_fk FOREIGN KEY (reporter_user_id) REFERENCES users(id),
    CONSTRAINT safeguarding_hold_actor_fk FOREIGN KEY (legal_hold_actor_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS safeguarding_case_events (
    id CHAR(36) NOT NULL PRIMARY KEY,
    case_id CHAR(36) NOT NULL,
    actor_user_id CHAR(36) NOT NULL,
    event_key VARCHAR(80) NOT NULL,
    from_status VARCHAR(24) NULL,
    to_status VARCHAR(24) NULL,
    encrypted_note MEDIUMTEXT NULL,
    created_at DATETIME NOT NULL,
    KEY safeguarding_case_event_idx (case_id, created_at),
    CONSTRAINT safeguarding_event_case_fk FOREIGN KEY (case_id) REFERENCES safeguarding_cases(id),
    CONSTRAINT safeguarding_event_actor_fk FOREIGN KEY (actor_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS safeguarding_case_access_events (
    id CHAR(36) NOT NULL PRIMARY KEY,
    case_id CHAR(36) NOT NULL,
    actor_user_id CHAR(36) NOT NULL,
    access_type VARCHAR(32) NOT NULL,
    request_id VARCHAR(64) NOT NULL,
    occurred_at DATETIME NOT NULL,
    KEY safeguarding_access_case_idx (case_id, occurred_at),
    KEY safeguarding_access_actor_idx (actor_user_id, occurred_at),
    CONSTRAINT safeguarding_access_case_fk FOREIGN KEY (case_id) REFERENCES safeguarding_cases(id),
    CONSTRAINT safeguarding_access_actor_fk FOREIGN KEY (actor_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS safeguarding_legal_hold_events (
    id CHAR(36) NOT NULL PRIMARY KEY,
    case_id CHAR(36) NOT NULL,
    actor_user_id CHAR(36) NOT NULL,
    action VARCHAR(16) NOT NULL,
    encrypted_reason MEDIUMTEXT NOT NULL,
    created_at DATETIME NOT NULL,
    KEY safeguarding_hold_case_idx (case_id, created_at),
    CONSTRAINT safeguarding_hold_case_fk FOREIGN KEY (case_id) REFERENCES safeguarding_cases(id),
    CONSTRAINT safeguarding_hold_event_actor_fk FOREIGN KEY (actor_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS safeguarding_retention_policies (
    category VARCHAR(80) NOT NULL PRIMARY KEY,
    retention_days INT UNSIGNED NOT NULL,
    legal_basis_reference VARCHAR(190) NOT NULL,
    policy_version VARCHAR(64) NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    updated_by CHAR(36) NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT safeguarding_retention_actor_fk FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS safeguarding_notification_outbox (
    id CHAR(36) NOT NULL PRIMARY KEY,
    case_id CHAR(36) NOT NULL,
    event_key VARCHAR(80) NOT NULL,
    recipient_ciphertext MEDIUMTEXT NOT NULL,
    payload_ciphertext MEDIUMTEXT NOT NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'pending',
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    available_at DATETIME NOT NULL,
    delivered_at DATETIME NULL,
    last_error_code VARCHAR(80) NULL,
    created_at DATETIME NOT NULL,
    KEY safeguarding_outbox_delivery_idx (status, available_at),
    CONSTRAINT safeguarding_outbox_case_fk FOREIGN KEY (case_id) REFERENCES safeguarding_cases(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
];
