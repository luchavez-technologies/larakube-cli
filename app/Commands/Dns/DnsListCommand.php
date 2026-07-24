<?php

namespace App\Commands\Dns;

use App\Enums\ClusterTool;
use App\Traits\DeploysClusterTool;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithClusterIdentity;
use App\Traits\InteractsWithDnsZones;
use App\Traits\LaraKubeOutput;
use App\Traits\ResolvesToolEnvironment;

use function Laravel\Prompts\table;

use LaravelZero\Framework\Commands\Command;

/**
 * Show which Cloudflare zones this cluster manages, and under which owner ID.
 *
 * The owner ID column is the point: it is what stops two clusters from
 * deleting each other's records, so being able to SEE it — and confirm two
 * clusters differ — is how you diagnose records flapping between them.
 */
class DnsListCommand extends Command
{
    use DeploysClusterTool, InteractsWithClusterContext, InteractsWithClusterIdentity,
        InteractsWithDnsZones, LaraKubeOutput, ResolvesToolEnvironment;

    protected $signature = 'dns:list
        {environment? : Environment whose zones to list}
        {--context=   : Target a specific kube-context}
        {--json       : Emit one machine-readable JSON array on stdout}';

    protected $description = 'List the Cloudflare zones this cluster manages with ExternalDNS';

    public function handle(): int
    {
        if (! $this->option('json')) {
            $this->renderHeader();
        }

        $env = $this->resolveToolEnvironment(ClusterTool::DNS);
        $context = $this->resolveToolContext($env, (string) $this->option('context') ?: null);
        $kubectl = $this->contextKubectl($context);

        $zones = $this->installedDnsZones($kubectl);

        if ($this->option('json')) {
            $this->line((string) json_encode($zones, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return 0;
        }

        if ($zones === []) {
            $this->laraKubeInfo('This cluster manages no Cloudflare zones.');
            $this->line('  <fg=gray>Add one with</> <fg=blue>larakube dns:init '.$env.' --zone=example.com</><fg=gray>.</>');

            return 0;
        }

        table(
            ['', 'Zone', 'Owner ID', 'Instance'],
            array_map(fn (array $z) => [
                $z['ready'] ? '<fg=green>●</>' : '<fg=yellow>◌</>',
                $z['zone'],
                $z['owner'] !== '' ? $z['owner'] : '<fg=red>unset</>',
                'external-dns-'.$z['slug'],
            ], $zones),
        );

        $this->newLine();
        $this->line('  <fg=gray>The Owner ID is how ExternalDNS marks records it owns. If another cluster</>');
        $this->line('  <fg=gray>manages the same zone, its Owner ID MUST differ — matching IDs make each</>');
        $this->line('  <fg=gray>cluster treat the other\'s records as orphans and delete them.</>');
        $this->newLine();

        return 0;
    }
}
