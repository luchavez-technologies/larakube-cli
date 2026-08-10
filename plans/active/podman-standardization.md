# Podman Standardization (Redesigned)

> Redesigned 2026-08-09 after review found two unverified technical blockers and a
> ~2x undercount of the actual file impact in the original draft. See
> [Rejected/Corrected Claims](#rejected--corrected-claims) at the bottom for what
> changed and why.

## Guiding Principle

**Podman replaces Docker only where LaraKube owns the container runtime.** That's:
native k3s (Linux, and WSL2 when it's running native k3s rather than Docker
Desktop's k8s), the remote VPS/registry build-and-deploy path, and the in-cluster
Forgejo Actions runner.

Where a third-party product owns the runtime and shares its image store with the
host `docker` CLI — **OrbStack on macOS**, **Docker Desktop on WSL2** — that path
keeps using `docker` CLI unchanged. Swapping it would break the image-sharing
mechanism those products rely on (OrbStack's Docker-API-compatible socket is not
Podman-API-compatible — verified via search, native Podman support is still an open
OrbStack feature request, not shipped). This is **not** a "100% Pure Podman"
migration; it's Podman where LaraKube controls the runtime, Docker where a
third-party tool does.

---

## Phase 0 — Spike: Rootless Podman Sidecar

**2026-08-09: done live, not as an isolated scratch pod.** The `larakube-shared`
namespace on `larakube-159.89.205.239` (the shared cluster hosting Forgejo) has no
active users yet, so this phase was executed directly against `forgejo.blade.php`
— see [Live Execution Log](#live-execution-log-2026-08-09) below for what was
found, changed, and still needs verifying.

Original spike plan, for reference (superseded by the live log below):

Prove out, in a scratch manifest:
1. `podman system service --time=0 unix:///run/podman/podman.sock` actually starts
   and stays up under `securityContext.privileged: false` with only
   `SETUID`/`SETGID` added — sources describing this "least-privileged" pattern
   also flag a need for `/dev/fuse` access (fuse-overlayfs) and specific
   host/runtime conditions, neither of which the original draft's YAML included.
   Confirm what's actually required on this cluster's node (crun/runc, cgroup
   version, kernel).
2. A client speaking `DOCKER_HOST=unix:///run/podman/podman.sock` (ideally
   `forgejo-runner` itself, in a matching container image/version) can actually
   build and run a job container through it. Podman's API aims for Docker
   compatibility but this hasn't been verified against forgejo-runner's client.

**Exit criteria**: a working scratch manifest + a written note of the exact
`securityContext`/volumes/capabilities that were actually needed (likely more than
the original draft's `SETUID`/`SETGID`-only spec). Phase 3 only starts once this
is proven.

---

## Live Execution Log (2026-08-09)

**Pre-flight diagnosis** (read-only `kubectl --context=larakube-159.89.205.239`,
no writes): the "issues with Forgejo Actions" the operator saw were **not** a
dind/docker bug. `forgejo-runner`'s logs showed only
`failed to fetch task: dial tcp 10.43.247.224:3000: connect: connection refused`
(the `forgejo-http` ClusterIP), clustered exactly around windows when the
`forgejo` pod itself was down/restarting. Restart counts on `dind`/`runner`
(2 and 9 respectively) lined up with a node-wide reboot ~2d4h prior (every pod in
the namespace picked up restarts at the same time) — not a dind-specific crash.
No actual CI job/build log ever appeared, meaning no workflow run has exercised
the container engine yet either way. **Conclusion**: the podman swap is a genuine
architectural improvement (drops `privileged: true`) but is not a proven fix for
a specific prior failure — there's no baseline "it broke like X, now it doesn't."

**Change applied**: `resources/views/k8s/git/forgejo.blade.php` — `dind` sidecar
replaced with the rootless `quay.io/podman/stable:v5.2` sidecar per the Phase 3
design below, with two additions the original Phase 3 draft was missing:
`allowPrivilegeEscalation: true` (rootless Podman's `newuidmap`/`newgidmap` need
it) and a `/dev/fuse` hostPath device mount (fuse-overlayfs, Podman's rootless
storage driver) — both called out as likely-needed in the original Phase 0 spike
plan and now included directly rather than deferred. `runner`'s `DOCKER_HOST`
now points at `unix:///run/podman/podman.sock`, dropping the old
`DOCKER_CERT_PATH`/`DOCKER_TLS_VERIFY` (TLS was a dind-only concern). Pint passed;
`tests/Unit/InteractsWithGitForgeTest.php` passed (it doesn't assert on
sidecar internals, so no test changes were needed).

**Verification still open** (blocked on a CLI rebuild + live apply, not yet run):
1. Does `quay.io/podman/stable` actually start `podman system service` under this
   exact capability set on this node's kernel/cgroup version, or does it need
   more (this is the one thing an isolated spike would have de-risked before a
   live apply — watch the rollout closely)?
2. Does `forgejo-runner`'s Docker-API client actually work against Podman's
   Docker-compatibility layer for a real build-and-push job?
3. `/dev/fuse` must exist on the `luchtech-vps` node as a character device for the
   hostPath mount to succeed — unverified before this edit.

If step 1 or 3 fails, the pod will sit in `Init`/`CrashLoopBackOff` on the
`podman` container specifically — check `kubectl --context=larakube-159.89.205.239
logs deploy/forgejo-runner -n larakube-shared -c podman` first.

**Iteration 1 (2026-08-09, live)**: exactly that happened. `podman` container
crashed with `configure storage: mount .../storage/overlay:.../storage/overlay,
flags: 0x1000: permission denied` (0x1000 = `MS_BIND` — Podman's own self
bind-mount of its graphroot). `runner` crashed downstream with `cannot ping the
docker daemon` since `podman` never came up. Root cause: the container ran as
root (image default), and Podman only takes the real rootless path — unshare
into a fresh user+mount namespace via `newuidmap`/`newgidmap`, which is what
SETUID/SETGID were granted for — when it is *not* euid 0. As root, it assumed
genuine host root and tried the bind-mount directly in the (capability-stripped)
outer namespace, which needs `CAP_SYS_ADMIN` we deliberately didn't grant.
Confirmed via `docker inspect quay.io/podman/stable:v5.2` locally: the image
ships a `podman` user (uid/gid 1000) with subuid/subgid ranges configured
(`1:999`, `1001:64535`) specifically for this. **Fix applied**: added
`runAsUser: 1000` / `runAsGroup: 1000` to the `podman` container's
`securityContext`.

**Iteration 2 (2026-08-09, live)**: uid 1000 took effect (error moved from
`/var/lib/containers/storage/overlay` to the rootless default
`/home/podman/.local/share/containers/storage/overlay`) but the same `MS_BIND
permission denied` persisted. Read-only `kubectl exec` into another pod on
`luchtech-vps` confirmed the real cause: Ubuntu 24.04's
`kernel.apparmor_restrict_unprivileged_userns=1` (AppArmor enabled) blocks
`unshare(CLONE_NEWUSER)` for any process without an AppArmor profile explicitly
allowing `userns,` — silently defeating the newuidmap/newgidmap path that
SETUID/SETGID enable, so Podman fell back to a raw bind-mount with no
`CAP_SYS_ADMIN`. This is a documented Ubuntu 24.04 hardening feature, not a
manifest mistake — the original Phase 0 spike plan didn't anticipate it because
it's node-OS-specific, not a generic "rootless Podman in K8s" concern.

Three options were on the table: (a) add `SYS_ADMIN` to the `podman` container
only, (b) disable the sysctl node-wide (weakens the hardening feature for every
pod on the node, not just this one), (c) pause and adopt Kubernetes-native pod
user namespaces (`hostUsers: false`, KEP-127) — architecturally correct but
needs verifying k3s/containerd 2.3.2 support first, not a same-session fix.
**Operator chose (a)**: `SYS_ADMIN` added to the `podman` container's
capabilities, scoped to that one sidecar (no device access, no other
capabilities, no seccomp/AppArmor bypass elsewhere in the pod) — narrower than
the `privileged: true` this phase set out to remove, but a real, broad
capability grant, consciously accepted rather than defaulted into.

**Iteration 3 (2026-08-09, live)**: SYS_ADMIN alone did not fix it — the
IDENTICAL `MS_BIND permission denied` persisted, still under
`/home/podman/.local/share/containers/storage/overlay`, confirming uid 1000
was still in effect and this was a different blocker than the mount
permission itself. Root cause: Linux capabilities and AppArmor are two
independent enforcement layers. `kernel.apparmor_restrict_unprivileged_userns`
is mediated by the AppArmor profile confining the container (via a `userns,`
rule), not by what capabilities the process holds — granting SYS_ADMIN doesn't
touch that layer at all, so `unshare(CLONE_NEWUSER)` was still being denied by
AppArmor before Podman's own capability-based logic ever got a say. **Fix
applied**: `securityContext.appArmorProfile.type: Unconfined` on the `podman`
container only (k3s server is v1.36.2, so the GA field is supported). Not yet
redeployed/verified — see commands below.

**Aside (2026-08-09)**: bumped the pinned image `quay.io/podman/stable`
from `v5.2` to `v5.8.2` (latest published on that image repo) while here.
Verified before pinning: upstream `containers/podman` (now
`podman-container-tools/podman` — a genuine GitHub org transfer post-dates
this plan's research, confirmed via matching repo ID + GitHub's own redirect,
not a hijack) is at v6.0.2, but (a) its changelog is unrelated to anything in
this plan (Windows/WSL `podman machine` cleanup, a cgroups-v1 remote-client
fix) and (b) `quay.io/podman/stable` doesn't publish a v6 tag yet regardless —
v5.8.2 is the real ceiling today.

---

## Phase 1 — Linux / WSL2-native-k3s Workstation

Scope: local image builds and the sideload-into-k3s path, on Linux and WSL2 **only
when the active context is native k3s** (`k3s-larakube`) — not when WSL2 is riding
Docker Desktop's k8s (see Guiding Principle).

### Files (verified by grepping actual `docker <cmd>` invocations, not just mentions
of the word "docker" — the original draft's list was ~half of this):

- `app/Commands/SetupCommand.php` — `ensureDockerInstalled()` → `ensurePodmanInstalled()`.
  Install `podman` on Linux/WSL2 (`apt-get install -y podman` / `dnf install -y podman`).
  Remove `dockerGroupNeedsRefresh()`-related install/group logic — rootless Podman
  has no daemon-socket group to join, so this whole class of bug disappears (don't
  reimplement a podman-flavored version of it).
- `app/Traits/InteractsWithDocker.php` — `docker build/run/images/save` → `podman`
  equivalents. `sideloadIntoK3s()` becomes
  `podman save --format docker-archive ... | sudo k3s ctr images import -`
  (keep `--format docker-archive` explicitly — it's what guarantees containerd
  compatibility). `chownToHostUser()` → `podman run`.
- `app/Commands/NewCommand.php` (lines ~287, ~341, ~351) — `docker pull`/`docker run`
  used to scaffold new projects → `podman pull`/`podman run`. **Missing from the
  original draft entirely.**
- `app/Commands/PurgeCommand.php` (lines ~139-141) — `docker rmi` → `podman rmi`.
  **Missing from the original draft.**
- `app/Traits/CheckPrerequisites.php` (lines ~21, ~46) — `which docker`/`docker info`
  preflight checks → podman equivalents, worded accordingly. **Missing.**
- `app/Mcp/Tools/LocalHealthCheckTool.php` (line ~28) — `docker info` health check →
  podman. **Missing.**
- `app/Commands/Pipeline/PipelineTestCommand.php` (line ~60) — `docker info`
  preflight → podman. **Missing.**
- `app/Traits/DetectsWsl.php` — `hasDockerCli()`/`isDockerDesktop()`/
  `isDockerDesktopKubernetesOnWsl()`/`hasDockerDesktopOnWsl()` **stay as-is** —
  they exist specifically to detect the Docker-Desktop-on-WSL2 path, which is
  explicitly out of scope per the Guiding Principle. Do not touch.
- `app/Commands/UpCommand.php` — large WSL2 recovery flow. Only the
  native-k3s-install branch (`setupDockerCli()` and its heredoc installer around
  line ~763, which currently lists `podman-docker` among the packages it *removes*
  as a conflict) changes to install/use podman instead. The Docker-Desktop-detection
  branches (`hasDockerDesktopOnWsl()`, `isDockerDesktopKubernetesOnWsl()`) stay
  untouched — same reasoning as `DetectsWsl.php`. **Missing from the original
  draft, and it's the largest file in this phase.**

### Tests
- `tests/Unit/InteractsWithDockerProcessTest.php`
- `tests/Unit/DockerGroupRefreshTest.php` — delete or repurpose; the function it
  tests is being removed, not renamed.
- `tests/Feature/DockerfileTest.php` — check whether it asserts `docker build`
  invocations directly.
- Any test asserting `NewCommand`/`PurgeCommand`/`CheckPrerequisites` process calls.

(The original draft named zero of these test files.)

---

## Phase 2 — Remote Deploy & Registry Push

Scope: `cloud:deploy` builds and pushes, on any OS (this path runs on the CLI
operator's machine regardless of local dev provider, but always targets a remote
VPS/registry, so the OrbStack/Docker-Desktop image-sharing concern doesn't apply
here).

- `app/Traits/InteractsWithRemoteDeploy.php`:
  - `buildProductionImageCommand()` / `buildAndPushImageCommand()`:
    `docker buildx build --platform ... --target deploy` → `podman build --platform ...`.
    Keep `--secret id=dotenv,src=...` — **verified**: Podman/buildah supports the
    same BuildKit-secret syntax, this part of the original draft was correct.
  - `sideloadOverSshCommand()`:
    `podman save --format docker-archive <image> | gzip -1 | ssh ... "gunzip | sudo k3s ctr images import -"`.
    Keep this — same `docker-archive` compatibility reasoning as Phase 1. Drop the
    "~80% smaller" claim from any docs/comments; it's not benchmarked. State
    instead that gzip meaningfully shrinks the transfer for the SSH-sideload path
    (VPS, no registry) since `podman save`/`docker save` output is uncompressed,
    and leave a TODO to measure the real number once this ships.
  - `dockerLoginCommand()` → `podman login`.
  - **Open item the original draft missed entirely**: line ~597 uses
    `docker buildx imagetools inspect` to resolve the pushed image's digest for
    immutable deploys. Podman has no direct equivalent — the replacement is
    `skopeo inspect docker://<image>` (different output shape, needs its own
    parsing) or `podman manifest inspect` for a local manifest list. Decide which,
    and if `skopeo`, add it as a new dependency in `SetupCommand`'s package list.
- `app/Commands/Cloud/CloudDeployCommand.php` (lines ~198, ~216) — `docker login`
  checks → podman. **Missing from the original draft.**

### Tests
- `tests/Unit/RemoteDeployTest.php`
- `tests/Unit/InteractsWithRemoteDeployProcessTest.php` — **missing from the
  original draft's test list.**
- `tests/Unit/InteractsWithGitForgeTest.php` (if it asserts registry/build command
  strings — verify before assuming it needs changes).

---

## Phase 3 — In-Cluster CI Runner (`forgejo.blade.php`)

**Gated on Phase 0's exit criteria.** Do not write this manifest from the original
draft's untested `SETUID`/`SETGID`-only spec — use whatever Phase 0 actually proved
necessary.

- Replace the `dind` container (`docker.io/docker:28.3.0-dind`,
  `securityContext.privileged: true`) with the Podman sidecar
  (`quay.io/podman/stable:v5.2`, `podman system service --time=0
  unix:///run/podman/podman.sock`), using the security context Phase 0 validated.
- Update the `runner` container's `DOCKER_HOST` to
  `unix:///run/podman/podman.sock` (dropping the current TLS cert-path env vars,
  which were dind-specific).
- Keep `emptyDir` for the podman-sock/podman-data volumes, matching the current
  dind volume pattern (ephemeral is fine — job image cache re-pulls after a
  restart, same as today).

### Tests
- `tests/Unit/InteractsWithGitForgeTest.php` — manifest/snapshot assertions for the
  runner sidecar.

---

## Directives for Execution Agents (any phase)

1. **AI agents must NOT run `./build`.** Tell the user when a build is needed and
   wait — per `cli/CLAUDE.md` and standing user preference.
2. Proactively run `./php vendor/bin/pint`, `./php vendor/bin/phpstan`, and
   `./php vendor/bin/pest` after edits.
3. Work one phase at a time and stop for review between phases — this is a wide,
   multi-file change and the user prefers incremental, à la carte work over one
   bundled sweep.
4. Phase 3 must not be attempted until Phase 0's spike has a written, validated
   security-context spec — don't guess it live against the real cluster.

---

## Verification Plan

### Automated (each phase)
```bash
./php vendor/bin/pint --test
./php vendor/bin/phpstan
./php vendor/bin/pest
```

### Manual Battle-Testing (operator runs, not the AI agent)
```bash
./build
larakube setup          # Phase 1
larakube new <app>       # Phase 1 (scaffolding path)
larakube up --build      # Phase 1 (sideload path)
larakube cloud:deploy production   # Phase 2
larakube git:init        # Phase 3, only after Phase 0 spike passes
```

---

## Rejected / Corrected Claims

What changed from the original draft, and why:

- **"podman CLI... connecting to OrbStack's socket via `CONTAINER_HOST`"** —
  rejected. OrbStack exposes a Docker-Engine-API-compatible socket only; Podman
  speaks its own separate `libpod` API. Native Podman support in OrbStack is an
  open feature request, not shipped. Resolved by scoping macOS out entirely
  (Guiding Principle) rather than attempting an unproven bridge.
- **"100% Pure Podman" framing** — corrected. The actual scope is "Podman where
  LaraKube owns the runtime," which explicitly excludes macOS/OrbStack and the
  Docker-Desktop-on-WSL2 path.
- **Rootless Podman sidecar as a settled `privileged: false` +
  `SETUID`/`SETGID`-only spec** — downgraded from "spec" to "spike required."
  The capability set is a real, documented pattern, but sources also describe
  `/dev/fuse` and other host requirements the original spec didn't include.
- **File impact list** — was ~5 files; actual `docker <cmd>` call sites (found by
  grepping for real invocations, not just the word "docker") span at least 11
  files across `app/Commands` and `app/Traits`, listed above by phase.
- **"cutting transfer sizes... by up to 80%"** — unbenchmarked, softened to a
  qualitative claim with a TODO to measure post-ship.
- **`docker buildx imagetools inspect`** (digest resolution for immutable
  deploys) — had no replacement named in the original draft at all; now an open
  decision in Phase 2 (`skopeo inspect` vs `podman manifest inspect`).
