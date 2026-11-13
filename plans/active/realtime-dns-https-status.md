# Implementation Plan: Realtime DNS & HTTPS Readiness Indicators (`DNS` + `HTTPS` Pills)

## Context & Goal
When deploying a project or installing a cluster app on a cloud server with **ExternalDNS** and **Traefik**, there is a ~30–90 second window after Kubernetes pods become `Running` before the public hostname is actually reachable:
1. **ExternalDNS** reconciles the Ingress and creates the `A` / `CNAME` record at the DNS provider (~15–60s).
2. **Traefik / ACME (Let's Encrypt)** completes the TLS challenge and swaps out `TRAEFIK DEFAULT CERT` (and avoids Cloudflare `525`/`526` origin SSL errors) (~10–45s).

We will add two compact, live-polling status pills (`DNS` and `HTTPS`) to public cloud hostnames in LaraKube Desktop so users can see in real time when DNS propagation and Let's Encrypt HTTPS are ready before clicking **Visit**, without truncating the displayed URL.

---

## Step 1: Persist the Plan Document (`AGENTS.md` Mandatory Audit Trail)
Write a copy of this plan to:
1. `/Users/jsluchavez/Codes/Ideas/laravel-k8s/cli/plans/active/realtime-dns-https-status.md`
2. `/Users/jsluchavez/.gemini/antigravity/brain/b3cd5fba-9cb3-490f-a948-3ebe5c73cf8c/realtime-dns-https-status-plan.md` (with `ArtifactMetadata`: `RequestFeedback: false`, `UserFacing: true`)

---

## Step 2: Backend Probe Service (`desktop/app/Services/LaraKube/DomainProbe.php`)
- `public function check(string $host): array`
  - Normalizes `$host` (strips protocol, path, port, trailing dot, lowercases) and short-circuits empty or local dev (`.test` / `.localhost` / `localhost`) domains.
  - **DNS Probe**: Queries Cloudflare DoH JSON (`https://cloudflare-dns.com/dns-query?name=<host>&type=A`) so local OS negative-TTL caching does not hide fresh ExternalDNS records.
  - **HTTPS Probe**: Probes `https://<host>` with `verify => true` and `allow_redirects => false`. Rejects TLS errors (e.g. `TRAEFIK DEFAULT CERT`) and Cloudflare `525` / `526` origin SSL responses; accepts any HTTP status `100..599` over verified TLS.

---

## Step 3: Controller & Route
- `desktop/app/Http/Controllers/DomainStatusController.php` (`show`)
- `desktop/routes/web.php`: `GET /domains/status` (`domains.status`)

---

## Step 4: Frontend Component (`desktop/resources/js/components/domain-status-pills.tsx`)
- Props: `host`, optional `showDetail?: boolean`, optional `className`.
- Renders compact `DNS` and `HTTPS` pills (green `Check` / `Lock` when ready, pulsing amber dots when pending) with click-to-recheck, hover diagnostic tooltips, module-level cache, and 5-second auto-polling while pending (stopping automatically once both are ready).
- When `showDetail` is true, renders a truncated summary next to the pills (`Checking DNS & TLS...`, pending DNS/TLS detail, or resolved IP + `TLS active`).

---

## Step 5: Wire Into All 3 Pages (Preserving Full URL Visibility)
1. **Cluster Tools / Apps Page (`desktop/resources/js/pages/tools/index.tsx`)**:
   - **Card view (`InstalledToolCard`)**: Two-row URL box — Row 1 keeps full `https://${tool.host}` + `QrCodePopover` + `Visit`; Row 2 shows `<DomainStatusPills host={tool.host} showDetail className="w-full border-t border-line/50 pt-2" />`.
   - **Table view**: Renders `<DomainStatusPills host={tool.host} />` alongside `tool.host`.
2. **Project Detail Page (`desktop/resources/js/pages/projects/show.tsx`)**:
   - **Production Public Address**: Row 1 keeps `https://{activeHost}` + QR + External Link; Row 2 shows `<DomainStatusPills host={activeHost} showDetail className="mt-1.5" />`.
3. **Server Detail Page (`desktop/resources/js/pages/servers/show.tsx`)**:
   - **Hosted Projects** & **Installed Apps**: Inline `<DomainStatusPills />` beside wide row hostnames.

---

## Step 6: Feature Tests & Full Verification Suite
- `desktop/tests/Feature/DomainStatusTest.php`
- Full checks in `desktop/`: `npx vp check --fix && npx tsc --noEmit`, `composer lint`, and `composer test` (Pint, PHPStan, Pest parallel suite).
