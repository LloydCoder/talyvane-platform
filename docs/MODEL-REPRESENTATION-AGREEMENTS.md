# Model Representation Agreement Register

## Purpose

This is a separate model-agency workflow. It does not reuse companionship bookings, companion contracts, companion profile publication consent or payment flows. Acceptance of an application is only an invitation to discuss representation; it does not itself create an agency relationship.

## Safe activation

Drafts can be prepared without enabling representation. Issuing an offer is blocked unless both deployment values are configured after jurisdiction-specific legal review:

```dotenv
MODEL_AGENCY_REPRESENTATION_TERMS_VERSION=reviewed-version
MODEL_AGENCY_REPRESENTATION_TERMS_APPROVED=true
```

The approval flag is an operator attestation, not legal advice, regulatory approval or platform certification. Changing the configured version makes older drafts ineligible for offer.

## Lifecycle

1. Draft: an authorized agency administrator creates an encrypted, versioned terms snapshot covering territory, representation scope, exclusivity, commission, term, notice, image-use scope, compensation, expenses, and consent/withdrawal provisions.
2. Offered: only a draft under the currently approved terms-policy version can be offered. The application must remain accepted, adult eligibility must be verified, and no legal hold may be active.
3. Executed: an administrator records that all required parties executed the agreement outside this platform, with effective dates and a reference to the executed document in private storage. The platform does not provide e-signature, identity assurance, delivery proof or enforceability determination.
4. Terminated: only an executed agreement can be terminated, with administrator, timestamp and reason recorded.

Each agreement is bound to one country-market code. Drafting, offering and recording execution require that market to remain enabled, compliance-approved and backed by current review evidence. Market approval is rechecked under a row lock at each transition. Multi-country scope requires separate market-approved agreements.\n\nOnly one draft, outstanding offer or executed agreement may exist per application at a time. Completed/terminated agreements remain as historical versions. Lifecycle operations serialize under row locks and append to the application event log.

## Evidence and privacy

Agreement terms, executed-document reference and termination reason are encrypted at rest with APP_KEY. This feature does not upload documents. Operators must store the executed instrument in access-controlled private storage outside the public web root before recording its storage key. Never use a public URL or filesystem path. A future document-management phase must add authenticated private upload/download, malware scanning, access logging, retention/legal-hold enforcement and backup/restore verification.

The register does not publish models, grant image rights, create assignments or authorize image use. Applicant erasure is deferred while any agreement record exists until an operator completes a separate documented contract-retention review; this avoids silently deleting or retaining contract evidence without a lawful retention decision. Image-use scope is proposed contract text only. Each campaign/purpose still requires a separate informed and revocable usage licence before image publication or use.

## Limitations and next phases

- The platform records external execution as an administrator assertion; it does not capture applicant signatures or cryptographically verify a signed document.
- The platform does not decide whether commission, exclusivity, territory, term, notice, deductions or termination provisions are lawful.
- No agreement should be offered until actual terms and applicant-market combinations are approved by qualified counsel.
- No model assignment, public profile, image publication, settlement or payment is enabled by this register.
- Direct database administrators can bypass application-level protections.
- Remaining phases: private document management; portfolio/media provenance and consent; purpose-bound image licences; casting and assignment workflows; model booking and delivery; expenses, commissions and settlements; verified applicant/model portal; jurisdiction-specific legal, privacy, safeguarding and accounting review.
