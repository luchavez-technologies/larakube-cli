<?php

namespace App\Commands\Bundle;

use App\Traits\GeneratesBundleSecrets;
use App\Traits\GeneratesOfflineCertificates;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\InteractsWithRemoteDeploy;
use App\Traits\LaraKubeOutput;
use App\Traits\PromptsForHosts;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use LaravelZero\Framework\Commands\Command;

/**
 * Install-side: extract and deploy the air-gapped bundle on the customer's server.
 * This runs completely offline. It reads the customer's .env if provided, merges
 * it with auto-generated secure credentials, imports the container images, and
 * applies the K8s manifests to the local k3s cluster.
 */
class BundleUpdateCommand extends Command
{
    use GeneratesBundleSecrets, GeneratesOfflineCertificates, InteractsWithProjectConfig, InteractsWithRemoteDeploy, LaraKubeOutput, PromptsForHosts;

    protected $signature = 'bundle:update
                            {--env= : Path to a custom .env file to merge with auto-generated secrets}';

    protected $description = 'Update an air-gapped bundle on the current server';

    public function handle(): int
    {
        $this->renderHeader();

        // 0. Pre-flight checks
        $missing = [];
        foreach (['openssl', 'curl', 'tar'] as $tool) {
            if (Process::run("which {$tool}")->output() === '') {
                $missing[] = $tool;
            }
        }
        if (count($missing) > 0) {
            $this->laraKubeError('Missing required system tools: '.implode(', ', $missing));
            $this->line('  <fg=gray>Please ensure these basic Linux utilities are installed before running the offline installer.</>');

            return 1;
        }

        // 1. Verify we are inside an extracted bundle directory
        if (! file_exists('bundle.json') || ! file_exists('.larakube.json')) {
            $this->laraKubeError("This command must be run from inside an extracted air-gapped bundle directory.\nMake sure bundle.json and .larakube.json are present.");

            return 1;
        }

        $bundleManifest = json_decode((string) file_get_contents('bundle.json'), true);
        if (! is_array($bundleManifest)) {
            $this->laraKubeError('Invalid bundle.json.');

            return 1;
        }

        $config = $this->getProjectConfig(getcwd());
        if ($config === null) {
            $this->laraKubeError('Failed to load .larakube.json.');

            return 1;
        }

        $env = $bundleManifest['environment'];
        $namespace = $config->getNamespace($env);
        $name = $config->getName();

        $this->laraKubeInfo("Updating air-gapped bundle — {$name} · {$env}");

        $this->laraKubeInfo('Waiting for K3s containerd to become ready...');
        for ($i = 0; $i < 30; $i++) {
            if (Process::run('k3s ctr version')->successful()) {
                break;
            }
            Sleep::sleep(1);
        }

        $this->laraKubeInfo('Importing application and dependency images into containerd...');
        foreach (glob('images/*.tar') as $tar) {
            $this->line("  <fg=gray>import</> {$tar}");
            $success = false;
            for ($attempt = 1; $attempt <= 10; $attempt++) {
                $importCode = $this->runStreaming('k3s ctr images import '.escapeshellarg($tar));
                if ($importCode === 0) {
                    $success = true;
                    break;
                }

                $this->line("  <fg=yellow>import failed. Waiting for containerd to recover... ({$attempt}/10)</>");

                // Actively wait for the containerd socket to come back online
                for ($wait = 0; $wait < 30; $wait++) {
                    if (Process::run('k3s ctr version')->successful()) {
                        break;
                    }
                    Sleep::sleep(1);
                }

                // Give it an extra 5 seconds of breathing room after the socket responds
                Sleep::sleep(5);
            }

            if (! $success) {
                $this->laraKubeError("Failed to import {$tar} after 10 attempts.");

                return 1;
            }
        }

        // 4. Secrets Generation & Customer .env Merging
        $ns = escapeshellarg($namespace);

        // Extract existing secrets from the cluster (for idempotent updates)
        $existingSecretsJson = Process::run("kubectl get secret laravel-secrets -n {$ns} -o json")->output();
        $existingSecrets = [];
        if ($existingSecretsJson !== '') {
            $parsed = json_decode($existingSecretsJson, true);
            if (isset($parsed['data']) && is_array($parsed['data'])) {
                foreach ($parsed['data'] as $k => $v) {
                    $existingSecrets[$k] = base64_decode($v);
                }
            }
        }

        $this->laraKubeInfo('Generating secure install secrets...');
        $mergedEnv = $this->generateInstallSecrets($config, $env, $existingSecrets);

        $envFile = (string) $this->option('env');
        if ($envFile === '') {
            $envFile = getcwd().'/.env';
        }

        if (! file_exists($envFile) && file_exists(getcwd().'/.env.example')) {
            $this->newLine();
            $this->laraKubeInfo('Almost ready to deploy! We found a .env.example file in the bundle.');
            $this->line('  Please copy it to .env and fill in any required third-party API keys (like AIRTABLE_API_KEY).');

            \Laravel\Prompts\pause('Press ENTER when you have created and saved the .env file.');
        }

        if (file_exists($envFile)) {
            $this->line('  <fg=gray>Merging customer provided env file:</> <fg=cyan>'.$envFile.'</>');
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
                    continue;
                }
                [$key, $value] = explode('=', $line, 2);
                $key = trim($key);
                if ($key !== '') {
                    // Customer overrides take precedence (e.g., they can override DB_PASSWORD if they really want)
                    $mergedEnv[$key] = trim($value);
                }
            }
        } elseif ((string) $this->option('env') !== '') {
            $this->laraKubeError("Provided env file '".((string) $this->option('env'))."' does not exist.");

            return 1;
        }

        foreach ($mergedEnv as $key => $value) {
            if (isset($existingSecrets[$key])) {
                $this->line("  <fg=gray>Restored existing secret:</> <fg=cyan>{$key}</>");
            } else {
                $this->line("  <fg=green>Generated new secret:</> <fg=cyan>{$key}</>");
            }
        }

        // Get ALL base environment variables defined by the blueprint (DB_CONNECTION, DB_HOST, etc.)
        $finalEnv = $config->getAllEnvironmentVariables($env);

        // Apply our generated secrets and any overrides from the customer's .env
        foreach ($mergedEnv as $k => $v) {
            $finalEnv[$k] = $v;
        }

        $mergedLines = [];
        foreach ($finalEnv as $k => $v) {
            $mergedLines[] = "{$k}={$v}";
        }

        // Get known secrets from ConfigData
        $knownSecrets = array_keys($config->getAllSecretEnvironmentVariables($env));

        // Split for K8s ConfigMap (public) vs Secret (secret)
        ['public' => $public, 'secret' => $secret] = $this->splitEnvForK8s($mergedLines, $knownSecrets);

        $this->laraKubeInfo('Waiting for Kubernetes API to be ready...');
        for ($wait = 0; $wait < 60; $wait++) {
            if (Process::run('kubectl get nodes')->successful()) {
                break;
            }
            Sleep::sleep(2);
        }

        if ($public !== '') {
            Process::run("kubectl create configmap laravel-config -n {$ns} {$public} --dry-run=client -o yaml | kubectl apply -f -");
        }
        if ($secret !== '') {
            Process::run("kubectl create secret generic laravel-secrets -n {$ns} {$secret} --dry-run=client -o yaml | kubectl apply -f -");
        }

        // 8. Apply manifests
        $this->laraKubeInfo('Applying Kubernetes manifests...');
        $overlayPath = getcwd().'/manifests/overlays/'.$env;

        if (! is_dir($overlayPath)) {
            $this->laraKubeError("Manifests not found at {$overlayPath}. Ensure bundle:build copied them correctly.");

            return 1;
        }

        // Use the standalone Kustomize binary bundled with the tarball to bypass older K3s parser bugs
        $applyCmd = getcwd().'/kustomize build '.escapeshellarg($overlayPath).' | kubectl apply -f -';

        $applyCode = $this->runStreaming($applyCmd);
        if ($applyCode !== 0) {
            $this->laraKubeError('Manifest apply failed.');

            return 1;
        }

        // Force a rollout restart to use the newly imported image
        $this->laraKubeInfo('Triggering zero-downtime rolling update...');
        $this->runStreaming('kubectl rollout restart deployment -l app=laravel -n '.escapeshellarg($namespace));

        // 9. Wait for rollout
        $this->laraKubeInfo('Waiting for rollout...');
        $this->runStreaming('kubectl rollout status deploy/web -n '.escapeshellarg($namespace).' --timeout=180s', 190);

        $this->newLine();
        $this->laraKubeInfo('✅ Bundle successfully updated!');
        $this->newLine();

        return 0;
    }
}
