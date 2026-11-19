# Compliance and Safeguarding Release Gates

This checklist is not legal advice. Obtain current advice from qualified counsel and written approvals from relevant hosting and payment providers.

## Jurisdiction mapping
Separately identify the contracting entity and incorporation country; operator/staff locations; where services are advertised and supplied; client/provider locations; where data is collected/accessed/stored; and where payment accounts or wallet controllers are located. A target market is not proof that the model is lawful or authorised.

## Current implementation caveat
The repository currently contains draft privacy and terms pages, not approved legal documents. The current profile publication workflow records a human reviewer's approved-provider reference and consent version; it does not itself perform age assurance or identity verification. The companionship deposit workflow records a human administrator's independent verification attestation; it does not itself query a blockchain or payment processor. The model settlement ledger records a decimal-safe financial calculation and human-approved external payment evidence; it does not initiate, custody or verify bank or blockchain transfers. Do not market either workflow as automated verification or payment processing.

## Enforced country-market gate

The application now treats a market as operational only when it is explicitly enabled, has an `approved` compliance decision, and has a reviewer ID, review timestamp, evidence reference and reviewer summary. New destinations automatically register their country in `pending` and disabled state. Existing destination countries are backfilled as pending during migration. Public destination discovery, companion publication, profile-media approval and booking creation require the operational-market predicate; booking checks it again inside the booking transaction. A pending, rejected, disabled or unregistered market is not serviceable.

The admin review form records an evidence reference and reviewer findings and requires an explicit confirmation that current written local-counsel advice covers the exact model and market, with hosting and payment-provider approvals separately documented. This is an auditable internal control, not a legal opinion, external verification, or proof that the referenced evidence is genuine. A human with authority must inspect and retain the underlying evidence securely before recording approval. Never approve a market based only on this application's form.

## Pre-production evidence
- Written legal opinion for the exact business model in each target market.
- Written hosting-provider approval for the actual content and business model.
- Written payment underwriting approval for each product, purpose and jurisdiction.
- Documented adult-only eligibility and independent age assurance.
- Identity/profile ownership verification proportionate to risk.
- Granular consent for each media item and public distribution.
- Safeguarding policy, complaint process, incident escalation and appeal.
- Privacy notice, terms, cancellation/refund terms and retention schedule.
- Data-controller/processor assessment and cross-border transfer review.
- Business registration, tax and local operating requirements assessment.
- Security test, backup restore test and incident-response readiness.

## Safeguarding
No minors, coercion, trafficking, exploitation or non-consensual material. No publication without verified adult status, identity/profile ownership checks and explicit media consent. Provide pause, removal and future-publication consent withdrawal. Never publish home addresses, private travel plans or real-time location. Incidents require trained human review, documented reasons, restricted access and appeal/correction. No unrestricted cross-customer blacklist. Preserve evidence only when lawful and necessary, with retention controls.

## Payments
Obtain written card-provider approval; adult-oriented business categories may be restricted. Assess money transmission, virtual asset service, AML, sanctions, consumer protection, tax and reporting obligations for the actual arrangement. Direct-to-wallet does not automatically remove regulatory obligations. Keep crypto disabled until chain/network, token, monitoring, confirmations and legal review are complete. Do not store private keys or represent the platform as custodian.

## Market review tracker
| Market | Required review areas | Status |
|---|---|---|
| Mexico | Exact service model legality, local permits, consumer rules, privacy/transfers, tax and payments/virtual assets | Not reviewed |
| United Kingdom | Criminal and advertising restrictions, online safety duties where applicable, UK data protection, age assurance, payments and tax | Not reviewed |
| Philippines | Anti-trafficking/exploitation law, local business rules, privacy, online platform obligations, payments and tax | Not reviewed |

Any unresolved issue concerning adult status, consent, exploitation risk, provider approval, data protection or legal authority is a production no-go for that market.
