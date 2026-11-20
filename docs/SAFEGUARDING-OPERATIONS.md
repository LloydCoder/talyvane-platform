# Safeguarding operations

## Purpose and boundary

The safeguarding register is an internal, restricted case-management aid for concerns involving consent, coercion or exploitation, trafficking, minor safety, non-consensual content, threats or harassment, and privacy/data risks. It does not determine legal status, replace trained human judgment, provide emergency response, or establish compliance in any jurisdiction.

## Access model

- Agency Super Admin, Owner and Admin accounts with MFA may submit a report and assign it to an active safeguarding specialist with MFA already enrolled.
- The reporter receives only a submission acknowledgement and opaque case reference. They cannot read the case contents after submission.
- A safeguarding specialist can list and access only cases assigned to that specialist account. The case identifier is not an authorization token.
- Safeguarding specialists cannot access general agency, client, booking, model-agency, adult-eligibility verifier, finance or deployment-control workspaces.
- Case detail views create access-audit events. Case lifecycle changes create append-only case events.

## Data handling

Case summaries, details, subject references, event notes and legal-hold reasons are encrypted with Laravel's application encryption key. Protect the key separately from database backups. Do not include identity-document numbers, full dates of birth, passwords, payment secrets, unnecessary contact information or raw evidence files in case narratives.

The register stores opaque related-record references only. It does not accept evidence uploads. Preserve external evidence using an independently approved secure evidence repository and reference its opaque record identifier only after the evidence-handling process has been reviewed.

## Workflow

Supported case categories:
- Consent concern
- Coercion or exploitation
- Trafficking concern
- Minor-safety concern
- Non-consensual content
- Threat or harassment
- Privacy or data risk
- Other

Severity values are low, medium, high and critical. The specialist workflow is:

`open → triaged → investigating / action_required → resolved → closed`

Transitions are server-side validated. A closed case is terminal in this release. Every transition requires a reason note and creates an immutable application event.

## Legal-hold governance

Super Admin and Owner accounts have a separate metadata-only governance register. They can place a hold with a reason and can release it only when they are not the actor who placed it. The register does not expose confidential case narratives. Placement and release create append-only case events and access events.

This application-level hold applies only to the safeguarding case record. It does not preserve evidence in external systems, provider logs, email, messaging, payment systems or backups. Those systems require their own preservation procedures.

Automated retention and cryptographic erasure are not yet implemented. Do not configure external deletion or promise automatic expiry based on the current fields. A future retention phase must implement per-case cryptographic erasure, approved retention periods, legal-hold exclusion and restore/backup controls before automated purge can be enabled.

## Retention and per-case content keys

Each case receives a random 256-bit content key. The key is wrapped with the Laravel application key; case narratives, related-record references, legal-hold reasons and event notes are encrypted with that per-case key. The migration re-encrypts existing records into this scheme and clears the legacy encrypted content columns. Keep `APP_KEY` stable during migration and take a separately encrypted backup before deploying it.

Retention is disabled by default. The operator must explicitly configure and approve all of the following in the deployment environment:

- `SAFEGUARDING_CASE_RETENTION_SCHEDULE_APPROVED=true`
- `SAFEGUARDING_CASE_RETENTION_DAYS` as a positive, legally reviewed day count measured from case closure
- `SAFEGUARDING_CASE_RETENTION_POLICY_VERSION` as the approved policy identifier
- `SAFEGUARDING_CASE_BACKUP_POLICY_APPROVED=true`
- `SAFEGUARDING_CASE_BACKUP_EXPIRY_DAYS` as a positive, separately approved backup expiry period

When all gates are satisfied, the scheduler runs `talyvane:safeguarding:apply-retention` daily at 02:45. The command considers only closed cases past the retention cutoff, rechecks each candidate under a database row lock, and excludes every case with an active legal hold. It removes the per-case key from the primary database and preserves case/audit metadata. It does not delete the case row or audit rows.

**Backup limitation:** the wrapped key and ciphertext may remain in database backups created before the live key was removed. Primary-database key removal is not immediate global erasure. Backup copies must expire under the separately approved backup schedule, and restores must not reintroduce erased keys into production. Do not promise complete cryptographic erasure until the backup expiry or an independently managed external key-vault destruction process has been verified. Migration rollback is blocked after retention erasure.

## Notification and escalation outbox

Case creation writes notification intents to a transactional outbox. Email delivery is disabled by default and requires `SAFEGUARDING_NOTIFICATIONS_ENABLED=true`, a real configured mail transport (not `log` or `array`), and deployment verification. The cPanel scheduler dispatches pending notices every five minutes.

Assignment notices go to the assigned active safeguarding specialist. Critical cases and minor-safety concerns also enqueue notices to the configured `SAFEGUARDING_EMERGENCY_CONTACT_EMAILS` list. If no valid emergency contacts are configured, the case receives an explicit `case.escalation_contact_unconfigured` event and access-audit record; the system does not pretend escalation occurred.

