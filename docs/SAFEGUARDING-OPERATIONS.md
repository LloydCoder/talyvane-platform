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

## Operational requirements before production

1. Name and verify safeguarding specialists, deputies and out-of-hours escalation contacts.
2. Establish an emergency response path independent of this application. Do not represent the internal report form as an emergency hotline.
3. Approve jurisdiction-specific procedures for imminent harm, suspected trafficking, minor-safety concerns, non-consensual imagery, law-enforcement requests and mandatory reporting.
4. Define triage severity criteria, response SLAs, escalation, conflict-of-interest reassignment and case closure review.
5. Approve a retention schedule, legal-hold workflow, secure export procedure, incident response and backup-restore process.
6. Run independent tabletop exercises and verify access revocation, MFA recovery and evidence preservation.
7. Complete privacy impact and legal review before collecting real case information.

The application currently does not send case notifications, operate an emergency hotline, perform external statutory reporting, or guarantee a response time. The access and event logs are application-level controls and are not tamper-proof against direct database access.
