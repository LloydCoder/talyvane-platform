# Secure browser installer: security and acceptance specification

**Status:** Approved requirement; implementation in PR #60, final CI and release verification pending  
**Scope:** First installation of a single Talyvane deployment on compatible cPanel/shared hosting, including hosts without SSH or Composer.  
**Normative language:** MUST, MUST NOT, SHOULD and MAY are used as engineering requirements.

## 1. Product outcome

A customer can install a complete, prebuilt Talyvane release through a constrained browser workflow without exposing Laravel source, environment files, dependencies, private storage or database backups to the public web root. A supported CLI path may remain available for operators who prefer it.

The installer is a first-install bootstrap only. It is not a general-purpose command runner, package manager, upgrade interface or remote administration endpoint.

## 2. Required installation sequence

1. **Release and entry-point validation**
   - Accept only a complete, versioned release artifact produced by the approved release process.
   - Verify the release manifest/checksum and supported application, PHP, database and extension versions.
   - Refuse development branches, incomplete packages and unsupported host layouts.
2. **Preflight**
   - Validate HTTPS, canonical application URL, PHP runtime and required extensions, database reachability/privileges, writable private storage, bootstrap/cache, session support, entropy, and the ability to keep application files outside the public document root.
   - Show actionable, non-secret diagnostics. Never disclose environment values, credentials, filesystem paths beyond what an operator needs, or stack traces to unauthenticated visitors.
3. **Configuration**
   - Collect only required deployment settings.
   - Validate and escape every value; protect all state-changing requests with CSRF protection, strict session handling, rate limits and same-origin controls.
   - Write the environment file atomically with restrictive permissions, outside the web root. Never render its contents back to the browser.
4. **Application initialization**
   - Generate a deployment-unique application key using Laravel's supported key-generation mechanism and cryptographically secure randomness.
   - Run only a fixed, allow-listed installation sequence: database connectivity check, forward migrations, required storage initialization and release-defined health checks.
   - Do not accept arbitrary Artisan commands, shell fragments, SQL, paths or executable code from browser input.
   - Make each step restart-safe and idempotent where feasible. Record non-secret step state so interrupted installs can be diagnosed without leaking secrets.
5. **Super Admin provisioning**
   - Create exactly one canonical Super Admin using the platform's existing singleton and account-governance rules.
   - Generate a unique, high-entropy initial password using a cryptographically secure random source.
   - Persist only the framework password hash; never persist or log plaintext credentials.
   - Mark the account as requiring a password change before normal administration access. Enforce MFA enrollment before privileged administration is enabled.
6. **Verification and commit**
   - Verify migrations, database access, storage permissions, route health, Super Admin account state, password-change enforcement and MFA enrollment gate.
   - Create the durable installation-complete lock only after all mandatory checks pass.
   - If any required step fails, do not show the success page or disclose credentials. Preserve safe diagnostic state and provide a documented recovery path.
7. **One-time credential handoff**
   - Display the actual Super Admin login URL generated from the validated canonical application URL and the application's named admin-login route; do not hard-code a path or trust an unvalidated Host header.
   - Display the generated password once, only after successful verification.
   - Hold the plaintext password only in short-lived, server-side, encrypted, one-time handoff state. Do not put it in a URL, query string, database, log, analytics event, email, exception, session identifier or persistent cache.
   - Consume and destroy the handoff on first successful display, expire it quickly, and invalidate the installer session. Disable caching on all installer and credential-handoff responses.
   - Tell the operator to store the password securely, sign in, change it immediately and complete MFA. If the password is lost after the one-time display, use the supported Super Admin recovery process; never reveal it again.
8. **Installer lockdown and removal**
   - Installer endpoints MUST refuse access whenever the durable install lock exists, regardless of browser session or directory presence.
   - Rotate/invalidate installer session state and CSRF tokens after completion.
   - Attempt automatic removal of the installer directory only after the durable lock is committed and credential handoff is safely prepared. Never make successful installation depend on self-deletion.
   - If filesystem permissions prevent removal, show a prominent instruction to delete the entire installer directory immediately using cPanel File Manager or the host's equivalent. Keep every installer route blocked while it remains present.
   - The application MUST fail closed if the lock is missing, malformed or inconsistent with the completed installation state. Provide a documented, authenticated recovery procedure rather than a public reset route.

## 3. Required security controls

