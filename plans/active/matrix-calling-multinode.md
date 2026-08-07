# Plan: Matrix Voice/Video Calling on Multi-Node Clusters (DOKS / EKS / AKS)

**Status:** Active — design still valid, but re-map the names before using it
**Created:** 2026-08-04
**Updated:** 2026-08-07 — LiveKit moved out of chat; two §1 claims corrected
**Parent doc:** `plans/completed/matrix-voice-video-calling.md` (superseded)

---

## 0. Read this before §1 — what 2026-08-07 changed

The multi-node problem and the LoadBalancer design below are **unchanged and still unbuilt**.
The names and two of the claims are stale:

| this doc says | now |
| --- | --- |
| `chat-livekit-rtc` Service | **`meet-livekit-rtc`**, in the `meet` tool |
| `chat:init --no-host-port` | **`meet:init --no-host-port`** for the SFU; chat's flag now only covers Coturn |
| LiveKit + Coturn are one problem | **two tools**: Coturn stays with chat (legacy 1:1 `turn_uris`), the SFU is `meet` |

**§1 claim 1 is half wrong.** It says the well-known key was "fixed in both spots" — Synapse's
`well_known.client` block and Cinny's mounted file. `well_known:` **is not a Synapse config
option**; Synapse silently ignores unknown top-level keys, so only the Cinny copy ever worked.
The correct key is `extra_well_known_client_content`. Removing the Cinny copy as a "duplicate"
took calling down until that was found.

**§4's "known gap" is closed.** LiveKit's TCP fallback `rtc.tcp_port: 7881` *is* now exposed —
`SharedClusterService::MEET::firewallPorts()` returns `[7881, '7882/udp']` and both are opened on
the DO firewall and host UFW.

**New constraint this doc predates:** `hostPort` is exclusive per node, so a chat-embedded SFU and
`meet-livekit` can never run on the same node. That is why the single-node changeover was a
delete-then-deploy, not a parallel run — and it is an argument *for* the LoadBalancer path here,
since LB Services have no such collision.

---

## 1. What's already fixed (single-node, shipped 2026-08-04)

Three bugs found while debugging "Your homeserver does not support calling" on `chat.luchtech.dev` (single-node K3s VPS, context `larakube-159.89.205.239`):

1. **Wrong well-known key** — Cinny v4.12.3 gates all calling UI on `org.matrix.msc4143.rtc_foci` (MSC4143 / MatrixRTC). `chat:init` was emitting the obsolete `org.matrix.msc3401.call1.livekit.service_url` shape in both Synapse's `well_known.client` block and Cinny's mounted `.well-known/matrix/client` file. Fixed in both spots in `resources/views/k8s/chat/matrix.blade.php`.
2. **LiveKit RTC port mismatch** — `livekit.yaml` configured `rtc.port_range_start/end: 50000-50050` but only `hostPort: 7882` was ever opened; the two didn't agree. Switched to LiveKit's single-UDP-port mode (`rtc.udp_port: 7882`) — the SFU multiplexes all participants over one port via ICE-lite, so it only ever needs the one port that's actually exposed.
3. **Coturn had no relay port range at all** — only signaling port 3478 was open; TURN's per-session relay allocations (coturn's default 49152-65535 range) had no path out of the pod. Added `min-port=49160`/`max-port=49179` (20 ports) to `turnserver.conf`, opened as matching `containerPort`/`hostPort` pairs in the Deployment and as a `Service`.

All three are `hostPort`-based (single-node-only) by default. A new `--no-host-port` flag on `chat:init` (mirroring the existing one on `mail:init`) flips Coturn's `chat-coturn` Service and a new `chat-livekit-rtc` Service from `ClusterIP` to `LoadBalancer`, dropping the per-port `hostPort` entries — same toggle mechanism already proven in `resources/views/k8s/mail/stalwart.blade.php` / `MailInitCommand`.

Verified so far: both manifest variants (`hostPort` true/false) render without Blade errors and pass `kubectl apply --dry-run=client` against the real cluster schema; `ChatInitCommandTest` suite still green. **Not yet verified**: an actual `chat:init --no-host-port` run against a real multi-node cluster, or a real call placed through it.

---

## 2. Why single-node's fix doesn't just carry over

On the single-node VPS, `$host` (`chat.luchtech.dev`) resolves via DNS to the one node's public IP, so `hostPort: 3478` on that node *is* reachable at the address clients are told to use for TURN/RTC.

On a multi-node cluster (DOKS/EKS/AKS), `$host` resolves to a cloud LoadBalancer's IP, not any one node. `hostPort` bindings only open a port on whichever specific node the pod happens to land on — if Coturn or `chat-livekit-rtc` gets rescheduled to a different node (or just started on a node other than the one an old DNS/ARP cache points at), UDP media silently stops arriving even though signaling (`/livekit/jwt`, `/_matrix`) keeps working through the HTTP-layer Ingress. This is the exact failure mode `--no-host-port` is meant to route around, by putting Coturn/LiveKit's raw ports behind a proper `Service.type: LoadBalancer` that kube-proxy/the cloud LB keeps pointed at the pod's current node regardless of rescheduling.

