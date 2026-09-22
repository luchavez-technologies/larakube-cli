# Google Cloud Platform (GCP) Integration Plan

**Status:** ✅ COMPLETED / VERIFIED  
**Target Date:** Workshop Preparation  
**Target Commands:** `cloud:create`, `cloud:configure`, `cloud:init:gke` (and `cloud:init:doks`), `cloud:scale`, `cloud:destroy`, `cloud:stacks`

---

## 🎯 Executive Summary & Context

Due to account constraints with DigitalOcean for the upcoming workshop, we are adding **Google Cloud Platform (GCP)** support to LaraKube. Google Cloud provides $300 in free trial credits for new accounts, making it ideal for workshop participants.

LaraKube will support **both architectural deployment models** on GCP:
1. **GCP VPS (Google Compute Engine - GCE VM)** *(Primary Workshop Path)*: Single-node K3s setup running on Ubuntu 24.04 LTS (default: `e2-medium`, with `e2-micro` free-tier eligibility). Leverages LaraKube's existing automated host hardening, UFW firewall, fail2ban, and Traefik single-node ingress. Offers lowest friction with zero GKE-specific client plugins required.
2. **GCP Managed Kubernetes (Google Kubernetes Engine - GKE)**: Multi-node managed Kubernetes cluster using GKE Standard (zonal cluster in `us-central1-a` to eliminate regional management fees and maximize Free Tier credits, default: 2× `e2-medium`), with persistent storage via `standard-rwo` (Google Persistent Disk CSI) and external Network LoadBalancer for Traefik.

### 🛡️ Critical Workshop Hole Mitigations (Resolved in Plan)
1. **Zero Manual API Enabling**: OpenTofu templates declare `google_project_service` for `compute.googleapis.com` and `container.googleapis.com` (`disable_on_destroy = false`), so fresh GCP accounts never hit "API disabled" 403 errors.
2. **Hybrid Local Authentication**: Auto-detects `gcloud` Application Default Credentials (ADC) if logged in; gracefully falls back to prompting for a Service Account JSON key path if `gcloud` is not installed.
3. **Frictionless CI Container Registry**: Defaults to **GitHub Container Registry (GHCR)** in `cloud:configure` (zero GCP IAM setup for attendees who already have GitHub accounts), with optional **Google Artifact Registry (GAR)** support.
4. **Zonal Cost Controls**: Enforces zonal GKE clusters (`us-central1-a`) to prevent Google's ~$73/month regional management fee and allow 1–2 node pools.
5. **Clean One-Click Teardown**: Enforces `deletion_protection = false` on GKE and `auto_delete = true` on VM boot disks so `larakube cloud:destroy` cleanly deletes all resources without orphaned cost leaks.
6. **Command Ergonomics**: Provides `cloud:init:gke` for GKE, `cloud:init:managed` as the unified multi-cloud entry point, and maintains backward compatibility for `cloud:init:doks`.

---

## 🏛️ Architectural Overview

```
                               ┌──────────────────────────────────────────────┐
                               │             larakube cloud:create            │
                               └──────────────────────┬───────────────────────┘
                                                      │
                                           Select Cloud Provider
                                           ┌──────────┴──────────┐
                                           │                     │
                                     DigitalOcean           Google Cloud
                                         (do)                  (gcp)
                                                                 │
                                                       Select Target Kind
                                                    ┌────────────┴────────────┐
                                                    │                         │
                                            VPS / Compute Engine       Managed / GKE
                                            (Single-Node K3s)        (Multi-Node Cluster)
                                                    │                         │
                                          OpenTofu: GCE Instance    OpenTofu: GKE Cluster
                                          + VPC Ingress Firewall    + Persistent Disks
                                                    │                         │
                                          LaraKube k3s Pipeline    Traefik + ACME (PVC)
                                          (ProvisionsK3sNode)       (cloud:init:gke)
                                                    │                         │
                                            Bound Environment        Bound Environment
                                          (larakube-<nat-ip>)      (gke_<proj>_<loc>_...)
```

---

## 📦 Detailed Component Changes

### 1. Provider Domain Model: `App\Enums\CloudProvider` & `ManagedProvider`

