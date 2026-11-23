<?php

namespace App\Traits;

use App\Services\PlexService;
use Illuminate\Support\Facades\Process;

/**
 * Pause and resume for plex:stop / plex:start: scales one Commons service's
 * Deployment (named after the service: postgres, redis, …) or every one in the
 * Commons namespace. Data stays on its volume either way.
 *
 * Needs TargetsExistingCommons (and what it needs), EmitsJsonOutput and LaraKubeOutput.
 */
trait ScalesCommonsServices
{
    protected function scaleCommons(int $replicas): int
    {
        if ($this->option('json')) {
            $this->enableJsonMode();
        }

        $this->renderHeader();
        $verb = $replicas === 0 ? 'Pausing' : 'Resuming';

        if (! $this->targetCommons()) {
            return $this->scaleResult(false, [], 'The cluster is not reachable.');
        }

        $plex = $this->plex();
        $spec = $plex->commonsSpec();

        if ($spec === null) {
            return $this->scaleResult(false, [], 'There is no Commons on this cluster yet. Run `larakube plex:init` first.');
        }

        $service = strtolower(trim((string) $this->option('service')));
        $normalized = $plex->normalizeCommonsSpec($spec);
        $enabled = $plex->enabledCommonsServices($normalized);

        if ($service !== '' && ! in_array($service, $enabled, true)) {
            return $this->scaleResult(false, [], "'{$service}' is not a service on this Commons. It has: ".implode(', ', $enabled).'.');
        }

        $targets = $service !== '' ? [$service] : $enabled;

        // The connection pooler is its own Deployment in front of Postgres:
        // it pauses and resumes with it.
        if (in_array('postgres', $targets, true) && ($normalized['services']['postgres']['pooler']['enabled'] ?? false)) {
            $targets[] = 'pgbouncer';
        }
        $kubectl = $plex->kubectl();
        $ns = PlexService::NAMESPACE;

        if ($replicas === 0) {
            $this->laraKubeWarn('Every app and tool using '.implode(', ', $targets).' loses it until it is resumed. Data stays on its volume.');
        }

        $failed = [];
        foreach ($targets as $target) {
            $ok = $this->withSpin("{$verb} {$target}...", fn () => Process::run(
                "{$kubectl} scale deployment/{$target} --replicas={$replicas} -n {$ns}",
            )->successful());

            if (! $ok) {
                $failed[] = $target;
            }
        }

        if ($failed !== []) {
            return $this->scaleResult(false, $targets, 'Could not scale: '.implode(', ', $failed).'.');
        }

        $this->laraKubeInfo($replicas === 0
            ? 'Paused: '.implode(', ', $targets).'. Resume with `larakube plex:start'.($service !== '' ? " --service={$service}" : '').'`.'
            : 'Resumed: '.implode(', ', $targets).'.');

        return $this->scaleResult(true, $targets);
    }

    /**
     * @param  list<string>  $services
     */
    private function scaleResult(bool $success, array $services, ?string $error = null): int
    {
        if ($error !== null) {
            $this->laraKubeError($error);
        }

        if ($this->option('json')) {
            $this->jsonOutput(array_filter(['success' => $success, 'services' => $services, 'error' => $error], fn (mixed $value): bool => $value !== null));
        }

        return $success ? 0 : 1;
    }
}
