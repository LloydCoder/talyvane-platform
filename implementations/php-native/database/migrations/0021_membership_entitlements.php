<?php
declare(strict_types=1);

/** Immutable membership lifecycle evidence and explicit entitlement grants. */
return [
    "ALTER TABLE memberships
        ADD COLUMN tier_name VARCHAR(120) NOT NULL DEFAULT 'Standard' AFTER plan_key,
        ADD COLUMN benefits_summary VARCHAR(1000) NOT NULL DEFAULT '' AFTER tier_name,
        ADD COLUMN assigned_by CHAR(36) NULL AFTER client_user_id,
        ADD KEY membership_assignment_history_idx (client_user_id,created_at),
        ADD CONSTRAINT membership_assigned_by_fk FOREIGN KEY (assigned_by) REFERENCES users(id)",
    "ALTER TABLE membership_grants
        ADD UNIQUE KEY membership_grant_entitlement_unique (membership_id,entitlement_key),
        ADD KEY membership_grant_actor_idx (granted_by,created_at)",
    "CREATE TABLE IF NOT EXISTS membership_events (
        id CHAR(36) NOT NULL PRIMARY KEY,
        membership_id CHAR(36) NOT NULL,
        client_user_id CHAR(36) NOT NULL,
        actor_user_id CHAR(36) NULL,
        actor_role VARCHAR(32) NOT NULL,
        event_key VARCHAR(80) NOT NULL,
        before_json JSON NULL,
        after_json JSON NULL,
        reason VARCHAR(500) NOT NULL,
        correlation_id CHAR(64) NOT NULL,
        occurred_at DATETIME NOT NULL,
        KEY membership_event_history_idx (membership_id,occurred_at),
        KEY membership_event_client_idx (client_user_id,occurred_at),
        CONSTRAINT membership_event_membership_fk FOREIGN KEY (membership_id) REFERENCES memberships(id),
        CONSTRAINT membership_event_client_fk FOREIGN KEY (client_user_id) REFERENCES users(id),
        CONSTRAINT membership_event_actor_fk FOREIGN KEY (actor_user_id) REFERENCES users(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TRIGGER membership_grants_reject_update
     BEFORE UPDATE ON membership_grants
     FOR EACH ROW
     SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Membership entitlement grants are immutable'",
    "CREATE TRIGGER membership_grants_reject_delete
     BEFORE DELETE ON membership_grants
     FOR EACH ROW
     SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Membership entitlement grants are immutable'",
    "CREATE TRIGGER membership_events_reject_update
     BEFORE UPDATE ON membership_events
     FOR EACH ROW
     SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Membership lifecycle events are append-only'",
    "CREATE TRIGGER membership_events_reject_delete
     BEFORE DELETE ON membership_events
     FOR EACH ROW
     SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Membership lifecycle events are append-only'"
];
