# Engineering Guide for Talyvane

## Stack
- Laravel 13, PHP 8.3+, MySQL.
- Blade and framework-native server rendering.
- Lightweight CSS and Alpine.js only where interaction needs it.
- PHPUnit 12 for automated tests.
- Keep the application a modular monolith until evidence justifies extraction.

## Required workflow
1. Read the relevant product requirement and architecture decision before changing a module.
2. Write or update a failing feature/unit test before implementing behaviour.
3. Run the focused test, implement the smallest correct change, then run the full suite.
4. Do not claim a test passed unless its command or CI result is available.
5. Keep migrations reversible and include validation, authorisation and failure-state tests.

## Security rules
- Authentication is not authorisation. Every protected operation needs a server-side policy or role check.
- All protected administrator routes require TOTP MFA. Keep secrets encrypted, recovery codes hashed and single-use, and enforce replay/rate-limit controls.
- Never trust browser state, model output, prompt text, tool output or user-controlled fields as execution authority.
- Never commit secrets, real customer data, private keys, wallet seeds, identity evidence or production environment files.
- Keep identity/age evidence separate from profile content; never expose legal IDs or private addresses.
- Use secure upload validation, private storage by default and explicit publication consent.
- Payment settlement must be verified server-side and idempotently. A screenshot or redirect is not proof.
- Never add public setup, migration or Artisan endpoints.
- Require human review for safeguarding and adverse decisions.

## Product boundaries
- Each licensed customer gets an independent deployment, database, storage, domain and secrets.
- Customer-specific brand assets and business data must not be embedded in reusable fixtures or shared demo data.
- The first public release is a proprietary source-available release; review LICENSE and preserve third-party notices.
- Do not market the repository as OSI-approved open source.
- Production release is blocked until host, payment-provider and jurisdictional approvals are documented.

## Quality
- Target WCAG 2.2 AA.
- Store timestamps in UTC and money in integer minor units plus ISO currency.
- Use explicit domain boundaries, small controllers and application services.
- Prefer framework primitives over unnecessary dependencies.
- Document material architecture changes as ADRs.
