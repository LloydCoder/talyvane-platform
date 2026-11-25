# Native PHP architecture

## Architectural decision

The native PHP implementation is a separate application boundary inside the existing repository. It must not share runtime files, dependencies, configuration, sessions, database migrations, or deployment artifacts with the Laravel application.

The repository's existing product, security, privacy, safeguarding, and acceptance documents remain the canonical requirements unless an explicitly reviewed decision record supersedes them.

## Layers

1. **Domain** — entities, value objects, invariants, domain policies, and domain events. No framework or infrastructure dependencies.
2. **Application** — use cases, orchestration, authorization decisions, transaction boundaries, and ports.
3. **Infrastructure** — PDO repositories, mail delivery, filesystem storage, payment-provider adapters, clocks, queues, and external integrations.
4. **HTTP interface** — request parsing, routing, middleware, response rendering, CSRF enforcement, and session lifecycle.
5. **Presentation** — accessible server-rendered views, progressive enhancement, localization, and static assets.
6. **Installer and operations** — preflight checks, secure bootstrap, schema migration, initial administrator setup, diagnostics, backup/restore, and permanent installer lock.

Dependencies point inward. Domain code must not depend on HTTP or infrastructure implementations.

## Deployment boundary

- Only the designated public directory is web-accessible.
- Configuration, credentials, private uploads, logs, backups, and installer state must be outside the public directory.
- If the hosting account cannot provide a safe private directory, installation must stop and explain the exact prerequisite; it must not silently place secrets under a publicly reachable path.
- Deny direct execution of uploaded content and protect private storage with restrictive permissions and non-public paths.
- The installer must use a one-time, high-entropy setup secret, enforce HTTPS, protect every state-changing request against CSRF, and permanently lock after successful installation.
- Do not expose phpinfo, raw environment variables, SQL errors, stack traces, or diagnostic logs publicly.

## Data and migrations

- Use explicit, ordered, immutable SQL migrations with checksums and a migration ledger.
- Use InnoDB and utf8mb4 where MySQL/MariaDB is selected.
- Enforce integrity in the database as well as in application services.
- Use UTC for persisted timestamps and IANA time-zone identifiers for user-facing scheduling.
- Use prepared statements for all data values; dynamic identifiers must come from strict allowlists.
- Sensitive operations must have explicit transaction boundaries and durable audit events.

## Security baseline

The implementation must be threat-modelled against OWASP Top 10:2025 and tested against applicable ASVS 5.0 controls. At minimum:

- deny by default and authorize every request at the server boundary;
- use Argon2id where supported, with a reviewed secure fallback;
- rotate session identifiers on authentication and privilege changes;
- use secure, HttpOnly, SameSite cookies and HTTPS-only transport;
- implement CSRF protection, output encoding, input validation, and safe file-upload handling;
- rate-limit authentication, verification, recovery, and other abuse-sensitive endpoints;
- require MFA for privileged administrators and step-up authentication for sensitive actions;
- store recovery codes securely and make them single-use;
- redact secrets and personal data from logs;
- maintain tamper-evident audit trails for privileged and safeguarding-sensitive actions;
- separate ordinary application access from restricted safeguarding evidence access.

## Private media and membership boundary

Public profile media and members-only media are separate authorization paths. A `members_only` moderation decision does not itself grant gallery publication consent or client access.

- Companion consent is a separate, versioned, per-image grant captured from the authenticated companion portal; only the linked companion account may grant or withdraw it.
- Client gallery access requires a client-only principal, an active and in-date membership, and an active immutable `media.private_gallery` entitlement grant.
- Every media-byte request rechecks the membership grant, profile discoverability and market eligibility, the exact current public publication grant/version, the separate members-only consent, human reviewer attestations, and the stored content hash.
- Media and membership records are locked for the duration of authorization and byte retrieval so concurrent membership suspension or companion withdrawal serializes with delivery.
- Withdrawal changes the media to a non-deliverable state, records consent/audit evidence, removes it from gallery projections, and deletes the private bytes after the transaction commits.
- The global feature flag remains disabled by default. Activation requires an explicit deployment legal-approval reference, current policy version, exact brand-bound consent-statement hash and current market/public-consent prerequisites. A brand or policy-version change invalidates stored approval and existing per-image consent. Provider and safeguarding prerequisites remain fail-closed until independent evidence adapters exist.
- Gallery pages and image responses are private, non-cacheable, non-indexable, and use opaque application routes rather than storage URLs.

## Quality gates

No feature is accepted without requirement traceability, tests for success and failure states, authorization tests, audit coverage where applicable, accessibility checks, and operational documentation. Production release additionally requires a reproducible cPanel ZIP, clean-install test, upgrade test, backup/restore test, and staging acceptance on the actual target hosting configuration.

## Destination cover media

Destination covers use the shared private media storage pipeline and the `destination_cover_assets` governance ledger. Uploaders attest to a versioned rights statement; assets are re-encoded to WebP and remain private in `pending` state. A different MFA-authorized agency administrator must review with a reason. Approval verifies the stored bytes against SHA-256 and binds the exact private key to the destination. Public delivery requires an approved cover, published destination, enabled country, current approved market review by an MFA-backed administrator, and a matching destination pointer. Withdrawal is audited, clears the public pointer transactionally, and deletes private bytes. Storage keys are never rendered to users. This is an operator attestation and human-review workflow, not a rights-verification service or legal opinion.

## Portal privacy export

Client and companion accounts can request a self-service JSON export from their authenticated portal. The export service requires a single client or companion role and uses resource-scoped queries: clients receive their own account metadata, bookings, favorites, memberships and entitlement history; companions receive their own profile, availability, bookings and consent history. The export omits password hashes, MFA/recovery material, safeguarding narratives, payment credentials and third-party contact identifiers. The download response is private and non-cacheable, and a successful export appends an audit event with a request correlation ID. This is not yet a complete data-subject-rights implementation: verified correction, erasure/deactivation, legal-hold/retention reconciliation, backup-expiry handling, and an independently reviewed export inventory remain release gates.
