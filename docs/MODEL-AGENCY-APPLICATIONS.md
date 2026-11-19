# Model Agency Application Operations

## Scope

The platform implements a separate application intake, administrator-review workflow, applicant privacy-rights workflow, adult-eligibility gate, representation-agreement register and private portfolio media/consent workflow. The model-agency workflow remains separate from companionship accounts, profiles, enquiries and bookings. The register records versioned draft terms, offers, external execution evidence and termination; it does not provide e-signature, grant image rights, publish models, create assignments or process settlements.

## Default-safe activation

The feature registry entry `model-agency.applications` is available in code but disabled by default. Activation requires verified applicant access/correction/withdrawal/erasure workflows, legal-hold controls, an approved retention schedule, configured SMTP and an explicitly approved scheduler.

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

The public intake HTTP controller repeats the activation evaluation. Applicant privacy-support routes and administrator record/legal-hold routes deliberately remain available after intake is disabled, so existing applicants retain access to their rights and the operator can continue governance. A database-only flag change cannot bypass missing policy pages, approval configuration, SMTP, rights-request schema or retention/scheduler settings.

## Applicant data

The public form collects full name, email, optional telephone, country code, city, optional HTTPS portfolio URL, and experience summary. It records separate timestamps and version identifiers for adult-eligibility policy acceptance and applicant privacy notice acceptance, plus a timestamp for the applicant's 18+ self-attestation.

Applicant names, contact details, city, portfolio URL, experience summary and administrator review notes are encrypted through Laravel encrypted casts using the deployment's APP_KEY. The database columns are non-searchable ciphertext. The operator must back up and tightly control APP_KEY, include it in the protected deployment recovery plan, and use Laravel's supported previous-key rotation process when rotating encryption keys. It deliberately does not collect date of birth, identity documents, applicant photographs or payment information. Portfolio URLs must use standard HTTPS without embedded credentials or custom ports. They are displayed as external links and are never fetched, crawled, previewed or resolved by the application.

## Applicant rights and retention

The application receipt displays the generated application reference once in a private, no-store response; applicants must keep it. Applicants open /models/application-support, provide that reference and the recorded email address, and choose access, correction, withdrawal or erasure. The response is deliberately generic whether or not the reference/email pair matches. A matching address receives an eight-digit code stored only as a password hash; it expires after 10 minutes and locks after five failed attempts. Successful verification creates a 30-minute session scoped to one rights request. A new code supersedes prior pending/verified requests, and applicants can explicitly close the private session. The private applicant data page sends no-store/no-cache headers and a no-referrer policy. Email cannot be changed through this flow.

- Access currently displays the submitted application fields and current status in the verified session. It is deliberately labelled as a self-service view, not a complete subject-access response for all records the agency may hold; broader access requests must use the controller contact in the published privacy notice.
- Correction updates only name, phone, city, HTTPS portfolio URL and experience summary; email changes require a separately verified process.
- Withdrawal transitions the application to withdrawn.
- Erasure anonymizes contact/profile fields, clears applicant consent timestamps and free-text review/event reasons, replaces the reference, and retains a minimal non-identifying audit record.
- A legal hold can be placed/released only by Super Admin or Owner with a reason. A hold blocks erasure and automated retention.
- talyvane:model-agency:apply-retention anonymizes records after the configured retention period. Accepted applications use the accepted retention period; other non-erased statuses use the general application period. The clock starts at the latest review/decision timestamp when present, otherwise at submission. The initial candidate scan is not authoritative: each record's current status, decision timestamp and legal-hold state are rechecked under a database row lock in the same transaction as anonymization, preventing a concurrent acceptance, status change or legal hold from being bypassed. The scheduled task runs daily at 02:30 and is protected against overlapping execution.

Retention values are deployment/legal decisions, not product defaults. The scheduler approval flag is an operator attestation, not automatic proof that the host cron is functioning. The schedule is registered only while the approval flag and positive retention periods are configured, and the command independently refuses to run if approval is withdrawn. Monitor the command and host cron. Erasure is anonymization of identifying fields, not deletion of the minimal audit row. The verified data page and administrator application list use no-store/no-cache and no-referrer headers. Rights support and administrator governance remain available even when new intake is disabled.

