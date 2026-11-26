# Booking Workflow

## Operating modes

The deployment has a global booking mode:

- **Manual:** every request enters pending_review; an authorised operator or the linked companion must review it.
- **Automatic:** a request may become confirmed only when all automatic-confirmation prerequisites pass. Otherwise it remains pending_review.

Automatic confirmation is separately disabled by default and is not implied by selecting automatic mode.

## Automatic-confirmation prerequisites

1. The profile is publicly discoverable.
2. Adult verification and current publication consent are recorded.
3. The companion explicitly opted into automatic booking.
4. A weekly availability window covers the requested local start and end times.
5. No overlapping pending or confirmed booking exists.
6. The configured deposit requirement is satisfied. The safe default requires a verified deposit; because a settlement adapter is not yet implemented, automatic confirmation remains blocked under that default.

Companions manage weekly availability in the companion portal. Availability is interpreted in the destination's configured timezone. Date-specific exceptions, cross-midnight automatic windows and provider-backed calendar synchronisation remain unimplemented.

## Time handling

The client selects a local date/time in the destination timezone. The server resolves the wall-clock value against the IANA timezone transition table and stores the selected instant in UTC alongside the timezone identifier. Non-existent spring-forward times are rejected. Repeated fall-back times are never resolved implicitly: the client must select the intended occurrence/UTC offset, and the server validates that offset against the exact local time.

## Concurrency and audit

Booking creation, companion decisions and admin confirmation lock the profile row before evaluating overlap, preventing concurrent requests from double-confirming the same profile/time window. Booking events record requests, automatic confirmations, companion decisions, admin status changes and deposit verification. The event table has no application update/delete route.

## Deposit verification

The current admin workflow records a human verification attestation and stores the external transaction reference as a keyed HMAC digest. This is not a blockchain watcher or processor integration. The reviewer must independently inspect the trusted provider/network source. Never accept a screenshot as proof.

Client cancellation is configurable by the admin. Pending requests can be cancelled by the client; confirmed bookings can be cancelled only when the policy switch is enabled and the request is outside the configured cutoff. Refunds are never automated by cancellation and must follow the separately approved payment policy.

## Remaining work

- Date-specific availability exceptions and blackout periods.
- Provider-backed calendar integration, schedule conflict synchronisation, feed-token revocation and reconciliation.
- Date-specific companion blackout exceptions and cross-midnight automatic windows.
- Client rescheduling is implemented with destination-local input, UTC persistence, state preservation and overlap/policy checks; full browser and concurrency acceptance remains open.
- Booking notification outbox, encrypted payloads, idempotency and bounded retry/dead-letter handling are implemented; real cPanel SMTP/cron acceptance and localized editable templates remain open.
- Booking event retention and tamper-evident export/backup.
- Payment invoice/settlement integration.
