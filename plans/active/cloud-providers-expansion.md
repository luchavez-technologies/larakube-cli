# LaraKube Multi-Cloud Provider Expansion Plan (v2 - Enum Standard)

**Status:** ⛔ NOT STARTED — confirmed 2026-08-08: there is no `CloudProvider` enum in `app/Enums/` at all, so Hetzner/Linode/AWS have no entry point. (Do not confuse with `ManagedProvider`, which covers managed-K8s overlays and does have EKS/GKE/AKS/CIVO/LKE.)

This document outlines the architectural changes and implementation steps required to support **Hetzner**, **Linode**, and **AWS** directly out of the box in the `LaraKube CLI`, adhering strictly to the project's standard of using rich Enums and Traits.

## 1. Core Abstraction: The `CloudProvider` Enum

We will eliminate the `PROVIDERS` constant inside `CloudCreateCommand` and reject Strategy classes. Instead, we will create a rich `App\Enums\CloudProvider` enum. This Enum will encapsulate all domain knowledge for a given cloud, matching the existing `ManagedProvider` and `DatabaseDriver` patterns.

```php
namespace App\Enums;

enum CloudProvider: string
{
    case DO = 'do';
    case AWS = 'aws';
    case HETZNER = 'hetzner';
    case LINODE = 'linode';

    public function label(): string
    {
        return match ($this) {
            self::DO => 'DigitalOcean',
            self::AWS => 'Amazon Web Services',
            self::HETZNER => 'Hetzner Cloud',
            self::LINODE => 'Linode (Akamai)',
        };
    }

    /** Map to the corresponding Managed Kubernetes enum. */
    public function managedProvider(): ManagedProvider
    {
        return match ($this) {
            self::DO => ManagedProvider::DOKS,
            self::AWS => ManagedProvider::EKS,
            self::LINODE => ManagedProvider::LKE, // Needs to be added to ManagedProvider
            self::HETZNER => ManagedProvider::CUSTOM, // Hetzner lacks official managed k8s
        };
    }

    public function regions(): array
    {
        return match ($this) {
            self::DO => ['nyc1' => 'nyc1  —  New York 1', /* ... */],
            self::HETZNER => ['fsn1' => 'fsn1 — Falkenstein', 'nbg1' => 'nbg1 — Nuremberg', /* ... */],
            self::LINODE => ['us-east' => 'us-east — Newark', /* ... */],
            self::AWS => ['us-east-1' => 'us-east-1 — N. Virginia', /* ... */],
        };
    }

    public function defaultRegion(): string
    {
        return match ($this) {
            self::DO => 'nyc1',
            self::HETZNER => 'fsn1',
            self::LINODE => 'us-east',
            self::AWS => 'us-east-1',
        };
    }

    public function sizes(): array
    {
        return match ($this) {
            self::DO => ['s-1vcpu-1gb' => 's-1vcpu-1gb — 1 vCPU, 1 GB RAM', /* ... */],
            self::HETZNER => ['cx11' => 'cx11 — 1 vCPU, 2 GB RAM', /* ... */],
            self::LINODE => ['g6-nanode-1' => 'g6-nanode-1 — 1 vCPU, 1 GB RAM', /* ... */],
            self::AWS => ['t3.small' => 't3.small — 2 vCPU, 2 GB RAM', /* ... */],
        };
    }
}
```

---

## 2. Authentication Logic via Traits

Logic that interacts with the user (prompts) or modifies the global config file should reside in a Trait, such as `App\Traits\InteractsWithCloudProviders` (or by extending `InteractsWithOpenTofu`).

We will update the `ensureProviderToken` logic to accept our new Enum and delegate to trait methods:

```php
protected function ensureProviderCredentials(CloudProvider $provider): bool
{
    return match ($provider) {
        CloudProvider::DO => $this->ensureDoToken(),
        CloudProvider::HETZNER => $this->ensureHetznerToken(), // Prompts for HCLOUD_TOKEN
        CloudProvider::LINODE => $this->ensureLinodeToken(), // Prompts for LINODE_TOKEN
        CloudProvider::AWS => $this->ensureAwsCredentials(), // Prompts for AWS_ACCESS_KEY_ID & AWS_SECRET_ACCESS_KEY
    };
}
```
These credential methods will save the inputs to `~/.larakube/config.json` and ensure they are exported to `Process::env()` during the `runTofu()` call (e.g., `TF_VAR_hcloud_token`).

---

## 3. OpenTofu Templates (`cli/resources/views/tofu/`)

We will create provider-specific subdirectories under the `tofu/` view path. Every template MUST output the standardized variables so `CloudCreateCommand.php` can read them.

### 3.1. Hetzner Cloud (`tofu/hetzner/`)
*   **`vps.blade.php`**
    *   **Resources:** `hcloud_server` (using an Ubuntu image), `hcloud_ssh_key`, `hcloud_firewall` (restricting SSH/API to `$adminCidr`).
    *   **Outputs:** `output "ip" { value = hcloud_server.main.ipv4_address }`
*   **`managed.blade.php`**
    *   *N/A* (The CLI will abort or hide the `--managed` option if `CloudProvider::HETZNER` is selected).

### 3.2. Linode/Akamai (`tofu/linode/`)
*   **`vps.blade.php`**
    *   **Resources:** `linode_instance`, `linode_sshkey`, `linode_firewall` (for `$adminCidr`).
    *   **Outputs:** `output "ip" { value = linode_instance.main.ip_address }`
*   **`managed.blade.php`**
    *   **Resources:** `linode_lke_cluster`.
    *   **Outputs:** `output "kubeconfig" { value = ... }`, `output "context" { value = ... }`.

### 3.3. Amazon Web Services (`tofu/aws/`)
*   **`vps.blade.php`**
    *   **Resources:** `aws_instance` (needs AMI lookup data source for latest Ubuntu), `aws_key_pair`, `aws_security_group` (for `$adminCidr`). Assumes default VPC.
    *   **Outputs:** `output "ip" { value = aws_instance.main.public_ip }`
*   **`managed.blade.php`** (Elastic Kubernetes Service - EKS)
    *   **Solution:** Use the official Terraform AWS EKS module (`terraform-aws-modules/eks/aws`) to hide the massive boilerplate required for VPCs, Subnets, and IAM roles.
    *   **Outputs:** Format the module's kubeconfig output to match LaraKube's requirements.

---

## 4. Implementation Phasing

To ensure stability while adhering to our Enum/Trait standards, we will implement this in phases:

### Phase 1: The Abstraction & Small Clouds (Hetzner & Linode)
1.  Create `App\Enums\CloudProvider`.
2.  Refactor `CloudCreateCommand.php` to use the new Enum for prompts instead of hardcoded arrays.
3.  Implement credential management in a Trait for Hetzner and Linode.
4.  Create `tofu/hetzner/vps.blade.php`, `tofu/linode/vps.blade.php`, and `tofu/linode/managed.blade.php`.
5.  **Test:** Provision a VPS on Hetzner and an LKE cluster on Linode.

### Phase 2: Amazon Web Services (AWS)
1.  Add AWS to `CloudProvider`.
2.  Implement `ensureAwsCredentials` in the Trait.
3.  Write the EC2 `vps.blade.php` template.
4.  Write the EKS `managed.blade.php` template using the EKS module.
5.  **Test:** Validate EKS context merging and ensure Traefik installs correctly on EKS (which requires specific AWS ingress annotations compared to DOKS).
