# Laravel → Native PHP parity tracker

**Authority:** the Laravel product requirements, architecture, security/privacy, safeguarding, compliance, TGLS, booking, payments, membership, media-governance, installer, recovery and operations documents in `/docs` are canonical. This tracker is the execution ledger for the native PHP port.

**Status definitions**
- `NOT_STARTED`: no native implementation.
- `IN_PROGRESS`: implementation exists but acceptance is incomplete.
- `IMPLEMENTED_UNVERIFIED`: code exists; required tests/runtime evidence are incomplete.
- `VERIFIED`: behavior, negative cases, authorization, audit and operational requirements have passing evidence.
- `BLOCKED`: requires external approval/provider capability or unresolved product decision.
- `NOT_APPLICABLE`: documented rationale required.

A screen, route, database table, stub, or passing unit test alone does not establish feature parity.

## 1. Runtime, installation and operations

The native branch now has 24 ordered PHP migrations and connected slices for identity, companion onboarding/publication/availability/media-consent, public discovery and consent-gated profile-media delivery, client shortlists and membership assignment/entitlement gates, bookings, model-agency applications/rights/agreements, destination/market administration, pinned ISO reference data, branding, CMS, CRM, audit investigation, private error logging, safeguarding case intake/assigned access/legal holds, encrypted notification outbox, retention policy and restore-verification records. PR #90 was merged into `main` as merge commit `9123d08c4dc47b52a943c5531c6365e9e0d59810`. The merged code head passed PHP 8.3/8.4/8.5 native lint/unit tests, MySQL integration (23 migrations; 206 assertions, including 30 membership assertions), Semgrep, native ZIP build/contract and archive checks, the existing Laravel feature suite, and native installer browser E2E (workflow run 80, run ID 45671355809, tested code head `4d7726bb922fba1d62443deff0daa0feef4e15c9`). These are repository-level checks, not live HostSailor cPanel/LiteSpeed acceptance. The single-ZIP installer, first Super Admin dual-email bootstrap, first-login password replacement/MFA and package are implemented and automated-tested; actual HostSailor acceptance, operational backup/restore and release provenance remain open. Public discovery and media consent, membership assignment/suspension/cancellation, safeguarding intake and case governance, and model-agency intake remain partial vertical slices. The native application is not a complete product: verified client/companion correction, erasure and retention rights (self-service export is now implemented), model portfolio/casting/assignment/settlement, complete safeguarding incident/appeal operations, payments/ledger/reconciliation, full CRM/communications, localization/reporting/integrations, upgrades/rollback and operational recovery remain open.

