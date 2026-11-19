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
Identity & Access; Deployment Configuration; Brand & Content; Globalization & Market Policy; Discovery & Profiles; Booking & Scheduling; Client Portal; Companion Portal; Verification & Consent; Safeguarding; CRM & Communications; Payments & Ledger; Reporting & Audit; Integrations & Operations.

## Data principles
Use UUID/ULID public identifiers; integer minor units plus ISO 4217 currency for money; decimal-safe arithmetic for exchange rates; UTC timestamps; immutable quote, exchange-rate and payment-instruction snapshots; separate identity evidence from profile data; private-by-default media; explicit retention/deletion rules; no wallet seeds/private keys. Country/territory (ISO 3166), currency (ISO 4217), locale/language (BCP 47), time zone (IANA), market policy, tax policy and payment rail are independent dimensions. Never expose private addresses, sensitive availability or exact real-time location. See docs/TGLS.md.

## Booking state
Draft → Requested → Pending Review / Awaiting Automatic Rule → Accepted or Declined → Confirmed → Completed. Alternate terminal states: Cancelled, Expired, No-show and Disputed. Automatic mode uses explicit admin rules and fails closed when prerequisites fail. Every transition records actor, time, reason and policy version. Booking quotes snapshot currency, amount, market and applicable exchange-rate policy; accepted quote terms cannot be silently changed.

## Payment state
Created → Pending → Observed → Confirming → Confirmed → Settled. Exceptions: Expired, Underpaid, Overpaid, Failed, Refund Pending, Refunded and Disputed. Separate quote, invoice creation, transaction observation and reconciliation. Validate asset, chain, token contract, destination, amount and confirmation threshold. Processing must be idempotent. Price currency and payment asset/network are distinct.

## Security boundaries
Authentication is not authorisation. Model output, prompts, memories, tool outputs and client input never grant execution authority. Enforce server-side policies on every protected operation. Separate admin, finance, verifier and safeguarding permissions. MFA and step-up authentication for high-risk actions. Never log secrets or private evidence. Market eligibility is checked server-side and fails closed when policy is absent or unapproved.

## Hosting
Do not assume SSH, Composer, cron, queues, Redis, Node build tools or writable paths until tested. Laravel public directory must be the web root. Environment files, source, storage and backups must not be web-accessible.

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
