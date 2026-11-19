# Security and Privacy Baseline

This is an engineering baseline, not a certification or legal opinion. Use OWASP ASVS 5.0 as a verification framework and threat-model authentication, admin access, bookings, identity evidence, media, payments, webhooks, exports and update workflows.

## Identity and access
- Server-side role and resource policies on every protected request.
- MFA for administrators, finance, verification and safeguarding roles.
- Strong password hashing, login throttling, session rotation and secure recovery.
- TLS-only secure, HttpOnly, SameSite cookies.
- Reauthentication for privileged actions; prevent IDOR with resource-scoped queries.

## Sensitive data
Collect only necessary data for documented purposes. Never expose legal names, identity documents, private addresses, phone numbers or verification evidence publicly. Store verification evidence separately with restricted access, encryption where supported and access audit. Record consent purpose, version, actor, timestamp and withdrawal. Profile publication and automatic-booking opt-in/out are recorded as separate review events. Provider references and external payment transaction references are stored as keyed HMAC digests, not raw identifiers; no identity document is stored in public profile tables. Define retention/deletion schedules. Do not infer age, identity or sensitive traits.

## Media
Validate extension, MIME and file signature; re-encode images/transcode video; strip EXIF/GPS; randomise storage names; keep originals private; enforce limits; scan and quarantine uploads; prevent path traversal and executable uploads. Publish only after adult-status, ownership and explicit publication-consent checks. Provide rapid unpublish and consent withdrawal. Signed URLs cannot prevent screenshots or copying.

## Payments
Never collect seed phrases/private keys. Validate public addresses, network and token contract. Screenshots, client callbacks and return URLs are not payment proof. Verify server-side; use idempotency/replay protection; snapshot invoice instructions; separate finance permissions. Do not claim custody or escrow without approval.

## Application controls
Validate trust-boundary inputs; parameterised queries; output encoding; CSRF; restrictive CORS; CSP/security headers; rate limits; SSRF protections on URL importers; explicit DTOs; dependency scanning; no debug mode or stack traces in production.

## Audit and response
Audit privileged configuration, profile publication, companion consent changes, booking decisions, deposit verification, refunds, exports and suspensions. Booking and consent audit events should be retained under a documented schedule; the current application has initial profile-review records but not yet a complete immutable event ledger. Keep sensitive payloads out of logs. Define incident triage, containment, evidence preservation, notification assessment and recovery.

## Release gates
No production release until authorisation tests, upload security, payment verification, privacy controls, backup restore, dependency audit, secret scan, vulnerability review and jurisdictional sign-off pass.
