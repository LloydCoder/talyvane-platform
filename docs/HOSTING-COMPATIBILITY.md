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
| Web root | Domain document root serves only Laravel public assets and a guarded front controller; use the direct `public/` root or the reviewed cPanel `public_html` wrapper. The full application source, `.env`, `vendor/`, private storage, logs and backups remain outside the document root |
| Disk / memory | Sufficient for the release package, private uploads, logs, backups and PHP memory limits; actual quota must be checked per deployment |

GD WebP support is required by the current profile-media pipeline. A host with GD but without WebP encoding is not compatible with that feature; do not silently store original uploads or skip metadata removal.

The single-ZIP cPanel bootstrap additionally requires PHP's ZIP extension (ZipArchive) on the first web request so it can move the bundled private application outside the document root. This extension is a bootstrap prerequisite, not a permanent application runtime dependency; the installer must report it clearly before extraction.

## Shared-hosting design constraints

Core functionality must not require:
- Root access, Docker, Kubernetes, a VPS or a continuously running daemon.
- Redis, Memcached, Elasticsearch, Node.js or npm on the production host.
- WebSockets, Laravel Octane, Horizon or a permanently running queue worker.
- SSH, Composer or Git installed on the production host (these are optional deployment conveniences, not runtime requirements).

Use MySQL-compatible persistence, Laravel file cache/session options where appropriate, database-backed records, synchronous execution for low-volume critical paths, and cron-triggered work for deferred tasks where cron is available. Dependency versions are fixed by the committed `composer.lock`; production release artifacts must be built with `composer install --no-dev` and must never resolve new versions on the hosting account. See [DEPENDENCY-MANAGEMENT.md](DEPENDENCY-MANAGEMENT.md). The application must not claim background work is running when the host cannot execute it. Avoid long-running HTTP requests and use bounded polling for live chat rather than requiring WebSockets.

Email delivery depends on a configured SMTP provider and outbound network policy. Blockchain watchers, external verification, Telegram, exchange-rate feeds, calendar sync and other integrations require their own provider credentials, outbound access and policy approvals; they are optional adapters and must remain disabled until configured and approved.

## Compatibility tiers

### Tier A — cPanel with SSH, Composer and cron
Preferred. Install dependencies on the host or upload a reproducible release artifact; run Artisan migrations and cache commands through CLI; use cron for the scheduler and optional database queue draining.

### Tier B — cPanel File Manager, no SSH/Composer, cron available
The constrained browser installer and reviewed main-domain/add-on-domain public-root wrappers were implemented in PR #62 and merged to `main` (merge commit `df9d4abf3905ba94b115f3fd6e9523e5822e401d`). Post-merge workflow #44757620878 passed all three PHP feature-suite jobs and the standalone release-package smoke build. This is repository-level verification only. Tier B is a supported product target only for a tagged release and a provider/account that passes every compatibility and deployment acceptance check below; provider-specific live cPanel/Apache/LiteSpeed certification remains pending. Never expose an unrestricted Artisan runner or public setup endpoint.

The single-ZIP customer installation path is tracked in [issue #73](https://github.com/LloydCoder/talyvane-platform/issues/73) and was merged in [PR #74](https://github.com/LloydCoder/talyvane-platform/pull/74) (merge commit `aae35c99dbaa1e0076cac06958c07b49b0f8c09a`). The merged candidate passed the package build, Apache/PHP HTTPS installer acceptance, OpenLiteSpeed/LSPHP 8.3 HTTPS smoke test, PHP 8.3/8.4/8.5 feature matrix, standalone release-package smoke build, and Semgrep on its tested head. The OpenLiteSpeed test verifies rewrite-based denial of the payload and `.env`, first-request extraction, the `/var/www/vhosts/<vhost>/html` account layout, and private-file isolation. This remains repository-level verification: merge is not a signed customer release and does not imply provider-specific cPanel, LiteSpeed Enterprise, PHP-FPM, or live-account certification.

The first public bootstrap request requires PHP `ZipArchive` and write access to the cPanel account directory outside the document root. After extraction, the Laravel application itself remains outside the public root. If the host cannot provide that safe layout, installation must stop with an actionable compatibility error rather than expose the private application.

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
- [ ] Only Laravel public assets and the reviewed front controller are web-accessible; the complete application root is outside the document root.
- [ ] Requests to `.env`, source files, `vendor/`, logs, backups and private uploads are denied.
- [ ] `storage/` and `bootstrap/cache/` are writable by the application without 0777.
- [ ] Upload limits, PHP memory, execution time and disk quota are sufficient.
- [ ] Mail transport can connect to the configured SMTP service.
- [ ] Cron is configured and a scheduled task has been observed running, or scheduler-dependent features are explicitly disabled.
- [ ] Backup and restore have been tested.
- [ ] Production debug mode is off; secrets are unique and not committed.
- [ ] Super Admin bootstrap and recovery procedures are available and secured.

## PHP request 403 diagnosis

If a static asset is served successfully but a minimal standalone PHP probe in the same document root returns HTTP 403, treat that as a PHP-handler or server-access problem until logs prove otherwise. It is not evidence of a Laravel route or installer-code defect: the probe does not execute Laravel.

For cPanel, verify the domain's active PHP version in MultiPHP Manager and confirm the web SAPI—not only CLI PHP—has the required handler and extensions. If the provider uses LiteSpeed, its cPanel integration runs PHP through LSPHP and selects the version configured by cPanel MultiPHP Manager / CloudLinux PHP Selector. LiteSpeed's own 403 guide lists a missing PHP handler, file permissions, incorrect ownership, `.htaccess` denial rules and firewall/WAF blocks as possible causes. Inspect the provider's error log at the exact request timestamp; do not globally disable ModSecurity/WAF or weaken permissions as a diagnostic shortcut.

References: [cPanel PHP version and extension management](https://docs.cpanel.net/ea4/php/how-to-locate-and-install-a-php-version-or-extension/), [LiteSpeed PHP on cPanel](https://docs.litespeedtech.com/lsws/cp/cpanel/php/), and [LiteSpeed 403 troubleshooting](https://docs.litespeedtech.com/lsws/cp/cpanel/403-error/).

## Support boundary

Record the provider, plan, PHP web/CLI versions, database engine/version, extensions, document-root mode, SSH/Composer/cron availability, limits and test date in the deployment record. Hosting compatibility is certified per plan and configuration, not merely by the presence of cPanel.
