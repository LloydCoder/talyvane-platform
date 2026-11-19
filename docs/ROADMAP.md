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
- [x] Add admin workflows for deployment base currency/locale/time zone, currency registration and currency enable/disable, plus country-market registration that defaults to pending compliance.
- [x] Record globalization defaults, currency and country-market configuration changes in an append-only audit-event table, atomically with the relevant writes.
- [x] Curate royalty-free hospitality imagery for the public homepage and destination fallback cards; document source, photographer, license and restrictions.
- [x] Add admin-managed destination cover image uploads with JPEG/PNG/WebP, size/dimension validation and commercial-rights declaration.
- [ ] Add local image optimization/CDN strategy, EXIF removal, image moderation and complete companion-owned media upload/consent lifecycle.
- [ ] Complete currency editing/reference-data validation, market policy review and approval workflows, market-specific price management and audited change history.
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

The current booking implementation is for companionship workflows. The separate model-agency track remains unimplemented and must not be represented by the companionship booking flow. It requires talent applications, agency review, representation agreements, casting calls, assignments, image usage licences, client briefs, talent availability, usage approvals and settlement records, with independent permissions and audit trails.
Partially implemented: client and companion account authentication; verified email; manual requests; admin-configurable automatic eligibility; companion opt-in/withdrawal; weekly availability windows; deposit verification attestation; overlap checks; client and companion booking views. Remaining: date-specific availability exceptions, calendar sync, cancellation/rescheduling policies, notifications, tamper-evident audit export/retention, and full companion profile editing.

## Release 3 — Payments
Payment adapter contract; manual instructions; crypto invoice snapshots and server-side chain verification for approved networks; ledger and reconciliation; card adapters only after written underwriting.

## Release 4 — Commercial packaging
Installation wizard; versioned updates and migration tooling; backup/restore; deployment entitlement; customer deployment records; reseller documentation and support boundaries.

A milestone is not complete because screens exist. Each release requires tests, security review, accessibility checks, privacy review, deployment verification and operator documentation. Production is blocked pending jurisdiction, hosting and payment approvals.


## Super Admin control plane (approved architecture; implementation pending)

- [x] Define and seed a deployment feature registry with stable keys, module ownership, implementation status, dependencies, risk classes and activation prerequisites.
- [x] Add a distinct `super_admin` principal to privileged authentication, route and MFA gates without silently promoting existing accounts.
- [x] Add a singleton deployment-control record and transactional CLI bootstrap that provisions at most one Super Admin.
- [x] Restrict deployment-wide branding, globalization, destinations, booking rules, payment configuration, site pages and feature controls to `super_admin`; retain day-to-day agency operations for `owner` and `admin`.
- [x] Protect the designated Super Admin against Eloquent-based demotion, deactivation and deletion; prevent a second principal after assignment; add regression tests. (Direct SQL bypass and governed recovery/rotation remain open.)
- [ ] Implement an audited, authenticated Super Admin recovery/rotation procedure and legacy owner/admin least-privilege migration.
- [ ] Implement membership entitlements and protected-resource authorization; current membership capability is assignment/display only.
- [x] Build a Super Admin-only read-only feature catalogue showing implementation state, configured/effective state, dependencies, risk and activation prerequisites.
- [x] Build feature mutation controls with impact preview, prerequisite evaluation, reason capture, password/TOTP step-up and transactional rollback.
- [x] Add dependency-aware server-side feature resolution and route middleware for public discovery, dynamic CMS pages, client/companion portals and companionship booking endpoints.
- [x] Recheck the automatic-booking feature gate inside the booking transaction before automatic confirmation.
- [x] Keep companion consent withdrawal and media removal outside the companion-portal feature gate, with a standalone privacy-controls page.
- [ ] Extend feature enforcement to every relevant policy, application service, scheduled job and integration boundary.
- [x] Implement current-password plus single-use TOTP step-up, reason capture, atomic feature-state changes and append-only audit writes.
- [x] Add an append-only feature-audit event schema, model-level update/delete protection and transactional event writes for feature state changes.
- [ ] Add secret set/rotate/clear workflows backed by environment or dedicated secret storage; never reveal stored values.
- [ ] Add dependency and eligibility validation for market, payment, verification and communication activation.
- [x] Add audited emergency disable for optional implemented features and prerequisite-checked re-enable workflow.
- [x] Test designated Super Admin lifecycle invariants and second-principal rejection at the Eloquent boundary.
- [x] Exclude MFA secret, recovery-code hashes and TOTP replay state from User model serialization, with regression coverage.
- [ ] Test authorization boundaries, direct-database bypass threat, audit integrity and attempts to bypass feature gates.
- [ ] Keep hard safeguards outside the feature registry and verify they cannot be disabled by Super Admin settings.


## cPanel shared-hosting compatibility and deployment documentation
- [x] Define PHP 8.3+ shared-hosting runtime baseline and required extensions, including GD WebP.
- [x] Define safe document-root and private application-storage requirements.
- [x] Document cPanel installation, database setup, environment configuration, permissions and first-run checks.
- [x] Document release, upgrade, backup, rollback and operations procedures.
- [ ] Merge and validate the generated `composer.lock` through the reviewed bootstrap PR; produce reproducible, tagged release artifacts with production vendor dependencies and compiled frontend assets.
- [x] Add a main-branch-only, reviewed lockfile-bootstrap workflow that targets PHP 8.3.0, publishes a bot-owned branch and dispatches the full CI matrix; the reviewed PR is opened separately and is never auto-merged.
- [x] Add automated PHP 8.3/8.4/8.5 compatibility and actual extension/platform checks in CI.
- [ ] Build a secure no-SSH installer and first Super Admin bootstrap, with no arbitrary command execution or public setup backdoor.
- [ ] Add preflight diagnostics for PHP web/CLI mismatch, extensions, GD WebP, database, writable paths, HTTPS, document-root exposure and hosting limits.
- [ ] Test supported cPanel/Apache/LiteSpeed configurations and document provider-specific exceptions.
- [ ] Add deployment smoke tests, restore drills and a per-installation compatibility report.
- [ ] Verify the current application can run with file cache/session and sync queue without Redis or persistent workers; disable scheduler-dependent features when cron is unavailable.
