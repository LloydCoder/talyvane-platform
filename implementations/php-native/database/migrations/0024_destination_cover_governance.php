<?php
declare(strict_types=1);

/** Destination covers are private until an independent rights review approves them. */
return [
"CREATE TABLE IF NOT EXISTS destination_cover_assets (
    id CHAR(36) NOT NULL PRIMARY KEY,
    destination_id CHAR(36) NOT NULL,
    uploaded_by CHAR(36) NOT NULL,
    private_storage_key VARCHAR(500) NOT NULL,
    mime_type VARCHAR(80) NOT NULL,
    file_size BIGINT UNSIGNED NOT NULL,
    sha256 CHAR(64) NOT NULL,
    rights_declaration_version VARCHAR(64) NOT NULL,
    rights_statement_hash CHAR(64) NOT NULL,
    rights_confirmed_at DATETIME NOT NULL,
    review_status VARCHAR(24) NOT NULL DEFAULT 'pending',
    reviewed_by CHAR(36) NULL,
    reviewed_at DATETIME NULL,
    review_reason VARCHAR(500) NULL,
    withdrawn_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    KEY destination_cover_review_idx (review_status,created_at),
    KEY destination_cover_destination_idx (destination_id,review_status),
    CONSTRAINT destination_cover_destination_fk FOREIGN KEY (destination_id) REFERENCES destinations(id),
    CONSTRAINT destination_cover_uploader_fk FOREIGN KEY (uploaded_by) REFERENCES users(id),
    CONSTRAINT destination_cover_reviewer_fk FOREIGN KEY (reviewed_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
];
