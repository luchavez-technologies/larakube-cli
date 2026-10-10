<?php

namespace App\Commands\Tool;

use App\Data\ClusterCapacitySnapshot;
use App\Enums\ClusterTool;
use App\Services\Tools\ToolInitSpec;
use App\Traits\InteractsWithClusterCapacity;
use App\Traits\LaraKubeOutput;
use App\Traits\RequiresFlagsWhenNonInteractive;

use function Laravel\Prompts\confirm;

use LaravelZero\Framework\Commands\Command;
use LogicException;

/**
 * What every Cluster Tool's init command stands on. Given a tool, the command
 * takes its name, signature and description from ToolInitSpec, so nothing is
 * written per tool. The command is never registered under that name:
 * `tool:init` builds it for the tool it is asked to deploy.
 */
abstract class AbstractToolInitCommand extends Command
{
    use InteractsWithClusterCapacity;
    use LaraKubeOutput;
    use RequiresFlagsWhenNonInteractive;

    public function __construct(protected ?ClusterTool $initTool = null)
    {
        if ($initTool !== null) {
            $this->signature = ToolInitSpec::signature($initTool);
            $this->description = ToolInitSpec::description($initTool);
        }

        parent::__construct();
    }

    public function handle(): int
    {
        $this->renderHeader();

        return $this->runInit();
    }

    /** Deploys the tool: the family's one deploy method. */
    abstract protected function runInit(): int;

    protected function tool(): ClusterTool
    {
        return $this->initTool ?? throw new LogicException(static::class.' was not given a tool.');
    }

    /**
     * Whether this manifest is safe to apply right now, given what the
     * cluster already has committed. Returns true (silently) when the
     * manifest fits, when either live read fails (nothing to guard against —
     * the apply step itself will surface a real connectivity problem), or
     * when the operator accepts the risk; false means the caller must not
     * apply the manifest.
     *
     * `--force` always bypasses the check. Non-interactively, with no
     * `--force` and a real risk, this refuses rather than guessing — the
     * same convention `RequiresFlagsWhenNonInteractive` uses for a missing
     * flag: never silently proceed in headless mode on something this
     * consequential.
     */
    protected function guardClusterCapacity(string $kubectl, string $renderedManifest, float $marginFraction = 0.10): bool
    {
        $snapshot = $this->clusterCapacitySnapshot($kubectl, $marginFraction);

        if ($snapshot === null) {
            return true;
        }

        $demand = $this->manifestResourceDemand($renderedManifest, $snapshot->nodeCount);

        if ($snapshot->fits($demand['cpu'], $demand['memory'])) {
            return true;
        }

        $forced = $this->hasOption('force') && (bool) $this->option('force');

        if ($forced) {
            $this->laraKubeWarn('Proceeding despite low free cluster capacity (--force).');

            return true;
        }

        $this->renderCapacityWarning($snapshot, $demand);

        if ($this->cannotPrompt()) {
            $this->laraKubeError('Refusing to install without enough free cluster capacity. Re-run with --force to proceed anyway.');

            return false;
        }

        return confirm('Not enough free capacity by this estimate — continue anyway?', default: false);
    }

    /** @param  array{cpu: int, memory: int}  $demand */
    private function renderCapacityWarning(ClusterCapacitySnapshot $snapshot, array $demand): void
    {
        $this->laraKubeWarn('This cluster may not have enough free capacity for this install:');
        $this->line('  ● <fg=red>Needs</>: '.$this->formatMillicores($demand['cpu']).' CPU, '.$this->formatBytes($demand['memory']).' memory');
        $this->line('    <fg=gray>👉 Free (after a '.(int) round($snapshot->marginFraction * 100).'% safety margin): '
            .$this->formatMillicores($snapshot->freeCpuMillicores()).' CPU, '.$this->formatBytes($snapshot->freeMemoryBytes())
            .' memory across '.$snapshot->nodeCount.' node'.($snapshot->nodeCount === 1 ? '' : 's').'</>');
    }

    private function formatMillicores(int $millicores): string
    {
        return $millicores % 1000 === 0 ? ($millicores / 1000).' cores' : "{$millicores}m";
    }

    private function formatBytes(int $bytes): string
    {
        return round($bytes / 1024 ** 2).'Mi';
    }
}
