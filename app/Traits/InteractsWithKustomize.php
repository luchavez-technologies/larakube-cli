<?php

namespace App\Traits;

use App\Data\ConfigData;
use Illuminate\Support\Facades\Process;

/**
 * Local manifest builds (up, kustomize, cloud:deploy) render our overlays — including a
 * multi-document `patches:` file that older kustomize can't parse. kubectl's EMBEDDED
 * kustomize varies across machines and its VERSION isn't a reliable signal (k3s/WSL ship a
 * very old one; even kubectl-bundled v5.0.4 reports "v5" but still can't handle multi-doc
 * patches). So we don't trust the version: a quick functional PROBE checks whether the
 * machine's own kustomize can actually build the overlay. If it can, we use it and download
 * nothing (recent macOS/OrbStack). If it can't, we install the CLI's pinned kustomize into
 * ~/.larakube/bin (isolated, no sudo, never shadows a system binary) and build with that.
 */
trait InteractsWithKustomize
{
    /** The CLI's own pinned standalone kustomize — isolated, never shadows a system binary. */
    protected function managedKustomizePath(): string
    {
        return home_path('.larakube/bin/kustomize');
    }

    /** The single version we install when a machine's own kustomize can't build our overlays. */
    protected function pinnedKustomizeVersion(): string
    {
        return (new ConfigData)->kustomizeVersion ?? 'v5.6.0';
    }

    /**
     * The kustomize binary to BUILD with, or null to fall back to `kubectl kustomize`.
     * Non-null only when we installed our standalone (because the machine's own kustomize
     * couldn't build the overlays). Capable machines keep this null and use their own.
     */
    protected function kustomizeBin(): ?string
    {
        $bin = $this->managedKustomizePath();

        return (is_file($bin) && is_executable($bin)) ? $bin : null;
    }

    /** Build an overlay dir to stdout: standalone `kustomize build` or `kubectl kustomize`. */
    protected function kustomizeBuildCommand(string $path): string
    {
        $bin = $this->kustomizeBin();

        return $bin !== null
            ? escapeshellarg($bin).' build '.escapeshellarg($path)
            : 'kubectl kustomize '.escapeshellarg($path);
    }

    /** Apply an overlay dir, using the same kustomize the build path resolves to. */
    protected function kustomizeApplyCommand(string $path): string
    {
        $bin = $this->kustomizeBin();

        return $bin !== null
            ? escapeshellarg($bin).' build '.escapeshellarg($path).' | kubectl apply -f -'
            : 'kubectl apply -k '.escapeshellarg($path);
    }

    /**
     * Ensure a kustomize that can build our overlays is available. If the machine's own
     * kustomize already builds our multi-doc `patches:` (recent macOS/OrbStack), use it —
     * no download. Otherwise (k3s/WSL, or an older v5 like v5.0.4) install the pinned
     * standalone. Best-effort — if the download fails (offline), builds fall back to
     * `kubectl kustomize`.
     */
    protected function ensureKustomizeReady(bool $verbose = true): void
    {
        // We already installed our standalone on a prior run — use it.
        if ($this->kustomizeBin() !== null) {
            return;
        }

        // Can the machine's own kustomize build our overlays? If so, no download needed.
        if ($this->embeddedKustomizeBuildsOurPatches()) {
            return;
        }

        $this->installManagedKustomize($this->pinnedKustomizeVersion(), $verbose);
    }

    /** Does kubectl's embedded kustomize build a representative multi-doc `patches:` overlay? */
    protected function embeddedKustomizeBuildsOurPatches(): bool
    {
        return $this->kustomizeHandlesMultiDocPatches('kubectl kustomize');
    }

