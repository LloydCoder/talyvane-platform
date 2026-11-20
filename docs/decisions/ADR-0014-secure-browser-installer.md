# ADR-0014: Secure browser-based first installation

- **Status:** Accepted; implementation in PR #60, CI and release verification pending
- **Date:** 2026-11-20
- **Decision owners:** Talyvane product and engineering

## Context

The commercial deployment target includes cPanel/shared-hosting customers who may not have SSH, Composer or shell access. The existing CLI bootstrap is the trusted installation path, but it does not satisfy the approved no-SSH first-install requirement. A public PHP script that accepts arbitrary commands, runs user-supplied SQL or provisions an administrator without a constrained workflow would create an unacceptable remote-administration surface.

Laravel's supported deployment model requires the application web root to be the `public/` directory. Source code, Composer dependencies, environment files, private storage and backups must remain outside that document root. The installer must preserve this boundary.

## Decision

1. **Keep the application private.** The Laravel project root, including the top-level `install/` directory, remains outside the web document root. The web server serves only `public/`.
2. **Support cPanel public_html without exposing the application.** When a provider restricts document roots to public_html, publish only the contents of the release's public/ directory and a reviewed main-domain or add-on-domain wrapper. The wrapper includes the private Laravel front controller; Laravel uses Application::usePublicPath() to bind asset paths and preflight checks to the actual document root. The full application root remains outside the document root.
3. **Use a private release bootstrap directory.** The top-level `install/` directory contains only release-owned first-request bootstrap support, a manifest and operator documentation. It is not a standalone HTTP endpoint. Apache access is denied as defense in depth.
4. **Initialize only the minimum runtime prerequisites before Laravel boots.** The public front controller may include the private bootstrap when it exists. It may create a deployment-unique `APP_KEY` and safe file-backed session/cache defaults when no environment exists. It must not accept request input, connect to a database, run migrations or create users.
5. **Serve the guided installer through Laravel.** All user interaction uses named, CSRF-protected Laravel routes, short-lived sessions, strict validation and rate limits. The workflow is a fixed allow-list; arbitrary Artisan commands, shell execution, arbitrary SQL and arbitrary file paths are prohibited.
6. **Persist installer state privately.** Temporary state and the durable installation lock live under private storage, outside the document root. The lock is integrity-protected and is cross-checked against deployment database state. Missing, malformed or inconsistent completion state fails closed; it must not reopen public installation.
7. **Preserve existing Super Admin governance.** Browser provisioning must use the canonical singleton deployment Super Admin, retain the designated-principal authorization checks, and preserve verified notification and recovery controls. It must not promote an existing Owner or create a second Super Admin.
8. **One-time initial password.** Generate the initial password with a cryptographically secure source, store only its password hash, and expose it once through a short-lived encrypted handoff after successful installation checks. The installation success page displays the configured Super Admin login URL and this password only. It must not display recovery keys, application keys, database credentials or environment contents. First login forces password replacement and MFA enrollment.
9. **Lock before success.** Commit the durable completion state and block every installer route before returning the success page. Installer session state is invalidated.
10. **Remove bootstrap files safely.** After completion, attempt to remove only known release-owned files in `install/`; do not recursively delete arbitrary files or follow symlinks. If safe automatic removal is unavailable, leave the application locked and instruct the operator to delete the complete directory through cPanel File Manager immediately.
10. **Keep CLI provisioning.** The existing trusted CLI bootstrap and recovery commands remain available and must retain their existing security properties.

## Consequences

- A verified prebuilt release must include production dependencies and the private `install/` directory.
- The release package workflow must inspect the archive and ensure the installer directory is not inside `public/`.
- A host that cannot keep the application root outside the document root or cannot include the private front controller from its public root is incompatible; installer convenience must not weaken this boundary.
- The no-SSH experience remains unsupported until implementation, automated security tests, cPanel/Apache-LiteSpeed verification, release-package verification and operator documentation are complete.
- Installer deletion is best-effort, but route lockdown is mandatory and independent of deletion success.

## Security and operational references

- [Laravel 13 deployment guidance](https://laravel.com/docs/13.x/deployment)
- [Laravel 13 application structure](https://laravel.com/docs/13.x/structure)
- [Laravel 13 Application::usePublicPath API](https://api.laravel.com/docs/13.x/Illuminate/Foundation/Application.html)
- [cPanel domain document-root restrictions](https://docs.cpanel.net/whm/server-configuration/tweak-settings/domains/)
- [Laravel 13 configuration and environment files](https://laravel.com/docs/13.x/configuration)
- [OWASP ASVS 5.0 — Session Management](https://github.com/OWASP/ASVS/blob/v5.0.0/5.0/en/0x16-V7-Session-Management.md)
- [OWASP ASVS 5.0 — File Handling](https://github.com/OWASP/ASVS/blob/v5.0.0/5.0/OWASP_Application_Security_Verification_Standard_5.0.0_en.pdf)
