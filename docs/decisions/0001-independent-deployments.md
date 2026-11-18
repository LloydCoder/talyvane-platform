# ADR 0001: Independent Customer Deployments First

- Status: Accepted baseline
- Date: 2026-11-19

## Context
The product must be white-label and resellable while keeping customer brands, databases, media, secrets and records isolated. Shared multi-tenancy adds cross-tenant authorisation and data-isolation risk before scale is validated.

## Decision
Ship a reusable modular Laravel application as one independently configured deployment per licensed customer. Do not implement shared multi-tenancy in the first release.

## Consequences
Stronger isolation and simpler customer-specific domains/backups, at the cost of deployment/update automation. A future licensing service may validate entitlements but must not require customer data access. Revisit shared tenancy only when scale and threat modelling justify it.
