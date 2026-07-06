<?php

namespace App\Commands\Plex;

use App\Traits\InteractsWithPlex;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use App\Traits\ResolvesEnvironmentContext;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;

class PlexDestroyCommand extends Command
{
    use InteractsWithPlex, InteractsWithProjectConfig, LaraKubeOutput, ResolvesEnvironmentContext;

    protected $signature = 'plex:destroy
        {environment=local : The environment whose Commons to destroy (local, or a cloud environment)}
        {--force : Skip the tenant guard and confirmation (destroys even with tenants)}';

    protected $description = 'Tear down the ENTIRE shared Commons (all services + ALL tenant data)';

    public function handle(): int
    {
        $this->renderHeader();
        $this->laraKubeInfo('LaraKube Plex — Destroy the Commons');

        if (! $this->isLaraKubeProject()) {
            return 1;
        }

        $config = $this->getProjectConfig(getcwd());
        if (! $config) {
            return 1;
        }

        $env = (string) $this->argument('environment');
        if ($env === 'local') {
            $this->laraKubeWarn('You are destroying a Plex Commons in a local environment.');
            $this->line("  <fg=gray>This deletes the <fg=red>{$this->plexNamespace()}</> namespace and ALL tenant data on K3D.</> <fg=yellow>This cannot be undone.</>");
            $this->newLine();

            if (! confirm('Destroy the local Commons?', false)) {
                return 0;
            }
        }

        $context = $this->environmentContextOrCurrent($config, $env);
        $this->plexContext = $context;
        $where = $context ?: 'the current context';

        if (! $this->plexContextReachable()) {
            $this->laraKubeError('The '.($context ? "context '{$context}'" : 'current context').' is unreachable.');

            return 1;
        }

        $ns = $this->plexNamespace();

        if ($this->getCommonsSpec() === null) {
            $this->laraKubeInfo("No Commons on {$where} — nothing to destroy.");

            return 0;
        }

        // Guard: tenants still registered would be orphaned by a destroy.
        $tenants = array_keys($this->getRegistry()['tenants'] ?? []);
        if (! empty($tenants) && ! $this->option('force')) {
            $this->laraKubeError(count($tenants).' tenant(s) still in the Commons: '.implode(', ', $tenants).'.');
            $this->laraKubeLine('  Detach them first (larakube plex:leave in each), or pass --force to destroy anyway');
            $this->laraKubeLine('  — every tenant database, cache and bucket (ALL data) would be lost.');

            return 1;
        }

        $this->laraKubeWarn("⚠ This DESTROYS the entire Commons in '{$ns}' on {$where}:");
        $this->laraKubeLine('    • every service (Postgres, Redis, object storage, …) and its data');
        $this->laraKubeLine('    • the tenant registry and admin credentials');
        if (! empty($tenants)) {
            $this->laraKubeWarn('  '.count($tenants).' tenant(s) will lose ALL their data: '.implode(', ', $tenants).'.');
        }
        $this->laraKubeNewLine();
        $this->laraKubeLine('  Tip: run <fg=yellow>larakube plex:export</> first if you may want to rebuild the spec later.');
        $this->laraKubeNewLine();

        if (! $this->option('force')) {
            $confirm = text(
                label: "To confirm, type the Commons namespace '{$ns}':",
                required: true,
            );

            if ($confirm !== $ns) {
                $this->laraKubeError('Confirmation failed. Destroy aborted.');

                return 1;
            }
        }

        // Deleting the namespace takes everything with it: workloads, services,
        // PVCs (and their dynamically-provisioned PVs), the spec ConfigMap, the
        // registry, and the admin secret.
        $kubectl = $this->plexKubectl();
        $this->withSpin("Deleting namespace {$ns}...", fn () => passthru(
            "{$kubectl} delete namespace ".escapeshellarg($ns).' --ignore-not-found',
        ));

        $this->laraKubeNewLine();
        $this->laraKubeInfo('✅ Commons destroyed.');
        $this->line('  Re-create it anytime with <fg=yellow>larakube plex:init</> (or plex:init --from <export>).');

        return 0;
    }
}