---

## 3. Open risk — needs verification on a real multi-node cluster

The one thing not provable from a single-node VPS: **does the target cloud's LoadBalancer actually forward UDP correctly** for a mixed TCP+UDP port like Coturn's 3478, and for the wide-ish `49160-49179` relay range plus `7882`?

- DigitalOcean LB added UDP forwarding-rule support at some point — needs re-confirming as of whatever DOKS version is current when this is tested, including whether it handles a *range* of UDP ports cleanly or wants one rule per port (which could blow past DO LB's per-LB rule limit for the 20-port Coturn range).
- `externalTrafficPolicy` matters here: default `Cluster` works with any node (kube-proxy handles cross-node routing but rewrites source IP, which coturn/livekit don't currently care about); `Local` preserves the client IP but only routes to nodes that actually have a ready pod — worth testing both.
- No DOKS/EKS/AKS cluster is provisioned right now to test against — this whole section is unverified until one exists.

Fallback if LB-per-port-range turns out to be impractical/expensive on the target provider: narrow the Coturn relay range further (e.g. 10 ports instead of 20), or fall back to the Traefik `IngressRouteUDP` CRD approach the original plan doc speculated about (CRDs already installed cluster-wide via `traefik-crds.blade.php`, just never wired to the chat stack) — deferred rather than built now since it's unproven anywhere in this codebase and adds a second UDP-routing mechanism to maintain alongside the mail stack's LB-toggle pattern.

---

## 4. Known gap not addressed by this pass — ✅ CLOSED 2026-08-07

~~LiveKit's TCP fallback port (`rtc.tcp_port: 7881`) has never been exposed externally.~~
Now exposed via `SharedClusterService::MEET::firewallPorts()` (`[7881, '7882/udp']`), opened on
both the DO cloud firewall and host UFW, and bound as a `hostPort` on `meet-livekit`. Still
needs the LB-toggle treatment for multi-node, same as the UDP ports.

---

## 5. Test / debug plan for when a multi-node cluster is available

### Setup
- [ ] Provision a real multi-node target (DOKS, ≥2 nodes) via `larakube cloud:create` / `cloud:configure` per the standard flow.
- [ ] `larakube chat:init {env} --context=... --no-host-port` — confirm it completes and `chat-coturn` / `chat-livekit-rtc` Services show `type: LoadBalancer` with an assigned `EXTERNAL-IP` (not `<pending>` — if stuck pending, that's the DO UDP-support question in §3 surfacing immediately).

### Signaling layer (should already work — same as single-node)
- [ ] `curl -i https://chat.{domain}/.well-known/matrix/client` — confirm `org.matrix.msc4143.rtc_foci` is present and points at `https://meet.{domain}/jwt`. This is served from Synapse's `extra_well_known_client_content`; an empty response here means the focus never reached the client, whatever homeserver.yaml contains.
- [ ] `curl -i https://meet.{domain}/jwt/sfu/get` reachable (the bridge moved off the chat host).

### Media layer (the actual multi-node-specific risk)
- [ ] Force Coturn's pod onto a *specific* node (`kubectl get pod -o wide`, or a temporary `nodeSelector`) and confirm from a client **on a different network** that TURN relay still works — this is the scenario `hostPort` would silently break and the LB toggle is meant to fix.
- [ ] From an external host: `nc -u -v {LB-IP} 3478` and a probe against a couple of the relay ports (`49160`-`49179`) — confirms the LB is actually passing UDP through, not just TCP.
- [ ] Delete the Coturn pod (`kubectl delete pod`) to force a reschedule to a different node, then immediately repeat the `nc -u` probe — confirms the Service/LB re-points correctly without any DNS or client-side changes, which is the entire point of `--no-host-port`.
- [ ] Two real browser clients on separate networks (e.g. one on cellular/hotspot to force TURN relay, not just STUN) — place a 1:1 call, confirm audio/video actually flows, then start a group call in a call-type room and confirm LiveKit's SFU handles 3+ participants.
- [ ] Chrome `chrome://webrtc-internals` during the call — check the selected ICE candidate pair; if it's `relay` type and connects, TURN relay is confirmed working end-to-end, not just STUN-assisted direct.

### If it breaks
- `kubectl logs deploy/chat-coturn` — coturn logs every allocate/permission/refresh; look for `allocate` requests that never get a matching client-side ICE candidate (relay unreachable) vs none at all (client never got past `turnServer` credential fetch).
- `kubectl logs deploy/chat-livekit` — LiveKit logs ICE connection state per participant; a `udp_port` single-port-mode misconfiguration shows up as connections stuck in `checking`.
- `kubectl get svc chat-coturn chat-livekit-rtc -o yaml` — confirm `spec.type`, external IP, and that `externalTrafficPolicy` matches whatever was tested.
- Once a working configuration is confirmed, log the result (working `externalTrafficPolicy`, relay range size, any DO-specific LB annotation needed) back into this doc and `plans/testing-checklist.md`, then fold this plan's status to done/merge into the parent doc.
