<?php
declare(strict_types=1);

/** Payment feature configuration only; no checkout, custody or settlement is enabled by this table. */
return [
    "CREATE TABLE IF NOT EXISTS payment_settings (
        id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
        payments_enabled TINYINT(1) NOT NULL DEFAULT 0,
        crypto_enabled TINYINT(1) NOT NULL DEFAULT 0,
        cards_enabled TINYINT(1) NOT NULL DEFAULT 0,
        manual_enabled TINYINT(1) NOT NULL DEFAULT 0,
        booking_payments_enabled TINYINT(1) NOT NULL DEFAULT 0,
        membership_payments_enabled TINYINT(1) NOT NULL DEFAULT 0,
        updated_by CHAR(36) NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        CONSTRAINT payment_settings_actor_fk FOREIGN KEY (updated_by) REFERENCES users(id),
        CONSTRAINT payment_settings_singleton_check CHECK (id = 1)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "INSERT INTO payment_settings
        (id,payments_enabled,crypto_enabled,cards_enabled,manual_enabled,booking_payments_enabled,membership_payments_enabled,updated_by,created_at,updated_at)
     VALUES (1,0,0,0,0,0,0,NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP())
     ON DUPLICATE KEY UPDATE id=id"
];
