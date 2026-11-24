# Single-ZIP cPanel customer package

The customer downloads one ZIP, uploads it to the domain's document root using cPanel File Manager, extracts it there, and visits the domain over HTTPS.

The package contains a small public bootstrap plus a hidden, HTTP-denied application payload. On the first request the bootstrap validates the document root, rejects unsafe archive paths and symbolic links, extracts the private Laravel application into `../talyvane-app` (one level above the document root), removes the temporary payload, and hands the request to Laravel. The existing Laravel browser installer then handles hosting preflight, database configuration, email verification, administrator provisioning, and installation locking.

## Hosting requirements

- PHP 8.3+ with the extensions required by Talyvane, including `zip` / `ZipArchive`.
- HTTPS enabled before opening the installer.
- The directory one level above the domain document root must be writable by the PHP account.
- The parent directory must not already contain `talyvane-app`.
- Apache/LiteSpeed-compatible `.htaccess` support.

The bootstrap never moves private application files into the document root. It fails closed if it cannot establish the private layout. It does not overwrite an existing application directory.

This is an engineering candidate, not a customer-approved release. It still requires CI execution, archive adversarial tests, complete browser-install acceptance, provider-specific cPanel/LiteSpeed validation, and a signed/tagged release before distribution.
