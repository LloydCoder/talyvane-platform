# Model Assignment Lifecycle

## Scope

The assignment register converts a human-selected private casting submission into a separately governed model work assignment. It is an administrator-only operational record, not a public client portal or self-service model portal.

A casting selection alone does not create an assignment. Assignment creation requires:
- A selected casting submission.
- An accepted model application with current adult-eligibility verification and no legal hold.
- An approved private portfolio asset with current, non-withdrawn consent.
- An active casting-distribution licence covering the casting call.
- An executed representation agreement that remains in force for the assignment dates.
- A currently approved assignment policy and an operationally approved territory.
- No overlapping active assignment for the same model.

## Lifecycle

`draft → offered → accepted → confirmed → in_progress → completed`

Alternate terminal or exception states: `declined`, `cancelled`, `disputed`.

1. An administrator creates a draft only from a selected casting submission. The client, project purpose, brief, location city, shoot window, deliverables, compensation metadata and expense cap are snapshotted.
2. The representation agreement's commission percentage is copied into the assignment as an immutable commercial snapshot. The separate native PHP settlement domain now accepts private expense claims, enforces the assignment cap, calculates commission and model net in integer minor units, snapshots the calculation and requires maker-checker approval. It records an external payment attestation; it does not move or independently verify funds.
3. An administrator records that an offer was issued externally. The platform does not send an offer or message the model.
4. An administrator records the private storage references for the externally executed assignment instrument and model acceptance evidence. The platform does not sign, validate or independently authenticate those documents.
5. A second confirmation rechecks the selected submission, adult-eligibility status, legal hold, asset consent, casting licence, representation agreement, assignment policy, market approval and schedule conflict.
6. Administrators record start and completion. Decline, cancellation and dispute require reasons and are appended to the event history.

The same model cannot hold overlapping active assignments in offered, accepted, confirmed or in-progress states. The application row is locked during schedule checks and writes to serialize competing assignments.

## Privacy and evidence

Client identity, brief, city, deliverables, external evidence references and event reasons are encrypted at rest. Assignment and event records reference application, submission and agreement IDs; they do not duplicate identity documents or image bytes. Evidence inputs must be private storage-key references. This release does not upload, download, preview or verify external documents.

The event model rejects Eloquent updates and deletes. This is application-layer append-only protection, not cryptographic immutability against a database administrator or direct SQL.

## Financial boundary

Compensation and expense-cap metadata are optional and must use an enabled ISO currency when supplied. The commission percentage is snapshotted from the executed representation agreement. This is not an invoice, tax calculation, payable ledger, payment instruction, refund, commission calculation or settlement. Those require a separate finance/settlement domain with independent approval and reconciliation.

## Usage-rights boundary

Assignment acceptance does not grant image or campaign usage rights. Public, client, advertising, social, e-commerce or other image distribution remains separately governed by an active purpose-specific image licence. Revocation or expiry of image usage rights must block the affected distribution path; it does not silently rewrite assignment terms or prove that the underlying work contract was cancelled.

## Known limitations

This assignment and settlement parity is implemented on the native PHP feature branch as a separate administrator-only workflow. It is not evidence that the Laravel reference or a production deployment has received the same change; see [native PHP port status](NATIVE-PHP-PORT-STATUS.md).

- No client portal, client review or client acceptance.
- No model self-service portal or direct model acceptance; an administrator records externally evidenced acceptance.
- No automated emails, messaging or calendar integration.
- No e-signature, identity-provider verification or legal validation of external documents.
- No assignment booking engine, rescheduling, attendance/call-sheet system or time tracking.
- A native PHP internal settlement ledger exists for expense review, commission/net calculation, approval, external payment attestation, reversal and dispute evidence. It does not execute reimbursement, provider payments, refunds or bank reconciliation.
- Tax, withholding, invoice generation, partial settlements and statutory reporting remain unimplemented.
- No public image delivery, client downloads or campaign publication.
- Jurisdiction-specific legal, safeguarding, contract and payment review remains a separate release gate.
