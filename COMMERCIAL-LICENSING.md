# Talyvane Commercial Licensing

Talyvane is intended to be a proprietary, source-available commercial product. Public repository visibility is not a production licence and does not make the platform OSI-approved open source.

Commercial paths may include single-business, enterprise, multi-deployment, agency/reseller, and separately negotiated source-code assignment arrangements. Each signed agreement must define licensed entity, deployment scope, domains, users, territory, term, support, updates, modification, sublicensing, fees, data roles, security duties and termination.

Platform IP and customer materials must be distinguished expressly. The reusable core remains with the rights holder unless expressly assigned; customer marks, content and business data remain subject to contract and applicable law. Custom-work ownership and reuse rights must be stated in the statement of work.

The initial public commit contained an Apache-2.0 licence. Changing the current licence does not retroactively revoke permissions already granted for that historical publication. Obtain legal review of repository history, chain of title and intended licensing model before commercial release.

Adopt contributor terms before accepting external code.

## Repository licence chronology for counsel review

The Git history provides a useful, but not conclusive, chronology:

- Initial repository commit `efd99c9e6b705746bcf9f057a622acdb10e8d861` (2026-11-18 23:30:53 UTC) contains only `.gitignore`, `LICENSE` and `README.md`; it contains no Laravel application source.
- The licence file was replaced with the source-available terms in commit `7a7a5730d41970ca2101a288622ba76eb9ffcdcd` (2026-11-18 23:44:45 UTC).
- The first `composer.json` commit is `7246dbab598502df0cb31f6af7fef09f462a0152` (2026-11-18 23:45:28 UTC), and the first `app/Models/User.php` commit is `13dbc12c6bcb8317d0eb9c6fa8673f65a47dc9d9` (2026-11-18 23:48:20 UTC).

This chronology indicates that the initial Apache-2.0 tree did not contain the Laravel application source and that the application source began appearing after the repository licence-file change. It is evidence for counsel to review, not a legal conclusion about the scope of any grant, copyright notices, repository terms, forks, caches, third-party materials or rights held by contributors.

Apache-2.0 section 2 describes a perpetual, worldwide, royalty-free, irrevocable copyright licence for the covered Work, and section 4 sets redistribution conditions: https://www.apache.org/licenses/LICENSE-2.0.html. Counsel should inspect the exact historical blobs, commit timestamps, GitHub publication/fork history, author/assignment records and all third-party assets before Tinlance makes exclusivity or enforcement claims.


## Engineering architecture

The binding engineering decision is [ADR-0015 — Commercial licensing, editions and deployment authority](docs/decisions/ADR-0015-commercial-licensing-and-deployment.md). The profiles below are agreement patterns, not published prices or claims that every capability is implemented:

- **Evaluation:** non-production inspection and evaluation only.
- **Single-business deployment:** one isolated production installation for one licensed legal entity.
- **Enterprise:** negotiated deployment estate, affiliates, users, territories, support and service levels.
- **Multi-deployment:** multiple named isolated installations with explicit deployment identities and transfer rules.
- **Agency/reseller:** downstream customer licensing and provisioning rights only when expressly granted.
- **White-label:** independently branded deployments with explicit branding, domain and notice rights.
- **Source assignment/buyout:** a separate written assignment covering exact assets, exclusions, third-party rights, consideration and transition obligations; it is not an ordinary deployment licence.

Each agreement must distinguish platform IP, customer materials, third-party materials and commissioned work. It must specify the licensed legal entity, deployment IDs/count, domains and environments, permitted users, territory, term, update/support scope, modification rights, transfer/re-hosting, sublicensing/resale, fees, data roles, security responsibilities, termination and post-termination data export.

## Deployment and entitlement policy

The initial supported commercial architecture is one isolated installation per licensed customer. Shared multi-tenant SaaS is future scope and must not be marketed as implemented. The customer installation owns its runtime, domain/TLS, database, private media, secrets, integration credentials, logs, backups and retention configuration.

TLMS is a separate licensing control plane. Talyvane verifies signed entitlements locally using a public verification key; ordinary requests must not depend on an online TLMS call. The protocol must support bounded offline operation and explicit renewal/recovery. A TLMS outage must never lock a customer out of their own data, data export, account security, safeguarding, incident response or emergency workflows. No remote destructive action or hidden kill switch is permitted. See ADR-0015 for the proposed Ed25519 envelope, claims, lifecycle, threat model and acceptance criteria.

The machine-readable entitlement is an implementation of the signed commercial agreement, not a substitute for it. Unsigned API responses, client-side flags or UI state must never grant commercial rights. Licensing cannot override authentication, Super Admin authority, consent, market approval, payment underwriting or hard safeguarding controls.

## External legal gate — not complete

Before production commercial enforcement, qualified counsel must approve the exact licence text and commercial agreement pack and review historical licence blobs, GitHub public-repository permissions, authorship/assignment, third-party code and assets, and enforceability in relevant jurisdictions. The repository's initial publication included an Apache-2.0 licence file. The current proprietary licence must not be represented as retroactively cancelling prior grants. Do not claim exclusivity or pursue enforcement based solely on this engineering document.

Do not accept external code contributions until approved contributor terms and provenance checks are in place. Maintain the custom `LicenseRef-Talyvane-Commercial` identifier consistently in Composer metadata and the licence inventory; this custom reference does not mean the licence is OSI-approved.