## Review state machine

- `submitted` → `reviewing` or `declined`
- `reviewing` → `accepted` or `declined`
- `accepted` and `declined` are terminal in this release.

Every transition requires an administrator reason and is committed with an application-event record in one transaction. Event records reject Eloquent updates and deletes; privileged direct database access remains outside that application-level protection.

**Acceptance is not representation.** Do not publish a model profile, schedule an assignment, use portfolio imagery, or claim an agency relationship from an accepted application. See [Model Representation Agreement Register](MODEL-REPRESENTATION-AGREEMENTS.md) for the separate agreement lifecycle and [Model Portfolio Media](MODEL-PORTFOLIO-MEDIA.md) for private media intake, review and consent withdrawal. The platform only records that an externally executed agreement was asserted by an administrator; it does not verify signatures. Approved portfolio assets remain private and are not licensed for campaign, casting or public use.

## Outstanding launch requirements

- Independently review retention periods and test the production scheduler before activation.
- Implement full operator-led subject-access request fulfilment: reasonable searches across agency systems, supplementary information, third-party review/redaction, response deadlines, secure downloadable copy and a documented decision/audit trail. The current self-service view is intentionally limited to the application record.
- Independent age/identity assurance appropriate to the legal markets and intended work.
- A separately verified applicant email-change and account-recovery process; the current rights workflow intentionally cannot change the registered email.
- Legal review and sign-off of the configured retention periods and successful production cron execution.
- Secure document and image submission only if later required, with private storage, scanning, consent and rights provenance.
- Casting, assignment approvals, purpose-bound image usage licences, model bookings and settlements. Private executed-document upload/download and access audit are also outstanding.
- Production legal review, privacy review and safeguarding sign-off.

## Engineering references

- [ICO — Storage limitation](https://ico.org.uk/for-organisations/uk-gdpr-guidance-and-resources/data-protection-principles/a-guide-to-the-data-protection-principles/storage-limitation/)
- [ICO — Recruitment and selection](https://ico.org.uk/for-organisations/uk-gdpr-guidance-and-resources/employment/recruitment-and-selection/)
- [ICO — A guide to subject access](https://ico.org.uk/for-organisations/uk-gdpr-guidance-and-resources/subject-access-requests/a-guide-to-subject-access/)
- [ICO — Data minimisation](https://ico.org.uk/for-organisations/uk-gdpr-guidance-and-resources/data-protection-principles/a-guide-to-the-data-protection-principles/data-minimisation/)
- [NIST SP 800-63A-4 — Identity proofing](https://pages.nist.gov/800-63-4/sp800-63a/proofing/)
- [OWASP — SSRF Prevention Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Server_Side_Request_Forgery_Prevention_Cheat_Sheet.html)

These are engineering references, not legal advice, certification or proof that the application performs age verification.


## Adult-eligibility verification gate

Applicant self-attestation is intake consent only; it is not proof of age or identity. An application may enter review, but it cannot be accepted until an authorized Owner or Super Admin records a manual verification against the currently approved, version-matched adult-eligibility policy.

The verifier must inspect an original identity document using a method permitted by the approved policy and confirm (1) identity matches the applicant, (2) the document is valid and acceptable, and (3) the applicant meets the applicable age threshold. The platform stores only the verifier, timestamp and method label; it does not collect or retain identity-document images, document numbers, document scans or dates of birth in this workflow. The accepted policy version is already retained on the application. The verification event is audited. The workflow is manual; no AI or unintegrated provider is treated as an age-verification authority.

If the applicant accepted a stale policy version, obtain fresh consent before verification. A later erasure request clears verification metadata from the applicant record while retaining only the minimal non-identifying lifecycle event. Legal review must determine the applicable age threshold, acceptable identity documents, inspection standard and recordkeeping obligations for each enabled market.
