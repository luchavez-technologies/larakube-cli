# Plan: Matrix Voice & Video Calling Stack (Coturn TURN + LiveKit RTC)

**Status:** ✅ SUPERSEDED 2026-08-07 — calling works; the architecture here no longer exists.
**Created:** 2026-08-04
**Superseded:** 2026-08-07 (commits `ca50daa`, `c858fdb`)
**Read instead:** `docs/decisions/0009-shared-livekit-and-per-consumer-keys.md`

---

## 0. What changed

This plan describes LiveKit and Coturn living *inside* the chat stack on one shared
`chat.example.com` host, against `synapse:v1.120.0`. None of that is true any more:

- **LiveKit moved out into its own `meet` tool** at `meet.<domain>` — `meet:init` / `meet:wire` /
  `meet:unwire` / `meet:remove` / `meet:show`. Chat no longer ships an SFU, and the two cannot
  coexist on one node anyway: both bind hostPort 7881/7882, which are exclusive per node.
- **The lk-jwt bridge belongs to the wiring**, deployed by `meet:wire --tool=chat` at
  `meet.<domain>/jwt`. Chat's two stripPrefix middlewares are gone.
- **Coturn stayed with chat** — it backs Synapse's legacy 1:1 `turn_uris`, unrelated to the SFU.
- **Synapse is on v1.158.0**, with `msc4140_enabled`, `max_event_delay_duration` and raised
  `rc_message` / `rc_delayed_event_mgmt` — none of which this plan knew it needed.
- **The RTC focus is served via `extra_well_known_client_content`.** This plan's
  `well_known: client:` shape is **not a Synapse option** and never took effect (see §0 of the
  archived focus-selection plan).

Still live and unbuilt from this plan's scope: multi-node UDP exposure, tracked separately in
`plans/active/matrix-calling-multinode.md`.

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

## 📋 Actual Implementation Status & Deployed Architecture

### Deployed Services in `matrix.blade.php`:
1. **LiveKit SFU Server (`livekit/livekit-server:v1.8.0`)**: Runs on port `7880` and UDP RTC port `7882`. Configured via `chat-livekit-config` with a 32-character API secret requirement (`livekitApiSecret`).
2. **LiveKit JWT Service (`ghcr.io/element-hq/lk-jwt-service:latest`)**: Runs on port `8080`, routed via Ingress path `/livekit/jwt`. Evaluates user Matrix room membership and issues signed JWT tokens for LiveKit spatial group calls.
3. **Matrix MSC3401 Discovery**: Served by Cinny's Nginx at `/.well-known/matrix/client` with explicit `default_type application/json;` and `Access-Control-Allow-Origin *;` headers.
4. **Coturn TURN/STUN (`coturn/coturn:4.6.3`)**: Handles peer-to-peer 1-on-1 WebRTC calls on port `3478`.
5. **Synapse S3 Storage Provider (`initContainer`)**: Mounts `synapse-s3-storage-provider` to offload room media attachments to SeaweedFS/MinIO S3 storage (`chat-media` bucket).

---

## 🔐 Matrix Server & Room Administration Standard Operating Procedure (SOP)

> [!CAUTION]
> **Strict Zero-SQL Mutation Policy**:
> Matrix Synapse computes cryptographic SHA-256 Event IDs for every state event (including `m.room.power_levels`). Direct `UPDATE` SQL mutations on `event_json` cause `DatabaseCorruptionError` because the stored Event ID no longer matches the calculated hash.
> All room power level changes and user administration MUST be performed via Matrix Client/Server REST APIs.

### Official API Workflow for Room Admin Elevation:
1. Ensure the user has `admin = 1` in Synapse `users` table (Synapse Server Superadmin).
2. Fetch an Admin Access Token or Session for the user.
3. Send a standard Matrix Client API request to update room power levels:
   `PUT /_matrix/client/v3/rooms/{roomId}/state/m.room.power_levels`
   ```json
   {
     "users": {
       "@james=40luchtech.dev:chat.luchtech.dev": 100,
       "@admin=40luchtech.dev:chat.luchtech.dev": 100,
       "@eman=40luchtech.dev:chat.luchtech.dev": 100
     }
   }
   ```
4. Synapse signs the state event, appends it to the room DAG, computes the new SHA-256 Event ID, and broadcasts the state update to all connected clients (`Cinny`).

---

## ✅ Verified & Battle-Tested

- `chat:init` deploys all 5 Matrix pods (`chat-synapse`, `chat-cinny`, `chat-coturn`, `chat-livekit`, `chat-lk-jwt`) into `larakube-shared`.
- `curl -i https://chat.luchtech.dev/.well-known/matrix/client` returns `HTTP/2 200` with `content-type: application/json`.
- LiveKit JWT authentication endpoint `https://chat.luchtech.dev/livekit/jwt` is live and active.
- Synapse `/sync` requests process with 0 corruption errors.
