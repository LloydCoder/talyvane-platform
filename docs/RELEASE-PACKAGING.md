# Standalone release packaging

## Current CI artifact

After every complete PHP 8.3/8.4/8.5 test matrix passes, CI performs a production-only Composer installation on PHP 8.3 and creates a standalone package smoke artifact from the exact tested commit.

The job:

1. Checks out the exact commit without persisting Git credentials.
2. Installs production dependencies with `composer install --no-dev` from the committed `composer.lock`.
3. Verifies actual PHP platform requirements and audits production dependencies.
4. Boots the production-only dependency tree, enumerates routes and compiles Blade views to verify the packaged runtime without development dependencies.
5. Packages the application, production `vendor/`, public assets, migrations, configuration, documentation, environment example and the private top-level `install/` bootstrap directory.
6. Excludes the real `.env`, tests, Git metadata, runtime caches, logs and customer/private uploaded data.
7. Writes a commit/runtime manifest, creates a normalized tar archive, computes SHA-256 and inspects the archive for forbidden paths.
8. Uploads the package, checksum and package-content listing as an immutable GitHub Actions artifact with a 14-day retention period.

The package uses the tracked public assets. No Node.js or npm build is currently required by the application.

## Important release boundary

The CI artifact is a packaging and installability smoke-test output. It verifies that `install/bootstrap.php` is present at the private project root and absent from `public/`. It is **not** a signed, versioned customer release, does not constitute a security certification, and must not be deployed directly to production. A successful package job proves only the checks actually executed by that job.

Production release engineering remains open. The one-download cPanel bootstrap candidate was merged in PR #74 at `aae35c99dbaa1e0076cac06958c07b49b0f8c09a`; its package workflow, PHP 8.3/8.4/8.5 CI matrix, standalone smoke build and Semgrep passed on the tested PR head. This merge is implementation integration only, not customer-release approval.

- Signed and tagged versioned release workflow.
- Build provenance/SBOM and artifact attestation.
- Release notes, migration compatibility declaration and rollback plan.
- Supported cPanel provider test matrix and deployment smoke tests.
- Secure no-SSH browser installer and first Super Admin provisioning were implemented in PR #62, merged at `df9d4abf3905ba94b115f3fd6e9523e5822e401d`; the one-download cPanel bootstrap was added in PR #74, merged at `aae35c99dbaa1e0076cac06958c07b49b0f8c09a`. Automated workflow coverage includes the browser-installer feature flow, first-login password replacement, MFA setup, post-MFA recovery-key handoff, Apache HTTPS package acceptance and OpenLiteSpeed/LSPHP 8.3 package acceptance. Remaining: clean live cPanel provider acceptance, actual hosting PHP-handler/403 diagnosis, backup/restore and upgrade/rollback drills, and the signed/tagged customer release.
- Backup/restore and upgrade/rollback drills.

Until the signed/tagged release and provider acceptance gates are completed, do not deploy the browser-installer smoke artifact as a production release. Use only an approved, documented deployment path on a verified compatible host, and obtain written hosting, payment and legal approvals for the actual deployment model.
