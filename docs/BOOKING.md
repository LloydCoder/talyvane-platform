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

Companions manage weekly availability in the companion portal. Availability is interpreted in the destination's configured timezone. Date-specific exceptions, calendar synchronisation and cross-midnight automatic windows are not implemented yet.

## Time handling

The client selects a local date/time in the destination timezone. The server validates the time and scheduling window, then stores the booking interval in UTC alongside the timezone identifier. Non-existent local times caused by daylight-saving transitions are rejected by a format round-trip check.

## Concurrency and audit

Booking creation, companion decisions and admin confirmation lock the profile row before evaluating overlap, preventing concurrent requests from double-confirming the same profile/time window. Booking events record requests, automatic confirmations, companion decisions, admin status changes and deposit verification. The event table has no application update/delete route.

## Deposit verification

The current admin workflow records a human verification attestation and stores the external transaction reference as a keyed HMAC digest. This is not a blockchain watcher or processor integration. The reviewer must independently inspect the trusted provider/network source. Never accept a screenshot as proof.

## Remaining work

- Date-specific availability exceptions and blackout periods.
- Calendar integration and schedule conflict synchronisation.
- Client cancellation/rescheduling and policy enforcement.
- Notification delivery and retry handling.
- Booking event retention and tamper-evident export/backup.
- Payment invoice/settlement integration.