| Capability | Laravel reference | Native status | Acceptance evidence |
|---|---|---:|---|
| Single ZIP cPanel installation | CPANEL-INSTALLATION, SECURE-BROWSER-INSTALLER | IN_PROGRESS | Native-PHP customer ZIP builds reproducibly and passes archive contract/private extraction and rejection tests; current-head native installer browser E2E passed (run 80); actual HostSailor cPanel/LiteSpeed acceptance remains open |
| Private app placement | HOSTING-COMPATIBILITY, ADR-0014 | IN_PROGRESS | Source/config/vendor/logs/uploads/backups inaccessible over HTTP |
| PHP/runtime preflight | HOSTING-COMPATIBILITY | IN_PROGRESS | Web-SAPI extension/version check, DB and filesystem capability check |
| One-time installer access key | SECURE-BROWSER-INSTALLER | IN_PROGRESS | 256-bit private cPanel key outside public root, owner-only permissions, HTTPS-only wizard, CSRF, session throttling, atomic encrypted pending state and consumption only after verified permanent lock; HostSailor acceptance remains open |
| Dual-email verification | Installer and recovery requirements | IN_PROGRESS | Native browser installer now uses separate primary/recovery mailbox challenges, 10-minute codes, 30-minute bootstrap expiry, DB-backed rate limits, resending, both-address verification and resumable bootstrap proof; dual-mailbox browser E2E passed on PR #90 code head; target-host SMTP acceptance remains open |
| Super Admin bootstrap | SUPER-ADMIN-RECOVERY | IN_PROGRESS | EmailVerificationService creates a singleton pending bootstrap, requires two distinct verified addresses, generates a one-time initial password and offline recovery key, persists both encrypted before activation commit, and displays both once in a session-bound HTTPS response with no-store/no-referrer protections; first-login password replacement/MFA passed in native browser E2E; actual-host validation remains open |
| Installer permanent lock | SECURE-BROWSER-INSTALLER | IN_PROGRESS | Durable lock; malformed/missing state fails closed; cleanup not required for lock |
| Private diagnostic logging | PrivateErrorLogger | IN_PROGRESS | Private runtime log records correlation ID, exception class and code only; no exception message, stack trace or request data; owner-only file permissions and private-root validation. Rotation, monitoring, alerting and installer diagnostics remain open |
| Upgrade/migration process | DEPLOYMENT-OPERATIONS | IN_PROGRESS | Forward-only migration, backup, compatibility and rollback evidence |
| Backup/restore | DEPLOYMENT-OPERATIONS | IN_PROGRESS | Safeguarding encrypted-backup policy and restore-verification evidence exist; actual database + private-file backup creation, isolated restore drill, integrity comparison and rollback acceptance remain open |
| Health checks and operations | ARCHITECTURE, DEPLOYMENT-OPERATIONS | NOT_STARTED | Safe diagnostics, SMTP, storage, scheduler and disk monitoring |
| Scheduler without workers | ARCHITECTURE, HOSTING-COMPATIBILITY | NOT_STARTED | Cron optional; missing scheduler explicitly disables scheduled features |
| Release artifact/provenance | RELEASE-PACKAGING | NOT_STARTED | Reproducible ZIP, SHA-256, SBOM, signed/tagged release and exclusions |

## 2. Identity, authentication and authorization

| Capability | Laravel reference | Native status | Acceptance evidence |
|---|---|---:|---|
| Public visitor identity boundary | PRODUCT-REQUIREMENTS | NOT_STARTED | No implicit account creation |
| Client registration/login/logout | routes/web.php, portal auth | IN_PROGRESS | Native `/portal/register`, `/portal/login`, `/portal` and logout journeys; CSRF, password policy, DB-backed rate limiting, role separation and session rotation; browser E2E and full account-management parity remain open |
| Client email verification | portal auth | IN_PROGRESS | Six-digit, 10-minute, single-use, rate-limited SMTP verification and resend route; no-SMTP configuration fails closed; browser acceptance remains open |
| Companion provisioning/login | SECURITY-PRIVACY | IN_PROGRESS | Companion role and separate portal login are wired; trusted onboarding, independent adult verification, safeguarding and profile-management journeys remain incomplete |
| Admin login/logout | admin routes | IN_PROGRESS | CSRF-protected browser flow, throttling, secure sessions and permission-checked entry point; audit coverage and browser acceptance remain open |
| Roles: Super Admin, Owner, Admin | SECURITY-PRIVACY | IN_PROGRESS | Server-side least privilege and negative authorization tests |
| Finance specialist isolation | SECURITY-PRIVACY | IN_PROGRESS | Finance sees settlement workspace only |
| Verifier specialist isolation | SECURITY-PRIVACY | IN_PROGRESS | Minimal adult-eligibility queue only |
| Safeguarding specialist isolation | SECURITY-PRIVACY | IN_PROGRESS | Assigned-case access only |
| MFA/TOTP and replay prevention | SECURITY-PRIVACY | IN_PROGRESS | RFC-compatible TOTP, replay state, browser challenge/setup and one-time recovery codes; browser acceptance remains open |
| Session versioning/revocation | SUPER-ADMIN-RECOVERY | IN_PROGRESS | Password/recovery/role changes invalidate prior sessions and pending MFA |
| First-login password replacement | Installer E2E | IN_PROGRESS | Expiring one-time session, mandatory password replacement, session-version revocation and MFA enrollment handoff |
| Initial password recovery | Admin recovery routes | NOT_STARTED | Super Admin recovery journey still requires offline-key and dual-owner browser acceptance |
| Super Admin offline recovery | SUPER-ADMIN-RECOVERY | NOT_STARTED | Password + offline key, key rotation, notices, append-only audit |
| Two-owner break-glass | SUPER-ADMIN-RECOVERY | NOT_STARTED | Independent owners, dual verification, distinct credentials and approvals |
| Resource-scoped authorization | SECURITY-PRIVACY | NOT_STARTED | IDOR/negative tests for every resource boundary |
| Step-up privileged actions | PRODUCT-REQUIREMENTS | NOT_STARTED | MFA reauthentication, reason, impact preview, audit |

