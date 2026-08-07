<?php

namespace App\Commands\Tool;

use App\Enums\ClusterTool;
use App\Traits\InteractsWithToolRegistry;
use App\Traits\LaraKubeOutput;
use App\Traits\ResolvesStandaloneEnvironment;
use App\Traits\SyncsClusterSecrets;

use function Laravel\Prompts\table;

use LaravelZero\Framework\Commands\Command;

/**
 * Show every shared cluster tool and whether it is installed on this cluster.
 *
 * The cluster registry (the `larakube-tools-registry` Secret) is the single
 * source of truth — deliberately NOT `.larakube.json`. These tools are cluster
 * infrastructure, not properties of any one Laravel app: a cluster can host
 * many projects, and a project can target many clusters, so recording tool
 * state in a project file would be both wrong and unshareable. Asking the
 * cluster means the answer is correct from any machine, in any project, or in
 * no project at all.
 */
class ToolListCommand extends Command
{
    use InteractsWithToolRegistry, LaraKubeOutput, ResolvesStandaloneEnvironment, SyncsClusterSecrets;

    protected $signature = 'tool:list
        {environment? : The environment to inspect}
        {--context=   : Target a specific kube-context}
        {--installed  : Only list tools that are actually installed}
        {--json       : Emit one machine-readable JSON array on stdout}';

    protected $description = 'List LaraKube shared cluster tools and which are installed';

    public function handle(): int
    {
        if (! $this->option('json')) {
            $this->renderHeader();
        }

        [$env, $kubectl] = $this->resolveStandaloneEnvironmentAndKubectl();

        $registry = $this->getRegisteredTools($kubectl);
        $onlyInstalled = (bool) $this->option('installed');

        $rows = [];
        foreach (ClusterTool::cases() as $tool) {
            $entry = $registry[$tool->value] ?? null;
            $isPresent = $this->isToolPresentOnCluster($kubectl, $tool);
            $installed = $entry !== null || $isPresent;

            if ($onlyInstalled && ! $installed) {
                continue;
            }

            $host = $entry['host'] ?? null;
            if ($installed && ($host === null || $host === '')) {
                $host = $this->resolveLiveToolHost($kubectl, $tool);
                if ($host !== null && $host !== '') {
                    $this->registerTool($kubectl, $tool, ['host' => $host]);
                }
            }

            $aliasHosts = $entry['alias_hosts'] ?? [];
            $aliasSuffix = $aliasHosts !== [] ? ' (+'.count($aliasHosts).' alias)' : '';

            $rows[] = [
                'tool' => $tool->value,
                'icon' => $tool->icon(),
                'brand' => $tool->brandName(),
                'label' => $tool->getLabel(),
                'installed' => $installed,
                'namespace' => $tool->namespace(),
                'host' => $host,
                'alias_hosts' => $aliasHosts,
                'url' => $host !== null ? 'https://'.$host.$aliasSuffix : null,
                'installed_at' => $entry['installed_at'] ?? null,
                'db_role' => $installed ? ($tool->commonsDatabases()[0] ?? null) : null,
            ];
        }

        // One readiness check up front, only if some installed row actually
        // has a Commons DB to report on — same reasoning as plex:show: a
        // cluster without OpenBao (or with nothing DB-backed installed)
        // shouldn't pay for a port-forward it can never use.
        $openBaoReady = collect($rows)->contains(fn ($r) => $r['db_role'] !== null)
            ? $this->isOpenBaoBootstrapped($kubectl, $this->secretsNamespace())
            : false;

        foreach ($rows as &$row) {
            $row['rotation'] = $row['db_role'] === null
                ? null
                : $this->rotationCell($openBaoReady, $openBaoReady ? $this->staticRoleExists($kubectl, $row['db_role']) : null);
        }
        unset($row);

        if ($this->option('json')) {
            $this->line((string) json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return 0;
        }

        if ($rows === []) {
            $this->laraKubeInfo('No tools installed on this cluster yet.');
            $this->line('  <fg=gray>Install one with</> <fg=blue>larakube tool:add</><fg=gray>.</>');

            return 0;
        }

        table(
            ['', 'Service', 'What it is', 'URL', 'Rotation'],
            array_map(fn (array $r) => [
                $r['installed'] ? '<fg=green>●</>' : '<fg=gray>○</>',
                $r['brand'],
                $r['label'],
                $r['url'] ?? ($r['installed'] ? '<fg=gray>no host recorded</>' : '<fg=gray>—</>'),
                $r['rotation'] ?? '<fg=gray>—</>',
            ], $rows),
        );

        $installedCount = count(array_filter($rows, fn ($r) => $r['installed']));

        $this->newLine();
        $this->line("  <fg=green>●</> installed ({$installedCount})   <fg=gray>○ available</>");
        $this->line("  <fg=gray>Details for one tool:</> <fg=blue>larakube tool:show {$env} --tool=<slug></>");
        $this->newLine();

        return 0;
    }

    /**
     * Compact rotation status for a table cell — the short form of
     * plex:show's rotationStatusLine(). $wired is null, not just false, when
     * OpenBao is bootstrapped but this specific check couldn't be confirmed
     * (sealed/unreachable) — never collapse that into "not wired".
     */
    private function rotationCell(bool $openBaoReady, ?bool $wired): string
    {
        if (! $openBaoReady) {
            return '<fg=gray>manual (.env)</>';
        }

        if ($wired === null) {
            return '<fg=yellow>unreachable</>';
        }

        return $wired
            ? '<fg=green>OpenBao</>'
            : '<fg=gray>manual (.env)</>';
    }
}