Assignment email contains only the opaque case reference, category, severity and an authenticated internal link. External emergency-contact escalation email contains only the reference, category and severity, with no internal link. Neither message includes summary, narrative, applicant contact details, identity-document data or evidence. A specialist must sign in with their own account and complete MFA. External emergency contacts receive no case-content access from the email.

The outbox encrypts stored recipient email addresses, uses a keyed recipient fingerprint for deduplication, clears an emergency contact address after successful delivery, retries transient failures with bounded exponential backoff, and marks a stuck processing claim eligible for recovery after 15 minutes. It records only the exception class as a failure code, not raw SMTP error text. Delivery is at-least-once: a process crash after the mail provider accepts a message but before the outbox is acknowledged can produce a duplicate. Delivery is not guaranteed; operators must monitor failed notices and maintain an independent emergency response path.

The current implementation provides email only. SMS, Telegram, provider webhooks, delivery receipts, on-call rotations and independent response drills remain separate work.

## Operational requirements before production

1. Name and verify safeguarding specialists, deputies and out-of-hours escalation contacts.
2. Establish an emergency response path independent of this application. Do not represent the internal report form as an emergency hotline.
3. Approve jurisdiction-specific procedures for imminent harm, suspected trafficking, minor-safety concerns, non-consensual imagery, law-enforcement requests and mandatory reporting.
4. Define triage severity criteria, response SLAs, escalation, conflict-of-interest reassignment and case closure review.
5. Approve a retention schedule, legal-hold workflow, secure export procedure, incident response and backup-restore process.
6. Run independent tabletop exercises and verify access revocation, MFA recovery and evidence preservation.
7. Complete privacy impact and legal review before collecting real case information.

The application currently does not send case notifications, operate an emergency hotline, perform external statutory reporting, or guarantee a response time. The access and event logs are application-level controls and are not tamper-proof against direct database access.

## External per-case key custody (HashiCorp Vault Transit)

The default `SAFEGUARDING_CASE_KEY_PROVIDER=local` preserves the existing application-key-wrapped per-case key model. It does not provide independent cryptographic erasure from old database backups. A deployment may select `vault` only after it has provisioned and independently tested a dedicated HTTPS HashiCorp Vault Transit mount.

For Vault-backed cases:

- Each case receives a randomly named Transit key. The key is non-exportable and plaintext key backup is disabled.
- The application token is limited to creating per-case keys and encrypting/decrypting their data-encryption keys. It must not have Transit key deletion permissions.
- `SAFEGUARDING_VAULT_RETENTION_TOKEN` must be a distinct credential, restricted to enabling deletion and deleting keys in the dedicated Transit mount. The application explicitly refuses to use the same token for both duties.
- The database stores only the Vault ciphertext and opaque key reference. The Vault address, application token and retention token remain in deployment environment/secret storage and are never written to application tables.
- Retention first requests irreversible deletion of the per-case Transit key using the separate retention credential, then clears the local wrapped-key reference and records the retention event. A retry treats a missing remote key as already destroyed. Any other Vault error fails closed and prevents the local retention tombstone from being written.
- Keep Vault TLS certificate verification enabled. Do not permit HTTP endpoints or redirects. Use a least-privilege Vault policy, short-lived/rotatable tokens where supported, audit device, restricted network access and independent Vault backups.

Required environment:
- `SAFEGUARDING_CASE_KEY_PROVIDER=vault`
- `SAFEGUARDING_VAULT_ADDRESS=https://...`
- `SAFEGUARDING_VAULT_MOUNT=transit`
- `SAFEGUARDING_VAULT_TOKEN` (application-only key creation/encrypt/decrypt permissions)
- `SAFEGUARDING_VAULT_RETENTION_TOKEN` (separate key-config/delete permissions)
- `SAFEGUARDING_VAULT_TIMEOUT=5`

Before enabling Vault mode, run `php artisan talyvane:safeguarding:vault-check` from the private application directory. It creates a temporary non-exportable key, verifies encrypt/decrypt round trip, and destroys the temporary key with the separate retention credential. Do not enable Vault mode until the operator has also verified the Transit mount, token policies, irreversible key deletion, Vault audit logging, token rotation, backup expiry and restore behavior. A Vault backup that contains a deleted Transit key can reintroduce decryption capability if restored; Vault backup retention and disaster recovery are therefore part of the same privacy control. Do not claim end-to-end cryptographic erasure until independent Vault backup/restore controls and a restore drill have been evidenced.

Vault Transit API reference: https://developer.hashicorp.com/vault/api-docs/secret/transit. Key lifecycle and backup/restore controls should follow the principles in [OWASP Secrets Management](https://cheatsheetseries.owasp.org/cheatsheets/Secrets_Management_Cheat_Sheet.html) and [NIST SP 800-57 Part 1 Rev. 5](https://doi.org/10.6028/NIST.SP.800-57pt1r5). These engineering controls do not replace jurisdiction-specific retention, safeguarding or privacy review.