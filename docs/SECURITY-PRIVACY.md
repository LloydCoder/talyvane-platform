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

## Administrator role separation

- `super_admin`: deployment-wide configuration and emergency governance within one isolated installation.
- `owner`: agency oversight and operational administration.
- `admin`: day-to-day agency operations; does not receive the model settlement workspace.
- `finance`: dedicated access to the model settlement workspace only. Finance accounts do not receive the general dashboard, profile, client, booking, model application, casting, assignment or deployment-configuration routes. Finance access requires the same session-version enforcement and TOTP MFA as other privileged accounts.
- Finance specialists and eligibility verifiers are provisioned from a trusted CLI with `php artisan talyvane:admin:create finance@example.test --role=finance` or `php artisan talyvane:admin:create verifier@example.test --role=verifier`. The default role remains `owner`; the command rejects `super_admin`, which is provisioned only through the designated bootstrap flow.
- The Super Admin and Owner retain settlement access for deployment/agency oversight. The settlement service additionally enforces independent maker-checker actors for expense review, settlement approval and payment attestation; this is an application-level control, not independent bank/provider authorization.

## Specialist verifier access

- The `verifier` principal is a dedicated privileged role and must complete TOTP MFA and current-session-version checks.
- Verifiers receive only the adult-eligibility queue and its single-purpose verification action. They do not receive the general dashboard, applicant administration, portfolio/media, casting, assignment, finance, booking, client or deployment-configuration routes.
- The verifier queue selects only the application identifier, reference, applicant display name, workflow status, accepted policy version and submission timestamp. It excludes email, phone, city, portfolio links, experience notes, agreement data and media relationships.
- Verification remains a manual document-inspection attestation, not an identity-provider integration. The platform intentionally stores no identity document image, number or date of birth. A real provider integration and jurisdiction-specific operational review remain release gates.
- Verification records are immutable through the application workflow and record the verifier actor and event. A verifier cannot accept, decline, or otherwise change application status.
- Safeguarding case access is not included in the verifier role and remains a separate specialist-role implementation.

## Sensitive data
Collect only necessary data for documented purposes. Never expose legal names, identity documents, private addresses, phone numbers or verification evidence publicly. Store verification evidence separately with restricted access, encryption where supported and access audit. Record consent purpose, version, actor, timestamp and withdrawal. Profile publication and automatic-booking opt-in/out are recorded as separate review events. Provider references and external payment transaction references are stored as keyed HMAC digests, not raw identifiers; no identity document is stored in public profile tables. Define retention/deletion schedules. Do not infer age, identity or sensitive traits.

## Model agency applications

- Keep model applicants and their data separate from companion profiles, clients, bookings and companionship CRM records.
- Collect only necessary contact, location, portfolio-link and experience information; do not collect date of birth, identity documents or applicant photographs in the public application form.
- Adult eligibility is an applicant self-attestation only, never an age/identity verification result. Do not represent an applicant, publish adult content or assign work until a suitable independent age/identity assurance process and legal review are complete.
- Require explicit, versioned privacy notice acceptance and a separately approved adult-eligibility policy before enabling intake. Both approvals are deployment configuration gates and default to false; the CMS pages `model-applicant-privacy` and `model-adult-eligibility` must also be published. The same activation evaluator is rerun at the application HTTP boundary.
- Treat portfolio URLs as untrusted display links: require HTTPS, escape output, open with `noopener noreferrer nofollow`, and never fetch, crawl, preview or resolve them server-side.
- Restrict application records to agency administrators. Record each state transition with actor, prior/new status, timestamp and reason; prevent application-event updates/deletes through the model boundary. Direct database access remains privileged and must be controlled separately.
- Require email-verified access/correction/withdrawal/erasure workflows, hashed one-time codes with expiry/attempt limits, owner-only legal holds, no-store applicant/admin PII pages and a policy-driven retention command before enabling model-agency applications. Activation also requires approved retention periods, SMTP and an operator-confirmed scheduler. Do not disable applicant privacy-support or administrator governance routes when disabling new application intake; they must remain available for existing records.
- Application acceptance is not representation, a contract, a casting confirmation or an image-use licence. Those require separate signed terms and purpose-specific rights records.
- References: [ICO data minimisation guidance](https://ico.org.uk/for-organisations/uk-gdpr-guidance-and-resources/data-protection-principles/a-guide-to-the-data-protection-principles/data-minimisation/); [NIST SP 800-63A-4 identity proofing](https://pages.nist.gov/800-63-4/sp800-63a/proofing/); [OWASP SSRF Prevention Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Server_Side_Request_Forgery_Prevention_Cheat_Sheet.html). These inform the engineering boundaries; they do not establish legal compliance or an age-verification implementation.

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

Super Admin provisioning and recovery require two separately verified notification addresses and a one-time offline deployment recovery key. Recovery is CLI-only and requires the current password plus that offline key. Existing deployments can enroll recovery credentials with current password plus TOTP, and verified recovery contacts/key can be rotated with current password plus TOTP. A successful recovery clears TOTP material, rotates the key, increments the admin session version, rotates the remember token, notifies both verified addresses and records an append-only recovery event. Admin routes reject sessions with missing or stale security versions, and pending MFA challenges are invalidated if the version changes before MFA completion. The detailed operator runbook is [SUPER-ADMIN-RECOVERY.md](SUPER-ADMIN-RECOVERY.md).

The recovery audit model blocks Eloquent updates/deletes, but direct SQL can bypass application events. This is not a database-level tamper-proof log. Production database credentials and backups must be restricted. Loss of both the password and offline key still requires separately governed deployment-owner identity proofing and dual-control break-glass handling; no public override exists.


## Financial maker-checker authorization

The settlement service enforces independent actors for financial state transitions within the locked database transaction:

- An expense claimant cannot approve or reject their own expense claim.
- A settlement draft creator cannot approve that draft.
- A settlement approver cannot attest to the external payment for that settlement.
- The restrictions apply to Owner and Super Admin accounts as well as Finance specialists; privileged oversight does not create a self-approval exception.
- Rejected attempts do not mutate the expense or settlement. Successful decisions retain the actor identifiers and append-only event records.

These controls are application-level authorization. They do not verify external bank transfers, prevent privileged direct database writes, or replace independent bank/payment-provider dual authorization. Operators must independently reconcile external payment evidence and restrict database credentials.
