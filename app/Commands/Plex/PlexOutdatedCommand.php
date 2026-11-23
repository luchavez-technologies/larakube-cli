<?php

namespace App\Commands\Plex;

use App\Traits\DeploysClusterTool;
use App\Traits\EmitsJsonOutput;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithPlex;
use App\Traits\LaraKubeOutput;
use App\Traits\TargetsExistingCommons;

use function Laravel\Prompts\table;

use LaravelZero\Framework\Commands\Command;

class PlexOutdatedCommand extends Command
{
    use DeploysClusterTool, EmitsJsonOutput, InteractsWithClusterContext, InteractsWithPlex, LaraKubeOutput, TargetsExistingCommons;

    protected $signature = 'plex:outdated
        {environment? : The environment whose Commons to inspect ("local" or a cloud env)}
        {--context= : Target a specific kube-context}
        {--json     : Emit one machine-readable JSON result on stdout}';

    protected $description = 'List the Commons services this CLI build would change (image, probes or settings)';

    public function handle(): int
    {
        if ($this->option('json')) {
            $this->enableJsonMode();
        }

        $this->renderHeader();

        if (! $this->targetCommons()) {
            return $this->failWith('The cluster is not reachable.');
        }

        $plex = $this->plex();
        $spec = $plex->commonsSpec();

        if ($spec === null) {
            return $this->failWith('There is no Commons on this cluster yet. Run `larakube plex:init` first.');
        }

        $changes = $plex->pendingChangesFor($plex->normalizeCommonsSpec($spec), $this->targetsLocalCluster());

        if ($this->option('json')) {
            $this->jsonOutput(['success' => true, 'outdated' => $changes]);

            return 0;
        }

        if ($changes === []) {
            $this->laraKubeInfo('The Commons is up to date with this CLI build.');

            return 0;
        }

        table(
            headers: ['Service', 'Now', 'After update', 'Restarts'],
            rows: array_map(fn (array $change): array => [
                $change['service'],
                $change['from'] ?? '—',
                $change['to'] === $change['from'] ? 'same image, new settings' : ($change['to'] ?? '—'),
                $change['stateful'] ? 'yes (tenants briefly lose it)' : 'yes',
            ], $changes),
        );
        $this->laraKubeInfo('Run `larakube plex:update` to apply.');

        return 0;
    }

    private function failWith(string $error): int
    {
        if ($this->option('json')) {
            $this->jsonOutput(['success' => false, 'error' => $error]);
        } else {
            $this->laraKubeError($error);
        }

        return 1;
    }
}