#### 1.1 Create `App\Enums\CloudProvider`
Promote the hardcoded `PROVIDERS` constant inside `CloudCreateCommand` to an official `App\Enums\CloudProvider` enum. This aligns with `ManagedProvider`, `RegistryProvider`, and `DatabaseDriver`:
- **Cases:** `DO = 'do'`, `GCP = 'gcp'` (and future: `AWS = 'aws'`, `HETZNER = 'hetzner'`).
- **Methods:**
  - `label()`: `'Google Cloud Platform'` / `'DigitalOcean'`
  - `managedProvider()`: Maps `DO -> ManagedProvider::DOKS`, `GCP -> ManagedProvider::GKE`.
  - `regions()`:
    - `us-central1` (Iowa - Default, Free Tier eligible)
    - `us-east1` (South Carolina)
    - `us-west1` (Oregon)
    - `europe-west1` (Belgium)
    - `europe-west3` (Frankfurt)
    - `asia-southeast1` (Singapore)
    - `asia-east1` (Taiwan)
  - `defaultRegion()`: `'us-central1'`
  - `vpsSizes()`:
    - `e2-micro`: 2 vCPU, 1 GB RAM (Free Tier eligible)
    - `e2-small`: 2 vCPU, 2 GB RAM (~$14/mo)
    - `e2-medium`: 2 vCPU, 4 GB RAM (~$25/mo, **Recommended for K3s**)
    - `e2-standard-2`: 2 vCPU, 8 GB RAM (~$49/mo)
  - `defaultVpsSize()`: `'e2-medium'`
  - `managedSizes()`:
    - `e2-medium`: 2 vCPU, 4 GB RAM (Default)
    - `e2-standard-2`: 2 vCPU, 8 GB RAM
    - `e2-standard-4`: 4 vCPU, 16 GB RAM
  - `defaultManagedSize()`: `'e2-medium'`

#### 1.2 Update `App\Enums\ManagedProvider`
- Update `defaultStorageClass()` for `ManagedProvider::GKE`:
  - Return `'standard-rwo'` (the modern GKE CSI standard default storage class).

---

### 2. Global Configuration & State (`State.php`, `GlobalConfigData.php`, `InteractsWithGlobalConfig.php`)

GCP requires a **Project ID** and authentication credentials (either Application Default Credentials via `gcloud` or a Service Account JSON key file).

#### 2.1 Changes to `cli/app/State.php`
- Add transient in-memory properties for run-only overrides:
  - `public static ?string $transientGcpProject = null;`
  - `public static ?string $transientGcpCredentials = null;`

#### 2.2 Changes to `cli/app/Data/GlobalConfigData.php`
- Add persistent fields to `~/.larakube/config.json`:
  - `public ?string $gcpProjectId = null;`
  - `public ?string $gcpCredentials = null;` (path to Service Account JSON key, or null if using ADC)
  - Getters/setters: `getGcpProjectId()`, `setGcpProjectId()`, `getGcpCredentials()`, `setGcpCredentials()`.

#### 2.3 Changes to `cli/app/Traits/InteractsWithGlobalConfig.php`
- Add resolution helpers:
  - `getGcpProjectId()`: checks transient state, then CLI flag `--gcp-project`, then `GOOGLE_PROJECT` / `CLOUDSDK_CORE_PROJECT`, then `gcloud config get-value project`, then global config.
  - `getGcpCredentials()`: checks transient state, then CLI flag `--gcp-credentials`, then `GOOGLE_APPLICATION_CREDENTIALS` / `GOOGLE_CREDENTIALS`, then global config.

#### 2.4 Changes to `cli/app/Traits/InteractsWithOpenTofu.php`
- In `tofuEnv(string $stack, bool $isOpenTofu, array $extra = [])`:
  - Automatically pass GCP credentials into the OpenTofu process environment:
    ```php
    if ($projectId = $this->getGcpProjectId()) {
        $env['TF_VAR_gcp_project_id'] = $projectId;
    }
    if ($creds = $this->getGcpCredentials()) {
        if (file_exists($creds)) {
            $env['GOOGLE_APPLICATION_CREDENTIALS'] = $creds;
            $env['TF_VAR_gcp_credentials'] = file_get_contents($creds);
        } else {
            $env['GOOGLE_CREDENTIALS'] = $creds;
            $env['TF_VAR_gcp_credentials'] = $creds;
        }
    }
    ```

---

### 3. OpenTofu Blade Templates (`cli/resources/views/tofu/gcp/`)

#### 3.1 `cli/resources/views/tofu/gcp/vps.blade.php` (Google Compute Engine VM)
- **Provider:** `hashicorp/google` (~> 6.0)
- **Resource `google_compute_instance` "larakube":**
  - Name: `{{ $instanceName }}`
  - Machine Type: `{{ $size }}` (e.g. `e2-medium`)
  - Zone: `{{ $zone }}` (default: `{{ $region }}-a`)
  - Boot disk: Ubuntu 24.04 LTS (`ubuntu-os-cloud/ubuntu-2404-lts-amd64`), size 30GB.
  - Network interface: `network = "default"` with ephemeral public NAT IP (`access_config {}`).
  - Metadata:
    - `enable-oslogin = "FALSE"` (Critical: ensures direct root/larakube SSH key authentication works without OS Login IAM restrictions).
    - `ssh-keys = "root:{{ $sshPubKey }}"`
  - Tags: `["larakube", "larakube-vps", "http-server", "https-server"]`
