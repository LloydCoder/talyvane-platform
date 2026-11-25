<?php
declare(strict_types=1);

/** Correct applicant-rights lifecycle state and permit country anonymization after verified erasure. */
return [
    "ALTER TABLE model_applicant_rights_requests
     ADD COLUMN status VARCHAR(24) NOT NULL DEFAULT 'pending' AFTER request_type,
     ADD KEY model_rights_status_idx (application_id,status,completed_at)",
    "ALTER TABLE model_talent_applications MODIFY country_code CHAR(2) NULL"
];
