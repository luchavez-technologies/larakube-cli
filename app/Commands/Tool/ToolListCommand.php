<?php

namespace App\Commands\Tool;

use App\Enums\ClusterTool;
use App\Traits\InteractsWithToolRegistry;
use App\Traits\LaraKubeOutput;
use App\Traits\ResolvesStandaloneEnvironment;

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
    use InteractsWithToolRegistry, LaraKubeOutput, ResolvesStandaloneEnvironment;

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
            $installed = $entry !== null;

            if ($onlyInstalled && ! $installed) {
                continue;
            }

            $rows[] = [
                'tool' => $tool->value,
                'label' => $tool->getLabel(),
                'installed' => $installed,
                'namespace' => $tool->namespace(),
                'host' => $entry['host'] ?? null,
                'url' => isset($entry['host']) ? 'https://'.$entry['host'] : null,
                'installed_at' => $entry['installed_at'] ?? null,
            ];
        }

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
            ['', 'Tool', 'What it is', 'URL'],
            array_map(fn (array $r) => [
                $r['installed'] ? '<fg=green>●</>' : '<fg=gray>○</>',
                $r['tool'],
                $r['label'],
                $r['url'] ?? ($r['installed'] ? '<fg=gray>no host recorded</>' : '<fg=gray>—</>'),
            ], $rows),
        );

        $installedCount = count(array_filter($rows, fn ($r) => $r['installed']));

        $this->newLine();
        $this->line("  <fg=green>●</> installed ({$installedCount})   <fg=gray>○ available</>");
        $this->line("  <fg=gray>Details for one tool:</> <fg=blue>larakube tool:show {$env} --tool=<slug></>");
        $this->newLine();

        return 0;
    }
}
