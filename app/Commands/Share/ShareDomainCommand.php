<?php

namespace App\Commands\Share;

use App\Exceptions\MissingFlagException;
use App\Services\Kubectl;
use App\Services\Share\DomainShare;
use App\Traits\AppliesShareEnvironment;
use App\Traits\SharesUnderDomain;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\select;

use LaravelZero\Framework\Commands\Command;
use RuntimeException;
use Spatie\TemporaryDirectory\TemporaryDirectory;

class ShareDomainCommand extends Command
{
    use AppliesShareEnvironment, SharesUnderDomain;

    protected $signature = 'share:domain
        {--domain= : The Cloudflare domain to put the names under (list them with share:domains)}
        {--box= : The machine name used in the public names (default: this dev box\'s name, else the host name)}
        {--json : Emit one machine-readable JSON result on stdout}';

    protected $description = 'Give the project stable public names under your own Cloudflare domain: app, Vite, Reverb and storage (reads CLOUDFLARE_API_TOKEN)';

    public function handle(): int
    {
        if ($this->flag('json')) {
            $this->enableJsonMode();
        }

        $this->renderHeader();

        $project = $this->domainProject();

        if ($project === null) {
            return 1;
        }

        [$config, $appName, $namespace] = $project;

        $token = $this->domainToken();

        if ($token === null) {
            return $this->failed('Set CLOUDFLARE_API_TOKEN to a Cloudflare API token with Account → Cloudflare Tunnel → Edit, Zone → DNS → Edit and Zone → Zone → Read.');
        }

        $share = new DomainShare($token);
        $zones = $this->domainZones($share);

        if ($zones === null) {
            return 1;
        }

        $saved = $this->getGlobalConfig()->getShareDomain($appName);
        $names = array_column($zones, 'name');

        try {
            $domain = $this->flagOrPrompt(
                'domain',
                fn () => select('Which domain should the project be shared under?', $names, default: in_array($saved['zone'] ?? null, $names, true) ? $saved['zone'] : null),
                'the Cloudflare domain to share under',
                '--domain='.$names[0],
            );
        } catch (MissingFlagException $e) {
            return $this->failed($e->getMessage());
        }

        $zone = collect($zones)->firstWhere('name', $domain);

        if ($zone === null) {
            return $this->failed("The token cannot see '{$domain}'. It can see: ".implode(', ', $names).'.');
        }

        $box = $this->domainBoxName();
        $targets = [];
        foreach ($this->buildServiceMap($config, $appName, $namespace) as $service) {
            $targets[$service['urlKey']] = $service['targetUrl'];
        }

        try {
            $hosts = $share->hosts($appName, $box, $zone['name'], array_keys($targets));
            $tunnel = null;

            $this->withSpin('Setting up the Cloudflare tunnel, routes and DNS...', function () use ($share, $zone, $appName, $box, $hosts, $targets, &$tunnel): bool {
                $tunnel = $share->ensureTunnel($zone['accountId'], 'larakube-'.$appName.'-'.$box);
                $share->route($zone['accountId'], $tunnel['id'], $hosts, $targets);
                $share->point($zone['id'], $tunnel['id'], $hosts);

                return true;
            });
        } catch (RuntimeException $e) {
            return $this->failed($e->getMessage());
        }

        $this->deployConnector($namespace, $tunnel['token']);

        $urls = array_map(fn (string $host): string => 'https://'.$host, $hosts);

        $this->applyEnvPatches($config, $appName, $namespace, $urls);

        $globalConfig = $this->getGlobalConfig();
        $globalConfig->setShareDomain($appName, ['zone' => $zone['name'], 'zoneId' => $zone['id'], 'accountId' => $zone['accountId'], 'tunnelId' => $tunnel['id'], 'urls' => $urls]);
        $globalConfig->save();

        if ($this->flag('json')) {
            $this->jsonOutput(['success' => true, 'mode' => 'domain', 'urls' => $urls]);

            return 0;
        }

        $this->printShareUrls($urls, 'named', waiting: false);
        $this->line('  <fg=gray>These names stay the same. Stop the link with larakube share --stop; remove it for good with larakube share:domain-remove.</>');

        return 0;
    }

    /** The cluster-side half: the connector pod, which reads its token from a Secret and not from its arguments. */
    private function deployConnector(string $namespace, string $tunnelToken): void
    {
        $this->withSpin('Deploying Cloudflare tunnel connector...', function () use ($namespace, $tunnelToken): bool {
            $manifest = view('k8s.cloudflared.deployment', [
                'name' => 'larakube-share',
                'namespace' => $namespace,
                'token' => $tunnelToken,
                'targetUrl' => null,
            ])->render();

            $temporaryDirectory = TemporaryDirectory::make();
            $file = $temporaryDirectory->path().'/larakube-share.yaml';
            file_put_contents($file, $manifest);
            Process::run(Kubectl::current()->prefix().' apply -f '.escapeshellarg($file));
            $temporaryDirectory->delete();

            return true;
        });
    }
}
