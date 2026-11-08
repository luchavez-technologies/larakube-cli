# Implementation Plan: Clip 1 ("Create a Server") Remaster

## Goal Description
Remaster Clip 1 ("Create a Server", OpenScreen project `proj_749f716c-f269-4c1a-8117-8ac1573fad22`) to address viewer feedback on YouTube:
1. **Compelling Story**: Retell the journey from a simple VPS/GCP instance into a fully managed Kubernetes cluster, emphasizing self-healing, automated DNS/SSL, and future-proof scalability.
2. **Studio Voiceover**: Generate a conversational, warm voiceover using ElevenLabs (`Adam`) timed to visual actions and the 6x fast-forward provisioning phase.
3. **Mobile Legibility & Zooms**: Add targeted zooms (depth 2–3: 1.5x–1.8x) onto active dropdowns, Cloudflare token inputs, and status indicators.
4. **Legible Subtitles**: Upgrade the 26px–30px annotations to bold 65px bottom subtitles on dark pills that pop clearly on mobile screens.

---

## User Review Required
> [!IMPORTANT]
> - **Voice Model**: ElevenLabs `Adam` will be used for synthesis.
> - **Resolution / Timebase**: Timeline virtual duration is ~282s (with virtual seconds 85.3s to 251.3s sped up 6x, yielding ~27s of actual screen time during cluster provisioning).
> - **Script Timing**: The narration must fit within the exact visual spans to avoid running over cuts or fast-forwards.

---

## Proposed Script & Story Arc (Timestamped)

### Scene 1: The Hook & The Promise (0:00 - 0:18)
- **Visuals**: LaraKube Cloud Dashboard, clicking "Create Server". (Zoom depth 2 on the server wizard).
- **Subtitles**: *Turn any cloud instance into a production Kubernetes cluster in 3 minutes.*
- **Spoken Narration (Adam)**:
  > *"Traditionally, deploying Kubernetes meant days of wrestling with complex YAML manifests, VPC networking, and cluster ingress. Most developers just settle for a simple VPS. But what if you could take a standard cloud instance and instantly turn it into a production-grade Kubernetes cluster with zero complexity? With LaraKube, you can do it in under three minutes."*

### Scene 2: Provider & Server Sizing (0:18 - 0:48)
- **Visuals**: Selecting Google Cloud (GCP), choosing region, and selecting the $24/mo compute tier. (Zoom depth 2 on instance configuration).
- **Subtitles**: *Select your cloud provider, region, and server sizing.*
- **Spoken Narration (Adam)**:
  > *"We'll start by selecting our cloud provider. LaraKube works with Google Cloud, DigitalOcean, or custom VPS providers. Whether you're starting on a budget machine or a twenty-four dollar compute instance like we're choosing here on GCP, pick a region close to your users, and LaraKube handles the rest."*

### Scene 3: Automated Networking & Cloudflare Token (0:48 - 1:18)
- **Visuals**: Switching to Cloudflare, pasting the scoped API token, validating connection. (Zoom depth 2 on API token & validation checkmark; blur applied to token value).
- **Subtitles**: *Zero-config DNS and automated Let's Encrypt SSL via Cloudflare.*
- **Spoken Narration (Adam)**:
  > *"Next is networking. LaraKube automates DNS routing and SSL certificate issuance using Cloudflare. Just drop in an API token with DNS Edit permissions. LaraKube verifies the token on the fly, and your domain is ready for secure HTTPS out of the box."*

### Scene 4: The 6x Fast-Forward: What Happens Under the Hood (1:18 - 2:05 screen time / 85s - 251s ruler)
- **Visuals**: The provisioning progress bar and terminal logs speeding by at 6x.
- **Subtitles**: *Automated K3s installation, security hardening, and Traefik ingress.*
- **Spoken Narration (Adam)**:
  > *"Once you click launch, LaraKube takes over. Behind the scenes, it's installing a hardened K3s runtime, configuring Traefik as your cluster ingress, setting up persistent local storage classes, and locking down firewall rules. What used to require a dedicated DevOps team is happening completely automatically in real time."*

### Scene 5: The Payoff & Future-Proofing (2:05 - 2:25 / 252s - 282s ruler)
- **Visuals**: "Server Ready" status, cluster dashboard, node metrics. (Zoom depth 2.5 on healthy status badge).
- **Subtitles**: *Your cluster is live and ready for instant one-click apps.*
- **Spoken Narration (Adam)**:
  > *"In just under three minutes, your server is live and healthy. You now have the power of Kubernetes—automatic self-healing, zero-downtime rolling updates, and container isolation—without the enterprise overhead. In the next clips, we'll deploy production apps like n8n, PocketBase, and WordPress in a single click."*

---

## Technical Execution Steps

### 1. Audio Synthesis via ElevenLabs
- Create a synthesis script using the official ElevenLabs Node/Python SDK with `.env.local` credentials.
- Generate high-bitrate `.mp3` files for each scene (`scene1_hook.mp3`, `scene2_provider.mp3`, `scene3_cloudflare.mp3`, `scene4_provisioning.mp3`, `scene5_payoff.mp3`).
- Audio files will be stored in `/tmp/clip1_audio/`.

### 2. OpenScreen Document Updates (`proj_749f716c-f269-4c1a-8117-8ac1573fad22`)
- **Import Media**: Call `importMedia` for each audio asset.
- **Timeline Audio**: Call `addAudio` to attach each voiceover to its precise timeline offset.
- **Subtitles**:
  - Remove the original 10 small text annotations (`ann_f1d2215c...`, `ann_0a421759...`, etc.) using `removeModifier`.
  - Add 5 new bold subtitles (`fontSize: 65`, `y: 90`, centered, high contrast `rgba(0,0,0,0.85)` background).
  - Retain the privacy blur (`ann_f2cc9f04...`) over the Cloudflare token.
- **Zooms**:
  - Add targeted zooms (`depth: 2` and `3`) for the provider dropdown, region list, Cloudflare token input, and ready state.

### 3. Verification & Export
- Check document state via `getCurrentDocument`.
- Validate frame previews using `getFrames`.
- Call `exportVideo` to render the final remastered MP4 ready for YouTube.
