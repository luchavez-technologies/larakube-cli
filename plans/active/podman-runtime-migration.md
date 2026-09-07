# Plan: Podman as the container runtime (WSL/Linux), Docker on Mac

**Status:** 🟡 PHASE 1 + PHASE 3 LANDED — 2026-09-04. Phase 2 (registry/push path)
deferred. Implemented:
- `App\Traits\ResolvesContainerRuntime` — `containerRuntime()`/`runtimeIsPodman()`
  (env override → Mac=docker → WSL/Linux podman-if-functional) plus the pure
  command builders `buildImageCommand()` (the buildx/`--load` vs plain-`podman build`
  split), `saveImageCommand()`, `pullImageCommand()`, `imageQuietLookupCommand()`.
- Wired through `InteractsWithDocker` (local build, sideload, imageExists, chown,
  runInContainer), `InteractsWithRemoteDeploy` (`buildProductionImageCommand`,
  `sideloadOverSshCommand`), and `ScaffoldsInNode` (create-* pull/run + chown).
  `sideloadIntoK3s` now streams `save | k3s ctr import` via the Process facade
  (fakeable) instead of `passthru`.
- Phase 3: `SetupCommand` prefers rootless Podman on WSL/Linux (`--runtime` flag or
  a prompt defaulting to Podman; installs `podman slirp4netns fuse-overlayfs
  uidmap` on apt hosts and verifies `podman info`). `DoctorCommand` gained a
  container-runtime health check.
- Tests: `ResolvesContainerRuntimeTest`, `SetupRuntimeInstallTest`, a podman-variant
  case in `RemoteDeployTest`. The suite pins `LARAKUBE_CONTAINER_RUNTIME=docker` in
  `Tests\TestCase::setUp()` so runtime detection never flips assertions on a host
  that has Podman installed.