## 3. Public website, CMS and discovery

| Capability | Laravel reference | Native status | Acceptance evidence |
|---|---|---:|---|
| Responsive public homepage/brand shell | README, public views | IN_PROGRESS | Editorial responsive design implemented; browser accessibility and visual parity acceptance remain open |
| Dynamic branding | BrandingController | IN_PROGRESS | Super Admin brand workspace; name/tagline, palette and typography tokens, contacts, HTTPS social links; existing private logo/favicon references preserved; upload and full token application remain open |
| CMS pages and navigation | SitePageController | IN_PROGRESS | Admin page listing/edit/versioned save/reasoned publish; immutable public snapshot and rollback-as-draft; header/footer navigation manager with safe targets and public rendering; preview, rollback/history UI and browser acceptance remain open |
| Legal pages and policy versions | PRODUCT-REQUIREMENTS | NOT_STARTED | Versioned, publishable and auditable content |
| SEO metadata/defaults | PRODUCT-REQUIREMENTS | IN_PROGRESS | Public title suffix, page metadata and robots defaults applied; canonical URLs, sitemap, structured data and richer SEO controls remain open |
| Destination/city directory | DiscoveryController | IN_PROGRESS | Public destination listing only returns published destinations in currently approved, unexpired markets; destination administration and localization remain open |
| Destination administration | DestinationController | IN_PROGRESS | MFA-protected destination create/update; ISO country/currency references; IANA zone/locale; market approval. Native cover intake uses private WebP re-encoding, a versioned rights statement/hash, distinct MFA administrator review, opaque public delivery only for a currently published market, audited withdrawal and private-byte deletion. Current-head MySQL and browser acceptance remain required; destination ordering remains open |
| Companion/profile discovery | DiscoveryController | IN_PROGRESS | Public destination/profile routes expose allowlisted fields only and require verified adult eligibility, active versioned publication consent and current market approval; approved profile media is served through an opaque consent-gated route. Search filters and browser acceptance remain open |
| Profile publication workflow | PROFILE-MEDIA-GOVERNANCE | IN_PROGRESS | MFA-authorized review queue and reasoned/audited publication require independent verification, current market approval and unwithdrawn consent; media approval/licensing and browser acceptance remain open |
| Public profile/media delivery | ProfileMediaController | IN_PROGRESS | Companion image intake re-encodes JPEG/PNG/WebP to metadata-stripped WebP in owner-only private storage; human review requires separate adult-safety/rights/consent attestations; public opaque media route rechecks current discovery eligibility, exact consent grant/version and file hash. The separate members-only gallery is tracked below; full model portfolio remains open. Current-head integration/browser acceptance required |
| FAQs, city guides, testimonials | PRODUCT-REQUIREMENTS | NOT_STARTED | Admin-managed content and publication workflow |
| Contact/enquiry form | EnquiryController | IN_PROGRESS | CSRF, quiet honeypot, IP rate limit, validation, explicit versioned privacy acknowledgement, safe attribution and atomic creation audit; browser acceptance and notification delivery remain open |

