# Product Requirements

## Product outcome
A premium, responsive white-label agency operations platform that can be independently deployed and branded for each licensed customer. Admin must manage approved branding and operational settings from desktop, tablet or mobile. The product is globally extensible by default; no initial customer-market list limits the platform's country, currency, language or locale model.

## Business verticals and product boundary

Talyvane supports two distinct agency verticals that may share platform infrastructure but must never be conflated in service, contract, consent, pricing, booking or reporting logic:

1. **Luxury escort and elite companionship** — adult companionship enquiries, introductions, availability, client relationship management and booking workflows. This vertical may be activated only in a deployment and jurisdiction that has passed documented legal, safeguarding, payment-provider and market approval. No market is presumed approved by default.
2. **Model agency and representation** — talent applications, portfolio curation, private agency casting, assignments, representation agreements, image usage licences, booking coordination and talent settlements. Implemented foundations include independent application intake/review and privacy rights, externally executed representation-agreement recording, private portfolio-media consent, a purpose-bound image-licence ledger, an internal casting register, a separate administrator-only assignment lifecycle, and a settlement ledger with expense review, decimal-safe commission/net calculations, approval and external payment evidence/reversal/dispute records. Client-facing casting/assignment distribution, model self-service, assignment booking/rescheduling, consent-aware image delivery, actual payment integrations, tax/withholding, invoices and statutory reporting remain planned. Model work is not an escort service and must retain its own service catalogue, contracts, consent scopes, usage-rights controls, fees and settlement records.

The verticals may reuse identity, talent records, media infrastructure, availability primitives and CRM components only where access controls and data-purpose boundaries permit. They require separate booking types, service definitions, contract templates, consent scopes, price rules, compliance gates and analytics dimensions. A model assignment must not be treated as an escort booking, and consent for one vertical never implies consent for the other. Adult age assurance and safeguarding policies remain mandatory wherever applicable; no person under 18 may be represented in adult services or content.

## Model agency application boundary

The initial model-agency application workflow is implemented as an independent domain. It does not create a client or companion account, companion profile, companionship enquiry or booking. Intake stores only the applicant's name, contact details, country/city, optional HTTPS portfolio URL, experience summary, adult-eligibility self-attestation timestamp and the exact privacy-notice version accepted. It does not collect date of birth, identity documents or applicant photographs. Portfolio URLs are displayed as links only; the platform does not fetch or preview them.

Applicants must self-attest that they are at least 18. This is not identity or age assurance. The application feature remains disabled until the deployment explicitly configures and approves versioned applicant privacy and adult-eligibility policies and publishes the CMS policy pages `model-applicant-privacy` and `model-adult-eligibility`. Admin acceptance is only progression to a separate representation discussion; it is not an executed agreement, a casting assignment or permission to use images. Applications and status transitions have separate audit records and administrator-only access.

Private portfolio images can be received and reviewed by administrators only after adult eligibility, approved-market and versioned-consent gates pass. Files are sanitized and stored privately; rejection or consent withdrawal removes the private image. Approval does not authorize public or client delivery. The purpose-bound image-licence ledger records external execution and model consent per use. The internal casting register allows agency administrators to create private briefs and submit eligible models under an active casting-distribution licence; shortlisting and selection remain human decisions. A separate admin-only assignment register starts from a selected submission, records external offer and model-acceptance evidence, confirms and tracks assignment progress/completion, and snapshots compensation, expense cap and representation commission. The settlement ledger accepts private expense claims, enforces the assignment cap, requires human review, calculates commission and model net using integer minor units and currency precision, snapshots the calculation, and records approval plus external payment/reversal/dispute evidence. It does not expose a client portal, deliver images, create calendar bookings, move funds or verify bank/blockchain transfers. Remaining increments include applicant/model self-service portfolio, casting and assignment controls; secure evidence-document management; approved e-signature or cryptographically verifiable execution; client-facing casting/assignment review and distribution; assignment booking/rescheduling and talent availability; consent-aware client/public image delivery; usage monitoring; actual payment integrations; tax/withholding; invoices; partial settlements; and statutory reporting. Applicant access, correction, withdrawal, erasure, legal holds and retention execution remain a separate privacy-governance domain.

## Roles
Public visitor; client; companion/provider; operator; verification/safeguarding reviewer; finance operator; administrator; platform maintainer. Use least privilege. Platform maintainers have no default access to customer business data.

