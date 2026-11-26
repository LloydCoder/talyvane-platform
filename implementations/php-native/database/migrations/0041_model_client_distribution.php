<?php
declare(strict_types=1);

/**
 * Bind private model-agency casting and assignment records to one verified client
 * identity. Existing records remain undistributed until an MFA administrator
 * explicitly links them; no client is inferred from free-form names/references.
 */
return [
    "ALTER TABLE model_casting_calls
        ADD COLUMN client_user_id CHAR(36) NULL AFTER client_reference,
        ADD KEY model_casting_client_status_idx (client_user_id,status),
        ADD CONSTRAINT model_casting_client_user_fk FOREIGN KEY (client_user_id) REFERENCES users(id) ON DELETE RESTRICT",
    "ALTER TABLE model_assignments
        ADD COLUMN client_user_id CHAR(36) NULL AFTER application_id,
        ADD KEY model_assignment_client_status_idx (client_user_id,status),
        ADD CONSTRAINT model_assignment_client_user_fk FOREIGN KEY (client_user_id) REFERENCES users(id) ON DELETE RESTRICT"
];
