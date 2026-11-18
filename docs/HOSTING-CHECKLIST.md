# Hosting Compatibility Checklist

The client previously reported PHP 8.4+ and MySQL-compatible shared hosting. This is not yet a verified deployment environment.

## Required verification
- PHP 8.4 current security patch and required extensions: Ctype, cURL, DOM, Fileinfo, Filter, Hash, Mbstring, OpenSSL, PCRE, PDO, Session, Tokenizer and XML.
- Composer 2 available over SSH/terminal, or a controlled build-and-upload process.
- MySQL version and utf8mb4 support; database/user creation privileges.
- Web document root can point to the Laravel public directory.
- Application source, environment file, storage and backups can remain outside public web root.
- Writable storage and bootstrap/cache directories with least-privilege permissions.
- HTTPS and secure-cookie support; set SESSION_SECURE_COOKIE=true in production.
- Cron availability for scheduled tasks.
- Queue worker support or a documented synchronous/database alternative.
- Upload size/time limits and sufficient private storage.
- Hosting provider's written permission for the actual adult-oriented business model.
- Backup export and restore procedure tested.

## Deployment safety
Never expose the project root as the web root. Never place .env, source, vendor internals, logs, private uploads or backups under a publicly served directory. Do not add public setup, migration or Artisan endpoints. Disable debug mode in production. Rotate secrets after deployment and restrict database credentials.

## Current status
PHP 8.4 is within Laravel 13's supported PHP range. All other hosting capabilities remain unverified. Do not promise production compatibility until this checklist is evidenced.
