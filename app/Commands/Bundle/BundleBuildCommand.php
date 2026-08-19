<?php

namespace App\Commands\Bundle;

use App\Enums\LaravelFeature;
use App\Traits\AssemblesBundle;
use App\Traits\InstallsK3s;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\InteractsWithRemoteDeploy;
use App\Traits\LaraKubeOutput;
use Illuminate\Support\Str;
use LaravelZero\Framework\Commands\Command;

/**
 * Build-side: assemble a self-contained air-gapped install kit for an on-prem
 * customer. Derives the image set from the blueprint (so it never drifts), builds
 * the app image for the target arch, saves every image to a tarball, copies the
 * manifests, and writes bundle.json. The customer later runs `bundle:install`
 * offline. (k3s artifacts + the bootstrap are a follow-up step.)
 */
class BundleBuildCommand extends Command
{
    use AssemblesBundle, InstallsK3s, InteractsWithProjectConfig, InteractsWithRemoteDeploy, LaraKubeOutput;

    protected $signature = 'bundle:build
                            {environment? : The environment to bundle (default: auto-detected offline/cloud env)}
                            {--arch= : Target CPU architecture (amd64|arm64) — match the customer server, not your Mac}
                            {--update : Build a lightweight update bundle (skips K3s and dependency images)}
                            {--tar : Compress the bundle into a .tar.gz file after assembly}
                            {--k9s : Bundle the k9s terminal UI alongside the kit}
                            {--dry-run : Show the plan (images, layout) without building or saving anything}
                            {--ca-cert= : Path to company CA certificate (.crt/.pem) — installs company CA on the target server}
                            {--ca-key= : Path to company CA private key — enables full-sign mode (server cert signed by company CA)}
                            {--tunnel : Bundle cloudflared for Cloudflare Tunnel — exposes a home/CGNAT server without port-forwarding}';

    protected $description = 'Assemble a self-contained air-gapped install kit (images + manifests) for an on-prem customer';

