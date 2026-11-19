# ADR-0008: Globalization and Localization as a Core Platform Capability

- **Status:** Accepted
- **Date:** 2026-11-19
- **Decision owners:** Talyvane platform

## Context
The initial customer has named Mexico, the United Kingdom and the Philippines. Those are initial operating markets, not an acceptable product boundary. Talyvane is intended for independent global deployments and must not encode a short country or currency allowlist into its core.

## Decision
Adopt the Talyvane Globalization and Localization Standard (TGLS). Model country/territory, currency, locale/language, IANA time zone, market policy, tax policy and payment rail as independent dimensions. Use USD as the initial deployment default reporting currency only. Support a versioned ISO 4217 currency reference dataset and configurable market-specific prices. Persist money with explicit currency and decimal-safe precision; preserve immutable quote, transaction and exchange-rate snapshots. Enforce market policy server-side and fail closed when legal/operational approval is absent.

## Consequences
- The existing destination-level currency/locale/time-zone fields remain useful snapshots but cannot be the authoritative global configuration model.
- Globalization tables, services, admin workflows, reference-data imports, market-policy middleware and tests must be delivered in stages.
- A configurable country record is not permission to operate there.
- Currency display conversion must never silently alter the currency agreed for a booking or payment.
- Tax calculations and payment processor eligibility require jurisdiction-specific adapters and approvals.
