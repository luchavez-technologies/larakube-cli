<?php

namespace App\Traits;

use App\Data\ConfigData;
use App\Enums\LaravelFeature;
use App\Enums\StorageDriver;
use App\Services\Kubectl;
use Illuminate\Support\Facades\Process;
use Spatie\TemporaryDirectory\TemporaryDirectory;

/**
 * What `share` and `share:remove` do to the project and its cluster: which services get a public name,
 * the names as the project's own hosts, and the tunnel connector that carries them.
 */
trait AppliesShareEnvironment
{
    /** Share's service key → the host key the project's config uses for it. */
    private const array HOST_KEYS = [
        'web' => 'web',
        'hmr' => 'vite',
        'reverb' => 'reverb',
        'storage' => 's3',
        'storage-console' => 's3-console',
    ];

    /**
     * The services the browser talks to and where each lives inside the cluster.
     *
     * @return array<string, string> [share service key => in-cluster URL]
     */
    protected function shareTargets(ConfigData $config): array
    {
        $targets = ['web' => 'http://web:80'];

        if ($config->getFrontend()?->requiresNodePod()) {
            $targets['hmr'] = 'http://node:5173';
        }

        if ($config->hasFeature(LaravelFeature::REVERB, 'local')) {
            $targets['reverb'] = 'http://reverb:8080';
        }

        $storage = $config->getObjectStorage();
        if ($storage instanceof StorageDriver) {
            $targets['storage'] = "http://{$storage->getPodName()}:{$storage->port()}";
            $targets['storage-console'] = "http://{$storage->getPodName()}:{$storage->consolePort()}";
        }

        return $targets;
    }

    /**
     * The public names the project is actually using: its local environment's public hosts, as share's
     * service keys with full URLs. Empty when the project is private.
     *
     * @return array<string, string>
     */
    protected function publicUrls(ConfigData $config): array
    {
        $byHostKey = array_flip(self::HOST_KEYS);
        $urls = [];

        foreach ($config->getEnvironment('local')?->publicHosts ?? [] as $hostKey => $host) {
            $urls[$byHostKey[$hostKey] ?? $hostKey] = 'https://'.$host;
        }

        return $urls;
    }

    /**
     * Make these names the local environment's hosts, or none to go back to the local names. Saved to the
     * gitignored local file, so the next `up` builds `.env`, the Vite config and the ingress from them.
     *
     * @param  array<string, string>  $hostsByShareKey  [share service key => public host]
     */
    protected function setPublicHosts(ConfigData $config, array $hostsByShareKey): void
    {
        $names = [];
        foreach ($hostsByShareKey as $service => $host) {
            $names[self::HOST_KEYS[$service] ?? $service] = $host;
        }

        $config->addEnvironment('local');
        $env = $config->getEnvironment('local');
        $env->hosts = array_merge(array_diff_key($env->hosts, $env->publicHosts), $names);
        $env->publicHosts = $names;

        $config->saveToFile((string) getcwd());
    }

    /** The connector pod: it reads its token from a Secret, never from its arguments. */
    protected function deployConnector(string $namespace, string $tunnelToken): void
    {
        $this->withSpin('Deploying the Cloudflare tunnel connector...', function () use ($namespace, $tunnelToken): bool {
            $manifest = view('k8s.cloudflared.deployment', [
                'name' => 'larakube-share',
                'namespace' => $namespace,
                'token' => $tunnelToken,
            ])->render();

            $temporaryDirectory = TemporaryDirectory::make();
            $file = $temporaryDirectory->path().'/larakube-share.yaml';
            file_put_contents($file, $manifest);
            Process::run(Kubectl::current()->prefix().' apply -f '.escapeshellarg($file));
            $temporaryDirectory->delete();

            return true;
        });
    }

    protected function removeConnector(string $namespace): void
    {
        $this->withSpin('Stopping the tunnel connector...', function () use ($namespace): bool {
            Process::run(Kubectl::current()->prefix()." delete deployment,secret -l larakube.dev/role=share -n {$namespace} --ignore-not-found");

            return true;
        });
    }

    /** @param  array<string, string>  $urls */
    protected function printShareUrls(array $urls): void
    {
        $labels = ['web' => 'Web app', 'hmr' => 'Vite HMR', 'reverb' => 'Reverb', 'storage' => 'Storage', 'storage-console' => 'S3 Console'];

        $this->laraKubeNewLine();
        $this->laraKubeInfo('🌐 Your project is public, with names that stay the same');
        $this->laraKubeNewLine();

        foreach ($urls as $service => $url) {
            $this->line('  <fg=gray>'.str_pad(($labels[$service] ?? $service), 10).':</> <fg=cyan'.($service === 'web' ? ';options=bold' : '').'>'.$url.'</>');
        }

        $this->laraKubeNewLine();
    }
}
