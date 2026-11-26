<?php
declare(strict_types=1);

/** Bind immutable booking quotes to one client/profile/market and consume them atomically at booking creation. */
return [
    "ALTER TABLE quote_snapshots
        ADD COLUMN client_user_id CHAR(36) NULL AFTER quote_reference,
        ADD COLUMN profile_id CHAR(36) NULL AFTER client_user_id,
        ADD COLUMN destination_id CHAR(36) NULL AFTER profile_id,
        ADD COLUMN created_by CHAR(36) NULL AFTER policy_version,
        ADD COLUMN accepted_by CHAR(36) NULL AFTER accepted_at,
        ADD COLUMN consumed_at DATETIME NULL AFTER accepted_by,
        ADD COLUMN consumed_booking_id CHAR(36) NULL AFTER consumed_at,
        ADD KEY quote_client_profile_expiry_idx (client_user_id,profile_id,expires_at),
        ADD UNIQUE KEY quote_consumed_booking_unique (consumed_booking_id),
        ADD CONSTRAINT quote_client_user_fk FOREIGN KEY (client_user_id) REFERENCES users(id),
        ADD CONSTRAINT quote_profile_fk FOREIGN KEY (profile_id) REFERENCES companion_profiles(id),
        ADD CONSTRAINT quote_destination_fk FOREIGN KEY (destination_id) REFERENCES destinations(id),
        ADD CONSTRAINT quote_creator_fk FOREIGN KEY (created_by) REFERENCES users(id),
        ADD CONSTRAINT quote_acceptor_fk FOREIGN KEY (accepted_by) REFERENCES users(id),
        ADD CONSTRAINT quote_consumed_booking_fk FOREIGN KEY (consumed_booking_id) REFERENCES bookings(id)",
    "CREATE TRIGGER quote_snapshots_immutable_update BEFORE UPDATE ON quote_snapshots FOR EACH ROW
     BEGIN
        IF NOT (
            NEW.id <=> OLD.id AND NEW.quote_reference <=> OLD.quote_reference AND
            NEW.client_user_id <=> OLD.client_user_id AND NEW.profile_id <=> OLD.profile_id AND
            NEW.destination_id <=> OLD.destination_id AND NEW.country_code <=> OLD.country_code AND
            NEW.original_amount_minor <=> OLD.original_amount_minor AND
            NEW.original_currency <=> OLD.original_currency AND NEW.target_amount_minor <=> OLD.target_amount_minor AND
            NEW.target_currency <=> OLD.target_currency AND NEW.exchange_rate_snapshot_id <=> OLD.exchange_rate_snapshot_id AND
            NEW.tax_policy_version <=> OLD.tax_policy_version AND NEW.fees_json <=> OLD.fees_json AND
            NEW.expires_at <=> OLD.expires_at AND NEW.policy_version <=> OLD.policy_version AND
            NEW.created_by <=> OLD.created_by AND NEW.created_at <=> OLD.created_at
        ) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Booking quote snapshot terms are immutable';
        END IF;
        IF (NEW.accepted_at IS NULL) <> (NEW.accepted_by IS NULL) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Quote acceptance timestamp and actor must be recorded together';
        END IF;
        IF OLD.accepted_at IS NOT NULL AND NOT (NEW.accepted_at <=> OLD.accepted_at AND NEW.accepted_by <=> OLD.accepted_by) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Quote acceptance is append-only';
        END IF;
        IF (NEW.consumed_at IS NULL) <> (NEW.consumed_booking_id IS NULL) OR
           (NEW.consumed_at IS NOT NULL AND NEW.accepted_at IS NULL) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Quote consumption requires an accepted quote and booking reference';
        END IF;
        IF OLD.consumed_at IS NOT NULL AND NOT (NEW.consumed_at <=> OLD.consumed_at AND NEW.consumed_booking_id <=> OLD.consumed_booking_id) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Quote consumption is append-only';
        END IF;
     END",
    "CREATE TRIGGER quote_snapshots_no_delete BEFORE DELETE ON quote_snapshots FOR EACH ROW
     SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Booking quote snapshots cannot be deleted'"
];
