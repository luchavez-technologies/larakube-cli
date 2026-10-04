<?php

namespace App\Commands;

use App\Data\GlobalConfigData;
use App\Enums\LaravelFeature;
use App\Services\Kubectl;
use App\Traits\AppliesShareEnvironment;
use App\Traits\EmitsJsonOutput;
use App\Traits\InteractsWithEnvironments;
use App\Traits\InteractsWithGlobalConfig;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use App\Traits\ReadsCommandOptions;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;

use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;
use Spatie\TemporaryDirectory\TemporaryDirectory;

class ShareCommand extends Command
{
    use AppliesShareEnvironment, EmitsJsonOutput, InteractsWithEnvironments, InteractsWithGlobalConfig, InteractsWithProjectConfig, LaraKubeOutput, ReadsCommandOptions;

    protected $signature = 'share
        {--stop : Stop all share tunnels and restore env overrides}
        {--token= : Cloudflare named-tunnel token (saves to global config for reuse)}
        {--reset : Forget saved share URLs and re-configure}
        {--detach : Print the links and leave the tunnels running, instead of waiting for Ctrl+C. Stop them with --stop}
        {--json : Emit one machine-readable JSON result on stdout (implies --detach)}';

    protected $description = 'Expose your local LaraKube project to the internet via Cloudflare Tunnel';

    public function handle(): int
    {
        if ($this->flag('json')) {
            $this->enableJsonMode();
        }

        $this->renderHeader();

        if (! $this->isLaraKubeProject()) {
            return $this->failed('This folder is not a LaraKube project.');
        }

        $projectPath = getcwd();
        $config = $this->getProjectConfig($projectPath);
        if (! $config) {
            return $this->failed('The project blueprint could not be read.');
        }

        $appName = $config->getName() ?? basename($projectPath);
        $namespace = $this->getNamespace('local', $appName);

        if ($this->option('stop')) {
            $this->stopShare($namespace);

            if ($this->getGlobalConfig()->getShareDomain($appName) !== null && ! $this->flag('json')) {
                $this->line('  <fg=gray>The names from share:domain stay reserved; larakube share:domain brings them back, share:domain-remove deletes them.</>');
            }

            if ($this->flag('json')) {
                $this->jsonOutput(['success' => true, 'stopped' => true]);
            }

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

        // Asking for the public URLs needs a person; headlessly they must have been saved by an earlier run.
        if ($this->flag('json') && ! isset($globalConfig->getShareUrls($appName)['web'])) {
            return $this->failed('A named tunnel needs its public web URL saved first: run `larakube share` once in the project, in a terminal.');
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

            $temporaryDirectory = TemporaryDirectory::make();
            $tmp = $temporaryDirectory->path('larakube-share.yaml');
            file_put_contents($tmp, $manifest);
            Process::run(Kubectl::current()->prefix().' apply -f '.escapeshellarg($tmp));
            $temporaryDirectory->delete();

            return true;
        });

        $this->applyEnvPatches($config, $appName, $namespace, $urls);
        $this->printShareUrls($urls, 'named');

        return $this->finish('named', $urls, $namespace);

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

                $temporaryDirectory = TemporaryDirectory::make();
                $tmp = $temporaryDirectory->path("larakube-share-{$name}.yaml");
                file_put_contents($tmp, $manifest);
                Process::run(Kubectl::current()->prefix().' apply -f '.escapeshellarg($tmp));
                $temporaryDirectory->delete();
            }

            return true;
        });

        $this->withSpin('Waiting for tunnel pods to be ready...', function () use ($namespace) {
            Process::timeout(100)->run(Kubectl::current()->prefix()." wait --for=condition=ready pod -l larakube.dev/role=share -n {$namespace} --timeout=90s");

            return true;
        });

        $urls = $this->extractQuickTunnelUrls($services, $namespace);

        if (empty($urls)) {
            return $this->failed('Could not retrieve tunnel URLs. Check pod logs: larakube logs larakube-share-web');
        }

        $this->applyEnvPatches($config, $appName, $namespace, $urls);
        $this->printShareUrls($urls, 'quick');

        return $this->finish('quick', $urls, $namespace);
    }

    private function extractQuickTunnelUrls(array $services, string $namespace): array
    {
        $urls = [];
        $pattern = '/(https:\/\/[a-z0-9-]+\.trycloudflare\.com)/';
        $maxAttempts = 15;

        foreach ($services as $podName => ['urlKey' => $urlKey]) {
            $found = null;

            for ($i = 0; $i < $maxAttempts && $found === null; $i++) {
                $result = Process::run(Kubectl::current()->prefix().' logs -l app='.escapeshellarg($podName)." -n {$namespace} --tail=30");
                $logs = $result->output().$result->errorOutput();

                if (preg_match($pattern, $logs, $m)) {
                    $found = $m[1];
                } else {
                    Sleep::sleep(2);
                }
            }

            if ($found !== null) {
                $urls[$urlKey] = $found;
            }
        }

        return $urls;
    }

    // ─── Keep-alive and stop ───────────────────────────────────────────────────

    /**
     * What happens once the links are printed: report them and leave (--detach, --json), or wait here
     * until Ctrl+C so the tunnels can be taken down again.
     *
     * @param  array<string, string>  $urls
     */
    private function finish(string $mode, array $urls, string $namespace): int
    {
        if ($this->flag('json')) {
            $this->jsonOutput(['success' => true, 'mode' => $mode, 'urls' => $urls]);

            return 0;
        }

        if ($this->flag('detach')) {
            return 0;
        }

        $this->keepAlive($namespace);

        return 0;
    }

    private function failed(string $message): int
    {
        $this->laraKubeError($message);

        if ($this->flag('json')) {
            $this->jsonOutput(['success' => false, 'error' => $message]);
        }

        return 1;
    }

    private function keepAlive(string $namespace): void
    {
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGINT, function () use ($namespace): void {
                $this->laraKubeNewLine();
                $this->stopShare($namespace);
                exit(0);
            });

            while (true) {
                Sleep::sleep(1);
            }
        } else {
            $this->confirm('Sharing… press Enter to stop', true);
            $this->stopShare($namespace);
        }
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
