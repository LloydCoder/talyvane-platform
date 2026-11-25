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

The native branch currently has 18 ordered PHP migrations plus backend and browser-facing slices for identity, companion onboarding/publication, public discovery, bookings, model-agency applications/rights/agreements, branding, CMS and initial CRM. The historical foundation at `fa133a89` passed PHP 8.3/8.4/8.5, Semgrep and MySQL 8.0 with 15 migrations and 35 identity, 16 model-agency/privacy, 12 content-management and 10 enquiry/CRM assertions. The expanded suite adds portal recovery, consent-gated discovery/publication, model-agency intake/review, applicant privacy rights and two further migrations; the first expanded MySQL run exposed a missing country field in the single-profile discovery query, now corrected, and a current-head rerun is required before claiming the expanded suite passes. The separate Laravel CI, Laravel single-ZIP package workflow and Laravel installer browser E2E also passed, but those workflows exercise the existing Laravel application and do **not** package or browser-test this native-PHP port. The native-PHP customer ZIP, native browser installation, and actual HostSailor cPanel/LiteSpeed acceptance remain outstanding. The public CMS layer includes a fixed-root PHP view renderer, escaped templates, published-page lookup and immutable publication snapshots. The administrator browser sign-in lifecycle includes CSRF-protected login, first-login password replacement, mandatory MFA setup/challenge, single-use recovery-code presentation, session rotation, rate limits and permission-checked access. The CMS has versioned authoring, reasoned publication, Super Admin branding and SEO defaults. The public enquiry and MFA-gated admin inbox now implement CSRF, honeypot, field validation, IP rate limiting, explicit versioned privacy acknowledgement, privacy-safe attribution, audited creation and status transitions. Public identity journeys include separate client/companion registration, email verification/resend, portal login/logout and six-digit email-based password recovery with session-version revocation; mail-dependent features fail closed without valid private SMTP settings. Public discovery and MFA-authorized companion publication enforce current market approval, independent adult verification, active purpose-specific consent and allowlisted profile fields. Model-agency intake is separate from companionship, requires the current published Privacy Notice version, explicit privacy acknowledgement and adult attestation, and exposes distinct administrator and minimal-data verifier queues. Client booking requests, companion accept/decline decisions and MFA-admin booking operations are now wired through separate read models and destination-local time handling; availability self-service, reschedule UI, quotes/deposits and notification delivery remain open. Applicant rights now have an email-OTP browser journey for access, correction, withdrawal and erasure, with audit events and legal-hold deferral. These remain partial vertical slices: full profile/account management, private media, model portfolio/casting/assignment/settlement, safeguarding, client rights, and complete booking/payment operations remain open.

| Capability | Laravel reference | Native status | Acceptance evidence |
|---|---|---:|---|
| Single ZIP cPanel installation | CPANEL-INSTALLATION, SECURE-BROWSER-INSTALLER | IN_PROGRESS | Existing Laravel distribution ZIP and Laravel installer E2E pass; native-PHP source is not yet included in a customer ZIP, and native installer/browser acceptance plus actual HostSailor cPanel/LiteSpeed acceptance remain outstanding |
| Private app placement | HOSTING-COMPATIBILITY, ADR-0014 | IN_PROGRESS | Source/config/vendor/logs/uploads/backups inaccessible over HTTP |
| PHP/runtime preflight | HOSTING-COMPATIBILITY | IN_PROGRESS | Web-SAPI extension/version check, DB and filesystem capability check |
| One-time installer access key | SECURE-BROWSER-INSTALLER | IN_PROGRESS | High entropy, hashed/one-time, HTTPS, throttled, CSRF protected |
| Dual-email verification | Installer and recovery requirements | NOT_STARTED | Separate mailbox codes, expiry, attempt limits and verified state |
| Super Admin bootstrap | SUPER-ADMIN-RECOVERY | NOT_STARTED | Singleton, verified addresses, one-time credentials, offline recovery key |
| Installer permanent lock | SECURE-BROWSER-INSTALLER | IN_PROGRESS | Durable lock; malformed/missing state fails closed; cleanup not required for lock |
| Private diagnostic logging | cPanel installer diagnostics | IN_PROGRESS | Correlation ID, redaction, restrictive permissions, no public log endpoint |
| Upgrade/migration process | DEPLOYMENT-OPERATIONS | IN_PROGRESS | Forward-only migration, backup, compatibility and rollback evidence |
| Backup/restore | DEPLOYMENT-OPERATIONS | NOT_STARTED | DB + private files restore drill on isolated environment |
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
| Destination administration | DestinationController | NOT_STARTED | Safe image upload, rights declaration, locale/currency/time zone |
| Companion/profile discovery | DiscoveryController | IN_PROGRESS | Public destination/profile routes expose allowlisted fields only and require verified adult eligibility, active versioned publication consent and current market approval; private media delivery and search filters remain open |
| Profile publication workflow | PROFILE-MEDIA-GOVERNANCE | IN_PROGRESS | MFA-authorized review queue and reasoned/audited publication require independent verification, current market approval and unwithdrawn consent; media approval/licensing and browser acceptance remain open |
| Public profile/media delivery | ProfileMediaController | NOT_STARTED | Private-by-default; no direct private URLs |
| FAQs, city guides, testimonials | PRODUCT-REQUIREMENTS | NOT_STARTED | Admin-managed content and publication workflow |
| Contact/enquiry form | EnquiryController | IN_PROGRESS | CSRF, quiet honeypot, IP rate limit, validation, explicit versioned privacy acknowledgement, safe attribution and atomic creation audit; browser acceptance and notification delivery remain open |

