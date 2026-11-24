# cPanel customer installation

This is the customer-facing installation procedure for the single-ZIP cPanel package. It replaces the older multi-archive/public-root bundle procedure. The ZIP must come from an approved, versioned Talyvane release; a GitHub source archive or CI test artifact is not a customer installer.

## Hosting requirements

Confirm these capabilities with the hosting provider before purchase or deployment:

- PHP 8.3 or later for the domain's **web** PHP handler (not merely CLI PHP).
- PHP extensions: ZIP/ZipArchive, PDO MySQL, GD with WebP, cURL, Mbstring, OpenSSL, DOM, Fileinfo and XML, plus Laravel's standard requirements.
- MySQL or supported MariaDB, with a dedicated empty database and a database user allowed to create/alter tables.
- HTTPS and Apache or LiteSpeed-compatible `.htaccess` rewrite support.
- PHP can create a private application directory in the cPanel account home, outside every public document root.
- Sufficient disk quota and upload/extraction limits for the release archive.

cPanel plans differ. A plan that prevents PHP from writing outside the document root or including the private Laravel front controller is not compatible with this package. Do not disable WAF or weaken filesystem permissions to force compatibility.

## Before uploading

1. Create a dedicated domain/subdomain and confirm its document root in cPanel **Domains**.
2. The document root must be a separate public directory, not the Laravel project root and not a directory nested inside an existing private `talyvane-app`.
3. In the cPanel account home, confirm that a `talyvane-app` directory does not already exist. The installer intentionally refuses to overwrite an existing directory. Back up and investigate any existing directory; do not delete or rename it without confirming what it contains.
4. Create a new, empty MySQL/MariaDB database and a dedicated user. Do not use an existing application database.
5. Enable HTTPS and verify the domain opens without a certificate warning.

For a typical cPanel subdomain, the document root is something like `/home/CPANEL_USER/public_html/talyvane.example.com`; the private application is placed at `/home/CPANEL_USER/talyvane-app`. The exact public path is assigned by cPanel. The bootstrap resolves the account home and refuses unsafe or ambiguous layouts.

## Install using File Manager and a browser

1. Download the approved Talyvane cPanel customer ZIP and its matching SHA-256 checksum from the same release.
2. Open cPanel **File Manager** and navigate to the domain's exact document root.
3. Enable **Show Hidden Files** in File Manager settings.
4. Upload the one customer ZIP into that document root and use File Manager's **Extract** action there.
5. Confirm the extracted root contains `index.php`, `.htaccess`, `README.txt`, `RELEASE-MANIFEST.txt`, public assets and the hidden `.talyvane-payload.zip`. Do not extract the hidden payload separately or move individual files.
6. Open the domain over HTTPS in a browser. The bootstrap validates the archive and hosting layout, extracts the private Laravel application outside the document root, removes the temporary payload, and forwards the request into Laravel. An uninstalled application redirects to the browser wizard at `/install`.
7. If the bootstrap returns an error, stop and follow the exact message. Do not change permissions to 0777, move private application files into the public directory, disable WAF, or repeatedly re-extract over existing files.

The bootstrap uses the private directory `talyvane-app` in the account home. It fails closed if that directory already exists or if the domain document root is nested inside it. This is intentional protection against overwriting an existing application or exposing private source code.

## Complete the browser wizard

1. In File Manager, open the private account-home directory `talyvane-app/storage/app/private/INSTALLER-ACCESS-KEY.txt`. Copy the one-time key. Never put it in a URL, email, screenshot or support ticket.
2. Return to `https://your-domain.example/install` and enter the key.
3. Complete hosting preflight. All checks must pass before configuration can be submitted.
4. Enter the exact HTTPS application URL, database connection details, SMTP connection details, Super Admin name/email and a separate recovery email address.
5. The wizard tests the database and applies the fixed Laravel migrations. It rejects unrelated databases, completed deployments and databases with existing user records.
6. The wizard sends separate six-digit verification codes to the Super Admin email and recovery email. Both addresses must be different and both codes must be verified within five minutes. Verification is limited to five attempts.
7. After successful verification, Talyvane creates the single Super Admin, commits the durable installation lock and displays the initial password through a one-time handoff.
8. Store the password securely, sign in, change it when prompted and complete mandatory MFA enrollment. Store MFA recovery codes and the Super Admin recovery key separately and securely.
9. Confirm the installer reports successful cleanup. If cleanup is incomplete, follow its explicit instructions in File Manager. The durable installation lock must remain active even if cleanup needs manual completion.

## Verify before opening to customers

- Super Admin can sign in and complete MFA; a subsequent login requires MFA.
- Installer routes are locked and the one-time access key is consumed.
- HTTPS, secure cookies, SMTP delivery, database operations and public assets work.
- Requests for `.env`, `vendor/`, `app/`, `storage/`, private payloads, logs and backups are denied.
- Public uploads and media are stored and served using the application's authorization controls.
- Privacy, consent, safeguarding, retention, notification, payment and market settings remain disabled until separately reviewed and approved.

## Troubleshooting

- **Standalone PHP file returns 403 while static files work:** this is a web-server/PHP-handler/access-control issue before Laravel executes. Ask the provider for the domain's access/error log entry, PHP-FPM/LSPHP handler status and effective file ownership. Do not assume it is an application bug and do not disable WAF.
- **The installer returns HTTP 409 about an existing `talyvane-app`:** stop. The installer will not overwrite it. Back up and identify the existing directory before planning a clean deployment.
- **The installer reports a nested document root:** the domain is pointing inside the private application directory. Configure a separate public document root before using this package. Never point the domain at the private project root.
- **ZIP extension missing:** enable PHP ZIP/ZipArchive for the domain's web PHP version.
- **Private account directory is not writable:** ask the provider to confirm the PHP process identity and safe write access outside the document root. Never use 0777.
- **Installer preflight is blocked:** resolve each named requirement; do not bypass the check.
- **Verification email does not arrive:** verify SMTP host, port, TLS mode, credentials, sender authorization, outbound SMTP policy and spam/quarantine. The installer does not create the Super Admin until both codes are verified.

Preserve the exact URL, timestamp, response and provider error-log entry when reporting a failure. Do not repeatedly upload archives over an existing installation.

## Backup, restore and upgrades

Before production use, back up the database, private uploads/user-generated files, deployment configuration and encryption keys. Keep encrypted backups outside the public document root and preferably outside the hosting account. Perform a restore into a separate staging account/database and verify application boot, authentication, private files and media. A backup that has never been restored is not a verified recovery plan.

Do not extract a new release over a live application. A production upgrade package must specify compatible source/database versions, a pre-upgrade backup, migration behavior, maintenance window, rollback boundary and post-upgrade checks. Database migrations may not be reversible; rollback may require restoring the matching pre-upgrade database and files.

## Release status

A merge, green CI run or CI artifact does not equal a customer release or provider certification. As of 24 November 2026, the single-ZIP installer implementation is merged and its repository CI checks pass, but clean end-to-end MySQL/SMTP/browser acceptance, failure-recovery drills, backup/restore and upgrade/rollback drills, actual target-cPanel validation and a signed/tagged customer release remain release gates. Distribute only a versioned customer ZIP explicitly approved after those gates pass.
