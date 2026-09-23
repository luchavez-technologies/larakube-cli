# Comparative Evaluation & Implementation Plan: Hetzner Cloud, Oracle Cloud (OCI), and Microsoft Azure

**Status:** 📋 PROPOSED (Architectural Evaluation & Comparative Plan)  
**Scope:** LaraKube Cloud Pilot (`cloud:create`, `cloud:scale`, `cloud:destroy`, `cloud:stacks`)  
**Evaluated Providers:** Hetzner Cloud (`hetzner`), Oracle Cloud Infrastructure (`oci`), Microsoft Azure (`azure`)

---

## 🎯 Executive Summary & Verdict

| Provider | Implementation Complexity | Developer UX | Typical Monthly Cost (2 vCPU / 4GB RAM) | Free Tier / Credits | Primary Recommended Use Case |
| :--- | :---: | :---: | :---: | :---: | :---: |
| **Hetzner Cloud** | ⭐ **Easiest (1/5)** | 🚀 **Frictionless** | **~€4.50 / mo (~$4.80)** | €20 referral credits | **Best Budget VPS / High-Performance Laravel Hosting** |
| **Microsoft Azure** | ⭐⭐⭐ **Moderate (3/5)** | 🏢 **Enterprise standard** | **~$35 - $45 / mo** | $200 trial + $5k-$150k Founder credits | **Corporate / Enterprise teams with Azure sponsorships** |
| **Oracle Cloud (OCI)** | ⭐⭐⭐⭐ **High (4/5)** | 🧩 **Complex / Tedious** | **$0.00 / mo (Always Free Ampere)** | **4 OCPU, 24 GB RAM, 200GB disk ALWAYS FREE** | **Hobbyists / Bootstrappers maximizing free compute** |

### 🏆 Which One is the Easiest?
**Hetzner Cloud is by far the easiest to build and use.**  
Its developer model is virtually identical to DigitalOcean: a single API token (`HCLOUD_TOKEN`), a lightweight standalone binary (`hcloud`), instant VM spin-up times (<10 seconds), and a clean, predictable OpenTofu provider (`hetznercloud/hcloud`).

### 💎 Which One Has the Most Unique Value?
**Oracle Cloud Infrastructure (OCI) has the legendary "Always Free" tier.**  
OCI gives away 4 ARM (Ampere A1) OCPUs, 24 GB of RAM, and 200 GB of NVMe block storage for **$0 forever**. For a developer wanting to run a production-ready single-node LaraKube k3s cluster with zero cloud bill, OCI is unmatched. However, its authentication (RSA keypairs + 3 different OCIDs) and anti-fraud account registration friction are notoriously high.

### 🏢 Which One is the Enterprise Standard?
**Microsoft Azure.**  
Like AWS and GCP, Azure is a hyperscaler. It requires Resource Groups, Virtual Networks, Subnets, and Network Security Groups. It is essential for enterprise shops, startups funded through Microsoft for Startups, or organizations deeply integrated with Active Directory / Microsoft 365.

---

## 🔍 Detailed Provider Breakdown

---

### 1. 🇩🇪 Hetzner Cloud (`hetzner`) — The Budget Champion

#### Overview
Hetzner is Europe's leading independent cloud provider, with datacenters in Germany (Falkenstein, Nuremberg), Finland (Helsinki), and the United States (Ashburn VA, Hillsboro OR). It offers bare-metal performance at fraction-of-hyperscaler prices.

#### Authentication & Setup
* **Token Model**: A single alphanumeric API token created under **Security > API Tokens** (Read & Write).
* **Environment Variable**: `HCLOUD_TOKEN`.
* **Zero Complex IAM**: No roles, no STS tokens, no service account JSON files.

#### CLI Tooling
* **Binary**: `hcloud` (single Go binary, ~15MB).
* **Installation**: `brew install hcloud` on macOS, or direct static binary download on Linux.
* **Authentication**: `hcloud context create larakube` (prompts for token once).

#### OpenTofu Provider
* **Provider**: `hetznercloud/hcloud` (~> 1.48+).
* **Terraform Registry**: Standard and battle-tested.
* **VPS Resources**:
  * `hcloud_server` (VM instance)
  * `hcloud_ssh_key` (SSH public key registration)
  * `hcloud_firewall` (security group rules)
  * `hcloud_primary_ip` (optional static public IPv4/IPv6)

#### Pricing & Specs
* **CX22 (Intel/AMD)**: 2 vCPU, 4 GB RAM, 40 GB NVMe, 20 TB traffic → **€3.79 / month** (~$4.15).
* **CPX31 (Dedicated vCPU)**: 4 vCPU, 8 GB RAM, 160 GB NVMe → **€13.40 / month** (~$14.50).
* **IPv4 Cost**: Hetzner charges €0.60/month for a public IPv4 (or IPv6-only servers for even less).

