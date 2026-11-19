# Delivery Roadmap

## Phase 0 — Foundation
- [x] Verify repository ownership, public visibility and default branch.
- [x] Establish product requirements, architecture, security and compliance baseline.
- [ ] Legal review of licence history and chain of title.
- [ ] Verify hosting: PHP, Composer, SSH/terminal, Node build, cron, queues, storage and document root.
- [x] Create Laravel 13 application skeleton and CI.
- [x] Define initial design tokens and responsive public/admin shell.

## Release 1 — Operational foundation
- [x] Initial public homepage and responsive design system.
- [x] CLI-only first-owner provisioning, login/logout, role-gated admin dashboard.
- [x] Database-backed brand name, logo upload, tagline and contact configuration.
- [ ] Enforce MFA and step-up authentication for privileged accounts before production.
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
- [x] Admin booking inbox, deposit verification attestation and overlap checks.
- [ ] Booking cancellation/rescheduling policies, notification delivery and companion availability calendar.
- [x] CI feature tests for homepage, authentication, role boundaries and branding persistence.
Remaining: configurable content/CMS; jurisdiction-approved legal documents (current pages are draft placeholders); market flags; staging deployment and runtime verification; MFA.

## Release 2 — Booking and portals
Partially implemented: client and companion account authentication; verified email; manual requests; admin-configurable automatic eligibility; companion opt-in/withdrawal; deposit verification attestation; overlap checks; client and companion booking views. Remaining: cancellation/rescheduling policies, availability calendars, notifications, booking event audit trail and full companion profile editing.

## Release 3 — Payments
Payment adapter contract; manual instructions; crypto invoice snapshots and server-side chain verification for approved networks; ledger and reconciliation; card adapters only after written underwriting.

## Release 4 — Commercial packaging
Installation wizard; versioned updates and migration tooling; backup/restore; deployment entitlement; customer deployment records; reseller documentation and support boundaries.

A milestone is not complete because screens exist. Each release requires tests, security review, accessibility checks, privacy review, deployment verification and operator documentation. Production is blocked pending jurisdiction, hosting and payment approvals.
