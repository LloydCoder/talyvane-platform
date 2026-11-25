<?php
declare(strict_types=1);

/** Database-enforced append-only guards for security audit evidence. */
return [
    "CREATE TRIGGER audit_events_reject_update
     BEFORE UPDATE ON audit_events
     FOR EACH ROW
     SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Security audit events are append-only'",
    "CREATE TRIGGER audit_events_reject_delete
     BEFORE DELETE ON audit_events
     FOR EACH ROW
     SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Security audit events are append-only'"
];