The rootless-Podman install lives in `App\Traits\InstallsPodman` (shared by `setup`
and `up`'s WSL runtime menu, which now offers "rootless Podman + k3s" as its default).

---

## 🧳 MACHINE-SWITCH HANDOFF (2026-09-05)

Committed as an uncommitted-tree checkpoint before james moved to another computer.
Everything below is **code-complete and green** (full suite 2131 passed / 2 skipped,
pint + phpstan clean) but **not yet `./build`-ed or validated live**.

Landed since the original Phase 1+3 note:
- **All hardcoded `docker` literals swept** through the runtime abstraction across
  the polyglot scaffolders (New/Adonisjs/Django/Dotnet/Nestjs/Statamic/Wordpress),
  Bundle/Cloud/Purge/Preview/Pipeline commands, `InteractsWithGlobalConfig`,
  `ClonesRepositories`, `LocalHealthCheckTool`.
- **Rootless-Podman ownership fixes** (Docker-compat kept): `containerChownSpec()`
  returns `0:0` under Podman vs `uid:gid` under Docker at all chown-back sites;
  `setLaravelStoragePermissions()` uses `0:0` vs `www-data:www-data`.
- **Test hermeticity/speed:** `Tests\TestCase::pinTestHostBinaries()` prepends a
  per-worker dir of no-op stubs for host-coupled binaries; `getRegisteredTools()`
  memoized. Suite ~130s → ~30s. (See memory `prevent-stray-processes-unfit.md` for
  why global `preventStrayProcesses()` was rejected.)

**RESUME HERE — remaining TODOs:**
1. `./build` (james runs it — never the agent).
2. Manual WSL validation of the build → `podman save` → `k3s ctr images import`
   round-trip (the Verification section below).
3. `larakube setup` on this box → confirm rootless Podman installs, `podman info`
   green, then `larakube up` on a scaffolded app with NO `LARAKUBE_CONTAINER_RUNTIME`
   override (auto-detect picks Podman) → pod Running, HTTPS 200.
4. Phase 2 (registry/push path) still emits `docker`/`docker buildx` — `cloud:deploy`
   push/login/digest unchanged. Do this when the push path is next touched.

**Related workstream (same machine switch):** WSL Windows hosts sync + mirrored
networking — see `plans/active/wsl-hosts-networking.md`. (Shares `SetupCommand`,
`InteractsWithDocker`, `GeneratesProjectInfrastructure` with this plan.)

**Still open (Phase 2):**
- ~~Phase 2: `buildAndPushImageCommand`, `dockerLoginCommand`, `resolvePushedDigest`
  still emit `docker`/`docker buildx`.~~ **DONE (2026-09-07).** The registry/push
  path is now runtime-aware: `buildAndPushImageCommand` builds via the shared builder
  then `podman push` under Podman; `dockerLoginCommand` → `loginCommand()`;
  `resolvePushedDigest` reads the digest `podman push --digestfile` captures (Docker
  still uses `buildx imagetools`), so Podman deploys pin `repo@sha256` too — no skopeo
  dependency. Covered by `RemoteDeployTest`.
- **Manual WSL validation** of the build+save+import round-trip (see Verification) —
  still the one remaining item; needs `./build` + a real cluster.

The only *other* Podman in the repo is an unrelated **rootless Podman CI sidecar** for
Forgejo Actions (`resources/views/k8s/git/forgejo.blade.php`, commit `762b8ef`) — that
runs Podman *inside a pod* as a CI image-builder, not as the developer's host runtime.

## 🎯 Vision

Let a WSL/Linux developer run LaraKube CLI on **rootless Podman** — daemonless, no
privileged socket, nothing to `sudo systemctl start` — instead of Docker Engine.
Detection makes it automatic: prefer Podman when it is present and functional, fall
back to Docker otherwise, with an env override for when the guess is wrong. **Mac is
untouched** and stays on Docker/OrbStack — for a hard technical reason (below), not
timidity.

## 🧭 The core constraint: how the local cluster consumes the image

The runtime choice is **coupled to how the target cluster ingests an image**, and the
two local cluster kinds differ:

| Cluster | How the built image reaches it | Runtime-agnostic? |
| :--- | :--- | :--- |
| **Mac — OrbStack / Docker Desktop** | reads the **host Docker image store directly** — no sideload (`resolveSideloadTarget()` returns false; see the "OrbStack, which reads host Docker images directly" comment in `InteractsWithDocker`) | ❌ Docker-only |
| **WSL/Linux — native k3s** | a **tarball import**: `docker save <img> \| sudo k3s ctr images import -` (`sideloadIntoK3s()`) | ✅ containerd doesn't care who built the tar |
| **Remote droplet (cloud:deploy)** | same tarball import over SSH (`sideloadOverSshCommand()` → `sudo k3s ctr images import -`) | ✅ |

The consequence is decisive:

- A **Podman-built image lives in Podman's own store** (on Mac, a separate
  `podman machine` Linux VM). OrbStack/Docker Desktop cannot see it → `larakube up`
  would `ImagePullBackOff`. So **Podman cannot drive the Mac local-dev path.**
- On WSL/Linux k3s (and every `cloud:deploy` sideload), the image is streamed as a
  **tarball** into containerd — `podman save` produces a `docker-archive` by default,
  which `ctr images import` reads. **Podman drops in with no change on the destination
  side.**

Therefore detection is **OS/cluster-scoped, not global**:

| | Local dev (`up`) | Cloud deploy (sideload / registry) |
| :--- | :--- | :--- |
| **Mac (OrbStack)** | Docker — forced | Docker (works; no reason to change) |
| **WSL/Linux (k3s)** | Podman-first, Docker fallback | Podman-first, Docker fallback |

Net: **Mac users see zero change.** This also sidesteps the "surprising a
dual-runtime user" problem, since the main dual-runtime case is a Mac with both
OrbStack and a brew-installed Podman.

## 🧱 Codebase grounding (read before implementing)

The container runtime is invoked through ~27 raw `docker …` string literals, almost
all inside two traits. Today's counts: `docker pull` ×7, `docker info` ×6,
`docker buildx` ×4, `docker save` ×3, `docker run` ×3, plus `docker rmi`/`login`/
`images` ×1 each.

