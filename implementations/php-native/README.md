# Talyvane — Native PHP implementation

This is an isolated, parallel implementation of Talyvane inside the existing monorepo. The Laravel application remains intact and is the behavioral reference until a formal parity review changes that decision.

## Status

**Foundation in progress — not a complete application and not deployable.** The initial HTTP kernel, routing primitive, money value object, password hashing helper, CSRF token helper, feature registry and dependency-free smoke test have been started. The feature-by-feature implementation ledger is [PORTING-TRACKER.md](PORTING-TRACKER.md).

Do not treat a green foundation test as proof of product parity, security certification, production readiness or cPanel compatibility.

## Non-negotiable delivery target

A customer must be able to upload one ZIP through cPanel File Manager, extract it, visit their domain over HTTPS, and complete a browser-based installation. The customer must not need SSH, Composer, npm, Git, Docker, terminal access, manual document-root changes, or manual source-file edits.

The installer must discover the hosting layout safely, keep application code/configuration/secrets/logs outside the public document root wherever the account layout permits, validate runtime requirements, and fail closed with actionable diagnostics when prerequisites are not met.

## Engineering constraints

- Keep native PHP isolated from Laravel runtime files, dependencies, configuration, sessions, migrations and release artifacts.
- Target PHP 8.3+ provisionally to match the current documented Laravel host baseline; verify the actual HostSailor web-SAPI runtime before locking the release minimum.
- Use PDO with parameterized queries and explicit transaction boundaries.
- Keep domain logic independent of HTTP, database, filesystem, and mail adapters.
- Use secure password hashing, CSRF protection, session hardening, rate limiting, authorization checks, MFA, audit logging, and safe error handling.
- Treat age/identity assurance, consent, safeguarding, payments, and market eligibility as high-risk domain boundaries. Do not weaken the product requirements to simplify the implementation.
- Never infer age, race, ethnicity, nationality, religion, or consent.
- Never expose identity documents, secrets, stack traces, or private operational logs through the public web root.
- Every behavior must have automated tests; security-sensitive flows require integration and end-to-end coverage.

## Proposed layout

```text
implementations/php-native/
├── README.md
├── ARCHITECTURE.md
├── PORTING-TRACKER.md
├── public/                 # Only web-accessible entrypoint and static assets
├── src/                    # Domain, application, and infrastructure code
├── config/                 # Non-secret configuration templates
├── database/               # Versioned SQL migrations and seed definitions
├── resources/              # Views, translations, and frontend source
├── routes/                 # HTTP route declarations
├── tests/
│   ├── Unit/
│   ├── Integration/
│   └── Security/
├── installer/              # Browser-based, one-time installer
└── bin/                    # Maintainer-only build and verification scripts
```

The final release ZIP may use a different flattened layout if required for cPanel compatibility. Build output must be reproducible and must not include development secrets, test data, or local environment files.

## Development sequence

1. Requirement inventory and traceability.
2. Secure runtime, installer, database migrations and deployment bootstrap.
3. Identity, authorization, MFA, audit and recovery.
4. Public site, CMS, discovery, booking and client/companion portals.
5. Model agency applications, agreements, portfolios, casting, assignments and settlements.
6. Verification, consent, safeguarding, CRM, communications, payments and globalization.
7. Super Admin configuration, integrations, reporting and operational workflows.
8. Automated parity/security tests, cPanel package, real-host acceptance, backup/restore and upgrade drills.

See [PORTING-TRACKER.md](PORTING-TRACKER.md) for acceptance status. No module is complete until its success/failure states, role boundaries, audit events, accessibility, privacy and operations evidence are accepted.
