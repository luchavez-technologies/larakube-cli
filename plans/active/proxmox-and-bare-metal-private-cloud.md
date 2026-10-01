# Proxmox, XCP-ng & Bare-Metal Private Cloud Integration

## Goal Description
Provide first-class support for on-premise virtualization platforms and bare-metal private clouds (Proxmox VE, XCP-ng, Harvester, TrueNAS SCALE, and Talos Linux) across LaraKube CLI, Desktop, and Cloud.

By bridging the gap between infrastructure virtualization (L1–L3) and developer application platforms (L7), LaraKube enables businesses, agencies, and homelab developers to achieve a Vercel/Forge-like deployment experience on top of their own dedicated hardware—delivering total data sovereignty and eliminating exorbitant public cloud costs.

---

## 1. Industry Context & The Commercial Opportunity

### The "VMware Exodus"
Following Broadcom's acquisition of VMware and subsequent 300%–1,000% licensing price hikes, thousands of mid-market enterprises, universities, and hosting providers are actively migrating workloads to open-source hypervisors:
1. **Proxmox VE**: The primary open-source Debian/KVM hypervisor.
2. **XCP-ng (with Xen Orchestra)**: The enterprise-grade Citrix XenServer fork.
3. **Harvester (SUSE/Rancher)**: Open-source hyperconverged infrastructure (HCI) built directly on top of Kubernetes and KubeVirt.
4. **TrueNAS SCALE**: Ubiquitous storage and VM hypervisor among tech agencies and creative studios.

### The Problem LaraKube Solves
Once infrastructure teams set up their on-premise hypervisors, they face a major hurdle: **Application Delivery**.
* Developers do not want to manage raw VMs, write custom Ansible playbooks, or file IT tickets for every new database or domain.
* Traditional panels (Coolify, Portainer, CapRover) rely on mutable single-node Docker Compose, lacking zero-downtime rolling updates, declarative storage (PVCs), and native secret vaults.

**LaraKube turns any Proxmox/XCP-ng virtual machine into a self-healing, multi-tenant Kubernetes platform in 60 seconds.**

---

## 2. Architectural Relationship

```
┌────────────────────────────────────────────────────────┐
│  LaraKube Platform (Apps, Horizon, Octane, Tools, SSO)  │  <-- LaraKube CLI / Cloud / Desktop
├────────────────────────────────────────────────────────┤
│  Kubernetes / K3s (Pods, Services, Ingress, PVCs)      │  <-- Container Orchestrator
├────────────────────────────────────────────────────────┤
│  Guest OS (Ubuntu / Debian Linux VM)                   │  <-- Operating System
├────────────────────────────────────────────────────────┤
│  Hypervisor (Proxmox VE / XCP-ng / Harvester / TrueNAS)│  <-- Infrastructure Virtualization
├────────────────────────────────────────────────────────┤
│  Physical Hardware (Hetzner Dedicated, Dell PowerEdge) │  <-- Bare Metal
└────────────────────────────────────────────────────────┘
```

---

## 3. How the LaraKube CLI Powers This

The CLI provides two integration tiers:

### Level 1: "Connect Existing Server" (Immediate / Zero Dependencies)
Any Linux VM spun up in Proxmox, XCP-ng, or TrueNAS can be joined to LaraKube with a single command:
```bash
larakube cloud:connect --token=<cluster-token> --server=<cloud-url>
```
Or via the non-interactive curl bootstrap:
```bash
curl -fsSL https://cloud.larakube.com/install.sh | LARAKUBE_CLUSTER_TOKEN=... sh
```

**What the CLI does automatically:**
1. Installs and configures lightweight K3s.
2. Applies the cluster hardening pipeline (`UFW` firewall, disables password SSH, protects port 6443).
3. Deploys the in-cluster `larakube-agent` daemon in the `larakube-system` namespace.
4. Connects outbound to LaraKube Cloud / Desktop over secure WebSocket (`wss://`).

### Level 2: Native Proxmox Provider (`CloudProvider::PROXMOX`)
Extend [`cli/app/Enums/CloudProvider.php`](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/cli/app/Enums/CloudProvider.php) with a new `PROXMOX` enum case:
* **OpenTofu Integration**: Leverage the battle-tested `bpg/proxmox` provider.
* **Workflow**:
  ```bash
  larakube cloud:create --provider=proxmox --vps --stack-name=app-cluster
  ```
* **Inputs**: Proxmox API URL (`https://proxmox.local:8006/api2/json`), Token ID, Secret, Node target, and VM template.
* **Action**: Tofu provisions the VM from an Ubuntu cloud-image template, configures cloud-init, and triggers the LaraKube bootstrap automatically.

---

## 4. Supported Private Cloud Targets

| Platform | Type | Integration Method | Primary Use Case |
| :--- | :--- | :--- | :--- |
| **Proxmox VE** | KVM / LXC Hypervisor | Level 1 (curl) & Level 2 (OpenTofu `bpg/proxmox`) | Homelabs, dedicated Hetzner root servers, SMB private clouds. |
| **XCP-ng / Xen Orchestra** | Xen Hypervisor | Level 1 (curl) & Level 2 (OpenTofu `vatesfr/xenorchestra`) | European enterprises, government, and university data centers. |
| **Harvester (SUSE)** | Kubernetes HCI (KubeVirt) | Native Kubernetes Manifests (`kubectl apply`) | Modern bare-metal Kubernetes appliances with VM coexistence. |
| **TrueNAS SCALE** | Storage + KVM / Apps | Level 1 (curl inside Debian VM) | Creative agencies, video studios, and local office labs. |
| **Talos Linux** | Immutable Bare-Metal K8s | Direct `kubectl` / `agent:manifest` deployment | High-security, bare-metal setups with zero OS overhead. |

---

## 5. Strategic Benefits for LaraKube

1. **Enterprise Repatriation**: Directly appeals to businesses pulling workloads off AWS/GCP to save hundreds of thousands of dollars annually on dedicated bare metal.
2. **Zero Provider Lock-in**: Developers use the exact same `.larakube.json` and CLI verbs whether deploying to AWS, DigitalOcean, or an on-premise Proxmox cluster.
3. **Data Residency Compliance**: Enables medical, legal, and financial applications requiring on-premise data residency to use modern containerized workflows.
