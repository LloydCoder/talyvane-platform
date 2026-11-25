<?php
declare(strict_types=1);

return [
"CREATE TABLE IF NOT EXISTS rate_limit_buckets (
    bucket_key CHAR(64) NOT NULL PRIMARY KEY,
    scope VARCHAR(80) NOT NULL,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    window_started_at DATETIME NOT NULL,
    blocked_until DATETIME NULL,
    created_at DATETIME NOT NULL,
    KEY rate_limit_cleanup_idx (window_started_at, blocked_until),
    KEY rate_limit_scope_idx (scope, window_started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
];
