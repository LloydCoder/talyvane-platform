# Talyvane

**A white-label luxury agency operations platform for independently branded commercial deployments.**

Talyvane is being developed for two distinct premium agency verticals: (1) adult luxury escort and elite companionship, activated only in legally approved markets; and (2) model agency representation, including applications, portfolios, casting, assignments and image usage rights. They may share core infrastructure but retain separate services, contracts, consent scopes, booking types, compliance gates and financial workflows. The platform also provides public discovery, client and talent portals, memberships, configurable payments, CRM, verification, privacy controls and administration.

## Product principles
- White-label by design: independently branded customer deployments.
- Configuration before custom code: brand identity, contact details, navigation, content and approved settings are managed through administration.
- Deployment isolation: each customer has its own domain, database, media, secrets and customer records.
- Modular operations: discovery, bookings, portals, CRM, payment adapters, verification, communications and reporting.
- Reusable core IP: customer data and brand assets remain separate from the reusable platform, subject to contract.
- Security and safeguarding by default: privacy, age assurance, informed consent, human review and jurisdiction-specific compliance are release gates.

## Repository status
This repository is public for visibility and evaluation. **Public does not mean open source or free for production use.** Covered materials first published under the current terms are proprietary and source-available under LICENSE, except where a file states otherwise.

Production use, commercial deployment, white-label operation, redistribution, sublicensing and resale require a separate written Commercial License from Tinlance Limited. The initial repository history included an Apache-2.0 licence. This update does not purport to revoke rights already granted for prior publications. Review LICENSE and obtain legal advice before relying on exclusivity claims.

See COMMERCIAL-LICENSING.md for the intended commercial model.

## Architecture and delivery
- docs/PRODUCT-REQUIREMENTS.md
- docs/ARCHITECTURE.md
- docs/decisions/ADR-0015-commercial-licensing-and-deployment.md (commercial licensing, editions, deployment authority and TLMS boundary)
- docs/TGLS.md (globalization and localization standard)
- docs/IMAGE-LICENSING.md (stock image provenance, licensing and media policy)
- docs/decisions/ADR-0008-globalization.md
- docs/decisions/ADR-0009-super-admin-control-plane.md
- docs/decisions/ADR-0014-secure-browser-installer.md
- docs/SECURITY-PRIVACY.md
- docs/SAFEGUARDING-OPERATIONS.md
- docs/COMPLIANCE-RELEASE-GATES.md
- docs/ROADMAP.md
- docs/NATIVE-PHP-PORT-STATUS.md (reconciled native implementation matrix, exact-head CI evidence and release blockers)
- docs/COMPETITIVE-REFERENCE.md
- docs/LOCAL-DEVELOPMENT.md
- docs/DEPENDENCY-MANAGEMENT.md
- docs/RELEASE-PACKAGING.md
- docs/SUPER-ADMIN-RECOVERY.md
- docs/HOSTING-COMPATIBILITY.md
- docs/CPANEL-INSTALLATION.md
- cpanel-public-root/README.md (safe cPanel public_html front-controller layouts)
- docs/SECURE-BROWSER-INSTALLER.md (approved browser installer security and acceptance specification)
- docs/DEPLOYMENT-OPERATIONS.md
- docs/BOOKING.md
- docs/PAYMENTS.md
- docs/decisions/

The public homepage and destination discovery now use a curated Unsplash hospitality photography set. Destination cover images can be uploaded and replaced in the admin area with type, size, dimension and rights-declaration checks. Stock fallbacks are explicitly labelled as illustrative, never as location-specific evidence. Companion profile photographs are deliberately not populated from stock images. Companion-owned submissions require rights and publication attestations, metadata-stripping image processing, private storage and MFA-protected agency review before delivery. Reviewers can classify approved images as public or members-only; members-only delivery requires a current, separate, versioned companion opt-in and active membership grant; grant/withdrawal evidence is retained and private media is excluded from public discovery queries. See docs/IMAGE-LICENSING.md, docs/PROFILE-MEDIA-GOVERNANCE.md and docs/CLIENT-MEMBERSHIP.md.

Globalization foundations include deployment-local country-market, currency, exchange-rate and localized-price persistence; decimal-safe money primitives; and administrator configuration for base reporting currency, locale, time zone, currency registration/enablement and country-market registration. Country markets fail closed until explicitly enabled with an evidence-backed compliance approval; public discovery, profile publication, media approval and booking confirmation enforce this gate. This is an architectural and administration foundation, not a complete global rollout: ISO reference-data imports, exchange-rate providers, localized price management, market-policy enforcement, full localization and jurisdictional tax adapters remain planned and must not be represented as complete.

