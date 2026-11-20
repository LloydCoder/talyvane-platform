# Deployment secret management

TALYVANE's Super Admin secret workspace manages an allowlisted set of integration credentials through a dedicated HashiCorp Vault KV v2 mount. It does not store plaintext secrets in the application database and does not display existing values.

## Configuration

Set the following environment variables outside the application database:

- `SECRETS_VAULT_ADDRESS`: HTTPS Vault base URL.
- `SECRETS_VAULT_MOUNT`: KV v2 mount name (default `secret`).
- `SECRETS_VAULT_PREFIX`: namespace prefix (default `talyvane`).
- `SECRETS_VAULT_TOKEN`: dedicated application token scoped to the configured namespace.
- `SECRETS_VAULT_TIMEOUT`: bounded HTTP timeout in seconds.

The token must have only the required KV v2 capabilities:
- Read/create/update on `secret/data/talyvane/*`.
- Read/delete on `secret/metadata/talyvane/*`.

Use a separate token from safeguarding Transit. Do not grant mount, policy or system administration privileges. The bootstrap token is environment-managed and is never stored in the application database or in the secret workspace.

## Supported secret identifiers

The application accepts only identifiers in `App\Services\SecretCatalog`. The catalog maps each identifier to a fixed Vault path. Arbitrary user-supplied paths are rejected.

Current catalog entries include SMTP password, transactional email API key, calendar OAuth client secret, identity-provider API key, blockchain RPC API key, exchange-rate API key and crypto webhook secret. These entries provide storage slots only; they do not claim the associated integration has been implemented or approved.

## Operations

1. Super Admin opens Administration → Deployment Control → Secret management.
2. Set or rotate by entering the new value, a meaningful operational reason, the current password and a fresh TOTP code.
3. The application writes the value to Vault using compare-and-set against the current metadata version.
4. Only metadata, Vault version, actor, timestamp and an audit event are stored locally.
5. The UI never displays or repopulates the value. The application exception handler excludes the secret field from flashed validation input.
6. Clear permanently deletes the KV v2 metadata path and all stored versions. This is irreversible.

A per-secret cache lock serializes concurrent writes and clears. Multi-node deployments must use a shared lock-capable cache backend. The cache lock must be shared across all application nodes; file or in-memory locks are not sufficient for a multi-node deployment. Runtime adapters must use `PlatformSecretResolver`, which requires configured metadata and retrieves the exact recorded Vault version. Never call it from a controller, Blade view, API response or logging path.

## Failure and recovery

- If Vault is unavailable, secret mutations fail closed.
- A failed first write leaves the secret unavailable to runtime adapters.
- A failed rotation leaves the previous recorded version active.
- A failed clear blocks runtime resolution until the Vault state is checked.
- A metadata/database failure after a successful Vault write must be reconciled by inspecting Vault metadata and performing a fresh CAS operation; never copy a secret into the database to recover.

## Operational checklist

- [ ] Provision Vault KV v2 mount and namespace.
- [ ] Create dedicated token with least-privilege ACL.
- [ ] Configure HTTPS, trusted CA chain, network access and Vault audit logging.
- [ ] Configure environment variables in the hosting control plane.
- [ ] Test initial set, rotation, failed rotation, clear and Vault outage.
- [ ] Verify integration adapters resolve only the approved version.
- [ ] Rotate the Vault application token through environment management.
- [ ] Test Vault backup and restore separately from application database backups.


## Security controls and operational limitations

- The application accepts only fixed catalog identifiers and fixed Vault-relative paths. It does not accept user-supplied Vault paths.
- TLS certificate verification is enabled and HTTP redirects are disabled. Vault endpoints must use HTTPS and a trusted certificate chain.
- Secret writes use KV v2 compare-and-set against the observed current version. A conflict or failed request is treated as failure; the application does not silently retry with a newer version.
- Secret values are excluded from session old input. Never include secret values in exception messages, structured logs, analytics, support tickets or audit reasons.
- Local audit events record requested, successful and failed mutations with actor attribution, reason and correlation ID. Vault's own audit devices must also be enabled and monitored. Application model hooks alone do not make the database audit ledger tamper-proof against direct database administrators.
- The secret store token is a high-value bootstrap credential. Keep it in hosting environment configuration, scope it to only the required KV v2 paths and operations, and rotate it through an out-of-band procedure.
- Manual rotation is supported. Automatic provider-side rotation, credential expiry enforcement, runtime secret-use telemetry and automated rotation scheduling are not implemented by this workspace and must not be claimed as available.
- Permanent clear uses the KV v2 metadata DELETE endpoint. HashiCorp documents this operation as deleting key metadata and all versions; it is irreversible through the application interface. Require an approved operational reason and verify downstream services have moved off the credential before clearing it.
- A database/Vault inconsistency after a successful remote write must be reconciled using Vault metadata and the recorded version. Do not recover by copying the secret into application storage.
- Before production use, exercise initial set, rotation, CAS conflict, Vault outage, metadata read failure, clear, multi-node lock behaviour, Vault backup and restore, and credential revocation in a non-production Vault environment.
