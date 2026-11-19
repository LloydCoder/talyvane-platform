# ADR-0009: Super Admin control plane and hard safeguards

- Status: Accepted
- Date: 2026-11-19

## Context

Talyvane must be configurable and resalable as an independently deployed white-label platform. The deployment owner needs comprehensive control over modules, business workflows, markets, channels, integrations and operational settings. A collection of unrelated toggles would create inconsistent enforcement and could allow UI-only controls to be bypassed.

## Decision

Each installation has one highest-privilege Super Admin, securely bootstrapped through the trusted CLI process. A singleton deployment-control record points to the provisioned principal; bootstrap is transactional, refuses a second principal, and never silently promotes an existing owner or administrator. The current `talyvane:super-admin:create` command implements this first bootstrap boundary. Super Admin authority covers all configurable business functionality within that installation.

Implement a registry-driven control plane with typed settings, declared dependencies, activation prerequisites, centralized server-side feature enforcement, privileged step-up authentication and append-only audit events. Feature state changes require the current Super Admin password, a fresh single-use TOTP code, a meaningful reason, transactional persistence and an immutable audit event. Enabling is blocked unless implementation status, dependencies and known activation prerequisites pass. Emergency disable is supported for optional features; re-enable is prerequisite checked.

Super Admin authority does not override hard-coded safety, security, privacy, safeguarding, payment-integrity or jurisdictional eligibility invariants. These constraints are enforced by server-side policy services and domain boundaries, not editable feature flags.

The first commercial release remains deployment-isolated. A future hosted multi-agency platform must separate platform-operator lifecycle permissions from each tenant's Super Admin and business data.

## Consequences

- Feature activation is consistent across UI, routes, APIs, jobs and services.
- Enabling a feature may be blocked until dependencies, credentials, provider approvals or market reviews are complete.
- High-impact changes require MFA/step-up, a reason and an audit record.
- Existing owner/admin roles are not automatically promoted. The designated Super Admin cannot be demoted, deactivated or deleted through Eloquent model operations; a second Super Admin cannot be assigned after the singleton principal is set. These model guards are defense in depth, not a database-level boundary: privileged direct SQL can bypass them. A separately authenticated, audited recovery/rotation command remains a release blocker.
- Tests must cover both feature states and attempts to bypass server-side gates.
- No Super Admin UI may reveal stored secrets, permit public registration of a Super Admin, or alter immutable audit history.


The first least-privilege boundary is implemented: `super_admin` alone may change deployment-wide branding, market/currency configuration, destinations, booking rules, payment configuration, site pages and feature controls. `owner` and `admin` retain agency operations such as profile review, enquiries, client records and booking operations. This is route-level role separation. The designated-principal lifecycle guards are implemented at the Eloquent model boundary and covered by regression tests; direct SQL remains outside that guard. Granular per-action permissions remain follow-up work. CLI-only recovery enrollment and MFA reset are implemented; dual-control break-glass handling for loss of both password and offline key remains outstanding.


## Recovery and session revocation implementation

The deployment Super Admin now has CLI-only recovery enrollment and MFA reset. Initial provisioning verifies the primary and separate recovery notification addresses, issues a 64-character offline recovery key and stores only its hash. Existing deployments can enroll the same recovery controls using the current password and TOTP; recovery contacts and the offline key can also be rotated after verifying the primary address and new recovery address. Recovery requires the password plus the single-use offline key, rotates that key, resets TOTP, increments the per-admin session version, rotates the remember token, sends notices to both verified addresses and appends audit events. The session-version middleware protects all authenticated admin route groups, including MFA setup; pending MFA challenges also carry and revalidate the version before completion.

This is application-level control, not a guarantee against a database administrator or direct SQL. Loss of both password and offline key remains an operational break-glass case requiring independent identity proofing and dual control.
