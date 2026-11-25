<?php
declare(strict_types=1);

/** MySQL 8 / supported MariaDB baseline; immutable once applied. */
return [
"CREATE TABLE IF NOT EXISTS users (
    id CHAR(36) NOT NULL PRIMARY KEY,
    email VARCHAR(254) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'pending',
    email_verified_at DATETIME NULL,
    first_login_required TINYINT(1) NOT NULL DEFAULT 0,
    admin_session_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
    remember_token_hash CHAR(64) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY users_email_unique (email),
    KEY users_status_idx (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS roles (
    role_key VARCHAR(48) NOT NULL PRIMARY KEY,
    label VARCHAR(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS user_roles (
    user_id CHAR(36) NOT NULL,
    role_key VARCHAR(48) NOT NULL,
    granted_by CHAR(36) NULL,
    granted_at DATETIME NOT NULL,
    PRIMARY KEY (user_id, role_key),
    CONSTRAINT user_roles_user_fk FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT user_roles_role_fk FOREIGN KEY (role_key) REFERENCES roles(role_key),
    CONSTRAINT user_roles_grantor_fk FOREIGN KEY (granted_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS feature_states (
    feature_key VARCHAR(120) NOT NULL PRIMARY KEY,
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    config_json JSON NULL,
    updated_by CHAR(36) NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT feature_states_actor_fk FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS audit_events (
    id CHAR(36) NOT NULL PRIMARY KEY,
    actor_user_id CHAR(36) NULL,
    event_key VARCHAR(190) NOT NULL,
    target_type VARCHAR(190) NOT NULL,
    target_id VARCHAR(190) NULL,
    reason VARCHAR(500) NULL,
    before_json JSON NULL,
    after_json JSON NULL,
    request_id VARCHAR(190) NOT NULL,
    policy_version VARCHAR(190) NOT NULL,
    occurred_at DATETIME NOT NULL,
    KEY audit_actor_time_idx (actor_user_id, occurred_at),
    KEY audit_target_idx (target_type, target_id),
    KEY audit_event_time_idx (event_key, occurred_at),
    CONSTRAINT audit_events_actor_fk FOREIGN KEY (actor_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS auth_challenges (
    id CHAR(36) NOT NULL PRIMARY KEY,
    user_id CHAR(36) NOT NULL,
    challenge_type VARCHAR(48) NOT NULL,
    secret_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    consumed_at DATETIME NULL,
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    KEY auth_challenge_lookup_idx (user_id, challenge_type, expires_at),
    CONSTRAINT auth_challenges_user_fk FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"INSERT IGNORE INTO roles (role_key, label) VALUES
('super_admin','Super Admin'),('owner','Owner'),('admin','Administrator'),
('finance','Finance'),('verifier','Eligibility verifier'),('safeguarding','Safeguarding specialist'),
('client','Client'),('companion','Companion')"
];
