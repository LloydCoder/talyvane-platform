<?php
declare(strict_types=1);

/**
 * Explicitly supersede prior email verification challenges when a new code is issued.
 * This prevents a historical verified challenge from satisfying a later recovery operation.
 */
return [
"ALTER TABLE email_verification_challenges
    ADD COLUMN superseded_at DATETIME NULL,
    ADD KEY email_challenge_current_idx (user_id,email_address,purpose,superseded_at,created_at)"
];
