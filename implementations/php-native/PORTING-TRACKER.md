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

The native branch currently has 13 ordered PHP migrations plus backend slices for identity, companion onboarding, bookings, model-agency applications/rights/agreements, branding and CMS. The current MySQL suite passes 13 migrations and 58 workflow assertions. A first public CMS layer now has a fixed-root PHP view renderer, escaped templates, published-page lookup and public-home rendering; these new routes are not yet CI-verified. Admin/customer/companion application flows and the remaining product domains remain open.

| Capability | Laravel reference | Native status | Acceptance evidence |
|---|---|---:|---|
| Single ZIP cPanel installation | CPANEL-INSTALLATION, SECURE-BROWSER-INSTALLER | NOT_STARTED | Fresh account install on cPanel/LiteSpeed, no SSH/Composer/npm/manual edits |
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
| Client registration/login/logout | routes/web.php, portal auth | IN_PROGRESS | Validation, throttling, session rotation and role separation |
| Client email verification | portal auth | NOT_STARTED | Expiring, single-use, rate-limited verification |
| Companion provisioning/login | SECURITY-PRIVACY | NOT_STARTED | Trusted provisioning; no public companion self-registration unless approved |
| Admin login/logout | admin routes | NOT_STARTED | Throttling, secure sessions and audit events |
| Roles: Super Admin, Owner, Admin | SECURITY-PRIVACY | IN_PROGRESS | Server-side least privilege and negative authorization tests |
| Finance specialist isolation | SECURITY-PRIVACY | IN_PROGRESS | Finance sees settlement workspace only |
| Verifier specialist isolation | SECURITY-PRIVACY | IN_PROGRESS | Minimal adult-eligibility queue only |
| Safeguarding specialist isolation | SECURITY-PRIVACY | IN_PROGRESS | Assigned-case access only |
| MFA/TOTP and replay prevention | SECURITY-PRIVACY | IN_PROGRESS | RFC-compatible TOTP, replay state, recovery codes single-use |
| Session versioning/revocation | SUPER-ADMIN-RECOVERY | IN_PROGRESS | Password/recovery/role changes invalidate prior sessions and pending MFA |
| First-login password replacement | Installer E2E | NOT_STARTED | One-time password expires and must be replaced |
| Initial password recovery | Admin recovery routes | NOT_STARTED | Short-lived verified recovery and session invalidation |
| Super Admin offline recovery | SUPER-ADMIN-RECOVERY | NOT_STARTED | Password + offline key, key rotation, notices, append-only audit |
| Two-owner break-glass | SUPER-ADMIN-RECOVERY | NOT_STARTED | Independent owners, dual verification, distinct credentials and approvals |
| Resource-scoped authorization | SECURITY-PRIVACY | NOT_STARTED | IDOR/negative tests for every resource boundary |
| Step-up privileged actions | PRODUCT-REQUIREMENTS | NOT_STARTED | MFA reauthentication, reason, impact preview, audit |

## 3. Public website, CMS and discovery

| Capability | Laravel reference | Native status | Acceptance evidence |
|---|---|---:|---|
| Responsive public homepage/brand shell | README, public views | NOT_STARTED | Mobile/tablet/desktop accessibility and visual parity |
| Dynamic branding | BrandingController | IN_PROGRESS | Name, logo, favicon, tokens, typography, contacts, social links |
| CMS pages and navigation | SitePageController | IN_PROGRESS | Draft/preview/publish/rollback/history |
| Legal pages and policy versions | PRODUCT-REQUIREMENTS | NOT_STARTED | Versioned, publishable and auditable content |
| SEO metadata/defaults | PRODUCT-REQUIREMENTS | NOT_STARTED | Titles, descriptions, canonical, robots, sitemap and structured data |
| Destination/city directory | DiscoveryController | NOT_STARTED | Structured taxonomy and active-market filtering |
| Destination administration | DestinationController | NOT_STARTED | Safe image upload, rights declaration, locale/currency/time zone |
| Companion/profile discovery | DiscoveryController | NOT_STARTED | Approved filters only; no sensitive attribute filters |
| Profile publication workflow | PROFILE-MEDIA-GOVERNANCE | NOT_STARTED | Adult eligibility, market approval and versioned consent gates |
| Public profile/media delivery | ProfileMediaController | NOT_STARTED | Private-by-default; no direct private URLs |
| FAQs, city guides, testimonials | PRODUCT-REQUIREMENTS | NOT_STARTED | Admin-managed content and publication workflow |
| Contact/enquiry form | EnquiryController | NOT_STARTED | Honeypot, rate limit, validation and safe handling |

## 4. Booking, scheduling and portals

