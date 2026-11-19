# Model Portfolio Media and Consent Governance

## Scope and separation

Model portfolio media is a separate domain from companion profile media. This phase provides administrator-managed private intake, image sanitization, internal review, private preview and consent withdrawal. It does not publish model images, authorize casting or campaign use, create an image licence, or share data with companionship profiles.

The applicant must already have an accepted application, completed adult-eligibility verification and no active legal hold. The applicant's country market must remain enabled and compliance-approved with current review evidence.

## Activation

Before receiving or approving model portfolio media, configure a reviewed, versioned policy:

```dotenv
MODEL_AGENCY_PORTFOLIO_CONSENT_VERSION=reviewed-version
MODEL_AGENCY_PORTFOLIO_CONSENT_APPROVED=true
```

The approval flag is an operator attestation that the exact consent and image-rights policy has received qualified jurisdiction-specific review. It is not legal advice or proof that any specific image or model consent is valid. If approval is withdrawn or the version changes, new uploads and approvals fail closed. Existing pending assets cannot be approved under a stale policy.

## Intake and processing

1. An authorized administrator uploads an applicant-provided JPEG, PNG or WebP image. This is not a model self-service upload portal.
2. The administrator records source/provenance, the exact limited consent scope, a private rights-evidence storage reference and a private model-consent evidence reference.
3. Both rights and consent attestations are mandatory.
4. The image is decoded and re-encoded as WebP; EXIF/GPS/camera metadata and original filename are discarded. Dimensions are bounded and output is capped at 8 MiB.
5. The sanitized file is stored on the private local disk under a random model-portfolio/ key. The original file is not retained.
6. The asset is pending_review. A separate admin-only preview route is protected by the existing authenticated, MFA-gated admin route group and uses no-store/no-referrer headers.
7. An administrator may approve for private agency curation or reject. Rejected files are removed from private storage.
8. Consent withdrawal requires a verified request record and a private evidence reference. The image file is removed, storage path cleared, and the asset becomes withdrawn. A withdrawn asset cannot be revived by changing profile or application state.

The image checksum prevents duplicate ingestion for the same applicant. It is a pseudonymous identifier and is retained only while the applicant's asset record remains in the system.

## Consent and rights boundaries

The external rights and consent references are administrator-recorded storage keys. The platform does not upload or validate the referenced documents, prove the signer's identity, verify signatures, or determine copyright ownership. Keep those records in access-controlled private storage outside the public web root; never supply public URLs, filesystem paths, credentials or document contents.

The scope captured here is for private agency curation only. An asset marked approved is not public and is not authorized for client viewing, casting distribution, portfolio publication, social media, advertising, campaign production, sublicensing or other third-party use. Those purposes require a later, separate purpose-bound image usage licence with territory, channels, purpose, term, licensee, compensation where applicable, evidence and revocation controls.

## Rejection, withdrawal and erasure

Rejection and consent withdrawal remove the private image file and clear its active storage path. The evidence references and audit metadata remain encrypted while the application remains active.

Applicant erasure is deferred while any portfolio asset still has a private storage path. An operator must first reject or record consent withdrawal and verify file removal. Once every asset file has been removed, the anonymization workflow deletes the remaining asset metadata and encrypted evidence references as part of erasure. Representation-agreement records still require their own separately documented contract-retention review before application erasure.

## Security and operations

- Only owner/admin/Super Admin accounts in the MFA-protected admin group can upload, review, preview or record withdrawal.
- Never place model portfolio files in storage/app/public or expose the private disk through a symlink.
- Verify the cPanel host has GD with WebP support; unsupported image processing fails closed.
- Configure private storage permissions, encrypted backups, retention and restore drills.
- Review the application's legal hold before changing status or processing erasure.
- Keep media evidence access separate from ordinary agency staff where operationally possible.
- This feature has no CDN/public cache integration; do not introduce one without consent-aware invalidation and takedown handling.

## Known limitations and next phases

- No applicant/model portal, self-service upload, consent dashboard or direct applicant withdrawal endpoint yet; administrators record verified external requests.
- No malware scanning or content moderation classifier; human review is mandatory.
- No public image delivery, casting publication, usage licensing, assignments, bookings or settlements.
- No automated identity or copyright verification.
- A later private document-management phase must implement secure evidence upload/download, malware scanning, access logs, retention and deletion.
- The private purpose-bound licence ledger is implemented in [Model Image Licensing](MODEL-IMAGE-LICENSING.md). A later delivery phase must implement consent-aware client/public/CDN access, expiry, takedown and usage monitoring.
