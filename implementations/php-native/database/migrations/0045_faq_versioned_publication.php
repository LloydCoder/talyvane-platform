<?php
declare(strict_types=1);

/**
 * Locale-scoped FAQ records with immutable publication snapshots.
 * Draft edits never mutate the version currently served to public visitors.
 */
return [
"CREATE TABLE IF NOT EXISTS site_faqs (
    id CHAR(36) NOT NULL PRIMARY KEY,
    question VARCHAR(500) NOT NULL,
    answer MEDIUMTEXT NOT NULL,
    locale VARCHAR(35) NOT NULL DEFAULT 'en',
    category_key VARCHAR(80) NOT NULL DEFAULT 'general',
    sort_order INT NOT NULL DEFAULT 0,
    status VARCHAR(24) NOT NULL DEFAULT 'draft',
    version INT UNSIGNED NOT NULL DEFAULT 1,
    published_version INT UNSIGNED NULL,
    created_by CHAR(36) NOT NULL,
    updated_by CHAR(36) NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    KEY site_faq_public_idx (status, locale, category_key, sort_order),
    CONSTRAINT site_faq_created_actor_fk FOREIGN KEY (created_by) REFERENCES users(id),
    CONSTRAINT site_faq_updated_actor_fk FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS site_faq_versions (
    id CHAR(36) NOT NULL PRIMARY KEY,
    faq_id CHAR(36) NOT NULL,
    version INT UNSIGNED NOT NULL,
    question VARCHAR(500) NOT NULL,
    answer MEDIUMTEXT NOT NULL,
    locale VARCHAR(35) NOT NULL,
    category_key VARCHAR(80) NOT NULL,
    sort_order INT NOT NULL,
    changed_by CHAR(36) NOT NULL,
    change_reason VARCHAR(500) NOT NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY site_faq_version_unique (faq_id, version),
    CONSTRAINT site_faq_version_faq_fk FOREIGN KEY (faq_id) REFERENCES site_faqs(id),
    CONSTRAINT site_faq_version_actor_fk FOREIGN KEY (changed_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
];
