<?php

namespace App\Commands\Plex;

use App\Data\ConfigData;
use App\Enums\ClusterTool;
use App\Traits\EmitsJsonOutput;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithPlex;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use App\Traits\ReadsCommandOptions;
use App\Traits\ReadsPlexCredentials;
use App\Traits\ResolvesEnvironmentContext;
use App\Traits\SyncsClusterSecrets;
use Carbon\CarbonInterval;
use LaravelZero\Framework\Commands\Command;

class PlexShowCommand extends Command
{
    use EmitsJsonOutput, InteractsWithClusterContext, InteractsWithPlex, InteractsWithProjectConfig, LaraKubeOutput, ReadsCommandOptions, ReadsPlexCredentials, ResolvesEnvironmentContext, SyncsClusterSecrets;

    protected $signature = 'plex:show
        {environment? : Environment whose Commons to inspect — "local" (default) or a cloud environment. Omit to be prompted (used only inside a project).}
        {--context= : Target a specific kube-context (else: the project env context, or you are prompted)}
        {--json : Emit one machine-readable JSON result on stdout — this is what Desktop\'s ClusterStatus::plex() shells to}';

    protected $description = 'Show the shared Commons services and its tenants';

    public function handle(): int
    {
        if ($this->flag('json')) {
            $this->enableJsonMode();
        }

        $this->renderHeader();
        $this->laraKubeInfo('LaraKube Plex — Commons Status');

        // Resolve the target context like plex:init — so `plex:show` works
        // inside OR outside a project. Precedence: --context > the project env's
        // context (read-only, no prompt) > an interactive picker (outside a
        // project). isLaraKubeProject(false) just branches, without erroring.
        $config = $this->isLaraKubeProject(false) ? $this->getProjectConfig(getcwd()) : null;
        $env = 'local';

        if ($this->option('context')) {
            $this->plexContext = (string) $this->option('context');
        } elseif ($config !== null) {
            $env = $this->resolvePlexEnvironment($config);
            // Local → null context = current kubectl context. No error; status works.
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

            if ($this->flag('json')) {
                $this->jsonOutput([
                    'initialized' => false,
                    'context' => $context,
                    'services' => [],
                    'tenants' => ['tool' => [], 'project' => [], 'custom' => []],
                ]);
            }

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
        $self = $config !== null ? $this->plexTenantIdentifier($config->getName(), $env) : null;

        // Application Tenants only — a registry entry that maps back to a
        // ClusterTool (its Commons DB or bucket name) is cluster
        // infrastructure, not a project app; its rotation status lives on
        // `tool:list` instead, which already scopes to cluster tools and
        // doesn't need a project checked out to answer.
        $appTenants = array_filter(
            $tenants,
            fn ($alloc, $name) => ClusterTool::forCommonsResource($name) === null,
            ARRAY_FILTER_USE_BOTH,
        );

        // Whether OpenBao rotation is even worth checking per-tenant below —
        // one readiness check up front instead of one per tenant/tool, so a
        // Commons without OpenBao (the common case pre-secrets:init) doesn't
        // pay for a port-forward it can never use.
        $kubectl = $this->plexKubectl();
        $openBaoReady = $this->isOpenBaoBootstrapped($kubectl, $this->secretsNamespace());

        // Section 1: Application Tenants
        $this->laraKubeNewLine();
        if (empty($appTenants)) {
            $this->line('  <fg=gray>No Application Tenants connected to Plex yet.</>');
        } else {
            $this->line('  <fg=green>Application Tenants</> ('.count($appTenants).'):');
            foreach ($appTenants as $name => $alloc) {
                $db = $alloc['db'] ?? '—';
                if (($alloc['db'] ?? null) && ($alloc['db_service'] ?? null)) {
                    $db .= " (<fg=gray>{$alloc['db_service']}</>)";
                }
                $you = $name === $self ? ' <fg=yellow>(this app)</>' : '';
                $this->line("    <fg=cyan>{$name}</>{$you}");
                $this->line("      <fg=gray>├─ Database:</> {$db}");

                // Computed once and reused below for showSelfCredentials() —
                // whether this tenant's DB is OpenBao-wired decides both the
                // Rotation line's text AND whether the credentials block is
                // even allowed to print a password (it never should for one
                // OpenBao manages — see that method's docblock).
                $dbWired = ($alloc['db'] ?? null) && $openBaoReady ? $this->staticRoleExists($kubectl, 'tenant-'.$name) : null;

                if ($alloc['db'] ?? null) {
                    $this->line('      <fg=gray>├─ Rotation:</> '.$this->rotationStatusLine($openBaoReady, $dbWired, $kubectl, 'tenant-'.$name));
                }

                if (($alloc['redis_index'] ?? null) !== null) {
                    $this->line("      <fg=gray>├─ Redis DB:</> {$alloc['redis_index']}");
                } else {
                    $this->line('      <fg=gray>├─ Redis DB:</> <fg=gray>—</>');
                }

                if ($alloc['s3_bucket'] ?? null) {
                    $this->line("      <fg=gray>└─ S3 Bucket:</> {$alloc['s3_bucket']}");
                } else {
                    $this->line('      <fg=gray>└─ S3 Bucket:</> <fg=gray>—</>');
                }

                if ($name === $self && $config !== null) {
                    $this->showSelfCredentials($config, $env, $dbWired === true);
                }
            }
        }

        $this->laraKubeNewLine();
        $this->line('  <fg=gray>Cluster tools\' rotation status:</> <fg=blue>larakube tool:list</>');
        $this->newLine();

        if ($this->flag('json')) {
            $this->jsonOutput($this->buildJsonReport($context, $spec, $tenants, $kubectl, $openBaoReady));
        }

        return 0;
    }

    /**
     * {initialized, context, services, tenants: {tool, project, custom}} —
     * the shape Desktop's ClusterStatus::plex() reads. Self credentials are
     * never included here: showSelfCredentials() above only ever prints when
     * a project config is present in cwd, which a `--context=`-only Desktop
     * invocation never has — intentional, not a gap to "fix" later.
     *
     * @return array{initialized: bool, context: ?string, services: array<string, mixed>, tenants: array{tool: list<array<string, mixed>>, project: list<array<string, mixed>>, custom: list<array<string, mixed>>}}
     */
    private function buildJsonReport(?string $context, array $spec, array $tenants, string $kubectl, bool $openBaoReady): array
    {
        $buckets = ['tool' => [], 'project' => [], 'custom' => []];

        foreach ($tenants as $name => $alloc) {
            $bucket = ClusterTool::forCommonsResource($name) !== null
                ? 'tool'
                : (($alloc['kind'] ?? 'project') === 'custom' ? 'custom' : 'project');

            $dbWired = ($alloc['db'] ?? null) && $openBaoReady ? $this->staticRoleExists($kubectl, 'tenant-'.$name) : null;

            $buckets[$bucket][] = [
                'name' => $name,
                'database' => $alloc['db'] ?? null,
                'databaseService' => $alloc['db_service'] ?? null,
                'redisIndex' => $alloc['redis_index'] ?? null,
                's3Bucket' => $alloc['s3_bucket'] ?? null,
                'rotation' => ($alloc['db'] ?? null) ? $this->rotationStatusData($openBaoReady, $dbWired, $kubectl, 'tenant-'.$name) : null,
            ];
        }

        return [
            'initialized' => true,
            'context' => $context,
            'services' => $spec['services'] ?? [],
            'tenants' => $buckets,
        ];
    }

    /**
     * rotationStatusLine(), structured for --json instead of colorized for a
     * terminal. `state` is one of: manual (no OpenBao installed, or this
     * credential predates OpenBao and was never switched over — Desktop
     * can't tell these apart any more usefully than the human line does),
     * unreachable (OpenBao sealed/unreachable right now), managed (OpenBao
     * owns rotation — nextRotation is when the current credential expires).
     *
     * @return array{state: string, nextRotation: ?string}
     */
    private function rotationStatusData(bool $openBaoReady, ?bool $wired, string $kubectl, string $roleName): array
    {
        if (! $openBaoReady) {
            return ['state' => 'manual', 'nextRotation' => null];
        }

        // Null, not false, means "can't tell right now" (OpenBao sealed/
        // unreachable) — must be checked before the plain `! $wired` case
        // below, since `! null` is also truthy and would misclassify it.
        if ($wired === null) {
            return ['state' => 'unreachable', 'nextRotation' => null];
        }

        if (! $wired) {
            return ['state' => 'manual', 'nextRotation' => null];
        }

        $info = $this->staticRoleRotationInfo($kubectl, $roleName);
        if ($info === null) {
            return ['state' => 'managed', 'nextRotation' => null];
        }

        return [
            'state' => 'managed',
            'nextRotation' => now()->addSeconds(max($info['ttl'], 0))->toIso8601String(),
        ];
    }

    /**
     * Human-readable rotation SCHEDULE for a tenant/tool's DB credential —
     * never the credential itself. OpenBao's UI has no browse view for
     * static-role creds (generated on demand via the API, not stored as a
     * browsable KV entry), so this is the only place that answers "when
     * does this rotate next". Three distinct states, each with its own
     * message: not wired to OpenBao at all (installed but this role
     * predates it / joined while unreachable); wired but OpenBao can't be
     * reached RIGHT NOW to confirm the schedule (sealed, etc. —
     * staticRoleExists() returns null for this, not false, specifically so
     * it isn't confused with "not wired"); wired and reachable, here's the
     * schedule.
     */
    private function rotationStatusLine(bool $openBaoReady, ?bool $wired, string $kubectl, string $roleName): string
    {
        if (! $openBaoReady) {
            return '<fg=gray>manual (.env) — OpenBao not installed</>';
        }

        // $wired is null, not just false, when this genuinely can't be
        // determined (OpenBao sealed/unreachable right now) — collapsing
        // that into "not wired" told a tenant that IS OpenBao-managed to
        // "switch it to OpenBao" when it already was. Caught live
        // 2026-08-02: OpenBao's pod had restarted and was sealed.
        if ($wired === null) {
            return '<fg=yellow>could not check — OpenBao unreachable (sealed?)</>';
        }

        if (! $wired) {
            return '<fg=gray>manual (.env) — run</> <fg=blue>larakube plex:rotate</> <fg=gray>to switch it to OpenBao</>';
        }

        $info = $this->staticRoleRotationInfo($kubectl, $roleName);
        if ($info === null) {
            return '<fg=yellow>OpenBao-managed (could not read its schedule)</>';
        }

        $next = CarbonInterval::seconds(max($info['ttl'], 0))->cascade()->forHumans(null, true, 2);
        $period = CarbonInterval::seconds(max($info['rotation_period'], 1))->cascade()->forHumans(null, true, 2);

        return "<fg=green>OpenBao-managed</> <fg=gray>— next in {$next} (every {$period})</>";
    }

    /**
     * Print the joined Commons credentials for this app (DB / Redis / S3), read
     * from the project's .env / .env.{env}. No-op when not a tenant for this env.
     */
    private function showSelfCredentials(ConfigData $config, string $env, bool $dbOpenBaoManaged): void
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

            // writeTenantConfig() actively strips DB_PASSWORD from .env once
            // OpenBao owns it, so $pairs has no Password key to print here —
            // say why instead of silently having a gap.
            if ($section === 'database' && $dbOpenBaoManaged) {
                $this->line('        <fg=gray>database Password:</> <fg=gray>OpenBao-managed — see Rotation above, never stored in .env</>');
            }
        }
    }
}
