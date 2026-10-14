# Server Detail: Multi-Provider Domain & SSL Configuration

## Context & Problem
When a server is provisioned without DNS or SSL automation (e.g. creating with "Create without DNS"), the operator sees the **Domain & SSL** card on the Server Detail page (`servers/show.tsx`):
- Currently, the card labels and descriptions exclusively refer to Cloudflare:
  - *"Create DNS records for your tools automatically with Cloudflare."*
  - *"Renew certificates through Cloudflare, even behind its proxy."*
- Clicking "Set up" or "Enable" opens `CloudflareDialog`, which strictly prompts for a Cloudflare API token.
- On AWS (e.g. EC2 / EKS) or GCP servers, operators cannot configure their cloud provider's native DNS services (AWS Route 53 or Google Cloud DNS).
- Furthermore, setting up DNS and SSL are treated as two disconnected flows, whereas operators frequently want to enable both simultaneously.
- Action buttons ("Set up", "Enable", "Connect", "Cancel") do not include icons as required by the LaraKube Button Icon Standard.

## Architecture & Requirements

### 1. Provider-Aware "Domain & SSL" Card
- **Unconfigured State**:
  - Clarify that ExternalDNS and Let's Encrypt wildcard SSL can be automated via **Cloudflare**, **AWS Route 53**, or **Google Cloud DNS**.
  - Action buttons:
    - Domain: "Set up" with `<Plus className="size-3.5" />`
    - SSL: "Enable" with `<ShieldCheck className="size-3.5" />`
- **Configured State**:
  - Display the active provider name dynamically (e.g. `Cloudflare`, `AWS Route 53`, `Google Cloud DNS`) based on cluster inspection (`provider` key in `DnsGroup` and `TlsReport`).
  - Action buttons:
    - Domain: "Add domain" with `<Plus className="size-3.5" />`
    - SSL: "Change" with `<RotateCw className="size-3.5" />`

### 2. Multi-Provider DNS Setup Modal (`ConnectDomainDialog`)
- **DNS Provider Selector**:
  - `cloudflare`: Cloudflare (Recommended)
  - `route53`: AWS Route 53 (Experimental)
  - `google`: Google Cloud DNS (Experimental)
- **Smart Preselection**:
  - If the server's cloud provider is AWS, preselect `route53` (or `cloudflare`).
  - If the server's cloud provider is GCP, preselect `google` (or `cloudflare`).
  - Otherwise default to `cloudflare`.
- **Provider-Specific Credential Inputs**:
  - **Cloudflare**: API token input + Cloudflare API token dashboard link + optional group name.
  - **AWS Route 53**: Access Key ID, Secret Access Key, Region.
  - **Google Cloud DNS**: Project ID, Service Account credentials JSON textarea / Browse JSON file button.
- **Combined Automation Toggle**:
  - Checkbox: `Also enable Automatic SSL certificates (DNS-01 challenge)` (checked by default).
  - When checked, setting up domain also initializes TLS certificates using the same provider credentials in one step.

### 3. Multi-Provider SSL Enable Modal (`EnableSslDialog`)
- If DNS is already configured on the cluster:
  - Provide a 1-click option: `Reuse stored {provider} credentials from ExternalDNS` (default, reuses stored cluster secret via `--group=`).
  - Or toggle to enter new / separate credentials.
- If DNS is not yet configured:
  - Offer provider selector (Cloudflare, Route 53, Google Cloud DNS) and matching credential inputs.

### 4. Backend Request & Controller Support
- Extend `app/Http/Requests/CloudflareStepRequest.php`:
  - Rename / expand to support `dns_provider` (`cloudflare`, `route53`, `google`).
  - Validate provider-specific fields (`cloudflare_token`, `aws_access_key_id`, `aws_secret_access_key`, `aws_region`, `gcp_project_id`, `gcp_credentials`, `also_enable_ssl`, `reuse_stored`).
  - Pass credentials securely via environment variables (`LARAKUBE_CLOUDFLARE_TOKEN`, `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`, `GCP_PROJECT_ID`, `GCP_SERVICE_ACCOUNT_JSON`).
- In `app/Http/Controllers/ServerController.php`:
  - Update `connectDomain()` to pass `--provider={$provider}` and execute `tool:init --tool=external-dns`. If `also_enable_ssl`, chain or trigger `tls:init`.
  - Update `enableSsl()` to pass `--provider={$provider}` and credentials (or `--group=`).

### 5. Compliance & Verification
- Follow Button Icon Standard for all modal and card action buttons.
- Run `npm run check:fix` and `npm run types:check`.
- Run Pest test suite in `desktop/`.
