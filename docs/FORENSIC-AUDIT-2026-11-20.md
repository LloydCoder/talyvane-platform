# Talyvane forensic audit baseline — 2026-11-20

**Repository:** `LloydCoder/talyvane-platform`  
**Default branch snapshot:** `main` at `df9d4abf3905ba94b115f3fd6e9523e5822e401d`  
**Audit status:** Baseline established; full release audit remains open  
**Purpose:** Preserve an evidence-based inventory of completed engineering, known gaps, external release gates and the order of remaining work.

## 1. Evidence and scope

The repository tree API returned 494 entries with `truncated=false`. The inventory includes the Laravel application, 52 migration files, with the latest numbered migration 000062, Blade views, routes, cPanel wrappers, installer bootstrap, documentation, Composer lockfile and feature/unit tests.

The review inspected repository metadata, the recursive tree, the CI workflow, product requirements, architecture, roadmap, security/privacy and compliance documents, release/hosting/installer documentation, Composer metadata, the licence history, and representative authentication, booking, payment configuration, globalization, safeguarding, settlement and installer code.

This is a repository/API-based forensic baseline, not a claim that every source line has been independently reviewed. A local checkout could not be obtained in the audit runtime because outbound DNS/network access to GitHub was unavailable. The exact-commit GitHub Actions runs are therefore the authoritative executable test evidence available here. Continue the deeper source-by-source review and independent security testing before release.

## 2. Verified delivery

### Secure browser installer

PR #62 was merged to `main` as `df9d4abf3905ba94b115f3fd6e9523e5822e401d`.

