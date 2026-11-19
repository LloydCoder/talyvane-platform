# Model Talent Settlement Ledger

## Scope

The settlement domain records the financial close-out of a completed model assignment. It is separate from companionship booking deposits and from payment processor configuration. It is an administrator-only ledger and is disabled by default until a versioned, jurisdiction-reviewed settlement policy is explicitly approved.

## Eligibility and lifecycle

- An assignment must be `completed`, have agreed compensation and an enabled ISO currency.
- Exactly one settlement may exist per assignment.
- Expense claims can be submitted while an assignment is `in_progress` or `completed`, before a settlement draft exists.
- Expense claims require a private evidence-key reference, the assignment currency and an expense cap. Pending and approved claims count against the cap; rejected claims do not.
- An expense claim can be reviewed once as approved or rejected. Pending claims block settlement creation.
- Settlement calculation snapshots gross compensation, the assignment's commission percentage, approved expenses, currency code and minor-unit precision.
- A settlement follows `draft → approved → paid`; approved or paid settlements can be disputed, and a paid settlement can be reversed with private evidence.
- The settlement and expense models prevent Eloquent mutation of financial terms after submission. Settlement audit events are append-only at the Eloquent model layer.

## Calculation

All amounts are represented as integer minor units using the configured ISO currency precision (0–3 decimal places). Floating-point arithmetic and implicit rounding are not used.

`commission = round_half_up(gross × commission_percent / 100, currency_minor_unit)`

`model_net = gross − commission + approved_expenses`

The commission percentage is snapshotted on the assignment from its executed representation agreement. Commission is rounded half-up to the currency's minor unit. Amounts with excess precision are rejected. Calculation range overflow is rejected rather than silently truncated.

## External payment boundary

The `paid` state means an administrator recorded an external payment attestation and private evidence reference. Supported labels include bank transfer, cash and other external payment. External crypto settlement is not implemented because network, token contract, confirmation, reorganization and reconciliation details are not captured. The application does not initiate, custody, route, confirm or independently verify funds, bank transfers or blockchain transactions. A transaction hash or uploaded screenshot is not independently validated.

Reversal records an external reversal reason and private evidence reference; it does not initiate a refund. Disputes require human reconciliation. Tax, withholding, invoicing, chargebacks, refunds, FX conversion, multi-currency split payments, partial payments and statutory reporting are not implemented.

## Privacy and security

Expense descriptions, evidence references, review notes, settlement calculation snapshots, external payment references and dispute/reversal evidence are encrypted at rest. Only authorized agency administrators can access the register; routes require the existing admin authentication, MFA and feature gates. The feature depends on the assignment domain and an explicitly approved settlement policy.

Application-layer immutability does not protect against a database administrator or direct SQL. Backups, database access controls, operational separation of duties and independent reconciliation remain necessary.