## 4. Booking, scheduling and portals

| Capability | Laravel reference | Native status | Acceptance evidence |
|---|---|---:|---|
| Manual booking request | BookingController | IN_PROGRESS | Client portal request form uses destination-local time and revalidates market, adult eligibility, consent, client verification, policy and overlap transactionally; nonexistent DST spring-forward wall times are rejected rather than silently normalized. Ambiguous fall-back times still need an explicit disambiguation UX; booking browser E2E remains open |
| Automatic booking mode | Booking domain | IN_PROGRESS | Disabled by default; native booking now enforces the deployment feature dependency graph in addition to transactional availability/consent/market checks. Provider approval, jurisdictional legal approval and safeguarding readiness deliberately fail closed until implemented and evidenced; companion opt-in/out and admin policy UI remain open |
| Admin approval rules | Booking settings | IN_PROGRESS | Typed policy and fail-safe manual defaults exist; customer-facing policy configuration UI and approval notifications remain open |
| Companion explicit auto-book opt-in/out | Companion portal | NOT_STARTED | Immediate opt-out enforcement |
| Weekly availability windows | Companion portal | IN_PROGRESS | IANA/local schedule conversion and automatic-rule validation exist; companion schedule editing UI remains open |
| Overlap prevention | Booking domain | IN_PROGRESS | Transactional overlap checks are covered by integration tests; dedicated concurrent-load test remains open |
| Deposit verification gate | Booking admin | IN_PROGRESS | Deposit-required policy blocks booking without an accepted quote; finance reconciliation and human verification UI remain open |
| Booking state machine | ARCHITECTURE | IN_PROGRESS | Client cancellation, companion accept/decline and MFA-admin transitions are wired to the state machine; full browser E2E remains open |
| Quote snapshot/acceptance | TGLS, BOOKING | NOT_STARTED | Currency, market, policy and rate immutable on acceptance |
| Cancellation/rescheduling | ROADMAP | IN_PROGRESS | Client cancellation UI and reschedule service exist; reschedule UI, notifications and policy-specific cancellation fees remain open |
| Calendar synchronization | ROADMAP | NOT_STARTED | Provider adapter, retries, idempotency and privacy |
| Client portal | PRODUCT-REQUIREMENTS | IN_PROGRESS | Separate verified client identity, booking request/history/cancellation, private shortlist and agency-managed membership assignment/suspension/cancellation with append-only lifecycle events are wired; client sees only their current active membership summary and effective allowlisted entitlement keys. Router applies no-store/private cache headers to all `/portal` and `/admin` responses. Members-only gallery delivery is now a gated, consent-checked client workflow (tracked below); full account editing, receipts, payment status, support and verified correction/erasure/retention workflows remain open; a scoped client/companion data export is implemented but requires current-head MySQL/browser acceptance |
| Client favourites | ClientFavoriteController | IN_PROGRESS | Native client-only private shortlist supports save/remove/list, revalidates current public discoverability on add and filters hidden/unconsented profiles from the read model; transactional audit events and integration coverage added. Browser E2E and full portal account management remain open |
| Membership records | ClientManagementController | IN_PROGRESS | MFA-gated agency-owner/admin workspace supports assignment/replacement, immediate suspension and cancellation with reasons, transactions and append-only events; directory retains active/suspended status, and client portal shows only the client's current active summary/effective allowlisted entitlements. Integration coverage includes owner/admin authorization, ownership, suspension/cancellation entitlement revocation, replay rejection and role denial; current-head CI and browser acceptance remain required |
| Entitlement authorization | CLIENT-MEMBERSHIP | IN_PROGRESS | Active/in-date membership and immutable grant are checked by the entitlement service; cancellation/supersession revoke effective access. Protected members-only media delivery and the client-facing allowlisted entitlement projection now have MySQL integration coverage; browser acceptance and broader resource-scoped entitlement coverage remain open |
| Members-only private gallery | CLIENT-MEMBERSHIP, PROFILE-MEDIA-GOVERNANCE | IN_PROGRESS | Admin moderation supports `members_only`; companion portal now captures separate versioned per-image consent and withdrawal; client portal lists eligible images and serves opaque protected media routes. Delivery rechecks active membership/grant, current profile discoverability/market approval, exact public consent grant/version, private consent version and exact brand-bound statement hash, human review attestations and file hash; membership/media rows are locked during byte delivery so concurrent suspension/withdrawal serializes. 32 profile-media integration assertions cover canonical statement parity, explicit policy approval, brand invalidation, entitled delivery, role denial, membership suspension and withdrawal/deletion. Feature gate remains fail-closed unless enabled with an explicit legal-approval reference, current policy version and exact brand-bound consent-statement hash, plus current market/public-consent prerequisites; brand/policy changes invalidate prior consent. Current-head CI and browser E2E acceptance remain pending |
| Companion portal | CompanionPortalController | IN_PROGRESS | Separate companion sign-in and booking decision workspace; availability schedule editing, profile-media upload/withdrawal and publication-consent withdrawal/renewal are wired. Full profile/account editing, consent history, safeguarding self-service and browser acceptance remain open |
| Companion availability self-service | CompanionPortalController | IN_PROGRESS | Native companion-only schedule workspace at `/portal/companion/availability`; destination IANA time zone is server-derived; date-bounded weekly windows, overlap prevention, owner-scoped writes/deletes and transactional audit events have integration coverage. Browser E2E, DST boundary cases, schedule notifications and automatic-booking opt-in/out remain open |

