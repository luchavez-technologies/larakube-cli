# LaraKube CLI: Architectural Blueprint

LaraKube CLI is a professional-grade Kubernetes orchestrator for the Laravel ecosystem, designed with a "Container-First" and "Zero-Host" philosophy.

## 🏗 Command Hierarchy

### 1. Project Management (`core`)
- `new`: Scaffold new masterpieces from high-performance stubs.
- `init`: Adopt existing projects by generating the LaraKube DNA (`.larakube.json`).
- `uninstall`: The "Clean Break" command. Performs a synchronized wipe of local manifests, cluster namespaces, and optionally Docker images. Requires project name confirmation.

### 2. Deployment Operations (`ops`)
- `up`: The "Launch" button. Orchestrates image building (with k3s sideloading), .env injection, and manifest application.
- `status`: Professional health dashboard for all project services.
- `down`: Synchronized namespace removal with a 5-second volume cleanup cooldown.
- `stop` / `start`: Scaling-based pause/resume to preserve state without deleting volumes.

### 3. Networking Stack (`traefik:*`)
Traefik v3 is managed as a first-class, cluster-wide service:
- `traefik:setup`: Idempotent installation of the controller, SSL wildcard secrets, and config.
- `traefik:logs`: Tail ingress traffic in real-time.
- `traefik:dashboard`: Secure tunnel to the network UI.
- `traefik:restart`: Graceful rollout restart of the networking pods.
- `traefik:destroy`: Complete removal of networking resources and ClusterRoles.

## 🛡 Architectural Guards

### Persistence Reliability
- **Recreate Strategy**: All server-based databases use `strategy: type: Recreate` to ensure only one pod ever touches the data files, preventing corruption during updates.
- **Debian Stability**: Standardized on Debian-based images for databases (Postgres 17.9, MySQL 8.4 LTS) for maximum reliability compared to Alpine.

### Frontend Dev Server (Vite)
- **Hot-file URL contract**: `laravel-vite-plugin` writes `public/hot` from `vite.config.js#server.origin` — that is the *only* lever it honors. Environment variables (including `VITE_DEV_SERVER_URL`) have no effect on the plugin and must not be relied on.
- **No liveness probe on the Node pod**: the plugin registers a `SIGTERM` cleanup handler that deletes `public/hot`. A liveness restart during a heavy rebuild therefore re-opens the cold-start window. Readiness alone gates ingress, which is sufficient.
- **Unified image**: the Node pod reuses the project's PHP dev image to preserve UID/GID parity with the host hostPath mount.

### AI-Native Discovery
- **llms-full.txt**: Automated documentation aggregation during the build process, providing a "Master Context" for LLM synthesis.
- **Hybrid Tooling**: Command logic is abstracted into traits (e.g., `InteractsWithTraefik`) to allow for both CLI execution and AI-agent SDK integration.

## 🏗 Build & CI/CD
- **Decoupled Workflow**: The CLI builder (`./build`) is isolated from the documentation builder (`npm run build`).
- **Phar + Phacker**: Compiles Laravel Zero apps into standalone binaries with embedded runtimes for macOS (arm/x64) and Linux.