Post-merge CI workflow [#44757620878](https://github.com/LloydCoder/talyvane-platform/actions/runs/44757620878) completed successfully on that exact commit. All four jobs passed:

- PHP 8.3 / Laravel feature suite
- PHP 8.4 / Laravel feature suite
- PHP 8.5 / Laravel feature suite
- Standalone release-package smoke build

The PR-head validation recorded 387 tests and 1,889 assertions per PHP version. The package workflow installed production dependencies from the committed lockfile, checked platform requirements and Composer advisories, validated routes/views, assembled the package, verified checksums and smoke-tested first-request bootstrap and cPanel public-root wrappers.

This verifies the checks executed by CI. It is not a live cPanel provider certification, a penetration test, a signed customer release or a legal approval.

### Existing product foundations

The repository contains implemented foundations and tests for:

- Laravel 13 application, Composer lockfile and PHP 8.3–8.5 CI matrix.
- Public site, branding/CMS, destination and profile discovery.
- Separate client and companion authentication/portals, email verification and role boundaries.
- Manual companionship booking requests, configurable automatic-booking prerequisites, weekly availability and overlap checks.
- Profile media upload/review, consent versioning, withdrawal and private media controls.
- A separate model-agency application, representation, portfolio, image-licence, casting, assignment and settlement domain.
- Super Admin singleton/control plane, privileged MFA, recovery and break-glass processes.
- Feature registry and server-side operational prerequisites.
- Safeguarding cases, restricted access, legal holds, retention and optional Vault-backed key custody.
- Membership entitlement grants and private-gallery delivery authorization.
- Globalization schema, money primitive and deployment-local market/currency configuration.
- Disabled-by-default payment configuration and Vault-backed secret-management foundations.
- Secure cPanel first-install browser flow and standalone release packaging.

A code path or administrative screen is not proof that the associated external service is operational. The product requirements and compliance documents correctly distinguish several human-attested/manual records from independent verification or actual funds movement.

## 3. Forensic findings and open risks

### 3.1 Licensing and chain of title

Git history establishes this chronology:

- Initial commit `efd99c9e6b705746bcf9f057a622acdb10e8d861` (2026-11-18 23:30:53 UTC) contains only `.gitignore`, `LICENSE` and `README.md`; no Laravel application source appears in that initial tree.
- Proprietary source-available terms replaced the Apache-2.0 licence file in commit `7a7a5730d41970ca2101a288622ba76eb9ffcdcd` (23:44:45 UTC).
- The first `composer.json` appeared in commit `7246dbab598502df0cb31f6af7fef09f462a0152` (23:45:28 UTC).
- The first `app/Models/User.php` appeared in commit `13dbc12c6bcb8317d0eb9c6fa8673f65a47dc9d9` (23:48:20 UTC).

This narrows the historical Apache-2.0 review but does not settle it. Apache-2.0 section 2 provides a perpetual, worldwide, royalty-free, irrevocable copyright licence for covered Work, and section 4 defines redistribution conditions: https://www.apache.org/licenses/LICENSE-2.0.html. Qualified counsel must review exact historical blobs, copyright notices, GitHub terms, forks/caches, authorship/assignment, dependency and image rights, and the enforceability of the current commercial terms. Do not claim exclusivity or infringement remedies before that review.

### 3.2 CI and application-security assurance

The current CI workflow runs PHP linting, Composer validation/audit, route and Blade compilation, the feature suite on three PHP versions, and a production-package smoke build. The inspected workflow does not yet run a dedicated PHP static-analysis gate, a comprehensive SAST ruleset, DAST, coverage threshold, mutation testing, accessibility automation or an independent penetration test.

GitHub CodeQL does not support PHP as an analyzed language in its current supported-language list; do not add a PHP CodeQL job and mistake a green/no-alert result for PHP coverage. See https://docs.github.com/en/code-security/concepts/code-scanning/codeql/codeql-code-scanning. Select PHP-capable tools such as Semgrep and PHPStan/Larastan, pin their versions, tune rules against the Laravel codebase, and make actionable findings merge-blocking. OWASP ASVS 5.0.0 and OWASP Top 10:2025 are the baseline references already named by the project: https://asvs.owasp.org/ and https://top10.owasp.org/2025/.

### 3.3 Commercial release controls

The GitHub releases API returned no published releases at audit time. The current CI artifact is a 14-day smoke-test artifact, not a signed/tagged customer release. The repository rulesets endpoint returned an empty list; branch-protection status could not be inspected through the connected GitHub integration (403), so do not assume required checks are enforced by repository settings.

### 3.4 Product and operational gates

The product requirements and roadmap still identify major incomplete domains: complete ISO reference-data imports; market-specific pricing and exchange-rate snapshots; locale/time-zone behaviour; real age/identity assurance; booking exceptions/rescheduling/calendar/notifications; model/client self-service and evidence/e-signature workflows; actual payment and tax integrations; audit integrity and comprehensive feature-gate bypass testing; Vault rotation/restore drills; and production hosting acceptance.

The current payments implementation is configuration groundwork only. It does not generate invoices, monitor chains, reconcile transfers, issue refunds or process cards. The model-agency settlement ledger records manual external-payment evidence; it does not move or verify funds. Self-attestation and reviewer-entered evidence are not independent age/identity assurance.

### 3.5 Hosting and installer acceptance

PR #62 adds constrained installer flow and main-domain/add-on-domain cPanel wrappers. CI smoke-tests the wrappers, but no provider-specific live cPanel/Apache/LiteSpeed test evidence is present in the inspected repository. A signed/tagged artifact, real-host installation drill, PHP web/CLI parity, SMTP, cron, private storage, backup/restore and upgrade/rollback acceptance remain release gates.

## 4. Remaining phases and acceptance criteria

| Phase | Remaining work | Completion evidence |
|---|---|---|
| 1. Repository baseline and docs | Keep roadmap, README, installer and hosting claims synchronized; record exact release evidence | Reviewed docs; no stale PR/branch/status claims |
| 2. Commercial rights | Counsel review of licence chronology, chain of title, contributor assignments, third-party dependencies/assets, customer and reseller agreements | Written legal opinion and approved licence/contract pack |
| 3. Globalization | Versioned ISO 3166-1 and ISO 4217 data/imports; market policy; localized prices; exchange-rate adapters and immutable quote snapshots; locale/language/time-zone rendering; approved tax adapters | Dataset provenance, migration/import tests, quote invariance tests and approved market evidence |
| 4. Eligibility and safeguarding | Approved age/identity provider integration; verifier procedures; custodian identity proofing; retention/incident drills; database-plus-Vault backup expiry and restore | Provider contract/configuration, negative/positive integration tests, documented drills and signed operational evidence |
| 5. Booking and portals | Date exceptions, reschedule/cancellation lifecycle, notifications, calendar integration, richer companion self-service and reporting | End-to-end tests, provider/OAuth review, operational runbooks |
| 6. Model-agency workflows | Secure evidence upload/retrieval, independently verifiable execution, model/client self-service, client casting/assignment, assignment scheduling, consent-aware media delivery and usage monitoring | Authorization/consent tests, evidence integrity checks, end-to-end workflows and legal approval |
| 7. Payments and finance | Approved provider adapters, invoice snapshots, chain verification/reconciliation, refunds/disputes, card underwriting, tax/invoices/statutory reporting | Provider approvals, independent transaction tests, reconciliation and finance-control evidence |
| 8. Super Admin assurance | Exhaustive feature-gate bypass tests across routes/services/jobs/adapters; database privilege review; tamper-evident audit exports; Vault rotation/telemetry/restore; legacy role migration | Threat-model traceability, direct-DB tests, audit verification, production Vault drill |
| 9. TLMS licensing | Separate TLMS service, signed licence issuance/verification, activation/renewal/revocation, offline/shared-hosting policy, deployment entitlements and platform adapter | Signed protocol/spec, interoperability tests, failure-mode tests, license-policy/legal review |
| 10. Release engineering | Signed/tagged artifacts, SBOM/provenance/attestation, release notes, migration compatibility, safe updates, backup/restore and rollback, deployment records and reseller support docs | Reproducible signed release and successful upgrade/rollback drills |
| 11. Security and quality | PHP static analysis, SAST, secret scanning, DAST, coverage policy, mutation-quality checks, accessibility and performance testing, independent penetration test | Clean/tolerated findings with documented exceptions and signed independent report |
| 12. Hosting and staging | Provider-specific cPanel/Apache/LiteSpeed matrix, no-SSH install, cron/SMTP, staging smoke, restore and compatibility report | Completed host matrix and deployment acceptance evidence |
| 13. Final release audit | Review every tracked file, route, migration, permission, dependency, package entry, workflow and document; resolve all findings; repeat exact-release tests | Closed findings register, full green CI/security gates and signed release approval |

## 5. External no-go gates

The following cannot be honestly marked complete by source-code changes alone:

- Written legal approval for the exact business model and each target jurisdiction.
- Written hosting-provider approval for the actual content and service model.
- Written payment-provider underwriting and approval for each payment purpose/market.
- Real identity/age assurance provider onboarding and operational validation.
- Approved privacy notices, terms, consent statements, retention schedules and tax treatment.
- Live shared-hosting/provider acceptance and independently witnessed backup/restore drills.

Features depending on these gates must remain disabled or fail closed.

## 6. Audit governance

1. Work serially by phase; keep one auditable scope per pull request.
2. Use test-first reproduction for every behavioral defect.
3. Run the complete repository CI and all relevant security/package checks on the exact PR head.
4. Inspect the current PR head SHA and all required check conclusions immediately before merge.
5. Merge only when every required check on that exact SHA is completed and successful.
6. Re-run post-merge CI on the resulting `main` commit.
7. Update this report and the roadmap with evidence, not assumptions.
8. Do not declare 100% complete while code findings, failed checks, external release gates or unverified deployment claims remain open.
