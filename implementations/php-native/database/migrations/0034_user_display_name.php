<?php
declare(strict_types=1);

/** User-facing client display name, separate from authentication email and legal identity evidence. */
return [
    "ALTER TABLE users ADD COLUMN display_name VARCHAR(120) NULL AFTER email"
];