#### Kubernetes Strategy
* **Single-Node VPS (K3s)**: **Native perfection for LaraKube**. 100% parity with DigitalOcean droplet provisioning.
* **Managed Kubernetes**: Hetzner does not offer a first-party managed control plane (no EKS/GKE equivalent), but the open-source community maintains `k3s-on-hetzner` / `syself-cluster-api` if multi-node clusters are needed.

---

### 2. 🟥 Oracle Cloud Infrastructure (`oci`) — The Free Tier Giant

#### Overview
Oracle Cloud Infrastructure is enterprise-focused, but gained a massive developer following due to its unmatched "Always Free" tier.

#### Authentication & Setup
* **Authentication Model**: Asymmetric RSA 2048/4096-bit Keypair + API Signing.
* **Required Parameters**:
  1. `tenancy_ocid`: e.g. `ocid1.tenancy.oc1..aaaaaaa...`
  2. `user_ocid`: e.g. `ocid1.user.oc1..aaaaaaa...`
  3. `compartment_ocid`: root or project compartment
  4. `fingerprint`: MD5 hash of uploaded public key (`20:3b:...`)
  5. `private_key_path`: path to `~/.oci/oci_api_key.pem`
  6. `region`: e.g. `us-ashburn-1`, `eu-frankfurt-1`
* **ConfigFile**: Standard `~/.oci/config` INI format with profile sections (`[DEFAULT]`, `[personal]`).

#### CLI Tooling
* **Binary**: `oci` CLI (Python-based, ~200MB+ install footprint).
* **Setup Wizard**: `oci setup config` (generates the RSA key, fingerprint, and config file).

#### OpenTofu Provider
* **Provider**: `oracle/oci` (~> 6.0+).
* **VPS Resources**:
  * `oci_core_instance` (Compute VM)
  * `oci_core_vcn` (Virtual Cloud Network)
  * `oci_core_subnet` (Public subnet)
  * `oci_core_internet_gateway` + `oci_core_route_table`
  * `oci_core_security_list` (Firewall rules for port 22, 80, 443, 6443)

#### The "Always Free" Value
* **Ampere A1 Compute**: Up to **4 OCPU (ARM Neoverse) and 24 GB of RAM**, configurable as 1 VM (4 OCPU / 24GB) or up to 4 VMs (e.g. 2 OCPU / 12GB each).
* **Block Storage**: 200 GB total free NVMe boot/block storage.
* **Outbound Data**: 10 TB free per month.
* **Managed Kubernetes (OKE)**: Oracle Container Engine for Kubernetes has a **Basic Cluster tier with $0 control plane fee**. You only pay for worker nodes (which can be Always Free Ampere nodes).

#### Key Nuances & Gotchas
* **ARM64 Architecture**: The Always Free compute is `linux/arm64`. Laravel container images must be built for ARM or multi-arch (`docker buildx`).
* **Registration Friction**: OCI fraud prevention often rejects credit cards or new signups unpredictably.
* **Capacity Scarcity**: In popular regions (e.g. Ashburn, Frankfurt), Always Free A1 shapes occasionally show "Out of host capacity" during initial creation.

---

### 3. 🟦 Microsoft Azure (`azure`) — The Enterprise Hyperscaler

#### Overview
Azure is Microsoft's global cloud platform, second only to AWS in global enterprise adoption.

#### Authentication & Setup
* **Interactive Mode**: `az login` (opens browser, authenticates via Azure AD / Entra ID, caches access tokens in `~/.azure`).
* **Headless / CI Mode**: Service Principal (`ARM_CLIENT_ID`, `ARM_CLIENT_SECRET`, `ARM_TENANT_ID`, `ARM_SUBSCRIPTION_ID`).
* **Multi-Account Model**: Azure organizes resources by **Subscriptions** (`az account list`) under **Tenants / Directories**.

#### CLI Tooling
* **Binary**: `az` (Azure CLI, Python-based, ~1GB+ install size).
* **Key Commands**:
  * `az login`
  * `az account list --output json`
  * `az account set --subscription <id>`

#### OpenTofu Provider
* **Provider**: `hashicorp/azurerm` (~> 4.0+).
* **Required in HCL**:
  ```hcl
  provider "azurerm" {
    features {}
    subscription_id = var.azure_subscription_id
  }
  ```
* **VPS Resources**:
  * `azurerm_resource_group` (Mandatory parent container for all Azure resources)
  * `azurerm_virtual_network` + `azurerm_subnet`
  * `azurerm_public_ip` (Standard SKU static IP)
  * `azurerm_network_security_group` (Ingress/egress rules)
  * `azurerm_network_interface` (NIC binding IP to VM)
  * `azurerm_linux_virtual_machine` (Compute VM)

#### Pricing & Specs
* **Standard_B2s (Burstable)**: 2 vCPU, 4 GB RAM → **~$30.37 / month**.
* **Standard_B4ms**: 4 vCPU, 16 GB RAM → **~$121.00 / month**.
* **Managed Kubernetes (AKS)**: **Free Cluster tier ($0 control plane fee)**. Standard SLA cluster adds $73/month.

