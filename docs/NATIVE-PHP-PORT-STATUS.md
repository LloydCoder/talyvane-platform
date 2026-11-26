# Native PHP port — reconciled implementation status

**Repository:** `LloydCoder/talyvane-platform`  
**Main baseline:** `d58ebea85e04ee334a76743df098d168d2c3bbf3`  
**Status:** substantial native implementation in progress; not complete, not a production release, and not approved for deployment.

## Authority and how to read this status

The Laravel application and the product/security/compliance documents in `/docs` remain the behavioral and requirements reference. The native PHP implementation is a separate implementation track. Do not infer that a native-only feature has been merged into Laravel or deployed merely because it exists in the native application.

Use the following order when determining native parity:

1. The relevant canonical product/security/compliance requirement in `/docs`.
2. The native implementation and its migration/service/controller/view code.
3. The native tests and exact-head CI evidence.
4. `implementations/php-native/PORTING-TRACKER.md` for the feature-by-feature execution ledger.
5. This document for a concise cross-domain status and current release gates.

A route, view, table, stub, static test or successful package build is not by itself feature parity.

## Merged implementation and validation baseline

- PR #90 merged initial native runtime/installer foundations into `main` as `9123d08c4dc47b52a943c5531c6365e9e0d59810`.
- PR #93 merged the 38-migration native booking, privacy, casting, assignment, membership/gallery and safeguarding increment as `b6fd78e342b47e1bbbee9b5e80801a00baa977f8`.
- PR #87 merged hardened private cPanel installer diagnostics as `9df1a0f7a50f71bf1f4f2a0a81c519cc4db2ddff`.
- PR #94 merged locale-versioned CRM templates and encrypted idempotent delivery as `956044031f38514afaf0ef83bd38732f85f339c2`, bringing the native migration count to 39.
- PR #95 merged verified privacy outcome notification safeguards as `d692dac3bf4e61999c35a2917010d138871969f9`.
- PR #96 merged external privacy-erasure evidence registry and owner-reviewed external release gates as `98eea044831502eee18fcd18a4ed707358300606`, bringing the native migration count to 40.
- PR #97 reconciled the documentation baseline as `bf2b8ae3168ec890041fae8d90da73e3dcfe126b`.
- PR #98 merged verified client model-agency distribution as `b5a9f680386b3b1ed4a41d13b53d2b6fd28c2cd0`, bringing `main` to 41 ordered migrations. Exact-head PHP 8.3/8.4/8.5, MySQL (41 migrations and 52 model-portfolio assertions), Semgrep, native package, single-ZIP, native installer browser E2E, Laravel CI, installer browser E2E and standalone release-package smoke all passed (runs `45845162811`, `45845162135`, `45845162259`, `45845161921`, `45845162073`, `45845162114`, `45845162582`). This is repository-level verification, not production-host or legal approval.
- PR #99 merged OTP-authenticated model profile and portfolio self-service as `e885851c6a8becb27f9163586b1f9e8fec2cf352`. It adds model workspace access, profile correction, private portfolio upload/preview/withdrawal and read-only licence/casting/assignment records; it reuses the verified 30-minute rights session and adds no migration. Exact-head PHP 8.3/8.4/8.5, 41-migration MySQL (59 model-portfolio assertions), Semgrep, native package, single-ZIP, native browser install, Laravel CI and installer browser E2E all passed (runs `45847914401`, `45847947801`, `45847947568`, `45847947670`, `45847947614`, `45847947799`, `45847947980`). This verifies the repository slice, not full model self-service or production-host acceptance.
- PR #100 merged OTP-session-bound applicant casting submissions as `ac5674d37cef385aece7412afc2d2086c04f0143`; exact-head PHP 8.3/8.4/8.5, MySQL (41 migrations and 65 model-portfolio assertions), Semgrep, package, browser installation and standalone release smoke all passed. Applicant submissions are scoped to the verified model session and attributed to `actor_type=applicant` without a synthetic user.
- PR #102 merged execution-evidence-first model assignment responses as `81438d80b88592d3de1cacfaa34503c5f200e0ef`; exact-head PHP 8.3/8.4/8.5, MySQL (41 migrations and 71 model-portfolio assertions), Semgrep, package, browser installation, Laravel and standalone release smoke all passed. The agency records an external executed-document reference; only the OTP-verified model can accept or decline.
- PR #101 was closed as superseded by PR #102; its alternative intent-first schema was not merged.
- PR #91 was closed as superseded. There were no open PRs from that set after those actions.

