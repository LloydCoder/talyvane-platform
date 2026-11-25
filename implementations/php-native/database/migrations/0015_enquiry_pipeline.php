<?php
declare(strict_types=1);

/** Public enquiry privacy acknowledgement and complete CRM lifecycle timestamps. */
return [
"ALTER TABLE enquiries
    ADD COLUMN destination_id CHAR(36) NULL AFTER country_code,
    ADD COLUMN privacy_acknowledged_at DATETIME NULL AFTER message,
    ADD COLUMN privacy_policy_version VARCHAR(64) NOT NULL DEFAULT 'privacy-v1' AFTER privacy_acknowledged_at,
    ADD COLUMN responded_at DATETIME NULL AFTER privacy_policy_version,
    ADD COLUMN closed_at DATETIME NULL AFTER responded_at",
"ALTER TABLE enquiries
    ADD CONSTRAINT enquiry_destination_fk FOREIGN KEY (destination_id) REFERENCES destinations(id)",
"CREATE INDEX enquiry_reference_created_idx ON enquiries (created_at, reference)"
];
