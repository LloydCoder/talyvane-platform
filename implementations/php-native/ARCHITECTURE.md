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

## Quality gates

No feature is accepted without requirement traceability, tests for success and failure states, authorization tests, audit coverage where applicable, accessibility checks, and operational documentation. Production release additionally requires a reproducible cPanel ZIP, clean-install test, upgrade test, backup/restore test, and staging acceptance on the actual target hosting configuration.
