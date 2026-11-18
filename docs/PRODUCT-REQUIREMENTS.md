# Product Requirements

## Product outcome
A premium, responsive white-label agency operations platform that can be independently deployed and branded for each licensed customer. Admin must manage approved branding and operational settings from desktop, tablet or mobile.

## Roles
Public visitor; client; companion/provider; operator; verification/safeguarding reviewer; finance operator; administrator; platform maintainer. Use least privilege. Platform maintainers have no default access to customer business data.

## Modules
1. Public site: editorial homepage, configurable pages/navigation, destination and city directory, profile discovery, controlled filters for location, language, experience category and approved non-sensitive attributes, consented galleries, FAQs, legal pages, SEO, city guides, testimonials and enquiries.
2. Booking: manual and automatic modes configurable by admin; approval rules; request lifecycle; calendar; optional deposits; reminders; cancellation/rescheduling; audit history. Automatic mode fails closed if age/identity assurance, consent, availability, payment or safety prerequisites fail.
3. Client portal: account, booking history, favourites, membership entitlements, receipts, payment status, support, privacy and deletion requests. Membership may unlock private profiles/galleries and priority concierge benefits, with server-side entitlement checks on every request.
4. Companion portal: application/casting pipeline, onboarding, profile/media submission, availability, request review, verification status, consent records, safety preferences, pause/unpublish and withdrawal workflows.
5. Verification and safeguarding: independent age/identity assurance; evidence minimisation; restricted evidence vault; explicit media consent; consent versioning/withdrawal; access logs; retention/deletion; human-reviewed incidents; appeal/correction.
6. CRM and communications: enquiry inbox, assignment, pipeline, attribution, notes, reminders, provider adapters, retries and delivery logs. Do not claim end-to-end encryption if the server can read content.
7. Payments: adapter architecture; manual payments; optional BTC, USDT and USDC per supported network; card processors only after written underwriting; invoice, immutable instructions, ledger, reconciliation and refund/dispute states. No custody/escrow claim without approval.
8. Administration: dashboard, roles, brand/CMS, navigation, destinations, booking rules, payment configuration, feature flags, audit logs, reports, integrations, localisation and export controls.

## Discovery and membership controls
Destination, city and approved locality taxonomies must be managed as structured data. Profile cards may show a coarse availability state, but never private addresses or exact real-time location. Do not implement race/ethnicity/nationality-based profile filters. Membership content must use server-side authorisation and private media delivery; hiding a link is not access control.

## Dynamic configuration
Admin can change brand name, logo, favicon, colour tokens, typography, contact details, social links, SEO defaults, navigation, homepage sections, legal links and approved page content. Support draft, preview, publish, rollback and audit history. Payment controls include a global switch and independent switches for crypto, cards, manual methods, assets, networks and processors. Each customer deployment has independent secrets, domains, database, storage and credentials.

## Markets
Initial target markets: Mexico (MX, MXN, Spanish), United Kingdom (GB, GBP, English) and Philippines (PH, PHP, English). Use verified country, currency, phone, timezone and language metadata. Market configuration is not proof of legality or operator authorisation.

## Crypto requirements
Configure BTC, USDT and USDC by network, with distinct receiving addresses and optional multiple addresses per asset/network. Validate address, network and token contract before activation. Snapshot asset, network, destination, token contract, expected amount, quote and expiry into each invoice. Verify on-chain server-side; screenshots and browser redirects are not proof. Use idempotency and replay protection. Never collect seed phrases or private keys. Trust Wallet is a wallet app, not a gateway or chain-verification service. Keep crypto disabled until technical, legal and operational approval.

## Non-functional requirements
OWASP ASVS 5.0-aligned verification; least privilege; MFA for privileged roles; secure sessions; audit logs; secure uploads; secrets management; tested backups; data minimisation; retention/deletion; WCAG 2.2 AA target; UTC storage with local timezone rendering; integer minor-unit money values; indexed search and pagination.

## Exclusions
No custody/escrow; no private-key handling; no automated age inference; no public identity documents; no unrestricted blacklist; no hidden surveillance; no guarantee against screenshots; no card processing before written approval; no production operation before legal review.

## Acceptance
A feature is not complete until permission boundaries, validation, failure states, audit events, tests, accessibility, privacy impact and operational documentation are addressed.