## 5. Model agency and representation vertical

| Capability | Laravel reference | Native status | Acceptance evidence |
|---|---|---:|---|
| Independent model application intake | PRODUCT-REQUIREMENTS | IN_PROGRESS | Separate public form; no companion/client/booking creation; explicit adult attestation and timestamped Privacy Notice acknowledgement; requires exact published policy version and valid SMTP/privacy-rights delivery |
| Applicant policy/version gate | PRODUCT-REQUIREMENTS | IN_PROGRESS | Intake disabled until policies published/approved |
| Applicant email-verified rights session | ARCHITECTURE | IN_PROGRESS | Browser OTP journey for access/correction/withdrawal/erasure; one-time codes, generic responses, session rotation, audit events, and legal-hold-aware erasure; browser E2E remains open |
| Application review/state machine | ModelAgencyApplicationAdminController | IN_PROGRESS | MFA-authorized admin queue, reasoned transitions and append-only events; current latest verification decision is authoritative |
| Manual adult eligibility attestation | verifier role | IN_PROGRESS | Dedicated MFA verifier queue with no applicant name/contact; monotonic decision ordering and acceptance blocked unless latest decision is current and verified; external provider workflow remains unintegrated |
| Application legal hold/retention | PRODUCT-REQUIREMENTS | IN_PROGRESS | MFA-admin legal-hold controls; hold blocks erasure and records an audit event; scheduled retention execution remains open |
| Representation agreement register | Agreement controller | IN_PROGRESS | External execution evidence; not a signature service |
| Private portfolio media intake | PortfolioAsset controller | NOT_STARTED | Private storage, sanitization, consent and eligibility gates |
| Portfolio review/consent withdrawal | PortfolioAsset controller | NOT_STARTED | Human review, withdrawal removes private image |
| Purpose-bound image licence ledger | ImageLicence controller | IN_PROGRESS | Asset/agreement/territory/channel/window and revocation |
| Internal casting register | Casting controller | NOT_STARTED | Private briefs, active licence, human shortlist/selection |
| Model assignment lifecycle | Assignment controller | IN_PROGRESS | Selected submission only; offer/accept/confirm/start/complete/decline/cancel/dispute |
| Compensation/expense snapshots | Assignment domain | NOT_STARTED | Immutable commission and cap snapshot |
| Expense review and settlement ledger | Settlement controller | IN_PROGRESS | Integer minor units, cap enforcement, maker-checker approval |
| External payment/reversal/dispute evidence | Settlement controller | NOT_STARTED | Records attestations only; no transfer initiation/verification |
| Client casting/assignment distribution | PRODUCT-REQUIREMENTS | NOT_STARTED | Planned, separate authorization and consent-aware delivery |
| Model self-service | PRODUCT-REQUIREMENTS | NOT_STARTED | Planned application/portfolio/casting/assignment controls |
| E-signature integration | PRODUCT-REQUIREMENTS | NOT_STARTED | Approved provider and verifiable execution evidence |
| Tax/withholding/invoicing/statutory reports | PRODUCT-REQUIREMENTS | NOT_STARTED | Planned, jurisdiction-specific legal/accounting adapter |

