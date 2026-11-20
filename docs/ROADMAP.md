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
- [x] Complete companion-owned media upload and consent lifecycle: rights and public-publication attestations, sanitized WebP/metadata removal, private storage, MFA-protected human review, separate versioned members-only opt-in, consent evidence and independent withdrawal.
- [ ] Add a production local-image optimization/CDN strategy and automated image moderation; current human review remains mandatory.
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
Remaining: approved jurisdiction-specific legal documents (current privacy and terms pages are draft placeholders); ISO reference-data imports; full market-policy, pricing and localization workflows; real age/identity assurance; staging deployment and runtime verification. The CMS foundation and administrator MFA are implemented, but content approval and production legal review remain separate release gates.

## Release 2 — Booking and portals

- [x] Create a separate model-agency application domain and public intake route, independent from companionship accounts, profiles, enquiries and bookings.
- [x] Require explicit adult-eligibility self-attestation and versioned privacy-notice acceptance; do not treat self-attestation as age assurance.
- [x] Add administrator-only application review with a constrained state machine and append-only application-event records.
- [x] Gate model-agency application activation on deployment-configured, explicitly approved privacy and adult-eligibility policies plus published CMS pages with slugs `model-applicant-privacy` and `model-adult-eligibility`; defaults remain disabled.
- [x] Validate portfolio links as HTTPS and never fetch applicant-supplied URLs server-side.
- [x] Implement a distinct versioned representation-agreement draft/offer/external-execution register, market and adult-eligibility gates, termination and audit; platform does not provide or validate electronic signatures.
- [x] Implement private model portfolio media intake, image sanitization, administrator review, consent-evidence references, purpose scope, withdrawal and file removal; no public/campaign use is authorized.
- [x] Implement private agency casting calls/briefs, licensed talent submissions, one-per-call constraints, manual shortlist/selection/decline, cancellation and licence-driven invalidation; no public/client casting distribution.
- [ ] Implement client-facing casting review/distribution, model self-service casting decisions and assignment states/approvals.
- [x] Implement private purpose-bound image usage licence ledger bound to approved portfolio assets, executed representation agreements and approved markets; require external execution and per-use model consent evidence; revoke on consent withdrawal/representation termination and expire by scheduler.
- [ ] Implement secure evidence document upload/download and cryptographic e-signature/verification integration; public/client/CDN image delivery remains disabled until consent-aware access, expiry and takedown controls are independently reviewed.
- [x] Implement a separate administrator-only model assignment lifecycle from selected casting submission through external offer, evidenced model acceptance, confirmation, in-progress and completion; snapshot agreed compensation, expense cap and representation commission.
- [x] Implement private expense claims, approval/rejection, expense-cap enforcement, decimal-safe commission/net calculations, immutable settlement snapshots and manual external-payment evidence/reversal/dispute records.
- [ ] Implement model self-service acceptance, client-facing assignment review, assignment booking/rescheduling, calendar and attendance workflows, actual payment integrations, tax/withholding, invoices, partial settlements and statutory reporting.
- [x] Implement email-verified applicant access, correction, withdrawal and erasure requests with hashed one-time codes, attempt limits, supersession and scoped sessions that remain available when intake is disabled.
- [x] Add owner/Super Admin legal holds that block erasure and retention.
- [x] Implement policy-driven application anonymization and daily scheduled retention command; decision-based retention clocks use reviewed_at with submitted_at fallback. Activation requires positive approved retention periods, SMTP and explicit scheduler approval.

The current booking implementation is for companionship workflows only. Model-agency applications, external representation-agreement recording, private portfolio curation, purpose-bound image-licence records, internal casting calls, admin-only assignments and the settlement ledger are separate domains; none is routed through companionship bookings. The casting register supports private briefs, licensed submissions and human-administered decisions. Assignments start only from selected submissions and record external offer, model acceptance evidence, confirmation and completion; they do not create calendar bookings or image delivery. The settlement ledger reviews expenses, snapshots gross/commission/approved expenses/net using integer minor units, and records an operator-attested external payment or reversal; it does not move or verify funds. Remaining model-agency work includes applicant/model self-service portfolio, casting and assignment controls, secure evidence-document storage and retrieval, approved e-signature or independently verifiable execution, client-facing casting/assignment review and distribution, assignment booking/rescheduling, model availability, consent-aware client/public image delivery, usage monitoring, actual payment integrations, tax/withholding, invoices, partial settlements and statutory reporting. The image-licence ledger records external execution and per-use consent but does not itself deliver images. Portfolio and casting assets remain private and administrator-controlled.
Partially implemented: client and companion account authentication; verified email; manual requests; admin-configurable automatic eligibility; companion opt-in/withdrawal; weekly availability windows; deposit verification attestation; overlap checks; client and companion booking views. Remaining: date-specific availability exceptions, calendar sync, cancellation/rescheduling policies, notifications, tamper-evident audit export/retention, and full companion profile editing.

## Release 3 — Payments
Payment adapter contract; manual instructions; crypto invoice snapshots and server-side chain verification for approved networks; ledger and reconciliation; card adapters only after written underwriting.

## Release 4 — Commercial packaging
Installation wizard; versioned updates and migration tooling; backup/restore; deployment entitlement; customer deployment records; reseller documentation and support boundaries.

A milestone is not complete because screens exist. Each release requires tests, security review, accessibility checks, privacy review, deployment verification and operator documentation. Production is blocked pending jurisdiction, hosting and payment approvals.


## Super Admin control plane (approved architecture; implementation in progress)

