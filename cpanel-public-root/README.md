# cPanel public-root front controllers

Use these wrappers only when the hosting provider requires the domain document root to remain under public_html. Extract the release archive in the account home directory, then rename its top-level talyvane/ directory to talyvane-app. Keep the full Talyvane application directory outside the domain document root.

## Main domain layout

- Private application: /home/CPANEL_USER/talyvane-app
- Public document root: /home/CPANEL_USER/public_html
- Copy the contents of talyvane-app/public/ into public_html/.
- Replace public_html/index.php with main-domain/index.php from this directory.

## Add-on domain or subdomain layout

- Private application: /home/CPANEL_USER/talyvane-app
- Public document root: /home/CPANEL_USER/public_html/example.com
- Copy the contents of talyvane-app/public/ into public_html/example.com/.
- Replace public_html/example.com/index.php with addon-domain/index.php from this directory.

The wrapper resolves the private application root, rejects a root inside the public document root, rejects symlinked application roots/front controllers, and includes only the private Laravel public front controller. Laravel then sets its public path to the actual web document root so assets and installer preflight checks use the public directory.

In cPanel File Manager, enable Show Hidden Files before copying so the public .htaccess file is included. Do not copy install/, .env, vendor/, storage/, bootstrap/, or any other private application files into public_html.

Back up any existing website before replacing its public files. For an existing main-domain website, prefer a new add-on domain or subdomain with its own document root. Do not weaken or remove the wrapper's path checks.
