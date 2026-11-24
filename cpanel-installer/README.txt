TALYVANE — SINGLE-ZIP CPANEL INSTALLER
=======================================

CUSTOMER INSTALLATION GUIDE

This is an owner-only cPanel acceptance candidate designed for a non-production staging domain using cPanel File Manager and a web browser. Upload only the inner Talyvane-cPanel-<commit>.zip supplied with its matching SHA-256 checksum. Do not upload the outer GitHub Actions artifact archive or the GitHub source-code ZIP. This candidate is not a production release and must not be used with customer production data.

BEFORE YOU UPLOAD

Confirm with your hosting provider that the domain has:
- PHP 8.3 or later selected for this domain in cPanel MultiPHP Manager.
- PHP extensions required by Talyvane, including ZIP / ZipArchive for the first request, GD with WebP support, PDO MySQL, cURL, Mbstring, OpenSSL, DOM, Fileinfo, XML, and the other Laravel-required extensions.
- HTTPS enabled and working for the domain.
- MySQL or a supported MariaDB database.
- Permission for the domain's PHP process to create a private application directory in the cPanel account home, outside the public document root.
- Apache or LiteSpeed-compatible .htaccess and rewrite support.

The exact PHP version and extensions used by the web request matter. CLI PHP settings do not prove that the domain's web PHP handler has the same configuration.

STEP 1 — CREATE A DATABASE

In cPanel, open MySQL Databases (or the provider's database wizard):
1. Create a new database for this Talyvane installation.
2. Create a dedicated database user with a strong, unique password.
3. Assign that user to the new database with the privileges required to create and alter tables.
4. Record the database host, database name, username and password.

Use a new, empty database. Do not use another live application's database.

STEP 2 — UPLOAD AND EXTRACT THE ONE ZIP

1. Open cPanel File Manager.
2. Open the document root assigned to the domain you are installing (often public_html, but addon domains may use a different directory).
3. Upload the supplied inner Talyvane-cPanel-<commit>.zip acceptance candidate into that directory.
4. Use File Manager's Extract action on that ZIP in the document root.
5. Confirm that index.php, .htaccess, README.txt, RELEASE-MANIFEST.txt, the css directory and the hidden .talyvane-payload.zip are present.
6. Do not move the private payload into a public folder, rename it, or extract it manually.
7. Do not upload the outer GitHub Actions artifact archive or GitHub source archive; upload only the inner Talyvane-cPanel-<commit>.zip. Use this candidate only for controlled staging acceptance.

STEP 3 — START THE BROWSER INSTALLER

1. Open https://your-domain.example/ in a browser.
2. The bootstrap checks the package and hosting layout, extracts the private Laravel application into the account area outside public web roots, removes the temporary payload and opens the Laravel installer.
3. If the bootstrap reports an unsupported layout or permissions error, stop. Do not move the application into the public document root and do not set permissions to 0777.

The private application is automatically placed in a per-domain directory beneath the cPanel account's private .talyvane/apps directory. The installer resolves the account home and generates a unique directory for each domain. It does not require you to change the document root, create directories, or edit paths.

STEP 4 — RETRIEVE THE PRIVATE INSTALLER KEY

The installer access key is deliberately not displayed in the browser and is not placed in a URL.

1. In cPanel File Manager, open the account's Home directory (not the domain's public document root).
2. Use File Manager's Search function to find INSTALLER-ACCESS-KEY.txt within the account home. Enable Show Hidden Files if required.
3. Open the matching file inside .talyvane/apps/<domain-specific-directory>/talyvane-app/storage/app/private and copy its complete contents.
4. Return to the HTTPS installer page and paste the key into the Private installer access key field.

The hidden private directory is generated automatically and is unique to this domain. Do not create or rename it manually.

If the key file does not exist, the bootstrap may have stopped before private extraction; read its error and resolve that first.

Never share the key, put it in a URL, email it, or save it inside the public document root.

STEP 5 — COMPLETE THE INSTALLER

