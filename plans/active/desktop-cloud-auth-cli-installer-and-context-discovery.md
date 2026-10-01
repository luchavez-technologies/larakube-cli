# LaraKube Desktop: Cloud Auth, CLI Auto-Installer & Kubeconfig Discovery

This plan details the implementation of zero-terminal cloud authentication (AWS & GCP), an in-app LaraKube CLI auto-installer with channel switching (Canary vs. Stable), and automatic Kubeconfig cluster discovery for existing local/remote clusters.

---

## 1. Zero-Terminal Cloud Authentication (AWS & GCP)

### Problem
Currently, users must use a terminal to run `aws configure` (for AWS) or `gcloud auth login --update-adc` (for GCP). Desktop blocks server creation and instructs users to log in from Setup, but Setup only provides a note that logins happen in each provider's own CLI.

### Solution
1. **AWS In-App Credential Setup**:
   - Add a modal / form in Setup (`/readiness`) and in `/servers/create` (when AWS is selected) for:
     - `aws_access_key_id`
     - `aws_secret_access_key`
     - `aws_region` (with picker: us-east-1, us-west-2, eu-west-1, ap-southeast-1, etc.)
   - Desktop writes credentials securely to standard `~/.aws/credentials` (profile `[default]`) and `~/.aws/config`, and saves them to `GlobalSettings`.
   - Desktop runs `aws sts get-caller-identity` to verify credentials immediately.
   - Server create and Setup update to `Ready` without touching a terminal.

2. **Google Cloud OAuth Trigger**:
   - Add a **"Sign in with Google"** button in Setup (`/readiness`) and in `/servers/create` (when GCP is selected).
   - In Desktop, trigger `gcloud auth login --update-adc` via a child process. On macOS, this natively opens the user's default browser (Chrome/Safari) to the Google OAuth consent screen.
   - Once authorized, `gcloud` writes `~/.config/gcloud/application_default_credentials.json`.
   - Desktop verifies via `gcloud auth print-access-token` and marks GCP as `Ready`.

3. **DigitalOcean & Hetzner Global Persistence**:
   - Persist DO token and Hetzner token in `GlobalSettings` so `/servers/create` automatically pre-fills them.

---

## 2. In-App LaraKube CLI Auto-Installer & Channel Selection

### Problem
On a clean machine without the CLI, Desktop shows a `curl ... | bash` command in `/readiness` and requires opening Terminal. Furthermore, the user needs to know how to install canary vs. stable builds.

### Solution
1. **In-App Direct Downloader (`POST /setup/cli/install`)**:
   - Does not require `sudo` or Terminal.
   - Downloads the standalone binary directly from GitHub releases:
     - Canary: `https://github.com/luchavez-technologies/larakube-cli/releases/download/canary/larakube-{os}-{arch}`
     - Stable: `https://github.com/luchavez-technologies/larakube-cli/releases/latest/download/larakube-{os}-{arch}`
   - Writes directly to `~/.larakube/bin/larakube` and sets permissions `0755`.
   - Updates `ToolLocator` to ensure `~/.larakube/bin` is always indexed in the search path.
2. **Channel Selection (Canary vs. Stable)**:
   - Add a **Release Channel** toggle in Settings (`/settings`) and in Setup (`/readiness`):
     - `Canary` (default for pre-v1): Bleeding-edge features directly from develop.
     - `Stable`: Official release tags.
   - Users can opt-in/opt-out of Canary at any time with a 1-click "Switch to Canary" / "Switch to Stable" button that updates the binary in-place.

---

## 3. Kubeconfig Context Auto-Discovery

### Problem
`StackCatalog` only lists servers created through `larakube cloud:create`. Developers who already have local Kubernetes (OrbStack, Docker Desktop, Minikube, kind) or existing remote clusters (DOKS, EKS, GKE) in `~/.kube/config` see an empty server list and cannot easily browse or install Cluster Tools onto their existing clusters.

### Solution
1. **Auto-Discovery Service (`KubeconfigDiscovery`)**:
   - Inspects `~/.kube/config` using `kubectl config get-contexts -o json` or parsing the local kubeconfig.
   - Identifies non-stack contexts (e.g. `orbstack`, `docker-desktop`, `minikube`, `kind-*`, or custom clusters).
   - Classifies each context (local runtime vs. cloud/remote).
2. **Presentation in UI**:
   - In `/servers`: Add a tab or section for **"Discovered Clusters"** alongside "Cloud Servers".
   - Shows active context indicator, node count, cluster status, and a **"Use for Cluster Tools"** button.
   - In `/tools`: Server dropdown switcher includes discovered clusters so users can install tools (PocketBase, Directus, Penpot, etc.) onto their existing local or remote clusters!

---

## 4. Teammate macOS Installation Without Apple Developer Account

### Explanation
- Apple Developer Program costs $99/year. Unsigned or ad-hoc signed apps trigger macOS Gatekeeper quarantine (`com.apple.quarantine`) on download.
- Teammates can easily install and run the app with either of two methods:
  1. **One-line terminal command**:
     ```bash
     xattr -cr /Applications/LaraKube.app
     ```
  2. **Finder bypass**: Right-click (or Control-click) `LaraKube.app` -> click **Open** -> click **Open** on the prompt. macOS remembers the exception permanently.
- Include these instructions in the release README and download prompt.

---

## 5. Verification Plan
- Unit & Feature tests in `desktop/tests/Feature/`:
  - `CliInstallerTest`: verifies downloading and placing the binary in `~/.larakube/bin/`.
  - `CloudAuthTest`: verifies saving AWS credentials to `~/.aws/` and triggering GCP OAuth.
  - `KubeconfigDiscoveryTest`: verifies detecting and presenting external kubeconfig contexts.
- Static analysis & formatting:
  - `composer format`, `composer analyse`, `vendor/bin/pest`.
  - `npm run types:check`, `npm run build`.
