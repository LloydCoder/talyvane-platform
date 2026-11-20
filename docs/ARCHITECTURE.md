# Architecture

## Deployment model
Start with one isolated installation per licensed customer, not shared multi-tenant SaaS. Each deployment owns its application runtime, domain/TLS, database, private media storage, secrets, integration credentials, logs, backups, retention policies and configuration. A future entitlement service may validate signed deployment licences but must not become a cross-customer data plane.

## Application layers
1. Presentation: Blade, Tailwind CSS and Alpine.js.
2. HTTP boundary: routes, middleware, validation, authentication and authorisation.
3. Application services: use cases for profiles, bookings, memberships, payments, verification, communications, CMS, pricing, market policy and localization.
4. Domain modules: explicit boundaries and domain events.
5. Infrastructure adapters: database, queue, mail, storage, payment, blockchain, calendar, exchange-rate, tax and messaging providers.
6. Operations: audit log, metrics, structured logs, backup, health checks and deployment tooling.

## Modules
Identity & Access; Deployment Configuration; Brand & Content; Globalization & Market Policy; Discovery & Profiles; Booking & Scheduling; Client Portal; Companion Portal; Model Agency Applications & Representation; Verification & Consent; Safeguarding; CRM & Communications; Payments & Ledger; Reporting & Audit; Integrations & Operations.

### Model agency application data boundary
`model_talent_applications` and `model_talent_application_events` are independent from `companion_profiles`, companionship `bookings` and `enquiries`. Public intake does not create an authenticated account, fetch submitted portfolio URLs or accept applicant image uploads. Administrative status transitions are transactionally recorded in an append-only application-event model. Representation agreements, private portfolio assets, purpose-bound image licences, internal casting calls and model assignments are separate model-agency subdomains with their own policy versions, market checks, authorization and audit records. Application acceptance is not a contract; portfolio approval is not a licence; a casting selection is not an assignment; assignment acceptance is not image-use permission or a payment settlement.

## Model agency application domain

Model applications are persisted independently from companion profiles, enquiries and companionship bookings. Public application submission is unauthenticated but rate-limited, policy-versioned and consent-gated. Only agency administrators can review records. A constrained state machine and append-only application event records capture each review decision. Applicant rights use email-verified, short-lived OTP sessions with access, correction, withdrawal and erasure actions; legal holds are owner/Super Admin-only and block erasure/retention. A scheduled retention service anonymizes records after configured periods. Rights and admin governance routes remain available when new intake is disabled. No external portfolio URL is fetched. Representation agreements record externally executed terms without providing or validating electronic signatures. Private portfolio assets are sanitized, consent-scoped and administrator-reviewed; purpose-specific image licences are recorded against assets, agreements, territories, channels and validity windows. The internal casting register creates private briefs, submits eligible models under active casting-distribution licences and records human-administered shortlist/selection/decline decisions. The assignment register creates work records only from selected submissions, rechecks current eligibility, consent, licence, agreement, market and schedule, records external offer/acceptance evidence, and tracks confirmation, start, completion, decline, cancellation and dispute. It snapshots compensation, expense-cap and agreement commission metadata but does not create calendar bookings. The settlement domain accepts private expense claims, enforces caps, calculates commission and model net in integer currency minor units, snapshots amounts, requires approval and records externally attested payment, reversal or dispute evidence. It does not initiate or verify transfers. Client review/distribution, model self-service, evidence-document upload/verification, image delivery, tax and actual payment integration remain separate future domains.

## Data principles
Use UUID/ULID public identifiers; integer minor units plus ISO 4217 currency for money; decimal-safe arithmetic for exchange rates; UTC timestamps; immutable quote, exchange-rate and payment-instruction snapshots; separate identity evidence from profile data; private-by-default media; explicit retention/deletion rules; no wallet seeds/private keys. Country/territory (ISO 3166), currency (ISO 4217), locale/language (BCP 47), time zone (IANA), market policy, tax policy and payment rail are independent dimensions. Never expose private addresses, sensitive availability or exact real-time location. See docs/TGLS.md.

## Booking state
Draft → Requested → Pending Review / Awaiting Automatic Rule → Accepted or Declined → Confirmed → Completed. Alternate terminal states: Cancelled, Expired, No-show and Disputed. Automatic mode uses explicit admin rules and fails closed when prerequisites fail. Every transition records actor, time, reason and policy version. Booking quotes snapshot currency, amount, market and applicable exchange-rate policy; accepted quote terms cannot be silently changed.

## Payment state
Created → Pending → Observed → Confirming → Confirmed → Settled. Exceptions: Expired, Underpaid, Overpaid, Failed, Refund Pending, Refunded and Disputed. Separate quote, invoice creation, transaction observation and reconciliation. Validate asset, chain, token contract, destination, amount and confirmation threshold. Processing must be idempotent. Price currency and payment asset/network are distinct.