    public function handle(): int
    {
        $this->renderHeader();

        $config = $this->getProjectConfig(getcwd());
        if ($config === null) {
            $this->laraKubeError('Run this inside a LaraKube project.');

            return 1;
        }

        // No environment given — auto-detect. Offline environments are this
        // command's home turf, so they win first; fall back to the project's
        // other cloud environments when none are marked offline.
        $env = $this->argument('environment');

        if ($env === null) {
            $offlineEnvs = collect($config->environments)
                ->filter(fn ($e) => $e->offline ?? false)
                ->keys()
                ->all();

            if (count($offlineEnvs) === 1) {
                $env = $offlineEnvs[0];
                $this->laraKubeInfo("Auto-selected offline environment: {$env}");
            } elseif (count($offlineEnvs) > 1) {
                $env = \Laravel\Prompts\select(
                    label: 'Multiple offline environments found. Which one?',
                    options: $offlineEnvs,
                );
            } else {
                $cloudEnvs = $config->getCloudEnvironments();
                if (count($cloudEnvs) === 1) {
                    $env = $cloudEnvs[0];
                } elseif (count($cloudEnvs) > 1) {
                    $env = \Laravel\Prompts\select(
                        label: 'Which environment would you like to bundle?',
                        options: $cloudEnvs,
                    );
                }
            }
        }

        if ($env === null || $config->getEnvironment($env) === null) {
            $this->laraKubeError($env === null
                ? 'No cloud environment configured yet. Run `larakube env` first (e.g. `larakube env production`).'
                : "Unknown environment '{$env}'. Pick one of: ".implode(', ', $config->getCloudEnvironments()));

            return 1;
        }

        $envData = $config->getEnvironment($env);
        if (! ($envData->offline ?? false)) {
            $this->laraKubeWarn("Environment '{$env}' is not marked as offline. Consider: larakube env {$env} --offline");
        }

        $archOption = $this->option('arch');
        if (! $archOption) {
            $archOption = \Laravel\Prompts\select(
                label: 'What is the target CPU architecture of the customer server?',
                options: ['amd64' => 'amd64 (Intel/AMD - standard for most VPS/servers)', 'arm64' => 'arm64 (Apple Silicon / Graviton)'],
                default: 'amd64',
            );
        }

        $platform = $this->normalizeArch((string) $archOption);
        if ($platform === null) {
            $this->laraKubeError("Unsupported --arch '{$archOption}' — use amd64 or arm64.");

            return 1;
        }
        $arch = str_replace('linux/', '', $platform);

        $images = $this->bundleImages($config);
        $images['app'] = $config->getName().':'.$env.'-latest';
        $allImages = $this->option('update') ? [$images['app']] : array_merge([$images['app']], $images['dependencies']);
        $name = $config->getName();
        $timestamp = date('Ymd-His');
        $outDir = $config->getPath()."/dist/{$name}-{$env}-{$arch}-bundle-{$timestamp}";

        $this->laraKubeInfo("Air-gapped bundle — {$name} · {$env} · {$arch}");
        $this->newLine();
        $this->line('  <fg=gray>Output:</> '.$outDir);
        $this->line('  <fg=gray>App image (built):</> <fg=cyan>'.$images['app'].'</>');
        $this->line('  <fg=gray>Dependency images (saved):</>');
        foreach ($images['dependencies'] as $img) {
            $this->line('    • <fg=cyan>'.$img.'</>  <fg=gray>→ images/'.$this->imageTarName($img).'</>');
        }
        $this->newLine();

        // Validate company CA options before doing any real work.
        $caCertPath = (string) $this->option('ca-cert');
        $caKeyPath = (string) $this->option('ca-key');

        if ($caKeyPath !== '' && $caCertPath === '') {
            $this->laraKubeError('--ca-key requires --ca-cert to be provided as well.');

            return 1;
        }

        if ($caCertPath !== '' && ! file_exists($caCertPath)) {
            $this->laraKubeError("CA certificate not found: {$caCertPath}");

            return 1;
        }

        if ($caKeyPath !== '' && ! file_exists($caKeyPath)) {
            $this->laraKubeError("CA private key not found: {$caKeyPath}");

            return 1;
        }

        $caMode = match (true) {
            $caCertPath !== '' && $caKeyPath !== '' => 'full_sign',
            $caCertPath !== '' => 'trust_only',
            default => null,
        };

        if ($caMode !== null) {
            $modeLabel = $caMode === 'full_sign' ? 'full-sign (server cert signed by company CA)' : 'trust-only (company CA installed on target)';
            $this->laraKubeInfo("Company CA mode: {$modeLabel}");
            if ($caMode === 'full_sign') {
                $this->line('  <fg=yellow>⚠  The bundle will contain your company CA private key. Treat this archive as sensitive.</>');
            }
        }

        if ($this->option('dry-run')) {
            $this->laraKubeInfo('Dry run — nothing built or saved.');

            return 0;
        }

        @mkdir("$outDir/images", 0755, true);
        @mkdir("$outDir/manifests", 0755, true);

        $gitignorePath = $config->getPath().'/.gitignore';
        if (file_exists($gitignorePath)) {
            $gitignore = file_get_contents($gitignorePath);
            if (! str_contains($gitignore, '/dist')) {
                file_put_contents($gitignorePath, rtrim($gitignore)."\n/dist\n");
            }
        }

        $dockerignorePath = $config->getPath().'/.dockerignore';
        if (file_exists($dockerignorePath)) {
            $dockerignore = file_get_contents($dockerignorePath);
            if (! str_contains($dockerignore, '/dist')) {
                file_put_contents($dockerignorePath, rtrim($dockerignore)."\n/dist\n");
            }
        }

        // Pre-generate REVERB_APP_KEY now so it can be baked into the JS assets
        // via the Docker assets stage --build-arg. The same key is written to
        // bundle.json and used by bundle:install — this is the only way to keep
        // the baked VITE_REVERB_APP_KEY and the runtime REVERB_APP_KEY in sync.
        $reverbAppKey = null;
        if ($config->hasFeature(LaravelFeature::REVERB, $env)) {
            $reverbAppKey = Str::random(32);
        }

        if (! $this->runPreDeploymentSteps($config)) {
            return 1;
        }

        // 1. Build the app image for the TARGET arch (the customer's, not the Mac's).
        //    VITE_* vars are appended to the .env secret so they are baked into the
        //    JS bundle inside the Docker assets stage without touching public/build/.
        $this->laraKubeInfo("Building app image for {$platform}…");
        $dotenvTemporaryDirectory = $this->createDotenvBuildSecret($config, $env, $reverbAppKey);
        $dotenvPath = $dotenvTemporaryDirectory->path().'/dotenv-build-secret';
        try {
            $code = $this->runStreaming($this->buildProductionImageCommand($images['app'], $config->getPath().'/Dockerfile.php', $config->getPath(), $platform, $dotenvPath));
        } finally {
            $dotenvTemporaryDirectory->delete();
        }
        if ($code !== 0) {
            $this->laraKubeError('App image build failed.');

            return 1;
        }

        // 2. Pull (dependencies, for the target arch) then save every image to a tarball.
        foreach ($allImages as $index => $image) {
            $tar = "$outDir/images/".$this->imageTarName($image);
            if ($index > 0) {
                $this->line('  <fg=gray>pull</> '.$image.' for '.$platform);
                $this->runStreaming('docker pull --platform '.escapeshellarg($platform).' '.escapeshellarg($image));
            }
            $this->line('  <fg=gray>save</> '.$image);
            $code = $this->runStreaming('docker save --platform '.escapeshellarg($platform).' '.escapeshellarg($image).' -o '.escapeshellarg($tar));
            if ($code !== 0) {
                $this->laraKubeError("Failed to save {$image} — is it built/pulled and is Docker running?");

                return 1;
            }
        }

        // 3. Manifests — copy the generated kustomize tree (image refs stay `<app>:latest`;
        //    `bundle:install` imports the saved app image under that exact name, IfNotPresent).
        $k8s = $config->getK8sPath();
        if (is_dir($k8s)) {
            $this->runStreaming('cp -R '.escapeshellarg($k8s.'/.').' '.escapeshellarg("$outDir/manifests"));

            // Prune overlays for other environments to avoid leaking configs
            foreach (array_keys($config->getEnvironments()) as $otherEnv) {
                if ($otherEnv !== $env) {
                    $overlayPath = "$outDir/manifests/overlays/{$otherEnv}";
                    if (is_dir($overlayPath)) {
                        $this->runStreaming('rm -rf '.escapeshellarg($overlayPath));
                    }
                }
            }

        }
        $this->runStreaming('cp '.escapeshellarg($config->getPath().'/.larakube.json').' '.escapeshellarg("$outDir/.larakube.json"));
        if (file_exists($config->getPath().'/.env.example')) {
            $this->runStreaming('cp '.escapeshellarg($config->getPath().'/.env.example').' '.escapeshellarg("$outDir/.env.example"));
        }

        $isUpdate = $this->option('update');
        $k3sVersion = $this->k3sVersion($config);
        $kustomizeVersion = $config->kustomizeVersion ?? 'v5.6.0';
        $kArch = $arch === 'arm64' ? 'arm64' : 'amd64';

        $this->laraKubeInfo("Downloading kustomize standalone binary ({$kustomizeVersion})...");
        $kustomizeUrl = "https://github.com/kubernetes-sigs/kustomize/releases/download/kustomize%2F{$kustomizeVersion}/kustomize_{$kustomizeVersion}_linux_{$kArch}.tar.gz";
        $this->runStreaming('curl -sL '.escapeshellarg($kustomizeUrl).' | tar -xz -C '.escapeshellarg($outDir).' kustomize');
        $this->runStreaming('chmod +x '.escapeshellarg("$outDir/kustomize"));

        if ($this->option('k9s')) {
            $k9sVersion = $config->k9sVersion ?? 'v0.32.7';
            $this->laraKubeInfo("Downloading k9s ({$k9sVersion})...");
            $k9sUrl = "https://github.com/derailed/k9s/releases/download/{$k9sVersion}/k9s_Linux_{$kArch}.tar.gz";
            $this->runStreaming('curl -sL '.escapeshellarg($k9sUrl).' | tar -xz -C '.escapeshellarg($outDir).' k9s');
            $this->runStreaming('chmod +x '.escapeshellarg("$outDir/k9s"));
        }

        if (! $isUpdate) {
            // 4. Offline k3s artifacts & larakube binary
            $this->laraKubeInfo("Downloading k3s artifacts ({$k3sVersion}) for offline install...");

            $k3sBinaryUrl = "https://github.com/k3s-io/k3s/releases/download/{$k3sVersion}/k3s".($arch === 'arm64' ? '-arm64' : '');
            $k3sImagesUrl = "https://github.com/k3s-io/k3s/releases/download/{$k3sVersion}/k3s-airgap-images-{$arch}.tar";
            $k3sInstallUrl = 'https://get.k3s.io';

            $this->line('  <fg=gray>download</> k3s binary');
            $this->runStreaming('curl -sL '.escapeshellarg($k3sBinaryUrl).' -o '.escapeshellarg("$outDir/k3s"));
            $this->runStreaming('chmod +x '.escapeshellarg("$outDir/k3s"));

            $this->line('  <fg=gray>download</> k3s-airgap-images');
            $this->runStreaming('curl -sL '.escapeshellarg($k3sImagesUrl).' -o '.escapeshellarg("$outDir/k3s-airgap-images.tar"));

            $this->line('  <fg=gray>download</> k3s-install.sh');
            $this->runStreaming('curl -sL '.escapeshellarg($k3sInstallUrl).' -o '.escapeshellarg("$outDir/k3s-install.sh"));
            $this->runStreaming('chmod +x '.escapeshellarg("$outDir/k3s-install.sh"));
        }

        $binArch = $arch === 'amd64' ? 'x64' : 'arm';
        $binaryName = "larakube-linux-{$binArch}";

        $this->line("  <fg=gray>download</> larakube binary (Linux {$binArch})");
        $binaryUrl = "https://github.com/luchavez-technologies/larakube-cli/releases/latest/download/{$binaryName}";
        $this->runStreaming('curl -sL '.escapeshellarg($binaryUrl).' -o '.escapeshellarg("$outDir/larakube"));

        $this->runStreaming('chmod +x '.escapeshellarg("$outDir/larakube"));

        if ($this->option('tunnel')) {
            $this->laraKubeInfo("Downloading cloudflared for Cloudflare Tunnel ({$arch})...");
            $cloudflaredUrl = "https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-linux-{$arch}";
            $this->runStreaming('curl -sL '.escapeshellarg($cloudflaredUrl).' -o '.escapeshellarg("$outDir/cloudflared"));
            $this->runStreaming('chmod +x '.escapeshellarg("$outDir/cloudflared"));
        }

        // Write clean-slate reset script (always included)
        file_put_contents("$outDir/reset.sh", $this->resetScript());
        $this->runStreaming('chmod +x '.escapeshellarg("$outDir/reset.sh"));

        // 5a. Company CA — copy into ca/ so bundle:install can find it without flags.
        if ($caMode !== null) {
            @mkdir("$outDir/ca", 0700, true);
            copy($caCertPath, "$outDir/ca/company-ca.crt");
            if ($caMode === 'full_sign') {
                copy($caKeyPath, "$outDir/ca/company-ca.key");
                $this->runStreaming('chmod 600 '.escapeshellarg("$outDir/ca/company-ca.key"));
            }
        }

        // 5. bundle.json
        $manifestData = $this->bundleManifest($config, $env, $arch, $allImages);
        $manifestData['k3sVersion'] = $k3sVersion;
        if ($reverbAppKey !== null) {
            $manifestData['reverbAppKey'] = $reverbAppKey;
        }
        if ($caMode !== null) {
            $manifestData['caMode'] = $caMode;
        }
        if ($this->option('tunnel')) {
            $manifestData['tunnelEnabled'] = true;
        }
        file_put_contents(
            "$outDir/bundle.json",
            json_encode($manifestData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
        );

        $this->newLine();
        $this->laraKubeInfo('✅ Bundle assembled: '.$outDir);

        if ($this->option('tar')) {
            $this->laraKubeInfo('Compressing bundle into a .tar.gz archive...');
            $tarFile = $outDir.'.tar.gz';
            $baseDir = dirname($outDir);
            $folderName = basename($outDir);

            $tarCode = $this->runStreaming('tar -czf '.escapeshellarg($tarFile).' -C '.escapeshellarg($baseDir).' '.escapeshellarg($folderName));

            if ($tarCode === 0) {
                $this->laraKubeInfo('✅ Bundle compressed successfully: '.$tarFile);
                $this->laraKubeInfo('You can safely delete the uncompressed folder: rm -rf '.escapeshellarg($outDir));

                // Generate instructions file
                $cmd = $isUpdate ? 'bundle:update' : 'bundle:install';
                $instructionsFile = $outDir.'-INSTRUCTIONS.txt';
                $instructions = <<<TXT
=========================================================
LARAKUBE AIR-GAPPED BUNDLE INSTRUCTIONS
=========================================================

1. TRANSFER THE BUNDLE TO THE SERVER
   If your server has SSH enabled, transfer the bundle securely with rsync (which shows a progress bar):
   rsync -P {$folderName}.tar.gz username@your-server-ip:~/

2. EXTRACT THE BUNDLE
   SSH into your server and run the following command to extract it:
   tar -xzf {$folderName}.tar.gz

3. RUN THE DEPLOYMENT
   cd {$folderName}
   sudo ./larakube {$cmd}

=========================================================
TXT;
                file_put_contents($instructionsFile, $instructions);
                $this->laraKubeInfo('✅ Generated instructions: '.$instructionsFile);
            } else {
                $this->laraKubeError('Failed to compress the bundle.');
            }
        }

        $cmd = $isUpdate ? 'bundle:update' : 'bundle:install';
        $this->laraKubeInfo("Next step: copy the bundle to the target server and run `sudo ./larakube {$cmd}`.");

        return 0;
    }

    private function resetScript(): string
    {
        return <<<'BASH'
#!/usr/bin/env bash
# larakube-reset — wipe k3s, swap, and k9s for a clean reinstall.
set -euo pipefail

echo "==> Stopping and removing k3s..."
if command -v k3s-uninstall.sh &>/dev/null; then
    k3s-uninstall.sh
    echo "    Done."
else
    echo "    k3s not installed, skipping."
fi

echo "==> Removing swap..."
if [ -f /swapfile ]; then
    swapoff /swapfile 2>/dev/null || true
    sed -i '/\/swapfile/d' /etc/fstab
    rm -f /swapfile
    echo "    Done."
else
    echo "    No /swapfile found, skipping."
fi

echo "==> Removing k9s..."
if [ -f /usr/local/bin/k9s ]; then
    rm -f /usr/local/bin/k9s
    echo "    Done."
else
    echo "    k9s not installed, skipping."
fi

echo "==> Removing cloudflared tunnel..."
if systemctl is-active --quiet cloudflared 2>/dev/null; then
    systemctl stop cloudflared
fi
systemctl disable cloudflared 2>/dev/null || true
rm -f /etc/systemd/system/cloudflared.service
rm -f /usr/local/bin/cloudflared
systemctl daemon-reload 2>/dev/null || true
echo "    Done."

echo "==> Removing larakube-reset..."
rm -f /usr/local/bin/larakube-reset

echo ""
echo "Clean slate. Re-run 'sudo ./larakube bundle:install' to reinstall."
BASH;
    }
}
