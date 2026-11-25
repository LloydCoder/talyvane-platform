<?php
declare(strict_types=1);

/** Backup policy approvals and immutable restore-verification evidence. */
return [
    "CREATE TABLE IF NOT EXISTS safeguarding_backup_retention_policies (
        singleton_id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
        policy_version VARCHAR(64) NOT NULL,
        expiry_days SMALLINT UNSIGNED NOT NULL,
        encrypted_backups TINYINT(1) NOT NULL DEFAULT 1,
        enabled TINYINT(1) NOT NULL DEFAULT 1,
        approved_by CHAR(36) NOT NULL,
        approved_at DATETIME NOT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        CONSTRAINT safeguarding_backup_policy_singleton CHECK (singleton_id=1),
        CONSTRAINT safeguarding_backup_policy_approver_fk FOREIGN KEY (approved_by) REFERENCES users(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TABLE IF NOT EXISTS safeguarding_restore_verifications (
        id CHAR(36) NOT NULL PRIMARY KEY,
        backup_reference_hash CHAR(64) NOT NULL,
        manifest_sha256 CHAR(64) NOT NULL,
        policy_version VARCHAR(64) NOT NULL,
        verification_status VARCHAR(16) NOT NULL,
        active_keys_verified INT UNSIGNED NOT NULL DEFAULT 0,
        orphan_keys_destroyed INT UNSIGNED NOT NULL DEFAULT 0,
        verified_by CHAR(36) NOT NULL,
        verified_at DATETIME NOT NULL,
        created_at DATETIME NOT NULL,
        KEY safeguarding_restore_policy_idx (policy_version,verification_status,verified_at),
        CONSTRAINT safeguarding_restore_verifier_fk FOREIGN KEY (verified_by) REFERENCES users(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TABLE IF NOT EXISTS safeguarding_backup_policy_events (
        id CHAR(36) NOT NULL PRIMARY KEY,
        policy_version VARCHAR(64) NOT NULL,
        actor_user_id CHAR(36) NOT NULL,
        event_key VARCHAR(64) NOT NULL,
        expiry_days SMALLINT UNSIGNED NOT NULL,
        encrypted_backups TINYINT(1) NOT NULL,
        reason VARCHAR(500) NOT NULL,
        occurred_at DATETIME NOT NULL,
        KEY safeguarding_backup_policy_event_idx (occurred_at),
        CONSTRAINT safeguarding_backup_policy_event_actor_fk FOREIGN KEY (actor_user_id) REFERENCES users(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TRIGGER safeguarding_restore_verifications_reject_update
     BEFORE UPDATE ON safeguarding_restore_verifications
     FOR EACH ROW
     SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Safeguarding restore verifications are append-only'",
    "CREATE TRIGGER safeguarding_restore_verifications_reject_delete
     BEFORE DELETE ON safeguarding_restore_verifications
     FOR EACH ROW
     SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Safeguarding restore verifications are append-only'",
    "CREATE TRIGGER safeguarding_backup_policy_events_reject_update
     BEFORE UPDATE ON safeguarding_backup_policy_events
     FOR EACH ROW
     SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Safeguarding backup policy events are append-only'",
    "CREATE TRIGGER safeguarding_backup_policy_events_reject_delete
     BEFORE DELETE ON safeguarding_backup_policy_events
     FOR EACH ROW
     SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Safeguarding backup policy events are append-only'"
];
