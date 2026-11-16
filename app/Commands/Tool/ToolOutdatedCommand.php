<?php

namespace App\Commands\Tool;

use App\Traits\InteractsWithToolRevisions;
use App\Traits\LaraKubeOutput;
use App\Traits\ResolvesStandaloneEnvironment;

use function Laravel\Prompts\table;

use LaravelZero\Framework\Commands\Command;

/**
 * Which installed Cluster Tools this CLI build would render differently: the
 * revision stamped on each instance's workloads (`Labels::REVISION`) against
 * `ToolRevision::for()` now. Read-only; `tool:update` acts on the result.
 */
class ToolOutdatedCommand extends Command
{
    use InteractsWithToolRevisions, LaraKubeOutput, ResolvesStandaloneEnvironment;

    public const string CURRENT = 'current';

    public const string OUTDATED = 'outdated';

    /** Installed before revisions were stamped (or by an older CLI): always worth an update. */
    public const string UNSTAMPED = 'unstamped';

    protected $signature = 'tool:outdated
        {environment? : The environment to inspect}
        {--context= : Target a specific kube-context}
        {--json     : Emit one machine-readable JSON array on stdout}';

    protected $description = 'List installed cluster tools that this CLI would update';

    public function handle(): int
    {
        [$env, $kubectl] = $this->resolveStandaloneEnvironmentAndKubectl();

        $rows = $this->outdatedRows($kubectl);

        if ($this->option('json')) {
            $this->line((string) json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->renderHeader();

        if ($rows === []) {
            $this->laraKubeInfo('No cluster tools are installed.');

            return self::SUCCESS;
        }

        table(
            headers: ['Tool', 'Host', 'Status', 'Installed', 'Available'],
            rows: array_map(fn (array $row): array => [
                $row['label'],
                $row['host'] ?? '—',
                match ($row['status']) {
                    self::CURRENT => 'Up to date',
                    self::OUTDATED => 'Update available',
                    default => 'Update available (untracked install)',
                },
                $row['installedRevision'] ?? '—',
                $row['availableRevision'],
            ], $rows),
        );

        $pending = count(array_filter($rows, fn (array $row): bool => $row['status'] !== self::CURRENT));

        if ($pending > 0) {
            $target = $this->option('context') ? '--context='.$this->option('context') : ($env ?? 'local');
            $this->line("  <fg=gray>👉 {$pending} to update:</> <fg=blue>larakube tool:update {$target} --all</>");
        }

        return self::SUCCESS;
    }
}
