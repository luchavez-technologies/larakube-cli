# Implementation Plan: Clip 4 ("WordPress Quick Launch") Remaster

## Goal Description
Remaster Clip 4 ("WordPress Quick Launch", OpenScreen project `proj_c614892d-78fd-41cb-9aae-e2a63f68fdcf`) strictly adhering to **ADR 0028**, explicitly branding the visual GUI app as **"LaraKube Desktop"**. The video contrasts expensive managed WordPress hosting providers ($35–$284+/month per site) with 1-click self-hosting on LaraKube Desktop with automated database wiring, persistent media storage, and free Let's Encrypt SSL.

---

## The Pitch: Self-Hosted WordPress on LaraKube Desktop vs. Managed Hosting

| Feature | Managed WordPress (Kinsta / WP Engine) | Self-Hosted on LaraKube Desktop |
| :--- | :--- | :--- |
| **Pricing** | $35–$284+/month per single site | **Flat server cost** (runs on the server you already own) |
| **Site Limits** | Strict site quotas & traffic overage bills | **Unlimited sites** across your clusters |
| **Plugin Freedom** | Disallowed plugin lists & arbitrary restrictions | **100% Freedom** (run any theme, custom plugin, or PHP code) |
| **Storage & DB** | Metered SSD storage & heavy upsells | **Persistent block storage** & dedicated MariaDB/MySQL |
| **Setup Experience** | Managed dashboard | **1-Click Launch via LaraKube Desktop** with automated DNS, SSL & ingress |

---

## Scene & Narration Breakdown (Adhering to ADR 0028 Invariants)

### Scene 1: The High Cost of Managed WordPress (Clip 0: 0.0s – 28.29s)
- **Visuals**: Kinsta ($35/mo to $284/mo) and WordPress.com pricing pages, transitioning to WordPress.org showing that WordPress itself is free and open source.
- **Duration**: ~20.0s audio (placed `1.0s – 22.0s`, inside Clip 0 with 6.2s buffer).
- **Subtitles**:
  - `1.0s – 12.0s`: *"Managed WordPress hosting costs $35 to hundreds per month"* (bold, 52px, `y: 90`)
  - `13.0s – 24.0s`: *"WordPress is free & open source — self-host it in one click"* (bold, 52px, `y: 90`)
- **Voiceover Script (James Carlo / eleven_v4)**:
  > *"Managed WordPress hosts like Kinsta and WP Engine charge thirty-five to hundreds of dollars every month for a single site. Even basic plans quickly add up. But WordPress itself is completely free and open source. With LaraKube Desktop, you can self-host high-performance WordPress on your own cluster in just one click."*

---

### Scene 2: 1-Click Launch & Auto-Config (Clip 1: 28.29s – 57.0s)
- **Visuals**: LaraKube Desktop Fleet Dashboard -> Quick Launch -> WordPress -> selecting target cluster (`hello-larakube-desktop` GCP) and subdomain (`blogs.larakube.app`).
- **Duration**: ~18.0s audio (placed `30.0s – 50.0s`, inside Clip 1, ending 7.0s before the speed region).
- **Subtitles**:
  - `29.0s – 41.0s`: *"Pick WordPress from LaraKube Desktop Quick Launch"* (bold, 52px, `y: 90`)
  - `42.0s – 54.0s`: *"Automated database wiring, persistent storage & free SSL"* (bold, 52px, `y: 90`)
- **Voiceover Script (James Carlo / eleven_v4)**:
  > *"Inside LaraKube Desktop, open Quick Launch and choose WordPress. Select your cluster, enter your subdomain, and LaraKube Desktop automatically wires your database, provisions persistent storage for your media uploads, and sets up free Let's Encrypt SSL."*

---

### Fast-Forward: Automated Cluster Deployment (Clip 1: 57.0s – 130.0s)
- **Visuals**: 4× speed region showing container rollout, volume attach, and Traefik ingress routing.
- **Audio**: **SILENT** (Zero audio across speed regions to prevent chipmunk distortion).
- **Subtitle**:
  - `58.0s – 129.5s`: *"Fast-forwarding: provisioning WordPress pod, database & SSL ingress"* (bold, 50px, `y: 90`)

---

