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
        ADD CONSTRAINT quote_consumed_booking_fk FOREIGN KEY (consumed_booking_id) REFERENCES bookings(id)"
];