| Command today | File | Podman equivalent | Notes |
| :--- | :--- | :--- | :--- |
| `docker buildx build --platform … --secret id=dotenv,src=… -f … --load` | `InteractsWithRemoteDeploy::buildProductionImageCommand()` (and the registry twin ~L284) | `podman build --platform … --secret id=dotenv,src=… -f …` | **drop `--load`** (podman loads to local storage by default); `buildx build` → `build`. This is the ONE command that does not alias cleanly. |
| `docker save <img> \| … k3s ctr images import -` | `InteractsWithRemoteDeploy::sideloadOverSshCommand()` (L166), `InteractsWithDocker::sideloadIntoK3s()` (L255) | `podman save <img> \| … k3s ctr images import -` | `podman save` defaults to `docker-archive`, which `ctr` reads. |
| `docker pull <img>` | scaffolders, `buildImage` | `podman pull` | direct |
| `docker run --rm … <img> …` | scaffolders (create-*), composer-in-container | `podman run --rm … <img> …` | direct; rootless uid mapping is fine for these |
| `docker images -q <img>` / `docker rmi` | `imageExists()`, cleanup | `podman images -q` / `podman rmi` | direct |
| `echo <pw> \| docker login -u … --password-stdin <host>` | `dockerLoginCommand()` (L334) | `podman login …` | direct |
| `docker buildx imagetools inspect <img>` (registry digest) | `InteractsWithRemoteDeploy` ~L628 | `skopeo inspect` / `podman manifest inspect`, **or** fall back to tag | no direct Podman verb; the code already falls back to deploying by tag with a warning |
| `docker info` (runtime health / Docker-Desktop detection) | `ensureDockerInstalled()`, doctor | `podman info` | branch on runtime |

**`buildx` is the only real incompatibility.** A naive `alias docker=podman` handles
`save`/`pull`/`run`/`images`/`login`, but breaks on `buildx build --load`. So the
abstraction cannot be a pure binary swap — it must own the *command shape*.

## 🏗 Design — a runtime resolver + command builders

Add to `InteractsWithDocker` (the trait both consumers already use):

```php
containerRuntime(): 'podman'|'docker'   // cached per-run; see resolution rules
runtimeIsPodman(): bool

// command builders that branch on the resolved runtime — replace the raw literals
buildImageCommand(...)     // podman build …          | docker buildx build … --load
saveImageCommand($img)     // podman save             | docker save
pullCommand($img) / runContainerCommand(...) / imageExistsCommand($img)
loginCommand($host,$u,$p) / removeImageCommand($img)
```

**Resolution rules for `containerRuntime()`:**

1. Explicit override wins: `LARAKUBE_CONTAINER_RUNTIME=podman|docker` (env), or a
   blueprint/global-config key. Escape hatch for wrong guesses and CI.
2. **Mac (`isMacOs()`): always `docker`.** The store-coupling above; never auto-pick
   Podman on Mac even if installed.
3. WSL/Linux: `podman` if `command -v podman` **and** `podman info` succeeds
   (present *and* functional, not just on PATH), else `docker`.
