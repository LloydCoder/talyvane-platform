<?php
declare(strict_types=1);

/** Deployment-wide locale, time-zone and reporting-currency defaults. */
return [
    "CREATE TABLE IF NOT EXISTS deployment_globalization_settings (
        id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
        base_currency_code CHAR(3) NULL,
        default_locale VARCHAR(35) NOT NULL DEFAULT 'en',
        default_timezone VARCHAR(64) NOT NULL DEFAULT 'UTC',
        updated_by CHAR(36) NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        CONSTRAINT deployment_globalization_currency_fk FOREIGN KEY (base_currency_code) REFERENCES currencies(code),
        CONSTRAINT deployment_globalization_actor_fk FOREIGN KEY (updated_by) REFERENCES users(id),
        CONSTRAINT deployment_globalization_singleton_check CHECK (id = 1)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "INSERT INTO deployment_globalization_settings
        (id,base_currency_code,default_locale,default_timezone,updated_by,created_at,updated_at)
     VALUES (1,NULL,'en','UTC',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP())
     ON DUPLICATE KEY UPDATE id=id"
];
