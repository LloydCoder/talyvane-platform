<?php
declare(strict_types=1);

/**
 * Immutable publication snapshots for destination guides and consent-evidenced testimonials.
 * Existing published rows are preserved as version one during the migration.
 */
return [
"ALTER TABLE destination_guides
    ADD COLUMN version INT UNSIGNED NOT NULL DEFAULT 1,
    ADD COLUMN published_version INT UNSIGNED NULL,
    ADD COLUMN meta_title VARCHAR(190) NULL,
    ADD COLUMN meta_description VARCHAR(320) NULL",
"CREATE TABLE IF NOT EXISTS destination_guide_versions (
    id CHAR(36) NOT NULL PRIMARY KEY,
    guide_id CHAR(36) NOT NULL,
    version INT UNSIGNED NOT NULL,
    title VARCHAR(190) NOT NULL,
    content MEDIUMTEXT NOT NULL,
    locale VARCHAR(35) NOT NULL,
    meta_title VARCHAR(190) NULL,
    meta_description VARCHAR(320) NULL,
    changed_by CHAR(36) NOT NULL,
    change_reason VARCHAR(500) NOT NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY destination_guide_version_unique (guide_id,version),
    CONSTRAINT destination_guide_version_guide_fk FOREIGN KEY (guide_id) REFERENCES destination_guides(id),
    CONSTRAINT destination_guide_version_actor_fk FOREIGN KEY (changed_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"INSERT INTO destination_guide_versions
    (id,guide_id,version,title,content,locale,meta_title,meta_description,changed_by,change_reason,created_at)
 SELECT UUID(),id,1,title,content,locale,NULL,NULL,updated_by,'Migrated existing destination guide',UTC_TIMESTAMP()
 FROM destination_guides",
"UPDATE destination_guides SET published_version=1 WHERE status='published'",
"ALTER TABLE testimonials
    ADD COLUMN version INT UNSIGNED NOT NULL DEFAULT 1,
    ADD COLUMN published_version INT UNSIGNED NULL,
    ADD COLUMN updated_by CHAR(36) NULL",
"UPDATE testimonials SET updated_by=created_by",
"ALTER TABLE testimonials
    MODIFY updated_by CHAR(36) NOT NULL,
    ADD CONSTRAINT testimonial_updated_actor_fk FOREIGN KEY (updated_by) REFERENCES users(id)",
"CREATE TABLE IF NOT EXISTS testimonial_versions (
    id CHAR(36) NOT NULL PRIMARY KEY,
    testimonial_id CHAR(36) NOT NULL,
    version INT UNSIGNED NOT NULL,
    display_name VARCHAR(120) NOT NULL,
    quote_text TEXT NOT NULL,
    locale VARCHAR(35) NOT NULL,
    consent_reference VARCHAR(190) NOT NULL,
    sort_order INT NOT NULL,
    changed_by CHAR(36) NOT NULL,
    change_reason VARCHAR(500) NOT NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY testimonial_version_unique (testimonial_id,version),
    CONSTRAINT testimonial_version_testimonial_fk FOREIGN KEY (testimonial_id) REFERENCES testimonials(id),
    CONSTRAINT testimonial_version_actor_fk FOREIGN KEY (changed_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"INSERT INTO testimonial_versions
    (id,testimonial_id,version,display_name,quote_text,locale,consent_reference,sort_order,changed_by,change_reason,created_at)
 SELECT UUID(),id,1,display_name,quote_text,locale,consent_reference,sort_order,created_by,'Migrated existing testimonial',UTC_TIMESTAMP()
 FROM testimonials",
"UPDATE testimonials SET published_version=1 WHERE status='published'"
];
