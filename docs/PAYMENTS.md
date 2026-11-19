# Payment Architecture and Release Status

## Receiving model

The requested operating model is direct-to-wallet. The business controls its receiving wallet and the customer sends funds to the configured public address. Trust Wallet is a wallet application, not a payment processor or transaction-verification service.

Talyvane stores public receiving addresses only. It must never collect or store wallet recovery phrases, private keys, keystore files or signing credentials.

## Supported asset configuration

The administration foundation supports these asset identifiers:

- BTC
- USDT
- USDC

Each receiving method is configured separately by network/chain. Network labels are deployment-defined because the client has not yet supplied the exact approved network list. USDT and USDC require a token contract address for the selected network.

## Current implementation

- Global payment enable/disable.
- Independent crypto, card and manual payment switches.
- Independent booking and membership/listing purpose switches.
- BTC, USDT and USDC receiving-address records by network.
- Configurable confirmation threshold.
- Methods are disabled by default.
- A human administrator can record a keyed-hash reference and attest that the asset/network/address/token configuration was independently reviewed.
- An address cannot be enabled until that review is recorded and global crypto plus a matching purpose switch are enabled.

The manual review is not proof of wallet ownership and is not proof that a customer payment was received.

## Not implemented — do not activate payments

- Public checkout and invoice generation.
- Immutable invoice snapshots.
- Network-specific address validation.
- Blockchain node/provider integrations.
- Transaction detection, confirmation counting, underpayment/overpayment handling and reorganisation handling.
- Idempotent settlement and reconciliation.
- Refund and dispute workflows.
- Card processor integrations or underwriting approval.
- Tax/accounting exports.

No customer should be instructed to send payment through Talyvane until these controls are implemented and verified. Payment switches and address records are configuration groundwork only.

## Invoice design requirements

When transaction processing is implemented, each invoice must snapshot the asset, exact network, receiving address, token contract, amount, quote source, fiat reference amount/currency, expiry and confirmation threshold at creation time. Editing a receiving method must not alter an existing invoice.

Payment state must be server-derived. A screenshot, wallet redirect, browser callback or user-submitted transaction hash alone is not proof of settlement. The server must query a trusted network source, verify the destination and amount, apply the configured confirmation policy and reconcile idempotently.

## Legal and commercial gates

The operating entity, payment purpose, jurisdictions, provider terms, sanctions controls, tax treatment and any applicable licensing requirements require qualified review. Direct-to-wallet does not automatically eliminate payment-services or other regulatory obligations. The business must obtain written approval from any card processor and hosting provider for the actual business model before enabling those rails.
