<?php

namespace App\Traits;

use Illuminate\Support\Facades\Process;

/**
 * Pause and resume every app Deployment in a namespace around a step that
 * needs a consistent snapshot (a Commons migration copy, a cross-cluster
 * migration's final backup) — writes during the copy would land after the
 * snapshot was taken and never make it to the destination.
 *
 * Extracted from PlexMigrateCommand (its own within-cluster self-hosted→
 * Commons copy) so CloudMigrateCommand's cross-cluster migration can reuse
 * the identical pause/resume mechanics instead of a second copy.
 */
trait QuiescesAppDeployments
{
    /**
     * Scale every deployment in the namespace to zero EXCEPT the source
     * service(s) being copied from (which must stay up to be read) —
     * quiesces writes so the dump/mirror gets a consistent snapshot. Returns
     * the original replica counts (empty if nothing else runs there) so
     * resumeAppDeployments() can restore them afterwards.
     *
     * @param  array<int, string>  $excludeDeployments
     * @return array<string, int>
     */
    protected function quiesceAppDeployments(string $kubectl, string $namespace, array $excludeDeployments): array
    {
        $decoded = json_decode(Process::run(
            "{$kubectl} get deployments -n ".escapeshellarg($namespace).' -o json',
        )->output(), true);

        $original = [];
        foreach ($decoded['items'] ?? [] as $item) {
            $name = $item['metadata']['name'] ?? null;
            $replicas = $item['spec']['replicas'] ?? 1;

            if ($name === null || in_array($name, $excludeDeployments, true) || $replicas < 1) {
                continue;
            }

            $original[$name] = $replicas;
        }

        if (empty($original)) {
            return [];
        }

        $this->withSpin('Pausing app writes ('.implode(', ', array_keys($original)).')...', function () use ($kubectl, $namespace, $original) {
            foreach (array_keys($original) as $name) {
                Process::run("{$kubectl} scale deployment/{$name} --replicas=0 -n ".escapeshellarg($namespace));
            }

            return true;
        });

        return $original;
    }

    /**
     * Restore the replica counts captured by quiesceAppDeployments(). Called
     * from a `finally` block so the app resumes whether the copy succeeded or
     * failed.
     *
     * @param  array<string, int>  $original
     */
    protected function resumeAppDeployments(string $kubectl, string $namespace, array $original): void
    {
        if (empty($original)) {
            return;
        }

        $this->withSpin('Resuming app...', function () use ($kubectl, $namespace, $original) {
            foreach ($original as $name => $replicas) {
                Process::run("{$kubectl} scale deployment/{$name} --replicas={$replicas} -n ".escapeshellarg($namespace));
            }

            return true;
        });
    }
}
