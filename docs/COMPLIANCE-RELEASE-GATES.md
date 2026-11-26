# Compliance and Safeguarding Release Gates

Repository-level Laravel or native PHP implementation evidence is not legal/provider approval. The native PHP candidate's implementation and release status is tracked separately in [NATIVE-PHP-PORT-STATUS.md](NATIVE-PHP-PORT-STATUS.md); all deployment-specific legal, safeguarding, hosting and payment gates apply independently.

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
- Granular, separate, versioned consent for each media item and each distribution audience; private-gallery activation requires a deployment-specific approved statement, current policy version, identifiable agency brand and tested withdrawal path.
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

For members-only gallery activation, do not set `PRIVATE_GALLERY_CONSENT_APPROVED=true` until counsel has approved the exact companion-facing statement and privacy notice for the deployment's actual brand and market. Set a new `PRIVATE_GALLERY_CONSENT_VERSION` whenever the consent text or purpose changes. The application intentionally keeps the feature disabled when the policy version or approval flag is missing.


## Verifier staffing and identity assurance

Before model-agency adult-eligibility verification can be activated, provision at least one active, email-verified account with the dedicated `verifier` role and MFA enrollment. The role is restricted to the eligibility queue; it is not a substitute for trained human review, written operating procedures or independent oversight. The workflow currently records human review evidence and must not be represented as an automated age-assurance provider integration.

Use NIST SP 800-63A-4 as a current identity-proofing reference where relevant, while obtaining jurisdiction-specific advice for the actual age-assurance obligation and method: https://csrc.nist.gov/pubs/sp/800/63/a/4/final

## Payment and integration activation remains blocked

Crypto settlement, card processing, concierge messaging and calendar synchronization remain planned. Unknown prerequisites deliberately fail closed. Do not enable these features until the repository contains the real adapter and its test suite, network/provider configuration is validated, the exact business model has written provider and legal approval, and data retention/security controls are implemented.

For any future OAuth calendar integration, apply IETF RFC 9700: authorization-code flow with PKCE (S256), exact redirect URI matching, audience/scope restriction, refresh-token rotation or sender-constraining for public clients, TLS-only token handling and revocation/replay detection: https://www.rfc-editor.org/rfc/rfc9700.html
