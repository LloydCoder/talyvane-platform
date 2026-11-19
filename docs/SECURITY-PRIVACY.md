# Security and Privacy Baseline

This is an engineering baseline, not a certification or legal opinion. Use OWASP ASVS 5.0.0 as the version-pinned verification framework and OWASP Top 10:2025 as a risk-awareness taxonomy. ASVS provides testable requirements; the Top 10 is not a substitute for a control catalogue or security assessment. Map applicable ASVS requirements to implementation evidence, automated tests and manual review. Do not claim ASVS compliance or certification without a scoped independent assessment.

Official references:
- OWASP ASVS 5.0.0: https://asvs.owasp.org/
- OWASP Top 10:2025: https://top10.owasp.org/2025/

## Verification governance
- Maintain a threat model covering public discovery, account lifecycle, privileged administration, bookings, companion autonomy, identity/age evidence, media, payments, integrations, exports, backups, installers and upgrades.
- Track each applicable ASVS requirement as not assessed, pass, fail, not applicable (with rationale), or blocked; retain test/evidence references and reviewer/date.
- Require negative authorization tests for every role and resource boundary, including tenant/deployment isolation if hosted multi-tenancy is introduced.
- Include dependency/SBOM review, secret scanning, static analysis, dynamic testing, upload abuse tests, backup-restore exercises and manual security review in release gates.
- Treat CI success as evidence only for the checks actually executed; it is not proof of legal compliance, production readiness or security certification.

## Identity and access
- Server-side role and resource policies on every protected request.
- MFA for administrators, finance, verification and safeguarding roles.
- Strong password hashing, login throttling, session rotation and secure recovery.
- TLS-only secure, HttpOnly, SameSite cookies.
- Reauthentication for privileged actions; prevent IDOR with resource-scoped queries.

## Sensitive data
Collect only necessary data for documented purposes. Never expose legal names, identity documents, private addresses, phone numbers or verification evidence publicly. Store verification evidence separately with restricted access, encryption where supported and access audit. Record consent purpose, version, actor, timestamp and withdrawal. Profile publication and automatic-booking opt-in/out are recorded as separate review events. Provider references and external payment transaction references are stored as keyed HMAC digests, not raw identifiers; no identity document is stored in public profile tables. Define retention/deletion schedules. Do not infer age, identity or sensitive traits.

## Media
Validate extension, MIME and file signature; re-encode images/transcode video; strip EXIF/GPS; randomise storage names; keep originals private; enforce limits; scan and quarantine uploads; prevent path traversal and executable uploads. Publish only after adult-status, ownership and explicit publication-consent checks. Provide rapid unpublish and consent withdrawal. Signed URLs cannot prevent screenshots or copying.

## Payments
Never collect seed phrases/private keys. Validate public addresses, network and token contract. Screenshots, client callbacks and return URLs are not payment proof. Verify server-side; use idempotency/replay protection; snapshot invoice instructions; separate finance permissions. Do not claim custody or escrow without approval.

## Application controls
Validate trust-boundary inputs; parameterised queries; output encoding; CSRF; restrictive CORS; CSP/security headers; rate limits; SSRF protections on URL importers; explicit DTOs; dependency scanning; no debug mode or stack traces in production.

## Audit and response
Audit privileged configuration, profile publication, companion consent changes, booking requests, booking decisions, deposit verification, refunds, exports and suspensions. The application now records append-only booking events and profile-review records; production still needs a documented retention schedule, tamper-evident export/backup strategy and a complete audit coverage review. Keep sensitive payloads out of logs. Define incident triage, containment, evidence preservation, notification assessment and recovery.

## Release gates
No production release until authorisation tests, upload security, payment verification, privacy controls, backup restore, dependency audit, secret scan, vulnerability review and jurisdictional sign-off pass.


## Super Admin feature controls

- Feature state changes are restricted to the deployment Super Admin and require current-password verification plus a fresh, single-use TOTP code.
- Every state change requires a meaningful operator reason and is committed with its append-only audit event in one database transaction.
- TOTP steps are consumed even when activation is blocked, preventing reuse of the same authenticator code for another action.
- Activation fails closed for unimplemented features, disabled dependencies and unknown or unsatisfied prerequisites. Market activation requires at least one fully evidenced operationally approved market.
- Disabling optional features is available as an emergency control. Re-enabling runs the activation evaluator again.
- The registry is not a legal approval system and cannot override market, age-assurance, consent, safeguarding, payment integrity or other independent domain policy checks.

- Companion publication-consent withdrawal and media removal remain reachable through a separate authenticated privacy-controls page even when the general companion portal feature is disabled.


## Designated deployment Super Admin lifecycle

The singleton `platform_control.super_admin_user_id` is the canonical deployment principal. Eloquent model lifecycle guards prevent the designated user from being demoted, deactivated or deleted and prevent a second Super Admin role assignment after the principal is set. Regression tests cover these operations. This is defense in depth, not a database authorization boundary: direct SQL, bulk query-builder updates and privileged database access can bypass Eloquent events. Restrict production database credentials and audit privileged database access. The CLI-only recovery procedure is implemented and documented, but a separate dual-control break-glass process for loss of both password and offline key remains a release gate.

MFA secrets, recovery-code hashes and TOTP replay-state fields are excluded from `User` model serialization. Regression tests assert that these fields are absent from serialized user payloads. This reduces accidental exposure through API/resource serialization but does not replace explicit response DTOs and access-control review.


## Super Admin recovery

Super Admin provisioning and recovery require two separately verified notification addresses and a one-time offline deployment recovery key. Recovery is CLI-only and requires the current password plus that offline key. A successful recovery clears TOTP material, rotates the key, increments the admin session version, rotates the remember token, notifies both verified addresses and records an append-only recovery event. Admin routes reject sessions with missing or stale security versions. The detailed operator runbook is [SUPER-ADMIN-RECOVERY.md](SUPER-ADMIN-RECOVERY.md).

The recovery audit model blocks Eloquent updates/deletes, but direct SQL can bypass application events. This is not a database-level tamper-proof log. Production database credentials and backups must be restricted. Loss of both the password and offline key still requires separately governed deployment-owner identity proofing and dual-control break-glass handling; no public override exists.
