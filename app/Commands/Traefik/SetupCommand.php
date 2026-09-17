<?php

namespace App\Commands\Traefik;

use App\Traits\DeploysClusterTool;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithTraefik;
use App\Traits\LaraKubeOutput;
use App\Traits\ProvisionsK3sNode;
use LaravelZero\Framework\Commands\Command;

class SetupCommand extends Command
{
    use DeploysClusterTool, InteractsWithClusterContext, InteractsWithTraefik, LaraKubeOutput, ProvisionsK3sNode;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'traefik:setup
        {environment=local : Environment whose Traefik to install or upgrade}
        {--context= : Target a specific kube-context}';

    /**
     * The console command description.
     */
    protected $description = 'Install or upgrade the Traefik Ingress Controller';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->renderHeader();

        $env = (string) $this->argument('environment');

        if ($env !== 'local') {
            return $this->upgradeCloudTraefik($env);
        }

        if (! $this->confirmLocalOnlyAction('Traefik + the shared Mailpit companion')) {
            $this->laraKubeInfo('Setup cancelled.');

            return 0;
        }

        if (! $this->setupTraefik()) {
            $this->laraKubeError('Traefik setup failed — see the output above.');

            return 1;
        }

        $this->laraKubeInfo('✅ Traefik setup complete.');

        return 0;
    }

    /**
     * Re-apply the cloud Traefik manifest to an already-provisioned cluster.
     *
     * cloud:create installs Traefik once and then skips it forever, so manifest
     * changes (CRDs, provider flags, RBAC) never reached existing clusters. This
     * is the upgrade path.
     */
    protected function upgradeCloudTraefik(string $env): int
    {
        $context = $this->resolveToolContext($env, (string) $this->option('context') ?: null);
        if ($context === null || $context === '') {
            $this->laraKubeError("No kube-context resolved for '{$env}' — pass --context= or run `larakube cloud:init` first.");

            return 1;
        }

        $ip = $this->resolveTraefikIngressIp($context);
        if ($ip === null) {
            $this->laraKubeError("Could not determine this cluster's ingress IP — pass --context= for the right cluster.");

            return 1;
        }

        $this->laraKubeWarn('Traefik will roll — expect a few seconds of 502s on every ingress while it restarts.');
        $this->newLine();

        if (! $this->deployTraefik($context, $ip, force: true)) {
            $this->laraKubeError('Traefik upgrade failed — see the output above.');

            return 1;
        }

        $this->laraKubeInfo("✅ Traefik upgraded on '{$env}'.");

        return 0;
    }
}
