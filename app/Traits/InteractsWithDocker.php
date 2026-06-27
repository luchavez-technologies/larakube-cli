<?php

namespace App\Traits;

use App\Data\ConfigData;

trait InteractsWithDocker
{
    use InteractsWithProjectConfig;

    /**
     * Decide where a freshly built image must be sideloaded, from the active
     * kube-context. Pure (no I/O) so the routing is unit-testable.
     *
     * Returns ['engine' => 'k3d', 'cluster' => '<name>'] for a `k3d-<name>`
     * context, ['engine' => 'k3s'] for the LOCAL native k3s context that
     * cluster:setup creates, or null for remote/registry-backed clusters
     * (including remote k3s, which is named "larakube-<ip>").
     */
    public function resolveSideloadTarget(string $context): ?array
    {
        $cluster = $this->resolveK3dClusterName($context);

        if ($cluster !== null) {
            return ['engine' => 'k3d', 'cluster' => $cluster];
        }

        if (trim($context) === 'k3s-larakube') {
            return ['engine' => 'k3s'];
        }

        return null;
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
        $imageExists = shell_exec("docker images -q {$localImage} 2>/dev/null");
        $image = $imageExists ? $localImage : $this->getProjectConfig($path)->getPhpImage(true);

        $baseEnvs = '-e COMPOSER_CACHE_DIR=/dev/null -e COMPOSER_ALLOW_SUPERUSER=1 -e COMPOSER_IGNORE_PLATFORM_REQS=1';

        return "docker run --rm --init -v $path:/var/www/html -w /var/www/html --user root $baseEnvs $envs {$image} ";
    }

    protected function imageExists(string $image): bool
    {
        $id = shell_exec('docker images -q '.escapeshellarg($image).' 2>/dev/null');

        return ! empty(trim($id ?? ''));
    }

    /**
     * Build the local project image.
     */
    protected function buildImage(ConfigData $config): void
    {
        $uid = host_uid();
        $gid = host_gid();
        $appName = $config->getName();
        $path = $config->getPath();

        // Build Primary Project Image (Includes PHP, Node, and correct permissions)
        $this->buildTargetedImage("$appName:local", "$path/Dockerfile.php", $path, $uid, $gid);
    }

    /**
     * Resolve the k3d cluster name from a kube-context, or null if the context
     * isn't a k3d cluster. k3d contexts are named `k3d-<cluster>`. Kept pure (no
     * shell) so the detection that decides which cluster to sideload the image
     * into is unit-testable — this is the logic that was previously hardcoded to
     * "larakube" and silently skipped other clusters.
     */
    protected function resolveK3dClusterName(string $context): ?string
    {
        $context = trim($context);

        if (! str_starts_with($context, 'k3d-')) {
            return null;
        }

        $cluster = substr($context, strlen('k3d-'));

        return $cluster !== '' ? $cluster : null;
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

        passthru("docker build $target $buildArgs -t $imageTag -f $dockerfile $path");

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
        $context = trim((string) shell_exec('kubectl config current-context 2>/dev/null'));
        $sideload = $this->resolveSideloadTarget($context);

        if ($sideload === null) {
            return; // Remote/registry-backed cluster — the image is pulled, not sideloaded.
        }

        if ($sideload['engine'] === 'k3d') {
            $this->sideloadIntoK3d($imageTag, $sideload['cluster']);
        } else { // k3s
            $this->sideloadIntoK3s($imageTag);
        }
    }

    /**
     * Whether the active local cluster's container runtime already has the image.
     *
     * Returns true/false when determinable, or null when it can't be checked
     * without side effects (native k3s needs sudo and it isn't cached) — callers
     * should treat null as "can't tell, don't force a re-import". Remote/registry
     * clusters (and OrbStack, which reads host Docker images) return true: there
     * is no separate cluster store to seed.
     */
    protected function imageInActiveCluster(string $imageTag): ?bool
    {
        $context = trim((string) shell_exec('kubectl config current-context 2>/dev/null'));
        $sideload = $this->resolveSideloadTarget($context);

        if ($sideload === null) {
            return true; // Nothing to sideload into.
        }

        if ($sideload['engine'] === 'k3d') {
            // k3d nodes run in Docker; query the server node's containerd (no sudo).
            $node = 'k3d-'.$sideload['cluster'].'-server-0';
            if (trim((string) shell_exec('docker inspect -f "{{.State.Running}}" '.escapeshellarg($node).' 2>/dev/null')) !== 'true') {
                return null; // Node isn't up — can't tell; don't force a re-import.
            }
            $images = shell_exec('docker exec '.escapeshellarg($node).' crictl images 2>/dev/null');

            return $this->clusterImageListContains($images ?? '', $imageTag);
        }

        // Native k3s: containerd is root-owned, so checking needs sudo. Only look
        // when sudo is already cached — never trigger a password prompt to check.
        $code = 0;
        exec('sudo -n true 2>/dev/null', $out, $code);
        if ($code !== 0) {
            return null;
        }
        $images = shell_exec('sudo -n k3s ctr -n k8s.io images ls -q 2>/dev/null');

        return $this->clusterImageListContains($images ?? '', $imageTag);
    }

    /**
     * Sideload a host-built image into a k3d cluster's nodes.
     */
    protected function sideloadIntoK3d(string $imageTag, string $cluster): void
    {
        // Confirm the cluster exists in k3d (also skips cleanly if k3d isn't installed).
        if (trim((string) shell_exec('k3d cluster list '.escapeshellarg($cluster).' --no-headers 2>/dev/null')) === '') {
            return;
        }

        $this->laraKubeInfo("Importing '$imageTag' into k3d cluster '$cluster'...");

        $output = [];
        $code = 0;
        exec('k3d image import '.escapeshellarg($imageTag).' -c '.escapeshellarg($cluster).' 2>&1', $output, $code);

        if ($code !== 0) {
            $this->laraKubeError("Could not sideload '$imageTag' into k3d cluster '$cluster'.");
            $this->line('  The local image is not visible to the cluster nodes, so pods will');
            $this->line('  likely fail with ImagePullBackOff. Last output from k3d:');
            foreach (array_slice($output, -4) as $line) {
                $this->line('    '.$line);
            }

            return;
        }

        // Verify availability on the cluster's server node.
        $this->withSpin('Verifying cluster image availability...', function () use ($imageTag, $cluster) {
            $images = shell_exec('docker exec k3d-'.$cluster.'-server-0 crictl images 2>/dev/null');

            return $this->clusterImageListContains($images ?? '', $imageTag);
        });
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
        passthru($base."sh -c '$command'");
    }

    /**
     * Fix file ownership in the project directory back to the host user.
     */
    protected function chownToHostUser(string $path): void
    {
        $uid = host_uid();
        $gid = host_gid();

        $appName = basename($path);
        $image = "$appName:local";

        // Fallback if image doesn't exist
        $imageExists = shell_exec("docker images -q {$image} 2>/dev/null");
        if (! $imageExists) {
            $image = $this->getProjectConfig($path)->getPhpImage(true);
        }

        passthru("docker run --rm --init -v $path:/var/www/html -w /var/www/html --user root $image chown -R $uid:$gid .");
    }
}