Implemented foundations include public destination/profile discovery, role-separated client and companion authentication, email verification, mandatory administrator TOTP MFA with one-time recovery codes, enquiry intake/CRM, consent-gated profile publication, companion consent controls, manual booking requests and configurable automatic booking eligibility with deposit, weekly availability and overlap gates; disabled-by-default payment configuration for BTC, USDT and USDC receiving addresses. Current application stack: Laravel 13, PHP 8.3–8.5, MySQL, Blade and custom responsive CSS. The committed `composer.lock` fixes the complete dependency graph. CI and production use deterministic `composer install`, with Composer resolution pinned to PHP 8.3.0 and actual runtime platform checks across PHP 8.3, 8.4 and 8.5. The lockfile is mandatory: missing or inconsistent dependency state fails CI and must be repaired through a reviewed dependency change. The client's PHP 8.4 host is version-compatible, subject to extension and hosting verification. Profile media processing requires PHP GD with WebP support (`imagewebp`).

## Native PHP compatibility port

`implementations/php-native/` is a separate, framework-independent PHP application for constrained cPanel deployments. It has its own runtime, configuration, migrations, installer, private storage and deployment boundary; it does not replace or share runtime dependencies with the Laravel application. Laravel and the canonical product/security documents remain the behavioral reference until parity is formally accepted.

The native port branch now includes 42 ordered migrations and partial workflows for the hardened browser installer, role-separated portals, discovery, booking/rescheduling, companion availability, client membership entitlements, consent-checked private gallery, privacy-rights fulfilment and guarded anonymization, applicant-originated casting, execution-evidence-first model assignment acceptance/decline with idempotent response provenance, applicant-initiated purpose-specific image-licence consent withdrawal, model settlement, CRM response templates and encrypted delivery, external privacy-erasure evidence, safeguarding governance and notification dispatch. It remains a work in progress: see the [reconciled native PHP port status](docs/NATIVE-PHP-PORT-STATUS.md) and [feature-by-feature parity tracker](implementations/php-native/PORTING-TRACKER.md) for exact-head validation and remaining gates. CI passing for an individual slice is not a claim of complete feature parity, legal approval, provider certification or production readiness. The native application must not be released until all required tracker rows have complete tests, security review, documentation and target-host acceptance.

See [native PHP architecture](implementations/php-native/ARCHITECTURE.md) and [native PHP operator/developer guide](implementations/php-native/README.md).

## Safeguarding
Talyvane must not be used to facilitate trafficking, coercion, exploitation, non-consensual content or any activity involving minors. No production deployment should proceed without verified adult-only access controls, documented informed consent, lawful operating arrangements, privacy safeguards, appropriate payment/provider approval and independent legal review for each relevant jurisdiction.

Third-party frameworks, packages, fonts and media retain their own licences. Maintain a dependency inventory and required notices. Intended rights holder: Tinlance Limited, subject to chain-of-title, contributor-assignment and third-party-rights verification.

Talyvane is under development. Feature completeness, security posture, legal compliance, payment support and hosting compatibility must be verified for each release and deployment.


## cPanel shared-hosting target
Talyvane targets compatible cPanel shared-hosting plans with PHP 8.3+, required PHP extensions (including GD WebP), a supported MySQL/MariaDB version, HTTPS, safe private application storage and a document root serving only Laravel's `public/` directory. SSH, Composer, cron, Node.js, Redis and persistent workers are not assumed as runtime requirements. Provider-by-provider compatibility must be verified; cPanel alone does not guarantee compatibility. See the hosting compatibility, installation and deployment operations guides before selecting a host. The constrained no-SSH browser installer and first Super Admin provisioning were implemented in PR #62 (merge `df9d4abf3905ba94b115f3fd6e9523e5822e401d`). The one-download cPanel bootstrap candidate was added in PR #74 (merge `aae35c99dbaa1e0076cac06958c07b49b0f8c09a`) and passed Apache/PHP HTTPS plus OpenLiteSpeed/LSPHP 8.3 HTTPS package acceptance, the PHP 8.3/8.4/8.5 feature matrix and Semgrep on the tested PR head. This is repository-level implementation and CI evidence, not provider-specific live cPanel certification or a customer release; a signed/tagged release and real-host acceptance remain required.
