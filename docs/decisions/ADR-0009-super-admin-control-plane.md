# ADR-0009: Super Admin control plane and hard safeguards

- Status: Accepted
- Date: 2026-11-19

## Context

Talyvane must be configurable and resalable as an independently deployed white-label platform. The deployment owner needs comprehensive control over modules, business workflows, markets, channels, integrations and operational settings. A collection of unrelated toggles would create inconsistent enforcement and could allow UI-only controls to be bypassed.

## Decision

Each installation has one highest-privilege Super Admin, securely bootstrapped through the installation/CLI process. Super Admin authority covers all configurable business functionality within that installation.

Implement a registry-driven control plane with typed settings, declared dependencies, activation prerequisites, centralized server-side feature enforcement, privileged step-up authentication and append-only audit events. Emergency disable is supported for optional features.

Super Admin authority does not override hard-coded safety, security, privacy, safeguarding, payment-integrity or jurisdictional eligibility invariants. These constraints are enforced by server-side policy services and domain boundaries, not editable feature flags.

The first commercial release remains deployment-isolated. A future hosted multi-agency platform must separate platform-operator lifecycle permissions from each tenant's Super Admin and business data.

## Consequences

- Feature activation is consistent across UI, routes, APIs, jobs and services.
- Enabling a feature may be blocked until dependencies, credentials, provider approvals or market reviews are complete.
- High-impact changes require MFA/step-up, a reason and an audit record.
- Existing owner/admin roles require a deliberate migration and bootstrap review before Super Admin authority is granted.
- Tests must cover both feature states and attempts to bypass server-side gates.
- No Super Admin UI may reveal stored secrets, permit public registration of a Super Admin, or alter immutable audit history.
