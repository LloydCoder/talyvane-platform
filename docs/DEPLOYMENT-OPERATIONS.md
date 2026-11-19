# Deployment, release and upgrade operations

## Release policy

- Deploy only tagged, reviewed releases with green CI.
- Build a reproducible artifact from the exact commit and committed `composer.lock`. CI currently produces a 14-day standalone-package smoke artifact after the full PHP matrix passes; it is a validation output, not a signed or tagged production release.
- Run dependency audit, tests, asset build and packaging in CI; production cPanel must not need Node.js. The current package job verifies production Composer installation, platform requirements, dependency advisories, archive contents and SHA-256 integrity.
- Store no secrets, customer records, production database dumps or `.env` files in the release artifact.
- Record release version, commit SHA, migration list, PHP/database compatibility and rollback notes.

## Initial deployment

Follow [CPANEL-INSTALLATION.md](CPANEL-INSTALLATION.md) and verify every item in [HOSTING-COMPATIBILITY.md](HOSTING-COMPATIBILITY.md). The application must be served from Laravel's `public/` directory only. Keep source, `.env`, `vendor/`, private storage and backups outside the web root.

## Upgrade procedure

1. Review release notes, required PHP/database versions and migration changes.
2. Put the application into maintenance mode using the supported release procedure, or use a provider-level maintenance page if CLI is unavailable.
3. Create and verify a database backup and private-file backup.
4. Upload the new release into a new versioned private directory where possible.
5. Preserve the existing production `.env` and private uploads; do not overwrite secrets with sample values.
6. Install dependencies from the lockfile if the artifact does not include `vendor/`.
7. Run only the documented forward migrations. Never run `migrate:fresh`, `db:wipe` or destructive seeders on customer production data.
8. Run the release health checks and smoke tests.
9. Switch the document root/symlink to the new release if the host supports atomic switching; otherwise follow the documented maintenance-window replacement procedure.
10. Clear and rebuild application caches using supported Artisan commands.
11. Verify login, MFA, booking, uploads, mail, scheduled tasks and private-file protections.
12. Remove maintenance mode and monitor logs.

## Rollback

A code rollback is not automatically a database rollback. Before deployment, every release must state whether migrations are backward-compatible and provide a recovery plan. If a migration is irreversible or changes persisted data, restore the verified database backup and compatible private-file backup together with the prior release. Never promise zero-downtime or automatic rollback on shared hosting without verifying provider capabilities.

## Backups and recovery

Back up the database and private storage separately, encrypt backups before off-account storage, restrict access and define retention. Keep at least one recovery copy outside the hosting account. Test restoration on an isolated environment before relying on backups. Do not place downloadable backups in `public_html`.

## Runtime operations

- Use file cache/session and synchronous queue execution as the baseline where supported.
- Use cron for scheduler tasks when available; test actual execution.
- Do not assume a queue worker, WebSocket server or persistent process can run on shared hosting.
- Use bounded polling for near-real-time website chat; document expected delay and request limits.
- Configure SMTP with authenticated TLS and verify delivery.
- Monitor disk quota, PHP errors, Laravel logs, failed jobs (if queueing is enabled), mail failures and backup freshness.
- Rotate credentials through approved secret-management procedures; never expose secret values in the Super Admin UI or logs.

## Incident response

Document provider contact, deployment owner, emergency feature-disable process, backup location, credential rotation steps and legal/safeguarding escalation contacts. Preserve relevant audit evidence; do not delete or alter incident records to hide an event.
