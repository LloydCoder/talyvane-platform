# Architecture

## Deployment model
Start with one isolated installation per licensed customer, not shared multi-tenant SaaS. Each deployment owns its application runtime, domain/TLS, database, private media storage, secrets, integration credentials, logs, backups, retention policies and configuration. A future entitlement service may validate signed deployment licences but must not become a cross-customer data plane.

## Application layers
1. Presentation: Blade, Tailwind CSS and Alpine.js.
2. HTTP boundary: routes, middleware, validation, authentication and authorisation.
3. Application services: use cases for profiles, bookings, memberships, payments, verification, communications and CMS.
4. Domain modules: explicit boundaries and domain events.
5. Infrastructure adapters: database, queue, mail, storage, payment, blockchain, calendar and messaging providers.
6. Operations: audit log, metrics, structured logs, backup, health checks and deployment tooling.

## Modules
Identity & Access; Deployment Configuration; Brand & Content; Discovery & Profiles; Booking & Scheduling; Client Portal; Companion Portal; Verification & Consent; Safeguarding; CRM & Communications; Payments & Ledger; Reporting & Audit; Integrations & Operations.

## Data principles
Use UUID/ULID public identifiers; integer minor units plus ISO currency for money; UTC timestamps; immutable payment instruction snapshots; separate identity evidence from profile data; private-by-default media; explicit retention/deletion rules; no wallet seeds/private keys. Never expose private addresses, sensitive availability or exact real-time location.

## Booking state
Draft → Requested → Pending Review / Awaiting Automatic Rule → Accepted or Declined → Confirmed → Completed. Alternate terminal states: Cancelled, Expired, No-show and Disputed. Automatic mode uses explicit admin rules and fails closed when prerequisites fail. Every transition records actor, time, reason and policy version.

## Payment state
Created → Pending → Observed → Confirming → Confirmed → Settled. Exceptions: Expired, Underpaid, Overpaid, Failed, Refund Pending, Refunded and Disputed. Separate invoice creation, quote, transaction observation and reconciliation. Validate asset, chain, token contract, destination, amount and confirmation threshold. Processing must be idempotent.

## Security boundaries
Authentication is not authorisation. Model output, prompts, memories, tool outputs and client input never grant execution authority. Enforce server-side policies on every protected operation. Separate admin, finance, verifier and safeguarding permissions. MFA and step-up authentication for high-risk actions. Never log secrets or private evidence.

## Hosting
Do not assume SSH, Composer, cron, queues, Redis, Node build tools or writable paths until tested. Laravel public directory must be the web root. Environment files, source, storage and backups must not be web-accessible.

## Evolution
Introduce shared tenancy, microservices or a central control plane only when customer scale and threat modelling justify them.