4. Cache the result for the process (don't re-shell `command -v` per call).

Keep the builders pure/string-returning so they unit-test the way
`RemoteDeployTest` already tests `buildProductionImageCommand()` — assert the emitted
string per runtime, no real process.

## 🚦 Phases

### Phase 1 — Sideload path on Podman (WSL-validatable, low-risk) ⬅ start here

The runtime-agnostic half. Build + `save` + SSH-import, plus the local k3s sideload.

- Add `containerRuntime()` + the `build`/`save`/`pull`/`run`/`images` builders,
  OS-gated (rules above).
- Route `buildProductionImageCommand`, `sideloadOverSshCommand`, `sideloadIntoK3s`,
  `imageExists`, and the scaffolder `docker run/pull` through the builders.
- Unit tests: emitted command per runtime (podman has no `--load`/`buildx`; docker
  unchanged — snapshots/existing tests must not move).
- **Validate on WSL** (see Verification): `larakube up` (local k3s) and
  `larakube cloud:deploy production` (droplet) both green under Podman.

### Phase 2 — Registry path on Podman (managed clusters)

- `podman push` / `podman login` (direct).
- Digest resolution: `docker buildx imagetools inspect` → `skopeo inspect` /
  `podman manifest inspect`, or keep the existing tag-fallback (already warns). Prefer
  keeping the fallback to avoid a new `skopeo` dependency unless a real need appears.
- Validate against DOKS or a registry-backed target.

### Phase 3 — `SetupCommand` installs rootless Podman on WSL/Linux

- The original ask. On WSL/Linux, `larakube setup` installs **rootless Podman**
  (`podman`, `slirp4netns`/`passt`, `fuse-overlayfs`; `podman info` green) instead of
  — or alongside — `docker-ce`. Register `qemu-user-static` binfmt only if cross-arch
  is needed (WSL amd64 → amd64 droplet is native, so usually not).
- Mac's setup path (`macOS detected — … OrbStack or Docker Desktop`) is untouched.
- `UpCommand::handleWsl2ClusterSetup()` / `setupDockerCli()` gain a Podman option in
  the WSL runtime menu.
- Doctor: `podman info` health check mirroring the Docker one.

## 🍏 What this means for Mac users

**Nothing changes.** Mac keeps Docker via OrbStack/Docker Desktop, because those
clusters read the host Docker image store and a Podman-built image would be invisible
to them. `containerRuntime()` returns `docker` unconditionally on macOS. Podman is
never installed, never preferred, never run there. The only cross-platform code is the
shared abstraction, which resolves to the exact commands emitted today on a Mac.

## ⚠️ Risks / caveats

- **`buildx` has no Podman verb** — handled by the command builders owning the shape,
  not by aliasing. This is the crux; get it right in Phase 1.
- **BuildKit secret** (`--secret id=dotenv,src=…` + `RUN --mount=type=secret,id=dotenv`
  in `Dockerfile.static`/`docker/php.blade.php`) — supported by Podman ≥3.1 via
  Buildah, so the dotenv-baking trick survives. **Verify on the target Podman version.**
- **Cross-arch** — Mac cross-builds arm64→amd64 via buildx+QEMU; but Mac stays on
  Docker, so this only matters if a WSL/Linux user targets a different-arch node.
  Podman uses the same `binfmt_misc`/`qemu-user-static` mechanism; register it in
  Phase 3 only when needed. (Ties into `arm-edge-deploy.md`'s platform resolution.)
- **Rootless `docker run` mounts** — the scaffolder/composer containers bind-mount the
  project and `--user root`; under rootless Podman the uid mapping differs. The
  existing `chown` back-to-host step (`hostUid()/hostGid()`) already normalizes
  ownership, but confirm no permission regression on the mounted tree.
- **Compose** — LaraKube does not use `docker compose` for app runtime (k8s does that),
  so `podman compose`/`podman-compose` is out of scope.

## ✅ Verification

**Manual proof-of-concept first (no code change), on WSL:**

```bash
podman build --platform linux/amd64 -t astroboy:pod -f Dockerfile.static .
podman save astroboy:pod | ssh larakube@159.89.205.239 'sudo k3s ctr images import -'
```

If the import succeeds, the whole sideload concept is validated end-to-end.

**Per phase, on the WSL box (a branch; Docker stays default until green):**

1. `LARAKUBE_CONTAINER_RUNTIME=podman larakube up` on a scaffolded app → pod Running,
   `https://<app>.test` serves 200. Confirm the image came from Podman
   (`podman images` shows it; the pod's containerd has it via `k3s ctr images ls`).
2. `LARAKUBE_CONTAINER_RUNTIME=podman larakube cloud:deploy production` → rollout
   completes, host serves 200 with a real cert. Byte-compare the emitted build/save
   commands against the Docker path in unit tests.
3. Regression: on a **Mac**, `containerRuntime()` returns `docker` and every emitted
   command is identical to today (snapshot tests unchanged).
4. Phase 3: fresh WSL distro → `larakube setup` installs rootless Podman, `podman info`
   green, then (1) and (2) pass with **no** `LARAKUBE_CONTAINER_RUNTIME` override
   (auto-detection picks Podman).

## 🔭 Out of scope

- Replacing the Forgejo CI **rootless-Podman sidecar** (already Podman; unrelated).
- `podman machine` on Mac (deliberately never used — Mac is Docker/OrbStack).
- Kubernetes runtime itself (k3s uses containerd regardless; this is about the
  *developer's build/sideload* runtime only).
