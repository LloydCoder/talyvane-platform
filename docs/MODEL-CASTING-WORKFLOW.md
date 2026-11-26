# Private Model Casting Workflow

## Scope

The casting module is an internal agency register for creating private casting briefs, submitting eligible models, recording manual shortlists/selections/declines, closing/cancelling calls and invalidating submissions when their image licence ends.

It is not a public casting board, client portal, applicant portal or image CDN. On the native PHP branch, a selected submission can progress into a separate administrator-only assignment lifecycle and finance settlement ledger; these are distinct workflows and do not turn the casting register into client distribution, booking, payment processing or media delivery.

## Activation

Configure an exact reviewed casting policy only after jurisdiction-specific legal and safeguarding review:

```dotenv
MODEL_AGENCY_CASTING_POLICY_VERSION=reviewed-version
MODEL_AGENCY_CASTING_POLICY_APPROVED=true
```

Calls require an enabled, compliance-approved country market and current casting policy. Submission requires:
- Accepted model application and completed adult-eligibility verification.
- No active legal hold.
- Approved private portfolio asset with non-withdrawn, current consent policy.
- Executed representation agreement for the casting territory, under the current approved agreement policy.
- Active purpose-specific image licence with purpose casting_distribution and channel casting_platform.
- Licence territory matching the call and licence dates covering the entire shoot window.
- Current casting, portfolio-consent and image-licence policy approvals.

## Workflow

1. An administrator creates a private casting brief with client/commissioning entity, project purpose, requirements, territory, submission window, shoot dates, compensation metadata and a submission cap.
2. The brief is published internally. It is not publicly discoverable or sent to clients.
3. An administrator submits a model on the model's behalf using an approved portfolio asset and active casting-distribution licence. One submission per model per call is allowed.
4. Authorized administrators manually shortlist, select or decline. No AI ranking or autonomous selection occurs.
5. Closing a call stops new submissions but preserves existing decision workflows. Cancelling a call invalidates live submissions.
6. Licence revocation/expiry, portfolio consent withdrawal or representation termination invalidates all linked live casting submissions.

A selected submission is not an assignment, booking or guarantee of work. A later assignment workflow must separately define scope, dates, model acceptance, compensation, expenses, cancellation, usage rights and settlement.

## Audit and privacy

Client name/reference, brief description, requirements, decision notes, cancellation reasons and invalidation reasons are encrypted at rest. Call and submission actions append to a separate append-only casting event ledger. Applicant identity and asset links are referenced by IDs; image bytes are not included in casting records or delivered externally.

Only authenticated, MFA-gated administrator routes can create or manage calls and submissions. There are no public casting routes.

## Compensation boundary

Casting compensation is recorded as agreed metadata in an enabled currency. It is not an invoice, payment, settlement, commission, tax calculation or proof of payment.

## Known limitations

- No client account or client-facing casting review.
- No model self-service portal or direct model submission; administrators record verified external model approval.
- No automated eligibility ranking or matching.
- Assignment lifecycle and internal settlement ledger exist as separate native PHP domains; client-facing distribution, model self-service, assignment booking/rescheduling, call sheets, shoot delivery and automated payment processing remain open.
- No public image transfer, client downloads, CDN or campaign publication.
- No external campaign compliance monitoring.

The casting implementation is part of the native PHP feature branch. It must not be assumed to be merged into or deployed with the Laravel reference application; see [native PHP port status](NATIVE-PHP-PORT-STATUS.md) and [the parity tracker](../implementations/php-native/PORTING-TRACKER.md).
- Qualified legal review is required for the actual call brief, compensation, image use and market before activation.
