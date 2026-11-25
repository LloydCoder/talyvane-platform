<?php
declare(strict_types=1);

/** Private-by-default companion media, consent attestations and human review evidence. */
return [
    "ALTER TABLE profile_media
        ADD COLUMN uploaded_by CHAR(36) NULL AFTER profile_id,
        ADD COLUMN width INT UNSIGNED NOT NULL DEFAULT 0 AFTER file_size,
        ADD COLUMN height INT UNSIGNED NOT NULL DEFAULT 0 AFTER width,
        ADD COLUMN rights_confirmed_at DATETIME NULL AFTER consent_grant_id,
        ADD COLUMN consent_version VARCHAR(80) NULL AFTER rights_confirmed_at,
        ADD COLUMN reviewer_adult_safety_confirmed_at DATETIME NULL AFTER reviewed_at,
        ADD COLUMN reviewer_rights_confirmed_at DATETIME NULL AFTER reviewer_adult_safety_confirmed_at,
        ADD COLUMN reviewer_consent_confirmed_at DATETIME NULL AFTER reviewer_rights_confirmed_at,
        ADD COLUMN review_note VARCHAR(1000) NULL AFTER reviewed_at,
        ADD COLUMN withdrawn_at DATETIME NULL AFTER review_note,
        ADD COLUMN withdrawn_by CHAR(36) NULL AFTER withdrawn_at,
        ADD COLUMN members_only_consent_at DATETIME NULL AFTER withdrawn_by,
        ADD COLUMN members_only_consent_version VARCHAR(80) NULL AFTER members_only_consent_at,
        ADD COLUMN members_only_consent_withdrawn_at DATETIME NULL AFTER members_only_consent_version,
        ADD COLUMN members_only_consent_withdrawn_by CHAR(36) NULL AFTER members_only_consent_withdrawn_at,
        ADD COLUMN updated_at DATETIME NULL AFTER created_at,
        ADD KEY profile_media_owner_status_idx (uploaded_by,review_status,created_at),
        ADD CONSTRAINT profile_media_uploader_fk FOREIGN KEY (uploaded_by) REFERENCES users(id),
        ADD CONSTRAINT profile_media_withdrawer_fk FOREIGN KEY (withdrawn_by) REFERENCES users(id),
        ADD CONSTRAINT profile_media_private_consent_withdrawer_fk FOREIGN KEY (members_only_consent_withdrawn_by) REFERENCES users(id)",
    "CREATE TABLE IF NOT EXISTS profile_media_consent_events (
        id CHAR(36) NOT NULL PRIMARY KEY,
        profile_media_id CHAR(36) NOT NULL,
        companion_profile_id CHAR(36) NOT NULL,
        media_reference VARCHAR(120) NOT NULL,
        actor_id CHAR(36) NULL,
        actor_email VARCHAR(254) NOT NULL,
        action VARCHAR(80) NOT NULL,
        capture_method VARCHAR(80) NOT NULL,
        consent_policy_version VARCHAR(80) NOT NULL,
        consent_statement TEXT NOT NULL,
        statement_sha256 CHAR(64) NOT NULL,
        correlation_id CHAR(36) NOT NULL,
        created_at DATETIME NOT NULL,
        KEY profile_media_consent_history_idx (profile_media_id,created_at),
        KEY profile_media_consent_profile_idx (companion_profile_id,created_at),
        CONSTRAINT profile_media_consent_media_fk FOREIGN KEY (profile_media_id) REFERENCES profile_media(id),
        CONSTRAINT profile_media_consent_profile_fk FOREIGN KEY (companion_profile_id) REFERENCES companion_profiles(id),
        CONSTRAINT profile_media_consent_actor_fk FOREIGN KEY (actor_id) REFERENCES users(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TRIGGER profile_media_consent_events_reject_update
     BEFORE UPDATE ON profile_media_consent_events
     FOR EACH ROW
     SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Profile media consent events are append-only'",
    "CREATE TRIGGER profile_media_consent_events_reject_delete
     BEFORE DELETE ON profile_media_consent_events
     FOR EACH ROW
     SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Profile media consent events are append-only'"
];
