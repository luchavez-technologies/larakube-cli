<?php

namespace App\Traits;

use App\Data\ConfigData;
use Illuminate\Support\Facades\Process;

trait InteractsWithDocker
{
    use InteractsWithProjectConfig, StreamsProcessOutput;

    /**
     * Whether a freshly built image must be sideloaded into a local cluster's
     * container runtime, decided from the active kube-context. Pure (no I/O)
     * so the routing is unit-testable.
     *
     * True only for the LOCAL native k3s context that cluster:setup creates.
     * False for remote/registry-backed clusters (including remote k3s, named
     * "larakube-<ip>") and for clusters sharing the host Docker daemon
     * (OrbStack, Docker Desktop), where the image is already visible.
     */
    public function resolveSideloadTarget(string $context): bool
    {
        return trim($context) === 'k3s-larakube';
    }

    /**
     * Whether a container-runtime image listing contains the given tag. Pure (no
     * I/O) so the matching is unit-testable. Handles the common decorations: a
     * `docker.io/library/` prefix, or the name and tag appearing separately.
     */
    public function clusterImageListContains(string $list, string $imageTag): bool
    {
        if (trim($list) === '' || trim($imageTag) === '') {
            return false;
        }

        if (str_contains($list, $imageTag) || str_contains($list, "docker.io/library/$imageTag")) {
            return true;
        }

        [$name, $tag] = array_pad(explode(':', $imageTag), 2, 'latest');

        return str_contains($list, $name) && str_contains($list, $tag);
    }

    /**
     * Get the base Docker run command for a specific type (php or node).
     */
    protected function getDockerCommand(string $path, string $type = 'php', string $envs = ''): string
    {

        $appName = basename($path);
        $localImage = "$appName:local";

        // Check if we have a local image, otherwise fallback to base
        $imageExists = Process::run("docker images -q {$localImage}")->output();
        $image = $imageExists !== '' ? $localImage : $this->getProjectConfig($path)->getPhpImage(true);

        $baseEnvs = '-e COMPOSER_CACHE_DIR=/dev/null -e COMPOSER_ALLOW_SUPERUSER=1 -e COMPOSER_IGNORE_PLATFORM_REQS=1 -e SHOW_WELCOME_MESSAGE=false';

        return "docker run --rm --init -v $path:/var/www/html -w /var/www/html --user root $baseEnvs $envs {$image} ";
    }

    protected function imageExists(string $image): bool
    {
        $id = Process::run('docker images -q '.escapeshellarg($image))->output();

        return trim($id) !== '';
    }

    /**
     * Build the local project image.
     */
    protected function buildImage(ConfigData $config): void
    {
        $uid = function_exists('posix_getuid') ? posix_getuid() : 1000;
        $gid = function_exists('posix_getgid') ? posix_getgid() : 1000;
        $appName = $config->getName();
        $path = $config->getPath();

        // Build Primary Project Image (Includes PHP, Node, and correct permissions)
        $this->buildTargetedImage("$appName:local", "$path/Dockerfile.php", $path, $uid, $gid);
    }

    protected function buildTargetedImage(string $imageTag, string $dockerfile, string $path, int $uid, int $gid): void
    {
        if (! file_exists($dockerfile)) {
            return;
        }

        $this->laraKubeInfo("Building local image '$imageTag'...");

        $target = '';
        $buildArgs = '';
        $content = file_get_contents($dockerfile);

        if (str_contains($content, 'AS development')) {
            $target = '--target development';
            $buildArgs = "--build-arg USER_ID=$uid --build-arg GROUP_ID=$gid";
        }

        $this->runStreaming("docker build $target $buildArgs -t $imageTag -f $dockerfile $path");

        // --- 🛡 LOCAL IMAGE BRIDGE ---
        // Images built on the host Docker engine are invisible to a local
        // cluster's container runtime until imported. Route the freshly built
        // image to whichever local engine is active so `larakube up` "just
        // works" without a registry. Remote/registry-backed clusters need
        // nothing here.
        $this->sideloadToActiveCluster($imageTag);
    }

    /**
     * Import a host-built image into whichever local cluster is active so pods
     * can run it without a registry. No-op for remote/registry-backed clusters
     * (and OrbStack, which reads host Docker images directly).
     */
    protected function sideloadToActiveCluster(string $imageTag): void
    {
        $context = trim(Process::run('kubectl config current-context')->output());

        if (! $this->resolveSideloadTarget($context)) {
            return; // Remote/registry-backed cluster — the image is pulled, not sideloaded.
        }

        $this->sideloadIntoK3s($imageTag);
    }

    /**
     * Whether the active local cluster's container runtime already has the image.
     *
     * Returns true/false when determinable, or null when it can't be checked
     * without side effects (native k3s needs sudo and it isn't cached) — callers
     * should treat null as "can't tell, don't force a re-import". Remote/registry
     * clusters (and clusters sharing the host Docker daemon — OrbStack, Docker
     * Desktop) return true: there
     * is no separate cluster store to seed.
     */
    protected function imageInActiveCluster(string $imageTag): ?bool
    {
        $context = trim(Process::run('kubectl config current-context')->output());

        if (! $this->resolveSideloadTarget($context)) {
            return true; // Nothing to sideload into.
        }

        // Native k3s: containerd is root-owned, so checking needs sudo. Only look
        // when sudo is already cached — never trigger a password prompt to check
        // (`-n` fails immediately instead of prompting).
        if (! Process::run('sudo -n true')->successful()) {
            return null;
        }
        $images = Process::run('sudo -n k3s ctr -n k8s.io images ls -q')->output();

        return $this->clusterImageListContains($images, $imageTag);
    }