- [x] Define and seed a deployment feature registry with stable keys, module ownership, implementation status, dependencies, risk classes and activation prerequisites.
- [x] Add a distinct `super_admin` principal to privileged authentication, route and MFA gates without silently promoting existing accounts.
- [x] Add a singleton deployment-control record and transactional CLI bootstrap that provisions at most one Super Admin.
- [x] Restrict deployment-wide branding, globalization, destinations, booking rules, payment configuration, site pages and feature controls to `super_admin`; retain day-to-day agency operations for `owner` and `admin`.
- [x] Protect the designated Super Admin against Eloquent-based demotion, deactivation and deletion; prevent a second principal after assignment; add regression tests. (Direct SQL bypass remains outside the application boundary.)
- [x] Implement CLI-only recovery enrollment and MFA reset: verify two notification addresses, issue a hashed single-use offline key, require current password plus key for recovery, rotate the key, invalidate authenticated and pending-MFA sessions and append audit events.
- [x] Provide an enrollment path for existing deployments using current password and TOTP.
- [x] Provide verified recovery contact and offline-key rotation, notify old and new addresses, and revoke prior admin sessions.
- [x] Implement CLI-only two-owner break-glass recovery for loss of both Super Admin password and offline key; require two distinct active verified Owner custodians, separate passwords/TOTP secrets/email codes, transactional revalidation, notifications, audit and session invalidation.
- [x] Add a dedicated finance role with MFA/session-version enforcement and an isolated model-settlement route/workspace boundary; general administrators cannot access the finance workspace.
- [x] Enforce independent maker-checker separation for expense review, settlement approval and payment attestation at the service transaction boundary; no owner/Super Admin self-approval exception.
- [x] Add a dedicated verifier role with MFA, a minimal eligibility-only queue, and no access to general agency or finance workspaces.
- [x] Add a dedicated safeguarding specialist role with MFA, assigned-case-only access, encrypted case content, immutable case events and detail-view access auditing.
- [x] Add metadata-only legal-hold governance with independent maker-checker release and append-only case/access audit events.
- [x] Implement disabled-by-default, policy-gated removal of per-case content keys from the primary database for closed cases, excluding active legal holds and preserving case/audit metadata.
- [x] Add a privacy-minimized safeguarding email outbox with MFA-gated internal links, critical/minor-safety escalation contacts, bounded retries and cPanel-compatible scheduled delivery.
- [x] Add an optional HashiCorp Vault Transit key provider with non-exportable per-case keys, separate application/retention credentials, HTTPS-only transport and retry-safe remote key destruction.
- [x] Add a maintenance-only post-restore reconciliation command that reapplies approved retention, verifies active external case keys, destroys orphaned Vault keys and records a restore verification result.
- [ ] Independently verify database/Vault backup expiry and complete database-plus-Vault restore drills; complete independent safeguarding response drills.
- [ ] Complete legacy owner/admin least-privilege migration and independent human custodian identity-proofing/operational drills.
- [x] Implement server-side membership entitlement grants, immutable grant records, active-membership/date evaluation, private-gallery delivery authorization and public-discovery exclusion; activation remains disabled until Super Admin review.
- [ ] Implement additional protected benefits such as priority concierge handling only when their complete operational workflows and server-side enforcement are delivered.
- [x] Record membership assignment and supersession in an actor-attributed append-only lifecycle ledger; recheck the membership feature gate inside the client-locked transaction.
- [x] Build a Super Admin-only read-only feature catalogue showing implementation state, configured/effective state, dependencies, risk and activation prerequisites.
- [x] Build feature mutation controls with impact preview, prerequisite evaluation, reason capture, password/TOTP step-up and transactional rollback.
- [x] Add dependency-aware server-side feature resolution and route middleware for public discovery, dynamic CMS pages, client/companion portals and companionship booking endpoints.
- [x] Recheck the automatic-booking feature gate inside the booking transaction before automatic confirmation.
- [x] Keep companion consent withdrawal and media removal outside the companion-portal feature gate, with a standalone privacy-controls page.
- [x] Enforce operational activation prerequisites in feature middleware, dependency evaluation, the admin catalog, public intake and new-work service boundaries; preserve cancellation, consent withdrawal, revocation, rejection, disputes and financial close-out when new work is paused.
- [ ] Complete scheduled-job and integration-boundary feature enforcement, direct-database bypass testing, audit-integrity verification and comprehensive feature-gate bypass tests.
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
- [x] Commit and validate the generated `composer.lock` through reviewed PR #20; CI installs the locked graph on PHP 8.3, 8.4 and 8.5.
- [x] Build and verify a reproducible standalone production-package smoke artifact in CI, including locked production dependencies, manifest, checksum and forbidden-file checks.
- [ ] Produce signed, versioned/tagged customer release artifacts with provenance/attestation, retention policy and release notes; CI smoke artifacts are temporary validation outputs, not production releases.
- [x] Retire the privileged lockfile-bootstrap workflow after the committed lockfile became mandatory; missing lockfiles now fail normal CI rather than granting automation repository write permissions.
- [x] Add automated PHP 8.3/8.4/8.5 compatibility and actual extension/platform checks in CI.
- [ ] Build a secure no-SSH installer and first Super Admin bootstrap, with no arbitrary command execution or public setup backdoor.
- [ ] Add preflight diagnostics for PHP web/CLI mismatch, extensions, GD WebP, database, writable paths, HTTPS, document-root exposure and hosting limits.
- [ ] Test supported cPanel/Apache/LiteSpeed configurations and document provider-specific exceptions.
- [ ] Add deployment smoke tests, restore drills and a per-installation compatibility report.
- [ ] Verify the current application can run with file cache/session and sync queue without Redis or persistent workers; disable scheduler-dependent features when cron is unavailable.
