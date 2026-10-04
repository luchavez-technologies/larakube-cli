<?php

namespace App\Commands;

use App\Exceptions\MissingFlagException;
use App\Services\Share\DomainShare;
use App\Traits\AppliesShareEnvironment;
use App\Traits\SharesUnderDomain;

use function Laravel\Prompts\select;

use LaravelZero\Framework\Commands\Command;
use RuntimeException;

class ShareCommand extends Command
{
    use AppliesShareEnvironment, SharesUnderDomain;

    protected $signature = 'share
        {--domain= : The Cloudflare domain to put the names under (list them with share:domains)}
        {--box= : The machine name used in the public names (default: this dev box\'s name, else the host name)}
        {--json : Emit one machine-readable JSON result on stdout}';

    protected $description = 'Make the project public under your own Cloudflare domain, with names that stay the same: app, Vite, Reverb and storage (reads CLOUDFLARE_API_TOKEN)';

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
        $targets = $this->shareTargets($config);

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

        $urls = array_map(fn (string $host): string => 'https://'.$host, $hosts);

        $globalConfig = $this->getGlobalConfig();
        $globalConfig->setShareDomain($appName, ['zone' => $zone['name'], 'zoneId' => $zone['id'], 'accountId' => $zone['accountId'], 'tunnelId' => $tunnel['id'], 'urls' => $urls]);
        $globalConfig->save();

        // The names become the project's hosts; up then builds .env, the Vite config and the ingress from them.
        $this->setPublicHosts($config, $hosts);

        if ($this->call('up', ['environment' => 'local', '--no-console' => true, '--no-test' => true, '--no-interaction' => true]) !== 0) {
            return $this->failed('The names are made, but up could not start the app with them. Fix the error above and run larakube share again.');
        }

        $this->deployConnector($namespace, $tunnel['token']);

        if ($this->flag('json')) {
            $this->jsonOutput(['success' => true, 'mode' => 'domain', 'urls' => $urls]);

            return 0;
        }

        $this->printShareUrls($urls);
        $this->line('  <fg=gray>These names stay the same, also after larakube up. Take them down with larakube share:remove.</>');

        return 0;
    }
}
