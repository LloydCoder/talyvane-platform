<?php
declare(strict_types=1);

/** Key-destruction retention, append-only case evidence and idempotent notification delivery. */
return [
    "ALTER TABLE safeguarding_cases
        MODIFY reporter_user_id CHAR(36) NULL,
        MODIFY encrypted_case_key MEDIUMTEXT NULL,
        MODIFY summary_ciphertext MEDIUMTEXT NULL,
        MODIFY details_ciphertext MEDIUMTEXT NULL,
        ADD COLUMN closed_at DATETIME NULL AFTER retention_due_at,
        ADD COLUMN retention_applied_at DATETIME NULL AFTER closed_at,
        ADD COLUMN retention_policy_version VARCHAR(64) NULL AFTER retention_applied_at,
        ADD KEY safeguarding_retention_due_idx (status,legal_hold,retention_due_at,retention_applied_at)",
    "ALTER TABLE safeguarding_case_events
        MODIFY actor_user_id CHAR(36) NULL,
        ADD COLUMN actor_kind VARCHAR(16) NOT NULL DEFAULT 'user' AFTER actor_user_id",
    "ALTER TABLE safeguarding_notification_outbox
        ADD COLUMN recipient_fingerprint CHAR(64) NULL AFTER recipient_ciphertext,
        ADD COLUMN dedupe_key CHAR(64) NULL AFTER recipient_fingerprint,
        ADD COLUMN claim_token CHAR(36) NULL AFTER available_at,
        ADD COLUMN claimed_at DATETIME NULL AFTER claim_token,
        ADD COLUMN is_external TINYINT(1) NOT NULL DEFAULT 0 AFTER status,
        ADD COLUMN last_attempted_at DATETIME NULL AFTER claimed_at,
        ADD UNIQUE KEY safeguarding_outbox_dedupe_unique (dedupe_key),
        ADD KEY safeguarding_outbox_claim_idx (status,available_at,claimed_at)",
    "CREATE TRIGGER safeguarding_case_events_reject_update
     BEFORE UPDATE ON safeguarding_case_events
     FOR EACH ROW
     SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Safeguarding case events are append-only'",
    "CREATE TRIGGER safeguarding_case_events_reject_delete
     BEFORE DELETE ON safeguarding_case_events
     FOR EACH ROW
     SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Safeguarding case events are append-only'",
    "CREATE TRIGGER safeguarding_case_access_events_reject_update
     BEFORE UPDATE ON safeguarding_case_access_events
     FOR EACH ROW
     SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Safeguarding access events are append-only'",
    "CREATE TRIGGER safeguarding_case_access_events_reject_delete
     BEFORE DELETE ON safeguarding_case_access_events
     FOR EACH ROW
     SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Safeguarding access events are append-only'",
    "CREATE TRIGGER safeguarding_legal_hold_events_reject_update
     BEFORE UPDATE ON safeguarding_legal_hold_events
     FOR EACH ROW
     SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Safeguarding legal-hold events are append-only'",
    "CREATE TRIGGER safeguarding_legal_hold_events_reject_delete
     BEFORE DELETE ON safeguarding_legal_hold_events
     FOR EACH ROW
     SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Safeguarding legal-hold events are append-only'"
];
