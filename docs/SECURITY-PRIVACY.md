# Security and Privacy Baseline

The Laravel security/privacy requirements remain canonical. Native PHP parity, current-head CI evidence and native-specific external release blockers are tracked separately in [NATIVE-PHP-PORT-STATUS.md](NATIVE-PHP-PORT-STATUS.md) and [the native parity tracker](../implementations/php-native/PORTING-TRACKER.md).

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

## Privacy-rights fulfilment notifications
- A privacy review decision or correction/erasure fulfilment must not commit if the encrypted outcome outbox is unavailable.
- Before a fulfilment transaction completes, re-check that the account is active and the current notification destination is still verified. Never send the notice to a stale or unverified address.
- Queue the generic outcome notice transactionally with the privacy state change; do not include restricted review rationale or corrected personal values in the message.
- This delivery control does not prove linked-data erasure, third-party propagation, encrypted-backup expiry or external mailbox deletion. Those remain separately evidenced retention/release gates.

## Identity and access
- Server-side role and resource policies on every protected request.
- Membership benefits are never authorization based on a tier label or client-facing summary. Use the server-owned entitlement catalogue and active/in-date grants; check at both query projection and final resource delivery. Keep private media URLs out of public discovery and cache responses.
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

## Safeguarding case access

- `safeguarding` is a dedicated privileged role with TOTP MFA and current-session-version enforcement. It cannot access the general agency dashboard, verifier queue, finance, booking, client, model-agency or deployment workspaces.
- Agency `super_admin`, `owner` and `admin` users may submit a confidential report and assign it only to an active safeguarding specialist whose MFA is already enrolled. The reporter cannot read the case after submission.
- A safeguarding specialist can read and transition only cases assigned to that exact user ID. Case queries are resource-scoped; guessing another case identifier returns not found.
- Case summaries, details, subject references, legal-hold reasons and case-event notes use Laravel encrypted casts. The encryption key and backups therefore require the same strict protection as production secrets and sensitive data.
- Case creation and state transitions append case events. Every case detail view appends an access event. These application-level append-only model guards do not prevent direct SQL or privileged database tampering.
- The register stores no uploaded identity documents or evidence files. Related records are linked only by an opaque application reference or other approved record reference. Do not place identity-document numbers, credentials, payment secrets or unnecessary personal data in case narratives.
- Current case states are `open`, `triaged`, `investigating`, `action_required`, `resolved` and `closed`; transitions are constrained in the service layer. Closed cases are terminal in this release.
- This release provides authenticated internal reporting only. It is not an emergency response service, public/anonymous reporting channel, hotline, notification dispatcher, statutory reporting integration or guarantee of safeguarding intervention. Operators must maintain independent emergency contacts, trained human responders, jurisdiction-specific procedures and documented response-time commitments.
- Retention schedules, legal-hold administration, export controls, notification/escalation, independent response drills and external safeguarding oversight remain release gates.

## Safeguarding legal-hold governance

- Super Admin and Owner accounts can access a metadata-only governance register. It exposes case references, category, severity, workflow state, timestamps and hold state; it never selects or renders case summaries, narratives, subject references or event notes.
- Legal holds require a reason and record the actor, timestamp and encrypted rationale in the case and append-only event history.
- A legal hold cannot be released by the same actor who placed it. Release requires a different active Super Admin or Owner, a separate reason, and append-only case/access audit events.
- This control records a platform preservation hold only. It does not place holds on external storage, email, messaging, payment providers, backups or third-party evidence systems.
- Retention execution is implemented behind fail-closed gates and remains disabled by default. It requires an approved case policy, separately approved encrypted-backup expiry, a different active MFA Owner/Super Admin, recent independent restore/key-reconciliation evidence and external per-case key destruction. Local application-key wrapping alone cannot provide global cryptographic erasure.

## Safeguarding content retention

