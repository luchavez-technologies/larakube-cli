<?php

namespace App\Commands;

use App\Data\GlobalConfigData;
use App\Enums\LaravelFeature;
use App\Enums\StorageDriver;
use App\Traits\InteractsWithEnvironments;
use App\Traits\InteractsWithGlobalConfig;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;

class ShareCommand extends Command
{
    use InteractsWithEnvironments, InteractsWithGlobalConfig, InteractsWithProjectConfig, LaraKubeOutput;

    protected $signature = 'share
        {--stop : Stop all share tunnels and restore env overrides}
        {--token= : Cloudflare named-tunnel token (saves to global config for reuse)}
        {--reset : Forget saved share URLs and re-configure}';

    protected $description = 'Expose your local LaraKube project to the internet via Cloudflare Tunnel';

    public function handle(): int
    {
        $this->renderHeader();

        if (! $this->ensureIsProject()) {
            return 1;
        }

        $projectPath = getcwd();
        $config = $this->getProjectConfig($projectPath);
        if (! $config) {
            return 1;
        }

        $appName = $config->getName() ?? basename($projectPath);
        $namespace = $this->getNamespace('local', $appName);

        if ($this->option('stop')) {
            $this->stopShare($namespace);

            return 0;
        }

        $token = $this->resolveToken();

        if ($token !== null) {
            return $this->runNamedTunnel($config, $appName, $namespace, $token);
        }

        return $this->runQuickTunnels($config, $appName, $namespace);
    }

    // ─── Named tunnel (B path — token available) ──────────────────────────────

    private function runNamedTunnel(mixed $config, string $appName, string $namespace, string $token): int
    {
        $this->laraKubeInfo('Named Cloudflare Tunnel detected — using stable public URLs.');

        $globalConfig = $this->getGlobalConfig();

        if ($this->option('reset')) {
            $globalConfig->setShareUrls($appName, ['web' => null, 'hmr' => null, 'reverb' => null, 'storage' => null, 'storage-console' => null]);
            $globalConfig->save();
        }

        $urls = $this->resolveNamedTunnelUrls($config, $appName, $globalConfig);

        // Deploy single connector pod
        $this->withSpin('Deploying Cloudflare tunnel connector...', function () use ($namespace, $token) {
            $manifest = view('k8s.cloudflared.deployment', [
                'name' => 'larakube-share',
                'namespace' => $namespace,
                'token' => $token,
                'targetUrl' => null,
            ])->render();

            $tmp = sys_get_temp_dir().'/larakube-share.yaml';
            file_put_contents($tmp, $manifest);
            Process::run('kubectl apply -f '.escapeshellarg($tmp));
            @unlink($tmp);

            return true;
        });

        $this->applyEnvPatches($config, $appName, $namespace, $urls);
        $this->printShareUrls($urls, 'named');
        $this->keepAlive($namespace);

        return 0;
    }

