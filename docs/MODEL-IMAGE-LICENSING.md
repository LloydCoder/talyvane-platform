# Model Image Usage Licence Ledger

## Scope

This is a private administrative ledger for purpose-specific model image usage licences. It is separate from companionship services and bookings. It binds one approved private portfolio asset to one executed representation agreement and one approved country market.

An asset approved for internal portfolio curation is not automatically licensed for any third-party or public use. Each use requires a separate licence record with its own licensee, purpose, channels, territory, term, exclusivity, compensation, external execution evidence and model consent evidence.

## Activation

Configure an exact reviewed policy version only after qualified legal review:

```dotenv
MODEL_AGENCY_IMAGE_LICENCE_POLICY_VERSION=reviewed-version
MODEL_AGENCY_IMAGE_LICENCE_POLICY_APPROVED=true
```

The operator approval flag is an attestation, not legal advice, signature validation, regulatory approval or platform certification. Activation also rechecks the current portfolio-consent and representation-terms policy versions, the applicant's accepted/verified state, the absence of a legal hold, the asset's approved/non-withdrawn state, the executed representation agreement, and the approved market.

## Licence record

A draft records:
- Licensee entity and optional customer-contract reference.
- One explicit purpose: editorial, commercial campaign, e-commerce, social media, advertising, casting distribution, private portfolio or other.
- One or more allowed channels.
- Specific usage details, campaign, restrictions and no implied sublicensing.
- One country-market territory, matching the executed representation agreement.
- Start and end dates, bounded by the representation agreement term.
- Exclusivity and optional agreed compensation in an enabled currency.

The service prevents overlapping active licences for the same asset/territory/channel where the purpose is the same or either licence is exclusive. Activation rechecks conflicts under the application's transaction/locking boundary.

## External execution and consent

A draft becomes active only when an administrator records:
1. A private storage reference to the externally executed licence instrument.
2. A separate private storage reference to the model's informed consent for this exact purpose, territory, channel set and term.
3. Confirmation that all required parties executed externally and the model consent was obtained.

The platform does not generate, deliver, sign or cryptographically validate the documents. It does not prove the identity or authority of signatories or determine copyright ownership. Evidence references must be keys in private storage, never public URLs or local filesystem paths. Evidence upload/download and access auditing are a separate document-management phase.

## Revocation and expiry

- Portfolio consent withdrawal revokes all draft and active licences tied to that asset in the same transaction that removes the private image.
- Representation agreement termination revokes all draft and active licences tied to that agreement in the same transaction.
- An administrator can revoke a draft or active licence with a recorded reason.
- A daily scheduled command marks active licences expired after their recorded end date. Cron must be configured and monitored on shared hosting.
- Revoked and expired records remain as audit history. No public image-serving route exists in this phase.

## Compensation boundary

Compensation is recorded as agreed contract metadata using an exact decimal amount and enabled ISO currency. It is not an invoice, payment instruction, settlement, commission calculation, tax determination or evidence that money was paid. Payment and settlement are separate later workflows.

## Security and operational controls

- All licence operations are inside the authenticated, MFA-gated administrator route group.
- Licensee identity, contract references, purpose details, external execution evidence and model consent evidence are encrypted at rest with APP_KEY.
- Channel names, territory, dates and status remain queryable for policy enforcement and audit.
- No image is made public, served to clients, distributed to casting users, sent to a CDN or embedded in a campaign by this ledger.
- No licence can be created without an approved private asset and executed representation agreement for the same applicant and territory.
- Current market approval and current policy versions are rechecked before activation.

## Known limitations and next phases

- No public/client/CDN image delivery or campaign export.
- No e-signature integration or cryptographic verification of external documents.
- No secure upload/download vault for the external evidence references.
- No client-facing casting calls, briefs, submissions, shortlists, assignments or bookings.
- No automatic royalty/commission calculation, invoicing, payment collection or settlement ledger.
- No automated monitoring of external campaign compliance or third-party takedown.
- Legal review is required for the actual terms, compensation, image rights, territory, channels, term, exclusivity, revocation and applicable privacy rules before market activation.
