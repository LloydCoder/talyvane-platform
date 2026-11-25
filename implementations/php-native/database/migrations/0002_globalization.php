<?php
declare(strict_types=1);

/** Reference datasets are imported as versioned data; the schema has no finite country/currency allowlist. */
return [
"CREATE TABLE IF NOT EXISTS countries (
    alpha2 CHAR(2) NOT NULL PRIMARY KEY,
    alpha3 CHAR(3) NOT NULL,
    numeric_code CHAR(3) NOT NULL,
    name VARCHAR(190) NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    dataset_version VARCHAR(64) NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY countries_alpha3_unique (alpha3),
    UNIQUE KEY countries_numeric_unique (numeric_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS currencies (
    code CHAR(3) NOT NULL PRIMARY KEY,
    numeric_code CHAR(3) NULL,
    name VARCHAR(190) NOT NULL,
    symbol VARCHAR(24) NULL,
    minor_units TINYINT UNSIGNED NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    dataset_version VARCHAR(64) NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY currencies_numeric_unique (numeric_code),
    CONSTRAINT currencies_precision_check CHECK (minor_units <= 4)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS market_policies (
    country_code CHAR(2) NOT NULL PRIMARY KEY,
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    decision VARCHAR(24) NOT NULL DEFAULT 'pending',
    reviewer_id CHAR(36) NULL,
    reviewed_at DATETIME NULL,
    expires_at DATETIME NULL,
    evidence_reference VARCHAR(500) NULL,
    review_summary TEXT NULL,
    policy_version VARCHAR(64) NOT NULL DEFAULT 'market-policy-v1',
    updated_at DATETIME NOT NULL,
    CONSTRAINT market_policy_country_fk FOREIGN KEY (country_code) REFERENCES countries(alpha2),
    CONSTRAINT market_policy_reviewer_fk FOREIGN KEY (reviewer_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS localized_prices (
    id CHAR(36) NOT NULL PRIMARY KEY,
    price_key VARCHAR(120) NOT NULL,
    country_code CHAR(2) NOT NULL,
    currency_code CHAR(3) NOT NULL,
    amount_minor BIGINT UNSIGNED NOT NULL,
    valid_from DATETIME NOT NULL,
    valid_until DATETIME NULL,
    policy_version VARCHAR(64) NOT NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY localized_price_scope (price_key, country_code, currency_code, valid_from),
    CONSTRAINT localized_price_country_fk FOREIGN KEY (country_code) REFERENCES countries(alpha2),
    CONSTRAINT localized_price_currency_fk FOREIGN KEY (currency_code) REFERENCES currencies(code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS exchange_rate_snapshots (
    id CHAR(36) NOT NULL PRIMARY KEY,
    base_currency CHAR(3) NOT NULL,
    quote_currency CHAR(3) NOT NULL,
    rate_decimal DECIMAL(30,12) NOT NULL,
    source VARCHAR(190) NOT NULL,
    observed_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL,
    KEY exchange_pair_observed_idx (base_currency, quote_currency, observed_at),
    CONSTRAINT exchange_base_currency_fk FOREIGN KEY (base_currency) REFERENCES currencies(code),
    CONSTRAINT exchange_quote_currency_fk FOREIGN KEY (quote_currency) REFERENCES currencies(code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS quote_snapshots (
    id CHAR(36) NOT NULL PRIMARY KEY,
    quote_reference VARCHAR(120) NOT NULL UNIQUE,
    country_code CHAR(2) NOT NULL,
    original_amount_minor BIGINT UNSIGNED NOT NULL,
    original_currency CHAR(3) NOT NULL,
    target_amount_minor BIGINT UNSIGNED NULL,
    target_currency CHAR(3) NULL,
    exchange_rate_snapshot_id CHAR(36) NULL,
    tax_policy_version VARCHAR(64) NULL,
    fees_json JSON NULL,
    expires_at DATETIME NOT NULL,
    accepted_at DATETIME NULL,
    policy_version VARCHAR(64) NOT NULL,
    created_at DATETIME NOT NULL,
    CONSTRAINT quote_country_fk FOREIGN KEY (country_code) REFERENCES countries(alpha2),
    CONSTRAINT quote_original_currency_fk FOREIGN KEY (original_currency) REFERENCES currencies(code),
    CONSTRAINT quote_target_currency_fk FOREIGN KEY (target_currency) REFERENCES currencies(code),
    CONSTRAINT quote_rate_fk FOREIGN KEY (exchange_rate_snapshot_id) REFERENCES exchange_rate_snapshots(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
];
