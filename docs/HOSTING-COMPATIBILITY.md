# Shared-hosting compatibility standard

- Status: Required product target; provider-by-provider certification pending.
- Applies to: Laravel 13 standalone installations on cPanel-managed shared hosting.

## Compatibility promise

Talyvane targets ordinary cPanel shared-hosting accounts that meet the requirements below. "Any cPanel hosting" is not a technically supportable blanket guarantee: cPanel is a control panel, not a fixed runtime specification. Providers can disable PHP extensions, restrict document roots, block outbound traffic, impose CPU/memory/process limits, or omit cron, SSH and Composer.

A hosting account is **compatible** only when every required check in this document passes. The installer and deployment checklist must report PASS, WARNING or BLOCKED for each capability. Never silently weaken security to accommodate a host.

## Runtime baseline

| Requirement | Minimum / policy |
|---|---|
| PHP | 8.3+ for the current Laravel 13 codebase; use the newest supported PHP version offered by the provider |
| PHP extensions | Ctype, cURL, DOM, Fileinfo, Filter, Hash, Mbstring, OpenSSL, PCRE, PDO with MySQL driver, Session, Tokenizer, XML, GD with WebP support |
| Database | MySQL or a compatible MariaDB release supported by the exact Laravel/database-driver dependency set; verify with migrations and integration tests |
| Web server | Apache/LiteSpeed with PHP support and rewrite rules; Nginx-backed cPanel is acceptable only when provider routing is configured correctly |
| TLS | Valid HTTPS certificate for the deployment hostname |
| Writable paths | `storage/` and `bootstrap/cache/` writable by the PHP runtime without world-writable permissions |
| Web root | Domain document root points to Laravel's `public/`; application source, `.env`, `vendor/`, logs and backups remain outside public web access |
| Disk / memory | Sufficient for the release package, private uploads, logs, backups and PHP memory limits; actual quota must be checked per deployment |

GD WebP support is required by the current profile-media pipeline. A host with GD but without WebP encoding is not compatible with that feature; do not silently store original uploads or skip metadata removal.

## Shared-hosting design constraints

Core functionality must not require:
- Root access, Docker, Kubernetes, a VPS or a continuously running daemon.
- Redis, Memcached, Elasticsearch, Node.js or npm on the production host.
- WebSockets, Laravel Octane, Horizon or a permanently running queue worker.
- SSH, Composer or Git installed on the production host (these are optional deployment conveniences, not runtime requirements).

Use MySQL-compatible persistence, Laravel file cache/session options where appropriate, database-backed records, synchronous execution for low-volume critical paths, and cron-triggered work for deferred tasks where cron is available. The application must not claim background work is running when the host cannot execute it. Avoid long-running HTTP requests and use bounded polling for live chat rather than requiring WebSockets.

Email delivery depends on a configured SMTP provider and outbound network policy. Blockchain watchers, external verification, Telegram, exchange-rate feeds, calendar sync and other integrations require their own provider credentials, outbound access and policy approvals; they are optional adapters and must remain disabled until configured and approved.

## Compatibility tiers

### Tier A — cPanel with SSH, Composer and cron
Preferred. Install dependencies on the host or upload a reproducible release artifact; run Artisan migrations and cache commands through CLI; use cron for the scheduler and optional database queue draining.

### Tier B — cPanel File Manager, no SSH/Composer, cron available
Supported only with a prebuilt, versioned release artifact containing production dependencies and compiled assets. Database migrations and first Super Admin provisioning must use a separately documented, authenticated installation process or a carefully prepared release-specific SQL import. Never expose an unrestricted Artisan runner or public setup endpoint. This tier is not fully supported until the safe browser installer and migration workflow are implemented and tested.

### Tier C — no SSH, no Composer, no cron
Limited compatibility. The site can serve synchronous requests if all prerequisites are prebuilt, but scheduled reminders, expiry, cleanup, queue processing and recurring integrations will not run automatically. The UI must disclose disabled operational capabilities. Do not certify as fully operational for production until a reliable provider scheduler or equivalent is available.

### Incompatible
Reject the host if it cannot provide PHP 8.3+, required extensions, a supported MySQL/MariaDB engine, HTTPS, safe private application storage, writable Laravel runtime directories, or a secure public document-root arrangement. Do not recommend putting the entire Laravel project inside `public_html`.

## Deployment acceptance checklist

A deployment is compatible only after:
- [ ] PHP CLI and web PHP versions match and both satisfy PHP 8.3+.
- [ ] All required PHP extensions are enabled for the web SAPI and CLI where used.
- [ ] GD reports WebP encoding support.
- [ ] Database connection, migrations, transactions, indexes and foreign keys pass.
- [ ] HTTPS and secure cookie behavior work behind the provider's proxy.
- [ ] Only Laravel `public/` is web-accessible.
- [ ] Requests to `.env`, source files, `vendor/`, logs, backups and private uploads are denied.
- [ ] `storage/` and `bootstrap/cache/` are writable by the application without 0777.
- [ ] Upload limits, PHP memory, execution time and disk quota are sufficient.
- [ ] Mail transport can connect to the configured SMTP service.
- [ ] Cron is configured and a scheduled task has been observed running, or scheduler-dependent features are explicitly disabled.
- [ ] Backup and restore have been tested.
- [ ] Production debug mode is off; secrets are unique and not committed.
- [ ] Super Admin bootstrap and recovery procedures are available and secured.

## Support boundary

Record the provider, plan, PHP web/CLI versions, database engine/version, extensions, document-root mode, SSH/Composer/cron availability, limits and test date in the deployment record. Hosting compatibility is certified per plan and configuration, not merely by the presence of cPanel.
