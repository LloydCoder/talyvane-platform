# Private installation bootstrap directory

This directory is part of the prebuilt Talyvane release and MUST remain outside the web document root.

- `bootstrap.php` is included by `public/index.php` only when present.
- It creates a deployment-unique application key and safe file-backed bootstrap defaults when the environment is not initialized.
- It does not accept HTTP input, configure a database, execute commands, run migrations or provision an administrator.
- The guided installer itself is served through constrained Laravel routes and application services; this directory is not a public PHP endpoint.
- The Apache rule denies direct access if a host is misconfigured, but this is defense in depth, not a substitute for a private application root.

After the installer has verified the deployment and committed its durable installation lock, it attempts to remove the known release-owned files in this directory. If it cannot safely remove the directory, the success screen instructs the operator to delete the entire `install/` directory immediately using cPanel File Manager. Installer routes remain blocked by the durable lock regardless of whether this directory remains.

Do not put customer uploads, environment files, database dumps, credentials, or arbitrary scripts in this directory.
