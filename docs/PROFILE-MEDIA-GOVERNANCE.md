# Companion profile media governance

## Workflow

1. A companion can submit media only for a profile linked to their authenticated account.
2. Upload requires separate positive attestations for rights to the image/likeness and consent to public profile publication. A third, optional members-only consent is presented separately only when a versioned policy has been approved; it is not preselected or bundled with public publication consent.
3. The upload is validated as JPEG, PNG or WebP, capped at 8 MiB, checked for dimensions/pixel count before decode, then re-encoded to WebP.
4. Re-encoding removes embedded EXIF/GPS/camera metadata and the original filename. The sanitized output is stored on Laravel's private local disk.
5. New media remains `pending_review`; it is not reachable through the public media endpoint.
6. An owner/admin with MFA reviews the private preview and records approve/reject plus an access level. `public` media may appear in public discovery. `members_only` media requires a separate, current companion opt-in to the exact approved policy version, the separately enabled membership-entitlements feature and a current `media.private_gallery` grant. Agency approval cannot substitute for the companion's consent.
7. Approved media is served through an application route that checks media approval and the profile's current publication, age-verification and consent state on every request. Members-only delivery additionally checks the current approved consent-policy version, non-withdrawn media-specific consent, an active, in-date membership, an in-date immutable grant, an active verified client account and the effective entitlement feature state.
8. Public discovery and destination listings query public media only; they never expose members-only media URLs. The profile-detail projection includes members-only media only for an entitled client with current consent. The companion can independently withdraw members-only consent; withdrawal is recorded with actor, exact statement snapshot, policy version, statement hash and correlation ID and immediately blocks delivery without changing separate public-publication consent. A policy-version change invalidates older private-gallery consent until the companion opts in again. Withdrawing profile consent or suspending the profile immediately makes all media routes return 404. Media is never placed in the public storage symlink.
9. The profile's country market must also be explicitly enabled and evidence-approved before media submission, moderation approval or public delivery is permitted.

## Operational requirements

- PHP GD with WebP support is mandatory. CI enables GD; verify `gd`, `imagewebp` and WebP support on the target cPanel host before deployment.
- Keep `storage/app/private` outside the web root and ensure it is not exposed by custom Apache/Nginx aliases.
- Use HTTPS, CSRF protection, authenticated companion accounts, email verification, MFA-protected administration and upload throttling.
- The upload path is randomized and does not include user-provided filenames.
- Public delivery uses `Cache-Control: private, no-store` so a profile suspension is not undermined by intermediary caching.
- Backups and retention must be configured to match the deployment's lawful retention policy. Withdrawing or removing an image marks its record as withdrawn and deletes its active private file; backup copies follow the documented backup lifecycle.

## Consent policy and evidence

- The separate private-gallery policy must identify the deploying agency/controller, the members-only purpose, the eligible audience, any relevant processors/recipients, and how to withdraw. Legal counsel must approve the exact text and lawful basis for each market before setting `PRIVATE_GALLERY_CONSENT_APPROVED=true`.
- `PRIVATE_GALLERY_CONSENT_VERSION` must be a non-empty version identifier. A policy version change invalidates older members-only grants at both discovery and delivery until re-consent is captured.
- Grant and withdrawal events are retained in `profile_media_consent_events` with actor/email snapshot, media reference, policy version, exact statement text, SHA-256 of that statement and correlation ID. Model-level update/delete guards are application controls, not database-administrator-proof WORM storage.
- Official guidance informing the granular opt-in design: [ICO consent guidance](https://ico.org.uk/for-organisations/uk-gdpr-guidance-and-resources/lawful-basis/a-guide-to-lawful-basis/consent/) and [EDPB Guidelines 05/2020](https://www.edpb.europa.eu/documents/guideline/guidelines-052020-on-consent-under-regulation-2016679_en). These sources do not replace jurisdiction-specific legal advice or determine the correct lawful basis for a particular deployment.

## Governance limitations

This workflow records the uploader's attestations and the administrator's review. It does not itself prove identity, age, ownership of copyright, consent of every depicted person, or legality of the image in a jurisdiction. The agency must verify the actual adult profile owner using an approved age-assurance process, retain appropriate evidence outside public profile data, and obtain documented likeness/publication rights from every identifiable person depicted. Do not upload third-party, stock, scraped, manipulated or AI-generated portraits that could misrepresent a real companion. Review content manually before approval.

A consent withdrawal blocks future media requests immediately. Operational procedures must also address takedown requests, backups, lawful retention, evidence preservation, and deletion from any CDN or external cache if one is introduced later.
