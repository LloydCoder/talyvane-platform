<?php
declare(strict_types=1);

/** Model-controlled unavailability windows, scoped to the verified model application. */
return [
    "CREATE TABLE model_availability_blocks (
        id CHAR(36) NOT NULL PRIMARY KEY,
        application_id CHAR(36) NOT NULL,
        starts_at DATETIME NOT NULL,
        ends_at DATETIME NOT NULL,
        reason_code ENUM('personal','travel','other') NOT NULL DEFAULT 'other',
        status ENUM('active','cancelled') NOT NULL DEFAULT 'active',
        request_fingerprint CHAR(64) NOT NULL,
        terms_hash CHAR(64) NOT NULL,
        created_at DATETIME NOT NULL,
        cancelled_at DATETIME NULL,
        UNIQUE KEY model_availability_request_unique (request_fingerprint),
        KEY model_availability_application_status_schedule_idx (application_id,status,starts_at,ends_at),
        CONSTRAINT model_availability_application_fk FOREIGN KEY (application_id) REFERENCES model_talent_applications(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];