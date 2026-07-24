# LaraKube CLI Context (GEMINI.md)

This file provides the definitive technical and architectural standards for the LaraKube CLI.

## 🚀 Core Engine: `orchestrateProjectScaffolding`
Located in `GeneratesProjectInfrastructure.php`, this engine coordinates:
-   **Blueprint Resilience**: Automatically backs up `.larakube.json` to a cluster Secret (`larakube-blueprint`). The `heal` command can restore this DNA if the local file is lost.
-   **Architecture-by-Flag**: Supports surgical CLI flags (`--frankenphp`, `--mysql`, `--meilisearch`, `--reverb`) to bypass the wizard.
-   **Server Variations**: Dynamically maps ports and schemes based on the server choice (FrankenPHP: 8080/http, Nginx/Apache: 8443/https).
-   **Stability Guards**: Databases use `strategy: type: Recreate` to prevent volume corruption during updates.

## 🤖 AI-Native Orchestration
LaraKube is designed for the age of AI agents:
1.  **MCP Integration**: Auto-scaffolds configurations for Gemini, Claude, and Cursor.
2.  **Discovery**: AI tools use the `InteractsWithLaraKubeCli` trait to learn flags in real-time via `larakube help`.

## 🛠 Technical Standards

### 🔄 Development Workflow
Whenever you modify the LaraKube CLI codebase, you **must** proactively run tests and linters to ensure code consistency.

```bash
./php vendor/bin/pint
./php vendor/bin/phpstan
```

**CRITICAL RULE FOR AI AGENTS**: You are STRICTLY FORBIDDEN from running the `./build` command. When a build is required to update the global binary, you must tell the user to run `./build` and wait for them to do it.

### 🔁 Idempotency & Safety Standard
- **Mandatory Idempotency**: Users are expected to commit mistakes and re-run commands when retrying, troubleshooting, or automating. EVERY CLI command (`*:init`, `*:wire`, `*:add`) MUST be strictly **idempotent**.
- **Credentials & State**: Initializers MUST read existing credentials (admin passwords, database keys, secrets) from the cluster or Infisical before falling back to generating new random values. Re-runs MUST NEVER wipe databases or break existing connection state.
### 🔐 Infisical Secrets Prioritization Standard
- **Infisical-First Credentials**: Whenever creating, provisioning, or wiring secrets (admin passwords, tokens, PATs, database credentials, or SMTP secrets), commands MUST prioritize pushing them to Infisical via `pushInfisicalSecret()` if Infisical is bootstrapped on the cluster (`infisicalAvailable()`).

### 🌐 Networking & Ingress
-   **Local Domains**: Standardized on **`.dev.test`**.
-   **Traefik v3**: Dedicated `traefik:*` suite for managing the cluster-wide networking stack.
-   **Wildcard SSL**: Automated provisioning of LaraKube Local CA certificates via `larakube trust`.
-   **Host Mapping**: `InteractsWithHosts` prioritizes `127.0.0.1` for Mac/Windows and LoadBalancer IPs for Linux.

### 🐘 PHP & Base Image
-   **ServerSideUp Base**: Standardized on `serversideup/php` images.
-   **Default Extensions**: The following extensions are **already bundled** in the base image. **Never** request these via `RequiresPhpExtensions` to keep Docker builds fast:
    -   `ctype`, `curl`, `dom`, `fileinfo`, `filter`, `hash`, `mbstring`, `mysqli`, `opcache`, `openssl`, `pcntl`, `pcre`, `pdo_mysql`, `pdo_pgsql`, `redis`, `session`, `tokenizer`, `xml`, `zip`.
-   **Surgical Extensions**: Only request non-standard extensions (e.g., `gd`, `intl`, `mongodb`, `exif`) within architectural Enums.

### 📦 PHAR & Binary Standards
-   **Binary Context**: Always assume LaraKube commands are running from a compiled PHAR binary.
-   **Temporary Bridge Files**: External system tools (e.g., `openssl`, `kubectl`, `security`, `certutil`) **cannot** access files inside the PHAR (paths starting with `phar://`).
-   **The Pattern**: If a bundled resource (like a certificate or manifest template) must be processed by an external tool:
    1.  Copy the resource from its `base_path()` to a temporary location using `sys_get_temp_dir()`.
    2.  Pass the temporary path to the system tool.
    3.  Clean up (`unlink`) the temporary file after the action is complete.

### 📦 Standalone Binary Architecture
- **Embedded Runtime**: LaraKube CLI is distributed as a standalone binary with its own **embedded PHP runtime** used for its own logic.
- **Pod Proxying**: Commands like `larakube php`, `larakube art`, and `larakube composer` act as **proxies**. They do NOT run the command locally. Instead, they identify the correct pod in the project's Kubernetes namespace (e.g., `laravel-web`) and execute the command there via `kubectl exec`.
- **Environment Context**: The CLI determines the target namespace and pod based on the `.larakube.json` file in the current working directory.

### 📦 Development Wrappers
-   **Standalone**: Distributed as a Mach-O/Linux binary with embedded PHP runtime.
-   **K3D Bridge**: Automated image sideloading via `k3d image import` for local builds.
-   **Total Cleanup**: `larakube uninstall` performs a synchronized wipe of manifests, cluster resources, and Docker images.
-   **Daemon Runner**: Persistent Docker-based development environment for zero-host dependencies:
    -   **CLI**: `./php` (Daemon: `larakube-php-cli`)
    -   *Note*: Use `./php stop` to cleanup the daemon.