    /**
     * Functional probe replicating exactly what we emit and what breaks: `patches: - path:`
     * pointing at a MULTI-DOCUMENT patch file (overlays/local/patches.yaml = deployment patch
     * `---` ingress patch). An older embedded kustomize fails this ("unable to parse SM or
     * JSON patch"); a capable one builds it. Version-agnostic and self-correcting — it tests
     * the real capability rather than guessing from a version string. Local-only (no cluster
     * contact). $buildPrefix is "kubectl kustomize" or "<bin> build".
     */
    protected function kustomizeHandlesMultiDocPatches(string $buildPrefix): bool
    {
        $dir = sys_get_temp_dir().'/lk-kustomize-probe-'.uniqid();
        @mkdir($dir, 0755, true);

        file_put_contents($dir.'/deploy.yaml', implode("\n", [
            'apiVersion: apps/v1',
            'kind: Deployment',
            'metadata:',
            '  name: probe',
            'spec:',
            '  replicas: 1',
            '  selector:',
            '    matchLabels:',
            '      app: probe',
            '  template:',
            '    metadata:',
            '      labels:',
            '        app: probe',
            '    spec:',
            '      containers:',
            '        - name: c',
            '          image: nginx',
        ])."\n");

        // Multi-document patch file — the exact shape that trips an older embedded kustomize.
        file_put_contents($dir.'/patches.yaml', implode("\n", [
            'apiVersion: apps/v1',
            'kind: Deployment',
            'metadata:',
            '  name: probe',
            'spec:',
            '  replicas: 2',
            '---',
            'apiVersion: apps/v1',
            'kind: Deployment',
            'metadata:',
            '  name: probe',
            'spec:',
            '  template:',
            '    metadata:',
            '      labels:',
            '        probed: "true"',
        ])."\n");

        file_put_contents($dir.'/kustomization.yaml', implode("\n", [
            'resources:',
            '  - deploy.yaml',
            'patches:',
            '  - path: patches.yaml',
        ])."\n");

        $result = Process::run($buildPrefix.' '.escapeshellarg($dir));

        @unlink($dir.'/deploy.yaml');
        @unlink($dir.'/patches.yaml');
        @unlink($dir.'/kustomization.yaml');
        @rmdir($dir);

        return $result->successful() && trim($result->output()) !== '';
    }

    /** Download the pinned kustomize into ~/.larakube/bin (no sudo, no system changes). */
    protected function installManagedKustomize(string $version, bool $verbose): void
    {
        $machine = php_uname('m');
        $arch = in_array($machine, ['arm64', 'aarch64'], true) ? 'arm64' : 'amd64';
        $os = PHP_OS_FAMILY === 'Darwin' ? 'darwin' : 'linux';   // WSL reports linux

        $bin = $this->managedKustomizePath();
        $binDir = dirname($bin);
        @mkdir($binDir, 0755, true);

        if ($verbose) {
            $this->laraKubeInfo("Your kustomize can't build these manifests — installing a standalone kustomize {$version}...");
        }

        $url = "https://github.com/kubernetes-sigs/kustomize/releases/download/kustomize%2F{$version}/kustomize_{$version}_{$os}_{$arch}.tar.gz";
        Process::forever()->run('curl -sL '.escapeshellarg($url).' | tar -xz -C '.escapeshellarg($binDir).' kustomize');
        @chmod($bin, 0755);

        // Confirm it runs the pinned version; otherwise drop it so the build cleanly
        // falls back to `kubectl kustomize` rather than using a broken/partial binary.
        $out = (is_file($bin) && is_executable($bin))
            ? Process::run(escapeshellarg($bin).' version')->output()
            : '';

        if (str_contains($out, $version)) {
            if ($verbose) {
                $this->laraKubeInfo('✅ kustomize ready at '.$bin);
            }

            return;
        }

        @unlink($bin);
        if ($verbose) {
            $this->laraKubeWarn("Could not install kustomize {$version} automatically (offline?).");
            $this->laraKubeLine('  👉 Builds will use your kubectl\'s kustomize for now; install kustomize v5.6+ for consistent results.');
        }
    }
}
