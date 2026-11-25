<?php
declare(strict_types=1);

/** CRM, support and outbound communications. Messages are server-readable, not E2EE. */
return [
"CREATE TABLE IF NOT EXISTS crm_notes (
    id CHAR(36) NOT NULL PRIMARY KEY,
    enquiry_id CHAR(36) NOT NULL,
    author_user_id CHAR(36) NOT NULL,
    note_ciphertext MEDIUMTEXT NOT NULL,
    created_at DATETIME NOT NULL,
    KEY crm_note_enquiry_idx (enquiry_id, created_at),
    CONSTRAINT crm_note_enquiry_fk FOREIGN KEY (enquiry_id) REFERENCES enquiries(id),
    CONSTRAINT crm_note_author_fk FOREIGN KEY (author_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS crm_reminders (
    id CHAR(36) NOT NULL PRIMARY KEY,
    related_type VARCHAR(64) NOT NULL,
    related_id CHAR(36) NOT NULL,
    assigned_to CHAR(36) NOT NULL,
    title VARCHAR(190) NOT NULL,
    due_at DATETIME NOT NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'pending',
    completed_at DATETIME NULL,
    created_by CHAR(36) NOT NULL,
    created_at DATETIME NOT NULL,
    KEY crm_reminder_queue_idx (assigned_to, status, due_at),
    CONSTRAINT crm_reminder_assignee_fk FOREIGN KEY (assigned_to) REFERENCES users(id),
    CONSTRAINT crm_reminder_creator_fk FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS communication_templates (
    id CHAR(36) NOT NULL PRIMARY KEY,
    template_key VARCHAR(120) NOT NULL,
    channel VARCHAR(32) NOT NULL,
    locale VARCHAR(35) NOT NULL,
    subject_template VARCHAR(190) NULL,
    body_template MEDIUMTEXT NOT NULL,
    version INT UNSIGNED NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 0,
    updated_by CHAR(36) NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY communication_template_version_unique (template_key, channel, locale, version),
    CONSTRAINT communication_template_actor_fk FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS communication_outbox (
    id CHAR(36) NOT NULL PRIMARY KEY,
    idempotency_key VARCHAR(190) NOT NULL UNIQUE,
    related_type VARCHAR(64) NULL,
    related_id CHAR(36) NULL,
    channel VARCHAR(32) NOT NULL,
    recipient_ciphertext MEDIUMTEXT NOT NULL,
    payload_ciphertext MEDIUMTEXT NOT NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'pending',
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    available_at DATETIME NOT NULL,
    delivered_at DATETIME NULL,
    last_error_code VARCHAR(80) NULL,
    created_at DATETIME NOT NULL,
    KEY communication_outbox_dispatch_idx (status, available_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS communication_delivery_attempts (
    id CHAR(36) NOT NULL PRIMARY KEY,
    outbox_id CHAR(36) NOT NULL,
    provider_key VARCHAR(120) NOT NULL,
    status VARCHAR(24) NOT NULL,
    provider_reference VARCHAR(190) NULL,
    error_code VARCHAR(80) NULL,
    attempted_at DATETIME NOT NULL,
    KEY communication_attempt_history_idx (outbox_id, attempted_at),
    CONSTRAINT communication_attempt_outbox_fk FOREIGN KEY (outbox_id) REFERENCES communication_outbox(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS support_tickets (
    id CHAR(36) NOT NULL PRIMARY KEY,
    ticket_reference VARCHAR(64) NOT NULL UNIQUE,
    client_user_id CHAR(36) NOT NULL,
    subject VARCHAR(190) NOT NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'open',
    assigned_to CHAR(36) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    KEY support_ticket_client_idx (client_user_id, created_at),
    KEY support_ticket_queue_idx (status, assigned_to, updated_at),
    CONSTRAINT support_ticket_client_fk FOREIGN KEY (client_user_id) REFERENCES users(id),
    CONSTRAINT support_ticket_assignee_fk FOREIGN KEY (assigned_to) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS support_messages (
    id CHAR(36) NOT NULL PRIMARY KEY,
    ticket_id CHAR(36) NOT NULL,
    author_user_id CHAR(36) NOT NULL,
    body_ciphertext MEDIUMTEXT NOT NULL,
    visibility VARCHAR(24) NOT NULL DEFAULT 'client',
    created_at DATETIME NOT NULL,
    KEY support_message_history_idx (ticket_id, created_at),
    CONSTRAINT support_message_ticket_fk FOREIGN KEY (ticket_id) REFERENCES support_tickets(id),
    CONSTRAINT support_message_author_fk FOREIGN KEY (author_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
];
