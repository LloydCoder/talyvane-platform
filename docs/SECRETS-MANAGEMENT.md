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

A per-secret cache lock serializes concurrent writes and clears. Multi-node deployments must use a shared lock-capable cache backend. Runtime adapters must use `PlatformSecretResolver`, which requires configured metadata and retrieves the exact recorded Vault version. Never call it from a controller, Blade view, API response or logging path.

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
