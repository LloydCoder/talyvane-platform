# Delivery Roadmap

## Phase 0 — Foundation
- [x] Verify repository ownership, public visibility and default branch.
- [x] Establish product requirements, architecture, security and compliance baseline.
- [ ] Legal review of licence history and chain of title.
- [ ] Verify hosting: PHP, Composer, SSH/terminal, Node build, cron, queues, storage and document root.
- [x] Create Laravel 13 application skeleton and CI.
- [x] Define initial design tokens and responsive public/admin shell.

## Release 1 — Operational foundation
- [x] Establish Talyvane Globalization and Localization Standard (TGLS) and ADR-0008 as mandatory global architecture.
- [x] Add deployment-local currency, country-market, exchange-rate and polymorphic localized-price persistence schema.
- [x] Add typed globalization domain models and a decimal-string Money value object with ISO-code shape and 0/2/3 precision handling.
- [x] Add feature tests for decimal-safe money conversion and globalization schema.
- [ ] Import and version the complete ISO 3166-1 and ISO 4217 reference datasets; current schema is intentionally empty until a vetted dataset/importer is delivered.
- [ ] Build admin workflows for currency enablement, base reporting currency, country policy, locale/timezone and market-specific prices.
- [ ] Implement exchange-rate provider adapters, rate freshness policy, immutable quote snapshots and deterministic rounding.
- [ ] Integrate fail-closed market policy checks into discovery, booking, membership and payment boundaries.
- [ ] Add localization preferences, translation fallback, locale-aware formatting and time-zone rendering across customer and admin interfaces.
- [ ] Add tax adapters only after jurisdiction-specific legal and merchant review.
- [x] Initial public homepage and responsive design system.
- [x] CLI-only first-owner provisioning, login/logout, role-gated admin dashboard.
- [x] Database-backed brand name, logo upload, tagline and contact configuration.
- [x] Enforce TOTP MFA, replay protection and single-use recovery codes for privileged administrator routes.
- [ ] Add hardware security keys, privileged step-up confirmation and formal MFA reset governance.
- [x] Destination administration with market/currency/locale/timezone configuration.
- [x] Public destination and companion profile discovery with active-destination filters.
- [x] Public profile visibility gated by verified-adult status and non-withdrawn publication consent.
- [x] Audited profile publication review with approved-provider allowlist, hashed provider reference and explicit consent version.
- [ ] Integrate and verify a real age-assurance provider; current publication workflow is reviewer-recorded and must not be represented as automated verification.
- [x] Public enquiry intake with validation, rate limiting, honeypot and admin CRM status workflow.
- [x] Client self-registration with role separation and email verification.
- [x] Companion CLI provisioning, linked profile accounts and verified companion portal.
- [x] Companion-controlled automatic-booking opt-in and immediate publication-consent withdrawal.
- [x] Manual booking requests and configurable automatic confirmation with fail-closed eligibility.
- [x] Admin booking inbox, deposit verification attestation, overlap checks and append-only booking event history.
- [x] Companion-managed weekly availability windows; automatic confirmation requires a matching local-time window.
- [x] Payment control centre with global, rail and purpose switches; BTC/USDT/USDC per-network receiving-address configuration; methods disabled until manual configuration review.
- [x] Plain-text CMS pages with draft/publish controls, SEO metadata and safe escaped public rendering.
- [x] Client booking cancellation with admin-configurable enable switch and cutoff; pending requests may be withdrawn.
- [ ] Date-specific availability exceptions, calendar sync, client rescheduling and notification delivery.
- [ ] Blockchain transaction watchers, immutable invoice snapshots, confirmations/reconciliation, refunds and approved card processor adapters.
- [x] CI feature tests for homepage, authentication, role boundaries and branding persistence.
Remaining: configurable content/CMS; jurisdiction-approved legal documents (current pages are draft placeholders); market flags; staging deployment and runtime verification; MFA.

## Release 2 — Booking and portals
Partially implemented: client and companion account authentication; verified email; manual requests; admin-configurable automatic eligibility; companion opt-in/withdrawal; weekly availability windows; deposit verification attestation; overlap checks; client and companion booking views. Remaining: date-specific availability exceptions, calendar sync, cancellation/rescheduling policies, notifications, tamper-evident audit export/retention, and full companion profile editing.

## Release 3 — Payments
Payment adapter contract; manual instructions; crypto invoice snapshots and server-side chain verification for approved networks; ledger and reconciliation; card adapters only after written underwriting.

## Release 4 — Commercial packaging
Installation wizard; versioned updates and migration tooling; backup/restore; deployment entitlement; customer deployment records; reseller documentation and support boundaries.

A milestone is not complete because screens exist. Each release requires tests, security review, accessibility checks, privacy review, deployment verification and operator documentation. Production is blocked pending jurisdiction, hosting and payment approvals.
