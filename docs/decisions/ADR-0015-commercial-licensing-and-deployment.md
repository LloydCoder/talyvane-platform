# ADR-0015: Commercial licensing, editions and deployment authority

- **Status:** Accepted as the engineering/product architecture; legal terms remain pending qualified counsel approval
- **Date:** 2026-11-20
- **Owners:** Tinlance Limited / Talyvane maintainers
- **Scope:** Public source-available repository, customer deployment licensing, edition boundaries and future TLMS integration
- **Related:** `LICENSE`, `COMMERCIAL-LICENSING.md`, `docs/ARCHITECTURE.md`, `docs/RELEASE-PACKAGING.md`, `docs/ROADMAP.md`

## 1. Context and binding product decisions

The repository is intentionally public for source inspection and evaluation. The selected commercial direction is proprietary source-available software, not OSI-approved open-source software. Production/commercial deployment, white-label operation, redistribution, sublicensing and resale require a separate written commercial agreement, subject to applicable law and rights that the licensor can actually grant.

The repository must remain public. The product must support independently branded customer deployments, enterprise and multi-deployment arrangements, agency/reseller arrangements and separately negotiated source-code assignment/buyout arrangements.

The first supported commercial deployment architecture is one isolated installation per licensed customer. Each installation owns its domain/TLS, application runtime, database, media storage, secrets, integration credentials, logs, backups, retention policy and configuration. Shared multi-tenant SaaS is not represented as implemented and is not the initial architecture.

TLMS (Talyvane License Management System) is a separate service/product boundary. It is not the Talyvane application database, customer data plane or a mandatory online dependency for every request.

## 2. Decision

Adopt a **deployment-scoped commercial entitlement model** with a public source-available distribution and separately executed customer agreements.

The agreement, not an undocumented assumption in code, defines each customer's rights. A signed entitlement record is a machine-readable representation of those agreed rights; it does not replace or amend the agreement.

The engineering system shall distinguish:

1. Repository/source access and evaluation permission.
2. A commercial right to operate a named deployment.
3. Optional rights to modify, maintain, duplicate, move, sublicense, resell or white-label.
4. Ownership assignment/source buyout, which is a separate negotiated transaction and must not be inferred from an ordinary deployment licence.

No code or UI may imply that purchasing installation, hosting, customisation, support or consulting automatically transfers platform IP or grants reseller/source-buyout rights.

## 3. Edition and commercial-rights catalogue

The following are commercial agreement profiles, not pre-priced SKUs or promises that all corresponding software capabilities already exist.

| Profile | Intended scope | Required contract dimensions |
|---|---|---|
| Evaluation | Non-production inspection and evaluation within the published source-available terms | Evaluator, purpose, duration and permitted test environment |
| Single-business deployment | One isolated production installation for one licensed legal entity | Entity, deployment identity, domains, environment count, term, support and updates |
| Enterprise | A negotiated enterprise deployment or estate | Affiliates, deployment count, territories, environments, users, support/SLA and security responsibilities |
| Multi-deployment | Multiple isolated installations | Named entities, deployment IDs, permitted domains/environments, transfer/replacement policy |
| Agency / reseller | Rights to provision or resell deployments to named end customers | Reseller identity, downstream agreement flow, end-customer registration, support split, branding and sublicensing authority |
| White-label | Customer may independently brand an authorised deployment | Brand scope, domains, notices, customer marks, permitted attribution changes and prohibited misleading claims |
| Source assignment / buyout | Negotiated assignment of specified source/IP rights | Exact assets, exclusions, third-party rights, contributor rights, warranties, consideration, effective date and transition obligations |

A licence may combine profiles only when the signed agreement explicitly says so. There is no implied right to sublicense, redistribute source, host third parties, create competing products or operate additional deployments.

## 4. Rights and data boundaries

The contracts and product must distinguish:

- **Platform IP:** reusable Talyvane core, original platform documentation, schemas and platform assets, subject to verified chain of title and third-party exclusions.
- **Customer materials:** customer marks, supplied content, customer configuration and customer business data. Customer ownership/control and permitted platform processing must be defined by contract and applicable law.
- **Third-party materials:** Laravel, Composer packages, fonts, stock photography, icons, datasets and other assets remain governed by their own licence/terms. The proprietary platform licence cannot override third-party rights.
- **Custom work:** every statement of work must specify ownership, assignment, licence-back, reuse, deliverables and third-party components.
- **Contributions:** do not accept external code or content until approved contributor terms and provenance checks are in place. Repository visibility or a pull request is not a substitute for a signed assignment/CLA where one is required.

