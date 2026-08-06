# Plan: Jitsi Meet Shared Video Conferencing (`larakube meet:init`)

**Status:** ⛔ **BLOCKED — premise not confirmed.** Do not implement as written.
**Created:** 2026-08-06
**Reviewed:** 2026-08-06 (against the live `larakube-159.89.205.239` cluster)
**Target Version:** LaraKube CLI v1.2.0

---

## ⛔ Why this is blocked

This plan's premise is that LiveKit is the reason calls on `chat.luchtech.dev` sit in
"Reconnecting…" / "Waiting for media…". Checked against the live cluster, **that is not what
was happening.**

LiveKit's media path was healthy. Both peer connections reached connected on the single-port
UDP mux:

```
publisherCandidates:  [local][selected] udp4 host 159.89.205.239:7882
                      [remote][selected] udp4 prflx <client>:51464
subscriberCandidates: [local][selected] udp4 host 159.89.205.239:7882
                      [remote][selected] udp4 prflx <client>:29258
```

Tracks published fine (audio + video, both participants). hostPort, the DO cloud firewall,
UFW, coturn and `external-ip` were all correct. Of 132 participant closes, **131 were
`CLIENT_REQUEST_LEAVE`** — a clean ~15.6 s sawtooth of join → publish → active → *the client
voluntarily leaves* → rejoin. A signaling/membership loop above the SFU, not an ICE failure.

The actual causes were version drift, all fixed in `resources/views/k8s/chat/matrix.blade.php`:

- `livekit-server` was pinned at **v1.8.0** while the browser ran livekit-client 2.19.0
  (`"protocol": 17`). Every join logged
  `WARN unsupported datachannel added {"label":"_data_track"}` — Element Call's RTC/E2EE
  signalling channel, silently dropped. → **v1.13.5**.
- Synapse was pinned at **v1.120.0** (Nov 2024), which reports
  `"org.matrix.msc4140": false` (no delayed events, so Element Call cannot hold
  `m.call.member` alive) and predates MSC4222 `use_state_after`, which the client was already
  requesting on every `/sync`. → **v1.158.0** plus `msc4140_enabled`,
  `max_event_delay_duration`, and raised `rc_message` / `rc_delayed_event_mgmt`.
- `lk-jwt-service` was on the floating `:latest`. → pinned **0.5.0** (note: the GHCR tags have
  no `v` prefix).

**Reopen this plan only if calls still fail after that lands.** If they do, the next lever is
self-hosting Element Call — so the *client* is pinned too, ~80 MiB — not replacing the SFU.

---

## Capacity: Jitsi does not fit on this node as it stands

Measured on `larakube-159.89.205.239` (4 vCPU / 7.9 GiB):

| | value |
| --- | --- |
| memory in use | **5690 MiB (71%)** |
| memory *limits* already committed | **6794 MiB (85%)** |
| CPU limits already committed | **96%** |

- Untuned Jitsi is a non-starter: `VIDEOBRIDGE_MAX_MEMORY` and `JICOFO_MAX_MEMORY` both
  default to `3072m` in `jitsi/docker-jitsi-meet`.
- Tuned (`jvb -Xmx512m`, `jicofo -Xmx384m`) it is still ~1.7 GiB of limits / ~800 MiB idle
  RSS, pushing committed limits past 100% next to Teable (1159 MiB actual, 2 GiB limit).
- Deleting LiveKit + lk-jwt + coturn reclaims only **~75 MiB actual** — not a trade.

Prerequisite for ever shipping this: resize the droplet to 16 GiB, or evict a large tenant.

---

## Defects in the checklist below (fix before implementing)

1. **"Element & Cinny chat rooms natively launch Jitsi Meet video calls" is unachievable for
   Cinny.** Cinny has no widget support at all, and `chat-cinny` is the only self-hosted
   client in the cluster. Half the Definition of Done cannot be met.
2. **Jitsi in Element is the legacy `im.vector.modular.widgets` widget, not MatrixRTC.** It is
   not an `org.matrix.msc4143.rtc_foci` entry and cannot be "wired as the default conference
   provider in `matrix.blade.php`" the way step 5 implies. It needs
   `io.element.jitsi.preferredDomain` in `/.well-known/matrix/client`, which is served from
   **two** places here — Synapse's `well_known.client` block *and* `chat-cinny-config`'s nginx.
3. **Says "Replace" but has no removal step.** As written you would run both stacks.
4. **Missing components:** XMPP secrets (`JICOFO_COMPONENT_SECRET`, `JVB_AUTH_PASSWORD`,
   `JICOFO_AUTH_PASSWORD`); `PUBLIC_URL`; `JVB_ADVERTISE_IPS` / `DOCKER_HOST_ADDRESS`
   (mandatory on k8s — without it media fails exactly the way this plan is trying to escape);
   the colibri websocket route through Traefik; prosody auth mode (the default is anonymous,
   i.e. an open bridge for anyone on the internet); JVM heap sizing; and pinning all four
   Jitsi images to the *same* `stable-XXXX` tag (mixed tags break the stack).
5. **Violates the tool-command convention:** no `meet:remove` / `meet:show`, despite the
   Abstract Tool Remove/Show base commands shipped 2026-07-22 across 24/25 tools.
6. **`firewallPorts()` is incomplete:** `['10000/udp']` covers JVB but says nothing about
   whether coturn's `3478` + `49160-49179/udp` range stays or goes.

---

## 🎯 Original objective (unmodified, for reference)

Replace experimental **MatrixRTC + LiveKit** video calling with **Jitsi Meet**, providing a
battle-tested, rock-solid WebRTC video conferencing engine (`meet.example.com`) embedded
seamlessly inside Element and Cinny chat rooms.

---

## 🏛 Architecture & Component Breakdown

```mermaid
flowchart TD
    Client["Browser / Mobile Client"] -->|1. HTTPS / WSS| Traefik["Traefik Ingress (meet.example.com)"]
    Traefik -->|2. Web UI & XMPP| JitsiWeb["Jitsi Web (Nginx)"]
    JitsiWeb -->|3. Signaling| Prosody["Prosody (XMPP)"]
    Prosody -->|4. Focus Control| Jicofo["Jicofo"]
    
    Client -->|5. WebRTC Media (10000/UDP)| JVB["Jitsi Videobridge (JVB)"]
    JVB -->|6. STUN/TURN Fallback| Coturn["Coturn (3478/UDP)"]
```

---

## 📋 Implementation Checklist

- [ ] Add `MEET` case to `SharedClusterService.php` (`hostPrefix: 'meet'`, `firewallPorts: ['10000/udp']`)
- [ ] Add `MEET` case to `ClusterTool.php`
- [ ] Create `cli/resources/views/k8s/meet/jitsi.blade.php` template
- [ ] Create `cli/app/Commands/Meet/MeetInitCommand.php`
- [ ] Wire Jitsi Meet domain as default conference provider in `matrix.blade.php`
- [ ] Write Pest feature tests (`tests/Feature/JitsiMeetTest.php`)
- [ ] Run PHPStan static analysis (`./php vendor/bin/phpstan`)

---

## ✅ Definition of Done

- `larakube meet:init` deploys Jitsi Meet stack (`web`, `jvb`, `jicofo`, `prosody`) into `larakube-shared`.
- Port `10000/UDP` is automatically opened on DigitalOcean Cloud Firewall and Host UFW.
- Element & Cinny chat rooms natively launch Jitsi Meet video calls.
- Pest tests and PHPStan pass with 0 errors.
