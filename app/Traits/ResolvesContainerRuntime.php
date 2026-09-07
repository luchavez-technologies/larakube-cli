<?php

namespace App\Traits;

use Illuminate\Support\Facades\Process;

/**
 * Resolves the developer's local container runtime — Docker or rootless Podman —
 * and emits the runtime-correct command strings for the handful of places the
 * CLI shells out to build, save, pull, tag and log in to images.
 *
 * Why an abstraction and not `alias docker=podman`: almost every verb aliases
 * cleanly (`save`/`pull`/`run`/`images`/`login`), but the image BUILD does not.
 * Docker cross-builds and `--load`s into its store via BuildKit's `buildx build
 * … --load`; Podman has no `buildx` sub-command and loads to local storage by
 * default, so its form is `podman build …` with **no** `--load`. The abstraction
 * therefore owns the command *shape*, not just the binary name — see
 * buildImageCommand().
 *
 * Resolution is OS/cluster-scoped, not global (see podman-runtime-migration.md):
 * a Podman-built image lives in Podman's own store, which OrbStack / Docker
 * Desktop on a Mac cannot see, so `larakube up` there would ImagePullBackOff.
 * Mac is therefore pinned to Docker. WSL/Linux k3s ingests images as a tarball
 * (`… save | k3s ctr images import -`), which containerd reads regardless of who
 * built it, so Podman drops in there with no change on the destination side.
 */
trait ResolvesContainerRuntime
{
    /**
     * The active container runtime: 'podman' or 'docker'.
     *
     * 1. An explicit `LARAKUBE_CONTAINER_RUNTIME=podman|docker` override wins —
     *    the escape hatch for a wrong guess or a pinned CI runtime.
     * 2. macOS is always 'docker' (the store-coupling above).
     * 3. WSL/Linux: **Podman is the default.** Use it when it's present AND
     *    functional (`podman info` succeeds, not merely on PATH). If it isn't but
     *    a working Docker is, respect that (don't break a Docker-only box). When
     *    neither is up yet, still default to 'podman' — it's the runtime
     *    `larakube setup` installs, so a fresh box should build toward Podman.
     *
     * Deliberately un-cached (no property): this trait is composed onto enums
     * (via GeneratesProjectInfrastructure), and enums may not declare properties.
     * The env override short-circuits before any shell-out, and callers that make
     * several runtime commands assign the result to a local `$runtime` once.
     */
    public function containerRuntime(): string
    {
        if (($override = $this->containerRuntimeOverride()) !== null) {
            return $override;
        }

        // macOS: never auto-pick Podman even if it is installed (brew) — the
        // OrbStack/Docker Desktop store can't see a Podman-built image.
        if (PHP_OS_FAMILY === 'Darwin') {
            return 'docker';
        }

        if ($this->podmanIsFunctional()) {
            return 'podman';
        }

        // Podman isn't up. Fall back to Docker only if it's actually the working
        // runtime here; otherwise Podman stays the default.
        return $this->dockerIsFunctional() ? 'docker' : 'podman';
    }

    public function runtimeIsPodman(): bool
    {
        return $this->containerRuntime() === 'podman';
    }

    /**
     * Build an image, in the shape the resolved runtime understands.
     *
     * Docker: `docker buildx build … --load` (BuildKit; `--load` writes the
     * cross-built image back into the local docker store so `docker save` can
     * stream it). Podman: `podman build …` — no `buildx`, and no `--load`
     * because Podman already writes the build to local storage. A dotenv path,
     * when given, is mounted as a BuildKit `--secret id=dotenv` (supported by
     * Podman ≥3.1 via Buildah), so VITE_* reach the build without being baked
     * into an image layer. Pure — returns the string, runs nothing.
     */
    public function buildImageCommand(
        string $image,
        string $dockerfile,
        string $path,
        string $platform = '',
        string $dotenvPath = '',
        string $target = '',
        string $buildArgs = '',
    ): string {
        $platformFlag = $platform !== '' ? '--platform '.$platform.' ' : '';
        $secret = $dotenvPath !== '' ? '--secret id=dotenv,src='.escapeshellarg($dotenvPath).' ' : '';
        $buildArgs = $buildArgs !== '' ? rtrim($buildArgs).' ' : '';
        $target = $target !== '' ? rtrim($target).' ' : '';

        $tail = '-t '.escapeshellarg($image).' -f '.escapeshellarg($dockerfile).' '
            .$secret.escapeshellarg($path);

        if ($this->runtimeIsPodman()) {
            return 'podman build '.$platformFlag.$target.$buildArgs.$tail;
        }

        return 'docker buildx build '.$platformFlag.$target.$buildArgs.$tail.' --load';
    }

