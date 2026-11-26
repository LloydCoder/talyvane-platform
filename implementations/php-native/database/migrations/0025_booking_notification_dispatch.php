<?php
declare(strict_types=1);

/** Claimable, encrypted and idempotent booking email delivery on the shared communications outbox. */
return [
    "ALTER TABLE communication_outbox
        ADD COLUMN recipient_fingerprint CHAR(64) NULL AFTER recipient_ciphertext,
        ADD COLUMN claim_token CHAR(36) NULL AFTER available_at,
        ADD COLUMN claimed_at DATETIME NULL AFTER claim_token,
        ADD COLUMN last_attempted_at DATETIME NULL AFTER claimed_at,
        ADD KEY communication_outbox_claim_idx (status,available_at,claimed_at)"
];