## Security boundaries
Authentication is not authorisation. Membership entitlements are server-owned catalogue keys backed by immutable grants, an active/in-date membership and grant window, and server-side checks at both resource discovery and delivery. Public discovery must never serialize private media URLs. Model output, prompts, memories, tool outputs and client input never grant execution authority. Enforce server-side policies on every protected operation. Separate admin, finance, verifier and safeguarding permissions. MFA and step-up authentication for high-risk actions. Never log secrets or private evidence. Market eligibility is checked server-side and fails closed when policy is absent or unapproved.

## Hosting
Target standard cPanel shared hosting, subject to a per-plan compatibility check. The application must run without root access, Docker, Redis, WebSockets, Node.js/npm on production or a persistent worker. Baseline operation uses supported MySQL/MariaDB, private file cache/session where appropriate, synchronous low-volume jobs and bounded HTTP polling. Cron, SSH and Composer are optional deployment conveniences; features requiring cron or external services must disclose when unavailable. PHP 8.3+ and required Laravel extensions plus GD WebP are mandatory for the current release. Only Laravel `public/` may be web-accessible; source, `.env`, `vendor/`, logs, private storage and backups remain outside the document root. If a provider cannot safely serve the public front controller or provide required runtime capabilities, mark it incompatible rather than weakening security. See docs/HOSTING-COMPATIBILITY.md, docs/CPANEL-INSTALLATION.md and docs/DEPLOYMENT-OPERATIONS.md.

## Evolution
Introduce shared tenancy, microservices or a central control plane only when customer scale and threat modelling justify them.


## Super Admin control plane

The deployment's Super Admin is the highest customer-controlled principal. Implement a registry-driven control plane rather than scattered boolean settings:

1. **Feature registry:** stable feature keys, module ownership, descriptions, default state, dependencies, risk class, configuration schema and activation prerequisites. Unknown feature keys are rejected.
2. **Deployment configuration:** persisted enabled/disabled state and typed configuration, scoped to the isolated installation. Sensitive credentials live in environment/secret storage, not generic settings rows.
3. **Policy enforcement:** a central feature-availability service is consulted by HTTP middleware, authorization policies, application services, scheduled jobs and provider adapters. UI visibility is a convenience only.
4. **Eligibility gates:** activation checks dependencies, verified configuration, provider approval, market policy and safeguarding prerequisites. A disabled feature fails closed at every entry point.
5. **Privileged workflow:** MFA/step-up, impact preview, reason capture, transactional update and append-only audit event. High-risk changes must be atomic with their audit record.
6. **Operational visibility:** searchable feature catalogue, status, blockers, last changed by/when, version, and safe diagnostics. Do not reveal secret values.
7. **Emergency control:** immediate disable path for risky optional functionality. Re-enable follows full eligibility checks; emergency disable cannot disable the security/audit mechanisms that make the action accountable.

Keep hard safety and security invariants outside the editable feature registry. The Super Admin can choose stricter policy and disable capabilities, but cannot toggle off age assurance, consent, authorization, MFA for privileged access, audit integrity, privacy boundaries, market approval or payment verification requirements. A configuration change must never grant authority by itself.

### Authorization hierarchy

- **Super Admin:** full configuration and operational authority within one deployment, subject to hard invariants.
- **Administrator / operator:** explicit scoped permissions; no implicit Super Admin capabilities.
- **Specialist roles:** finance, verification, safeguarding and support permissions limited to their duties.
- **Client and companion:** access only to their own authorized records and self-service functions.
- **Platform operator (future SaaS only):** deployment lifecycle/licensing administration, separated from tenant data-plane permissions.

Bootstrap the first Super Admin through a one-time CLI/installation process. Do not expose public Super Admin registration. Protect role changes, recovery and Super Admin succession with step-up authentication and immutable audit events.


## Release artifact and installation
Commercial releases must be reproducible from a committed `composer.lock`, include production dependencies and compiled frontend assets, and exclude secrets and customer data. Build steps belong in CI or a trusted build environment, not on the production shared host. The no-SSH/no-Composer tier requires a prebuilt artifact and a safe, release-specific installation and Super Admin bootstrap path; never expose an unrestricted web-based Artisan runner.

## Hard safeguards and continuity-safe operations

The feature registry is not an authority over hard safeguards. Reserved safety, privacy, safeguarding, financial-integrity and audit keys are rejected by the runtime feature resolver and cannot be changed by Super Admin feature controls. Optional feature pauses stop new work while preserving protective and close-out operations. Approved safeguarding retention, configured safeguarding notifications and model image-licence expiry continue under their independent operational prerequisites. See [ADR-0011](decisions/ADR-0011-hard-safeguards-not-feature-flags.md).
