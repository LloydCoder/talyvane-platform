<?php
declare(strict_types=1);

return [
"CREATE TABLE IF NOT EXISTS super_admin_bootstrap_challenges (
    singleton_id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    user_id CHAR(36) NOT NULL,
    primary_email VARCHAR(254) NOT NULL,
    recovery_email VARCHAR(254) NOT NULL,
    proof_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    activated_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    CONSTRAINT super_admin_bootstrap_singleton_check CHECK (singleton_id = 1),
    CONSTRAINT super_admin_bootstrap_user_fk FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
];
