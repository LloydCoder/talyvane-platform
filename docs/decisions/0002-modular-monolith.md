# ADR 0002: Modular Laravel Monolith

- Status: Accepted baseline
- Date: 2026-11-19

## Context
The first delivery needs one coherent application, constrained hosting assumptions and a small team. Microservices would add operational overhead without demonstrated scaling needs.

## Decision
Use a modular Laravel monolith with explicit domain/application boundaries and infrastructure adapters. Keep payments, messaging, verification and storage behind interfaces.

## Consequences
One initial deployable application and database. Modules can be extracted later only when justified. Domain boundaries and automated tests must prevent uncontrolled coupling.
