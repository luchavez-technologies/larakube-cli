# Implementation Plan: Google Cloud Project Auto-Finder & Creator

**Status:** ✅ COMPLETED  
**Target Commands:** `cloud:create`, `cloud:scale`

---

## 🎯 Executive Summary & Context

When running `larakube cloud:create` with Google Cloud Platform, operators should not have to manually memorize or look up their Project ID in the GCP web console.
1. **Authentication First:** Ensured `gcloud` authentication (or service account JSON) is verified *before* asking for a Project ID so `gcloud` can query the operator's account.
2. **Auto-Discovery Dropdown:** When authenticated, queries `gcloud projects list --format="json(projectId,name)"` and presents available projects in a Laravel Prompts `select` menu (along with `+ Create a new Google Cloud project` and `✎ Enter Project ID manually`).
3. **Automated Project Creation:** If `+ Create a new Google Cloud project` is chosen (or no projects exist):
   - Prompts for project name and generates a globally unique GCP Project ID (`Str::slug` + 5 random hex chars, 6-30 chars, lowercase alphanumeric + hyphens).
   - Runs `gcloud projects create <id> --name=<name>`.
   - Sets project as active in `gcloud config set project <id>`.
   - Discovers open billing accounts via `gcloud billing accounts list --format="json(name,displayName,open)" --filter="open=true"` and links via `gcloud billing projects link <id> --billing-account=<account>`.
   - Enables required baseline APIs (`compute.googleapis.com`, `container.googleapis.com`, and `cloudresourcemanager.googleapis.com`).

---

## 📦 User Experience & Flow

### 1. Interactive Experience (Auto-Discovery)
```
LARAKUBE LaraKube Cloud Pilot: OpenTofu Provisioner

Which cloud provider?
Google Cloud Platform

What kind of infrastructure?
VPS / droplet (SSH + k3s, single-node)

  ✓ Detected active authentication via local gcloud CLI.

Google Cloud Project
❯ My Production App (my-prod-12345)
  My Staging Cluster (my-staging-67890)
  + Create a new Google Cloud project
  ✎ Enter Project ID manually
```

### 2. Built-in Project Creation Flow
If the operator selects `+ Create a new Google Cloud project`:
```
Project Name:
> LaraKube Fleet

Project ID (globally unique across Google Cloud):
> larakube-fleet-9a3b1

Creating Google Cloud project 'larakube-fleet-9a3b1'...
  ✓ Project 'larakube-fleet-9a3b1' created successfully.

Link billing account 'My Company Billing (012345-6789AB-CDEF01)' to project? (yes/no) [yes]
  ✓ Billing account linked.

Enabling required Google Cloud APIs (Compute Engine, GKE, & Resource Manager)...
  ✓ Using Google Cloud project: larakube-fleet-9a3b1
```

---

## 🏛️ Implementation Details

### 1. Trait Architecture: `App\Traits\InteractsWithGcp`
- Extracted all GCP credential resolution and project handling into a reusable trait used by both `CloudCreateCommand` and `CloudScaleCommand`.
- Handles non-interactive mode with strict error feedback when `--no-interaction` is provided without required flags (`--gcp-project`, `--gcp-credentials`).

### 2. Auto-Discovery & Selection: `resolveGcpProjectId()`
- Queries `gcloud projects list --format="json(projectId,name)"`.
- Presents existing projects along with `+ Create a new Google Cloud project` and `✎ Enter Project ID manually`.
- Pre-selects active project from `gcloud config get-value project` or global config if present.

### 3. Automated Creation: `createGcpProjectFlow()` & `linkBillingAccountIfAvailable()`
- Validates GCP project ID constraints (6-30 chars, lowercase alphanumeric and hyphens, starting with a letter).
- Executes `gcloud projects create <id> --name=<name>`.
- Automatically links billing account if available (with confirmation or selection if multiple).
- Automatically enables Compute Engine, GKE, and Cloud Resource Manager APIs.

### 4. GCP VPC Firewall Rule Segregation (`tofu/gcp/vps.blade.php`)
- Google Cloud strictly rejects mixing IPv4 (`0.0.0.0/0`) and IPv6 (`::/0`) ranges within the same `source_ranges` attribute (`googleapi: Error 400: Mixture of IPv4 and IPv6 in the same rule is not allowed`).
- Segregated firewall rules into:
  - `google_compute_firewall.larakube_ingress`: handles admin access (SSH 22, k3s API 6443) using sanitized IPv4 CIDRs (`"0.0.0.0/0"` or operator `--admin-cidr`).
  - `google_compute_firewall.larakube_http`: handles web traffic (HTTP 80, HTTPS 443) using pure IPv4 `["0.0.0.0/0"]` so web traffic remains open even when `--admin-cidr` is supplied.

---

## 🧪 Verification & Test Results
- **Feature Tests:** Added interactive and template tests in `cli/tests/Feature/CloudCreateGcpTest.php`:
  - `interactive ensureGcpCredentials lists projects and selects project`
  - `interactive ensureGcpCredentials allows manual entry when custom is selected`
  - `interactive ensureGcpCredentials creates new project, links billing, and enables APIs`
  - `interactive ensureGcpCredentials handles multiple billing accounts`
  - Verified GCP VPS template renders both `larakube_ingress` and `larakube_http` with pure IPv4 ranges and excludes `::/0`.
- **Formatting:** `vendor/bin/pint` run and verified clean.
- **Static Analysis:** `phpstan` run with zero errors (`{"tool":"phpstan","result":"passed","errors":0}`).
- **Full Suite:** Full Pest test suite executed: 2,598 passed, 0 failed (10,236 assertions).
