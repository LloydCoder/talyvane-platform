# ADR-0011: Hard safeguards are not feature flags

- Status: Accepted
- Date: 2026-11-20

## Context

The Super Admin feature registry controls optional business capabilities. It must never become an authority to disable legal, safety, privacy, safeguarding, authorization or financial-integrity invariants. A broad feature toggle could otherwise disable consent withdrawal, media takedown, legal holds, retention, safeguarding escalation or ledger protections while a deployment is under maintenance or responding to an incident.

## Decision

Hard safeguards are enforced in domain services, authorization boundaries and protected operational commands. They are not configurable modules and must not be represented as ordinary feature flags.

The following feature-key namespace entries are reserved and cannot be enabled, disabled or used as dependencies:

- `adult-eligibility.assurance`
- `consent.withdrawal`
- `privacy.media-takedown`
- `privacy.data-rights`
- `safeguarding.case-access`
- `safeguarding.legal-holds`
- `safeguarding.retention`
- `safeguarding.notification-escalation`
- `payments.ledger-integrity`
- `audit.immutability`

If a reserved key is accidentally inserted into the feature table, the runtime registry treats it as disabled, reports an explicit hard-safeguard blocker and refuses Super Admin mutation. Dependencies on reserved keys fail closed.

Feature pause semantics are continuity-safe: optional switches may prevent new work, but existing obligations and protective transitions continue. This includes consent withdrawal, private-media deletion/takedown, licence revocation and expiry, legal-hold operations, approved safeguarding retention, safeguarding alert delivery, booking cancellation, disputes, reversals and financial close-out.

Scheduled safeguards are governed by their own approved policy, transport and retention prerequisites, not optional module switches. A paused model-agency module must not prevent expiry of existing image licences. A paused feature set must not prevent approved safeguarding retention or configured safeguarding escalation notices. These jobs must remain bounded, idempotent and observable.

## Consequences

- Super Admin controls only optional, implemented business capabilities.
- Reserved safeguard keys cannot become activation dependencies.
- Tests verify that reserved keys fail closed even if a database row is manually inserted with `is_enabled = true`.
- Tests verify that a Super Admin cannot mutate a reserved key.
- Tests verify that expiry, retention and safeguarding notifications continue when optional feature flags are disabled.
- A feature flag is not a legal approval, security boundary or substitute for domain authorization.

## Security limitations

These are application-layer protections. A database administrator or attacker with write access to application code or host infrastructure can bypass application controls. Production database credentials, backups, deployment access and operational audit trails require independent restriction and monitoring.

## References

- [OWASP Authorization Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Authorization_Cheat_Sheet.html)
- [OWASP ASVS 5.0 — V8 Authorization](https://github.com/OWASP/ASVS/blob/master/5.0/en/0x17-V8-Authorization.md)
- [OWASP Authorization Patterns Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Authorization_Patterns_Cheat_Sheet.html)
- [Laravel 13 Authorization](https://laravel.com/framework/docs/13.x/authorization)