    /**
     * Sideload a host-built image into native k3s. k3s uses containerd (not
     * Docker), so stream the image straight into its store with `k3s ctr images
     * import`. Requires sudo because the k3s containerd socket is root-owned.
     */
    protected function sideloadIntoK3s(string $imageTag): void
    {
        $this->laraKubeInfo("Importing '$imageTag' into k3s (containerd)...");
        $this->line('  <fg=gray>k3s uses containerd; importing requires sudo.</>');

        // Pre-warm sudo so the credential prompt is interactive (the import runs
        // through a pipe where a prompt would otherwise be swallowed).
        passthru('sudo -v');

        $code = 0;
        passthru('docker save '.escapeshellarg($imageTag).' | sudo k3s ctr images import -', $code);

        if ($code !== 0) {
            $this->laraKubeError("Could not sideload '$imageTag' into k3s.");
            $this->line('  The local image is not visible to k3s, so pods will likely fail');
            $this->line('  with ImagePullBackOff.');
        }
    }

    /**
     * Get the PHP image string based on project config.
     */
    protected function getProjectPhpImage(string $path): string
    {
        $configPath = $path.'/.larakube.json';
        $phpVersion = '8.5';
        $osSuffix = '-alpine';

        if (file_exists($configPath)) {
            $config = json_decode(file_get_contents($configPath), true);
            $phpVersion = $config['phpVersion'] ?? $phpVersion;
            $os = $config['os'] ?? 'alpine';
            $osSuffix = $os === 'alpine' ? '-alpine' : '';
        }

        return "serversideup/php:{$phpVersion}-cli{$osSuffix}";
    }

    /**
     * Get the command to install Node.js and NPM based on the image OS.
     */
    protected function getNodeInstallationCommand(string $image): string
    {
        return str_contains($image, 'alpine')
            ? 'apk add --no-cache nodejs npm'
            : 'apt-get update && apt-get install -y nodejs npm';
    }

    /**
     * Run a command inside a Docker container.
     */
    protected function runInContainer(string $command, string $path, string $type = 'php', string $envs = ''): void
    {
        $base = $this->getDockerCommand($path, $type, $envs);
        $this->runStreaming($base."sh -c '$command'");
    }

    /**
     * The host user's uid/gid, resolved reliably even when the posix extension isn't
     * compiled into the larakube binary. The old `posix_getuid() ?: 1000` fallback chowned
     * scaffolded files to uid 1000 when posix was absent (or the user wasn't 1000), leaving
     * a root/foreign-owned project you'd need sudo to manage — the WSL `larakube new` bug.
     * `id` is present on every Linux/macOS/WSL shell, so prefer it.
     */
    protected function hostUid(): int
    {
        $uid = trim(Process::run('id -u')->output());

        if ($uid !== '' && ctype_digit($uid)) {
            return (int) $uid;
        }

        return function_exists('posix_getuid') ? posix_getuid() : 1000;
    }

    protected function hostGid(): int
    {
        $gid = trim(Process::run('id -g')->output());

        if ($gid !== '' && ctype_digit($gid)) {
            return (int) $gid;
        }

        return function_exists('posix_getgid') ? posix_getgid() : 1000;
    }

    /**
     * True when this process's active group list doesn't include `docker`, even
     * though the system group database says the user IS a member — the classic
     * "usermod -aG docker ran (setup / installDockerEngine), but this shell
     * never picked it up" trap. `docker` commands then fail with a permission-
     * denied error on the socket that reads like Docker itself is broken, when
     * the fix is just `newgrp docker` or a new terminal. Shells out to
     * `id`/`getent` rather than the posix extension — it isn't compiled into
     * the standalone binary (see hostUid() above). Never blocks: returns false
     * when docker/getent aren't available, no `docker` group exists on this
     * system, or the user isn't a member at all (a different problem entirely).
     */
    protected function dockerGroupNeedsRefresh(): bool
    {
        $groupEntry = trim(Process::run('getent group docker')->output());

        if ($groupEntry === '') {
            return false;
        }

        $user = trim(Process::run('id -un')->output());

        if ($user === '') {
            return false;
        }

        // getent group docker → "docker:x:999:alice,bob" — 4th field is the member list.
        $fields = explode(':', $groupEntry);
        $members = isset($fields[3]) && $fields[3] !== '' ? explode(',', $fields[3]) : [];
        $primaryGroup = trim(Process::run('id -gn')->output());

        $isMember = in_array($user, $members, true) || $primaryGroup === 'docker';

        if (! $isMember) {
            return false;
        }

        $activeGroups = explode(' ', trim(Process::run('id -Gn')->output()));

        return ! in_array('docker', $activeGroups, true);
    }

    /**
     * Fix file ownership in the project directory back to the host user.
     */
    protected function chownToHostUser(string $path): void
    {
        $uid = $this->hostUid();
        $gid = $this->hostGid();

        $appName = basename($path);
        $image = "$appName:local";

        // Fallback if image doesn't exist
        $imageExists = Process::run("docker images -q {$image}")->output();
        if (trim($imageExists) === '') {
            $image = $this->getProjectConfig($path)->getPhpImage(true);
        }

        $this->runStreaming("docker run --rm --init -v $path:/var/www/html -w /var/www/html --user root -e SHOW_WELCOME_MESSAGE=false $image chown -R $uid:$gid .");
    }
}
