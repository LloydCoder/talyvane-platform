# Talyvane Globalization and Localization Standard (TGLS)

**Status:** Architecture standard — required for all new modules and customer deployments  
**Scope:** Globalization, localization, markets, currencies, pricing, tax configuration and financial records

## 1. Product mandate

Talyvane is globally extensible by default. No country, currency, locale, language, time zone or payment market may be hardcoded as the complete supported-world list. Initial customer markets are configuration, not product boundaries. A market being technically configurable does not establish that a service is lawful or approved there.

## 2. Independent dimensions

Do not collapse these concepts into a single country or market setting:

- **Country/territory:** ISO 3166-1 alpha-2/alpha-3 identity and deployment-specific availability.
- **Currency:** ISO 4217 code, name, symbol, numeric code and minor-unit precision.
- **Locale/language:** BCP 47 language tag and locale-specific formatting/content.
- **Time zone:** IANA time-zone identifier. Persist instants in UTC; retain the relevant zone snapshot for bookings and display.
- **Market policy:** Per-country operational, legal-review, feature, pricing, tax and payment configuration.
- **Payment rail/asset:** Fiat processor or crypto asset/network, separate from the price currency.

A user's display preference must not silently change a transaction's contractual currency.

## 3. Currency and money

- Use ISO 4217 three-letter codes; validate against a maintained, versioned ISO currency reference dataset.
- Store monetary values as integer minor units where a currency has a defined minor unit. For calculations requiring fractional rates, use decimal arithmetic, never binary floating point.
- Store the currency code with every amount. Never infer currency from a symbol such as USD or dollar sign.
- Support currencies with zero, two, three or other defined minor-unit precision; do not assume every currency has two decimal places.
- Maintain one configurable reporting/base currency per deployment (USD is the initial default, not a universal invariant).
- Preserve the original amount and currency for invoices, bookings, payments, refunds and ledger entries.
- Store exchange-rate source, pair, precision, timestamp, expiry and the rate actually used. A transaction takes an immutable rate snapshot.
- Do not rewrite historical transactions when rates or currency metadata change.
- Market-specific prices are explicit overrides. Automatic conversion is a display/quoting option, not a substitute for a merchant's price policy.
- Define rounding mode and stage (line, tax, invoice total) explicitly. Reconcile at the payment rail's supported precision.

## 4. Country and market policy

Each deployment can configure every ISO country/territory record independently:
- enabled/disabled and public availability;
- legal-review state, reviewer, jurisdiction scope and review date;
- permitted service categories and booking/payment feature flags;
- supported languages, locales, currencies and time zones;
- phone/address formatting metadata;
- tax regime references and tax calculation adapter;
- payment provider and payment rail eligibility;
- market-specific pricing and content.

Fail closed when a country or required policy is unknown, disabled, expired or awaiting legal review. Never infer legality from a country being present in the reference dataset.

## 5. Localization

- Store translatable content by locale with an explicit fallback chain.
- Keep locale, currency and time zone as separate user preferences.
- Render dates/times in the user's selected locale and applicable IANA time zone; store event instants in UTC.
- Normalize phone numbers to E.164 when possible while retaining the original input where justified.
- Use CLDR/ICU-backed formatting where the runtime supports it; provide deterministic fallbacks and test them.
- Make language selection explicit; do not infer sensitive identity attributes.

## 6. Pricing, checkout and accounting

- A quote records price basis, currency, market, exchange-rate snapshot (if converted), tax treatment, fees, expiry and policy version.
- Booking acceptance locks the quoted amount and currency. Changes require a new quote and explicit customer acceptance.
- A payment asset/network is not the same thing as the price currency. Example: a GBP quote may be paid in a configured USDT network only after a valid, expiring conversion quote.
- Store observed/settled payment amounts and assets separately from the fiat-equivalent amount.
- Refunds use the original payment rail and currency where legally and technically possible; disclose exceptions before payment.
- Consolidated reports convert from immutable original records using a named reporting policy and rate source.
- Tax is jurisdiction-specific. Do not calculate or collect tax until a qualified provider/rule set and merchant obligations are configured.

## 7. Security, privacy and compliance

- Country/market selection is untrusted input. Resolve and validate server-side; never trust a browser-supplied country to bypass restrictions.
- Do not use IP geolocation as the sole proof of residence, location or legal eligibility.
- Apply market policy before profile discovery, booking, membership access and payment initiation.
- Audit changes to currencies, rates, prices, tax settings, country enablement and compliance status.
- Keep provider credentials and signing secrets outside the database or encrypted at rest; never store wallet seed phrases/private keys.
- Minimize location and identity data. No exact real-time location exposure.
- Obtain qualified legal and tax advice for the operator entity and every market in which services are offered.

## 8. Extensibility and deployment isolation

Each independently licensed installation owns its country/currency reference data version, market policies, base currency, price lists, exchange-rate provider configuration, tax adapters, secrets and financial records. No cross-customer financial data plane is required. Reference data may be updated through signed/versioned application releases or an operator-approved import process.

## 9. Acceptance criteria

A release claiming TGLS compliance must demonstrate:
1. no hardcoded finite country/currency allowlist in domain logic;
2. ISO currency code and precision validation;
3. correct zero-, two- and three-decimal currency handling;
4. explicit base/reporting currency configuration;
5. immutable quote and exchange-rate snapshots;
6. no floating-point money arithmetic;
7. independent locale, time-zone and currency preferences;
8. fail-closed market policy checks at server-side action boundaries;
9. audit history for market and financial configuration;
10. tests for currency precision, rounding, rate expiry, disabled markets and transaction immutability.

## 10. Initial defaults

USD is the initial default reporting currency for new installations. GBP, MXN and PHP may be configured as initial local price/display currencies for the first customer, but they are not privileged or exhaustive. The customer deployment can select another base currency and enable other valid currencies without a code change.
