# Talyvane — Native PHP implementation

This directory is the isolated workspace for a native PHP implementation of Talyvane.

## Status

**Scaffold only — not an application and not deployable.**

The Laravel application remains the existing implementation and is not moved, renamed, or modified by this workspace. The native PHP implementation must be developed and evaluated independently before any decision is made about the preferred production implementation.

## Non-negotiable delivery target

A customer must be able to upload one ZIP through cPanel File Manager, extract it, visit their domain over HTTPS, and complete a browser-based installation. The customer must not need SSH, Composer, npm, Git, Docker, terminal access, manual document-root changes, or manual source-file edits.

The installer must discover the hosting layout safely, keep application code/configuration/secrets/logs outside the public document root wherever the account layout permits, validate runtime requirements, and fail closed with actionable diagnostics when prerequisites are not met.

## Engineering constraints

- Target the PHP version supported by the agreed HostSailor account; verify the actual runtime before setting a minimum version.
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

## Current phase

1. Establish the implementation boundary and architecture.
2. Inventory the canonical product requirements and map every requirement to native PHP modules and tests.
3. Build the secure installer and deployment bootstrap first.
4. Implement vertical slices with tests, beginning with identity, authorization, audit, and installation lifecycle.
5. Compare feature parity, security, operations, and cPanel deployment against Laravel.
6. Do not promote either implementation based on a homepage/demo alone.