    private function resolveNamedTunnelUrls(mixed $config, string $appName, GlobalConfigData $globalConfig): array
    {
        $stored = $globalConfig->getShareUrls($appName);
        $urls = [];

        // Web URL (always required)
        $urls['web'] = $stored['web'] ?? (string) text(
            label: 'Public URL for the web app (from your Cloudflare tunnel config)',
            placeholder: 'https://myapp.example.com',
            required: true,
            validate: fn ($v) => str_starts_with(trim($v), 'http') ? null : 'Must be a full URL (https://…)',
        );

        // HMR URL (only if project has a frontend)
        if ($config->getFrontend()?->requiresNodePod()) {
            $urls['hmr'] = $stored['hmr'] ?? (string) text(
                label: 'Public URL for Vite HMR (hostname that routes to the node service on port 5173)',
                placeholder: 'https://hmr.myapp.example.com',
                hint: 'Leave blank to skip — HMR will only work on your local browser',
            );
        }

        // Reverb URL (only if project uses Laravel Reverb for broadcasting)
        if ($config->hasFeature(LaravelFeature::REVERB, 'local')) {
            $urls['reverb'] = $stored['reverb'] ?? (string) text(
                label: 'Public URL for Reverb WebSocket (hostname that routes to the reverb service on port 8080)',
                placeholder: 'https://reverb.myapp.example.com',
                hint: 'Leave blank to skip — real-time broadcasting will only work on your local browser',
            );
        }

        // Storage URL (only if project has object storage)
        if ($config->getObjectStorage() !== null) {
            $urls['storage'] = $stored['storage'] ?? (string) text(
                label: 'Public URL for object storage (routes to the S3 API port)',
                placeholder: 'https://s3.myapp.example.com',
                hint: 'Leave blank to skip — stored file URLs will only resolve locally',
            );

            $urls['storage-console'] = $stored['storage-console'] ?? (string) text(
                label: 'Public URL for the storage admin console (routes to the console/UI port)',
                placeholder: 'https://s3-console.myapp.example.com',
                hint: 'Leave blank to skip — the admin console will only be reachable locally',
            );
        }

        $urls = array_filter($urls);

        // Persist so next run skips prompting
        $globalConfig->setShareUrls($appName, $urls);
        $globalConfig->save();

        return $urls;
    }

    // ─── Quick tunnels (A path — no token, one pod per service) ───────────────

    private function runQuickTunnels(mixed $config, string $appName, string $namespace): int
    {
        $this->laraKubeInfo('No Cloudflare token found — using quick tunnels (random URLs).');
        $this->line('  <fg=gray>Tip: set CLOUDFLARE_TUNNEL_TOKEN or run with --token for a stable named tunnel.</>');
        $this->laraKubeNewLine();

        $services = $this->buildServiceMap($config, $appName, $namespace);

        // Deploy all pods in one pass
        $this->withSpin('Deploying tunnel pods...', function () use ($services, $namespace) {
            foreach ($services as $name => ['targetUrl' => $targetUrl]) {
                $manifest = view('k8s.cloudflared.deployment', [
                    'name' => $name,
                    'namespace' => $namespace,
                    'token' => null,
                    'targetUrl' => $targetUrl,
                ])->render();

                $tmp = sys_get_temp_dir()."/larakube-share-{$name}.yaml";
                file_put_contents($tmp, $manifest);
                Process::run('kubectl apply -f '.escapeshellarg($tmp));
                @unlink($tmp);
            }

            return true;
        });

        $this->withSpin('Waiting for tunnel pods to be ready...', function () use ($namespace) {
            Process::timeout(100)->run("kubectl wait --for=condition=ready pod -l larakube.dev/role=share -n {$namespace} --timeout=90s");

            return true;
        });

        $urls = $this->extractQuickTunnelUrls($services, $namespace);

        if (empty($urls)) {
            $this->laraKubeError('Could not retrieve tunnel URLs. Check pod logs: larakube logs larakube-share-web');

            return 1;
        }

        $this->applyEnvPatches($config, $appName, $namespace, $urls);
        $this->printShareUrls($urls, 'quick');
        $this->keepAlive($namespace);

        return 0;
    }

    /**
     * Build the map of pod-name → [targetUrl, urlKey] for the services we need to expose.
     * urlKey matches the key used in $urls ('web', 'hmr', 'reverb', 'storage', 'storage-console').
     */
    private function buildServiceMap(mixed $config, string $appName, string $namespace): array
    {
        $map = [
            'larakube-share-web' => ['targetUrl' => 'http://web:80', 'urlKey' => 'web'],
        ];

        if ($config->getFrontend()?->requiresNodePod()) {
            $map['larakube-share-hmr'] = ['targetUrl' => 'http://node:5173', 'urlKey' => 'hmr'];
        }

        if ($config->hasFeature(LaravelFeature::REVERB, 'local')) {
            $map['larakube-share-reverb'] = ['targetUrl' => 'http://reverb:8080', 'urlKey' => 'reverb'];
        }

        $storage = $config->getObjectStorage();
        if ($storage instanceof StorageDriver) {
            $map['larakube-share-storage'] = [
                'targetUrl' => "http://{$storage->getPodName()}:{$storage->port()}",
                'urlKey' => 'storage',
            ];
            $map['larakube-share-storage-console'] = [
                'targetUrl' => "http://{$storage->getPodName()}:{$storage->consolePort()}",
                'urlKey' => 'storage-console',
            ];
        }

        return $map;
    }

