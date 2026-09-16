# Spike: Forgejo runner v13 builds images with Podman

Gate for the `cloud:configure` Forgejo/static-site CI work (Phase 2+). Proves the
re-applied runner can build and push an image through its rootless Podman
sidecar before any workflow generator depends on it.

## What changed in `git:init`
- Runner `6.4.0` → `13.1.0`, Forgejo `16.0.1` → `16.0.4`, Podman sidecar `v5.8.2` → `v5.8.4` (constants on `GitInitCommand`).
- Job labels map to `node:24-trixie` (was `node:22-bookworm`).
- Runner config: `container.docker_host: unix:///run/podman/podman.sock` mounts the sidecar socket into every job at `/var/run/docker.sock`, and `runner.envs.CONTAINER_HOST` points a job's `podman` CLI at it.
- The final apply now reuses the first render's parameters, so `--app-name` branding is no longer dropped.
- Forgejo Deployment carries `larakube-tool: git`.

## 1. Re-apply the runner
```bash
./build
larakube git:init production
```
Expect a short Forgejo restart (16.0.4 image). Then check the runner is online:
repo or site admin → Actions → Runners → `larakube` shows **Idle** with labels
`ubuntu-latest`, `ubuntu-22.04`, `docker`.

If the runner pod never becomes Ready, check its log for a Docker API version
error — v13 requires Docker API ≥ 25 from the socket it creates job containers
through. That is the one expected failure mode; fall back to runner `12.13.2`
and report back.

## 2. Scratch repo
Create a private repo `spike` on git.luchtech.dev under your own user. Add two
Actions secrets (repo → Settings → Actions → Secrets):
- `REGISTRY_USERNAME`: your Forgejo username
- `REGISTRY_PASSWORD`: a personal access token with `write:package`

`Dockerfile` (fails the build unless the secret mount works):
```dockerfile
FROM docker.io/library/alpine:3
RUN --mount=type=secret,id=dotenv,target=/tmp/dotenv grep -q HELLO=world /tmp/dotenv
```

`.github/workflows/spike.yml` (replace `<owner>`):
```yaml
name: Podman spike
on: [push, workflow_dispatch]

jobs:
  spike:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v6

      - name: Ensure podman client
        run: |
          command -v podman || {
            apt-get update
            apt-get install -y --no-install-recommends podman-remote
            ln -sf "$(command -v podman-remote)" /usr/local/bin/podman
          }

      - name: Podman reaches the sidecar
        run: podman info

      - name: Build with a BuildKit-style secret
        run: |
          printf 'HELLO=world\n' > .spike-secret
          podman build --secret id=dotenv,src=.spike-secret -t git.luchtech.dev/<owner>/spike:${{ github.sha }} .

      - name: Push and record the digest
        run: |
          echo "${{ secrets.REGISTRY_PASSWORD }}" | podman login -u "${{ secrets.REGISTRY_USERNAME }}" --password-stdin git.luchtech.dev
          podman push --digestfile digest git.luchtech.dev/<owner>/spike:${{ github.sha }}
          cat digest
```

## 3. Pass criteria
- [ ] Job dispatches to the `larakube` runner (not stuck "Waiting for a runner").
- [ ] `podman info` prints the sidecar's host info (rootless: true).
- [ ] The build step succeeds — proves `RUN --mount=type=secret` works over the remote socket.
- [ ] Push succeeds and `cat digest` prints `sha256:…`.
- [ ] Package `spike` appears under your user → Packages.
- [ ] `--app-name` branding (if you use one) is still shown on git.luchtech.dev after the re-apply.

## Report back
Which step failed and its log output, or "all passed" — Phase 2 starts from there.
Delete the `spike` repo and package afterwards.
