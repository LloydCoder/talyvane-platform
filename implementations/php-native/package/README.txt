TALYVANE — NATIVE PHP cPANEL INSTALLER

1. Create/select a dedicated domain document root in cPanel.
2. Upload this ZIP using File Manager and extract it in that document root.
3. Enable HTTPS and visit https://your-domain.example/.
4. Follow the browser setup wizard. You need a dedicated MySQL database/user, working SMTP credentials and two distinct administrator email addresses.

No SSH, Composer, npm, Git, Docker, terminal access or manual source edits are required.

REQUIREMENTS
- PHP 8.3+; PDO, PDO MySQL, OpenSSL, mbstring, fileinfo, JSON, ZIP and GD with WebP support.
- HTTPS and cookie-backed sessions.
- MySQL 8.0+ or a compatible supported MariaDB release.
- Writable account home outside the public document root.
- SMTP with TLS or implicit TLS.

PRIVATE FILES
The bootstrap moves the application payload to owner-only private storage outside the public document root and removes the temporary payload ZIP. Configuration, database credentials, logs, backups, uploads and installer state remain private. If private storage cannot be verified, installation stops. Never use 0777 permissions or place private files in public_html.

RELEASE STATUS
This native-PHP package is an acceptance candidate, not a production release. Use only on a non-production staging domain until feature parity, browser, security, backup/restore, upgrade and actual-host checks are signed off.