- Installer code and temporary state MUST be outside the public document root wherever the supported hosting layout permits. If the hosting provider cannot isolate application code and private state from public access, reject the host as incompatible.
- Never place an executable installer script directly in public_html as a workaround.
- The installer access key MUST be generated and stored outside the public document root with owner-only permissions. It MUST be retrieved by the deployment operator through the hosting control panel's private file manager (or a trusted CLI path) and entered manually. It MUST NEVER be rendered into HTML, returned by an API, included in a hidden field, exposed in a URL, or logged. A public GET request must not disclose or derive the key.
- Use secure, HttpOnly, SameSite cookies; Secure cookies on HTTPS; session ID rotation; CSRF protection; strict origin/host validation; request throttling; and short installer-session expiry.
- Protect database and mail credentials in transit and at rest as far as the hosting model permits. Never echo secrets in validation errors.
- Restrict file writes to known application paths. Prevent path traversal, symlink escapes, archive traversal, arbitrary upload extraction and overwrite of unrelated files.
- Use atomic writes and restrictive file permissions. Do not use 0777.
- Do not run browser-supplied shell commands or interpolate user input into process execution.
- Do not enable debug mode or expose stack traces. Redact secrets from all logs and diagnostics.
- The install lock must be stored in a location that survives PHP process restarts and is not web-accessible. Its integrity must be checked on every installer entry point.
- Apply OWASP ASVS controls to authentication, session management, access control, input validation, file handling, cryptography, error handling and security logging.
- Do not automatically enable legally sensitive markets, bookings, payment methods, adult eligibility, public media or safeguarding workflows as a side effect of installation. Existing default-off approvals remain authoritative.

## 4. Acceptance tests

The implementation is not complete until automated tests cover at least:

- Installer is inaccessible after a successful install, including direct route requests and a fresh browser session.
- Installer refuses a second installation when the lock exists.
- Missing, malformed or inconsistent lock state fails closed.
- Invalid PHP version, missing extensions, unsupported database, insufficient privileges, non-HTTPS production URL and unsafe document-root layout block installation.
- CSRF failures, expired sessions, replayed steps, throttled attempts and invalid Host headers are rejected.
- The private installer access key is never present in the HTML response, page source, URL, logs or analytics; a missing or invalid operator-supplied key is rejected.
- Path traversal, symlink escape and archive traversal attempts cannot write outside approved paths.
- Database and migration failures do not provision credentials or report success.
- Super Admin creation is singleton-safe under concurrent requests and retry scenarios.
- Password is generated with a cryptographically secure source, stored only as a hash and never appears in logs, URLs, persistent records, analytics or exception output.
- Credential handoff is one-time, short-lived, non-cacheable and consumed on display; a second request cannot retrieve the password.
- First login requires password replacement and MFA enrollment before privileged administration.
- Successful installation creates a durable lock and disables installer routes before the success response.
- Automatic installer-directory deletion is attempted safely; deletion failure leaves the application locked and displays the manual-removal instruction.
- Re-running or retrying interrupted steps cannot duplicate the Super Admin, corrupt configuration or silently rerun destructive operations.
- A private, expiring pending-install marker prevents concurrent setup sessions from changing database/mail configuration during email verification; the marker is bound to a random session nonce and stored with restrictive permissions.
- Runtime directories are created/validated before Laravel session middleware runs; symlinked storage ancestors are rejected and private runtime directories are restricted to owner-only permissions.
- cPanel main-domain and add-on-domain front-controller wrappers keep the full Laravel application outside public_html, resolve the actual public document root using Laravel's supported usePublicPath API, and are linted and included in the standalone package.
- The public release contains a hardened Apache/LiteSpeed rewrite file; cPanel public-root wrappers preserve route rewriting, authorization/XSRF headers, deny hidden/private files and disable directory listing.
- The full PHP compatibility matrix, complete application test suite, static/security checks and release packaging workflow pass.

## 5. cPanel operator success screen

The final screen must be explicit and minimal. It displays:

- Installation completed and verified.
- The Super Admin sign-in URL.
- The one-time generated initial password.
- A warning to copy/store the password securely; it will not be shown again.
- A requirement to change the password and enroll MFA immediately.
- A clear instruction to delete the entire installer directory immediately if automatic removal did not succeed.

It MUST NOT display database passwords, application keys, recovery keys, environment contents or internal filesystem secrets.

## 6. Release gate

The browser installer is a release blocker for the promised no-SSH cPanel self-service installation experience. Until its implementation, tests, security review and package verification are complete, documentation and sales material MUST describe no-SSH first installation as unsupported or pending. A visual mockup or documentation-only specification is not evidence of a working installer.

The existing CLI provisioning workflow remains a separate supported path and must continue to preserve the platform's current Super Admin verification, recovery-key, MFA and audit controls. Browser provisioning verifies two distinct email addresses and stores the generated offline recovery key only as a hash plus a short-lived encrypted handoff. That key is revealed only on the authenticated post-MFA recovery-code screen, never on the installer success page.
