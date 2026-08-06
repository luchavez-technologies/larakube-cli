# Plan: Jitsi Meet Shared Video Conferencing (`larakube meet:init`)

**Status:** Proposed Active Plan
**Created:** 2026-08-06
**Target Version:** LaraKube CLI v1.2.0

---

## 🎯 Objective

Replace experimental **MatrixRTC + LiveKit** video calling with **Jitsi Meet**, providing a battle-tested, rock-solid WebRTC video conferencing engine (`meet.example.com`) embedded seamlessly inside Element and Cinny chat rooms.

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
