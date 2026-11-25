<?php

namespace App\Traits;

use App\Data\ConfigData;

/**
 * Reconciles services marked for Plex Commons in `environments.<env>.plex`
 * that have not yet been allocated on the cluster (absent from `managed`).
 *
 * Keeps blueprint configuration (`--only=plex`, `env --plex=`, Desktop forms)
 * fast and cluster-free while ensuring `cloud:deploy` and `cloud:configure
 * --only=ci` provision the Commons tenant services before workloads ship.
 */
trait ReconcilesPendingPlexCommons
{
    /**
     * Services configured for Plex Commons in `$environment` that are not yet
     * joined/recorded in `managed`.
     *
     * @return list<string>
     */
    protected function pendingPlexServices(ConfigData $config, string $environment): array
    {
        return array_values(array_diff(
            $config->getPlex($environment),
            $config->getManaged($environment),
        ));
    }

    /**
     * Provision any pending Plex Commons services for `$environment` by
     * delegating to `plex:join --no-interaction`. Reloads `$config` on success
     * and fails loudly if any pending service still could not be joined (for
     * example when existing self-hosted PVC data blocks an automatic join).
     */
    protected function reconcilePendingPlexCommons(ConfigData &$config, string $environment, ?string $context, string $projectPath): bool
    {
        $pending = $this->pendingPlexServices($config, $environment);

        if ($pending === []) {
            return true;
        }

        $this->laraKubeInfo("Provisioning Plex Commons services for '{$environment}': ".implode(', ', $pending).'...');

        $arguments = [
            'environment' => $environment,
            '--service' => $pending,
            '--no-interaction' => true,
        ];

        if ($context !== null && $context !== '') {
            $arguments['--context'] = $context;
        }

        $exit = $this->call('plex:join', $arguments);

        if ($exit !== 0) {
            $this->laraKubeError("Failed to provision Plex Commons services for '{$environment}'.");

            return false;
        }

        $config = $this->getProjectConfigObject($projectPath);
        $stillPending = $this->pendingPlexServices($config, $environment);

        if ($stillPending !== []) {
            $this->laraKubeError(
                "Plex Commons service(s) still pending for '{$environment}': ".implode(', ', $stillPending).'.',
            );
            $this->line(
                "   <fg=gray>If existing self-hosted data blocked automatic joining, run</> <fg=yellow>larakube plex:migrate {$environment}</> <fg=gray>(or</> <fg=yellow>larakube plex:join {$environment} --fresh</><fg=gray>).</>",
            );

            return false;
        }

        return true;
    }
}