- **Resource `google_compute_firewall` "larakube_ingress":**
  - Allows TCP 22 (SSH) from `{!! $sshSources !!}` (admin CIDR or 0.0.0.0/0).
  - Allows TCP 80, 443 (HTTP/HTTPS) from `0.0.0.0/0`.
  - Allows TCP 6443 (k3s API) from `{!! $apiSources !!}`.
- **Outputs:**
  - `ip`: `google_compute_instance.larakube.network_interface[0].access_config[0].nat_ip`
  - `id`: `google_compute_instance.larakube.id`

#### 3.2 `cli/resources/views/tofu/gcp/managed.blade.php` (Google Kubernetes Engine)
- **Resource `google_container_cluster` "larakube":**
  - Name: `{{ $clusterName }}`
  - Location: `{{ $zone ?? $region }}` (Zonal cluster saves ~$73/month control plane fee vs regional, ideal for workshops).
  - `initial_node_count = {{ (int) ($nodeCount ?? 2) }}`
  - `deletion_protection = false` (Critical: allows `tofu destroy` to clean up cleanly without errors).
  - Node Config:
    - `machine_type = "{{ $size }}"` (e.g. `e2-medium`)
    - `disk_size_gb = 30`
    - `oauth_scopes = ["https://www.googleapis.com/auth/cloud-platform"]`
- **Outputs:**
  - `context`: `"gke_${var.gcp_project_id}_{{ $zone ?? $region }}_{{ $clusterName }}"`
  - `endpoint`: `google_container_cluster.larakube.endpoint`
  - `cluster_name`: `google_container_cluster.larakube.name`
  - `kubeconfig`: Direct raw YAML format containing client access token, CA certificate, and server endpoint for immediate kubectl access.

---

### 4. Command Updates: `CloudCreateCommand.php`

1. **Signatures & Options:**
   - Add `{--gcp-project= : Google Cloud Project ID (skips the prompt)}`
   - Add `{--gcp-credentials= : Path to Google Service Account JSON key (skips the prompt)}`
   - Add `{--zone= : GCP Compute Zone (e.g. us-central1-a)}`
2. **Provider Resolution:**
   - Enable `'gcp' => 'Google Cloud Platform'` in `PROVIDERS` list.
3. **Authentication Verification:**
   - Add `ensureGcpCredentials()` to `ensureProviderToken($provider)`:
     - Detects Project ID via flag, `gcloud` config, or interactive prompt.
     - Detects auth via Service Account JSON key, `gcloud` Application Default Credentials (ADC), or prompts to paste key path.
     - Saves to global config when non-transient.
4. **Execution Flow (`createVps` & `createManaged`):**
   - For VPS:
     - Calls `tofu.gcp.vps` template when provider is `gcp`.
     - Waits for SSH on the allocated external NAT IP using `waitForSsh('root', $ip, '22', $keyPath)`.
     - Runs existing `provisionK3sNode` pipeline (completely provider-agnostic!).
     - Registers stack with `provider: 'gcp'`.
   - For Managed:
     - Calls `tofu.gcp.managed` template when provider is `gcp`.
     - Merges kubeconfig into `~/.kube/config`.
     - Optionally runs `gcloud container clusters get-credentials` if `gcloud` is installed to ensure persistent OAuth token refresh.
     - Calls `cloud:init:gke` (or `cloud:init:doks --context=...`) to install Traefik and obtain the LoadBalancer IP.
     - Registers stack with `provider: 'gcp'`.

---

### 5. Companion Provisioning Command: `cloud:init:gke` (`CloudProvisionGkeCommand.php`)

Create `cli/app/Commands/Cloud/CloudProvisionGkeCommand.php`:
- **Signature:** `cloud:init:gke {environment?} {--context=} {--email=}`
- **Alias:** `cloud:provision:gke`
- **Functionality:**
  - Targets the GKE cluster context.
  - Installs Traefik using `k8s.traefik-managed` (Service of `type: LoadBalancer`, persistent volume claim for `acme.json` using GKE default `standard-rwo`).
  - Waits for GCP to provision the external Network Load Balancer IP.
  - Automatically binds the environment using `recordManagedTarget($config, $environment, $projectPath, $context, ManagedProvider::GKE)`.
  - Prompts to automate DNS with Cloudflare (`dns:init`).

