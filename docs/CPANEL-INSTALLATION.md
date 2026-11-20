# cPanel installation guide

This guide covers secure deployment of a Talyvane release on cPanel shared hosting. cPanel providers differ in PHP configuration, document-root restrictions, filesystem permissions and open_basedir; confirm compatibility before purchasing or deploying.

## 1. Hosting requirements

Confirm that the plan provides:

- PHP 8.3 or later for the website runtime, with the extensions listed in HOSTING-COMPATIBILITY.md, including PDO MySQL and GD with WebP support.
- MySQL or a supported MariaDB version, a dedicated database/user and migration privileges.
- HTTPS for the installation domain.
- PHP access to a private application directory outside the domain document root.
- Writable private runtime directories and the ability to set owner-only permissions.
- PHP open_basedir and filesystem rules that permit the public front controller to include the private Laravel front controller.
- Sufficient disk quota, memory and upload limits.

Do not purchase a plan based only on the word cPanel. The installer performs runtime checks and rejects unsafe layouts.

## 2. Choose a tagged release

Use a tagged, tested release artifact from the project release process. Do not deploy directly from an unreviewed development branch.

The standalone release includes production vendor dependencies, compiled public assets, migrations, a private bootstrap directory and the cPanel front-controller templates. CI also publishes dedicated public-root ZIP bundles for the main domain and add-on domain; these contain only public assets, the hardened .htaccess file and the matching guarded index.php wrapper. Production hosting does not need Node.js or Composer for the browser-based first installation.

The browser installer is implemented on the feature/secure-browser-installer branch and remains a pre-release capability until PR #60 is merged, all required CI and package checks pass, and a tagged release is published. Do not deploy the feature branch or treat this guide as a released support commitment before that gate.

## 3. Create the database