## 6. Verification, consent, safeguarding and privacy

| Capability | Laravel reference | Native status | Acceptance evidence |
|---|---|---:|---|
| Independent age/identity assurance boundary | SECURITY-PRIVACY | NOT_STARTED | No automated age inference; provider integration remains gated |
| Versioned consent capture | PRODUCT-REQUIREMENTS | IN_PROGRESS | Purpose/audience/item/version/time/actor evidence |
| Publication consent withdrawal | Companion portal | IN_PROGRESS | Companion can withdraw publication consent; profile is unpublished and prior media grants/versions cannot silently reactivate after renewal. Current-head integration and browser acceptance required |
| Private evidence vault | SAFEGUARDING-OPERATIONS | IN_PROGRESS | Case narrative, subject reference, event notes and hold reasons are encrypted with per-case data keys; provider abstraction supports local envelope encryption or external Vault Transit. Local-key mode explicitly blocks cryptographic erasure; independent external-key custody, backup isolation and operational restore acceptance remain release gates |
| Safeguarding case submission | SafeguardingCaseController | IN_PROGRESS | MFA-verified admin/Owner/Super Admin only; encrypted per-case narrative, opaque reference, assigned active MFA specialist, transactional audit and encrypted notification outbox; integration tests cover minimal notices and retry. Browser E2E, emergency escalation configuration and actual cPanel mail delivery remain open |
| Assigned specialist case access | SECURITY-PRIVACY | IN_PROGRESS | Dedicated safeguarding role only, live account/MFA recheck, assigned-case-only query, access events and no-store detail view; integration tests deny unassigned specialist and revoked MFA. Browser E2E and role-route negative matrix remain open |
| Case state machine/events | SECURITY-PRIVACY | IN_PROGRESS | Server-side transition graph, encrypted reason notes, transactional case/access/audit events; integration coverage exists. Database-level immutability enforcement and complete transition/browser acceptance remain open |
| Legal-hold governance | SafeguardingGovernanceController | IN_PROGRESS | Owner/Super Admin MFA-only metadata register; encrypted reasons and independent actor required for release; integration tests cover self-release denial. Browser E2E and complete governance audit coverage remain open |
| Retention/deletion/anonymization | SECURITY-PRIVACY | IN_PROGRESS | Versioned policy, encrypted-backup expiry and independent restore-verification gates; scheduled per-case key destruction excludes legal holds and fails closed with local key custody. Vault/cPanel operational restore drill, backup expiry proof and deletion acceptance remain open |
| Incident/appeal/correction | PRODUCT-REQUIREMENTS | NOT_STARTED | Human-reviewed, restricted and auditable |
| Privacy rights for applicants/clients | PRODUCT-REQUIREMENTS | IN_PROGRESS | Model-applicant access/correction/withdrawal/erasure browser flow is OTP-verified and audited; client/companion self-service data export is role-scoped, allowlisted, per-account rate-limited, no-store and audited. Verified correction, erasure/deactivation, legal-hold/retention reconciliation and backup-expiry proof remain open |
| Data export | Administration | IN_PROGRESS | Client/companion portal offers a role-scoped allowlisted JSON export of account, own bookings, client memberships/entitlements/favorites or companion profile/availability/consents; per-account rate-limited no-store attachment, excludes credentials, safeguarding narratives and third-party contact details, and emits an audit event. Current-head MySQL/browser acceptance and a full export inventory remain required |
| Sensitive logging redaction | SECURITY-PRIVACY | IN_PROGRESS | No secrets/identity evidence in logs |
| No minors/exploitation/non-consensual content | COMPLIANCE-RELEASE-GATES | NOT_STARTED | Hard application invariants and release no-go gates |

