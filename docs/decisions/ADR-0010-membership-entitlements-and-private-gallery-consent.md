# ADR-0010: Server-enforced membership entitlements and private gallery consent

- Status: Accepted for implementation; production activation remains disabled pending deployment-specific legal approval.
- Date: 2026-11-20
- Decision owners: TALYVANE product and engineering

## Context

Client membership records previously represented an agency-assigned tier and a client-facing benefits summary. Those fields are descriptive and must never act as authorization. The product requires protected benefits, beginning with private gallery media.

A general public-profile publication attestation does not establish consent for a different audience or purpose. The platform must not infer members-only distribution consent from the public publication consent.

## Decision

1. Maintain a server-owned entitlement catalogue. Only catalogue keys can be assigned; client input and tier labels never create authority.
2. Persist each entitlement as an immutable grant tied to a specific membership, assigning operator, and validity window.
3. Evaluate access using an active, in-date membership, an in-date grant, an active email-verified client account, and the effective platform feature state.
4. Deliver private gallery media only when the media has a separate, positive, versioned, non-withdrawn companion opt-in to the current approved members-only consent statement.
5. Keep private media URLs out of public companion and destination listings. Recheck all conditions at final media delivery.
6. Record companion consent grant and withdrawal events with actor identity snapshot, capture method, media reference, policy version, exact statement text and SHA-256, correlation ID and timestamp. Application model guards reject ordinary Eloquent mutation/deletion; database administrators remain outside this application-level guarantee.
7. Keep the feature disabled by default. Super Admin activation requires the schema/runtime checks and an explicitly approved policy version. Changing the policy version invalidates older private-gallery consent until the companion opts in again.
8. Companion consent withdrawal is available independently from general public-profile publication withdrawal and immediately blocks members-only delivery.

## Consent policy

The deployment must determine and document the appropriate lawful basis and obtain jurisdiction-specific legal review. The private-gallery consent statement must clearly identify the agency/controller, purpose, eligible audience, relevant recipients/processors and withdrawal mechanism. The statement is optional and separate from public publication consent.

The current canonical statement is provided by `MembershipEntitlementCatalog::privateGalleryConsentStatement()`. Its SHA-256 and policy version are captured in the immutable consent event record.

## Alternatives rejected

- **Tier-name checks:** rejected because mutable display labels are not stable authorization policy.
- **Client-submitted entitlement keys without server allowlisting:** rejected because client input must never grant access.
- **Public profile consent reused for members-only distribution:** rejected because it conflates separate audiences and purposes.
- **URL secrecy or obscurity:** rejected because a guessed or leaked URL must not bypass authorization.
- **Feature activation as the only access check:** rejected because every request must verify the individual membership, grant, consent and resource state.
- **Public CDN delivery for private gallery media:** rejected because access revocation and cache invalidation cannot be reliably guaranteed.

## Consequences

- Membership assignment and entitlement grants are committed in one database transaction.
- Superseding or suspending a membership removes effective access without deleting historical grants.
- Expired memberships and grants fail closed without requiring a scheduled revocation job.
- Consent withdrawal and policy-version changes immediately block private media.
- Additional benefits require a complete server-side enforcement path, tests and an updated catalogue before they can be offered.
- Database-level tamper resistance, WORM retention and external evidence archival remain separate hardening work and must not be implied by model-level guards.

## Verification

Feature tests cover grant validity, supersession, inactive/unverified clients, direct service authorization, public-discovery leakage, media delivery, policy-version invalidation, consent grant/withdrawal, and application-level immutability. CI runs PHP 8.3, 8.4 and 8.5 plus standalone production-package smoke validation.

## References

- ICO, [Consent](https://ico.org.uk/for-organisations/uk-gdpr-guidance-and-resources/lawful-basis/a-guide-to-lawful-basis/consent/)
- EDPB, [Guidelines 05/2020 on consent](https://www.edpb.europa.eu/documents/guideline/guidelines-052020-on-consent-under-regulation-2016679_en)
- `docs/CLIENT-MEMBERSHIP.md`
- `docs/PROFILE-MEDIA-GOVERNANCE.md`
- `docs/SECURITY-PRIVACY.md`
