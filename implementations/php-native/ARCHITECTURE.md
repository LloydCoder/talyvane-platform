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

## Booking lifecycle and rescheduling

- Client, companion and administrator booking projections are separate read models; client and companion queries are scoped to the authenticated principal and omit the other party's account identifier.
- Client cancellation of a pending-review/requested booking is permitted without enabling confirmed-booking cancellation. Cancellation of a confirmed booking is disabled unless the current deployment booking policy explicitly enables it and the request is outside the configured cutoff.
- Rescheduling is available only to the owning client for accepted or confirmed bookings. The portal form resolves the booking through the client's scoped history before rendering or accepting a change; POST requests require CSRF validation.
- Reschedule inputs are interpreted in the destination's IANA time zone, reject nonexistent local wall-clock times, and are converted to UTC before the command service. The command service re-locks the booking, rechecks current profile consent, adult-verification, destination/market eligibility, quote validity, overlap and booking-policy constraints, and preserves the original duration and accepted/confirmed state.
- A duration change requires a separate booking request and quote. External calendar providers, calendar-feed tokens and synchronization retries are not yet implemented; no external calendar side effect is implied by a successful internal reschedule.
- Booking lifecycle email events are inserted into the shared communications outbox within the booking transaction. Recipient addresses and payloads are AES-256-GCM encrypted with context binding; recipient fingerprints are keyed HMACs; idempotency keys bind booking, event and recipient. The dispatcher atomically claims due records, sends through the configured SMTP adapter, records delivery attempts, and applies bounded exponential retry. Delivery is at-least-once: a process crash after SMTP acceptance but before the database acknowledgement can cause a duplicate email. Real SMTP/cron operations, editable localized templates, user notification preferences and provider-level delivery reconciliation remain acceptance work.

## Private booking calendars and iCalendar export

- Calendar pages and .ics downloads require an authenticated client or companion session. The router applies private, no-store response headers to portal routes.
- Calendar database queries are scoped by the authenticated client user ID or companion profile owner ID. Companion event projections intentionally omit client identity, contact data and private booking notes.
- iCalendar exports use UTC DTSTART/DTEND, opaque HMAC-derived stable UIDs, escaped RFC5545 text, UTF-8-safe 75-octet line folding, LAST-MODIFIED and timestamp-based SEQUENCE values.
- Recent cancellations are emitted as STATUS:CANCELLED events using the same stable UID so downstream import/synchronization clients can reconcile previously exported events. Only accepted, confirmed and recently changed cancelled bookings are exported.
- The current implementation is an authenticated download, not a public subscription feed. Calendar tokens, revocation, provider OAuth/webhooks, outbound synchronization, retry queues, pagination beyond the bounded export query and cross-provider reconciliation remain unimplemented. No provider connection or external calendar mutation is implied.

## Client and companion privacy-rights request handling

- Signed-in client and companion accounts can initiate correction or erasure requests. The service requires an active, email-verified account, role-scoped permission, per-account/IP rate limiting and an eight-digit one-time code with a ten-minute expiry and five-attempt lockout.
- Administrator review, correction and erasure fulfilment fail closed if the encrypted privacy-outcome outbox is unavailable. Before correction/erasure completes, the service rechecks that the account is active and the current notification destination is still email-verified; the outcome notice is queued in the same database transaction as the privacy operation.
- Correction explanations are encrypted with the application SecretBox and a request-specific context string. OTPs are stored only as keyed HMAC digests. The user-facing history exposes request metadata and decisions, never the encrypted explanation.
- Verification moves the request into the restricted in_review queue. Owner/admin review requires an MFA-verified principal and the dedicated privacy.requests.review permission. Reviewers can defer or reject with a mandatory reason and an audit event.
- This workflow intentionally does not claim complete global erasure. A verified erasure request never automatically disables or deletes an account. Active bookings, financial records, safeguarding duties, legal holds, retention periods and backup expiry must be evaluated before anonymization. The current fulfilment path requires a current verified notification destination and encrypted outcome outbox, but linked-data legal-hold/retention reconciliation, external storage/provider propagation and backup-expiry proof remain outstanding.


## CRM response templates and delivery

- Customer responses use the existing `communication_templates` registry. Each email template is versioned by fixed template key and locale; publishing a new version deactivates the previous version for that locale without mutating its content.
- Only MFA-authorized administrators with the enquiry-management permission can publish templates or queue replies. Template placeholders are allowlisted; subject and body are plain text, and no arbitrary PHP, HTML, URL fetch or dynamic expression is evaluated.
- Reply queueing locks the enquiry, rejects closed/spam records and invalid destinations, renders a selected locale template (with explicit English fallback), and inserts encrypted recipient/payload records into the shared transactional outbox with a request-bound idempotency key. Internal notes and unrelated enquiry data are not copied into the message.
- Queueing is not delivery. New enquiries remain in progress until the private SMTP dispatcher receives transport acceptance, then the enquiry is marked responded. The dispatcher claims bounded batches, records attempts, retries with capped exponential backoff and is at-least-once: a crash after SMTP acceptance but before acknowledgement may cause a duplicate.
- The cPanel CLI dispatcher requires owner-only configuration outside the public root and an explicit canonical public-root path. Provider-level bounce/complaint webhooks, delivery reconciliation, monitoring/alerting, localized template review and target-host SMTP/cron acceptance remain external operational work.

## External privacy-erasure evidence gate

- Before client or companion erasure, the service locks the privacy request and requires an Owner/Super Admin reviewed record for every configured external-system gate: encrypted database backups, private storage backups, SMTP/mailbox providers, payment processors, analytics integrations, external evidence repositories and safeguarding key custody.
- A `retained` decision requires a legal-basis reference and future UTC expiry and is not sufficient to fulfil erasure. The operator must later record a `cleared` or `not_applicable` decision with an evidence reference.
- The UI accepts opaque evidence references only. Never paste credentials, signed URLs, personal data or secret tokens.
- These records are auditable human attestations, not cryptographic proof, provider API confirmation or automatic deletion. Production acceptance still requires independent provider/backup/Vault evidence and qualified legal review.