    private function extractQuickTunnelUrls(array $services, string $namespace): array
    {
        $urls = [];
        $pattern = '/(https:\/\/[a-z0-9-]+\.trycloudflare\.com)/';
        $maxAttempts = 15;

        foreach ($services as $podName => ['urlKey' => $urlKey]) {
            $found = null;

            for ($i = 0; $i < $maxAttempts && $found === null; $i++) {
                $result = Process::run('kubectl logs -l app='.escapeshellarg($podName)." -n {$namespace} --tail=30");
                $logs = $result->output().$result->errorOutput();

                if (preg_match($pattern, $logs, $m)) {
                    $found = $m[1];
                } else {
                    sleep(2);
                }
            }

            if ($found !== null) {
                $urls[$urlKey] = $found;
            }
        }

        return $urls;
    }

    // ─── Env patches (shared between B and A paths) ────────────────────────────

    /**
     * Patch the cluster deployments with public URLs so the running app generates
     * correct external links. All patches are deployment-level overrides — the
     * original ConfigMap values are untouched and restored when the share stops.
     */
    private function applyEnvPatches(mixed $config, string $appName, string $namespace, array $urls): void
    {
        $ns = escapeshellarg($namespace);
        $restartNeeded = [];

        // Storage: update AWS_URL on the web deployment so Storage::url() generates public links
        if (isset($urls['storage'])) {
            $storageUrl = rtrim($urls['storage'], '/');
            Process::run('kubectl set env deployment/web AWS_URL='.escapeshellarg($storageUrl)." -n {$namespace}");
            $restartNeeded[] = 'web';
        }

        // HMR: inject VITE_HMR_HOST/PORT/PROTOCOL so the Vite server tells browsers
        // to connect via the public tunnel URL instead of the local .kube hostname
        if (isset($urls['hmr'])) {
            $hmrHost = preg_replace('#^https?://#', '', rtrim($urls['hmr'], '/'));
            Process::run('kubectl set env deployment/node VITE_HMR_HOST='.escapeshellarg($hmrHost)." VITE_HMR_CLIENT_PORT=443 VITE_HMR_PROTOCOL=wss -n {$namespace}");
            $restartNeeded[] = 'node';
        }

        // Reverb: inject VITE_REVERB_HOST/PORT/SCHEME so Laravel Echo in the
        // browser connects via the public tunnel URL instead of the internal
        // cluster host. The server-side REVERB_HOST/PORT (Laravel → Reverb,
        // pod-to-pod) stay untouched — only the browser-facing VITE_REVERB_*
        // vars need to change, same split as HMR's VITE_HMR_* above.
        if (isset($urls['reverb'])) {
            $reverbHost = preg_replace('#^https?://#', '', rtrim($urls['reverb'], '/'));
            Process::run('kubectl set env deployment/node VITE_REVERB_HOST='.escapeshellarg($reverbHost)." VITE_REVERB_PORT=443 VITE_REVERB_SCHEME=https -n {$namespace}");
            $restartNeeded[] = 'node';
        }

        if (! empty($restartNeeded)) {
            $targets = implode(' ', array_map(fn ($d) => "deployment/{$d}", array_unique($restartNeeded)));
            Process::run("kubectl rollout restart {$targets} -n {$namespace}");
            Process::timeout(70)->run("kubectl rollout status {$targets} -n {$namespace} --timeout=60s");
        }
    }

