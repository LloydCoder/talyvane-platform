<?php
declare(strict_types=1);

/** CRM follow-up reminders are metadata-only, role-gated and auditable. */
return [
    "CREATE TABLE crm_enquiry_reminders (
        id CHAR(36) NOT NULL PRIMARY KEY,
        enquiry_id CHAR(36) NOT NULL,
        created_by CHAR(36) NOT NULL,
        completed_by CHAR(36) NULL,
        due_at DATETIME NOT NULL,
        completed_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        CONSTRAINT crm_reminder_enquiry_fk FOREIGN KEY (enquiry_id) REFERENCES enquiries(id),
        CONSTRAINT crm_reminder_creator_fk FOREIGN KEY (created_by) REFERENCES users(id),
        CONSTRAINT crm_reminder_completer_fk FOREIGN KEY (completed_by) REFERENCES users(id),
        INDEX crm_reminder_due_idx (due_at,completed_at),
        INDEX crm_reminder_enquiry_idx (enquiry_id,due_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
];
