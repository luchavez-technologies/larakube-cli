<?php

namespace App\Commands\Plex;

use App\Traits\InteractsWithPlex;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use App\Traits\ResolvesEnvironmentContext;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;

use LaravelZero\Framework\Commands\Command;

class PlexRemoveCommand extends Command
{
    use InteractsWithPlex, InteractsWithProjectConfig, LaraKubeOutput, ResolvesEnvironmentContext;

    protected $signature = 'plex:remove
        {service? : The Commons service to remove (postgres, redis, meilisearch, seaweedfs)}
        {environment=local : The environment whose Commons to edit (local, or a cloud environment)}
        {--keep-data : Delete the workload but KEEP its PersistentVolumeClaim (data)}
        {--force : Skip the confirmation (and the tenant-in-use guard)}';

    protected $description = 'Remove an unused service from the shared Commons';

    public function handle(): int
    {
        $this->renderHeader();
        $this->laraKubeInfo('LaraKube Plex — Remove a Commons service');

        if (! $this->isLaraKubeProject()) {
            return 1;
        }

        $config = $this->getProjectConfig(getcwd());
        if (! $config) {
            return 1;
        }

        $env = (string) $this->argument('environment');
        $context = $this->environmentContextOrCurrent($config, $env);
        $this->plexContext = $context;

        if (! $this->plexContextReachable()) {
            $this->laraKubeError('The '.($context ? "context '{$context}'" : 'current context').' is unreachable.');

            return 1;
        }

        $spec = $this->getCommonsSpec();
        if ($spec === null) {
            $this->laraKubeInfo('No Commons on this cluster — nothing to remove.');

            return 0;
        }

        $enabled = $this->enabledCommonsServices($spec);
        if (empty($enabled)) {
            $this->laraKubeInfo('The Commons has no enabled services.');

            return 0;
        }

        $service = (string) ($this->argument('service') ?: select(
            label: 'Which Commons service do you want to remove?',
            options: array_combine($enabled, $enabled),
        ));

        if (! in_array($service, $enabled, true)) {
            $this->laraKubeError("'{$service}' isn't an enabled Commons service. Enabled: ".implode(', ', $enabled).'.');

            return 1;
        }

        // Guard: refuse if tenants still use it (unless --force).
        $users = $this->commonsServiceTenants($this->getRegistry(), $service);
        if (! empty($users) && ! $this->option('force')) {
            $this->laraKubeError("'{$service}' is still used by: ".implode(', ', $users).'.');
            $this->laraKubeLine('  Detach those tenants first (larakube plex:leave), or pass --force to remove anyway.');

            return 1;
        }

        $ns = $this->plexNamespace();
        $keepData = (bool) $this->option('keep-data');

        $this->laraKubeWarn("⚠ Removing '{$service}' from the Commons".($keepData ? ' (keeping its data).' : ' — its data will be DELETED.'));
        if (! empty($users)) {
            $this->laraKubeWarn('  Still referenced by: '.implode(', ', $users).' — they will break.');
        }
        $this->laraKubeNewLine();

        if (! $this->option('force') && ! confirm("Remove '{$service}' from the Commons?", false)) {
            $this->laraKubeInfo('Aborted.');

            return 0;
        }

        $kubectl = $this->plexKubectl();

        // 1. Delete the service's workload (+ ingress; + data unless --keep-data).
        //    kubectl apply won't prune, so removal is explicit.
        $this->withSpin("Deleting {$service} workload...", fn () => passthru(
            "{$kubectl} delete deployment/{$service} service/{$service} ingress/{$service}-s3 -n {$ns} --ignore-not-found",
        ));

        if (! $keepData) {
            $this->withSpin("Deleting {$service} data (PVC)...", fn () => passthru(
                "{$kubectl} delete pvc/{$service}-data -n {$ns} --ignore-not-found",
            ));
        }

        // 2. Disable it in the spec and re-apply the Commons manifest (updates the
        //    plex-commons ConfigMap; the remaining services are re-applied as-is).
        $spec['services'][$service]['enabled'] = false;
        $this->withSpin('Updating the Commons spec...', function () use ($spec) {
            $this->applyCommonsManifest($spec);

            return true;
        });

        $this->laraKubeNewLine();
        $this->laraKubeInfo("✅ Removed '{$service}' from the Commons.");

        return 0;
    }
}
