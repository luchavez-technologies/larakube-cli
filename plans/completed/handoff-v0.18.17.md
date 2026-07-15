# 📝 LaraKube Handoff Summary (v0.18.17)

**Context:**
We recently tackled a critical architectural flaw regarding infrastructure determinism and reproducibility across different deployment targets (Local, VPS, Managed Cloud, and Airgapped Bundles).

**The Problem:**
An airgapped bundle installation on a bare VPS (`bundle:update`) crashed. The templates used modern Kustomize `patches:` blocks, but K3s `v1.30.4` ships with an older built-in Kustomize (`v5.0.4`) which fails to parse multi-document YAMLs. 
Our initial fix was a regex "hack" inside `BundleBuildCommand` to mutate the YAML syntax during the bundle zipping process. However, we realized this broke reproducibility—the shipped bundle was no longer a 1:1 match with the user's source code, creating a dangerous "hidden pipeline mutation".

**The Architectual Resolution:**
We removed the regex hack entirely and adopted a strict **Project-Level Version Locking** architecture. 

1. **Standalone Kustomize Bundling:**
   Instead of relying on the host server's binary, `BundleBuildCommand` now dynamically downloads a standalone Kustomize Go binary (`v5.6.0`) directly into the `.tar.gz` bundle. `BundleInstallCommand` and `BundleUpdateCommand` now execute `./kustomize build` locally from within the bundle. This guarantees mathematical template consistency across Mac, GitHub Actions, and remote airgapped servers.

2. **Project Version Locking (`.larakube.json`):**
   To prevent infrastructure from "floating" beneath the developer's feet, `App\Data\ConfigData` now explicitly tracks `k3sVersion` (default: `v1.30.4+k3s1`) and `kustomizeVersion` (default: `v5.6.0`). These are written directly into the project's `.larakube.json`, giving the developer total transparency and control over their infrastructure stack.

3. **Plugging the `latest` Leak:**
   During this refactor, we discovered a major hidden flaw: `cloud:provision` (VPS setup) and `cluster:setup` (Local/K3d setup) were executing `curl -sfL https://get.k3s.io | sh -` without a version flag, meaning they were silently pulling `latest` and falling out of sync with the bundles. We fixed this globally by injecting `INSTALL_K3S_VERSION` into the curl scripts and passing the exact `rancher/k3s` image tag to `k3d`.

**Current State:**
All environments—Local K3d, GitHub Actions, Managed DOKS, Direct VPS, and Airgapped Bundles—are now perfectly and rigidly synchronized to the exact K8s and Kustomize versions defined in `.larakube.json`. 

The test suite (`pest`) passes and all code is formatted via `pint`. The codebase is stable at tag **`v0.18.17`**. The next step is for the user to continue verifying their live deployments with this newly deterministic architecture.
