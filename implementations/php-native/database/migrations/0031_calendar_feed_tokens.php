<?php
declare(strict_types=1);

/** Revocable, role-scoped bearer tokens for private iCalendar subscriptions. */
return [
    "CREATE TABLE IF NOT EXISTS calendar_feed_tokens (
        id CHAR(36) NOT NULL PRIMARY KEY,
        user_id CHAR(36) NOT NULL,
        role_key VARCHAR(16) NOT NULL,
        label VARCHAR(80) NOT NULL,
        token_hash CHAR(64) NOT NULL UNIQUE,
        expires_at DATETIME NOT NULL,
        revoked_at DATETIME NULL,
        last_used_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        KEY calendar_feed_owner_idx (user_id,role_key,revoked_at,created_at),
        KEY calendar_feed_expiry_idx (expires_at,revoked_at),
        CONSTRAINT calendar_feed_user_fk FOREIGN KEY (user_id) REFERENCES users(id),
        CONSTRAINT calendar_feed_role_check CHECK (role_key IN ('client','companion'))
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TABLE IF NOT EXISTS calendar_feed_token_events (
        id CHAR(36) NOT NULL PRIMARY KEY,
        token_id CHAR(36) NOT NULL,
        user_id CHAR(36) NOT NULL,
        role_key VARCHAR(16) NOT NULL,
        event_type VARCHAR(24) NOT NULL,
        occurred_at DATETIME NOT NULL,
        KEY calendar_feed_event_token_idx (token_id,occurred_at),
        CONSTRAINT calendar_feed_event_token_fk FOREIGN KEY (token_id) REFERENCES calendar_feed_tokens(id),
        CONSTRAINT calendar_feed_event_user_fk FOREIGN KEY (user_id) REFERENCES users(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TRIGGER calendar_feed_token_events_no_update BEFORE UPDATE ON calendar_feed_token_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Calendar feed token events are append-only'",
    "CREATE TRIGGER calendar_feed_token_events_no_delete BEFORE DELETE ON calendar_feed_token_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Calendar feed token events are append-only'"
];
