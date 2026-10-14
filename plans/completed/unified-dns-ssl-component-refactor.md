# Unified DNS & SSL Provider Component & Flow Refactor

## Context & Architecture

### The Problem
1. **DNS & Auto-TLS Provider Mismatch**:
   - A domain has exactly one authoritative set of nameservers (e.g. Cloudflare, AWS Route 53, or Google Cloud DNS).
   - ExternalDNS publishes `A`/`CNAME` records to that authoritative DNS provider.
   - Auto-TLS with Let's Encrypt DNS-01 challenge creates `_acme-challenge` TXT records on the **authoritative nameservers** for Let's Encrypt to verify domain ownership.
   - Having separate, decoupled dialogs where an operator could set ExternalDNS to Cloudflare and auto-TLS to Route 53 results in guaranteed verification failure.
2. **Duplicated UI Logic**:
   - `Create Server` (`servers/create.tsx`) already has a unified Step 3 ("DNS & TLS") that selects one provider and configures both ExternalDNS and cert-manager / Traefik DNS-01 together.
   - `Show Server` (`servers/show.tsx`) historically kept two separate dialogs with duplicated form inputs, different states, and no shared components.

## Goals & Decisions

### 1. Reusable `DnsTlsFields` Component
- Create `desktop/resources/js/components/dns-tls-fields.tsx`:
  - Shared between `servers/create.tsx` and `servers/show.tsx`.
  - Encapsulates:
    - Provider select (`cloudflare`, `route53`, `google`).
    - Feature checkboxes (`enable_dns`, `enable_tls`).
    - Provider-specific inputs:
      - **Cloudflare**: API token + dashboard link.
      - **AWS Route 53**: Policy instructions + IAM Key ID + Secret Key + Region.
      - **Google Cloud DNS**: Role instructions + Project ID + Service Account JSON textarea + "Browse JSON File…" file reader.
      - **Optional Group Name**: input for group slug.
    - Locked provider mode: When ExternalDNS is already installed on a cluster, lock the provider to match the active DNS provider to prevent misconfiguration.

### 2. Unified "Domain & SSL" Dialog on Show Server
- Consolidate `ConnectDomainDialog` and `EnableSslDialog` into `ConfigureDnsTlsDialog`:
  - When neither is configured: Operator picks the provider once, enters credentials once, and checks both automations (ExternalDNS + auto-TLS).
  - When ExternalDNS is already connected: Recognizes the active provider and offers a 1-click option to enable auto-TLS using stored cluster credentials (`--group=`), preventing provider mismatch.
  - Updates action buttons in the "Domain & SSL" card to open the unified dialog with appropriate context.

### 3. Backend Chaining & Run Handling
- In `CloudflareStepRequest.php`: Support `enable_dns` and `enable_tls` flags.
- In `ServerController.php`:
  - `connectDomain`: Pass `also_enable_tls` in `meta` if `enable_tls` is true.
  - `enableSsl`: Support direct TLS enablement if DNS is already connected or only TLS is requested.
- In `RecordRunOutput.php`:
  - When `RunKind::ConnectDomain` succeeds and `run->meta['also_enable_tls']` is true:
    - Automatically trigger `tls:init` with the same context, provider, and group.

### 4. Verification & Testing
- Update and add feature tests in `ServersTest.php`.
- Run `npm run check:fix`, `npm run types:check`, `composer lint`, and `composer types:check`.
- Run full Pest test suite in `desktop/`.