Exact native parity head `0d836a9cf7c051ef31f9f42b7d6b2ad3a7662ac3` passed native PHP 8.3/8.4/8.5 tests, MySQL migration/integration, Semgrep, native package verification, single-ZIP cPanel package, native installer browser E2E, Laravel feature CI and standalone release-package smoke (workflow runs 45830668363, 45830668306, 45830668346, 45830668297, 45830668209, 45830668238 and 45830668285). The later private-installer diagnostics head `f6b14f37ebd8ea936e638de91189d8478fa683ab` passed PHP matrix, Semgrep, package, installer browser E2E and standalone package smoke (runs 45832598812, 45832599142, 45832599029 and 45832598827).

These are repository-level checks only. They do not establish actual HostSailor cPanel/LiteSpeed acceptance, SMTP operation on the target host, external Vault/key custody, legal/provider approval, independent security certification or production readiness. Any later runtime change requires fresh exact-head validation.

## Completed phase — applicant casting self-service

PR #100 merged as `ac5674d37cef385aece7412afc2d2086c04f0143`. Applicant application identity is resolved server-side from a live `portal_access` rights session; submissions accept only a casting-call ID, an owned portfolio asset ID and an image-licence ID. The casting service repeats strict eligibility checks (open call, accepted application, no legal hold, current independent adult verification, approved private image and integrity hash, active purpose/territory/date/channel-specific licence, executed representation agreement, current consent grants and policy versions, and approved market), then enforces per-call duplicate and capacity controls transactionally. Applicant events use `actor_type=applicant` with a null user actor. Exact-head PHP 8.3/8.4/8.5, MySQL (41 migrations and 65 model-portfolio assertions), Semgrep, package, browser installation and standalone release smoke passed. This is repository-level verification, not legal/adult-verification provider approval.

## Completed phase — execution-evidence-first model assignment response

PR #102 merged as `81438d80b88592d3de1cacfaa34503c5f200e0ef`. Agency staff can record an opaque external executed-document evidence reference but cannot attest model acceptance or decline. The model sees only its own offered assignment in the OTP-verified workspace and may accept only after execution evidence is recorded, or decline without needing to satisfy ongoing booking/usage eligibility checks. Acceptance rechecks the selected submission, application, adult verification, approved private asset integrity, active licence, executed representation agreement, current consent grants, policy versions, market and schedule. Applicant statements and decline reasons are encrypted; events use `actor_type=applicant` and a null user actor. Exact-head PHP 8.3/8.4/8.5, MySQL (41 migrations and 71 model-portfolio assertions), Semgrep, package, browser installation, Laravel and standalone release smoke passed. The external document reference remains an operator-recorded evidence reference, not independent signature or byte verification.

## Current in-progress phase — applicant response provenance hardening

PR #103 adds migration 0042 for response protocol version, hashed request fingerprint and model-visible offer-term hash. It adds explicit portal attestation, idempotent accept/decline replay (including after assignment progression), rejects request-key reuse for a different decision, formats compensation/expense amounts using ISO currency precision, and prevents unoffered drafts from appearing in applicant portal projections. Internal agency commission remains excluded from the applicant projection and terms hash. Exact-head PHP 8.3/8.4/8.5, MySQL (42 migrations and 77 model-portfolio assertions), Semgrep, package verification, single-ZIP, browser installation, Laravel and standalone release smoke all passed. This is repository-level verification; provider signature/document verification and target-host acceptance remain external.
- PR #104 merged as `c366579b732977ebc02991b0e6ec49b7b54f52c6` with OTP-verified applicant-initiated withdrawal of consent for a specific image licence. The portal resolves application identity from the live rights session; the service enforces licence ownership, revokes the licence and consent grant, invalidates dependent casting submissions and records applicant-attributed events in one transaction. No migration is required. Exact-head PHP 8.3/8.4/8.5, MySQL (42 migrations; 78 model-portfolio assertions), Laravel, Semgrep, package/installer, browser installation, single-ZIP and standalone release checks passed on `ac5975505655491667c4c40b6bf04a0348e8bbf5`.

Remaining model self-service work includes licence/consent grant and renewal decisions, availability and calendar conflict response, complete account recovery, browser acceptance and independent adult-verification provider integration. PR #104's specific-licence consent withdrawal is merged and repository-validated; client notification, provider delivery evidence and legal/production acceptance remain open. External legal/provider approvals remain release gates.

## Current implementation phase

PR #94 added migration 0039 for replay-safe template publication plus versioned locale-specific CRM response templates, MFA-authorized reply queueing, encrypted recipient/payload outbox records, idempotency and delivery-state reconciliation. Runtime head `581fb753ce4d0c3439fae7a8d8647b53824da406` passed PHP 8.3/8.4/8.5 tests, MySQL integration (39 migrations and 30 enquiry/CRM assertions), Semgrep, native package verification, single-ZIP package, native installer browser E2E, Laravel CI and installer browser E2E (runs 45835096236, 45835096363, 45835096225, 45835096465, 45835096187, 45835096125 and 45835096293). The documentation-only follow-up does not change runtime code. This is repository-level acceptance only; production mail delivery still requires private cPanel cron configuration and target SMTP acceptance. Bounce/complaint reconciliation, delivery monitoring and template governance remain open.

