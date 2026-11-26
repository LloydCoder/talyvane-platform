<?php
declare(strict_types=1);

/** Pending verified contact-email changes for client and companion portals. */
return [
    "CREATE TABLE portal_email_change_challenges (
        id CHAR(36) NOT NULL PRIMARY KEY,
        user_id CHAR(36) NOT NULL,
        old_email VARCHAR(254) NOT NULL,
        new_email VARCHAR(254) NOT NULL,
        code_hash CHAR(64) NOT NULL,
        expires_at DATETIME NOT NULL,
        attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        consumed_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        KEY portal_email_change_user_idx (user_id,consumed_at,created_at),
        KEY portal_email_change_expiry_idx (expires_at,consumed_at),
        CONSTRAINT portal_email_change_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
];
