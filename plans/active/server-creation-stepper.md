# Server Creation Stepper Redesign

## Context & Motivation
During workshops and live demos, operators and students found the single-page "Create Server" form overwhelming and difficult to follow due to a lack of visual cues and progressive disclosure:
- The form loaded all provider cards, accounts, server naming, VPS vs. Managed options, region/size selectors, and a collapsed DNS/SSL accordion on a single view.
- Instructors had difficulty directing students ("which button to click next") because there were no sequential step numbers, progression markers, or forward buttons.
- While Auto-DNS and Auto-TLS are optional, turning them on by default makes deploying apps and cluster tools drastically easier (automated HTTPS and routing without manual DNS records).

## Architecture & Stepper Breakdown

### Step 1: Choose Provider
- **Goal**: Select cloud/VPS provider and verify authorization.
- **Components**:
  - Provider grid: DigitalOcean, Hetzner Cloud, Google Cloud Platform, Amazon Web Services.
  - Multi-account switcher & "+ Manage Accounts" modal trigger.
  - Authentication status alerts (e.g. Sign in with Google, enter API tokens).
- **Navigation**:
  - Cancel (`/servers`).
  - Next: "Next: Customize Server" (`ArrowRight` icon). Enabled once the provider credentials/requirements are satisfied.

### Step 2: Customize Server
- **Goal**: Configure instance identity, compute specs, and location.
- **Components**:
  - Server Name input (with placeholder `workshop-demo` and naming rules).
  - Server Type selector: Single server (VPS) vs. Managed Kubernetes (EKS/GKE/DOKS).
  - Region & Size selectors with live/cached pricing estimate indicators.
  - Managed Kubernetes controls (Node count, High Availability control plane).
  - Provider-specific key overrides (e.g. AWS access keys or per-run tokens).
- **Navigation**:
  - Back: "Back to Provider" (`ArrowLeft` icon).
  - Skip option: "Create without DNS" / "Skip to Create" (`Plus` icon).
  - Next: "Next: DNS & SSL (Recommended)" (`ArrowRight` icon). Enabled once server name is valid.

### Step 3: Automate DNS & SSL (Optional & Recommended)
*(Skipped automatically for Dev Boxes)*
- **Goal**: Configure ExternalDNS and Let's Encrypt wildcard certificates.
- **Components**:
  - High-visibility banner explaining why Auto-DNS and Auto-TLS make workshops and deployments seamless.
  - DNS & TLS Provider selection: Cloudflare (Recommended), AWS Route 53 (Experimental), Google Cloud DNS (Experimental), or None (Skip).
  - Default toggles: `enable_dns` (`true`) and `enable_tls` (`true`) checked by default.
  - Provider credential forms (Cloudflare API token, Route 53 IAM keys, Google Cloud DNS service account JSON).
- **Navigation**:
  - Back: "Back to Server Specs" (`ArrowLeft` icon).
  - Primary Submit: "+ Create Server" (`Plus` icon).

### Top Stepper Navigation
- Responsive step navigation bar with step numbers, icons (`Cloud`, `Server`, `Globe`), status checkmarks, active ring highlights, and connecting progress lines.
- Allows direct navigation to previously completed or unlocked steps.

## Verification & Testing
- Format & lint checks via `npm run check:fix` and `npm run types:check`.
- Pest tests via `./vendor/bin/pest`.
- Visual verification across VPS and Managed options for all 4 providers.
