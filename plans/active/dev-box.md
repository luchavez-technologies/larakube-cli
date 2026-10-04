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
