# Super Admin recovery and credential enrollment

## Security model

The deployment Super Admin is a singleton principal. The recovery path is CLI-only; there is no public or browser-accessible recovery endpoint.

The offline deployment recovery key is generated with a cryptographically secure random generator, shown once at provisioning or enrollment, and stored only as a password hash. It is a separate credential from the user's TOTP recovery codes. A successful recovery consumes the old key by replacing its hash with a newly generated key.

Recovery requires both:
1. The current Super Admin password.
2. The one-time offline deployment recovery key.

This follows the recovery pattern of a saved recovery code combined with a bound single-factor authenticator. It is not a claim of NIST certification or an AAL assessment.

## First-time provisioning

Before provisioning:

- Apply all database migrations.
- Configure a working SMTP mailer: `MAIL_MAILER=smtp`, `MAIL_HOST`, `MAIL_PORT`, SMTP credentials and a valid `MAIL_FROM_ADDRESS`.
- Use HTTPS and a valid `APP_URL`.
- Ensure the operator has access to the primary administrator email and a separate recovery notification email.

Run:

```bash
php artisan talyvane:super-admin:create admin@example.com
```

The command:

1. Requires a strong password.
2. Requires a distinct recovery notification address.
3. Sends separate short-lived verification codes to both addresses.
4. Requires both codes to be entered in the active terminal session within five minutes.
5. Marks the two addresses verified only after both checks pass.
6. Creates the designated Super Admin and stores a hash of a new 64-character offline recovery key.
7. Writes an append-only bootstrap audit event.
8. Displays the offline recovery key once.

Store the key in an approved offline secret vault with restricted access. Do not put it in source control, tickets, email, chat, shell arguments, screenshots or deployment logs. If the terminal output is captured by a hosting provider, rotate the key immediately using the recovery workflow.

## Existing deployments

For an existing designated Super Admin that already has MFA but has no enrolled offline recovery key or verified recovery address, run:

```bash
php artisan talyvane:super-admin:enroll-recovery
```

The command requires the current password and a fresh TOTP code, verifies the primary and separate recovery email addresses, issues the offline recovery key, records the enrollment and invalidates all previous administrator sessions. It does not reset the existing TOTP authenticator.

## Rotating recovery notification contacts

When the separate recovery mailbox changes, run:

```bash
php artisan talyvane:super-admin:rotate-recovery-contacts
```

This requires the current password and a fresh TOTP code. It verifies the primary administrator address and the new recovery address, sends a change notice to the primary, previous recovery and new recovery addresses, rotates the offline recovery key, increments the session version and records the reason. The old recovery address is retained for notification only during the transaction; it is replaced after successful delivery.

The new offline key is displayed once. Store it offline and replace the previous key in the approved secret vault.

## Recovering a lost authenticator

Use this only when the Super Admin can still provide the current password and the offline deployment recovery key:

```bash
php artisan talyvane:super-admin:recover
```

The command:

- Requires the current password and offline key; neither is accepted as a command-line argument.
- Requires a reason between 20 and 500 characters. Do not include secrets or unnecessary personal information.
- Locks and rechecks the designated principal and recovery record in a database transaction.
- Clears the TOTP secret, enabled timestamp, TOTP replay state and TOTP recovery codes.
- Rotates the offline recovery key and stores only its hash.
- Increments the administrator session-version counter and rotates the remember token.
- Sends security notices to both verified notification addresses before committing the transaction.
- Records the recovery reason, session-version transition and notification result in the append-only recovery audit table.
- Displays the replacement offline recovery key once.

After recovery, sign in with the password and complete MFA enrollment again. All existing administrator sessions must be rejected; users must authenticate again.

If SMTP delivery fails, the transaction is rolled back and the recovery is not committed. Because mail delivery is external to the database, one recipient may receive a conservative false-positive notice if a later delivery or database commit fails. Verify both contacts and the audit record before retrying.

## Audit and session invalidation

`super_admin_recovery_events` records bootstrap issuance, recovery enrollment, rejected credential attempts, completed MFA recovery and notification delivery. The Eloquent model rejects update and delete operations. This is application-level append-only protection; a database administrator or direct SQL connection can bypass model events. Production database credentials must therefore be restricted, and database audit/backup controls must be enabled by the hosting operator.

Authenticated admin sessions and pending MFA challenges carry the current `admin_session_version`. Recovery, recovery enrollment/contact rotation and administrator password changes increment this value and rotate `remember_token`. The middleware rejects sessions whose stored version is missing or stale, including file-based sessions; pending MFA challenges are also discarded if the version changes between password verification and MFA completion.