- Safeguarding narratives, related-record references, legal-hold reasons and case-event notes are encrypted with a unique per-case data-encryption key (DEK). The DEK is wrapped with the application encryption key; it is not stored in plaintext.
- The migration moves existing encrypted case content and event notes into the per-case key scheme, then clears the legacy content columns. Keep the production `APP_KEY` stable and take a separately encrypted, access-controlled backup before applying this migration.
- Retention is disabled by default. It requires an approved schedule, positive day count, policy version, and a separately approved encrypted-backup expiry period.
- Only closed cases older than the configured retention period are eligible. An active legal hold always excludes a case. The command rechecks status, close timestamp and hold state under a row lock before removing the DEK from the primary database.
- The primary database retains case references, status/severity metadata, event types, actor/timestamp metadata and access logs. Case narratives and event notes become unreadable in the primary database after key removal.
- The wrapped DEK and ciphertext may still exist in older database backups. Removing the live database key row is not immediate global erasure. Backups must be separately encrypted, expire on the approved schedule, and be prevented from restoring erased keys into production. Do not claim complete cryptographic erasure until backup expiry or an independently managed external key-vault destruction process is verified.
- Migration rollback is intentionally blocked after any case content has been retention-erased because the original content cannot be reconstructed from a destroyed key.

## Safeguarding notification delivery

- Case assignment and critical/minor-safety escalation notices are written to a transactional outbox; case intake does not synchronously send email.
- Recipient email addresses are encrypted at rest and cleared from the outbox after successful delivery for external escalation recipients. A keyed HMAC fingerprint is used for deduplication; no plaintext recipient address is used as a unique database key.
- Assignment notices contain only case reference, category, severity and a restricted internal URL. External emergency-contact notices contain only reference, category and severity and include no internal URL. Case summary, narrative, subject reference, applicant contact information, identity-document data and evidence are excluded.
- The dispatcher requires an explicit enable flag and rejects `log` and `array` mailers. Delivery retries are bounded, stale processing claims are recovered, and raw transport exception messages are not persisted. Dispatch is at-least-once and may duplicate a message if the process crashes after provider acceptance but before database acknowledgement.
- Critical and minor-safety cases are escalated only to valid addresses explicitly configured by the deployment operator. Missing contacts create a case event and access-audit event rather than a false delivery claim.
- Email is not an emergency response channel or guaranteed delivery. Failed outbox items require operational monitoring; maintain independent emergency contacts and human response procedures.

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
Validate trust-boundary inputs; parameterised queries; output encoding; CSRF; restrictive CORS; CSP/security headers; rate limits; SSRF protections on URL importers; explicit DTOs; dependency scanning; no debug mode or stack traces in production. Authenticated `/admin` and `/portal` responses must use `Cache-Control: no-store, no-cache, must-revalidate, private` (plus `Pragma: no-cache`) so account, booking, membership and administrative data is not retained by shared or browser caches; public HTML retains its separately configurable cache policy.

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

## External safeguarding key custody

An optional HashiCorp Vault Transit adapter supports per-case, non-exportable keys. The application token performs key creation and encrypt/decrypt only; irreversible key deletion requires a separate retention credential. Vault endpoints must use HTTPS with certificate verification and redirects disabled. The two credentials are environment-backed and are never serialized into application records.

Vault mode is opt-in. Existing local-key records remain marked `local` and continue to use the existing application-key wrapper. New records use the configured provider. Do not switch providers in a live deployment until Vault connectivity, least-privilege policies, key lifecycle, token rotation, audit logging and recovery have been tested. A Vault backup may restore deleted keys, so Vault backup retention and restore controls must be independently verified before claiming complete erasure.