    // ─── Output ────────────────────────────────────────────────────────────────

    private function printShareUrls(array $urls, string $mode): void
    {
        $modeLabel = $mode === 'named' ? '(named tunnel — stable)' : '(quick tunnel — random URL)';
        $this->laraKubeNewLine();
        $this->laraKubeInfo("🌐 Your project is now public {$modeLabel}");
        $this->laraKubeNewLine();

        if (isset($urls['web'])) {
            $this->line('  <fg=gray>Web app  :</> <fg=cyan;options=bold>'.$urls['web'].'</>');
        }
        if (isset($urls['hmr'])) {
            $this->line('  <fg=gray>Vite HMR :</> <fg=cyan>'.$urls['hmr'].'</>');
        }
        if (isset($urls['reverb'])) {
            $this->line('  <fg=gray>Reverb   :</> <fg=cyan>'.$urls['reverb'].'</>');
        }
        if (isset($urls['storage'])) {
            $this->line('  <fg=gray>Storage  :</> <fg=cyan>'.$urls['storage'].'</>');
        }
        if (isset($urls['storage-console'])) {
            $this->line('  <fg=gray>S3 Console:</> <fg=cyan>'.$urls['storage-console'].'</>');
        }

        $this->laraKubeNewLine();
        $this->line('  Press <fg=yellow>Ctrl+C</> or run <fg=yellow>larakube share --stop</> to stop sharing.');
    }

    // ─── Keep-alive and stop ───────────────────────────────────────────────────

    private function keepAlive(string $namespace): void
    {
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGINT, function () use ($namespace) {
                $this->laraKubeNewLine();
                $this->stopShare($namespace);
                exit(0);
            });

            while (true) {
                sleep(1);
            }
        } else {
            $this->confirm('Sharing… press Enter to stop', true);
            $this->stopShare($namespace);
        }
    }

    private function stopShare(string $namespace): void
    {
        $this->withSpin('Stopping tunnels and restoring env...', function () use ($namespace) {
            // Remove all share pods (label-selector covers both B and A pods)
            Process::run("kubectl delete deployment -l larakube.dev/role=share -n {$namespace} --ignore-not-found");

            // Remove deployment-level env overrides (no-op if they were never set)
            Process::run("kubectl set env deployment/web AWS_URL- -n {$namespace}");
            Process::run("kubectl set env deployment/node VITE_HMR_HOST- VITE_HMR_CLIENT_PORT- VITE_HMR_PROTOCOL- VITE_REVERB_HOST- VITE_REVERB_PORT- VITE_REVERB_SCHEME- -n {$namespace}");

            // Restart to pick up original ConfigMap values
            Process::run("kubectl rollout restart deployment/web -n {$namespace}");
            Process::run("kubectl rollout restart deployment/node -n {$namespace}");

            return true;
        });

        $this->laraKubeInfo('Tunnel stopped and env restored.');
    }

    // ─── Token resolution ──────────────────────────────────────────────────────

    private function resolveToken(): ?string
    {
        // CLI flag always wins
        if ($this->option('token')) {
            $token = (string) $this->option('token');
            $this->persistToken($token);

            return $token;
        }

        // Shell env var (CI-friendly, no storage needed)
        $envToken = getenv('CLOUDFLARE_TUNNEL_TOKEN');
        if ($envToken !== false && $envToken !== '') {
            return $envToken;
        }

        // Saved in global config from a previous run
        $saved = $this->getGlobalConfig()->getShareToken();
        if ($saved !== null && $saved !== '') {
            return $saved;
        }

        return null;
    }

    private function persistToken(string $token): void
    {
        $globalConfig = $this->getGlobalConfig();
        $globalConfig->setShareToken($token);
        $globalConfig->save();
    }
}
