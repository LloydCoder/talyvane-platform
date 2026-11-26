# Native PHP port — reconciled implementation status

**Repository:** `LloydCoder/talyvane-platform`  
**Branch:** `feat/native-private-gallery`  
**Pull request:** [#93](https://github.com/LloydCoder/talyvane-platform/pull/93) — open, draft, unmerged  
**Status:** substantial implementation in progress; not complete, not a production release, and not yet approved for deployment.

## Authority and how to read this status

The Laravel application and the product/security/compliance documents in `/docs` remain the behavioral and requirements reference. The native PHP implementation is a separate implementation track. Do not infer that a native-only feature has been merged into Laravel or deployed merely because it exists on this branch.

Use the following order when determining native parity:

1. The relevant canonical product/security/compliance requirement in `/docs`.
2. The native implementation and its migration/service/controller/view code.
3. The native tests and exact-head CI evidence.
4. `implementations/php-native/PORTING-TRACKER.md` for the feature-by-feature execution ledger.
5. This document for a concise cross-domain status and current release gates.

A route, view, table, stub, static test or successful package build is not by itself feature parity. A feature is verified only when positive and negative behavior, authorization, audit, data handling, integration and operational acceptance are evidenced.

## Current head and validation

Latest runtime-affecting implementation head: `f62c6f314f468644e6b6ca7434001279f33ff26f`. The subsequent tracker-only reconciliation commit `3f8a472351f2e1059601c009489aa488b7e69cea` does not change runtime behavior. Exact-head native PHP 8.3/8.4/8.5 tests, MySQL 8.0 integration (37 migrations), native cPanel package verification, single-ZIP package verification, security analysis and native installer browser E2E have passed. The general CI/Laravel release smoke and Laravel installer browser E2E are still running at the latest documentation head; do not mark them passed until their exact-head runs complete. The runtime implementation head passed these required repository workflows:

| Check | Exact-head result |
|---|---|
| PHP 8.3/8.4/8.5 native implementation tests | Passed |
| MySQL 8.0 migrations/integration | Passed |
| Security analysis | Passed |
| Native cPanel package contract | Passed |
| Single-ZIP cPanel package build | Passed |
| Native installer browser E2E | Passed |
| Laravel installer browser E2E | Passed |
| General CI | Passed |

The PR remains open, draft and unmerged. These are repository-level results, not live-host acceptance. The current native branch has 37 ordered migrations. Any later runtime change requires fresh exact-head validation.
## Implemented native domains — partial vertical slices

| Domain | Implemented on this branch | Remaining parity / acceptance |
|---|---|---|
| Runtime and cPanel installer | Native HTTP kernel, private configuration/storage boundaries, browser installer, dual-email Super Admin bootstrap, first-login password replacement/MFA, ZIP packaging and package contract | Exact-head browser and package workflows; real cPanel/LiteSpeed host acceptance; upgrade/rollback |
| Identity and portals | Client/companion registration and login, email verification, recovery, MFA-gated admin, account exports, verified privacy-rights request intake/history | Full account lifecycle and cross-role negative authorization matrix |
| Privacy rights | MFA review; client display-name and companion profile correction fulfilment; guarded client/companion account anonymization with dependency blockers and session invalidation; applicant email-OTP portfolio withdrawal bound to one asset, with dependent licence/casting invalidation | Client/companion contact-email re-verification; legal-hold/retention reconciliation for every data class; media/object-store deletion proof; third-party propagation; full outcome delivery/monitoring; backup expiry proof |
| Companion/client operations | Booking request/history/cancel/reschedule, companion decisions and availability, client shortlist, agency-managed membership assignment/suspension/cancellation | Full companion profile/media self-service, receipts/support parity, client-facing model casting/assignment |
| Membership and gallery | Immutable entitlement grants, active/date-scoped authorization, protected members-only gallery and opaque media delivery with consent/market/file-integrity checks | Legal approval and target-deployment activation; independent media/consent withdrawal and storage acceptance |
| Model agency | Separate applicant intake/rights, human verification, representation agreements, private portfolio, purpose-bound image licences, private casting calls/submissions/shortlist/selection, admin assignment lifecycle and settlement ledger; OTP-verified applicant portfolio inventory and single-asset withdrawal now revokes consent/licences and invalidates dependent casting submissions | Broader model self-service, client casting/assignment distribution, e-signature/evidence custody, assignment booking/calendar, usage monitoring, tax/invoices/statutory reports |
| Payments | Payment configuration foundations and model settlement calculations/approvals/external payment attestations | No full customer payment provider integration, chain watchers, card underwriting, invoice/reconciliation/refund/dispute automation or actual funds movement |
| Safeguarding | Restricted case intake/access, encrypted case content, assigned specialists, MFA Owner/Super Admin legal-hold governance, retention policy and restore-verification controls, encrypted notification outbox and dispatch path | External Vault/key custody and real restore drill; external-system holds; full incident/appeal/correction operations; operational escalation/response exercises and backup expiry evidence |
| CRM and communications | Enquiry intake/pipeline/assignment, encrypted internal notes, follow-up reminders, bounded inbox pagination, shared notification transport for booking, safeguarding and privacy outcomes | CRM-specific templates/outbox delivery, response workflows, provider breadth, delivery diagnostics/monitoring and browser acceptance |
| Globalization/content | ISO country/currency reference snapshots, markets, destinations, CMS, branding and baseline locale/time-zone fields | Translation management/fallback, full locale formatting, market-specific pricing/tax, reports and external integrations |
| Audit and operations | Append-only application audit controls, private error logs, healthcheck, bounded notification CLI and file-level SPDX SBOM | Independent DB immutability/security review, monitoring/alerting, full dependency SBOM, signing/provenance, tested backups and recovery |

## Privacy and erasure safety boundary

Client and companion erasure fulfilment is an explicit, MFA-authorized administrative action following verified request intake. The implementation anonymizes the account only when the role boundary and dependency checks permit it. Known retained bookings, memberships, invoices, support tickets, safeguarding reports, enquiries, companion bookings and private profile media block fulfilment. Companion publication/consent is withdrawn and the profile is de-identified before the account is anonymized.

This is not a claim that every historical copy has been erased. The current service does not independently prove deletion from database backups, private storage backups, SMTP/provider systems, external evidence repositories, payment providers or other third parties. Legal holds and applicable retention schedules must be reconciled for every linked data class before a deployment can claim complete erasure. Where that cannot be proven, the request must remain deferred or blocked and the reason communicated through a verified outcome channel.

## Financial boundary

The model-agency settlement ledger is an internal calculation and approval register. A `paid` state records an administrator's external payment attestation and evidence reference; it does not initiate, custody, route or independently verify money. The companion/client payment subsystem remains configuration groundwork until real providers, invoices, observations, reconciliation, adjustments and jurisdiction/provider approvals are implemented and accepted. Never conflate the model settlement ledger with customer checkout or payment processing.

## Safeguarding and external key custody

The native safeguarding retention service fails closed unless an external key provider supports irreversible per-case key destruction, the encrypted-backup expiry policy is approved, and a recent restore/key-reconciliation verification by a different MFA Owner/Super Admin exists. Local application-key custody is insufficient to promise global cryptographic erasure. External Vault deployment, separate retention credential, Vault backup expiry, restore drill and provider evidence are external release gates. The platform's own legal-hold flag does not preserve third-party records.

## Release blockers — do not bypass

- Exact-head native MySQL, browser, CI and package workflows must all pass.
- Real cPanel/LiteSpeed installation, filesystem permissions, SMTP and cron acceptance.
- External Vault/key custody, backup expiry and independent restore/recovery drills.
- Payment-provider underwriting, credentials, webhook security and legal approval before live payment activation.
- Qualified jurisdiction-specific legal, privacy, safeguarding and tax review.
- Complete incident/appeal operations and trained human response procedures.
- Signed/tagged release, dependency SBOM, build provenance/attestation, release signing-key custody and independent approval.
- Independent security/accessibility/performance review and documented rollback/recovery plan.

**Do not merge, publish a production ZIP, activate payment rails, or describe the native port as complete solely on the basis of this status document.**
