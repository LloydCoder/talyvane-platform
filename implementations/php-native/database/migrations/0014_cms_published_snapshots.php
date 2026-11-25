<?php
declare(strict_types=1);

/**
 * Separate the mutable working revision from the immutable public revision.
 * Existing published pages are pinned to their current version before the
 * foreign key is introduced; draft pages remain unpublished.
 */
return [
    "ALTER TABLE site_pages
        ADD COLUMN published_version INT UNSIGNED NULL AFTER version,
        ADD KEY site_page_published_version_idx (id, published_version)",
    "UPDATE site_pages
        SET published_version = version
        WHERE status = 'published' AND published_version IS NULL",
    "ALTER TABLE site_pages
        ADD CONSTRAINT site_page_published_version_fk
        FOREIGN KEY (id, published_version)
        REFERENCES site_page_versions (page_id, version)"
];
