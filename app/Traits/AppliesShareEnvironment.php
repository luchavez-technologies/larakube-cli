<?php

namespace App\Traits;

use App\Enums\LaravelFeature;
use App\Enums\StorageDriver;
use App\Services\Kubectl;
use Illuminate\Support\Facades\Process;

/**
 * What every way of sharing a project does to the cluster: which services get a public address, the
 * deployment env that points the browser at those addresses, and taking it all down again.
 */
trait AppliesShareEnvironment
{
    /**
     * Build the map of pod-name → [targetUrl, urlKey] for the services we need to expose.
     * urlKey matches the key used in $urls ('web', 'hmr', 'reverb', 'storage', 'storage-console').
     */
    protected function buildServiceMap(mixed $config, string $appName, string $namespace): array
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

    /**
     * Patch the cluster deployments with public URLs so the running app generates
     * correct external links. All patches are deployment-level overrides — the
     * original ConfigMap values are untouched and restored when the share stops.
     */
    protected function applyEnvPatches(mixed $config, string $appName, string $namespace, array $urls): void
    {
        $ns = escapeshellarg($namespace);
        $restartNeeded = [];

        // Storage: update AWS_URL on the web deployment so Storage::url() generates public links
        if (isset($urls['storage'])) {
            $storageUrl = rtrim($urls['storage'], '/');
            Process::run(Kubectl::current()->prefix().' set env deployment/web AWS_URL='.escapeshellarg($storageUrl)." -n {$namespace}");
            $restartNeeded[] = 'web';
        }

        // HMR: inject VITE_HMR_HOST/PORT/PROTOCOL so the Vite server tells browsers
        // to connect via the public tunnel URL instead of the local .kube hostname
        if (isset($urls['hmr'])) {
            $hmrHost = preg_replace('#^https?://#', '', rtrim($urls['hmr'], '/'));
            Process::run(Kubectl::current()->prefix().' set env deployment/node VITE_HMR_HOST='.escapeshellarg($hmrHost)." VITE_HMR_CLIENT_PORT=443 VITE_HMR_PROTOCOL=wss -n {$namespace}");
            $restartNeeded[] = 'node';
        }

        // Reverb: inject VITE_REVERB_HOST/PORT/SCHEME so Laravel Echo in the
        // browser connects via the public tunnel URL instead of the internal
        // cluster host. The server-side REVERB_HOST/PORT (Laravel → Reverb,
        // pod-to-pod) stay untouched — only the browser-facing VITE_REVERB_*
        // vars need to change, same split as HMR's VITE_HMR_* above.
        if (isset($urls['reverb'])) {
            $reverbHost = preg_replace('#^https?://#', '', rtrim($urls['reverb'], '/'));
            Process::run(Kubectl::current()->prefix().' set env deployment/node VITE_REVERB_HOST='.escapeshellarg($reverbHost)." VITE_REVERB_PORT=443 VITE_REVERB_SCHEME=https -n {$namespace}");
            $restartNeeded[] = 'node';
        }

        if (! empty($restartNeeded)) {
            $targets = implode(' ', array_map(fn ($d) => "deployment/{$d}", array_unique($restartNeeded)));
            Process::run(Kubectl::current()->prefix()." rollout restart {$targets} -n {$namespace}");
            Process::timeout(70)->run(Kubectl::current()->prefix()." rollout status {$targets} -n {$namespace} --timeout=60s");
        }
    }

    protected function stopShare(string $namespace): void
    {
        $this->withSpin('Stopping tunnels and restoring env...', function () use ($namespace) {
            // Remove all share pods (label-selector covers both B and A pods)
            Process::run(Kubectl::current()->prefix()." delete deployment -l larakube.dev/role=share -n {$namespace} --ignore-not-found");
            Process::run(Kubectl::current()->prefix()." delete secret -l larakube.dev/role=share -n {$namespace} --ignore-not-found");

            // Remove deployment-level env overrides (no-op if they were never set)
            Process::run(Kubectl::current()->prefix()." set env deployment/web AWS_URL- -n {$namespace}");
            Process::run(Kubectl::current()->prefix()." set env deployment/node VITE_HMR_HOST- VITE_HMR_CLIENT_PORT- VITE_HMR_PROTOCOL- VITE_REVERB_HOST- VITE_REVERB_PORT- VITE_REVERB_SCHEME- -n {$namespace}");

            // Restart to pick up original ConfigMap values
            Process::run(Kubectl::current()->prefix()." rollout restart deployment/web -n {$namespace}");
            Process::run(Kubectl::current()->prefix()." rollout restart deployment/node -n {$namespace}");

            return true;
        });

        $this->laraKubeInfo('Tunnel stopped and env restored.');
    }

    protected function printShareUrls(array $urls, string $mode, bool $waiting = true): void
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

        if ($waiting) {
            $this->line('  Press <fg=yellow>Ctrl+C</> or run <fg=yellow>larakube share --stop</> to stop sharing.');
        }
    }

    /**
     * After `up` re-applies the manifests, put the public addresses of a running domain share back into
     * the deployments (the apply resets them) and say what they are. Does nothing when the project has
     * no domain share or its connector is not running.
     */
    protected function reapplyDomainShare(mixed $config, string $appName, string $namespace): bool
    {
        $saved = $this->getGlobalConfig()->getShareDomain($appName);

        if ($saved === null || empty($saved['urls'])) {
            return false;
        }

        $running = Process::run(Kubectl::current()->prefix().' get deployment larakube-share -n '.escapeshellarg($namespace).' -o name');

        if (! $running->successful() || trim($running->output()) === '') {
            return false;
        }

        $this->applyEnvPatches($config, $appName, $namespace, $saved['urls']);
        $this->printShareUrls($saved['urls'], 'named', waiting: false);

        return true;
    }
}