## 7. CRM, communications, payments and finance

| Capability | Laravel reference | Native status | Acceptance evidence |
|---|---|---:|---|
| Enquiry inbox/assignment/pipeline | EnquiryAdminController | IN_PROGRESS | MFA-gated inbox, 100 most recent records, status updates with reason and atomic audit; assignment, pagination, notes, reminders, response delivery and browser acceptance remain open |
| Attribution/notes/reminders | PRODUCT-REQUIREMENTS | NOT_STARTED | Permissioned, auditable CRM data |
| SMTP/provider adapters | ARCHITECTURE | NOT_STARTED | Authenticated TLS, retries and delivery logs |
| Message templates/channels | PRODUCT-REQUIREMENTS | NOT_STARTED | Versioned templates and safe rendering |
| Payment control centre | PaymentSettingsController | IN_PROGRESS | Global/rail/purpose switches and eligibility |
| Manual payment methods | PRODUCT-REQUIREMENTS | NOT_STARTED | Immutable instructions and human reconciliation |
| BTC/USDT/USDC network config | PAYMENTS | NOT_STARTED | Asset/network/token contract/destination validation |
| Invoice/quote snapshots | TGLS, PAYMENTS | NOT_STARTED | Immutable terms, expiry, currency and payment instructions |
| Blockchain observation/confirmation | PAYMENTS | NOT_STARTED | Server-side adapter, idempotency, replay and confirmations |
| Ledger/reconciliation | PAYMENTS | NOT_STARTED | Immutable postings, under/overpayment, refunds and disputes |
| Card processor | COMPLIANCE-RELEASE-GATES | BLOCKED | Written underwriting and jurisdiction approval |
| Custody/escrow/private keys | PRODUCT-REQUIREMENTS | NOT_APPLICABLE | Explicitly excluded; never implement custody |
| Model settlement finance workspace | Settlement controller | NOT_STARTED | Finance-only, maker-checker and audit |
| Actual settlement transfers | PRODUCT-REQUIREMENTS | BLOCKED | External provider approval/integration; no custody |
| Tax adapters | TGLS | BLOCKED | Qualified rules, merchant obligations and legal review |

## 8. Globalization, configuration, audit and integrations