### Scene 3: Live Verification & Claim Admin URL (Clip 1: 130.0s – 137.275s)
- **Visuals**: "WordPress is ready" success card with live confetti and copyable Admin URL.
- **Duration**: ~5.2s audio (placed `130.5s – 136.0s`, inside Clip 1, strictly before clip boundary).
- **Subtitle**:
  - `130.5s – 136.8s`: *"WordPress is live with instant admin access"* (bold, 54px, `y: 90`)
- **Voiceover Script (James Carlo / eleven_v4)**:
  > *"Your site is live! LaraKube Desktop provides your secure admin URL ready to open."*

---

### Scene 4: Instant Login & Full Admin Dashboard (Clip 2: 137.275s – 170.0s)
- **Visuals**: Public site loading instantly, navigating to `wp-admin`, logging in with credentials, landing on the full dashboard.
- **Duration**: ~16.0s audio (placed `139.0s – 156.0s`, inside Clip 2).
- **Subtitles**:
  - `138.0s – 148.0s`: *"Log in to the official WordPress admin dashboard"* (bold, 52px, `y: 90`)
  - `149.0s – 165.0s`: *"Full access to themes, plugins, and settings with zero limits"* (bold, 52px, `y: 90`)
- **Voiceover Script (James Carlo / eleven_v4)**:
  > *"Open your admin URL and log in with your credentials. You land directly in the official WordPress dashboard with full access to themes, plugins, and custom settings—with zero hosting restrictions."*

---

### Scene 5: Native Block Editor & Production Payoff (Clip 2: 170.0s – 209.7s)
- **Visuals**: Editing "Hello LaraKube Desktop!" post with the Gutenberg block editor, publishing live, instant update.
- **Duration**: ~17.0s audio (placed `176.0s – 194.0s`, inside Clip 2, ending 15.7s before video ends).
- **Subtitles**:
  - `172.0s – 188.0s`: *"Edit posts natively — changes publish instantly without redeploys"* (bold, 50px, `y: 90`)
  - `189.0s – 208.0s`: *"Enterprise Kubernetes hosting with zero monthly subscription fees"* (bold, 50px, `y: 90`)
- **Voiceover Script (James Carlo / eleven_v4)**:
  > *"Create and edit posts using the native block editor, with changes publishing instantly. You get the full power of WordPress on enterprise-grade Kubernetes infrastructure, without ever paying expensive monthly hosting fees."*

---

## Zoom Plan
- **Zoom 1** (`6.72s – 8.72s`): Existing (1.8× on Kinsta pricing).
- **Zoom 2** (`16.012s – 18.012s`): Existing (1.8× on WordPress.com pricing).
- **Zoom 3** (`35.0s – 40.0s`): **NEW** (1.5× depth 2, focus on cluster and subdomain configuration).
- **Zoom 4** (`50.0s – 55.0s`): **NEW** (1.5× depth 2, focus on launch button).
- **Zoom 5** (`131.0s – 136.0s`): **NEW** (1.5× depth 2, focus on Admin URL card).
- **Zoom 6** (`146.0s – 150.0s`): **NEW** (1.5× depth 2, focus on wp-admin login box).
- **Zoom 7** (`180.0s – 188.0s`): **NEW** (1.5× depth 2, focus on "Hello LaraKube Desktop!" block editor).

---

## Technical Invariants Checklist (ADR 0028)
- [x] Plan documented in `cli/plans/active/clip4-wordpress-revamp.md`.
- [x] Save safety checkpoint in OpenScreen before making edits (`cp_2bd6f8d2-591d-4d04-9794-ddea2ccd6b7e`).
- [x] Generate 5 audio tracks with `eleven_v4`, "James Carlo", and pronunciation dictionary locators (`j2KLW9JVRqfuHUuM3Pdc`).
- [x] Import and place audio tracks adhering to zero-speed and zero-boundary rules.
- [x] Remove 14 legacy annotations.
- [x] Add 8 bold mobile subtitles (`y: 90`, dark background).
- [x] Add 5 targeted zooms.
- [x] Verify rendered frames and document state.
- [x] Save completion checkpoint (`cp_7990a2ee-d0b9-459e-ae46-bc0b7e2e04c1`).