References: [OWASP Secrets Management Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Secrets_Management_Cheat_Sheet.html), [NIST SP 800-57 Part 1 Rev. 5](https://doi.org/10.6028/NIST.SP.800-57pt1r5), and [HashiCorp Vault Transit API](https://developer.hashicorp.com/vault/api-docs/secret/transit).
## Safeguarding database restore gate

Vault-backed safeguarding restores have a maintenance-only reconciliation command. It reapplies approved retention to due closed cases, inventories only Talyvane-managed Vault key prefixes, verifies every active case key exists, deletes orphaned case/preflight keys with the separate retention token, and records aggregate counts. It refuses to run outside maintenance mode and fails closed on missing active keys or Vault errors.

This is an application-level restore gate, not proof of backup expiry or complete recovery. A restored database cannot reveal case state that was absent from its snapshot; a restored Vault backup may also reintroduce deleted keys. Independent backup lifecycle evidence, Vault backup controls and human restore drills remain release gates.
## Operational feature gates and continuity-safe shutdown

A persisted `is_enabled` flag is not sufficient authorization to execute a feature. Runtime middleware and service/controller boundaries now use operational feature evaluation: the feature must be available, explicitly enabled, have operationally enabled dependencies, and have no unmet activation blockers. Unknown activation requirements fail closed. The admin feature catalog reports this effective state rather than only echoing the stored toggle.

Feature pause semantics distinguish new work from existing obligations. New booking creation, casting creation/submission, assignment creation/offers, representation/portfolio/licence initiation, new settlement/expense creation and membership assignment are blocked when their feature is paused. Existing booking cancellation, companion automatic-booking opt-out, applicant rights requests, consent withdrawal, licence revocation, unsafe-media rejection, casting close/cancel/decline, assignment completion/cancellation/dispute, and settlement review/payment/reversal/dispute remain available to close out or protect existing work. Admin registers remain readable while new creation controls are hidden.

Feature activation checks are enforced at route middleware and relevant application-service boundaries. Request-specific controls such as adult eligibility, current consent, companion opt-in, availability, verified email and deposit prerequisites remain enforced by their respective domain workflows; they are not treated as static feature activation toggles.

Authorization and deny-by-default design references: [OWASP Authorization Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Authorization_Cheat_Sheet.html) and [OWASP ASVS](https://owasp.org/www-project-application-security-verification-standard/). Feature toggles are not a substitute for role authorization, ownership checks, MFA or domain-specific policy enforcement.

## Canonical Super Admin identity at authorization boundaries

The `super_admin` role value alone is not sufficient to establish deployment-wide authority. Login, pending-MFA completion and role middleware compare the authenticated user ID with the singleton `platform_control.super_admin_user_id`. This prevents a direct SQL role change on an ordinary account from granting Super Admin access through the application. Regression tests exercise this threat by changing an ordinary user's role through the query builder and verifying that password login, pending MFA and authenticated privileged routes all fail closed.

This remains an application authorization boundary, not protection against a database administrator or attacker who can alter the canonical `platform_control` pointer, application code or host. Restrict production database credentials, monitor privileged SQL access and maintain independently controlled backups.


## Non-toggleable hard safeguards

The Super Admin feature registry controls optional business capabilities only. A dedicated hard-safeguard registry reserves age-assurance, consent-withdrawal, privacy takedown/data-rights, safeguarding case access/legal holds/retention/escalation, payment-ledger integrity and audit immutability keys. Reserved keys are never effectively enabled through the feature registry, cannot be dependencies of optional modules and cannot be changed by Super Admin feature controls. Accidentally inserting a reserved key into the feature table therefore fails closed.

Existing obligations must survive an optional feature pause. Approved safeguarding retention, configured safeguarding notification delivery and model image-licence expiry are not gated by optional feature switches. They are controlled by their own approved policy, mail transport and scheduler prerequisites. Regression tests exercise these continuity guarantees with all optional feature flags disabled. Consent withdrawal, media deletion, licence revocation/expiry, legal-hold operations, disputes and financial close-out remain protective transitions, not optional new-work capabilities.

See [ADR-0011: Hard safeguards are not feature flags](decisions/ADR-0011-hard-safeguards-not-feature-flags.md). This is an application-level control; database/host administrators and compromised deployment code remain outside the boundary and require independent privilege restrictions and monitoring.


## Deployment secret storage

Integration credentials are managed through a dedicated HTTPS HashiCorp Vault KV v2 mount. The application database stores only allowlisted secret identifiers, fixed Vault paths, status, Vault version, actor and timestamps. Secret values are never persisted in application tables or audit events and are never rendered back to the Super Admin. Set/rotate/clear require the canonical Super Admin, current password and fresh single-use TOTP. Writes use Vault compare-and-set; runtime resolution is metadata-gated and pinned to the exact recorded version. A failed rotation preserves the previous version; a failed clear blocks runtime resolution pending reconciliation.

The Vault bootstrap token remains environment-managed. The application uses a separate least-privilege token scoped to the configured KV v2 namespace; it must not be reused from safeguarding Transit. Permanent clear deletes the KV v2 metadata path and all versions. Provider credentials do not constitute provider implementation or legal approval. See [Deployment secret management](SECRETS-MANAGEMENT.md) and [ADR-0012](decisions/ADR-0012-super-admin-secret-management.md).

Secret input is excluded from Laravel's flashed old input on validation redirects. Per-secret cache locks serialize concurrent rotation and clear operations; multi-node production deployments must use a shared lock-capable cache backend. Secret operation audit events share a correlation ID across requested, completed and failed transitions. Vault audit devices must be enabled and monitored; application model hooks do not make the local database audit ledger tamper-proof against direct database administrators.


## Feature-gate enforcement at service and scheduled-work boundaries

The private-gallery entitlement resolver independently re-evaluates the effective `memberships.entitlements` feature state before querying grants. This is intentional defense in depth: the HTTP media controller also checks the feature, but authorization must remain fail-closed if another controller, job or integration invokes the resolver directly. Regression tests mutate the feature registry through the query builder to prove that direct database changes to `is_enabled` cannot bypass implementation-status and operational prerequisite checks.

Scheduled commands are classified by business effect rather than blanket feature-flagging. Existing-obligation and protective work remains runnable when optional features are paused: model image-licence expiry, approved model-applicant retention, approved safeguarding retention, safeguarding notification escalation, consent withdrawal, revocation, dispute handling and financial close-out. These are continuity and safety controls, not optional feature launches. Their own policy, transport and retention prerequisites remain mandatory. No currently registered scheduled command is allowed to create new optional bookings, castings, assignments or payments. Any future scheduled task that creates optional work must re-evaluate the corresponding operational feature gate at command execution and again inside its state-changing transaction.

Feature audit events reject Eloquent updates and deletes, and feature changes write audit events in the same database transaction as the state change. This is application-level append-only protection only: a database principal with direct UPDATE/DELETE privileges can bypass Eloquent hooks. Production deployments must restrict database credentials and privileged SQL access, export audit events to an independently controlled append-only destination, and complete the separate audit-integrity/restore verification work before claiming tamper-proof audit storage.


## Adult-eligibility verifier readiness

Activation of the model-agency adult-eligibility verification workflow now requires at least one active account with the dedicated `verifier` role, verified email and enrolled MFA. This prevents the feature registry from declaring the verification workflow operational when no independently authenticated verifier is available. The verifier role remains limited to the eligibility queue and must not gain general agency, finance or safeguarding access. Provisioning a verifier does not prove the quality or legality of the verification method; the deployment must still approve the policy, train the verifier and maintain auditable evidence minimization.

The platform continues to fail closed on unknown payment, communications and calendar activation prerequisites. Crypto settlement, card processing, concierge messaging and calendar synchronization must not be activated until their actual adapters, security controls, provider approvals, network validation and retention policies are implemented and independently tested.


## Safeguarding Vault deployment isolation and restore assurance

Vault-backed safeguarding now requires an explicit `SAFEGUARDING_KEY_NAMESPACE` of 8–32 lowercase alphanumeric characters. Set a unique immutable value per deployment and preserve it across application-key rotations. New per-case and preflight Transit key names include that namespace. The database enforces uniqueness of non-null key references. Restore reconciliation inventories all remote keys to verify active references, but only deletes orphan keys in the current deployment namespace; legacy unnamespaced active keys are verified but never automatically deleted. This prevents one deployment's reconciliation from garbage-collecting another deployment's keys when a Transit mount is shared. A dedicated Transit mount per deployment remains preferred, and Vault ACL deletion permissions should be narrowed to the exact namespace.

A restore drill now proves more than key-name existence: every active key must decrypt its stored ciphertext to a 32-byte data-encryption key before any orphan deletion begins. After deletion, the command re-lists the Vault inventory and checks that active keys remain and no namespaced orphan keys remain. The unique key-reference constraint prevents two safeguarding cases from sharing one external key reference. These checks do not prove that historical Vault backup snapshots have expired or that a provider-level restore cannot reintroduce deleted key material; independent backup-expiry evidence and a full provider restore drill remain release blockers.
