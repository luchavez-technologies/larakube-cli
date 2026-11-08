# Implementation Plan: Clip 2 ("n8n Quick Launch") Remaster

## Goal Description
Remaster Clip 2 ("n8n Quick Launch", OpenScreen project `proj_e3be0e5f-a835-47dc-b2e4-952a44b53fc3`) strictly adhering to **ADR 0028**, explicitly branding the visual GUI app as **"LaraKube Desktop"**. The video positions self-hosting n8n via LaraKube Desktop as the superior, cost-effective alternative to n8n Cloud and Zapier.

---

## The Pitch: Why Self-Hosted n8n on LaraKube Desktop Beats Official SaaS

| Feature | Official n8n Cloud / Zapier | Self-Hosted on LaraKube Desktop |
| :--- | :--- | :--- |
| **Pricing Model** | $24–$960/mo billed per execution | **Flat server cost** (runs on the server you already own) |
| **Execution Limits** | Strict monthly caps (2.5k–50k runs) + overage fees | **Unlimited executions** (run millions of workflow tasks free) |
| **Data Privacy** | Sensitive API keys and customer data sit on 3rd-party cloud | **100% Data Sovereignty** (stays on your private cluster) |
| **Custom Code & AI** | Sandboxed or paywalled | Full Python/JS, custom NPM community nodes, local files |
| **Setup Complexity** | 1-click, but expensive recurring lock-in | **1-click via LaraKube Desktop** (automated DNS, SSL, storage, and self-healing) |

---

## Proposed Scene & Narration Breakdown

### Scene 1: The Trap of Pay-Per-Execution Automation (Clip 0: 1.0s – 18.5s)
- **Visuals**: n8n Cloud pricing page showing $24, $60, up to $960/mo tiers and task limits.
- **Subtitle**: *Stop paying per execution — self-host unlimited n8n automations*
- **Narration (James Carlo)**:
  > *"SaaS automation tools like Zapier, Make, and even n8n Cloud charge you for every single task execution. A few active webhooks or AI workflows can easily push your monthly bill from sixty dollars to nearly a thousand dollars. But n8n is completely open-source. With LaraKube Desktop, you can run unlimited workflows on your own cluster for zero additional cost."*

### Scene 2: The 1-Click Private Launch (Clip 1: 22.0s – 47.0s)
- **Visuals**: Selecting n8n in LaraKube Desktop Quick Launch, typing subdomain `n8n`, persistent storage confirmed.
- **Subtitle**: *Automated DNS, free Let's Encrypt SSL, and persistent storage in one click*
- **Narration (James Carlo)**:
  > *"Inside LaraKube Desktop, pick n8n from the Quick Launch menu. Type your subdomain, and LaraKube Desktop automatically routes your DNS, provisions free SSL, and attaches persistent storage for your workflow database."*

### Fast-Forward 1: Automated Cluster Deployment (Clip 1: 49.1s – 93.1s)
- **Visuals**: Fast-forward at 4× speed showing container rollout and volume attach.
- **Audio**: *Silent (zero audio mangling).*
- **Subtitle**: *Fast-forwarding: deploying n8n containers, networking & storage*

### Scene 3: Live Verification & Data Privacy (Clip 1: 94.0s – 100.5s)
- **Visuals**: Green deployment checkmark and copyable Admin URL.
- **Subtitle**: *Your private automation engine is live with 100% data sovereignty*
- **Narration (James Carlo)**:
  > *"In seconds, your private automation engine is live. Your API keys, customer data, and database credentials stay safely inside your own infrastructure."*

### Scene 4: Instant Admin Setup (Clip 2: 102.0s – 107.5s)
- **Visuals**: Browser opening to n8n owner setup screen.
- **Subtitle**: *Set up your owner account to secure your private workspace*
- **Narration (James Carlo)**:
  > *"Open your secure admin URL and create your owner account to lock down your workspace."*

### Fast-Forward 2: Workspace Preferences (Clip 2: 108.6s – 148.2s)
- **Visuals**: Fast-forward at 2× speed entering credentials and opening the builder.
- **Audio**: *Silent (zero audio mangling).*
- **Subtitle**: *Setting up owner credentials and workspace preferences*

### Scene 5: Payoff — Unlimited Automations (Clip 2: 149.0s – 156.5s)
- **Visuals**: Landing on the full n8n visual automation builder canvas with nodes.
- **Subtitle**: *Run millions of tasks, webhooks, and AI workflows with zero fees*
- **Narration (James Carlo)**:
  > *"You're in. You now have the full power of n8n—hundreds of integrations, custom code, and AI agents—running with unlimited executions and zero monthly subscription fees."*

---

## Technical Invariants Checklist (ADR 0028)
- [x] Model: `eleven_v4` with voice `MygA9aaydxOchycGVIMI` ("James Carlo").
- [x] Pronunciation dictionary locator: `j2KLW9JVRqfuHUuM3Pdc`.
- [x] Product Naming: Explicitly "LaraKube Desktop" across visual app actions.
- [x] Audio tracks isolated from speed regions (`49.1s–93.1s` and `108.6s–148.2s`).
- [x] Audio tracks isolated within single clip boundaries (no cuts crossed).
- [x] High-contrast 60px mobile subtitles (`y: 90`, dark background).
- [x] Targeted 1.5×–1.8× zooms on form inputs and canvas nodes.