---

### 6. Command Updates: `CloudConfigureCommand.php` & Deployment Pipeline

1. **Deploy Target Prompting (`ResolvesEnvironmentContext.php`):**
   - `promptCloudTarget`:
     - When selecting an existing kube-context starting with `gke_`, auto-suggests or defaults provider to `ManagedProvider::GKE`.
     - Sets default `storageClass` to `standard-rwo`.
     - Detects cluster node count and configures deployment strategy (`single-node` vs `multi-node-ha`).
2. **Container Registry Options (`GathersEnvironmentData.php` & `RegistryProvider.php`):**
   - For workshops, participants can use **GitHub Container Registry (GHCR)** (free, zero GCP setup needed for container hosting) or **Google Artifact Registry (GAR)** (`<region>-docker.pkg.dev/<project-id>/<repo>`).
   - Add `RegistryProvider::GAR` ('Google Artifact Registry') with `_json_key` Service Account authentication support.
3. **CI/CD Scoped Token Minting (`ConfiguresCloudEnvironment.php`):**
   - `uploadGhaSecrets` mints a namespace-scoped ServiceAccount and RBAC Role in the GKE cluster.
   - Because standard Kubernetes ServiceAccount tokens work across all standard K8s clusters, the generated GitHub Actions workflow deploys to GKE **without requiring any proprietary gcloud plugins in CI runners**!

---

### 7. Ancillary Commands Updates

1. **`CloudScaleCommand.php` (`cloud:scale`):**
   - Check `$stack->provider`:
     - If `gcp`: Use GCP machine types (`e2-micro`, `e2-small`, `e2-medium`, `e2-standard-2`, `e2-standard-4`) instead of DigitalOcean droplet slugs.
     - Uses GCP credentials instead of failing on missing `doToken`.
2. **`CloudDestroyCommand.php` (`cloud:destroy`):**
   - Injects GCP credentials in `tofuEnv` so `tofu destroy` succeeds cleanly against GCP resources.
3. **`CloudStacksCommand.php` (`cloud:stacks`):**
   - Displays `Provider` column (`DO` vs `GCP`) in the table output.

---

## 🧪 Testing & Verification Strategy

### TDD Test Plan
1. **Unit Tests:**
   - `tests/Unit/CloudProviderTest.php`: Validate enum cases, regions, machine sizes, and mappings to `ManagedProvider`.
   - `tests/Unit/ManagedProviderTest.php`: Validate GKE `defaultStorageClass()` returns `'standard-rwo'`.
2. **Feature Tests:**
   - `tests/Feature/CloudCreateGcpTest.php`:
     - Non-interactive mode throws when `--gcp-project` is missing.
     - `--gcp-project` and `--gcp-credentials` flag binding.
     - Generation of `main.tf` for `tofu/gcp/vps` and `tofu/gcp/managed`.
     - Verify `enable-oslogin = "FALSE"` is rendered in instance metadata.
     - Verify `deletion_protection = false` is rendered in GKE cluster.
   - `tests/Feature/CloudConfigureGkeTest.php`:
     - Verify configuring an environment with a GKE context records `ManagedProvider::GKE` and sets `standard-rwo`.
3. **Code Style & Static Analysis:**
   - Format: `php vendor/bin/pint`
   - Static Analysis: `php vendor/bin/phpstan`
   - Test Suite: `php vendor/bin/pest`

---

## 📅 Step-by-Step Implementation Roadmap

| Phase | Description | Key Deliverables |
|---|---|---|
| **Phase 1** | **Data Models & Credentials** | `CloudProvider.php`, update `ManagedProvider.php`, `GlobalConfigData.php`, `InteractsWithGlobalConfig.php`, `State.php`, `InteractsWithOpenTofu.php` |
| **Phase 2** | **OpenTofu Templates** | `resources/views/tofu/gcp/vps.blade.php`, `resources/views/tofu/gcp/managed.blade.php` |
| **Phase 3** | **Cloud Provisioning Commands** | Update `CloudCreateCommand.php` with GCP support, implement `CloudProvisionGkeCommand.php` (`cloud:init:gke`) |
| **Phase 4** | **Configuration & Registry** | Update `CloudConfigureCommand.php`, `ConfiguresCloudEnvironment.php`, `RegistryProvider.php` (GAR support) |
| **Phase 5** | **Lifecycle & Day-2 Commands** | Update `CloudScaleCommand.php`, `CloudDestroyCommand.php`, `CloudStacksCommand.php` |
| **Phase 6** | **Testing & Verification** | Write Pest tests, run Pint & Larastan/PHPStan, document workshop checklist |