    /**
     * Export an image as a tarball on stdout, for piping into a remote/local
     * `k3s ctr images import -`. `podman save` defaults to a `docker-archive`,
     * which `ctr` reads, so the destination side is unchanged. Pure.
     */
    public function saveImageCommand(string $image): string
    {
        return $this->containerRuntime().' save '.escapeshellarg($image);
    }

    /** `<runtime> images -q <image>` — quiet id lookup used for existence checks. Pure. */
    public function imageQuietLookupCommand(string $image): string
    {
        return $this->containerRuntime().' images -q '.escapeshellarg($image);
    }

    /** `<runtime> pull <image>`. Pure. */
    public function pullImageCommand(string $image): string
    {
        return $this->containerRuntime().' pull '.escapeshellarg($image);
    }

    /** `<runtime> rmi [-f] <image>`. Pure. */
    public function removeImageCommand(string $image, bool $force = false): string
    {
        return $this->containerRuntime().' rmi '.($force ? '-f ' : '').escapeshellarg($image);
    }

    /**
     * `<runtime> run [flags] <image> [cmd]` — a container run. `$args` is the
     * everything between the verb and here (flags, mounts, env, the image, and
     * any command), already assembled and escaped by the caller. Pure.
     */
    public function runContainerCommand(string $args): string
    {
        return $this->containerRuntime().' run '.$args;
    }

    /**
     * `echo <pw> | <runtime> login -u <user> --password-stdin <host>`. `podman
     * login` and `docker login` share this shape. Pure.
     */
    public function loginCommand(string $registryHost, string $username, string $password): string
    {
        return 'echo '.escapeshellarg($password).' | '.$this->containerRuntime()
            .' login -u '.escapeshellarg($username).' --password-stdin '.escapeshellarg($registryHost);
    }

    /**
     * Whether some container runtime is installed and responding — Podman or
     * Docker. For health checks / prerequisite gates that shouldn't care which.
     */
    public function containerRuntimeIsResponding(): bool
    {
        return $this->podmanIsFunctional() || $this->dockerIsFunctional();
    }

    /**
     * The `uid:gid` to chown a bind-mounted, container-written tree back to the
     * host user — the arg for an in-container `chown -R … <path>`.
     *
     * Docker: the container's root is real root and bind-mount files land
     * root-owned, so chown to the host's ACTUAL uid:gid.
     *
     * Rootless Podman: the default userns already maps the container's root (0)
     * to the host user, and the host's subuid range to container uids 1+. So a
     * file the container made as root is ALREADY host-user-owned, and chowning
     * to the host's real uid (e.g. 1000) would instead map to an unwritable
     * subuid (~100999) — the "Permission denied writing .larakube.json" bug.
     * `0:0` is what keeps the tree owned by the host user under Podman.
     */
    public function containerChownSpec(int $hostUid, int $hostGid): string
    {
        return $this->runtimeIsPodman() ? '0:0' : "{$hostUid}:{$hostGid}";
    }

    /**
     * An explicit runtime override from the environment, or null when unset or
     * unrecognised. Read first so it short-circuits detection entirely, which is
     * also what lets unit tests pin a runtime without any process running.
     */
    protected function containerRuntimeOverride(): ?string
    {
        $value = getenv('LARAKUBE_CONTAINER_RUNTIME');

        if (! is_string($value)) {
            return null;
        }

        $value = strtolower(trim($value));

        return in_array($value, ['podman', 'docker'], true) ? $value : null;
    }

    /**
     * True only when Podman is both installed and its engine responds — a
     * `command -v` hit alone isn't enough (a `podman` shim with no working
     * machine would then be preferred over a working Docker).
     */
    protected function podmanIsFunctional(): bool
    {
        if (trim(Process::run('command -v podman')->output()) === '') {
            return false;
        }

        return Process::run('podman info')->successful();
    }

    /** Whether a Docker engine is installed and responding. */
    protected function dockerIsFunctional(): bool
    {
        return Process::run('docker info')->successful();
    }
}
