# Dev box: a server used as a normal development machine

Status: PLAN, not started. Supersedes workspaces as the main remote-development path; workspaces (pods) stay experimental for sandboxed or shared use. See `remote-workspaces.md`, `workspace-images.md`, `workspace-graduate.md`.

## Idea
A dev box is an Ubuntu server with Podman, a local Kubernetes and the LaraKube CLI. A developer runs the normal `new`, `up`, Commons, CI setup and deploy there, as on their own computer. Nothing needs a special mode, and a project made there is already a complete LaraKube project, so graduating it is the existing flow.

## Why Podman
The CLI already prefers Podman where it can (`ResolvesContainerRuntime`: `podman build`, `save | k3s ctr images import`; Desktop's Setup offers it on Linux and WSL). On a dev box it also fixes the main weakness of a shared host: rootless Podman has no daemon and no `docker` group, so a shell on the box (a developer's, or an AI session's) is not root-equivalent.

## Pieces
1. **Provision.** A dev box command (own command, not a flag on `cloud:create`) that makes the server, installs Podman and the LaraKube CLI, and sets up a local cluster. Idempotent.
2. **Run the CLI there.** Desktop's `CliRunner` gets a location: this computer, or a dev box over SSH (`ssh -i <key> user@ip larakube ...`), with output streamed the same way.
3. **List projects by server.** A CLI command that scans a root folder for `.larakube.json` and reports each project as JSON. The Projects page gets a location switcher: This computer, or each dev box; the Project model gains a server column. New environment, Deploy, Up/Down, Commons and the host settings then work unchanged because the CLI and the container engine are on that machine.
4. **Editing.** Open in the person's own editor over SSH (VS Code Remote-SSH, JetBrains Gateway, which needs a 4 GB+ backend) with a link built by Desktop; a browser editor on the box is optional.
5. **Seeing the app.** The local address (`*.test`) only resolves on the box. Start with an SSH tunnel to the box's ingress; later a real hostname behind the VPN with a wildcard certificate (the Cloudflare DNS challenge work).
6. **Logins and keys on the box.** `gh`, `tea` and `glab` and the production kubeconfig live there, so the box is as trusted as the developer's own computer. One developer per box; do not share one.

## Spike first (throwaway Ubuntu server)
- Install rootless Podman and the CLI; run local cluster setup; then `larakube new` and `larakube up` for a Laravel app and one Node app.
- Questions: does `cloud:create`'s k3s clash with the local cluster the CLI sets up (one k3s per host); does rootless Podman handle the uid mapping the scaffold and `up` assume (`docker-php-serversideup-set-id`, host uid chown); does `k3s ctr images import` need sudo from a rootless user; do the local TLS and `*.test` names work, or must the box use real hostnames; sizing (8 GB expected).
- Done when the answers are written here and there is a go or no-go.

## Not decided
- Whether `cloud:create` becomes a role (`--role=dev`) or a separate provision command; naming per the one-positional rule.
- Per-developer isolation on a shared box (separate Linux users and rootless Podman each) as a later option.

## Spike results (GCP `e2-standard-2`, Ubuntu 24.04, 8 GB; destroyed afterwards)
**Go.** A plain Ubuntu server with rootless Podman, the LaraKube CLI and a local k3s ran the normal `new` and `up` for a Laravel app. The app answered HTTP 200 on the box and through an SSH tunnel to the Mac.

What works, with the commands used:
- `larakube setup --profile=local --runtime=podman --no-interaction` runs headless; it configured dnsmasq (`*.kube` to 127.0.0.1) and the developer tools. `larakube cluster:setup` on a clean server gives native k3s, the `k3s-larakube` context, and a sudoers rule for `k3s ctr`, so Podman images sideload into k3s without a prompt.
- Rootless Podman has no uid trouble: `new` scaffolded as the normal user, files owned by that user. `app/` ends up mode 777; the rest is 755.
- The Commons (MySQL, Redis) joined by `new`, Traefik and Mailpit run. Whole box idle with the app up: 1.7 GB used of 7.7 GB (the Commons MySQL is the largest at 443 MiB, the app 92 MiB). 8 GB is comfortable for one app; 4 GB is likely too small.
- Seeing the app: `ssh -L 18443:127.0.0.1:443` plus a hosts entry for `spike-app.kube` returned the Laravel page. A browser also needs the box's local CA trusted (`larakube trust` copies it) or a certificate warning accepted.

Answers to the open questions:
1. **Does `cloud:create`'s k3s clash with the local cluster? Yes, badly.** Running `cluster:setup` on a `cloud:create` server rewrote the k3s service with `--disable=traefik --write-kubeconfig-mode=644` and dropped the secrets-encryption flag; k3s then crash-looped ("identity transformer tried to read encrypted data"). One k3s per host, so a dev box needs its own provisioning that makes the server (hardening, user, firewall) and skips the deployment k3s step, then runs `setup --profile=local`. This is the intersection of `SetupCommand` and `cloud:init`: server creation and hardening from the cloud side, the Podman, tools and local-cluster stack from `setup`.
2. **uid mapping under rootless Podman:** no problem seen.
3. **`k3s ctr` needs sudo:** `cluster:setup` grants it; works.
4. **`*.test`/`*.kube` names:** resolve on the box through dnsmasq; from the Mac they need a tunnel plus a hosts entry (or the Mac's own dnsmasq) and the CA.
5. **Sizing:** 8 GB.

CLI bugs the spike found (to fix before the dev box can be called done):
- **Podman image names break the k3s sideload.** Podman stores a built image as `localhost/<name>:<tag>`; k3s imports it under that name; the pod asks for `<name>:<tag>`, which is `docker.io/library/<name>:<tag>`, so the web pod sat in `ImagePullBackOff`. Build and sideload with the `docker.io/library/` prefix, or retag after import.
- **Podman short names are configured only when `setup` installs Podman** (`InstallsPodman::configurePodmanShortNames`). A host where Podman is already installed never gets `unqualified-search-registries`, and `larakube new` fails pulling `serversideup/php:...`. Configure it whenever the runtime is Podman, or fully qualify every image the CLI pulls.
- `setup` tries to install OpenTofu without `unzip` on a minimal Ubuntu and fails the step; install `unzip` first.
- `new --fast --no-interaction` needs `--email`, and rejects `example.com` addresses.

## Next
1. Fix the two Podman bugs and the `unzip` gap (small, independent, useful beyond dev boxes).
2. A dev box provisioning command (own command per the one-positional rule): server creation and hardening, no deployment k3s, then `setup --profile=local --runtime=podman` and `cluster:setup` over SSH. Idempotent.
3. A CLI command that lists the projects in a folder as JSON; Desktop's SSH runner and the Projects location switcher.
