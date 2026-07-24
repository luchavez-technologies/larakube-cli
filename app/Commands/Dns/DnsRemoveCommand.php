<?php

namespace App\Commands\Dns;

use App\Enums\ClusterTool;
use App\Exceptions\MissingFlagException;
use App\Traits\ConfirmsDestructiveAction;
use App\Traits\DeploysClusterTool;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithClusterIdentity;
use App\Traits\InteractsWithDnsZones;
use App\Traits\LaraKubeOutput;
use App\Traits\RequiresFlagsWhenNonInteractive;
use App\Traits\ResolvesToolEnvironment;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\select;

use LaravelZero\Framework\Commands\Command;

/**
 * Remove one zone's ExternalDNS instance, or all of them.
 *
 * Per-zone rather than all-or-nothing, because a cluster now runs one instance
 * per zone and tearing down the wrong one silently stops DNS reconciliation for
 * a domain that is still live.
 *
 * Note what this deliberately does NOT do: delete the DNS records themselves.
 * ExternalDNS under `--policy=sync` deletes records when their ingress goes
 * away — but removing the controller just stops reconciliation, leaving the
 * existing records in place and resolvable. Deleting the controller AND
 * expecting the records to vanish is the mistake worth warning about.
 */
class DnsRemoveCommand extends Command
{
    use ConfirmsDestructiveAction, DeploysClusterTool, InteractsWithClusterContext,
        InteractsWithClusterIdentity, InteractsWithDnsZones, LaraKubeOutput,
        RequiresFlagsWhenNonInteractive, ResolvesToolEnvironment;

    protected $signature = 'dns:remove
        {environment? : Environment whose ExternalDNS to remove}
        {--zone=      : The zone to stop managing, e.g. example.com}
        {--all        : Remove every zone instance on this cluster}
        {--context=   : Target a specific kube-context}
        {--force      : Skip the confirmation prompt}';

    protected $description = 'Stop managing a Cloudflare zone with ExternalDNS';

    public function handle(): int
    {
        $this->renderHeader();

        $env = $this->resolveToolEnvironment(ClusterTool::DNS);
        $context = $this->resolveToolContext($env, (string) $this->option('context') ?: null);
        $kubectl = $this->contextKubectl($context);
        $ns = 'larakube-shared';

        $installed = $this->installedDnsZones($kubectl, $ns);

        if ($installed === []) {
            $this->laraKubeInfo('No ExternalDNS instances are running on this cluster.');

            return 0;
        }

        $targets = $this->resolveTargets($installed);
        if ($targets === []) {
            return 1;
        }

        if (! $this->confirmDestructive([
            'These ExternalDNS instances will be REMOVED: '.implode(', ', array_column($targets, 'zone')),
            'DNS reconciliation stops for those zones.',
            'Existing DNS records are NOT deleted — they stay live and unmanaged.',
        ])) {
            return 0;
        }

        $ok = true;

        foreach ($targets as $target) {
            $slug = $target['slug'];

            $ok = $this->removeResources(
                "Removing ExternalDNS for {$target['zone']}...",
                "{$kubectl} delete deployment/external-dns-{$slug} "
                ."serviceaccount/external-dns-{$slug} secret/cloudflare-token-{$slug} "
                ."-n {$ns} --ignore-not-found",
            ) && $ok;

            // Cluster-scoped — not garbage-collected with the namespace objects.
            Process::run("{$kubectl} delete clusterrolebinding/external-dns-{$slug} --ignore-not-found");
        }

        // The ClusterRole is shared by every zone instance, so it only goes when
        // the last one does.
        if ($this->installedDnsZones($kubectl, $ns) === []) {
            Process::run("{$kubectl} delete clusterrole/external-dns --ignore-not-found");
        }

        if (! $ok) {
            $this->laraKubeError('One or more ExternalDNS resources failed to remove.');

            return 1;
        }

        $this->laraKubeInfo('ExternalDNS removed for: '.implode(', ', array_column($targets, 'zone')));
        $this->newLine();
        $this->line('  <fg=gray>The DNS records it created still exist in Cloudflare. Delete them there</>');
        $this->line('  <fg=gray>if you want them gone, or re-run</> <fg=blue>dns:init</> <fg=gray>to resume management.</>');
        $this->newLine();

        return 0;
    }

    /**
     * @param  list<array{zone: string, slug: string}>  $installed
     * @return list<array{zone: string, slug: string}>
     */
    protected function resolveTargets(array $installed): array
    {
        if ($this->option('all')) {
            return $installed;
        }

        $zone = (string) ($this->option('zone') ?? '');

        if ($zone !== '') {
            foreach ($installed as $entry) {
                if ($entry['zone'] === $zone || $entry['slug'] === $this->zoneSlug($zone)) {
                    return [$entry];
                }
            }

            $this->laraKubeError("'{$zone}' is not managed by this cluster.");
            $this->line('  <fg=gray>Managed zones: </>'.implode(', ', array_column($installed, 'zone')));

            return [];
        }

        if ($this->cannotPrompt()) {
            throw new MissingFlagException(
                'zone',
                'which zone to stop managing',
                'larakube dns:remove production --zone='.$installed[0]['zone'],
            );
        }

        $options = [];
        foreach ($installed as $entry) {
            $options[$entry['slug']] = $entry['zone'];
        }

        $chosen = select(label: 'Which zone should this cluster stop managing?', options: $options);

        foreach ($installed as $entry) {
            if ($entry['slug'] === $chosen) {
                return [$entry];
            }
        }

        return [];
    }
}
