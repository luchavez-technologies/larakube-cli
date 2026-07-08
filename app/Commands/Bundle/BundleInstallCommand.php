<?php

namespace App\Commands\Bundle;

use App\Traits\GeneratesBundleSecrets;
use App\Traits\GeneratesOfflineCertificates;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\InteractsWithRemoteDeploy;
use App\Traits\LaraKubeOutput;
use App\Traits\PromptsForHosts;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;

/**
 * Install-side: extract and deploy the air-gapped bundle on the customer's server.
 * This runs completely offline. It reads the customer's .env if provided, merges
 * it with auto-generated secure credentials, imports the container images, and
 * applies the K8s manifests to the local k3s cluster.
 */
class BundleInstallCommand extends Command
{
    use GeneratesBundleSecrets, GeneratesOfflineCertificates, InteractsWithProjectConfig, InteractsWithRemoteDeploy, LaraKubeOutput, PromptsForHosts;

    protected $signature = 'bundle:install
                            {--env= : Path to a custom .env file to merge with auto-generated secrets}
                            {--skip-images : Skip importing Docker images into containerd (useful for re-running configuration)}
                            {--swap=1G : Size of swap file to create (e.g. 2G). Defaults to 1G. Prevents crashes on small servers.}';

    protected $description = 'Install an air-gapped bundle on the current server';

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

        $this->laraKubeInfo("Installing air-gapped bundle — {$name} · {$env}");

        // 1.5. Optional Swap Creation (must happen before heavy k3s/docker loads)
        if (($swapSize = $this->option('swap')) !== null) {
            // Normalize bare numbers to gigabytes (--swap=2 → 2G)
            if (preg_match('/^\d+$/', $swapSize)) {
                $swapSize .= 'G';
            }
            $this->laraKubeInfo("Allocating {$swapSize} swap file...");
            if (file_exists('/swapfile')) {
                $this->line('  <fg=gray>/swapfile already exists. Skipping.</>');
            } else {
                $swapCode = $this->runStreaming('fallocate -l '.escapeshellarg($swapSize).' /swapfile');
                if ($swapCode === 0) {
                    $this->runStreaming('chmod 600 /swapfile');
                    $this->runStreaming('mkswap /swapfile');
                    $this->runStreaming('swapon /swapfile');
                    $this->runStreaming("echo '/swapfile none swap sw 0 0' | tee -a /etc/fstab > /dev/null");
                    $this->laraKubeInfo('✅ Swap file activated.');
                } else {
                    $this->laraKubeError('Failed to allocate swap space (check disk space or permissions).');
                    // We don't abort install on swap failure, but let the user know.
                }
            }
        }

        // 2. Detect/install k3s (INSTALL_K3S_SKIP_DOWNLOAD=true)
        $this->laraKubeInfo('Ensuring k3s is installed (offline mode)...');
        $k3sInstalled = trim(Process::run('which k3s')->output()) !== '';

        if (! $k3sInstalled) {
            $this->line('  <fg=gray>K3s not found. Installing from offline artifacts...</>');

            // Put images in containerd directory BEFORE install
            $this->runStreaming('mkdir -p /var/lib/rancher/k3s/agent/images/');
            $this->runStreaming('cp k3s-airgap-images.tar /var/lib/rancher/k3s/agent/images/');

            // Put k3s binary in PATH
            $this->runStreaming('cp k3s /usr/local/bin/k3s');

            // Run offline installer
            $k3sCode = $this->runStreaming('INSTALL_K3S_SKIP_DOWNLOAD=true ./k3s-install.sh --disable=traefik --write-kubeconfig-mode 644');
            if ($k3sCode !== 0) {
                $this->laraKubeError('K3s installation failed.');

                return 1;
            }
            $this->laraKubeInfo('✅ K3s installed successfully.');
        } else {
            $this->laraKubeInfo('✅ K3s is already installed. Skipping installation.');
        }

        // Make the k3s kubeconfig available at the standard path so kubectl, k9s,
        // and other tools work without needing KUBECONFIG set explicitly. This
        // script requires root throughout (update-ca-certificates, /etc/rancher,
        // systemctl, /usr/local/bin), so root's home is always /root regardless
        // of $HOME — which varies with the operator's sudoers env_reset config.
        $homeDir = '/root';
        $this->runStreaming('mkdir -p '.escapeshellarg("{$homeDir}/.kube").' && cp /etc/rancher/k3s/k3s.yaml '.escapeshellarg("{$homeDir}/.kube/config"));

        if (file_exists('./k9s')) {
            $this->laraKubeInfo('Installing k9s terminal UI...');
            $this->runStreaming('cp k9s /usr/local/bin/k9s');
            $this->runStreaming('chmod +x /usr/local/bin/k9s');
            $this->laraKubeInfo('✅ k9s installed at /usr/local/bin/k9s');
        }

