# Talyvane — Native PHP implementation

This is an isolated, parallel implementation of Talyvane inside the existing monorepo. The Laravel application remains intact and is the behavioral reference until a formal parity review changes that decision.

## Status

**Foundation and vertical slices in progress — not a complete application and not deployable.** The initial HTTP kernel, routing primitive, request/response hardening, money and quote value objects, password hashing, CSRF/session/TOTP/recovery-code/secret-encryption primitives, role authorization, feature registry and management service, PDO migration/audit foundations, and domain policies for bookings, consent, markets, memberships, payments and model-agency workflows have been started. The native schema now has 31 ordered migrations covering core identity, globalization, discovery/bookings/portals, model agency, safeguarding, payments, identity recovery, CRM/communications, branding/CMS, media governance, memberships, retention/restore governance, model casting/portfolio/assignment/settlement parity, booking quote governance and calendar feed tokens. PR #90 merged the secure browser installer, dual-email Super Admin bootstrap, first-login password/MFA journey, native one-ZIP package, profile-media governance, membership lifecycle and safeguarding foundations into `main` (merge commit `9123d08c4dc47b52a943c5531c6365e9e0d59810`). Its recorded native PHP 8.3/8.4/8.5, MySQL, Semgrep, package and browser-installer checks passed. These are foundations and partial vertical slices, not completed end-to-end product workflows or live HostSailor acceptance. The feature-by-feature implementation ledger is [PORTING-TRACKER.md](PORTING-TRACKER.md).

Do not treat a green foundation test as proof of product parity, security certification, production readiness or cPanel compatibility.

## Current native browser-facing slices

The native HTTP kernel currently exposes:
- Public homepage, CMS pages, privacy/terms aliases, contact/enquiry intake and public destination/profile discovery.
- Separate client and companion registration, email verification/resend, portal login/logout, and email-code password recovery.
- Client/companion self-service account-data export with role-scoped allowlists, JSON download, per-account rate limiting, no-store response handling, correlated audit events and explicit exclusion of password hashes, MFA/recovery secrets, safeguarding narratives, payment credentials and third-party contact details. This is an export slice, not the full verified erasure/correction/retention workflow.
- Client/companion correction and erasure request intake with eight-digit email OTP verification, per-account rate limits, encrypted correction summaries, account-scoped status history and an MFA-gated administrator review queue. Review decisions can defer or reject with a reason; correction fulfilment and erasure are not automatic.
- Administrator sign-in, first-login password replacement, mandatory MFA setup/challenge, CMS, branding, navigation, enquiry inbox and companion profile publication review.
- Separate client booking request/history/cancellation/rescheduling, private client and companion calendar views with authenticated iCalendar exports, companion booking decision workspace and MFA-admin booking operations. Booking requests and reschedules use destination-local input times and UTC storage; rescheduling preserves duration and booking state, while confirmed-booking client cancellation is explicitly policy-gated. Manual review is the safe default, while automatic confirmation remains opt-in and policy-gated.
- Companion-owned weekly availability management with destination-derived IANA time zones, effective-date windows, overlap prevention, ownership checks and audit events.
- Client-only private saved-companion shortlist with current discoverability checks, owner-scoped removal and audit events; shortlist membership never grants media or paid entitlements. Public profile pages expose a CSRF-protected save action to authenticated client accounts and use no-store responses when rendering the session token.
- MFA-protected destination create/update workspace with ISO country/currency references, IANA time-zone/locale validation, and an independent current market-approval gate before publication.
- Destination cover workflow: JPEG/PNG/WebP uploads are re-encoded to metadata-stripped WebP in private storage, accompanied by a versioned copyright/licence/property/likeness rights declaration, independently reviewed by a different MFA-authorized administrator, and exposed only through an opaque public route while the destination and market remain eligible. Withdrawal clears the public pointer and deletes private bytes.
- Super Admin-only market compliance review with evidence reference, review findings, explicit approval/rejection, dated expiry and transactional audit; market enablement is not inferred from country configuration.
- Pinned 249-country ISO 3166-1 and 155-current non-fund currency ISO 4217 reference snapshots import idempotently on first application boot; refreshes preserve administrator enablement decisions and keep market approval separate.
- A Super Admin/MFA-gated, read-only security audit workspace with bounded filtering/pagination; MySQL triggers reject audit-event updates and deletes.
- Companion profile-media intake re-encodes JPEG/PNG/WebP into metadata-stripped WebP in private owner-only storage; reviewers must separately attest adult safety, image rights and consent. Approved media can be scoped to public or members-only. Members-only items require a separate, versioned companion opt-in using the canonical brand-aware consent statement; the exact statement hash and policy version are retained in append-only consent events. A brand or policy change invalidates prior consent; withdrawal immediately revokes delivery and deletes the stored image. The protected client gallery rechecks active membership entitlement, current profile eligibility, market approval, exact public consent grant/version, private-gallery consent and file integrity. Publication withdrawal unpublishes the profile, and consent renewal does not reactivate prior media grants. The public opaque media route retains its separate public-only checks.
- Agency-managed client membership assignment/replacement, immediate suspension and cancellation are restricted to MFA-protected agency owners/admins and append lifecycle events. The admin directory preserves active/suspended state; the client portal shows a safe current-membership summary and only effective allowlisted entitlement keys. The router applies `Cache-Control: no-store` to all `/admin` and `/portal` responses. Entitlement grants are immutable and checked server-side. The members-only gallery has a client-only portal route and protected opaque media route; each image requires separate versioned companion consent, current public profile consent/market eligibility, human approval attestations and an active client entitlement. Consent withdrawal serializes against delivery, removes the image from gallery projections and deletes private bytes. Activation requires an explicit legal-approval reference, current policy version and exact brand-bound consent-statement hash, plus current market/public-consent prerequisites; brand or policy changes invalidate approval and existing consent. The feature remains disabled by default and provider/safeguarding gates remain fail-closed.
- Private model-agency workflows now include MFA-gated casting calls and submissions, private sanitized portfolio assets, purpose-specific image licences, human shortlisting/selection, policy-gated assignment creation and transitions, and a finance workspace for expenses, independent review, integer-minor-unit settlement calculations, maker-checker approval, external-payment attestations, reversal and dispute evidence. MySQL integration coverage exercises the selected-submission-to-assignment-to-settlement path, encrypted sensitive fields, idempotent expense replay and separation of submitter/reviewer/approver/payer. These workflows remain gated by explicit legal, rights, consent, adult-verification and market approvals; the platform records external payment attestations and does not transfer or custody funds. Model self-service, client casting/assignment distribution, complete notifications and browser acceptance remain open.
- Safeguarding case intake, assigned-specialist access, encrypted case content, encrypted notification outbox, owner/Super Admin legal-hold governance, retention policy gates and restore-verification records are present as partial workflows. External Vault key custody, live notification delivery, backup/restore drills and full safeguarding browser acceptance remain open.
- Private runtime error logging writes correlation ID, exception class and code to `application-error.log` outside the public root; exception messages, stack traces and request data are deliberately omitted.