## Two-owner break-glass recovery

Configure this before production, while the designated Super Admin still has the current password, MFA and offline recovery key:

\`\`\`bash
php artisan talyvane:super-admin:configure-break-glass
\`\`\`

Configuration requires:
- The designated Super Admin's current password and fresh TOTP.
- Exactly two different active, email-verified \`owner\` accounts.
- Each Owner's own password and fresh TOTP.
- Separate email verification codes sent to both Owner mailboxes, entered within five minutes.
- Distinct Owner email addresses that differ from both Super Admin notification addresses.
- Different Owner passwords and different authenticator secrets.
- A recorded reason and working SMTP delivery.

The setup increments the Super Admin session version, consumes the TOTP steps, stores two guardian assignments and sends security notices to the Super Admin primary/recovery addresses and both guardians. No guardian password, TOTP secret or verification code is stored in the guardian table.

If the two Owner custodians later change, remove the existing assignment with:

\`\`\`bash
php artisan talyvane:super-admin:clear-break-glass
\`\`\`

This requires the current Super Admin password and fresh TOTP, records a reason, invalidates administrator sessions and notifies all affected addresses. The recovery path is unavailable until two new guardians are configured.

When both the Super Admin password and offline recovery key are lost, run:

\`\`\`bash
php artisan talyvane:super-admin:break-glass
\`\`\`

The command requires both configured Owners to authenticate independently with their own password, fresh TOTP and a one-time email code. It also requires a new strong Super Admin password and a reason. Five rejected break-glass attempts in a rolling 15-minute window temporarily block further attempts.

A successful break-glass operation:
- Rechecks the designated Super Admin, both guardian assignments and both Owner identities under database row locks.
- Rechecks each Owner's role, activation, verified email, password, TOTP replay state and distinct authenticator/password requirements.
- Consumes both Owners' TOTP steps and records the use time.
- Replaces the Super Admin password, clears the old MFA secret/recovery codes, increments the session version and rotates the remember token.
- Replaces the lost offline recovery key with a new 64-character key, storing only its hash.
- Sends security notices to the Super Admin primary/recovery addresses and both Owners.
- Writes an append-only application audit event with the reason, guardian account IDs and session-version transition.
- Displays the new offline key once. The Super Admin must sign in and enroll MFA again.

Assigned guardian accounts cannot be demoted, deactivated, deleted, have their email changed or have their MFA enrollment reset through Eloquent while assigned. Clear the assignment first using the controlled command. Direct SQL remains outside these application-level guards and must be restricted operationally.

**Dual-control boundary:** this is strict 2-of-2 recovery: both assigned Owner principals are required; it is not a 2-of-3 quorum. Loss of either guardian prevents break-glass until the Super Admin can authenticate and clear/reconfigure the assignment. Keep both guardians operational and test their access periodically. The application requires two distinct Owner principals with different passwords and authenticator secrets, but software cannot prove that the accounts are controlled by two different human beings. The deployment owner must designate independent custodians, prohibit credential sharing, verify their identities out of band and ensure neither person controls both mailboxes. If both guardian accounts or their common infrastructure are compromised, this recovery path is compromised. Do not claim certification or independent identity proofing from this workflow.

There is deliberately no public reset link, support override, model-driven approval or unauthenticated database-reset endpoint. Do not improvise direct SQL changes on a live deployment.


## Standards references and scope

This implementation adopts relevant recovery patterns from [NIST SP 800-63B-4, Section 4.2 (Account Recovery)](https://pages.nist.gov/800-63-4/sp800-63b.html) and [OWASP Multifactor Authentication Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Multifactor_Authentication_Cheat_Sheet.html): offline saved recovery material, one-time replacement after use, verified multiple notification addresses, independent event notifications, and throttling of rejected recovery attempts.

The 64-character recovery key exceeds the NIST 64-bit minimum for saved recovery codes. The password plus saved recovery key follows one of the NIST AAL2 recovery method patterns. This is an implementation reference, not an AAL2 or NIST conformance claim: the full platform has not undergone an independent digital-identity assessment, and phishing-resistant authentication and identity-proofing controls remain separate work.

## Operational requirements

- SMTP must deliver to both verified addresses.
- Keep the offline key separate from the server and application database.
- Back up the database and protect backup access.
- Review recovery audit events after every enrollment or recovery.
- Treat unexpected recovery notifications as security incidents.
- Rotate the offline key after every successful recovery.
- Do not claim NIST conformance without a full independent assessment.
