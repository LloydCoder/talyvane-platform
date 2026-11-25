# Talyvane — Native PHP implementation

This is an isolated, parallel implementation of Talyvane inside the existing monorepo. The Laravel application remains intact and is the behavioral reference until a formal parity review changes that decision.

## Status

**Foundation in progress — not a complete application and not deployable.** The initial HTTP kernel, routing primitive, request/response hardening, money and quote value objects, password hashing, CSRF/session/TOTP/recovery-code/secret-encryption primitives, role authorization, feature registry and management service, PDO migration/audit foundations, and domain policies for bookings, consent, markets, memberships, payments and model-agency workflows have been started. Initial MySQL migrations now cover core identity, globalization, discovery/bookings/portals, model agency, safeguarding, payments, identity recovery, CRM/communications and branding/CMS. These are foundations, not completed end-to-end workflows. The feature-by-feature implementation ledger is [PORTING-TRACKER.md](PORTING-TRACKER.md).

Do not treat a green foundation test as proof of product parity, security certification, production readiness or cPanel compatibility.

## Current native browser-facing slices

The native HTTP kernel currently exposes:
- Public homepage, CMS pages, privacy/terms aliases, contact/enquiry intake and public destination/profile discovery.
- Separate client and companion registration, email verification/resend, portal login/logout, and email-code password recovery.
- Administrator sign-in, first-login password replacement, mandatory MFA setup/challenge, CMS, branding, navigation, enquiry inbox and companion profile publication review.
- Separate client booking request/history/cancellation, companion booking decision workspace and MFA-admin booking operations. Booking requests use destination-local input times and store UTC; manual review is the safe default, while automatic confirmation remains opt-in and policy-gated.
- Companion-owned weekly availability management with destination-derived IANA time zones, effective-date windows, overlap prevention, ownership checks and audit events.
- Client-only private saved-companion shortlist with current discoverability checks, owner-scoped removal and audit events; shortlist membership never grants media or paid entitlements. Public profile pages expose a CSRF-protected save action to authenticated client accounts and use no-store responses when rendering the session token.
- MFA-protected destination create/update workspace with ISO country/currency references, IANA time-zone/locale validation, and an independent current market-approval gate before publication.
- Super Admin-only market compliance review with evidence reference, review findings, explicit approval/rejection, dated expiry and transactional audit; market enablement is not inferred from country configuration.
- Pinned 249-country ISO 3166-1 and 155-current non-fund currency ISO 4217 reference snapshots import idempotently on first application boot; refreshes preserve administrator enablement decisions and keep market approval separate.
- A Super Admin/MFA-gated, read-only security audit workspace with bounded filtering/pagination; MySQL triggers reject audit-event updates and deletes.
- Companion profile-media intake re-encodes JPEG/PNG/WebP into metadata-stripped WebP in private owner-only storage; reviewers must separately attest adult safety, image rights and consent. Publication withdrawal unpublishes the profile, and consent renewal does not reactivate prior media grants. The public opaque media route rechecks current profile eligibility, exact consent grant/version and file integrity.
- Agency-managed client membership assignment/replacement and explicit cancellation are MFA-gated and append lifecycle events. The client portal shows a safe current-membership summary and only effective allowlisted entitlement keys. The router applies `Cache-Control: no-store` to all `/admin` and `/portal` responses. Entitlement grants are immutable and checked server-side; the actual members-only gallery and protected media delivery are not implemented.
- Safeguarding case intake, assigned-specialist access, encrypted case content, encrypted notification outbox, owner/Super Admin legal-hold governance, retention policy gates and restore-verification records are present as partial workflows. External Vault key custody, live notification delivery, backup/restore drills and full safeguarding browser acceptance remain open.
- Private runtime error logging writes correlation ID, exception class and code to `application-error.log` outside the public root; exception messages, stack traces and request data are deliberately omitted.

Portal registration and email-based recovery require a valid private `mail.smtp` configuration in the private configuration file. Without it, mail-dependent routes fail closed while the public site and already verified portal logins remain available. Public discovery returns only allowlisted fields and requires current approved market status, independent adult-eligibility verification and active purpose-specific publication consent. Public profile media delivery is consent-gated and uses opaque routes with no direct storage URLs; private members-only gallery delivery is not yet implemented.

These are implemented slices, not a complete product. Remaining parity includes full client/companion account and privacy-rights self-service; complete companion account/consent and scheduling controls; reschedule UI, calendar and notifications; model-agency portfolio/casting/assignment/settlement workflows; safeguarding browser acceptance, incident/appeal, externally managed key custody and operational retention/restore acceptance; quote/deposit/payment/reconciliation; full CRM/communications; localization/reporting/configuration/integrations; and backup/restore/upgrade/release operations. The native installer and single-ZIP package are implemented and pass automated integration/package checks; dedicated native browser E2E and actual target-host acceptance remain release gates.


## Non-negotiable delivery target

A customer must be able to upload one ZIP through cPanel File Manager, extract it, visit their domain over HTTPS, and complete a browser-based installation. The customer must not need SSH, Composer, npm, Git, Docker, terminal access, manual document-root changes, or manual source-file edits.

The installer must discover the hosting layout safely, keep application code/configuration/secrets/logs outside the public document root wherever the account layout permits, validate runtime requirements, and fail closed with actionable diagnostics when prerequisites are not met.

## Engineering constraints

- Keep native PHP isolated from Laravel runtime files, dependencies, configuration, sessions, migrations and release artifacts.
- Target PHP 8.3+ provisionally to match the current documented Laravel host baseline; verify the actual HostSailor web-SAPI runtime before locking the release minimum.
- Require `upload_max_filesize >= 8M` and `post_max_size >= 10M`; set these through cPanel's PHP Selector/MultiPHP INI Editor when the hosting defaults are lower.
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

## Native browser installer

The native front controller routes /install before the application kernel. The HTTPS-only wizard creates a 256-bit setup key outside the public document root, checks the private code root and PHP environment, applies the ordered native migrations, imports pinned country/currency reference data, tests SMTP delivery by sending separate primary and recovery codes, and activates the first Super Admin only after both addresses are verified.

Pending database/SMTP configuration and bootstrap proof are stored in an encrypted owner-only private state file. The one-time initial password and offline recovery key are persisted encrypted before the Super Admin activation transaction commits, then shown once after the permanent installer lock is written. The setup key is consumed after the lock. The first login requires password replacement and MFA enrollment.

The browser success page displays the generated initial password and offline recovery key exactly once through a session-bound HTTPS response with `no-store`, `no-cache` and `no-referrer` protections. Both are encrypted in private one-time state before the activation transaction commits; the state is consumed after rendering, and a subsequent request returns HTTP 410. Store both credentials in a password manager and approved offline recovery location before leaving the page. No terminal, SSH or command-line access is required to complete installation. The native installer has automated integration/browser coverage and a reproducible cPanel package contract; actual HostSailor cPanel/LiteSpeed acceptance remains an external deployment gate. The existing Laravel installer E2E does not validate this native installer.

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
