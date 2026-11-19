# Local Development and First Deployment

## Requirements
- PHP 8.3–8.5 with Laravel-required extensions.
- Composer 2.
- MySQL for a deployment; SQLite is used by the CI test suite.
- A writable Laravel storage directory.
- A web server configured with the Laravel public directory as its document root.

## Initial local setup
1. Clone the repository.
2. Resolve dependencies with Composer. The repository currently has no committed composer.lock, so the first setup uses composer update and generates one.
3. Copy .env.example to .env.
4. Configure APP_URL and the MySQL database credentials.
5. Generate the application key.
6. Run database migrations.
7. Create the first owner through the trusted CLI prompt.
8. Create the public storage link for customer brand assets.
9. Start the local server.

Commands:

```bash
composer update --prefer-dist
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan talyvane:admin:create owner@example.com
php artisan storage:link
php artisan serve
```

After the first dependency resolution, commit composer.lock and use composer install for reproducible installs. Do not run composer update as part of a production deployment. CI currently audits and exports the resolved lockfile as an artifact; the lockfile has not yet been committed.

## Tests

```bash
php artisan test
```

CI currently resolves dependencies, validates Composer metadata and runs PHPUnit on PHP 8.4. The lockfile should be committed before the first production release so CI and deployment resolve identical dependency versions.

## First administrator
There is no public staff registration or web-based setup endpoint. Create the first owner from a trusted terminal using the CLI command. The command prompts for a name and hidden password input; use a unique password of at least 14 characters. Do not place credentials in shell arguments, source control or chat.

Provision companion accounts from a trusted terminal with `php artisan talyvane:companion:create companion@example.com`. Deliver the temporary password through a separate secure channel. The companion must verify their email and then access `/companion-portal`. Link the account to a draft profile in Admin → Profiles.

The first owner must enrol a TOTP authenticator on first admin login before accessing the dashboard. Save the one-time recovery codes in a secure offline vault. Protected admin routes enforce MFA; never disable this middleware in production.

## Production deployment checklist
- Set APP_ENV=production, APP_DEBUG=false and a strong generated APP_KEY.
- Set SESSION_SECURE_COOKIE=true and serve the application only over HTTPS.
- Point the web root to the Laravel public directory only.
- Keep .env, source, vendor internals, private storage, logs and backups outside public web access.
- Set least-privilege writable permissions on storage and bootstrap/cache.
- Configure the database and run migrations from a trusted shell.
- Create the public storage symlink only after confirming the host supports it.
- Verify private storage cannot be fetched over HTTP.
- Test backup and restore.
- Obtain written hosting, payment-provider and jurisdictional approvals before activating the business.