In cPanel, open MySQL Database Wizard (or the provider's equivalent):

1. Create a dedicated database for this Talyvane deployment.
2. Create a dedicated database user with a unique, strong password.
3. Grant that user the privileges required by the application's migrations and runtime.
4. Record the provider-specific database host, database name and username (cPanel commonly prefixes database names and users).
5. Keep phpMyAdmin private to the hosting account; never expose it through the application.

Use a separate database and credentials for every customer deployment.

## 4. Keep the application outside the public document root

The complete Laravel application must remain outside the domain's public document root. Only public assets and a guarded front controller belong in the document root.

There are two supported layouts.

### Option A — direct Laravel public root

Use this when the provider allows the domain document root to point directly to the application's public/ directory:

    /home/CPANEL_USER/
      talyvane-app/             # complete private Laravel application
        app/
        bootstrap/
        config/
        database/
        install/
        public/
        storage/
        vendor/
        .env                    # created by the browser installer

Set the domain document root to /home/CPANEL_USER/talyvane-app/public.

### Option B — standard cPanel public_html layout

Use this when cPanel requires the domain document root to remain under public_html:

**Main domain**

- Private application root: /home/CPANEL_USER/talyvane-app
- Public document root: /home/CPANEL_USER/public_html
- Upload the matching talyvane-cpanel-main-public ZIP artifact to the home directory and extract its contents into public_html/.

**Add-on domain or subdomain**

- Private application root: /home/CPANEL_USER/talyvane-app
- Public document root: /home/CPANEL_USER/public_html/example.com
- Upload the matching talyvane-cpanel-addon-public ZIP artifact to the home directory and extract its contents into public_html/example.com/.

The wrappers include the private Laravel front controller, reject an application root inside the public document root and reject symlinked application roots/front controllers. Laravel resolves its public path to the actual document root so assets and installer preflight checks use the public directory.

In cPanel File Manager, enable Show Hidden Files before copying so the public .htaccess file is included. Back up any existing website before replacing its public files. For an existing main-domain website, prefer a new add-on domain or subdomain with its own document root.

Do not copy install/, .env, vendor/, storage/, bootstrap/, source code or backups into public_html. Do not weaken the wrapper's path checks. If PHP open_basedir or the hosting filesystem prevents including the private front controller, the host is incompatible.

cPanel's default behavior matters: the main domain's document root cannot be changed in the Domains interface, and add-on/subdomain roots are normally restricted to public_html unless the hosting administrator changes the WHM restriction. The reviewed wrappers support the safe public_html arrangement without requiring that WHM setting. See the [cPanel domain document-root documentation](https://docs.cpanel.net/cpanel/domains/domains/create-a-new-domain/) and [cPanel's document-root restriction setting](https://docs.cpanel.net/whm/server-configuration/tweak-settings/domains/).

## 5. First installation without SSH or Composer

This path is available only in a tagged release that has passed the installer release gate.

1. Upload the complete standalone release ZIP into /home/CPANEL_USER/ using cPanel File Manager and extract it there. The archive creates a top-level talyvane/ directory; rename that directory to talyvane-app so it matches the wrapper paths and the examples below.
2. Configure the public document root using Option A or Option B above. For Option B, also upload and extract the matching main-domain or add-on-domain public-root ZIP into the document root. Back up any existing public files first.
3. Confirm the domain has a valid TLS certificate and opens over HTTPS.
4. Visit the HTTPS domain. The private first-request bootstrap creates a deployment-unique application key, private runtime directories and a random installer access key. It does not run arbitrary commands.
5. In cPanel File Manager, open the private application root (not public_html), then open `storage/app/private/INSTALLER-ACCESS-KEY.txt`. Copy the complete key. The installer page never displays or embeds this secret; enter it only in the protected installer form. Do not paste it into a URL, ticket, email or screenshot.
6. Complete every hosting preflight check. Resolve any PHP extension, GD/WebP, HTTPS, document-root, symlink or permission failure before proceeding.
7. Enter the application URL, database credentials, SMTP credentials, Super Admin details and a distinct recovery email address.
7. The installer writes the production environment configuration, tests the database, runs the fixed migrations and sends separate verification codes to the Super Admin and recovery email addresses.
8. Enter both codes within the verification window. The installer creates the canonical Super Admin and durable installation lock.
9. The success screen displays the actual Super Admin sign-in URL and generated initial password exactly once. Store the password securely, sign in, change it immediately and complete mandatory MFA enrollment before privileged administration.
10. The installer attempts to remove its private install/ directory before showing the success screen. If it reports that automatic removal failed, use cPanel File Manager to delete the entire talyvane-app/install/ directory immediately. The durable installation lock blocks installer routes even if the directory remains.

The browser installer session expires after 15 minutes. Only one pending verification flow can modify deployment configuration at a time. Credentials are not sent by email or placed in URLs. Never share the initial password, recovery key, database password or SMTP password with support personnel.

## 6. Installation with SSH/Terminal and Composer

If the host provides SSH/Terminal and Composer, use the private application root and the PHP binary/version selected for the domain:

    composer install --no-dev --prefer-dist --optimize-autoloader
    php artisan key:generate
    php artisan migrate --force
    php artisan storage:link
    php artisan optimize

Run key:generate only for a first installation when no valid key exists. Do not replace an existing production key casually; encrypted data may become unreadable.

Provision the deployment's single Super Admin from a trusted terminal:

    php artisan migrate --force
    php artisan talyvane:super-admin:create admin@example.com

The command requires SMTP, a strong password and a distinct recovery notification address. It sends separate verification codes to the primary and recovery addresses and requires both to be confirmed within five minutes. It creates the singleton Super Admin, stores only a hash of a new offline recovery key and displays that key once. Store it offline and separately from the server and database. Sign in immediately and complete mandatory TOTP MFA.

For an existing Super Admin without enrolled recovery credentials, run:

    php artisan talyvane:super-admin:enroll-recovery

To rotate a recovery mailbox and key later:

    php artisan talyvane:super-admin:rotate-recovery-contacts

If the authenticator is lost but the password and offline recovery key remain available, run:

    php artisan talyvane:super-admin:recover

Follow SUPER-ADMIN-RECOVERY.md. Do not place passwords, TOTP codes or recovery keys in shell arguments, deployment logs or tickets. Never create a public route that executes arbitrary Artisan commands.

## 7. Permissions and private storage

The PHP runtime must be able to write to storage/, its framework/cache/session/log subdirectories and bootstrap/cache/. The bootstrap restricts private runtime directories to owner-only permissions and the environment file and installer state files to owner-only file permissions.

Use the narrowest ownership and permissions the host supports. Never use 0777 as a general fix. Keep private identity evidence and unpublished media outside public storage. Publicly approved assets must be delivered through the application's authorized media path.

## 8. First-run verification

Before opening the site to customers:

1. Visit the HTTPS homepage and health endpoint.
2. Verify login, email verification and administrator MFA.
3. Confirm the Super Admin is provisioned through the approved bootstrap process.
4. Confirm a non-admin cannot access administration routes.
5. Confirm private storage, installer state and environment files are not downloadable.
6. Verify mail delivery, uploads, image re-encoding and WebP processing.
7. Run a test booking through the manual workflow in a non-production test account.
8. Verify every market and payment method remains disabled until separately reviewed and approved.
9. Verify backups and restoration.
10. Confirm the installer directory is absent or delete it manually as instructed; ensure debug mode is off.

## 9. Cron and scheduled tasks

If the release requires Laravel's scheduler, add a cPanel cron entry using the exact PHP CLI binary and private application path provided by the host. Standard Laravel scheduler invocation is once per minute:

    * * * * * /usr/local/bin/php /home/CPANEL_USER/talyvane-app/artisan schedule:run >> /dev/null 2>&1

The PHP path is provider-specific; discover it from the provider or cPanel Terminal. Do not copy the example path blindly. If cron is unavailable, disable or clearly mark scheduler-dependent features as unavailable.

The model-agency retention command is scheduled daily at 02:30 when approved retention periods and the scheduler approval flag are configured. Safeguarding primary-database content-key removal is scheduled daily at 02:45 only when the safeguarding retention schedule and encrypted-backup expiry policies are both explicitly approved and configured. Keep SAFEGUARDING_CASE_RETENTION_SCHEDULE_APPROVED=false and SAFEGUARDING_CASE_BACKUP_POLICY_APPROVED=false until legal review, backup expiry and restore procedures have been verified. Configure and verify this once-per-minute scheduler entry before enabling either retention schedule.

Safeguarding email dispatch is scheduled every five minutes only when SAFEGUARDING_NOTIFICATIONS_ENABLED=true and the configured mailer is a real transport (not log or array). Keep it disabled until SMTP delivery, SPF/DKIM/DMARC, recipient ownership and escalation procedures have been verified. Configure SAFEGUARDING_EMERGENCY_CONTACT_EMAILS as a comma-separated list of approved addresses. Critical and minor-safety cases enqueue notices to these contacts. Email contains only a case reference, category, severity and an authenticated internal link, not case narrative or applicant details. Outbox delivery is retried and must be monitored; it is not an emergency hotline or guaranteed response.

## 10. Go-live

- Confirm DNS and TLS.
- Confirm a verified backup exists outside the web root.
- Confirm legal, safeguarding, payment-provider and market approvals independently.
- Confirm operational contact and incident-response procedures.
- Record hosting environment details and deployment version.
- Do not enable production bookings or payment methods until all applicable release gates pass.
