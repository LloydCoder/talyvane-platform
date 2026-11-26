<?php
declare(strict_types=1);

/** Enquiry CRM notes are encrypted at rest and append-only. */
return [
    "CREATE TABLE crm_enquiry_notes (
        id CHAR(36) NOT NULL PRIMARY KEY,
        enquiry_id CHAR(36) NOT NULL,
        author_user_id CHAR(36) NOT NULL,
        ciphertext TEXT NOT NULL,
        created_at DATETIME NOT NULL,
        CONSTRAINT crm_enquiry_notes_enquiry_fk FOREIGN KEY (enquiry_id) REFERENCES enquiries(id),
        CONSTRAINT crm_enquiry_notes_author_fk FOREIGN KEY (author_user_id) REFERENCES users(id),
        INDEX crm_enquiry_notes_timeline_idx (enquiry_id,created_at,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TRIGGER crm_enquiry_notes_no_update BEFORE UPDATE ON crm_enquiry_notes FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'CRM notes are append-only'",
    "CREATE TRIGGER crm_enquiry_notes_no_delete BEFORE DELETE ON crm_enquiry_notes FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'CRM notes are append-only'"
];
