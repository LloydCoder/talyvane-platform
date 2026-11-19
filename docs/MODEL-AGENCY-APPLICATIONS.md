# Model Agency Application Operations

## Scope

This release implements a separate application and administrator-review workflow. It does not implement representation agreements, casting, assignments, image rights, model bookings or settlements. The model-agency workflow is separate from companionship accounts, profiles, enquiries and bookings.

## Default-safe activation

The feature registry entry `model-agency.applications` is available in code but disabled by default. Do not enable it until all gates below are met.

1. Obtain jurisdiction-specific legal/privacy review for the actual operator entity and intended applicant markets.
2. Create and publish a CMS page with the exact slug `model-applicant-privacy`. It must identify the controller, purposes and lawful bases, data categories, recipients/processors, international transfers, retention periods, applicant rights, complaint routes and contact details.
3. Create and publish a CMS page with the exact slug `model-adult-eligibility`. It must describe adult eligibility requirements and make clear that the application checkbox is a self-attestation, not age or identity verification.
4. Configure the following deployment environment values after the pages have been reviewed and published:

```dotenv
MODEL_AGENCY_PRIVACY_NOTICE_VERSION=reviewed-version
MODEL_AGENCY_PRIVACY_NOTICE_APPROVED=true
MODEL_AGENCY_ADULT_ELIGIBILITY_POLICY_VERSION=reviewed-version
MODEL_AGENCY_ADULT_ELIGIBILITY_POLICY_APPROVED=true
```

5. Reload the application's configuration cache according to the deployment runbook.
6. In the Super Admin feature registry, inspect the activation blockers and enable `model-agency.applications` only when the published policy pages, explicit approval configuration and CMS dependency all pass.

The application HTTP controllers repeat the same activation evaluation. A database-only flag change cannot bypass missing policy pages or approval configuration.

## Applicant data

The public form collects full name, email, optional telephone, country code, city, optional HTTPS portfolio URL, and experience summary. It records separate timestamps and version identifiers for adult-eligibility policy acceptance and applicant privacy notice acceptance, plus a timestamp for the applicant's 18+ self-attestation.

Applicant names, contact details, city, portfolio URL, experience summary and administrator review notes are encrypted through Laravel encrypted casts using the deployment's APP_KEY. The database columns are non-searchable ciphertext. The operator must back up and tightly control APP_KEY, include it in the protected deployment recovery plan, and use Laravel's supported previous-key rotation process when rotating encryption keys. It deliberately does not collect date of birth, identity documents, applicant photographs or payment information. Portfolio URLs are displayed as external links and are never fetched, crawled, previewed or resolved by the application.

## Review state machine

- `submitted` → `reviewing` or `declined`
- `reviewing` → `accepted` or `declined`
- `accepted` and `declined` are terminal in this release.

Every transition requires an administrator reason and is committed with an application-event record in one transaction. Event records reject Eloquent updates and deletes; privileged direct database access remains outside that application-level protection.

**Acceptance is not representation.** Do not publish a model profile, schedule an assignment, use portfolio imagery, or claim an agency relationship from an accepted application. Those require later implementation of independent age/identity assurance, a signed representation agreement and purpose-specific image usage licences.

## Outstanding launch requirements

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
