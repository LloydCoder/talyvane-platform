# Client membership and private shortlist

## Client shortlist

- Only authenticated, email-verified client accounts can save a companion.
- A profile must be publicly discoverable at the time it is saved, including current profile consent, adult-status review, active destination and approved country-market state.
- Favorites are stored in a client-scoped pivot table and are never exposed to companions or public discovery.
- The client portal lists only profiles that remain publicly discoverable. A withdrawn or unavailable profile is not re-exposed through the shortlist.
- Clients can remove a saved profile. The shortlist is not a booking, reservation or guarantee of availability.

## Membership administration

- Membership tiers are operator-defined strings; the application does not impose tier names, pricing or benefits.
- Only MFA-protected owner/admin accounts can assign or suspend a membership.
- Each assignment creates a new membership record with the tier, client-facing benefits summary, start/end dates, status and assigning administrator. The admin form can grant only keys present in the server-owned entitlement catalogue; unsupported client-supplied keys are rejected.
- Membership assignment is performed by a domain service that rechecks the membership feature gate inside the same database transaction that locks the client and changes membership state.
- Assignment and supersession append actor-attributed lifecycle events with before/after snapshots, a correlation identifier and a fixed operational reason. Snapshots include the immutable entitlement keys granted at that point in time. Internal agency notes are never copied into the client-facing projection.
- Lifecycle events reject Eloquent update and delete operations. This is an application-layer append-only guard, not protection against direct SQL or privileged database administrators.
- Replacing an active membership marks the prior active record as `superseded`; it is retained for history.
- A client sees only the current active membership's tier, benefits summary and validity dates.
- Internal agency notes are excluded from the client-facing query and view.
- The initial enforced entitlement is `media.private_gallery`. It is granted only while an active, in-date membership and in-date grant exist, the client account is active and email-verified, and the Super Admin has enabled the entitlement feature. The same check is enforced in the profile-detail query and again at the media-delivery endpoint. Public discovery queries never include members-only media URLs. Superseding, suspending or expiring the membership removes effective access without deleting historical grants.
- Entitlement grant rows are immutable at the Eloquent model boundary. This is not a database-administrator-proof WORM ledger; direct SQL remains outside that application guarantee.
- Membership assignment does not create a charge, payment mandate or automatic renewal. Any commercial terms must be confirmed separately and documented in the final terms.

## Release considerations

The membership workflow is operational tooling, not a legal contract. Before production, the operator must define tier eligibility, consumer terms, cancellation/refund treatment, applicable taxes, retention, support escalation and any local restrictions. Benefits displayed to clients must be accurate, approved by the operator and deliverable.