| Capability | Laravel reference | Native status | Acceptance evidence |
|---|---|---:|---|
| ISO 3166-1 reference dataset | TGLS | IN_PROGRESS | Pinned 249-entry reference snapshot with alpha-2, alpha-3 and numeric-3; provenance and licenses recorded; idempotent upsert preserves operator enablement; removed codes are disabled, never deleted. Integration acceptance pending |
| ISO 4217 reference dataset | TGLS | IN_PROGRESS | Pinned 155-current non-fund-code snapshot sourced from SIX ISO 4217 List One; numeric code, name, symbol and minor-unit precision; fund/test/unknown/withdrawn codes excluded; source provenance and license notices recorded; idempotent upsert preserves operator enablement. Integration acceptance pending |
| Independent country/currency/locale/time-zone models | TGLS | NOT_STARTED | No conflated market setting |
| Deployment base currency/locale/time zone | GlobalizationController | NOT_STARTED | Super Admin control and audit |
| Market review/approval | COMPLIANCE-RELEASE-GATES | IN_PROGRESS | Super Admin/MFA-only review UI; required evidence reference, substantive summary, approve/reject decision, explicit enablement and expiry within two years; transactional audit events; destination publication rechecks current approval and MFA reviewer. Verified ISO reference-data import and browser E2E remain open |
| Localized content and formatting | TGLS | NOT_STARTED | BCP 47, fallback and CLDR/ICU or deterministic formatting |
| Market-specific price overrides | TGLS | NOT_STARTED | Explicit overrides and versioned policy |
| Exchange-rate provider/snapshots | TGLS | NOT_STARTED | Freshness, source/time/expiry and decimal-safe conversion |
| Tax configuration | TGLS | BLOCKED | Jurisdiction-specific legal/accounting approval |
| Feature registry/control plane | Super Admin architecture | IN_PROGRESS | Dependencies, blockers, status and server-side enforcement |
| Secret catalog/rotation | SecretManagementController | NOT_STARTED | Allowlist, masked values, secret-store adapter |
| Append-only audit log and investigation workspace | ARCHITECTURE | IN_PROGRESS | AuditLogger redacts credentials and personal contact/identity fields at ingestion; Super Admin/MFA-gated read-only metadata viewer with exact filters and bounded pagination; migration 0018 adds database triggers rejecting UPDATE and DELETE; MySQL tests verify immutability and redaction. Tamper-evidence/cryptographic integrity verification, retention governance, complete event coverage and final acceptance remain open |
| Reports/analytics | PRODUCT-REQUIREMENTS | NOT_STARTED | Role-scoped and privacy-aware |
| Integrations | ARCHITECTURE | NOT_STARTED | Explicit adapters, retries, idempotency and safe credentials |
| Localization and export controls | PRODUCT-REQUIREMENTS | NOT_STARTED | Locale-aware, permissioned and audited |

## 9. Non-functional and security verification

The merged PR #90 code head passed PHP 8.3/8.4/8.5 native lint/unit tests, MySQL 8.0 integration (24 ordered migrations; current-head assertion count pending CI), Semgrep, native ZIP build/contract/archive checks, the existing Laravel feature suite, and native installer browser E2E (workflow run 80, ID 45671355809). Native upgrade/restore drills, release provenance, and actual HostSailor cPanel/LiteSpeed acceptance remain outstanding.

- [ ] Applicable OWASP ASVS 5.0 requirements mapped to controls, automated tests and manual review.
- [ ] OWASP Top 10:2025 threat model covering public site, identity, admin, bookings, media, safeguarding, payments, integrations, exports, installer, backups and upgrades.
- [ ] Negative authorization tests for every role/resource pair.
- [ ] CSRF, XSS, SQL injection, SSRF, IDOR, session fixation, replay, rate-limit bypass and path traversal tests.
- [ ] Secure upload tests: MIME/content mismatch, polyglots, oversized files, decompression bombs, malicious SVG, executable payload and metadata removal.
- [ ] Accessibility target WCAG 2.2 AA for public, installer and portal/admin flows.
- [ ] Performance tests for indexed search, pagination and constrained shared hosting.
- [ ] PHP 8.3/8.4/8.5 runtime matrix and supported MySQL/MariaDB matrix.
- [ ] Reproducible no-SSH/no-Composer/no-npm ZIP; no secret/test/customer data leakage.
- [ ] Actual HostSailor cPanel/LiteSpeed staging install and 403 diagnosis; no production data touched.
- [ ] Clean install, upgrade, backup/restore, rollback and installer-lock acceptance.
- [ ] Independent legal, hosting and payment-provider approval before relevant market/rail activation.

## 10. Porting rule

For each Laravel capability, record its source controller/service/model/migration/routes, native domain/application/infrastructure/UI components, test IDs, authorization roles, audit events, operational dependencies and acceptance status. Preserve the Laravel behavior unless an explicitly reviewed product/security decision changes it. Do not silently remove planned requirements or mislabel external human attestations as automated provider verification.
