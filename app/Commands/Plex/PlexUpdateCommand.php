<?php

namespace App\Commands\Plex;

use App\Traits\DeploysClusterTool;
use App\Traits\EmitsJsonOutput;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithPlex;
use App\Traits\LaraKubeOutput;
use App\Traits\StreamsProcessOutput;
use App\Traits\TargetsExistingCommons;
use LaravelZero\Framework\Commands\Command;

class PlexUpdateCommand extends Command
{
    use DeploysClusterTool, EmitsJsonOutput, InteractsWithClusterContext, InteractsWithPlex, LaraKubeOutput, StreamsProcessOutput, TargetsExistingCommons;

    protected $signature = 'plex:update
        {environment? : The environment whose Commons to update ("local" or a cloud env)}
        {--context= : Target a specific kube-context}
        {--service= : Update only this Commons service (e.g. postgres); the others stay as they run}
        {--dry-run  : List what would change, and change nothing}
        {--confirm-commons-restart : Confirm restarting running Commons services, without an interactive prompt}
        {--json     : Emit one machine-readable JSON result on stdout}';

    protected $description = 'Re-apply the Commons with this CLI build, keeping its services, sizes and settings';

    public function handle(): int
    {
        if ($this->option('json')) {
            $this->enableJsonMode();
        }

        $this->renderHeader();
        $this->laraKubeInfo('LaraKube Plex — Commons Update');

        if (! $this->targetCommons()) {
            return $this->result(false, [], 'The cluster is not reachable.');
        }

        $plex = $this->plex();
        $saved = $plex->commonsSpec();

        if ($saved === null) {
            return $this->result(false, [], 'There is no Commons on this cluster yet. Run `larakube plex:init` first.');
        }

        // The saved spec as this CLI build reads it: same services, sizes and
        // custom images; current pins and manifest shape.
        $spec = $plex->normalizeCommonsSpec($saved);
        $changes = $plex->pendingChangesFor($spec, $this->targetsLocalCluster());
        $only = $this->option('service') ? strtolower(trim((string) $this->option('service'))) : null;

        if ($only !== null) {
            if (! array_key_exists($only, $plex->commonsServiceCatalog()) || ! ($spec['services'][$only]['enabled'] ?? false)) {
                return $this->result(false, [], "'{$only}' is not a service on this Commons.");
            }

            $changes = array_values(array_filter($changes, fn (array $change): bool => $change['service'] === $only));
        }

        if ($changes === []) {
            $this->laraKubeInfo('The Commons is already up to date with this CLI build.');

            return $this->result(true, []);
        }

        foreach ($changes as $change) {
            $what = $change['from'] !== $change['to'] ? "{$change['from']} → {$change['to']}" : 'new settings';
            $when = $change['paused'] ? ' (paused: applies when resumed)' : '';
            $this->laraKubeLine("  • {$change['service']}: {$what}{$when}");
        }

        if ($this->option('dry-run')) {
            $this->laraKubeInfo('Dry run: nothing was changed.');

            return $this->result(true, $changes, dryRun: true);
        }

        // Confirms (or refuses, without --confirm-commons-restart) before any
        // stateful service restarts.
        if (! $this->applyCommons($spec, 'Updating Commons manifests...', $only)) {
            return $this->result(false, $changes, 'The update was not applied.');
        }

        $kubectl = $this->plexKubectl();
        $ns = $this->plexNamespace();
        $failed = [];

        foreach (array_filter($changes, fn (array $change): bool => ! $change['paused']) as $change) {
            $ok = $this->withSpin("Waiting for {$change['service']} to be ready...", fn () => $this->runStreaming(
                "{$kubectl} rollout status deploy/{$change['service']} -n {$ns} --timeout=180s",
                190,
            ) === 0);

            if (! $ok) {
                $failed[] = $change['service'];
            }
        }

        if ($failed !== []) {
            return $this->result(false, $changes, 'Not ready after the update: '.implode(', ', $failed).'. Check `kubectl get pods -n '.$ns.'`.');
        }

        $this->laraKubeInfo('The Commons is up to date.');

        return $this->result(true, $changes);
    }

    /**
     * @param  list<array{service: string, stateful: bool, from: string|null, to: string|null, paused: bool}>  $changes
     */
    private function result(bool $success, array $changes, ?string $error = null, bool $dryRun = false): int
    {
        if ($error !== null) {
            $this->laraKubeError($error);
        }

        if ($this->option('json')) {
            $this->jsonOutput(array_filter([
                'success' => $success,
                'updated' => $dryRun ? [] : array_column($changes, 'service'),
                'changes' => $changes,
                'dryRun' => $dryRun ?: null,
                'error' => $error,
            ], fn (mixed $value): bool => $value !== null));
        }

        return $success ? 0 : 1;
    }
}
