# Plan: Matrix Voice & Video Calling Stack (Coturn TURN + LiveKit RTC)

**Status:** Active Plan / Ready for Implementation
**Created:** 2026-08-04
**Target Version:** LaraKube CLI v1.2.0

---

## 🎯 Objective

Upgrade `chat:init` (Matrix / Synapse + Cinny web client) to automatically include real-time **1-on-1 voice/video calling** and **multi-party group video calls** natively out-of-the-box in Cinny and Element clients.

Without a TURN relay and WebRTC SFU, Matrix calls fail behind residential NATs, symmetric firewalls, and cellular networks. This plan integrates:
1. **Coturn**: STUN/TURN media relay server for NAT/firewall traversal.
2. **LiveKit**: Matrix 2.0 WebRTC SFU (Selective Forwarding Unit) for high-performance multi-party group calls.

---

## 🏛 Architecture & Media Flow

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                       LaraKube Shared Namespace (`larakube-shared`)         │
│                                                                             │
│  ┌────────────────────────┐      ┌────────────────────────┐                 │
│  │   Cinny / Element Web  │      │     Synapse Matrix     │                 │
│  │     `chat.{domain}`    ├─────►│    `matrix.{domain}`   │                 │
│  └───────────┬────────────┘      └───────────┬────────────┘                 │
│              │                               │                              │
│              │ WebRTC Signaling              │ TURN Shared Secret           │
│              ▼                               ▼                              │
│  ┌────────────────────────┐      ┌────────────────────────┐                 │
│  │      LiveKit SFU       │      │  Coturn TURN/STUN Pod  │                 │
│  │     `rtc.{domain}`     │      │   `turn.{domain}:3478` │                 │
│  └───────────┬────────────┘      └───────────┬────────────┘                 │
│              │ UDP 7882                      │ UDP 3478 / 49152-49200       │
│              ▼                               ▼                              │
│  ┌────────────────────────────────────────────────────────┐                 │
│  │              WebRTC Media Stream (Voice/Video)         │                 │
│  └────────────────────────────────────────────────────────┘                 │
└─────────────────────────────────────────────────────────────────────────────┘
```

---

## 🌐 Topology & Multi-Node Parity

| Topology Tier | UDP Traffic Routing | IP Resolution (`turn_uris`) |
|---|---|---|
| **Single-Node K3s VPS ($12/mo)** | Direct `hostPort: 3478` (Coturn) & `hostPort: 7882` (LiveKit) | Auto-detected Node Public IPv4 (`curl -s api.ipify.org`) |
| **Multi-Node Cluster (DOKS / EKS / AKS)** | Traefik `IngressRouteUDP` CRD / `LoadBalancer` Service | Auto-detected LoadBalancer External IP (`kubectl get svc traefik`) |
| **Local Dev (`.dev.test`)** | Local `hostPort` / Docker bridge | Local host IP (`127.0.0.1`) |

> [!IMPORTANT]
> **Multi-Node Resilience**: On multi-node Kubernetes clusters, static `hostPort` bindings fail when pods move across worker nodes. LaraKube automatically detects `SharedStorageGuard::isMultiNode()` and deploys Traefik `IngressRouteUDP` CRDs for node-agnostic UDP packet routing.

### Automated External Public IP Discovery
WebRTC TURN servers must announce their public IP address in ICE candidates so clients outside the cluster can traverse NATs.
1. `InteractsWithClusterContext::resolveExternalIp()` automatically queries:
   - Multi-node: Traefik LoadBalancer External IP (`{.status.loadBalancer.ingress[0].ip}`)
   - Single-node VPS: Cluster Node External IP
   - Local: `127.0.0.1`
2. Optional override via `chat:init --public-ip=x.x.x.x`.

---

## 🔧 Component Specifications

### 1. Coturn (TURN/STUN Relay)
- **Image**: `coturn/coturn:4.6.3-alpine`
- **RAM Footprint**: ~30MB
- **Ports**:
  - `3478/UDP` & `3478/TCP` — STUN/TURN listener (`hostPort` on K3s, `IngressRouteUDP` on Multi-Node)
  - `49152-49200/UDP` — Dynamic media relay port range
- **Synapse Configuration (`homeserver.yaml`)**:
  ```yaml
  turn_shared_secret: "<AUTO_GENERATED_SECRET>"
  turn_uris:
    - "turn:turn.{{ $domain }}:3478?transport=udp"
    - "turn:turn.{{ $domain }}:3478?transport=tcp"
    - "stun:turn.{{ $domain }}:3478"
  turn_user_lifetime: 86400000
  turn_allow_guests: false
  ```

### 2. LiveKit Matrix RTC SFU
- **Image**: `livekit/livekit-server:v1.8.0`
- **RAM Footprint**: ~70MB
- **Ingress Host**: `rtc.{domain}` (WebSocket/HTTP signaling via Traefik Ingress)
- **Media Port**: `7882/UDP` (LiveKit WebRTC media streams)
- **Cinny / Element Client Integration**:
  Automatically configures LiveKit Widget URL in Cinny configuration (`config.json`) for instant 1-click group video calls.

---

## 📐 Command Experience & Flow (`chat:init`)

`chat:init` automatically provisions Coturn and LiveKit alongside Synapse and Cinny:

```bash
# Deploys Synapse + Cinny + Coturn + LiveKit automatically
larakube chat:init [environment] [--public-ip=x.x.x.x]
larakube chat:init [environment]

# Re-wires Coturn & LiveKit secrets when domain changes
larakube chat:init production --domain=example.com
```

### Automatic Secret Generation:
- Generates `turn_shared_secret` (64-char random string) stored in `chat-coturn-secret`.
- Generates LiveKit `api_key` and `api_secret` stored in `chat-livekit-secret`.

---

## 📋 Implementation Checklist

- [ ] Create Coturn deployment template (`resources/views/k8s/tools/chat-coturn.blade.php`)
- [ ] Create LiveKit SFU deployment template (`resources/views/k8s/tools/chat-livekit.blade.php`)
- [ ] Update `resources/views/k8s/tools/chat-synapse.blade.php`:
  - Inject `turn_shared_secret` and `turn_uris` into `homeserver.yaml` Secret
- [ ] Update `InteractsWithChat.php` trait:
  - Add `renderCoturnConfig()`, `renderLiveKitConfig()`
  - Generate TURN and LiveKit API secrets on first init
- [ ] Update Cinny client configuration template (`resources/views/k8s/tools/chat-cinny.blade.php`) to enable LiveKit call widgets
- [ ] Create Pest feature tests (`tests/Feature/ChatVoiceVideoCallingTest.php`)
- [ ] Run `./php vendor/bin/pint` and verify PHPStan (0 errors)

---

## ✅ Definition of Done

- `larakube chat:init` deploys Synapse, Cinny, Coturn, and LiveKit in `larakube-shared`.
- 1-on-1 WebRTC audio/video calls succeed behind NATs via Coturn TURN relay.
- Multi-party group video calls succeed seamlessly in Cinny web client via LiveKit SFU.
- Re-running `chat:init` is idempotent and preserves TURN shared secrets.
