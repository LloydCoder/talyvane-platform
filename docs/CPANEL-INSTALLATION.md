# cPanel installation guide

This guide describes the intended safe deployment procedure for a Talyvane release on cPanel shared hosting. It does not imply that every cPanel provider exposes the same controls. Confirm the hosting compatibility checklist before uploading a production release.

## 1. Choose a compatible hosting plan

Ask the provider to confirm:
- PHP 8.3 or later for both the website and CLI.
- Required PHP extensions listed in [HOSTING-COMPATIBILITY.md](HOSTING-COMPATIBILITY.md), including GD with WebP support and PDO MySQL.
- MySQL or supported MariaDB, database creation privileges and phpMyAdmin access.
- Ability to set the domain document root to a folder named `public`, preferably outside `public_html`.
- HTTPS, adequate disk quota, PHP memory/upload limits and writable application storage.
- SSH/Terminal, Composer and cron availability (strongly recommended).

Do not purchase a plan based only on the word "cPanel".

## 2. Prepare the release

Use a tagged, tested release artifact from the project release process. Do not deploy directly from an unreviewed development branch.

The release must include:
- Application source and production `vendor/` dependencies resolved from the committed `composer.lock`.
- Compiled frontend assets in the public build directory.
- Migrations and documented release notes.
- No production `.env`, database dump, customer data, developer secrets or local logs.

Build dependencies and assets in CI or a compatible local build environment. Production hosting does not need Node.js or npm. The repository must commit and validate `composer.lock` before commercial deployment.

## 3. Create the database