---

## ⚖️ Architectural Comparison Table

| Dimension | Hetzner Cloud (`hetzner`) | Oracle Cloud (`oci`) | Microsoft Azure (`azure`) |
| :--- | :--- | :--- | :--- |
| **Auth Method** | Single API Token (`HCLOUD_TOKEN`) | RSA Keypair + Tenancy/User OCIDs | `az login` or Service Principal |
| **CLI Tool** | `hcloud` (Go, 15MB, ultra-fast) | `oci` (Python, 250MB, complex) | `az` (Python, 1GB+, widely packaged) |
| **Account Hierarchy** | Projects under one account | Tenancy → Compartments | Tenant → Subscriptions → Resource Groups |
| **OpenTofu Provider** | `hetznercloud/hcloud` | `oracle/oci` | `hashicorp/azurerm` |
| **State / Lock** | Fast, reliable | Fast, reliable | Fast, reliable |
| **Entry VM Cost** | **€3.79 / mo (~$4.15)** | **$0.00 / mo (Always Free)** | **~$30.00 / mo** |
| **Managed K8s** | None (Single-Node K3s only) | OKE (Free basic control plane) | AKS (Free tier control plane) |
| **Network Complexity** | Low (Server gets Public IPv4/IPv6) | Moderate (VCN, Subnet, IGW, SecList) | High (RG, VNet, Subnet, NSG, NIC, Public IP) |
| **LaraKube Parity** | 100% identical to DigitalOcean | High for VPS, ARM64 container awareness | High for VPS & AKS |

---

## 🗺️ Recommended Roadmap & Phasing

```mermaid
flowchart LR
    A[Current: DO, GCP, AWS] --> B[Phase 1: Hetzner Cloud]
    B --> C[Phase 2: Microsoft Azure]
    C --> D[Phase 3: Oracle Cloud OCI]
    
    style B fill:#d4edda,stroke:#28a745,stroke-width:2px
    style C fill:#d1ecf1,stroke:#17a2b8,stroke-width:1px
    style D fill:#fff3cd,stroke:#ffc107,stroke-width:1px
```

### Why Implement **Hetzner Cloud** First?
1. **Developer Demand**: PHP and Laravel communities in Europe and the US overwhelmingly favor Hetzner for self-hosting due to 70–80% cost savings compared to AWS/GCP.
2. **Speed of Delivery**: Because Hetzner uses a simple API token and flat networking, implementing `cloud:create --provider=hetzner` takes **~1 day**, compared to ~3–4 days for Azure or OCI.
3. **No Bloat**: `hcloud` installs in seconds without multi-gigabyte Python toolchains.
4. **Already Halfway There**: `CloudProvider::HETZNER` already exists in `App\Enums\CloudProvider`!

### Why Implement **Azure** Second?
1. **Hyperscaler Completeness**: With AWS, GCP, and Azure supported, LaraKube covers the "Big Three" cloud providers.
2. **Startup Credits**: Many Laravel startups hold Microsoft for Startups Founders Hub credits ($5k to $150k) that they want to spend on Kubernetes.
3. **AKS Free Tier**: AKS has a free control plane tier, making managed Kubernetes very cost-effective.

### Why Implement **Oracle Cloud (OCI)** Third?
1. **Huge Free Tier Appeal**: The Always Free 4 OCPU / 24GB RAM tier is a massive marketing magnet for LaraKube hobbyists.
2. **Implementation Complexity**: Requires building automated RSA key generation and OCID parsing in the CLI to keep the UX smooth.

---

## 📋 Implementation Checklist for Hetzner Cloud (Next Up)

- [ ] **1. Enable `hetzner` in `CloudProvider`**:
  - Add regions: `fsn1` (Falkenstein), `nbg1` (Nuremberg), `hel1` (Helsinki), `ash` (Ashburn US), `hil` (Hillsboro US).
  - Add VPS sizes: `cx22` (2 vCPU, 4GB), `cx32` (4 vCPU, 8GB), `cpx31` (4 vCPU, 8GB dedicated).
  - Activate `hetzner` in `activeProviders()`.
- [ ] **2. Credential Management (`InteractsWithHetzner`)**:
  - Auto-discover `HCLOUD_TOKEN` or read `~/.config/hcloud/cli.toml`.
  - Add `--hetzner-token=` flag.
  - Interactive prompt via Laravel Prompts: `text(label: 'Paste your Hetzner Cloud API token', required: true)`.
- [ ] **3. OpenTofu Template (`cli/resources/views/tofu/hetzner/vps.blade.php`)**:
  - Render `hcloud_server`, `hcloud_ssh_key`, and `hcloud_firewall`.
- [ ] **4. Day-2 Operations (`cloud:scale`, `cloud:destroy`)**:
  - Scale CPU/RAM via `server_type` in `main.tf`.
  - Destroy server and clean up primary IP.
- [ ] **5. Automated Tests & Pint/PHPStan verification**.
