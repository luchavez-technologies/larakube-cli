# Implementation Plan: Clip 3 ("PocketBase Quick Launch") Remaster

## Goal Description
Remaster Clip 3 ("PocketBase Quick Launch", OpenScreen project `proj_582ad266-4216-4e5a-b722-02e110d71b98`) strictly following **ADR 0028**, explicitly branding the visual GUI app as **"LaraKube Desktop"**. The video contrasts expensive metered backend-as-a-service platforms like Supabase ($25/mo base + user/storage meters) with self-hosting PocketBase on LaraKube Desktop in a single click for flat server costs and 100% data sovereignty.

---

## The Pitch: Self-Hosted PocketBase on LaraKube Desktop vs. Supabase

| Capability | Supabase / Firebase SaaS | PocketBase on LaraKube Desktop |
| :--- | :--- | :--- |
| **Pricing** | $25/month base + metered active users, bandwidth, egress | **Zero subscription** (runs on your existing server/cluster) |
| **Architecture** | Heavy PostgreSQL cluster, microservices, complex config | **Ultra-light single Go binary** with embedded SQLite |
| **Data Sovereignty** | Stored on 3rd-party vendor infrastructure | **100% private** on your own cloud VPS / dedicated hardware |
| **Capabilities** | Realtime database, auth, file storage | Realtime database, auth, file storage, admin UI, OAuth2 |
| **Setup & Maintenance** | Managed, but expensive lock-in | **1-Click launch via LaraKube Desktop** with automated SSL & persistent volumes |

---

## Scene & Narration Breakdown (Adhering to ADR 0028 Invariants)

### Scene 1: The Trap of Metered Backend SaaS (Clip 0: 0.0s – 18.14s)
- **Visuals**: Supabase pricing page ($25/mo Pro tier) transitioning to PocketBase open-source homepage.
- **Duration**: ~16.5s audio (placed `1.0s – 17.5s`, inside Clip 0).
- **Subtitles**:
  - `1.0s – 8.5s`: *"Supabase costs $25/month just to start, plus metered fees"* (bold, 54px, `y: 90`)
  - `9.0s – 17.5s`: *"PocketBase gives you realtime DB, auth & storage for free"* (bold, 54px, `y: 90`)
- **Voiceover Script (James Carlo / eleven_v4)**:
  > *"Services like Supabase charge twenty-five dollars a month just to get started, with steep metered pricing for bandwidth and active users. PocketBase gives you that exact same power—a real-time database, authentication, and file storage—completely free and open-source. And with LaraKube Desktop, you can launch it in a single click."*

---

### Scene 2: 1-Click Launch & Auto-Config (Clip 1: 18.14s – 46.0s)
- **Visuals**: LaraKube Desktop Fleet Dashboard -> Quick Launch -> PocketBase -> selecting cluster and subdomain.
- **Duration**: ~13.5s audio (placed `21.0s – 35.0s`, inside Clip 1, well before speed region).
- **Subtitles**:
  - `20.0s – 35.0s`: *"One-click deploy: automated DNS, free SSL & persistent storage"* (bold, 52px, `y: 90`)
  - `36.0s – 45.0s`: *"Embedded SQLite with automatic persistent block storage"* (bold, 54px, `y: 90`)
- **Voiceover Script (James Carlo / eleven_v4)**:
  > *"Inside LaraKube Desktop, select PocketBase from the Quick Launch menu. Choose your target cluster, set your subdomain, and LaraKube Desktop handles the rest—configuring automated DNS, free Let's Encrypt SSL, and persistent block storage for your SQLite database."*

---

### Fast-Forward: Automated Provisioning (Clip 1: 46.0s – 84.0s)
- **Visuals**: 4× speed region showing container deployment and ingress routing.
- **Audio**: **SILENT** (Zero audio across speed regions to prevent chipmunk distortion).
- **Subtitle**:
  - `47.0s – 83.5s`: *"Fast-forwarding: provisioning PocketBase container, storage & ingress"* (bold, 50px, `y: 90`)

---

### Scene 3: Live Verification & Admin Credentials (Clip 1: 84.0s – 94.23s)
- **Visuals**: "PocketBase is ready" success card with Admin URL, generated email & password.
- **Duration**: ~7.0s audio (placed `85.5s – 92.5s`, inside Clip 1, strictly before clip boundary).
- **Subtitle**:
  - `85.0s – 93.5s`: *"Live in under a minute with auto-generated admin credentials"* (bold, 52px, `y: 90`)
- **Voiceover Script (James Carlo / eleven_v4)**:
  > *"In under a minute, PocketBase is live. LaraKube Desktop automatically generates your admin credentials so you can log in immediately."*

---

### Scene 4: Superuser Login & Production Payoff (Clip 2: 94.23s – 118.04s)
- **Visuals**: Logging into PocketBase admin UI, navigating Collections, Files, and Backups.
- **Duration**: ~11.0s audio (placed `96.0s – 107.5s`, inside Clip 2).
- **Subtitles**:
  - `95.0s – 105.0s`: *"Log in to your private admin console to manage collections & auth"* (bold, 52px, `y: 90`)
  - `106.0s – 117.5s`: *"Production-ready backend with zero monthly SaaS subscription bills"* (bold, 52px, `y: 90`)
- **Voiceover Script (James Carlo / eleven_v4)**:
  > *"Open your admin URL and log in to your dashboard. You now have a complete, production-ready backend for your web and mobile apps, with full data privacy, automatic backups, and zero monthly SaaS fees."*

---

## Zoom Plan
- **Zoom 1** (`29.1s – 33.0s`): Existing (1.8× on cluster selection).
- **Zoom 2** (`36.9s – 45.0s`): Existing (1.5× on domain and deploy button).
- **Zoom 3** (`85.5s – 93.0s`): **NEW** (1.5× depth 2, focus on Admin URL and credentials card).
- **Zoom 4** (`95.0s – 98.0s`): **NEW** (1.5× depth 2, focus on Superuser login form).
- **Zoom 5** (`107.0s – 115.0s`): **NEW** (1.5× depth 2, focus on Admin Settings / File storage & Backups).

---

## Technical Invariants Checklist (ADR 0028)
- [x] Model: `eleven_v4`.
- [x] Voice: `MygA9aaydxOchycGVIMI` ("James Carlo").
- [x] Dictionary locators: `j2KLW9JVRqfuHUuM3Pdc` (version `2pGB88vwBhxGC4Uc1EDx`).
- [x] Naming: Explicitly **"LaraKube Desktop"** for visual app actions.
- [x] Zero audio across speed region (`46.0s – 84.0s`).
- [x] Zero audio crossing clip cuts (`18.14s`, `94.23s`).
- [x] Remove 10 legacy 32px annotations.
- [x] Add 8 bold mobile subtitles (`y: 90`, dark background).
- [x] Add 3 targeted zooms (credentials card, login form, admin settings).
- [x] Save safety checkpoints (`cp_a3c2272a-691d-4f63-a12f-f415cff07cbe`, `cp_5babb032-7e1c-44cd-ab1a-4bbc49239c1b`).
