<?php
declare(strict_types=1);

/** Extend native assignment, expense and settlement ledgers to the governed Laravel workflow contract. */
return [
    "ALTER TABLE model_assignments
        ADD COLUMN application_id CHAR(36) NULL AFTER submission_id,
        ADD COLUMN representation_agreement_id CHAR(36) NULL AFTER application_id,
        ADD COLUMN country_code CHAR(2) NULL AFTER representation_agreement_id,
        ADD COLUMN project_purpose VARCHAR(40) NULL AFTER country_code,
        ADD COLUMN client_name TEXT NULL AFTER project_purpose,
        ADD COLUMN client_reference TEXT NULL AFTER client_name,
        ADD COLUMN brief TEXT NULL AFTER client_reference,
        ADD COLUMN location_city TEXT NULL AFTER brief,
        ADD COLUMN shoot_starts_at DATETIME NULL AFTER location_city,
        ADD COLUMN shoot_ends_at DATETIME NULL AFTER shoot_starts_at,
        ADD COLUMN deliverables TEXT NULL AFTER shoot_ends_at,
        ADD COLUMN assignment_policy_version VARCHAR(80) NULL AFTER terms_snapshot,
        ADD COLUMN offered_by CHAR(36) NULL AFTER offered_at,
        ADD COLUMN acceptance_recorded_by CHAR(36) NULL AFTER accepted_at,
        ADD COLUMN executed_document_reference TEXT NULL AFTER acceptance_evidence_reference,
        ADD COLUMN model_acceptance_evidence_reference TEXT NULL AFTER executed_document_reference,
        ADD COLUMN confirmed_by CHAR(36) NULL AFTER confirmed_at,
        ADD COLUMN started_by CHAR(36) NULL AFTER started_at,
        ADD COLUMN completed_by CHAR(36) NULL AFTER completed_at,
        ADD COLUMN declined_by CHAR(36) NULL AFTER completed_by,
        ADD COLUMN declined_at DATETIME NULL AFTER declined_by,
        ADD COLUMN model_decline_evidence_reference TEXT NULL AFTER declined_at,
        ADD COLUMN decline_reason TEXT NULL AFTER model_decline_evidence_reference,
        ADD COLUMN cancelled_by CHAR(36) NULL AFTER decline_reason,
        ADD COLUMN cancelled_at DATETIME NULL AFTER cancelled_by,
        ADD COLUMN cancellation_reason TEXT NULL AFTER cancelled_at,
        ADD COLUMN disputed_by CHAR(36) NULL AFTER cancellation_reason,
        ADD COLUMN dispute_reason TEXT NULL AFTER disputed_by,
        ADD KEY model_assignment_application_status_idx (application_id,status),
        ADD KEY model_assignment_schedule_idx (application_id,shoot_starts_at,shoot_ends_at),
        ADD CONSTRAINT model_assignment_application_fk FOREIGN KEY (application_id) REFERENCES model_talent_applications(id),
        ADD CONSTRAINT model_assignment_agreement_fk FOREIGN KEY (representation_agreement_id) REFERENCES model_representation_agreements(id),
        ADD CONSTRAINT model_assignment_country_fk FOREIGN KEY (country_code) REFERENCES countries(alpha2),
        ADD CONSTRAINT model_assignment_offerer_fk FOREIGN KEY (offered_by) REFERENCES users(id),
        ADD CONSTRAINT model_assignment_acceptor_fk FOREIGN KEY (acceptance_recorded_by) REFERENCES users(id),
        ADD CONSTRAINT model_assignment_confirmer_fk FOREIGN KEY (confirmed_by) REFERENCES users(id),
        ADD CONSTRAINT model_assignment_starter_fk FOREIGN KEY (started_by) REFERENCES users(id),
        ADD CONSTRAINT model_assignment_completer_fk FOREIGN KEY (completed_by) REFERENCES users(id),
        ADD CONSTRAINT model_assignment_decliner_fk FOREIGN KEY (declined_by) REFERENCES users(id),
        ADD CONSTRAINT model_assignment_canceller_fk FOREIGN KEY (cancelled_by) REFERENCES users(id),
        ADD CONSTRAINT model_assignment_disputer_fk FOREIGN KEY (disputed_by) REFERENCES users(id)",
    "UPDATE model_assignments a
        INNER JOIN model_casting_submissions s ON s.id=a.submission_id
        INNER JOIN model_talent_applications p ON p.id=s.application_id
        INNER JOIN model_casting_calls c ON c.id=s.casting_call_id
        INNER JOIN model_image_licences l ON l.id=s.image_licence_id
        SET a.application_id=p.id,a.representation_agreement_id=l.agreement_id,a.country_code=c.country_code",
    "ALTER TABLE model_assignments
        MODIFY currency_code CHAR(3) NULL,
        MODIFY compensation_minor BIGINT UNSIGNED NULL",
    "ALTER TABLE model_assignment_events
        MODIFY reason TEXT NULL,
        MODIFY evidence_reference TEXT NULL,
        ADD COLUMN actor_type VARCHAR(24) NOT NULL DEFAULT 'administrator' AFTER actor_user_id,
        ADD COLUMN event_type VARCHAR(64) NULL AFTER actor_type,
        ADD COLUMN occurred_at DATETIME NULL AFTER evidence_reference,
        ADD COLUMN updated_at DATETIME NULL AFTER created_at",
    "UPDATE model_assignment_events SET event_type='assignment.legacy',occurred_at=created_at,updated_at=created_at WHERE event_type IS NULL OR occurred_at IS NULL",
    "ALTER TABLE model_assignment_events
        MODIFY event_type VARCHAR(64) NOT NULL,
        MODIFY occurred_at DATETIME NOT NULL",
    "ALTER TABLE model_assignment_expenses
        ADD COLUMN settlement_id CHAR(36) NULL AFTER assignment_id,
        ADD COLUMN submitted_by CHAR(36) NULL AFTER private_evidence_key,
        ADD COLUMN review_note TEXT NULL AFTER reviewed_at,
        ADD COLUMN updated_at DATETIME NULL AFTER created_at,
        MODIFY status VARCHAR(24) NOT NULL DEFAULT 'pending',
        ADD KEY model_assignment_expense_settlement_idx (settlement_id,status),
        ADD CONSTRAINT model_assignment_expense_submitter_fk FOREIGN KEY (submitted_by) REFERENCES users(id),
        ADD CONSTRAINT model_assignment_expense_settlement_fk FOREIGN KEY (settlement_id) REFERENCES model_settlements(id)",
    "ALTER TABLE model_settlements
        ADD COLUMN application_id CHAR(36) NULL AFTER assignment_id,
        ADD COLUMN currency_precision TINYINT UNSIGNED NOT NULL DEFAULT 2 AFTER currency_code,
        ADD COLUMN commission_basis_points SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER commission_minor,
        ADD COLUMN settlement_policy_version VARCHAR(80) NULL AFTER calculation_snapshot,
        ADD COLUMN payment_method VARCHAR(32) NULL AFTER paid_at,
        ADD COLUMN payment_reference TEXT NULL AFTER payment_method,
        ADD COLUMN paid_by CHAR(36) NULL AFTER payment_reference,
        ADD COLUMN reversed_by CHAR(36) NULL AFTER reversed_at,
        ADD COLUMN reversal_reason TEXT NULL AFTER reversed_by,
        ADD COLUMN disputed_by CHAR(36) NULL AFTER disputed_at,
        ADD COLUMN dispute_reason TEXT NULL AFTER disputed_by",
    "UPDATE model_settlements s
        INNER JOIN model_assignments a ON a.id=s.assignment_id
        SET s.application_id=a.application_id,s.commission_basis_points=a.commission_basis_points",
    "UPDATE model_settlements s
        INNER JOIN currencies c ON c.code=s.currency_code
        SET s.currency_precision=c.minor_units",
    "ALTER TABLE model_settlements
        MODIFY application_id CHAR(36) NOT NULL,
        ADD KEY model_settlement_application_status_idx (application_id,status),
        ADD CONSTRAINT model_settlement_application_fk FOREIGN KEY (application_id) REFERENCES model_talent_applications(id),
        ADD CONSTRAINT model_settlement_payer_fk FOREIGN KEY (paid_by) REFERENCES users(id),
        ADD CONSTRAINT model_settlement_reverser_fk FOREIGN KEY (reversed_by) REFERENCES users(id),
        ADD CONSTRAINT model_settlement_disputer_fk FOREIGN KEY (disputed_by) REFERENCES users(id)",
    "CREATE TABLE IF NOT EXISTS model_settlement_events (
        id CHAR(36) NOT NULL PRIMARY KEY,
        assignment_id CHAR(36) NOT NULL,
        settlement_id CHAR(36) NULL,
        expense_id CHAR(36) NULL,
        actor_user_id CHAR(36) NULL,
        actor_type VARCHAR(24) NOT NULL,
        event_type VARCHAR(64) NOT NULL,
        reason TEXT NULL,
        occurred_at DATETIME NOT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        KEY model_settlement_event_assignment_idx (assignment_id,occurred_at),
        KEY model_settlement_event_settlement_idx (settlement_id,occurred_at),
        KEY model_settlement_event_expense_idx (expense_id,occurred_at),
        CONSTRAINT model_settlement_event_assignment_fk FOREIGN KEY (assignment_id) REFERENCES model_assignments(id),
        CONSTRAINT model_settlement_event_settlement_fk FOREIGN KEY (settlement_id) REFERENCES model_settlements(id),
        CONSTRAINT model_settlement_event_expense_fk FOREIGN KEY (expense_id) REFERENCES model_assignment_expenses(id),
        CONSTRAINT model_settlement_event_actor_fk FOREIGN KEY (actor_user_id) REFERENCES users(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TRIGGER model_assignment_events_no_update BEFORE UPDATE ON model_assignment_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Model assignment events are append-only'",
    "CREATE TRIGGER model_assignment_events_no_delete BEFORE DELETE ON model_assignment_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Model assignment events are append-only'",
    "CREATE TRIGGER model_settlement_events_no_update BEFORE UPDATE ON model_settlement_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Model settlement events are append-only'",
    "CREATE TRIGGER model_settlement_events_no_delete BEFORE DELETE ON model_settlement_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Model settlement events are append-only'"
];