        $tunnelToken = null;
        if (($bundleManifest['tunnelEnabled'] ?? false) && file_exists('./cloudflared')) {
            $this->laraKubeInfo('Cloudflare Tunnel detected in bundle.');
            $this->line('  <fg=gray>Create a tunnel at:</> <fg=cyan>https://one.dash.cloudflare.com → Zero Trust → Networks → Tunnels</>');
            $this->line('  <fg=gray>Choose "Cloudflared" → copy the token from the install command.</>');
            $tunnelToken = \Laravel\Prompts\password(
                label: 'Cloudflare tunnel token (leave blank to skip)',
                placeholder: 'eyJhIjoiM...',
            );
        }

        $this->laraKubeInfo('Waiting for K3s containerd to become ready...');
        for ($i = 0; $i < 30; $i++) {
            if (Process::run('k3s ctr version')->successful()) {
                break;
            }
            sleep(1);
        }

        if ($this->option('skip-images')) {
            $this->laraKubeInfo('Skipping image imports (--skip-images passed)...');
        } else {
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
                        sleep(1);
                    }

                    // Give it an extra 5 seconds of breathing room after the socket responds
                    sleep(5);
                }

                if (! $success) {
                    $this->laraKubeError("Failed to import {$tar} after 10 attempts.");

                    return 1;
                }
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

        // If bundle:build pre-generated REVERB_APP_KEY to bake into JS assets,
        // re-use that same key here so the runtime value matches the baked one.
        if (isset($bundleManifest['reverbAppKey']) && ! isset($existingSecrets['REVERB_APP_KEY'])) {
            $existingSecrets['REVERB_APP_KEY'] = $bundleManifest['reverbAppKey'];
        }

        $this->laraKubeInfo('Generating secure install secrets...');
        $mergedEnv = $this->generateInstallSecrets($config, $env, $existingSecrets);

        foreach ($mergedEnv as $key => $value) {
            if (isset($existingSecrets[$key])) {
                $this->line("  <fg=gray>Restored existing secret:</> <fg=cyan>{$key}</>");
            } else {
                $this->line("  <fg=green>Generated new secret:</> <fg=cyan>{$key}</>");
            }
        }

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

        // 5. Resolve hostnames — use values already stored in .larakube.json when
        //    available (set during `larakube env airgap --offline` on the dev machine).
        //    Only fall back to interactive prompts when no host has been configured.
        $webDefault = $config->getWebHost($env);

        if (! empty($webDefault)) {
            $this->laraKubeInfo('Using configured hostnames from bundle...');
            $hosts = array_filter([
                'web' => $webDefault,
                'reverb' => $config->getHost($env, 'reverb'),
            ]);
        } else {
            $this->laraKubeInfo('Configuring hostnames...');
            $hosts = $this->promptForHosts($env, $config->getComponents($env));
            foreach ($hosts as $service => $host) {
                $config->setHost($service, $host, $env);
            }
        }

        // APP_URL / ASSET_URL / VITE_REVERB_* were computed before the hostname
        // prompt ran and still hold blueprint placeholders. Override them now and
        // re-split so the ConfigMap gets the real domains.
        $urlOverrides = [];

        if (isset($hosts['web'])) {
            $appUrl = 'https://'.$hosts['web'];
            $urlOverrides['APP_URL'] = $appUrl;
            $urlOverrides['ASSET_URL'] = $appUrl;
        }

        if (isset($hosts['reverb'])) {
            // REVERB_HOST stays as the internal cluster FQDN for PHP server-side.
            // The browser connects to the external host — inject it as VITE_REVERB_HOST
            // so apps that resolve it at runtime (rather than baking via Vite) get the
            // right value.
            $urlOverrides['VITE_REVERB_HOST'] = $hosts['reverb'];
            $urlOverrides['VITE_REVERB_PORT'] = '443';
            $urlOverrides['VITE_REVERB_SCHEME'] = 'https';
            $urlOverrides['VITE_REVERB_APP_KEY'] = $finalEnv['REVERB_APP_KEY'] ?? ($finalEnv['VITE_REVERB_APP_KEY'] ?? '');
        }

        if ($urlOverrides !== []) {
            foreach ($urlOverrides as $k => $v) {
                $finalEnv[$k] = $v;
            }
            $mergedLines = [];
            foreach ($finalEnv as $k => $v) {
                $mergedLines[] = "{$k}={$v}";
            }
            ['public' => $public, 'secret' => $secret] = $this->splitEnvForK8s($mergedLines, $knownSecrets);
        }

        // 6. Company CA trust + TLS certificate generation
        $bundledCaCrt = getcwd().'/ca/company-ca.crt';
        $bundledCaKey = getcwd().'/ca/company-ca.key';
        $caMode = $bundleManifest['caMode'] ?? null;

        if ($caMode !== null && file_exists($bundledCaCrt)) {
            $this->laraKubeInfo('Installing company CA certificate...');

            // Install into the OS trust store so curl, apt, and system tools trust
            // the company's internal services (private registries, LDAP, etc.).
            $this->runStreaming('cp '.escapeshellarg($bundledCaCrt).' /usr/local/share/ca-certificates/company-ca.crt');
            $this->runStreaming('update-ca-certificates');

            // Trust in containerd so k3s can pull from private registries signed by
            // the company CA without TLS errors.
            $registriesYaml = '/etc/rancher/k3s/registries.yaml';
            if (! file_exists($registriesYaml)) {
                @mkdir(dirname($registriesYaml), 0755, true);
                file_put_contents($registriesYaml, <<<'YAML'
                configs:
                  "*":
                    tls:
                      ca_file: /usr/local/share/ca-certificates/company-ca.crt
                YAML);
            }

            if ($caMode === 'full_sign') {
                $this->laraKubeInfo('Full-sign mode: server certificate will be signed by company CA.');
            } else {
                $this->laraKubeInfo('Trust-only mode: company CA installed; LaraKube will generate its own server certificate.');
            }
        }

        $this->laraKubeInfo('Generating TLS certificates...');
        $certDir = sys_get_temp_dir().'/larakube-certs-'.time();
        @mkdir($certDir, 0700, true);

        $certs = $this->generateSanCertificates(
            domains: array_values($hosts),
            outputDir: $certDir,
            companyCaCrt: ($caMode === 'full_sign' && file_exists($bundledCaCrt)) ? $bundledCaCrt : null,
            companyCaKey: ($caMode === 'full_sign' && file_exists($bundledCaKey)) ? $bundledCaKey : null,
        );

        $this->laraKubeInfo('Waiting for Kubernetes API to be ready...');
        for ($wait = 0; $wait < 60; $wait++) {
            if (Process::run('kubectl get nodes')->successful()) {
                break;
            }
            sleep(2);
        }

        // Ensure namespace exists before we create secrets
        Process::run("kubectl create namespace {$ns} --dry-run=client -o yaml | kubectl apply -f -");

        // Traefik expects the TLSStore in its own namespace for the default certificate
        Process::run('kubectl create namespace traefik --dry-run=client -o yaml | kubectl apply -f -');

        $tmpCertsYml = sys_get_temp_dir().'/traefik-certs.yml';
        file_put_contents($tmpCertsYml, view('traefik.dev-certs')->render());
        Process::run("kubectl create configmap traefik-config -n traefik --from-file=traefik-certs.yml={$tmpCertsYml} --dry-run=client -o yaml | kubectl apply -f -");

        $tmpTlsCrt = escapeshellarg($certs['tls_crt']);
        $tmpTlsKey = escapeshellarg($certs['tls_key']);
        Process::run("kubectl create secret generic traefik-certificates -n traefik --from-file=local-dev.pem={$tmpTlsCrt} --from-file=local-dev-key.pem={$tmpTlsKey} --dry-run=client -o yaml | kubectl apply -f -");

        @unlink($tmpCertsYml);

        // 7. Deploy Traefik
        $this->laraKubeInfo('Deploying Traefik Ingress Controller...');
        $tmpInstall = sys_get_temp_dir().'/traefik-install.yaml';
        file_put_contents($tmpInstall, view('k8s.traefik-install')->render());
        $this->runStreaming("kubectl apply -f {$tmpInstall}");
        @unlink($tmpInstall);

        if ($public !== '') {
            Process::run("kubectl create configmap laravel-config -n {$ns} {$public} --dry-run=client -o yaml | kubectl apply -f -");
        }
        if ($secret !== '') {
            Process::run("kubectl create secret generic laravel-secrets -n {$ns} {$secret} --dry-run=client -o yaml | kubectl apply -f -");
        }

        // 8. Apply manifests
        // Rewrite the ingress-patch hostname before applying — the bundle was built
        // with a placeholder host (e.g. airgap.local); the real domain is only known
        // at install time after the hostname prompt.
        $ingressPatchPath = getcwd()."/manifests/overlays/{$env}/ingress-patch.yaml";
        if (file_exists($ingressPatchPath) && isset($hosts['web'])) {
            $serverName = $config->getServerVariation()->getPodName($config);
            $webHost = $hosts['web'];
            file_put_contents($ingressPatchPath, <<<YAML
            apiVersion: networking.k8s.io/v1
            kind: Ingress
            metadata:
              name: {$serverName}
              annotations:
                traefik.ingress.kubernetes.io/router.tls: "true"
                traefik.ingress.kubernetes.io/service.serversscheme: http
            spec:
              rules:
                - host: {$webHost}
                  http:
                    paths:
                      - path: /
                        pathType: Prefix
                        backend:
                          service:
                            name: {$serverName}
                            port:
                              number: 80
              tls:
                - hosts:
                    - {$webHost}
            YAML);
        }

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

        // 9. Wait for rollout
        $this->laraKubeInfo('Waiting for rollout...');
        $this->runStreaming('kubectl rollout status deploy/web -n '.escapeshellarg($namespace).' --timeout=180s', 190);

        // 10. Activate Cloudflare Tunnel (if bundled and token was provided)
        if ($tunnelToken !== null && $tunnelToken !== '') {
            $this->laraKubeInfo('Installing cloudflared as a system service...');
            $this->runStreaming('cp cloudflared /usr/local/bin/cloudflared');
            $this->runStreaming('chmod +x /usr/local/bin/cloudflared');

            $serviceFile = "[Unit]\n"
                ."Description=Cloudflare Tunnel\n"
                ."After=network-online.target\n"
                ."Wants=network-online.target\n"
                ."\n"
                ."[Service]\n"
                ."Type=simple\n"
                ."User=root\n"
                .'ExecStart=/usr/local/bin/cloudflared tunnel --no-autoupdate run --token '.$tunnelToken."\n"
                ."Restart=on-failure\n"
                ."RestartSec=5s\n"
                ."\n"
                ."[Install]\n"
                ."WantedBy=multi-user.target\n";

            file_put_contents('/etc/systemd/system/cloudflared.service', $serviceFile);
            $this->runStreaming('chmod 600 /etc/systemd/system/cloudflared.service');
            $this->runStreaming('systemctl daemon-reload');
            $this->runStreaming('systemctl enable --now cloudflared');
            $this->laraKubeInfo('✅ Cloudflare Tunnel service enabled and started.');
        } elseif (($bundleManifest['tunnelEnabled'] ?? false)) {
            $this->laraKubeWarn('Cloudflare Tunnel skipped (no token provided). Run manually later:');
            $this->line('  cp cloudflared /usr/local/bin/cloudflared && chmod +x /usr/local/bin/cloudflared');
            $this->line('  cloudflared tunnel --no-autoupdate run --token YOUR_TOKEN');
        }

        if (file_exists('./reset.sh')) {
            $this->runStreaming('cp reset.sh /usr/local/bin/larakube-reset');
            $this->runStreaming('chmod +x /usr/local/bin/larakube-reset');
        }

        $this->newLine();
        $this->laraKubeInfo('✅ Bundle successfully installed!');
        if (isset($hosts['web'])) {
            $this->line("  <fg=gray>Your app should be available at:</> <fg=cyan>https://{$hosts['web']}</>");
        }
        if ($tunnelToken !== null && $tunnelToken !== '') {
            $this->line('  <fg=gray>Cloudflare Tunnel is active — configure the tunnel in your dashboard to route:</>');
            $this->line('  <fg=cyan>yourapp.yourdomain.com → http://localhost:80</>');
            $this->line('  <fg=gray>Cloudflare handles TLS. No port-forwarding or static IP needed.</>');
        }
        $this->newLine();

        if ($caMode === 'full_sign') {
            $this->line('  <fg=gray>Server certificate signed by your company CA.</>');
            $this->line('  <fg=gray>Browsers and devices that already trust your company CA will connect without warnings.</>');
        } else {
            // Expose the LaraKube-generated CA for easy download (trust-only and default).
            $niceCaName = "{$name}-{$env}-".date('Y-m-d').'-ca.crt';
            $niceCaPath = getcwd().'/'.$niceCaName;
            copy($certs['ca_crt'], $niceCaPath);

            $this->line('  <fg=gray>The CA certificate for this installation is at:</>');
            $this->line('  <fg=cyan>'.$niceCaPath.'</>');
            $this->newLine();
            $this->line('  <fg=gray>Pull it to your developer machine and trust it:</>');
            $this->line('  <fg=cyan>rsync -P root@YOUR_SERVER_IP:'.escapeshellarg($niceCaPath).' ~/Downloads/</>');
            $this->line('  <fg=cyan>larakube trust ~/Downloads/'.$niceCaName.'</>');
        }

        $this->newLine();
        $this->line('  <fg=gray>To wipe everything and start fresh, run:</> <fg=cyan>sudo larakube-reset</>');

        return 0;
    }
}
