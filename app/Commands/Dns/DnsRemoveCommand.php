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
 * Remove one dns:init GROUP's ExternalDNS instance, or all of them.
 *
 * Per-group rather than all-or-nothing, because a cluster can run several
 * instances and tearing down the wrong one silently stops DNS reconciliation
 * for zones that are still live. A group can now cover 2+ zones sharing one
 * token — `--zone=` targeting one of several zones in a group refuses rather
 * than guessing whether "remove this zone" means the whole group or an
 * (unsupported) partial shrink; `--group=` is the removal unit for that case.
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
        {--zone=      : A zone to stop managing — refuses if it is one of several zones sharing a group, use --group= for those}
        {--group=     : The named multi-zone instance to remove entirely, e.g. --group=shared}
        {--all        : Remove every instance on this cluster}
        {--context=   : Target a specific kube-context}
        {--force      : Skip the confirmation prompt}';

    protected $description = 'Stop managing one or more Cloudflare zones with ExternalDNS';

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

        $groups = $this->groupInstalledZones($installed);

        $targets = $this->resolveTargets($groups);
        if ($targets === []) {
            return 1;
        }

        $allZones = array_merge(...array_column($targets, 'zones'));

        if (! $this->confirmDestructive([
            'These ExternalDNS instances will be REMOVED: '.implode(', ', $allZones),
            'DNS reconciliation stops for those zones.',
            'Existing DNS records are NOT deleted — they stay live and unmanaged.',
        ])) {
            return 0;
        }

        $ok = true;

        foreach ($targets as $target) {
            $slug = $target['slug'];

            $ok = $this->removeResources(
                'Removing ExternalDNS for '.implode(', ', $target['zones']).'...',
                "{$kubectl} delete deployment/external-dns-{$slug} "
                ."serviceaccount/external-dns-{$slug} secret/cloudflare-token-{$slug} "
                ."-n {$ns} --ignore-not-found",
            ) && $ok;

            // Cluster-scoped — not garbage-collected with the namespace objects.
            Process::run("{$kubectl} delete clusterrolebinding/external-dns-{$slug} --ignore-not-found");
        }

        // The ClusterRole is shared by every instance, so it only goes when
        // the last one does.
        if ($this->installedDnsZones($kubectl, $ns) === []) {
            Process::run("{$kubectl} delete clusterrole/external-dns --ignore-not-found");
        }

        if (! $ok) {
            $this->laraKubeError('One or more ExternalDNS resources failed to remove.');

            return 1;
        }

        $this->laraKubeInfo('ExternalDNS removed for: '.implode(', ', $allZones));
        $this->newLine();
        $this->line('  <fg=gray>The DNS records it created still exist in Cloudflare. Delete them there</>');
        $this->line('  <fg=gray>if you want them gone, or re-run</> <fg=blue>dns:init</> <fg=gray>to resume management.</>');
        $this->newLine();

        return 0;
    }

    /**
     * Collapse the flat per-zone rows installedDnsZones() returns into one
     * row per real instance — several zone rows can share the same slug
     * (one dns:init group covering several zones on one token).
     *
     * @param  list<array{zone: string, slug: string, owner: string, ready: bool}>  $installed
     * @return list<array{slug: string, zones: list<string>}>
     */
    protected function groupInstalledZones(array $installed): array
    {
        $groups = [];
        foreach ($installed as $entry) {
            $groups[$entry['slug']]['slug'] = $entry['slug'];
            $groups[$entry['slug']]['zones'][] = $entry['zone'];
        }

        return array_values($groups);
    }

    /**
     * @param  list<array{slug: string, zones: list<string>}>  $groups
     * @return list<array{slug: string, zones: list<string>}>
     */
    protected function resolveTargets(array $groups): array
    {
        if ($this->option('all')) {
            return $groups;
        }

        $groupName = (string) ($this->option('group') ?? '');
        if ($groupName !== '') {
            foreach ($groups as $group) {
                if ($group['slug'] === $groupName || $group['slug'] === $this->zoneSlug($groupName)) {
                    return [$group];
                }
            }

            $this->laraKubeError("'{$groupName}' is not a managed instance on this cluster.");
            $this->line('  <fg=gray>Managed instances: </>'.implode(', ', array_column($groups, 'slug')));

            return [];
        }

        $zone = (string) ($this->option('zone') ?? '');
        if ($zone !== '') {
            foreach ($groups as $group) {
                if (! in_array($zone, $group['zones'], true)) {
                    continue;
                }

                if (count($group['zones']) > 1) {
                    $others = implode(', ', array_diff($group['zones'], [$zone]));
                    $this->laraKubeError(
                        "'{$zone}' is part of the '{$group['slug']}' instance (also manages: {$others}) — ".
                        "pass --group={$group['slug']} to remove the whole instance, or re-run dns:init ".
                        '--group='.$group['slug'].' with a reduced --zone= list to shrink it.',
                    );

                    return [];
                }

                return [$group];
            }

            $this->laraKubeError("'{$zone}' is not managed by this cluster.");
            $this->line('  <fg=gray>Managed zones: </>'.implode(', ', array_merge(...array_column($groups, 'zones'))));

            return [];
        }

        if ($this->cannotPrompt()) {
            throw new MissingFlagException(
                'zone',
                'which zone (or --group=) to stop managing',
                'larakube dns:remove production --zone='.$groups[0]['zones'][0],
            );
        }

        $options = [];
        foreach ($groups as $group) {
            $options[$group['slug']] = implode(', ', $group['zones']);
        }

        $chosen = select(label: 'Which instance should this cluster stop managing?', options: $options);

        foreach ($groups as $group) {
            if ($group['slug'] === $chosen) {
                return [$group];
            }
        }

        return [];
    }
}