Portal registration and email-based recovery use the flat SMTP configuration written by the native installer in the private configuration file (`mail.host`, `mail.port`, `mail.username`, `mail.password`, `mail.from_email`, `mail.from_name`, `mail.encryption`). Without it, mail-dependent routes fail closed while the public site and already verified portal logins remain available.

### Booking notification cron

The booking dispatcher is a private CLI entrypoint; it must never be exposed as a public HTTP route. Configure a cPanel Cron Job to run it periodically (for example, once per minute), using the account's actual private paths and PHP binary:

```sh
TALYVANE_CONFIG="/absolute/private/path/config/app.php" \
  /absolute/path/to/php /absolute/private/path/application/bin/dispatch-booking-notifications.php
```

The dispatcher claims at most 25 due booking-email records per run, uses SMTP settings from the installer's flat `mail` configuration, and records attempts/retries in the database. The command is safe to overlap because claims are transactionally locked. Keep stdout/stderr and cron mail under the hosting account's operational controls; never move the private configuration into the public document root. Actual cPanel cron scheduling, real SMTP delivery, alerting and dead-letter operations remain release acceptance tasks. Public discovery returns only allowlisted fields and requires current approved market status, independent adult-eligibility verification and active purpose-specific publication consent. Public profile media delivery is consent-gated and uses opaque routes with no direct storage URLs. A separate members-only gallery route is implemented behind a feature gate and active membership entitlement; live browser acceptance and production market/provider readiness remain outstanding.

These are implemented slices, not a complete product. Remaining parity includes actual client/companion correction fulfilment, safe anonymization/erasure and retention/backup expiry; complete companion account/consent and scheduling controls; external calendar provider synchronization, calendar feed tokens/revocation, real SMTP/cron acceptance, localized communication templates and delivery reconciliation; model self-service and client casting/assignment distribution; safeguarding browser acceptance, incident/appeal, externally managed key custody and operational retention/restore acceptance; quote/deposit/payment/reconciliation; full CRM/communications; localization/reporting/configuration/integrations; and backup/restore/upgrade/release operations. The native installer browser E2E and single-ZIP package workflows passed on PR #90 code head `4d7726bb922fba1d62443deff0daa0feef4e15c9` (native installer E2E run 80); actual target-host acceptance remains a release gate.


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
