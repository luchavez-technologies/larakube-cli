# 0044: LaraKube Cloud Keeps Each Server's State by Default

**Status:** Accepted

## Context

To create, change or delete a server, OpenTofu keeps a small record of what it made: the "state", a few KB per server. Cloud asks every team to set up its own S3-compatible bucket for it (a "state storage backend", today Cloudflare R2) before the first server can be created. A non-developer does not know what that is, and it is the second thing they must set up, after a cloud account.

Losing the state does not stop a server, but LaraKube can then no longer change or cleanly delete it. Whatever keeps it must be durable. Cloud already encrypts each server's state with its own passphrase (`LARAKUBE_TOFU_PASSPHRASE`), held in OpenBao.

The Plex Commons SeaweedFS on Cloud's server was considered and rejected for this: it runs on one node, and its volumes have no backups of their own.

## Decision

1. **By default Cloud keeps each server's state**, in a private Cloudflare R2 bucket that belongs to LaraKube Cloud, one bucket per Cloud environment.
   - Each server's state is its own object, keyed by the server's unique ID and encrypted as today.
   - Only Cloud holds the bucket's keys; they are Cloud's own secrets, never a team's.
   - A server with no `state_backend_id` uses this storage.
2. **A team can still use its own storage** (today's option), under "Advanced" on the create page. When a team has no storage of its own, the create page shows no storage step at all.
3. **Leaving never strands a server.**
   - "Move to my own storage" is offered at any time and when leaving. Cloud copies the encrypted state into the team's bucket, then points the server at it.
   - An export gives the team each server's state passphrase, so the LaraKube CLI can use the state without Cloud.
   - This export is needed even for teams that already use their own storage, because the passphrase has only ever been in Cloud.

## Consequences

- The create flow needs one thing from a non-developer: a cloud account. Cloudflare (automatic DNS and SSL) stays optional.
- Cloud carries the durability of every Cloud-kept state. R2 is replicated, and Cloud's own backups do not need to cover it.
- LuchTech pays for the bucket: effectively nothing at these sizes.
- The passphrase export closes a gap in works-without-Cloud that already existed.