Do not place customer personal data, payment credentials, identity evidence or confidential operational data in TLMS. TLMS stores only the minimum customer/deployment and entitlement metadata needed to administer a licence.

## 5. Legacy publication and legal boundary

The initial repository publication included an Apache-2.0 licence file. Git history indicates that the initial tree contained only `.gitignore`, `LICENSE` and `README.md`, and that the source-available licence-file replacement preceded the first application-source commits. This chronology is evidence for counsel, not a legal conclusion.

The current proprietary terms must not be described as retroactively cancelling rights already granted. Preserve historical commits and licence blobs. Do not rewrite Git history, delete tags or make infringement/exclusivity claims as a substitute for legal analysis.

Before commercial enforcement or a source buyout, qualified counsel must review:
- Exact historical licence blobs and repository snapshots.
- GitHub Terms of Service and public-repository fork/access permissions.
- Commit authorship, employer/contractor obligations, assignments and contributor records.
- All third-party code, media, fonts, datasets and generated assets.
- The scope of any rights granted before the current licence became effective.
- Applicable governing law, mandatory rights, consumer/commercial terms and enforceability.

The current `LICENSE` and `composer.json` use a custom `LicenseRef-Talyvane-Commercial` identifier. This is a custom identifier, not an SPDX-listed standard licence and not evidence of OSI approval. Keep the identifier and the actual licence text synchronized; maintain a separate third-party notices/SBOM inventory.

## 6. TLMS service boundary

TLMS is a separate licensing control plane with its own repository/service lifecycle, database, deployment, credentials, audit trail, backups and release process. Talyvane remains independently deployable.

### 6.1 Proposed signed entitlement envelope

The protocol shall be versioned and use an asymmetric signature scheme. The proposed first implementation uses Ed25519 signatures with:
- Private signing keys held only by TLMS in a protected signing boundary (preferably KMS/HSM-backed when available).
- Public verification keys embedded/distributed with the Talyvane verifier.
- Explicit key IDs and a documented key-rotation overlap window.
- Canonical serialization and strict schema/version validation before signature verification.

Minimum signed claims (subject to final protocol review):

- `schema_version`, `license_id`, `product_id`
- `customer_legal_entity_ref` (opaque reference; no unnecessary personal data)
- `deployment_id` (unique per installation)
- `edition` and explicit `entitlements`
- `issued_at`, `not_before`, `expires_at` or an explicit perpetual term
- `environment_scope`, permitted domain policy and deployment count where contracted
- `policy_version`, `key_id`, and optional non-sensitive support/update entitlement dates

The payload must not contain private signing material, application secrets, customer user records, identity documents, payment credentials or personal data that is not strictly necessary.

### 6.2 Activation and verification

- Initial activation binds a signed entitlement to a generated deployment identity and the licensed scope.
- A deployment can verify its cached signed entitlement offline using the embedded public key.
- Online activation, renewal, support status and revocation refresh are administrative operations, not per-request authorization dependencies.
- Every verification failure is explicit and auditable without logging licence secrets or customer personal data.
- A copied licence must not silently authorise a second deployment when the agreement is single-deployment; activation/re-hosting and disaster-recovery replacement must have documented transfer/recovery procedures.
- Domain changes, staging environments, restores and hardware/provider changes must follow an explicit policy. Do not bind licences to brittle hardware fingerprints that break shared hosting or ordinary migration.
- TLMS outages must not prevent the customer from authenticating, accessing their own data, exporting data, performing safety-critical operations or reaching safeguarding/emergency workflows.
- Expiry or revocation must follow the signed agreement and a documented, proportionate degradation policy. No remote destructive action, data deletion, hidden kill switch or lockout of customer data is permitted.
- Offline revocation has a freshness limitation. The protocol must state the maximum revocation-staleness window for online deployments and the limitations for permanently offline installations; do not claim instant revocation while offline.
- Clock rollback, stale licences, key rotation, unknown key IDs, malformed payloads, duplicate activation, restore cloning and signature tampering require explicit tests.

