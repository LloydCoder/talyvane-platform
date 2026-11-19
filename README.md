# Talyvane

**A white-label luxury agency operations platform for independently branded commercial deployments.**

Talyvane is being developed for premium agency operations: public discovery, profiles, booking workflows, client and companion portals, memberships, configurable payments, CRM, verification, privacy controls and administration.

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
- docs/TGLS.md (globalization and localization standard)
- docs/IMAGE-LICENSING.md (stock image provenance, licensing and media policy)
- docs/decisions/ADR-0008-globalization.md
- docs/SECURITY-PRIVACY.md
- docs/COMPLIANCE-RELEASE-GATES.md
- docs/ROADMAP.md
- docs/COMPETITIVE-REFERENCE.md
- docs/LOCAL-DEVELOPMENT.md
- docs/BOOKING.md
- docs/PAYMENTS.md
- docs/decisions/

The public homepage and destination discovery now use a curated Pexels hospitality photography set. Destination cover images can be uploaded and replaced in the admin area with type, size, dimension and rights-declaration checks. Stock fallbacks are explicitly labelled as illustrative, never as location-specific evidence. Companion profile photographs are deliberately not populated from stock images; they must come from the actual profile owner with documented consent. See docs/IMAGE-LICENSING.md.

Globalization foundations now include deployment-local country-market, currency, exchange-rate and localized-price persistence; decimal-safe money primitives; and administrator configuration for base reporting currency, locale, time zone, currency registration/enablement and country-market registration. This is an architectural and administration foundation, not a complete global rollout: ISO reference-data imports, exchange-rate providers, localized price management, market-policy enforcement, full localization and jurisdictional tax adapters remain planned and must not be represented as complete.

Implemented foundations include public destination/profile discovery, role-separated client and companion authentication, email verification, mandatory administrator TOTP MFA with one-time recovery codes, enquiry intake/CRM, consent-gated profile publication, companion consent controls, manual booking requests and configurable automatic booking eligibility with deposit, weekly availability and overlap gates; disabled-by-default payment configuration for BTC, USDT and USDC receiving addresses. Current application stack: Laravel 13, PHP 8.3–8.5, MySQL, Blade and custom responsive CSS. Composer dependencies are resolved by CI; a committed lockfile is still required before production. The client's PHP 8.4 host is version-compatible, subject to extension and hosting verification.

## Safeguarding
Talyvane must not be used to facilitate trafficking, coercion, exploitation, non-consensual content or any activity involving minors. No production deployment should proceed without verified adult-only access controls, documented informed consent, lawful operating arrangements, privacy safeguards, appropriate payment/provider approval and independent legal review for each relevant jurisdiction.

Third-party frameworks, packages, fonts and media retain their own licences. Maintain a dependency inventory and required notices. Intended rights holder: Tinlance Limited, subject to chain-of-title, contributor-assignment and third-party-rights verification.

Talyvane is under development. Feature completeness, security posture, legal compliance, payment support and hosting compatibility must be verified for each release and deployment.
