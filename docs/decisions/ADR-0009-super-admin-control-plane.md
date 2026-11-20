# ADR-0009: Super Admin control plane and hard safeguards

- Status: Accepted
- Date: 2026-11-19

## Context

Talyvane must be configurable and resalable as an independently deployed white-label platform. The deployment owner needs comprehensive control over modules, business workflows, markets, channels, integrations and operational settings. A collection of unrelated toggles would create inconsistent enforcement or permit UI-only controls to be bypassed.

## Decision

Each installation has one highest-privilege Super Admin, securely bootstrapped through the trusted CLI process. A singleton deployment-control record points to the provisioned principal; bootstrap is transactional, refuses a second principal, and never silently promotes an existing owner or administrator. The `talyvane:super-admin:create` command implements this first bootstrap boundary. Super Admin authority covers configurable business functionality within that installation.

The control plane uses a feature registry with typed settings, declared dependencies, activation prerequisites, centralized server-side enforcement, privileged step-up authentication and append-only audit events. Feature state changes require the current Super Admin password, a fresh single-use TOTP code, a meaningful reason, transactional persistence and an immutable audit event. Enabling is blocked unless implementation status, dependencies and known activation prerequisites pass. Emergency disable is supported for optional features; re-enable is prerequisite checked.

Super Admin authority does not override hard-coded safety, security, privacy, safeguarding, payment-integrity or jurisdictional eligibility invariants. These constraints are enforced by server-side policy services and domain boundaries, not editable feature flags.

The first commercial release remains deployment-isolated. A future hosted multi-agency platform must separate platform-operator lifecycle permissions from each tenant's Super Admin and business data.

## Consequences

- Feature activation is consistent across UI, routes, APIs, jobs and services where the feature registry is integrated.
- Enabling a feature may be blocked until implementation status, dependencies, credentials, provider approvals or market reviews pass.
- High-impact changes require MFA/step-up, a reason and an audit record.
- Existing owner/admin roles are not automatically promoted.
- The designated Super Admin cannot be demoted, deactivated or deleted through Eloquent model operations; a second principal cannot be assigned after the singleton principal is set. These model guards are defense in depth, not a database-level boundary: privileged direct SQL can bypass them.
- Assigned break-glass guardians are protected against Eloquent-based role, activation, email-verification, MFA-enrollment and deletion changes until the assignment is cleared. Guardian assignment records and recovery events reject Eloquent updates/deletes. These are application-layer safeguards; database administrators retain direct-SQL authority.
- Tests must cover feature states, role/resource boundaries and attempts to bypass server-side gates.
- Hard safety, privacy, safeguarding, payment-integrity and audit controls are reserved outside the feature registry; see [ADR-0011](ADR-0011-hard-safeguards-not-feature-flags.md).
- No Super Admin UI may reveal stored secrets, permit public registration of a Super Admin, or alter immutable audit history.

## Implemented privilege boundary

The current least-privilege boundary reserves deployment-wide branding, market/currency configuration, destinations, booking rules, payment configuration, site pages and feature controls to `super_admin`. `owner` and `admin` retain agency operations such as profile review, enquiries, client records and booking operations. This is route-level role separation; granular per-action permissions remain follow-up work.

## Recovery and session revocation

Initial Super Admin provisioning verifies the primary and separate recovery notification addresses, issues a 64-character offline recovery key and stores only its hash. Existing deployments can enroll recovery controls using the current password and TOTP. Recovery contacts and the offline key can be rotated after verifying the primary identity and new recovery address. The password-plus-offline-key recovery flow rotates the key, resets TOTP, increments the per-admin session version, rotates the remember token, sends notices to verified addresses and appends recovery events.

The session-version middleware protects authenticated administrator route groups, including MFA setup; pending MFA challenges also carry and revalidate the version before completion. The designated Super Admin is additionally resolved against the canonical platform_control.super_admin_user_id at login, pending-MFA completion and every role-protected request. A raw SQL change that merely assigns users.role = super_admin therefore does not grant deployment authority.

## Two-owner break-glass recovery

A CLI-only, dual-control path is implemented for the exceptional case where the designated Super Admin has lost both the primary password and offline recovery key:

1. Two distinct, pre-enrolled Owners must remain active, email-verified and enrolled in MFA.
2. Each guardian independently supplies their account password and a fresh, single-use TOTP code. Guardian passwords and TOTP secrets must be independent.
3. Separate email verification codes are delivered to both configured guardian addresses and must be entered within five minutes.
4. The operator supplies a new Super Admin password and a reason. The service rechecks guardian assignments and credentials under database locks before committing.
5. The transaction resets the Super Admin password and MFA enrollment, rotates the offline recovery key (only its hash is stored), increments the session version, rotates the remember token, updates guardian-use timestamps and appends audit events. Notifications are sent to the designated Super Admin, recovery contact and both guardians.
6. Attempts are rate-limited and rejected attempts are recorded. The command is not exposed through an HTTP endpoint.

The guardians must be configured in advance using the documented trusted-CLI workflow. If fewer than two eligible guardians are configured, break-glass recovery fails closed. This process is not a substitute for independent identity proofing, secure custody of recovery material, operational separation of duties or incident response.

## Explicit security limitations

These safeguards are application-level controls. They do not protect against a database administrator or attacker with direct database write access, compromised host/root access, compromised SMTP, malicious deployment code or simultaneous compromise of both guardians. Eloquent model hooks do not make database rows cryptographically immutable. Production deployments need database access controls, encrypted and tested backups, monitoring, secure operator identity verification and an incident-response process.

Recovery email delivery is a required operational dependency. Operators must test SMTP delivery and retain offline recovery material securely. Never put passwords, TOTP codes, email verification codes or recovery keys in shell arguments, logs, tickets or source control.

## Remaining work

- Independent review of the complete Super Admin control plane and every privileged command.
- The canonical principal check at login, pending-MFA completion and authenticated authorization boundaries is implemented and regression-tested, including direct SQL role escalation in the users table.
- Restrict and audit direct database access: an attacker with authority to alter both the canonical platform_control pointer and user records, or to modify application code/host state, remains outside this application authorization boundary.
- Database-level constraints or separately controlled database procedures for invariants that must resist ordinary application SQL paths, where supported by the target database.
- Granular per-action permissions and a documented privileged-access review process.
- Production SMTP delivery, recovery drill, backup restoration and session-revocation verification on each supported hosting profile.
- Independent security testing; passing automated tests does not constitute certification or prove resistance to host/database compromise.
