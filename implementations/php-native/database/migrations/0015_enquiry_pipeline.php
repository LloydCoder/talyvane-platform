<?php
declare(strict_types=1);

/** Public enquiry privacy acknowledgement and complete CRM lifecycle timestamps. */
return [
"ALTER TABLE enquiries
    ADD COLUMN destination_id CHAR(36) NULL AFTER country_code,
    ADD COLUMN privacy_acknowledged_at DATETIME NULL AFTER message,
    ADD COLUMN responded_at DATETIME NULL AFTER closed_at",
"ALTER TABLE enquiries
    ADD CONSTRAINT enquiry_destination_fk FOREIGN KEY (destination_id) REFERENCES destinations(id)",
"CREATE INDEX enquiry_reference_created_idx ON enquiries (created_at, reference)"
];
