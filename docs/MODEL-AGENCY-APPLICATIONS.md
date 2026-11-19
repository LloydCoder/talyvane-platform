# Model Agency Application Operations

## Scope

This release implements a separate application and administrator-review workflow. It does not implement representation agreements, casting, assignments, image rights, model bookings or settlements. The model-agency workflow is separate from companionship accounts, profiles, enquiries and bookings.

## Default-safe activation

The feature registry entry `model-agency.applications` is available in code but disabled by default. It remains disabled by default. Activation requires verified applicant access/correction/withdrawal/erasure workflows, legal-hold controls, an approved retention schedule, configured SMTP and an explicitly approved scheduler.

1. Obtain jurisdiction-specific legal/privacy review for the actual operator entity and intended applicant markets.
2. Create and publish a CMS page with the exact slug `model-applicant-privacy`. It must identify the controller, purposes and lawful bases, data categories, recipients/processors, international transfers, retention periods, applicant rights, complaint routes and contact details. Include the exact configured privacy-notice version string in the published page body so stale pages cannot satisfy activation.
3. Create and publish a CMS page with the exact slug `model-adult-eligibility`. It must describe adult eligibility requirements and make clear that the application checkbox is a self-attestation, not age or identity verification. Include the exact configured adult-eligibility policy version string in the published page body.
4. Configure the following deployment environment values after the pages have been reviewed and published:

```dotenv
MODEL_AGENCY_PRIVACY_NOTICE_VERSION=reviewed-version
MODEL_AGENCY_PRIVACY_NOTICE_APPROVED=true
MODEL_AGENCY_ADULT_ELIGIBILITY_POLICY_VERSION=reviewed-version
MODEL_AGENCY_ADULT_ELIGIBILITY_POLICY_APPROVED=true
```

5. Reload the application's configuration cache according to the deployment runbook.
6. Configure the cPanel scheduler (or equivalent) to invoke php artisan schedule:run every minute. Set MODEL_AGENCY_RETENTION_SCHEDULE_APPROVED=true only after verifying that cron executes successfully.
7. Configure positive, counsel-approved MODEL_AGENCY_APPLICATION_RETENTION_DAYS and MODEL_AGENCY_ACCEPTED_RETENTION_DAYS values, then clear Laravel's configuration cache.
8. In the Super Admin feature registry, inspect all activation blockers and enable model-agency.applications only when the workflow, published legal pages, approval flags, SMTP, retention periods and scheduler gate pass.

The application HTTP controllers and administrator review controller repeat the same activation evaluation. A database-only flag change cannot bypass missing policy pages, approval configuration, SMTP, rights-request schema or retention/scheduler settings.

## Applicant data

The public form collects full name, email, optional telephone, country code, city, optional HTTPS portfolio URL, and experience summary. It records separate timestamps and version identifiers for adult-eligibility policy acceptance and applicant privacy notice acceptance, plus a timestamp for the applicant's 18+ self-attestation.

Applicant names, contact details, city, portfolio URL, experience summary and administrator review notes are encrypted through Laravel encrypted casts using the deployment's APP_KEY. The database columns are non-searchable ciphertext. The operator must back up and tightly control APP_KEY, include it in the protected deployment recovery plan, and use Laravel's supported previous-key rotation process when rotating encryption keys. It deliberately does not collect date of birth, identity documents, applicant photographs or payment information. Portfolio URLs must use standard HTTPS without embedded credentials or custom ports. They are displayed as external links and are never fetched, crawled, previewed or resolved by the application.

## Applicant rights and retention

Applicants open /models/application-support, provide the application reference and recorded email address, and choose access, correction, withdrawal or erasure. The response is deliberately generic whether or not the reference/email pair matches. A matching address receives an eight-digit code stored only as a password hash; it expires after 10 minutes and locks after five failed attempts. Successful verification creates a 30-minute session scoped to one rights request. The private applicant data page sends no-store/no-cache headers and a no-referrer policy. Email cannot be changed through this flow.

- Access displays the applicant's stored application data in the verified session.
- Correction updates only name, phone, city, HTTPS portfolio URL and experience summary; email changes require a separately verified process.
- Withdrawal transitions the application to withdrawn.
- Erasure anonymizes contact/profile fields, clears applicant consent timestamps and free-text review/event reasons, replaces the reference, and retains a minimal non-identifying audit record.
- A legal hold can be placed/released only by Super Admin or Owner with a reason. A hold blocks erasure and automated retention.
- talyvane:model-agency:apply-retention anonymizes records after the configured retention period. Accepted applications use the accepted retention period; other non-erased statuses use the general application period. The scheduled task runs daily at 02:30 and is protected against overlapping execution.

Retention values are deployment/legal decisions, not product defaults. The scheduler approval flag is an operator attestation, not automatic proof that the host cron is functioning. Monitor the command and host cron. Erasure is anonymization of identifying fields, not deletion of the minimal audit row.

## Review state machine

- `submitted` → `reviewing` or `declined`
- `reviewing` → `accepted` or `declined`
- `accepted` and `declined` are terminal in this release.

Every transition requires an administrator reason and is committed with an application-event record in one transaction. Event records reject Eloquent updates and deletes; privileged direct database access remains outside that application-level protection.

**Acceptance is not representation.** Do not publish a model profile, schedule an assignment, use portfolio imagery, or claim an agency relationship from an accepted application. Those require later implementation of independent age/identity assurance, a signed representation agreement and purpose-specific image usage licences.

## Outstanding launch requirements

- Independently review retention periods and test the production scheduler before activation.
- Independent age/identity assurance appropriate to the legal markets and intended work.
- Applicant access, correction, withdrawal and deletion-request workflows.
- A legally approved retention schedule and automated/manual retention execution.
- Secure document and image submission only if later required, with private storage, scanning, consent and rights provenance.
- Representation agreements, casting, assignment approvals, usage rights and settlements.
- Production legal review, privacy review and safeguarding sign-off.

## Engineering references

- [ICO — Data minimisation](https://ico.org.uk/for-organisations/uk-gdpr-guidance-and-resources/data-protection-principles/a-guide-to-the-data-protection-principles/data-minimisation/)
- [NIST SP 800-63A-4 — Identity proofing](https://pages.nist.gov/800-63-4/sp800-63a/proofing/)
- [OWASP — SSRF Prevention Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Server_Side_Request_Forgery_Prevention_Cheat_Sheet.html)

These are engineering references, not legal advice, certification or proof that the application performs age verification.
