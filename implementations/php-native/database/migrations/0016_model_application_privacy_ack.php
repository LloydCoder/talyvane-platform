<?php
declare(strict_types=1);

/** Capture explicit, timestamped privacy-notice acknowledgement for model-agency intake. */
return [
    "ALTER TABLE model_talent_applications
     ADD COLUMN privacy_acknowledged_at DATETIME NULL AFTER adult_eligibility_attested_at"
];