| Capability | Laravel reference | Native status | Acceptance evidence |
|---|---|---:|---|
| Manual booking request | BookingController | IN_PROGRESS | Request lifecycle and audit history |
| Automatic booking mode | Booking domain | IN_PROGRESS | Disabled by default; all eligibility gates rechecked transactionally |
| Admin approval rules | Booking settings | IN_PROGRESS | Typed rules and fail-closed defaults |
| Companion explicit auto-book opt-in/out | Companion portal | NOT_STARTED | Immediate opt-out enforcement |
| Weekly availability windows | Companion portal | IN_PROGRESS | IANA/local schedule converted to UTC safely |
| Overlap prevention | Booking domain | IN_PROGRESS | Concurrent request integration tests |
| Deposit verification gate | Booking admin | IN_PROGRESS | Human attestation only; never misrepresented as chain verification |
| Booking state machine | ARCHITECTURE | IN_PROGRESS | Draft/requested/review/accepted/confirmed/completed and terminal states |
| Quote snapshot/acceptance | TGLS, BOOKING | NOT_STARTED | Currency, market, policy and rate immutable on acceptance |
| Cancellation/rescheduling | ROADMAP | IN_PROGRESS | Planned Laravel scope; explicit lifecycle and notifications |
| Calendar synchronization | ROADMAP | NOT_STARTED | Provider adapter, retries, idempotency and privacy |
| Client portal | PRODUCT-REQUIREMENTS | NOT_STARTED | Account, history, favourites, receipts, payment status, support, privacy |
| Client favourites | ClientFavoriteController | NOT_STARTED | Owner-scoped create/remove/list |
| Membership records | ClientManagementController | NOT_STARTED | Agency assignment and client visibility |
| Entitlement authorization | CLIENT-MEMBERSHIP | IN_PROGRESS | Active/in-date membership + immutable grant checked on projection and delivery |
| Members-only private gallery | CLIENT-MEMBERSHIP | NOT_STARTED | Consent, grant and membership all enforced server-side |
| Companion portal | CompanionPortalController | NOT_STARTED | Own profile, booking decisions, consent and account controls |
| Companion media/availability self-service | ROADMAP | NOT_STARTED | Planned workflows with review and safety preferences |

## 5. Model agency and representation vertical

| Capability | Laravel reference | Native status | Acceptance evidence |
|---|---|---:|---|
| Independent model application intake | PRODUCT-REQUIREMENTS | IN_PROGRESS | No companion/client/booking creation; minimum fields only |
| Applicant policy/version gate | PRODUCT-REQUIREMENTS | IN_PROGRESS | Intake disabled until policies published/approved |
| Applicant email-verified rights session | ARCHITECTURE | IN_PROGRESS | OTP access/correction/withdrawal/erasure |
| Application review/state machine | ModelAgencyApplicationAdminController | IN_PROGRESS | Append-only events and admin-only access |
| Manual adult eligibility attestation | verifier role | IN_PROGRESS | Dedicated minimal verifier queue; no DOB/ID document storage |
| Application legal hold/retention | PRODUCT-REQUIREMENTS | IN_PROGRESS | Hold blocks erasure; scheduled anonymization and audit |
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
| Privacy rights for applicants/clients | PRODUCT-REQUIREMENTS | NOT_STARTED | Access/correction/withdrawal/erasure workflow |
| Data export | Administration | NOT_STARTED | Permissioned, reasoned, audited and safe |
| Sensitive logging redaction | SECURITY-PRIVACY | IN_PROGRESS | No secrets/identity evidence in logs |
| No minors/exploitation/non-consensual content | COMPLIANCE-RELEASE-GATES | NOT_STARTED | Hard application invariants and release no-go gates |

## 7. CRM, communications, payments and finance

| Capability | Laravel reference | Native status | Acceptance evidence |
|---|---|---:|---|
| Enquiry inbox/assignment/pipeline | EnquiryAdminController | NOT_STARTED | Scoped CRM states and event history |
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
| Append-only audit log | ARCHITECTURE | NOT_STARTED | Actor/target/before-after/reason/request ID/policy version |
| Reports/analytics | PRODUCT-REQUIREMENTS | NOT_STARTED | Role-scoped and privacy-aware |
| Integrations | ARCHITECTURE | NOT_STARTED | Explicit adapters, retries, idempotency and safe credentials |
| Localization and export controls | PRODUCT-REQUIREMENTS | NOT_STARTED | Locale-aware, permissioned and audited |

## 9. Non-functional and security verification

Current verified scope: PHP 8.3/8.4/8.5 lint and foundation tests pass; Semgrep passes with native PHP included; MySQL 8.0 applies 13 ordered migrations idempotently and passes 34 identity, 16 model-agency/privacy and 8 CMS/branding assertions. These are backend integration slices only. Browser route coverage, full feature parity and actual cPanel/LiteSpeed host acceptance remain unverified.

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
