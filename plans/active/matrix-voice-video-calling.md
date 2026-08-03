# Plan: Matrix Voice & Video Calling Stack (Coturn TURN + LiveKit RTC)

**Status:** Active Plan / Grounded in Codebase (`matrix.blade.php`)
**Created:** 2026-08-04
**Target Version:** LaraKube CLI v1.2.0

---

## 🎯 Objective

Upgrade `chat:init` (Matrix / Synapse `matrixdotorg/synapse:v1.120.0` + Cinny Web Client `ghcr.io/cinnyapp/cinny:v4.12.3`) to automatically support real-time **1-on-1 voice/video calling** and **multi-party group video calls** natively out-of-the-box.

In LaraKube's codebase ([`matrix.blade.php`](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/cli/resources/views/k8s/chat/matrix.blade.php)), Cinny and Synapse run on a **single shared domain** (`{{ $host }}` e.g. `chat.example.com` or `chat.dev.test`):
- `/` → `chat-cinny` (Cinny SPA)
- `/_matrix` & `/_synapse` → `chat-synapse` (Synapse Matrix server)
- `/.well-known/matrix` → `chat-synapse` (Delegation endpoint)

This single-domain architecture means **Cinny and Synapse share the exact same domain**, eliminating CORS errors entirely!

---

## 🏛 Real-World Grounded Architecture

```
┌─────────────────────────────────────────────────────────────────────────────┐
│             LaraKube Shared Namespace (`larakube-shared`) — Single Domain   │
│                                                                             │
│  Traefik Ingress: `chat.example.com`                                        │
│  ├── /_matrix & /_synapse ─────────────────► Synapse (matrixdotorg:v1.120.0)│
│  └── / ───────────────────────────────────► Cinny SPA (cinny:v4.12.3)       │
│                                                   │                         │
│                                                   ▼                         │
│  1-on-1 WebRTC Calls                             1-on-1 STUN/TURN          │
│  Cinny queries `/_matrix/client/v3/turnServer`   Credentials via HMAC      │
│                    │                               │                        │
│                    └───────────────┬───────────────┘                        │
│                                    ▼                                        │
│                     Coturn TURN Pod (`chat-coturn`)                         │
│                     UDP 3478 / 49152-49200                                  │
│                                    │                                        │
│  Group Video Calls                 ▼                                        │
│  LiveKit SFU (`chat-livekit`) ──► Matrix 2.0 MSC3401 Call Widget            │
│  UDP 7882 / TCP 7881                                                        │
└─────────────────────────────────────────────────────────────────────────────┘
```

---

## 🔍 Codebase Grounding Audit (`matrix.blade.php`)

1. **Cinny & Synapse Domain Single-Host Alignment**:
   Because `matrix.blade.php` routes `/_matrix` and `/` under the same Traefik Ingress rule (`{{ $host }}`), Cinny calls `https://{{ $host }}/_matrix/client/v3/turnServer` natively without cross-origin CORS headers needed.
2. **TURN Credentials API (`homeserver.yaml`)**:
   Synapse natively implements Matrix Client-Server API `GET /_matrix/client/v3/turnServer`. When `turn_shared_secret` is configured in `homeserver.yaml`, Synapse generates short-lived HMAC TURN credentials dynamically for Cinny users whenever a 1-on-1 call starts.
3. **Plex Commons Infrastructure Integration (Postgres + SeaweedFS/MinIO S3)**:
   - **Database**: Synapse uses Plex Commons PostgreSQL database (`chat_matrix` tenant) or bundled `chat-synapse-db` (`postgres:15-alpine` when `--no-plex` is passed).
   - **S3 Media Offloading**: `ClusterTool::CHAT` is mapped in `commonsBuckets() => ['chat-media']`. `chat:init` automatically provisions the `chat-media` S3 bucket in Plex Commons (SeaweedFS / MinIO) and configures Synapse's S3 media provider plugin to store all photo, audio, and video uploads directly on S3 storage, keeping disk usage lightweight.
   - Coturn requires no database (stateless HMAC authentication via shared secret).

---

## 🌐 Topology & Multi-Node Parity

| Topology Tier | UDP Traffic Routing | STUN/TURN Listener |
|---|---|---|
| **Single-Node K3s VPS ($12/mo)** | Direct `hostPort: 3478` (Coturn) & `hostPort: 7882` (LiveKit) | Binds to single VPS node IP |
| **Multi-Node Cluster (DOKS / EKS / AKS)** | Traefik `IngressRouteUDP` CRDs / `LoadBalancer` Service | Node-agnostic routing (survives pod reschedules across nodes) |
| **Local Dev (`.dev.test`)** | Local `hostPort` / Docker bridge | `127.0.0.1` |

> [!IMPORTANT]
> **Multi-Node Resilience**: On multi-node Kubernetes clusters, static `hostPort` bindings fail when pods move across worker nodes. LaraKube automatically detects `SharedStorageGuard::isMultiNode()` and deploys Traefik `IngressRouteUDP` CRDs for node-agnostic UDP packet routing.

### Automated External Public IP Discovery
WebRTC TURN servers must announce their public IP address in ICE candidates so clients outside the cluster can traverse NATs.
1. `InteractsWithClusterContext::resolveExternalIp()` automatically queries:
   - Multi-node: Traefik LoadBalancer External IP (`{.status.loadBalancer.ingress[0].ip}`)
   - Single-node VPS: Cluster Node External IP (`159.89.205.239`)
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
---

## 🛡️ Production Resilience & Edge-Case Safeguards

1. **Matrix Federation `.well-known` Delegation**:
   `chat:init` provisions a Traefik Ingress Middleware serving `https://{domain}/.well-known/matrix/server` returning `{"m.server": "matrix.{{ $domain }}:443"}` so external Matrix homeservers (`matrix.org`, partner companies) can discover and federate with your cluster automatically.
2. **Plex Commons S3 Media Storage (`larakube-chat-media`)**:
   Media attachments (photos, voice messages, video clips) are stored in Plex Commons S3 object storage (`StorageDriver::commonsBucketCreateCommand('larakube-chat-media')`) via S3 API, preventing local pod disk bloat.
3. **Traefik CORS & Cross-Origin Alignment**:
   `homeserver.yaml` is configured with `public_baseurl: "https://matrix.{{ $domain }}"` and `allow_origin: "*"` so Cinny (`chat.{domain}`) can send WebRTC REST and WebSocket API requests without browser CORS errors.
4. **Coturn & Synapse Secret Synchronization**:
   `turn_shared_secret` is stored in a single Kubernetes Secret (`chat-turn-secret`) mounted by both Synapse and Coturn pods simultaneously, preventing secret drift and WebRTC call drops.

---

## 📋 Implementation Checklist

- [ ] Create Coturn deployment template (`resources/views/k8s/tools/chat-coturn.blade.php`)
- [ ] Create LiveKit SFU deployment template (`resources/views/k8s/tools/chat-livekit.blade.php`)
- [ ] Update `resources/views/k8s/tools/chat-synapse.blade.php`:
  - Inject `turn_shared_secret` and `turn_uris` into `homeserver.yaml` Secret
  - Add `.well-known/matrix/server` delegation Traefik middleware
  - Wire S3 media repository settings to `larakube-chat-media`
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