Enter:
- Deployment name and the exact HTTPS domain URL.
- Database host, port, database name, username and password.
- SMTP security mode, host, port, username, password and sender address/name.
- Initial Super Admin name and email address.
- A separate recovery email address that you control.

The two administrator/recovery email addresses must be different and both must receive their own six-digit verification code. Codes expire after five minutes. Check spam/quarantine and confirm SMTP delivery before retrying. The installer will not create the initial Super Admin until both codes are verified.

The installer checks the database before migrating. A new installation must use an empty database. A recognised interrupted Talyvane migration may be resumed; an unrelated or already-administered database must be rejected.

STEP 6 — SECURE THE FIRST ADMINISTRATOR LOGIN

After successful verification:
1. The installer creates the initial Super Admin and displays a one-time initial password handoff.
2. Copy the password to a secure password manager immediately. The handoff is not a permanent password-recovery page and is not cacheable.
3. Open the administrator login link.
4. Sign in and change the initial password when prompted.
5. Complete mandatory multi-factor authentication (MFA) enrollment.
6. Store the generated MFA recovery codes securely and offline.
7. Complete the Super Admin recovery-key handoff and store that key separately from the account password and MFA recovery codes.

Do not leave the initial password or recovery material in a shared computer, public folder, screenshot, email draft or support ticket.

STEP 7 — VERIFY THE INSTALLATION

Before using the platform:
- Confirm the administrator can sign in with the new password and MFA.
- Confirm a second login requires an MFA challenge.
- Confirm the installation is locked and the installer access key is consumed.
- Confirm the temporary install directory has been removed where the cleanup checks report success.
- Confirm HTTPS, secure cookies, email delivery and database operations work.
- Confirm requests for .env, vendor files, private payloads, logs and backups are denied.
- Configure the platform's privacy, consent, safeguarding, notification, retention and other operational policies before enabling the related features.

BACKUP AND RESTORE

A cPanel account backup and an application-level backup are not interchangeable. Protect the database and private application data, including uploads and user-generated files, as well as the deployment configuration and encryption keys. Store backups outside the public document root and preferably outside the hosting account. Encrypt backups in transit and at rest, restrict access, and define retention and deletion rules.

Before production use, perform a restore into a separate staging account/database and verify that the restored application can boot, authenticate, access its private files and serve expected media. A backup that has never been restored is not a verified recovery plan. Do not overwrite a live installation during a restore drill.

UPGRADES AND ROLLBACK

Do not overwrite a live application directory by extracting a newer ZIP over it. A future approved upgrade package must include a version-specific migration plan, pre-upgrade backup, compatibility checks, maintenance procedure, rollback boundary and post-upgrade smoke tests. Database migrations may not be safely reversible; restore the verified pre-upgrade database and matching files when rollback requires it.

TROUBLESHOOTING

- PHP diagnostic returns 403 while static files work: this is a web-server/PHP-handler/access-control issue to investigate with the hosting provider's error log. The Laravel installer cannot cause a standalone PHP probe to return 403 before Laravel executes.
- ZIP extension missing: enable PHP ZIP / ZipArchive for the domain's web PHP version, then retry.
- Account directory is not writable: ask the provider to confirm the PHP handler's effective account identity and safe write access outside the document root. Never use 0777.
- Existing talyvane-app directory: it is preserved. The installer uses an isolated per-domain private path and does not overwrite an existing application.
- Preflight reports BLOCKED: resolve each named requirement before submitting configuration.
- Email codes do not arrive: verify SMTP host, port, TLS mode, credentials, sender authorization, outbound SMTP policy and spam/quarantine.
- HTTP 403/500 after upload: preserve the files and error response; record the exact URL, timestamp, PHP version/handler and provider error-log entry. Do not disable WAF or rewrite protections globally.

IMPORTANT RELEASE STATUS

This guide describes the intended customer workflow. A repository merge or green CI run is not, by itself, a customer release or hosting certification. Distribute only a signed/tagged, versioned customer package whose SHA-256 checksum matches the release, after the project owner has approved the release and the target hosting plan has passed a clean end-to-end installation, first-login/MFA, post-install lock, backup/restore and rollback acceptance.
