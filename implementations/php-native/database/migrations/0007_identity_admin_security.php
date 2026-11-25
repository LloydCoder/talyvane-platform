<?php
declare(strict_types=1);

/** Identity lifecycle, mandatory privileged MFA and recovery evidence. */
return [
"CREATE TABLE IF NOT EXISTS email_verification_challenges (
    id CHAR(36) NOT NULL PRIMARY KEY,
    user_id CHAR(36) NOT NULL,
    email_address VARCHAR(254) NOT NULL,
    purpose VARCHAR(48) NOT NULL,
    code_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    verified_at DATETIME NULL,
    consumed_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    KEY email_challenge_user_idx (user_id, purpose, expires_at),
    CONSTRAINT email_challenge_user_fk FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS admin_mfa_credentials (
    user_id CHAR(36) NOT NULL PRIMARY KEY,
    encrypted_totp_secret MEDIUMTEXT NOT NULL,
    encryption_key_version VARCHAR(64) NOT NULL,
    enabled_at DATETIME NULL,
    last_accepted_counter BIGINT NOT NULL DEFAULT -1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT admin_mfa_user_fk FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS admin_mfa_recovery_codes (
    id CHAR(36) NOT NULL PRIMARY KEY,
    user_id CHAR(36) NOT NULL,
    code_hash VARCHAR(255) NOT NULL,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    KEY admin_recovery_user_unused_idx (user_id, used_at),
    CONSTRAINT admin_recovery_code_user_fk FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS super_admin_recovery (
    singleton_id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    super_admin_user_id CHAR(36) NOT NULL,
    recovery_email VARCHAR(254) NOT NULL,
    offline_recovery_key_hash VARCHAR(255) NOT NULL,
    recovery_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
    enrolled_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT super_admin_recovery_singleton_check CHECK (singleton_id = 1),
    CONSTRAINT super_admin_recovery_user_fk FOREIGN KEY (super_admin_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS super_admin_recovery_events (
    id CHAR(36) NOT NULL PRIMARY KEY,
    actor_user_id CHAR(36) NULL,
    event_key VARCHAR(80) NOT NULL,
    reason VARCHAR(500) NULL,
    notification_status VARCHAR(24) NULL,
    session_version_before BIGINT UNSIGNED NULL,
    session_version_after BIGINT UNSIGNED NULL,
    request_id VARCHAR(64) NOT NULL,
    created_at DATETIME NOT NULL,
    KEY super_admin_recovery_event_idx (created_at, event_key),
    CONSTRAINT super_admin_recovery_event_actor_fk FOREIGN KEY (actor_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS admin_step_up_challenges (
    id CHAR(36) NOT NULL PRIMARY KEY,
    user_id CHAR(36) NOT NULL,
    purpose VARCHAR(120) NOT NULL,
    reason VARCHAR(500) NOT NULL,
    challenge_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    consumed_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    KEY admin_stepup_user_idx (user_id, purpose, expires_at),
    CONSTRAINT admin_stepup_user_fk FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS deployment_settings (
    setting_key VARCHAR(120) NOT NULL PRIMARY KEY,
    setting_json JSON NOT NULL,
    version BIGINT UNSIGNED NOT NULL DEFAULT 1,
    updated_by CHAR(36) NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT deployment_setting_actor_fk FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS platform_secret_metadata (
    secret_key VARCHAR(120) NOT NULL PRIMARY KEY,
    secret_store VARCHAR(48) NOT NULL,
    secret_version VARCHAR(120) NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'unset',
    updated_by CHAR(36) NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT platform_secret_actor_fk FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS privacy_requests (
    id CHAR(36) NOT NULL PRIMARY KEY,
    user_id CHAR(36) NOT NULL,
    request_type VARCHAR(24) NOT NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'submitted',
    reason VARCHAR(500) NULL,
    assigned_to CHAR(36) NULL,
    completed_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    KEY privacy_request_queue_idx (status, created_at),
    CONSTRAINT privacy_request_user_fk FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT privacy_request_assignee_fk FOREIGN KEY (assigned_to) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
];
