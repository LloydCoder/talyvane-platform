<?php
declare(strict_types=1);

return [
    "CREATE TABLE IF NOT EXISTS safeguarding_retention_policy_events (
        id CHAR(36) NOT NULL PRIMARY KEY,
        category VARCHAR(80) NOT NULL,
        policy_version VARCHAR(64) NOT NULL,
        actor_user_id CHAR(36) NOT NULL,
        event_key VARCHAR(64) NOT NULL,
        retention_days INT UNSIGNED NOT NULL,
        legal_basis_reference VARCHAR(190) NOT NULL,
        enabled TINYINT(1) NOT NULL,
        reason VARCHAR(500) NOT NULL,
        occurred_at DATETIME NOT NULL,
        KEY safeguarding_retention_policy_event_idx (category,occurred_at),
        CONSTRAINT safeguarding_retention_policy_event_actor_fk FOREIGN KEY (actor_user_id) REFERENCES users(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TRIGGER safeguarding_retention_policy_events_reject_update
     BEFORE UPDATE ON safeguarding_retention_policy_events
     FOR EACH ROW
     SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Safeguarding retention policy events are append-only'",
    "CREATE TRIGGER safeguarding_retention_policy_events_reject_delete
     BEFORE DELETE ON safeguarding_retention_policy_events
     FOR EACH ROW
     SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Safeguarding retention policy events are append-only'"
];
