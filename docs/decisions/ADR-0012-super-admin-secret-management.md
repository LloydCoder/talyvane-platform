# ADR-0012: Super Admin secrets use a dedicated Vault KV v2 store

- Status: Accepted
- Date: 2026-11-20

## Context

Deployment integrations require credentials, but writing secret values to the application database, ordinary settings tables, audit events, logs or HTML responses creates unnecessary exposure. A web process also cannot safely rewrite its own environment file on shared hosting. The existing safeguarding Transit token is purpose-specific and must not be reused for integration secrets.

## Decision

Use a dedicated HashiCorp Vault KV v2 mount and a separate environment-provisioned application token. The bootstrap token and Vault endpoint remain environment-managed; the Super Admin UI manages only allowlisted secret values within the configured namespace.

The approved secret identifiers are defined in `SecretCatalog`. User input never becomes a Vault path. The application uses HTTPS only, validates TLS certificates, disables redirects, bounds request timeouts and sends the Vault token in the `X-Vault-Token` header.

A per-secret cache lock serializes set/rotate and clear operations across application workers; multi-node deployments must use a shared lock-capable cache backend. The write path uses KV v2 compare-and-set (CAS) against the current Vault metadata version. Runtime resolution is gated by metadata state and pinned to the exact recorded version. During a rotation, the currently configured version remains active until the new Vault write and metadata/audit transaction succeed. A failed rotation therefore does not silently disable the previous working credential.

The application database stores only:
- Approved secret identifier and fixed Vault path
- Configured/pending/error/cleared state
- Vault version number
- Configuring actor and timestamps

The database and audit ledger never store secret values. The UI accepts a new value but never reads it back or includes it in rendered HTML. The exception handler excludes secret_value from flashed old input so validation redirects cannot persist it in the session. Audit events record action, actor, reason, a shared per-operation correlation ID and Vault version only.

Set, rotate and clear require the canonical deployment Super Admin, current password and a fresh single-use TOTP step. Clear uses the Vault KV v2 metadata deletion endpoint, permanently deleting all versions for that secret path. This action is irreversible and requires an explicit reason.

Approved server-side integration adapters must resolve credentials only through `PlatformSecretResolver`. Controllers and views must never call the resolver. Storing a provider credential does not implement, approve or activate the provider integration.

## Vault ACL

For a KV v2 mount named `secret` and namespace `talyvane`, provision a dedicated token scoped to the required paths, for example:

- `secret/data/talyvane/*`: `read`, `create`, `update`
- `secret/metadata/talyvane/*`: `read`, `delete`

Do not grant broad system, mount-management or policy-management capabilities. The Vault bootstrap token is not stored in Vault and is not managed by the application. Never reuse the safeguarding Transit application or retention tokens.

## Failure semantics

- Missing/invalid Vault configuration: all secret mutations fail closed.
- CAS conflict: write is rejected; retry only after fetching current metadata and requiring fresh Super Admin step-up.
- Failed initial write: metadata is marked error and runtime resolution remains blocked.
- Failed rotation: the previously configured version remains active and pinned.
- Failed clear: metadata is marked error and runtime resolution is blocked until an operator verifies the Vault state.
- Application database failure after Vault write: metadata remains pending/previous-version state; a later operation can inspect current Vault metadata and safely CAS a new version. No secret value is copied into the database for recovery.

## Security limitations

The application token can read the allowlisted secrets within its Vault namespace at runtime. A compromised application process with that token can therefore access those secrets. Restrict the token to the smallest namespace and secret set practical, rotate it through environment management, monitor Vault audit logs and isolate Vault from public access.

Application-level append-only model hooks do not prevent a database administrator from modifying or deleting audit rows through direct SQL. Independent database privileges, backups and external Vault audit logs remain necessary.

## References

- [HashiCorp Vault KV v2 API](https://developer.hashicorp.com/vault/api-docs/secret/kv/kv-v2)
- [HashiCorp Vault policy concepts](https://developer.hashicorp.com/vault/docs/concepts/policies)
- [OWASP Secrets Management Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Secrets_Management_Cheat_Sheet.html)
- [OWASP Authorization Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Authorization_Cheat_Sheet.html)
