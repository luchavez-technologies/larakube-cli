<?php

namespace App\Commands\Plex;

use App\Data\ConfigData;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithPlex;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use App\Traits\ReadsPlexCredentials;
use App\Traits\ResolvesEnvironmentContext;
use LaravelZero\Framework\Commands\Command;

class PlexStatusCommand extends Command
{
    use InteractsWithClusterContext, InteractsWithPlex, InteractsWithProjectConfig, LaraKubeOutput, ReadsPlexCredentials, ResolvesEnvironmentContext;

    protected $signature = 'plex:status
        {environment=local : The environment whose Commons to inspect (used only inside a project)}
        {--context= : Target a specific kube-context (else: the project env context, or you are prompted)}';

    protected $description = 'Show the shared Commons services and its tenants';

    public function handle(): int
    {
        $this->renderHeader();
        $this->laraKubeInfo('LaraKube Plex — Commons Status');

        // Resolve the target context like plex:init — so `plex:status` works
        // inside OR outside a project. Precedence: --context > the project env's
        // context (read-only, no prompt) > an interactive picker (outside a
        // project). isLaraKubeProject(false) just branches, without erroring.
        $config = $this->isLaraKubeProject(false) ? $this->getProjectConfig(getcwd()) : null;

        if ($this->option('context')) {
            $this->plexContext = (string) $this->option('context');
        } elseif ($config !== null) {
            $env = (string) $this->argument('environment');
            // Local → null context = current kubectl context (K3D). No error; status works.
            $this->plexContext = $this->environmentContextOrCurrent($config, $env);
        } else {
            // Outside a project (no env config to map): pick the cluster, like plex:init.
            $target = $this->askForClusterContext();
            if (! $target) {
                $this->laraKubeError('No Kubernetes context selected.');

                return 1;
            }
            $this->plexContext = $target;
        }

        $context = $this->plexContext;
        if (! $this->plexContextReachable()) {
            $this->laraKubeError('The '.($context ? "context '{$context}'" : 'current context').' is unreachable.');

            return 1;
        }

        $this->line('  <fg=gray>Context:</> <fg=cyan>'.($context ?: 'current context').'</>');

        $spec = $this->getCommonsSpec();
        if ($spec === null) {
            $this->laraKubeNewLine();
            $this->laraKubeInfo('No Commons on this cluster yet. Run `larakube plex:init`.');

            return 0;
        }

        // Services + in-cluster hosts — driven by the spec itself (service name
        // and port come from the spec, never a hardcoded list).
        $ns = $this->plexNamespace();
        $this->laraKubeNewLine();
        $this->line("  <fg=green>Commons services</> ({$ns}):");
        foreach ($spec['services'] ?? [] as $service => $cfg) {
            $on = $cfg['enabled'] ?? false;
            $mark = $on ? '<fg=green>●</>' : '<fg=gray>○</>';
            $detail = $on ? "{$service}.{$ns}.svc.cluster.local:".($cfg['port'] ?? '') : 'disabled';
            $this->line('    '.$mark.' '.str_pad($service, 12).' <fg=gray>'.$detail.'</>');
            if ($on && ! empty($cfg['host'])) {
                $this->line('      <fg=gray>public:</> <fg=cyan>https://'.$cfg['host'].'</>');
            }
            if ($on && ! empty($cfg['console_host'])) {
                $this->line('      <fg=gray>console:</> <fg=cyan>https://'.$cfg['console_host'].'</>');
            }
            if ($on && ! empty($cfg['admin_host'])) {
                $this->line('      <fg=gray>admin:</> <fg=cyan>https://'.$cfg['admin_host'].'</>');
            }
        }

        // Tenants from the registry (highlight this app if it's one).
        $tenants = $this->getRegistry()['tenants'] ?? [];
        // Highlight "this app" only when run inside a project.
        $self = $config !== null ? $this->plexTenantIdentifier($config->getName(), (string) $this->argument('environment')) : null;

        $this->laraKubeNewLine();
        if (empty($tenants)) {
            $this->line('  <fg=gray>No tenants yet.</>');

            return 0;
        }

        $this->line('  <fg=green>Tenants</> ('.count($tenants).'):');
        foreach ($tenants as $name => $alloc) {
            $db = $alloc['db'] ?? '—';
            if (($alloc['db'] ?? null) && ($alloc['db_service'] ?? null)) {
                $db .= " (<fg=gray>{$alloc['db_service']}</>)";   // which engine holds it (postgres/mysql/mariadb)
            }
            $you = $name === $self ? ' <fg=yellow>(this app)</>' : '';
            $this->line("    <fg=cyan>{$name}</>{$you}");

            // Database
            $this->line("      <fg=gray>├─ Database:</> {$db}");

            // Redis
            if (($alloc['redis_index'] ?? null) !== null) {
                $this->line("      <fg=gray>├─ Redis DB:</> {$alloc['redis_index']}");
            } else {
                $this->line('      <fg=gray>├─ Redis DB:</> <fg=gray>—</>');
            }

            // S3 Bucket
            if ($alloc['s3_bucket'] ?? null) {
                $this->line("      <fg=gray>└─ S3 Bucket:</> {$alloc['s3_bucket']}");
            } else {
                $this->line('      <fg=gray>└─ S3 Bucket:</> <fg=gray>—</>');
            }

            // For this app, surface the joined credentials (passwords live in the
            // project's .env, never the registry) so they're recoverable here too.
            if ($name === $self && $config !== null) {
                $this->showSelfCredentials($config, (string) $this->argument('environment'));
            }
        }

        return 0;
    }

    /**
     * Print the joined Commons credentials for this app (DB / Redis / S3), read
     * from the project's .env / .env.{env}. No-op when not a tenant for this env.
     */
    private function showSelfCredentials(ConfigData $config, string $env): void
    {
        $creds = $this->plexTenantCredentials($config, getcwd(), $env);

        if ($creds === []) {
            return;
        }

        $source = $env === 'local' ? '.env' : ".env.{$env}";
        $this->line("      <fg=gray>credentials</> <fg=gray>(from {$source}):</>");

        foreach ($creds as $section => $pairs) {
            foreach ($pairs as $key => $value) {
                $this->line("        <fg=gray>{$section} {$key}:</> {$value}");
            }
        }
    }
}
