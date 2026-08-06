# 0009 — LiveKit is a shared tool with per-consumer keys

**Status:** accepted
**Date:** 2026-08-07

## Context

LiveKit shipped inside the chat stack, rendered by `resources/views/k8s/chat/matrix.blade.php`
and gated on `$livekitApiKey`. That was correct while Matrix was the only consumer. It stopped
being correct the moment a Laravel app wanted a video call: there was no path to reach the SFU,
and no shared cluster tool injects credentials into a project.

Three concrete problems:

- One API key/secret was shared by everything, so rotating for one consumer broke all of them.
- `chat:remove` never deleted `chat-livekit` / `chat-lk-jwt` / `chat-coturn`; they leaked.
- On a single node the two stacks **cannot coexist at all**: both bind `hostPort` 7881/TCP and
  7882/UDP, and hostPorts are exclusive. Any migration is a changeover, never a parallel run.

## Decision

**LiveKit becomes the `meet` shared cluster tool.** One SFU at `meet.<domain>`, consumed by
Matrix and by Laravel apps.

**Each consumer gets its own key pair.** LiveKit's `keys:` map accepts any number of
apiKey/apiSecret pairs. The `meet-keys` Secret holds a JSON registry (`consumer` →
key/secret/roomPrefix/webhookUrl) and `livekit.yaml` is rendered from it, never hand-edited.

**The Matrix bridge belongs to the wiring, not to either service.** `meet-lk-jwt` is deployed by
`meet:wire --tool=chat` and removed by `meet:unwire`. Serving it from `meet.<domain>/jwt` rather
than `chat.<domain>/livekit/jwt` means the wire command never mutates the chat Ingress, and let
chat drop both stripPrefix middlewares.

**Coturn stays with chat.** It backs Synapse's legacy 1:1 `turn_uris`, which have nothing to do
with the SFU. `meet` needs no TURN: UDP 7882 with a TCP 7881 fallback is what works in
production today.

## Consequences

### Two limits this does not solve

**OSS LiveKit has no per-key room restriction.** Any valid API key can mint a token for any
room. Per-consumer keys buy independent rotation and revocation — cutting off one app without
breaking chat — not room isolation. Scoping is the `roomPrefix` convention each consumer is
issued, enforced by the consumer, not the server. Treat a leaked key as access to every room.

**Webhooks fan out and can only be verified by one consumer.** The config takes a single
`webhook.api_key` and a list of `urls`, and delivers every event to every URL, signed with that
one key. A second subscriber could not verify the payloads it receives. So the template wires
webhooks **only while exactly one consumer registers a URL**, and omits the block entirely
otherwise. Multi-app webhooks need a filtering relay; that is deliberately not built.

### Invariants the code enforces

- **The registry is hashed into the LiveKit Deployment's `config-checksum`.** `livekit.yaml` is
  a subPath-mounted Secret and never hot-reloads; without this, wiring a consumer rewrites the
  Secret while the pod keeps serving the old key set and silently rejects the new credentials.
- **The registry is `ksort`ed before serializing.** Restarting the SFU drops every live call, so
  the same set of consumers in a different map order must hash identically.
- **A persisted registry always contains a `_system` key.** `livekit-server` exits with
  `one of key-file or keys must be provided` on an empty `keys:` map, so a fresh install — or
  the last `meet:unwire` — would otherwise CrashLoopBackOff the SFU. Seeded in
  `writeMeetKeys()` so no call site can bypass it.
- **`meet:wire` writes Synapse's whole calling block**, not just the focus URL:
  `experimental_features` (MSC4140), `max_event_delay_duration`, `rc_message`,
  `rc_delayed_event_mgmt`, `well_known`. Writing `well_known` alone points Element Call at a
  working SFU with delayed events disabled — the exact configuration that made every client
  rejoin on a ~15 second loop. `renderSynapseCalling()` must stay in lockstep with the
  `@if($meetJwtUrl)` block in `matrix.blade.php`.

### Migration

There is none, by the no-one-time-migration rule. The old `chat-livekit` resources are deleted
by hand once, and because of the hostPort collision that deletion must happen **before**
`meet:init` can schedule.