## Modules
1. Public site: editorial homepage, configurable pages/navigation, destination and city directory, profile discovery, controlled filters for location, language, experience category and approved non-sensitive attributes, consented galleries, FAQs, legal pages, SEO, city guides, testimonials and enquiries.
2. Booking: manual and automatic modes configurable by admin; approval rules; request lifecycle; destination-local scheduling stored in UTC; profile opt-in for automatic booking; companion-managed weekly availability; deposit verification gate; overlap prevention; client and companion decision workflows. Cancellation/rescheduling, calendar sync and notifications remain planned. Automatic mode fails closed if age assurance, current consent, explicit companion opt-in, availability or configured deposit prerequisites fail.
3. Client portal: account, booking history, favourites, membership entitlements, receipts, payment status, support, privacy and deletion requests. Membership may unlock private profiles/galleries and priority concierge benefits, with server-side entitlement checks on every request.
4. Companion portal: CLI-provisioned account onboarding, linked profiles, booking request decisions, explicit automatic-booking opt-in/out, publication-consent withdrawal and profile suspension. Application/casting pipeline, profile/media editing, availability and safety preferences remain planned.
5. Verification and safeguarding: independent age/identity assurance; evidence minimisation; restricted evidence vault; explicit media consent; consent versioning/withdrawal; access logs; retention/deletion; human-reviewed incidents; appeal/correction.
6. CRM and communications: enquiry inbox, assignment, pipeline, attribution, notes, reminders, provider adapters, retries and delivery logs. Do not claim end-to-end encryption if the server can read content.
7. Payments: adapter architecture; manual payments; optional BTC, USDT and USDC per supported network; card processors only after written underwriting; invoice, immutable instructions, ledger, reconciliation and refund/dispute states. No custody/escrow claim without approval.
8. Administration: dashboard, roles, brand/CMS, navigation, destinations, booking rules, payment configuration, feature flags, audit logs, reports, integrations, localization and export controls.
9. Globalization: ISO country/currency reference data; market policy; currency and localized price configuration; language/locale preferences; IANA time zones; tax adapters; exchange-rate snapshots; global reporting.

## Discovery and membership controls
Destination, city and approved locality taxonomies must be managed as structured data. Profile cards may show a coarse availability state, but never private addresses or exact real-time location. Do not implement race/ethnicity/nationality-based profile filters. Membership content must use server-side authorisation and private media delivery; hiding a link is not access control. The current implementation provides basic agency-managed membership assignment and client display only. The separate `memberships.entitlements` capability remains planned; do not treat the current membership record as authorization for protected profiles, galleries or other resources until server-side entitlement checks are implemented and tested.

## Dynamic configuration
Admin can change brand name, logo, favicon, colour tokens, typography, contact details, social links, SEO defaults, navigation, homepage sections, legal links and approved page content. Support draft, preview, publish, rollback and audit history. Payment controls include a global switch and independent switches for crypto, cards, manual methods, assets, networks and processors. Each customer deployment has independent secrets, domains, database, storage and credentials.

## Global market and localization requirements
Talyvane must not ship with a hardcoded finite country or currency allowlist. The system must support a versioned ISO 3166-1 country/territory dataset and ISO 4217 currency dataset, with operator-controlled enablement per deployment. New valid country/currency records must be importable without domain-code changes.

Country/territory, currency, locale/language, IANA time zone, market/legal policy, tax treatment and payment rail are independent dimensions. Customer and visitor preferences must not silently alter a transaction's agreed currency. USD is only the initial default reporting currency; each deployment may select another base currency. GBP, MXN and PHP may be enabled for the first customer, but are neither privileged nor exhaustive.

Support market-specific price overrides and optional exchange-rate-derived quotes. Every quote must snapshot the original amount/currency, target amount/currency where converted, market, rate, rate source/time/expiry, tax treatment, fees and policy version. Booking acceptance locks the quote; changes require explicit re-acceptance. Preserve original currency and amount for payments/refunds and never rewrite historical transactions when rates change.

Store money using integer minor units where appropriate and decimal-safe arithmetic for rates and calculations; never use binary floating point for money. Honor currency-specific precision, including zero- and three-decimal currencies. Store UTC instants and render using explicit IANA time zones and locale. Keep language, locale, currency and time-zone preferences separate.

Country enablement is not legal authorization. Country-level availability, services, pricing, tax and payment features require deployment-specific review and server-side policy enforcement. Fail closed for unknown, disabled, expired or unapproved market policy. Do not use IP geolocation as sole proof of residence or eligibility. Tax calculation and collection remain disabled until qualified rules/provider and merchant obligations are configured.

## Crypto requirements
Configure BTC, USDT and USDC by network, with distinct receiving addresses and optional multiple addresses per asset/network. Validate address, network and token contract before activation. Snapshot asset, network, destination, token contract, expected amount, quote and expiry into each invoice. Verify on-chain server-side; screenshots and browser redirects are not proof. Use idempotency and replay protection. Never collect seed phrases or private keys. Trust Wallet is a wallet app, not a gateway or chain-verification service. Keep crypto disabled until technical, legal and operational approval.