In cPanel, open **MySQL Database Wizard** (or the provider's equivalent):
1. Create a dedicated database for this Talyvane installation.
2. Create a dedicated database user with a unique, strong password.
3. Grant that user only the privileges needed by the application and migrations.
4. Record the provider-specific database host, database name and username (cPanel commonly prefixes these names).
5. Keep phpMyAdmin private to the hosting account; never expose it through the application.

## 4. Upload outside the public web root

Preferred layout:

```text
/home/CPANEL_USER/
  talyvane-app/          # Laravel source, vendor, .env, storage
    app/
    bootstrap/
    config/
    database/
    public/
    resources/
    routes/
    storage/
    vendor/
    .env
  public_html/            # only if this domain's document root is public_html
```

Preferred option: configure the domain or subdomain document root to `/home/CPANEL_USER/talyvane-app/public`. This keeps application code, dependencies, environment variables and private storage outside the web root.

If the provider cannot change the document root, use only a release-supported public front-controller arrangement that keeps the entire application directory outside `public_html` and changes the front-controller paths to the private application directory. Do not copy the Laravel source, `.env`, `vendor/`, `storage/` or backups into `public_html`. If the host cannot safely serve only the public front controller, it is incompatible.

## 5. Configure production environment

Create `.env` in the private application root with values specific to this installation. Example (replace every placeholder and never commit this file):

```dotenv
APP_NAME="Talyvane"
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=https://example.com

LOG_CHANNEL=stack
LOG_LEVEL=warning

DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=CPANEL_PREFIX_database
DB_USERNAME=CPANEL_PREFIX_user
DB_PASSWORD=REPLACE_WITH_UNIQUE_SECRET

SESSION_DRIVER=file
SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=true
CACHE_STORE=file
QUEUE_CONNECTION=sync

MAIL_MAILER=smtp
MAIL_HOST=mail.example.com
MAIL_PORT=587
# Leave MAIL_SCHEME unset unless the provider explicitly requires smtp or smtps.
MAIL_USERNAME=REPLACE_WITH_MAIL_USER
MAIL_PASSWORD=REPLACE_WITH_MAIL_SECRET
MAIL_FROM_ADDRESS=concierge@example.com
MAIL_FROM_NAME="Talyvane"
```

Use only environment keys supported by the exact release. Generate a unique application key once during secure installation; never reuse a key across customer deployments or rotate it casually because encrypted data may become unreadable. Set the database host and mail encryption exactly as instructed by the provider. Keep `APP_DEBUG=false` at all times in production.

The example uses file sessions/cache and synchronous queues to avoid requiring Redis or a long-running worker. The release must verify these drivers are supported and that the required private storage directories are writable. Use database sessions/cache only when the matching migrations and operational configuration are present.

## 6. Install dependencies and run migrations

### With SSH/Terminal and Composer

From the private application directory, using the PHP binary/version selected for the domain:

```bash
composer install --no-dev --prefer-dist --optimize-autoloader
php artisan key:generate
php artisan migrate --force
php artisan storage:link
php artisan optimize
```

Only run `key:generate` during first installation when no key exists. For upgrades, back up first, review release notes and run the documented migration command. Do not run destructive migration reset/fresh commands on production.

After configuring the environment and applying migrations, provision the deployment's single Super Admin from a trusted terminal:

```bash
php artisan migrate --force
php artisan talyvane:super-admin:create admin@example.com
```

The command prompts interactively for the account name and a strong password, records the unique Super Admin principal in the deployment control row, and refuses a second bootstrap. Sign in immediately and complete mandatory TOTP MFA. Do not place passwords in shell arguments, deployment logs or tickets. Preserve a separately controlled recovery procedure. Never create a public route that executes arbitrary Artisan commands.

### Without SSH/Composer

Upload only a complete, prebuilt release containing its production dependencies. The current application provides CLI-only Super Admin provisioning; therefore a host without SSH is **not yet fully installable through a supported self-service workflow** until the safe browser installer or a release-specific secure bootstrap alternative is implemented and tested.

Do not work around this limitation by uploading a web-accessible PHP script that runs migrations, accepts arbitrary commands or creates an administrator from an unauthenticated request. A manual SQL import is acceptable only when supplied and versioned for that exact release, reviewed for the target database engine and accompanied by a secure first-admin provisioning procedure.

## 7. Permissions and private storage

The PHP runtime must be able to write to:
- `storage/` and its framework/cache/session/log subdirectories.
- `bootstrap/cache/`.

Use the narrowest ownership and permissions the host supports (commonly 0755/0775 depending on PHP-FPM ownership). Never use 0777 as a general fix. Keep private identity evidence and unpublished media outside public storage. Publicly approved assets must be delivered through the application's authorized media path.

## 8. First-run verification

Before opening the site to customers:
1. Visit the HTTPS homepage and health endpoint.
2. Verify login, email verification and administrator MFA.
3. Verify the Super Admin is provisioned through the approved bootstrap process.
4. Confirm a non-admin cannot access administration routes.
5. Confirm private storage and environment files are not downloadable.
6. Verify mail delivery, uploads, image re-encoding and WebP processing.
7. Run a test booking through the manual workflow in a non-production test account.
8. Verify every market and payment method remains disabled until separately reviewed and approved.
9. Verify backups and restoration.
10. Remove installer artifacts and temporary files; ensure debug mode is off.

## 9. Cron and scheduled tasks

If the release requires Laravel's scheduler, add a cPanel cron entry using the exact PHP CLI binary and private application path provided by the host. Standard Laravel scheduler invocation is once per minute:

```bash
* * * * * /usr/local/bin/php /home/CPANEL_USER/talyvane-app/artisan schedule:run >> /dev/null 2>&1
```

The PHP path is provider-specific; discover it from the provider or cPanel Terminal. Do not copy the example path blindly. If cron is unavailable, disable or clearly mark scheduler-dependent features as unavailable.

## 10. Go-live

- Confirm DNS and TLS.
- Confirm a verified backup exists outside the web root.
- Confirm legal, safeguarding, payment-provider and market approvals independently.
- Confirm operational contact and incident-response procedures.
- Record hosting environment details and deployment version.
- Do not enable production bookings or payment methods until all applicable release gates pass.
