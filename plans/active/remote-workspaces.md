# Remote workspaces (experimental)

A workspace is one developer's copy of one repository, running as a pod with a browser editor on a server the developer owns. LaraKube hosts nothing. Spike numbers and the go decision: `remote-workspaces-spike.md`.

## Built (Step 1)
- CLI `workspace:create|list|suspend|resume|remove|open|options`, no positional; the server is `--stack=` (a `cloud:create` server) or `--context=` (any kube-context). `--json` on all for Desktop.
- `WorkspaceSpec` owns sizes, image, names and the manifest (`resources/views/k8s/workspace`); the Dockerfile is `resources/views/workspace/dockerfile.blade.php` (PHP 8.4, Node 22, Composer, code-server).
- Per workspace: namespace `ws-<name>`, quota, PVC, NetworkPolicy (ingress from kube-system only; egress DNS plus 22/80/443 outside private ranges, so no cluster API), no service-account token, password and deploy key in a Secret (kept on re-run).
- Image is built locally and sideloaded over SSH (as `cloud:deploy` does); a local cluster shares the host's images.
- Clone: SSH with the deploy key, falling back to https for a public repo; the remote is set to SSH, the chosen branch is created, and a pre-push hook refuses the default branch.
- Desktop: Workspaces page, shown only when Settings > Experimental features is on; Connect starts `workspace:open` (a port-forward) on a Desktop-chosen port.

## Verified
- Manifest passes `kubectl apply --dry-run=client`; the entrypoint script passes `bash -n`.
- Image builds; the container clones a public repo, creates the branch, serves `/healthz`, and has PHP, Node and Composer.
- Not run against a cluster yet: first `workspace:create` on a real server, the sideload, and the NetworkPolicy under the final manifest.

## Not built
- Public or VPN-only ingress with SSO (needs the wildcard host and certificate), idle suspend, per-user quotas.
- Step 2: the workspace MCP. Step 3: teams, and "describe the app".
- PhpStorm through JetBrains Gateway (needs a 4 GB+ backend).
- Branch protection on the repository is the real guarantee that the default branch is never pushed; the hook only guards the common mistake.