## Implemented native domains — partial vertical slices

| Domain | Implemented on this branch | Remaining parity / acceptance |
|---|---|---|
| Runtime and cPanel installer | Native HTTP kernel, private configuration/storage boundaries, browser installer, dual-email Super Admin bootstrap, first-login password replacement/MFA, ZIP packaging and package contract | Exact-head browser and package workflows; real cPanel/LiteSpeed host acceptance; upgrade/rollback |
| Identity and portals | Client/companion registration and login, email verification, recovery, MFA-gated admin, account exports, verified privacy-rights request intake/history; verified client/companion contact-email change with current-password confirmation, OTP to the new address, session revocation and old-address security notice | Full account lifecycle and cross-role negative authorization matrix; browser and target-SMTP acceptance for the new email-change journey |
| Privacy rights | MFA review; client display-name and companion profile correction fulfilment with encrypted completion notices; guarded client/companion account anonymization with dependency blockers and session invalidation; applicant email-OTP portfolio withdrawal bound to one asset, with dependent licence/casting invalidation | full linked-data legal-hold/retention reconciliation for every data class; media/object-store deletion proof; third-party propagation; full outcome delivery/monitoring (the current phase now fails closed when the encrypted outbox or current verified destination is unavailable); backup expiry proof |
| Companion/client operations | Booking request/history/cancel/reschedule, companion decisions and availability, client shortlist, agency-managed membership assignment/suspension/cancellation, and verified client-only model casting/assignment distribution with rights-checked private previews | Full companion profile/media self-service, receipts/support parity |
| Membership and gallery | Immutable entitlement grants, active/date-scoped authorization, protected members-only gallery and opaque media delivery with consent/market/file-integrity checks | Legal approval and target-deployment activation; independent media/consent withdrawal and storage acceptance |
| Model agency | Separate applicant intake/rights, human verification, representation agreements, private portfolio, purpose-bound image licences, private casting calls/submissions/shortlist/selection, applicant-originated casting submissions (PR #100), execution-evidence-first model assignment acceptance/decline (PR #102), settlement ledger, OTP applicant privacy rights and verified model profile/portfolio self-service (PR #99); verified client-only casting/assignment distribution (PR #98) | Licence/consent grant and renewal decisions, availability/booking/calendar, external signature and document verification, usage monitoring, tax/invoices/statutory reports and independent adult-verification provider acceptance |
| Payments | Payment configuration foundations and model settlement calculations/approvals/external payment attestations | No full customer payment provider integration, chain watchers, card underwriting, invoice/reconciliation/refund/dispute automation or actual funds movement |
| Safeguarding | Restricted case intake/access, encrypted case content, assigned specialists, MFA Owner/Super Admin legal-hold governance, retention policy and restore-verification controls, encrypted notification outbox and dispatch path | External Vault/key custody and real restore drill; external-system holds; full incident/appeal/correction operations; operational escalation/response exercises and backup expiry evidence |
| CRM and communications | Enquiry intake/pipeline/assignment, encrypted internal notes, follow-up reminders, bounded inbox pagination; current phase adds versioned response templates, MFA-gated reply queueing, encrypted idempotent outbox and SMTP delivery attempts | Exact-head test/HTTP acceptance; bounce/complaint reconciliation, delivery monitoring/alerting, template approval lifecycle, provider breadth and broader CRM workflows |
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

## Current privacy-erasure evidence phase

PR #96 added migration 0040 and an MFA-verified Owner/Super Admin external-evidence registry. Client and companion erasure now requires recorded decisions for encrypted database backups, private storage backups, SMTP/mailbox providers, payment processors, analytics integrations, external evidence repositories and safeguarding key custody. A `retained` decision with a future expiry remains blocking; `cleared` and `not_applicable` require an evidence reference and operator rationale. These are accountable human attestations, not independent proof that an external provider deleted data or that backups expired. Runtime head `52d6733c87e36e37307517364b17a2151fc460fb` passed PHP 8.3/8.4/8.5, MySQL integration (40 migrations and 165 identity assertions), Semgrep, native package verification, single-ZIP cPanel package, native installer browser E2E, Laravel CI, installer browser E2E and standalone release-package smoke (runs 45839353795, 45839353548, 45839353572, 45839353591, 45839353958, 45839353554 and 45839353495). This is repository-level verification; provider/legal/host acceptance remains external.