## Non-functional requirements
OWASP ASVS 5.0-aligned verification; least privilege; MFA for privileged roles; secure sessions; audit logs; secure uploads; secrets management; tested backups; data minimisation; retention/deletion; WCAG 2.2 AA target; UTC storage with local timezone rendering; integer minor-unit money values; indexed search and pagination.

## Exclusions
No custody/escrow; no private-key handling; no automated age inference; no public identity documents; no unrestricted blacklist; no hidden surveillance; no guarantee against screenshots; no card processing before written approval; no production operation before legal review.

## Acceptance
A feature is not complete until permission boundaries, validation, failure states, audit events, tests, accessibility, privacy impact and operational documentation are addressed. TGLS acceptance criteria are defined in docs/TGLS.md.


## Super Admin control plane

Each standalone customer deployment has one highest-privilege **Super Admin** role, provisioned through a secure installation/CLI bootstrap process. The Super Admin has comprehensive control over all configurable business and platform functionality within that deployment, including:

- Enable, disable, configure and phase-rollout of eligible modules and features.
- Website visibility, CMS, brand identity, navigation, SEO and public content.
- Client and companion registration, portal capabilities, onboarding and account controls.
- Booking modes, availability rules, workflows, cancellation policies and notifications.
- Payment rails, assets, networks, processors, receiving instructions and payment-purpose controls.
- Communication channels, inbox routing, templates, service hours and provider connections.
- Country/market, city, currency, locale, language and time-zone configuration.
- Memberships, pricing, promotions, CRM, reports, exports and operational workflows.
- Staff accounts, role assignments, permissions, security policies and integration settings.
- Maintenance mode, feature rollout, deployment diagnostics and approved operational controls.

The administration interface must expose a searchable module/feature catalogue with status, dependencies, eligibility, configuration, impact summary and audit history. A Super Admin can disable an eligible module immediately; enabling a module requires its dependencies, required configuration, provider approval and applicable market/legal gates to be satisfied. Feature switches must be enforced server-side in routes, policies, jobs, APIs and domain services; hiding navigation or UI elements is not enforcement.

### Authority and non-overridable safeguards

Super Admin authority is comprehensive over business configuration, but it is not permission to bypass mandatory security or legal controls. The following constraints are enforced by application code and policy services, not by editable feature flags:

- Adult-only access, independent age assurance and informed, revocable consent requirements.
- Jurisdictional approval and market eligibility; unknown, expired, suspended or unapproved markets fail closed.
- Anti-trafficking, anti-coercion, anti-exploitation and non-consensual-content prohibitions.
- Authentication, authorization, MFA, session protection, audit integrity and privacy boundaries.
- Companion autonomy and explicit opt-in requirements for automatic booking and publication.
- Payment verification, transaction integrity, no-custody boundaries and provider restrictions.
- Data minimization, evidence access restrictions, retention/deletion duties and incident escalation.

The Super Admin may configure stricter controls, suspend a market, disable a feature, or suspend an account. They cannot mark an unverified person as verified, fabricate consent, bypass a market approval, suppress a safeguarding incident, alter historical payment evidence, erase audit history or grant themselves access to secrets/private keys through the UI.

### Privileged action controls

- Require MFA and step-up reauthentication for high-impact changes, including enabling markets, enabling payment rails, changing verification rules, changing privileged roles, exporting sensitive data and modifying retention.
- Require an explicit reason for high-impact changes; display the expected impact and affected modules before confirmation.
- Record actor, timestamp, action, target, before/after state, reason, request correlation ID and policy/configuration version in append-only audit events.
- Never store plaintext integration secrets in ordinary settings or return existing secrets to the browser. Support set/rotate/clear workflows with masked status.
- Prevent the last active Super Admin from being disabled or demoted without a safe successor and recovery path.
- Support emergency disable of risky features; re-enablement must satisfy the normal prerequisites and step-up controls.
- No support engineer, vendor, platform maintainer or tenant administrator receives implicit access to another deployment's customer data.

### Multi-agency product boundary

The initial commercial release remains one isolated installation per licensed agency. Each installation has its own Super Admin and independent data, secrets, storage, domain and configuration. A future centrally hosted SaaS may add a separate platform-operator control plane for deployment lifecycle and licensing; it must not silently inherit tenant Super Admin access or expose tenant business data. Tenant Super Admin permissions and platform-operator permissions are separate security principals and authorization domains.