### 6.3 Grace and failure policy

The protocol must distinguish network unavailability from a verified expired/revoked entitlement. Temporary TLMS unavailability is not proof of breach.

Define policy states such as `valid`, `renewal_due`, `grace`, `expired`, `revoked`, `invalid_signature` and `unknown_key`. A verified invalid signature must fail closed for new licensed feature activation. A network outage must use the last valid signed entitlement within its bounded offline policy.

Even when commercial entitlements lapse, retain access to customer data, account security, safeguarding, incident response, audit evidence, backup/restore and lawful data export. Any restriction of new commercial operations must be contractually approved and legally reviewed before implementation.

## 7. Talyvane integration contract

Talyvane shall consume a small local verifier interface. Domain code must not call TLMS directly.

The adapter must:
- Validate signature and schema locally.
- Resolve the deployment identity and entitlement snapshot.
- Expose typed entitlement decisions to the feature/edition policy layer.
- Cache only signed entitlement material and non-sensitive verification metadata.
- Avoid network requests in ordinary page/API requests.
- Fail safely on unknown schema versions and unknown signing keys.
- Emit metadata-only audit events for activation, renewal, transfer, invalid signature and administrative changes.
- Keep safeguarding hard controls independent of commercial feature flags.
- Never permit a licence claim to override Super Admin authorization, consent, market approval, payment underwriting or other hard safety/compliance gates.

Licensing is an additional commercial authorization layer. It is not a replacement for authentication, authorization, safeguarding, market policy or feature prerequisites.

## 8. Security and abuse cases

Threat model and tests must cover:
- Forged/modified entitlement payloads and algorithm-confusion attempts.
- Private-key disclosure, key rotation and compromised signing-key recovery.
- Replay, deployment cloning, duplicate activation and restore-to-new-host flows.
- Domain spoofing, reverse-proxy/Host-header manipulation and weak deployment identifiers.
- Clock rollback, stale cached entitlements and online/offline state confusion.
- TLMS outage, DNS/TLS failure, rate limiting and malicious API responses.
- Unauthorized licence transfer, reseller over-delegation and downstream customer mismatch.
- Logging of licence/customer secrets and accidental inclusion in public artifacts.
- Attempts to use licensing to bypass or disable hard safety controls.

Treat every TLMS response as untrusted until signature and schema verification succeed. Never let a model, prompt, UI field or unsigned API response grant entitlements.

## 9. Consequences and implementation sequence

Positive:
- The repository can remain public while production rights are commercially managed.
- Customer installations remain isolated and independently operable.
- Licensing can be verified on shared hosting without requiring a persistent worker or constant outbound network.
- The licence service does not become a cross-customer application data plane.

Costs and constraints:
- Historical rights and chain of title require legal review; code cannot erase prior grants.
- Offline verification limits instantaneous revocation.
- Reseller, source assignment and enterprise agreements need distinct contract templates and operational processes.
- A secure signing-key lifecycle, deployment recovery workflow and support operation must be maintained.

Implementation order:
1. Legal review and approved commercial agreement templates.
2. TLMS protocol/schema and threat model.
3. TLMS issuance/renewal/revocation API and audited admin controls.
4. PHP verification SDK with deterministic cryptographic test vectors.
5. Talyvane local adapter, deployment identity and activation UI/CLI.
6. Offline/grace/recovery/restore tests on shared-hosting constraints.
7. Customer/reseller portal and deployment inventory.
8. Signed release and operational key-rotation/incident drills.

## 10. Acceptance criteria

This ADR's engineering architecture is accepted when:
- The commercial rights matrix and deployment model are documented consistently.
- The distinction between platform IP, customer materials, third-party materials and custom work is explicit.
- The historical Apache-2.0 and GitHub public-repository caveats are stated accurately.
- TLMS is separate from the Talyvane customer data plane.
- Offline verification and safe degradation are mandatory design requirements.
- No code claims legal approval, OSI approval, exclusive ownership or revocation of prior grants.
- Implementation work is tracked separately and is not marked complete by this ADR alone.

Legal approval, signed customer contracts, verified chain of title, production TLMS and commercial launch remain separate gates.
