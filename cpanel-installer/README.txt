# Talyvane single-ZIP cPanel customer package

The customer downloads one ZIP, uploads it to the domain's document root using cPanel File Manager, extracts it there, and visits the domain over HTTPS.

The package contains a small public bootstrap, public assets, and a hidden HTTP-denied application payload. On the first request the bootstrap validates the document root, resolves the cPanel account home (or the parent of the outermost public_html directory), rejects unsafe archive paths and symbolic links, extracts the private Laravel application outside all public_html roots, removes the temporary payload, and hands the request to Laravel. The existing Laravel browser installer then handles hosting preflight, database configuration, email verification, administrator provisioning, and installation locking.

## Hosting requirements

- PHP 8.3+ with the extensions required by Talyvane, including zip / ZipArchive for the first request.
- HTTPS enabled before opening the installer.
- PHP must be able to write to the cPanel account home directory.
- The account must not already contain a talyvane-app directory at the resolved private location.
- Apache/LiteSpeed-compatible .htaccess support.
- The domain document root must be inside the cPanel account home directory.

The bootstrap never moves private application files into a public document root. It fails closed if it cannot establish the private layout. It does not overwrite an existing application directory.

If the host cannot expose a safe account-home path or the parent is not writable by PHP, the bootstrap displays a specific compatibility error instead of placing private files under public_html.

This is an engineering candidate, not a customer-approved release. It still requires latest-head CI, complete database/admin provisioning acceptance, provider-specific cPanel/LiteSpeed validation, and a signed/tagged release before distribution.
