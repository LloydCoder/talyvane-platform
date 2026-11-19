# Product Requirements

## Product outcome
A premium, responsive white-label agency operations platform that can be independently deployed and branded for each licensed customer. Admin must manage approved branding and operational settings from desktop, tablet or mobile. The product is globally extensible by default; no initial customer-market list limits the platform's country, currency, language or locale model.

## Roles
Public visitor; client; companion/provider; operator; verification/safeguarding reviewer; finance operator; administrator; platform maintainer. Use least privilege. Platform maintainers have no default access to customer business data.

## Modules
1. Public site: editorial homepage, configurable pages/navigation, destination and city directory, profile discovery, controlled filters for location, language, experience category and approved non-sensitive attributes, consented galleries, FAQs, legal pages, SEO, city guides, testimonials and enquiries.
2. Booking: manual and automatic modes configurable by admin; approval rules; request lifecycle; destination-local scheduling stored in UTC; profile opt-in for automatic booking; companion-managed weekly availability; deposit verification gate; overlap prevention; client and companion decision workflows. Cancellation/rescheduling, calendar sync and notifications remain planned. Automatic mode fails closed if age assurance, current consent, explicit companion opt-in, availability or configured deposit prerequisites fail.
3. Client portal: account, booking history, favourites, membership entitlements, receipts, payment status, support, privacy and deletion requests. Membership may unlock private profiles/galleries and priority concierge benefits, with server-side entitlement checks on every request.
4. Companion portal: CLI-provisioned account onboarding, linked profiles, booking request decisions, explicit automatic-booking opt-in/out, publication-consent withdrawal and profile suspension. Application/casting pipeline, profile/media editing, availability and safety preferences remain planned.
5. Verification and safeguarding: independent age/identity assurance; evidence minimisation; restricted evidence vault; explicit media consent; consent versioning/withdrawal; access logs; retention/deletion; human-reviewed incidents; appeal/correction.
6. CRM and communications: enquiry inbox, assignment, pipeline, attribution, notes, reminders, provider adapters, retries and delivery logs. Do not claim end-to-end encryption if the server can read content.
7. Payments: adapter architecture; manual payments; optional BTC, USDT and USDC per supported network; card processors only after written underwriting; invoice, immutable instructions, ledger, reconciliation and refund/dispute states. No custody/escrow claim without approval.
8. Administration: dashboard, roles, brand/CMS, navigation, destinations, booking rules, payment configuration, feature flags, audit logs, reports, integrations, localization and export controls.
9. Globalization: ISO country/currency reference data; market policy; currency and localized price configuration; language/locale preferences; IANA time zones; tax adapters; exchange-rate snapshots; global reporting.

## Discovery and membership controls
Destination, city and approved locality taxonomies must be managed as structured data. Profile cards may show a coarse availability state, but never private addresses or exact real-time location. Do not implement race/ethnicity/nationality-based profile filters. Membership content must use server-side authorisation and private media delivery; hiding a link is not access control.

## Dynamic configuration
Admin can change brand name, logo, favicon, colour tokens, typography, contact details, social links, SEO defaults, navigation, homepage sections, legal links and approved page content. Support draft, preview, publish, rollback and audit history. Payment controls include a global switch and independent switches for crypto, cards, manual methods, assets, networks and processors. Each customer deployment has independent secrets, domains, database, storage and credentials.

## Global market and localization requirements
Talyvane must not ship with a hardcoded finite country or currency allowlist. The system must support a versioned ISO 3166-1 country/territory dataset and ISO 4217 currency dataset, with operator-controlled enablement per deployment. New valid country/currency records must be importable without domain-code changes.

Country/territory, currency, locale/language, IANA time zone, market/legal policy, tax treatment and payment rail are independent dimensions. Customer and visitor preferences must not silently alter a transaction's agreed currency. USD is only the initial default reporting currency; each deployment may select another base currency. GBP, MXN and PHP may be enabled for the first customer, but are neither privileged nor exhaustive.

Support market-specific price overrides and optional exchange-rate-derived quotes. Every quote must snapshot the original amount/currency, target amount/currency where converted, market, rate, rate source/time/expiry, tax treatment, fees and policy version. Booking acceptance locks the quote; changes require explicit re-acceptance. Preserve original currency and amount for payments/refunds and never rewrite historical transactions when rates change.

Store money using integer minor units where appropriate and decimal-safe arithmetic for rates and calculations; never use binary floating point for money. Honor currency-specific precision, including zero- and three-decimal currencies. Store UTC instants and render using explicit IANA time zones and locale. Keep language, locale, currency and time-zone preferences separate.

Country enablement is not legal authorization. Country-level availability, services, pricing, tax and payment features require deployment-specific review and server-side policy enforcement. Fail closed for unknown, disabled, expired or unapproved market policy. Do not use IP geolocation as sole proof of residence or eligibility. Tax calculation and collection remain disabled until qualified rules/provider and merchant obligations are configured.

## Crypto requirements
Configure BTC, USDT and USDC by network, with distinct receiving addresses and optional multiple addresses per asset/network. Validate address, network and token contract before activation. Snapshot asset, network, destination, token contract, expected amount, quote and expiry into each invoice. Verify on-chain server-side; screenshots and browser redirects are not proof. Use idempotency and replay protection. Never collect seed phrases or private keys. Trust Wallet is a wallet app, not a gateway or chain-verification service. Keep crypto disabled until technical, legal and operational approval.

## Non-functional requirements
OWASP ASVS 5.0-aligned verification; least privilege; MFA for privileged roles; secure sessions; audit logs; secure uploads; secrets management; tested backups; data minimisation; retention/deletion; WCAG 2.2 AA target; UTC storage with local timezone rendering; integer minor-unit money values; indexed search and pagination.

## Exclusions
No custody/escrow; no private-key handling; no automated age inference; no public identity documents; no unrestricted blacklist; no hidden surveillance; no guarantee against screenshots; no card processing before written approval; no production operation before legal review.

## Acceptance
A feature is not complete until permission boundaries, validation, failure states, audit events, tests, accessibility, privacy impact and operational documentation are addressed. TGLS acceptance criteria are defined in docs/TGLS.md.
