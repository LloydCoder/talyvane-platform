<?php
declare(strict_types=1);

/** Prevent replayed template publication forms from creating duplicate versions. */
return [
    "ALTER TABLE communication_templates
        ADD COLUMN idempotency_key CHAR(64) NULL AFTER active,
        ADD UNIQUE KEY communication_template_idempotency_unique (idempotency_key)"
];