## 4. Booking, scheduling and portals

| Capability | Laravel reference | Native status | Acceptance evidence |
|---|---|---:|---|
| Manual booking request | BookingController | IN_PROGRESS | Client portal request form uses destination-local time and revalidates market, adult eligibility, consent, client verification, policy and overlap transactionally; booking browser E2E remains open |
| Automatic booking mode | Booking domain | IN_PROGRESS | Disabled by default; transactional eligibility and local-availability gates exist; companion availability self-service and admin policy UI remain open |
| Admin approval rules | Booking settings | IN_PROGRESS | Typed policy and fail-safe manual defaults exist; customer-facing policy configuration UI and approval notifications remain open |
| Companion explicit auto-book opt-in/out | Companion portal | NOT_STARTED | Immediate opt-out enforcement |
| Weekly availability windows | Companion portal | IN_PROGRESS | IANA/local schedule conversion and automatic-rule validation exist; companion schedule editing UI remains open |
| Overlap prevention | Booking domain | IN_PROGRESS | Transactional overlap checks are covered by integration tests; dedicated concurrent-load test remains open |
| Deposit verification gate | Booking admin | IN_PROGRESS | Deposit-required policy blocks booking without an accepted quote; finance reconciliation and human verification UI remain open |
| Booking state machine | ARCHITECTURE | IN_PROGRESS | Client cancellation, companion accept/decline and MFA-admin transitions are wired to the state machine; full browser E2E remains open |
| Quote snapshot/acceptance | TGLS, BOOKING | NOT_STARTED | Currency, market, policy and rate immutable on acceptance |
| Cancellation/rescheduling | ROADMAP | IN_PROGRESS | Client cancellation UI and reschedule service exist; reschedule UI, notifications and policy-specific cancellation fees remain open |
| Calendar synchronization | ROADMAP | NOT_STARTED | Provider adapter, retries, idempotency and privacy |
| Client portal | PRODUCT-REQUIREMENTS | NOT_STARTED | Account, history, favourites, receipts, payment status, support, privacy |
| Client favourites | ClientFavoriteController | IN_PROGRESS | Native client-only private shortlist supports save/remove/list, revalidates current public discoverability on add and filters hidden/unconsented profiles from the read model; transactional audit events and integration coverage added. Browser E2E and full portal account management remain open |
| Membership records | ClientManagementController | NOT_STARTED | Agency assignment and client visibility |
| Entitlement authorization | CLIENT-MEMBERSHIP | IN_PROGRESS | Active/in-date membership + immutable grant checked on projection and delivery |
| Members-only private gallery | CLIENT-MEMBERSHIP | NOT_STARTED | Consent, grant and membership all enforced server-side |
| Companion portal | CompanionPortalController | IN_PROGRESS | Separate companion sign-in and booking decision workspace; profile, consent, safeguarding, availability and account self-service remain open |
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
| Publication consent withdrawal | Companion portal | NOT_STARTED | Immediate unpublish and future-use block |
| Private evidence vault | SAFEGUARDING-OPERATIONS | NOT_STARTED | Restricted paths, least privilege, encryption/key isolation |
| Safeguarding case submission | SafeguardingCaseController | NOT_STARTED | Reporter cannot read after submission |
| Assigned specialist case access | SECURITY-PRIVACY | NOT_STARTED | Exact assigned-user scope and MFA |
| Case state machine/events | SECURITY-PRIVACY | NOT_STARTED | Append-only transitions/access events |
| Legal-hold governance | SafeguardingGovernanceController | NOT_STARTED | Metadata-only governance; independent release actor |
| Retention/deletion/anonymization | SECURITY-PRIVACY | NOT_STARTED | Policy-driven, legal-hold-aware and verifiable |
| Incident/appeal/correction | PRODUCT-REQUIREMENTS | NOT_STARTED | Human-reviewed, restricted and auditable |
| Privacy rights for applicants/clients | PRODUCT-REQUIREMENTS | IN_PROGRESS | Model-applicant access/correction/withdrawal/erasure browser flow is OTP-verified and audited; client/companion rights workflows remain open |
| Data export | Administration | NOT_STARTED | Permissioned, reasoned, audited and safe |
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
| ISO 3166-1 reference dataset | TGLS | IN_PROGRESS | Versioned import; no finite hardcoded allowlist |
| ISO 4217 reference dataset | TGLS | IN_PROGRESS | Code/name/symbol/numeric/precision validation |
| Independent country/currency/locale/time-zone models | TGLS | NOT_STARTED | No conflated market setting |
| Deployment base currency/locale/time zone | GlobalizationController | NOT_STARTED | Super Admin control and audit |
| Market review/approval | COMPLIANCE-RELEASE-GATES | IN_PROGRESS | Evidence-backed human review, expiry and fail-closed checks |
| Localized content and formatting | TGLS | NOT_STARTED | BCP 47, fallback and CLDR/ICU or deterministic formatting |
| Market-specific price overrides | TGLS | NOT_STARTED | Explicit overrides and versioned policy |
| Exchange-rate provider/snapshots | TGLS | NOT_STARTED | Freshness, source/time/expiry and decimal-safe conversion |
| Tax configuration | TGLS | BLOCKED | Jurisdiction-specific legal/accounting approval |
| Feature registry/control plane | Super Admin architecture | IN_PROGRESS | Dependencies, blockers, status and server-side enforcement |
| Secret catalog/rotation | SecretManagementController | NOT_STARTED | Allowlist, masked values, secret-store adapter |
| Append-only audit log and investigation workspace | ARCHITECTURE | IN_PROGRESS | AuditLogger records redacted structured events; PR #89 adds a Super Admin/MFA-gated read-only metadata viewer with exact filters and bounded pagination; migration 0018 adds database triggers rejecting UPDATE and DELETE, with MySQL regression assertions. Tamper-evidence/cryptographic integrity verification, retention governance, complete event coverage and final acceptance remain open |
| Reports/analytics | PRODUCT-REQUIREMENTS | NOT_STARTED | Role-scoped and privacy-aware |
| Integrations | ARCHITECTURE | NOT_STARTED | Explicit adapters, retries, idempotency and safe credentials |
| Localization and export controls | PRODUCT-REQUIREMENTS | NOT_STARTED | Locale-aware, permissioned and audited |

## 9. Non-functional and security verification

At `fa133a89`, native PHP 8.3/8.4/8.5 tests and Semgrep passed; MySQL 8.0 applied 15 migrations idempotently and passed 35 identity, 16 model-agency/privacy, 12 content-management and 10 enquiry/CRM assertions. The expanded branch now has 18 migrations and additional portal, discovery and model-agency assertions; its current-head MySQL run must pass before these new slices can be marked verified. Laravel CI (PHP 8.3/8.4/8.5), the existing Laravel distribution package, and its Laravel installer browser E2E also passed; they are separate from native-PHP acceptance. Native-PHP package build, native HTTP/browser end-to-end tests, native upgrade/restore drills, and actual HostSailor cPanel/LiteSpeed acceptance remain outstanding.

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
