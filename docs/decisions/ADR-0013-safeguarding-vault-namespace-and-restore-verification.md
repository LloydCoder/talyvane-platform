# ADR-0013: Deployment-scoped safeguarding keys and restore verification

- **Status:** Accepted
- **Date:** 2026-11-20
- **Decision owners:** TALYVANE platform maintainers

## Context

Safeguarding case content is encrypted with a per-case 256-bit data-encryption key (DEK). In Vault-backed deployments, the application database stores the encrypted content and the Vault Transit ciphertext wrapping the DEK; Vault stores the non-exportable key material. A database restore without the matching Transit key material can make active safeguarding records unreadable. Conversely, a Vault inventory can contain a key name whose restored key material is unusable for the actual ciphertext.

The platform is intended to support independent customer deployments. A shared Transit mount with unnamespaced `case-*` keys creates a cross-deployment deletion risk if one deployment's orphan reconciliation treats another deployment's keys as local.

## Decision

1. Vault-backed safeguarding requires an explicit `SAFEGUARDING_KEY_NAMESPACE` containing 8–32 lowercase alphanumeric characters. It is unique and immutable per deployment.
2. New case and preflight key names include that namespace. The application refuses to create keys outside its own namespace.
3. The database enforces uniqueness for every non-null safeguarding `key_reference`, preventing two cases from sharing one external key reference.
4. The application credential may create namespaced keys and encrypt/decrypt DEKs. A separate retention credential may list the mount and delete only keys in the deployment namespace. It cannot encrypt or decrypt.
5. Restore reconciliation runs only in maintenance mode. It verifies that each active key reference exists in Vault and decrypts the actual stored ciphertext to a 32-byte DEK before deleting any orphan.
6. Orphan cleanup is limited to the current deployment namespace. The command re-lists Vault after deletion and verifies that active keys remain and no namespaced orphan keys remain.
7. Legacy unnamespaced active keys are accepted for decryption and retention, and are verified during restore, but are never automatically treated as orphans. A deployment containing legacy keys must keep its Transit mount dedicated until those keys have been erased or explicitly migrated.
8. A successful reconciliation does not prove that historical Vault backup snapshots have expired. Independent database/Vault backup expiry evidence and a full provider restore drill remain mandatory before claiming cryptographic erasure.

## Consequences

- A missing or unusable active key blocks restore completion; the operator must keep the application in maintenance mode.
- A shared mount is safer because new key cleanup is namespace-scoped, but a dedicated mount per deployment remains preferred.
- The configured namespace must be supplied in the Vault ACL policy as a literal value; Vault ACL policy files do not interpolate shell environment variables.
- Application-level restore records are not independent proof of backup expiry and may be rolled back with an older database snapshot. Preserve provider-side evidence separately.
- Legacy unnamespaced keys require deliberate retirement/migration; the application will not guess their deployment ownership or delete them as orphans.

## Verification

The automated test suite covers active ciphertext decryption before cleanup, failure-before-delete behavior, post-delete inventory verification, other-deployment key preservation, legacy active-key compatibility, explicit namespace requirement, cross-namespace create rejection and unique per-case key references.
