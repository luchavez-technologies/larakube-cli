# Plan: Seamless Download Mechanism & Cross-Platform Ingress Parity

## 1. Objectives & Context
1. **Preserve Traefik Ingress Parity across Platforms**: Ensure that Traefik's `hostNetwork: true` configuration is **strictly WSL2-exclusive** (`$this->isWsl()`) so macOS (OrbStack, Docker Desktop) and bare-metal Linux continue using standard Kubernetes `LoadBalancer` services without any port contention or host networking side-effects.
2. **Seamless Download Mechanism (Landing Page & Download Page)**: Implement an auto-detecting, multi-platform download selector inspired by Docker Desktop (see screenshot reference) on both the landing page (`/`) and the dedicated download page (`/download`).

---

## 2. Platform Ingress Isolation Verification

### Contract
| Environment | `$this->isWsl()` | Traefik `spec.template.spec.hostNetwork` | Traefik `Service.spec.type` | Strategy |
| :--- | :--- | :--- | :--- | :--- |
| **macOS (OrbStack / Docker)** | `false` | *Not set* (disabled) | `LoadBalancer` | `RollingUpdate` (default) |
| **Linux (K3s / MicroK8s)** | `false` | *Not set* (disabled) | `LoadBalancer` | `RollingUpdate` (default) |
| **Windows (WSL2 Mirrored)** | `true` | `true` (`ClusterFirstWithHostNet`) | `ClusterIP` | `Recreate` |

### Actions
- Verify `cli/app/Traits/InteractsWithTraefik.php` ensures `$hostNetwork = $this->isWsl();`.
- Add test cases in `cli/tests/Unit/TraefikManifestsTest.php` verifying both `$hostNetwork = false` (macOS OrbStack / Linux) and `$hostNetwork = true` (WSL2) to prevent any regression.

---

## 3. Seamless Download Component & UI (Docs)

### Requirements
- **Auto-Detection**:
  - Detect OS (`macOS`, `Windows`, `Linux`).
  - Detect architecture where possible (e.g. `navigator.userAgentData` or `navigator.userAgent` containing `mac` / Apple Silicon vs Intel).
- **Split-Action Download Button with Dropdown (Landing Page)**:
  - Primary button: 1-click download of the detected installer (e.g. `Download for Mac (Apple Silicon)`, `Download for Windows`, etc.).
  - Secondary button (chevron): Opens a menu styled like Docker Desktop:
    - 🍎 **Download for Mac – Apple Silicon** (`.dmg`)
    - 🍎 **Download for Mac – Intel Chip** (`.dmg`)
    - 🪟 **Download for Windows – AMD64** (`.exe`)
    - 🐧 **Download for Linux – AppImage** (`.AppImage`)
    - 🐧 **Download for Linux – Debian/Ubuntu** (`.deb`)
    - Divider
    - 💻 **Install CLI via Terminal**
    - 📦 **View all builds / release notes**
- **Enhanced Download Page (`/download`)**:
  - Platform tabs: `macOS`, `Windows`, `Linux` to freely switch and inspect other OS requirements.
  - Channel switcher: `Canary` vs `Stable`.
  - Download cards for all variants of the selected OS.
  - OS-specific installation instructions (SmartScreen on Windows, Gatekeeper on macOS, chmod on Linux).
  - CLI installation command for the active OS.
