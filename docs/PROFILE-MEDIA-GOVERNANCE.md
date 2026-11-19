# Companion profile media governance

## Workflow

1. A companion can submit media only for a profile linked to their authenticated account.
2. Upload requires two explicit attestations: rights to the image/likeness and consent to publication if approved.
3. The upload is validated as JPEG, PNG or WebP, capped at 8 MiB, checked for dimensions/pixel count before decode, then re-encoded to WebP.
4. Re-encoding removes embedded EXIF/GPS/camera metadata and the original filename. The sanitized output is stored on Laravel's private local disk.
5. New media remains `pending_review`; it is not reachable through the public media endpoint.
6. An owner/admin with MFA reviews the private preview and records approve/reject.
7. Approved media is served through an application route that checks both media approval and the profile's current publication, age-verification and consent state on every request.
8. Withdrawing profile consent or suspending the profile immediately makes the media route return 404. Media is never placed in the public storage symlink.

## Operational requirements

- PHP GD with WebP support is mandatory. CI enables GD; verify `gd`, `imagewebp` and WebP support on the target cPanel host before deployment.
- Keep `storage/app/private` outside the web root and ensure it is not exposed by custom Apache/Nginx aliases.
- Use HTTPS, CSRF protection, authenticated companion accounts, email verification, MFA-protected administration and upload throttling.
- The upload path is randomized and does not include user-provided filenames.
- Public delivery uses `Cache-Control: private, no-store` so a profile suspension is not undermined by intermediary caching.
- Backups and retention must be configured to match the deployment's lawful retention policy. Deleting a media record deletes its active private file; backup copies follow the documented backup lifecycle.

## Governance limitations

This workflow records the uploader's attestations and the administrator's review. It does not itself prove identity, age, ownership of copyright, consent of every depicted person, or legality of the image in a jurisdiction. The agency must verify the actual adult profile owner using an approved age-assurance process, retain appropriate evidence outside public profile data, and obtain documented likeness/publication rights from every identifiable person depicted. Do not upload third-party, stock, scraped, manipulated or AI-generated portraits that could misrepresent a real companion. Review content manually before approval.

A consent withdrawal blocks future media requests immediately. Operational procedures must also address takedown requests, backups, lawful retention, evidence preservation, and deletion from any CDN or external cache if one is introduced later.
