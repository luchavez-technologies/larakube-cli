<?php

namespace App\Commands\Errors;

use App\Commands\Tool\AbstractToolInitCommand;
use App\Data\ToolInstance;
use App\Enums\ClusterTool;
use App\Enums\DatabaseDriver;
use App\Enums\SharedClusterService;
use App\Services\Kubectl;
use App\Traits\ConfirmsDestructiveAction;
use App\Traits\DeploysClusterTool;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithErrors;
use App\Traits\InteractsWithIngressProxy;
use App\Traits\InteractsWithPlex;
use App\Traits\InteractsWithVolumeSizing;
use App\Traits\LaraKubeOutput;
use App\Traits\RequiresFlagsWhenNonInteractive;
use App\Traits\ResolvesToolBranding;
use App\Traits\ResolvesToolEnvironment;
use App\Traits\ResolvesToolHost;
use App\Traits\StreamsProcessOutput;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Spatie\TemporaryDirectory\TemporaryDirectory;

abstract class ErrorsInitCommand extends AbstractToolInitCommand
{
    use ConfirmsDestructiveAction, DeploysClusterTool, InteractsWithClusterContext, InteractsWithErrors, InteractsWithIngressProxy, InteractsWithPlex, InteractsWithVolumeSizing, LaraKubeOutput, RequiresFlagsWhenNonInteractive, ResolvesToolBranding, ResolvesToolEnvironment, ResolvesToolHost, StreamsProcessOutput;

    protected function runInit(): int
    {
        return $this->deployErrors();
    }

    protected function deployErrors(): int
    {
        $env = $this->resolveEnvironment();
        $context = $this->resolveToolContext($env, $this->option('context'));
        $this->plexContext = $context;
        $kubectl = Kubectl::forContext($context)->prefix();
        $host = $this->resolveToolHost(SharedClusterService::ERRORS, ClusterTool::ERRORS, $env, $kubectl);
        $ns = $this->errorsNamespace();
        $names = ToolInstance::forHost(ClusterTool::ERRORS, $host);

        $noPlex = (bool) $this->option('no-plex');

        if (! $noPlex) {
            if (! $this->ensureCommons(['postgres', 'redis'])) {
                return 1;
            }
        }

        // Read or generate database credentials
        $dbPassword = $this->readExistingDbPassword($kubectl, $ns, $names->secret());
        if ($dbPassword === null) {
            $dbPassword = Str::random(24);
        }

        $dbName = $names->database();
        $redisIndex = null;

        // Allocate database, user and a Redis index on Plex Commons
        if (! $noPlex) {
            if (! $this->allocateDatabase(DatabaseDriver::POSTGRESQL, $dbName, $dbPassword)) {
                return 1;
            }

            $redisIndex = $this->allocateCommonsRedisIndex($names->redisTenant());
            if ($redisIndex === null) {
                $this->laraKubeError('Every Commons Redis index (0-15) is already allocated — free one up (larakube <tool>:remove --purge) and retry.');

                return 1;
            }
        }

        // Read or generate admin credentials
        $adminPassword = $this->readErrorsAdminPassword($kubectl, $ns, $names->secret());
        if ($adminPassword === null) {
            $adminPassword = Str::random(16);
        }

        // Ensure target namespace exists
        $this->withSpin("Ensuring namespace {$ns}...", fn () => Process::run(
            "{$kubectl} create namespace {$ns} --dry-run=client -o yaml | {$kubectl} apply -f -",
        ));

        // Delete any existing migrations job first because Job specs are immutable
        Process::run("{$kubectl} delete job {$names->name('migrations')} -n {$ns} --ignore-not-found");

        $vpnOnly = (bool) $this->option('vpn-only');

        if ($vpnOnly && ! $this->ensureVpnMiddleware(ClusterTool::ERRORS, $kubectl, $names->instance)) {
            $this->laraKubeError('Failed to create the VPN-only Middleware — check kubectl access to the cluster above and re-run.');

            return 1;
        }

        $branding = $this->resolveToolBranding($kubectl, ClusterTool::ERRORS, ClusterTool::ERRORS->instanceSlugFromHost($host));

        $manifest = view('k8s.errors.shared', [
            'dbName' => $dbName,
            'instance' => $names->instance,
            'redisIndex' => $redisIndex,
            'volumeSize' => $this->volumeSizeResolver($kubectl, $ns),
            'host' => $host,
            'appName' => $branding['appName'],
            'logoUrl' => $branding['logoUrl'],
            'adminPassword' => $adminPassword,
            'dbPassword' => $dbPassword,
            'plexNamespace' => $this->plexNamespace(),
            'noPlex' => $noPlex,
            'isLocal' => $env === 'local',
            'proxied' => $this->resolveProxied($env === 'local'),
            'vpnOnly' => $vpnOnly,
        ])->render();

        $temporaryDirectory = TemporaryDirectory::make();
        $tmp = $temporaryDirectory->path('larakube-glitchtip.yaml');
        file_put_contents($tmp, $manifest);

        // Multiple resources to verify in sequence (db/cache/job/web/worker),
        // so this can't use the single apply+rollout applyAndVerifyRollout()
        // helper — but every step below still must check its real exit code,
        // not discard it like the old runStreaming() calls did, or a
        // rejected apply / stuck rollout prints ✔ and this command claims
        // success regardless (confirmed live on Documenso, 2026-08-05). Every
        // Process::run() below sets an explicit ->timeout() exceeding its own
        // kubectl --timeout flag — Laravel's default PHP-level timeout is
        // only 60s, which would otherwise throw a ProcessTimedOutException
        // and crash this command before kubectl's own timeout ever fires
        // (the exact same incident, same root cause).
        $applied = $this->withSpin('Applying GlitchTip manifests...', fn () => Process::timeout(70)->run("{$kubectl} apply -f {$tmp} --request-timeout=60s")->successful());
        $temporaryDirectory->delete();

        if (! $applied) {
            $this->laraKubeError('Could not apply the GlitchTip manifest — see the output above.');

            return 1;
        }

        if ($noPlex) {
            if (! $this->withSpin('Waiting for local database...', fn () => Process::timeout(130)->run("{$kubectl} rollout status deploy/{$names->deployment('db')} -n {$ns} --timeout=120s")->successful())) {
                $this->laraKubeError("{$names->deployment('db')} never became Ready.");

                return 1;
            }
            if (! $this->withSpin('Waiting for local cache...', fn () => Process::timeout(130)->run("{$kubectl} rollout status deploy/{$names->deployment('cache')} -n {$ns} --timeout=120s")->successful())) {
                $this->laraKubeError("{$names->deployment('cache')} never became Ready.");

                return 1;
            }
        }

        if (! $this->withSpin('Waiting for database migrations...', fn () => Process::timeout(130)->run("{$kubectl} wait --for=condition=complete job/{$names->name('migrations')} -n {$ns} --timeout=120s")->successful())) {
            $this->laraKubeError("{$names->name('migrations')} never completed.");

            return 1;
        }

        if (! $this->withSpin('Waiting for GlitchTip Web...', fn () => Process::timeout(130)->run("{$kubectl} rollout status deploy/{$names->deployment()} -n {$ns} --timeout=120s")->successful())) {
            $this->laraKubeError("{$names->deployment()} never became Ready.");

            return 1;
        }

        if (! $this->withSpin('Waiting for GlitchTip Worker...', fn () => Process::timeout(130)->run("{$kubectl} rollout status deploy/{$names->deployment('worker')} -n {$ns} --timeout=120s")->successful())) {
            $this->laraKubeError("{$names->deployment('worker')} never became Ready.");

            return 1;
        }

        $adminEmail = $this->readClusterSecretKey($kubectl, $ns, $names->secret(), 'admin-email') ?? $this->resolveAdminEmail($host);

        $this->registerDeployedTool(ClusterTool::ERRORS, $kubectl, $host, $names->instance, extra: ['adminEmail' => $adminEmail]);

        $this->laraKubeNewLine();
        $this->laraKubeInfo('✅ GlitchTip stack is live.');
        $this->newLine();
        $this->line("  <fg=gray>Access URL:</>              <fg=blue>https://{$host}</>");
        $this->line("  <fg=gray>Admin Email:</>             {$adminEmail}");
        $this->line("  <fg=gray>Admin Password:</>          {$adminPassword}");
        $this->newLine();

        return 0;
    }

    /** Check if database-url points locally or to Plex Commons */
    protected function isErrorsDatabaseLocal(string $kubectl, string $ns, ToolInstance $names): bool
    {
        $url = $this->readClusterSecretKey($kubectl, $ns, $names->secret(), 'database-url');

        return $url !== null && str_contains($url, $names->deployment('db'));
    }

    /**
     * Parse the database password from the existing credentials Secret.
     */
    protected function readExistingDbPassword(string $kubectl, string $ns, string $secret): ?string
    {
        $url = $this->readClusterSecretKey($kubectl, $ns, $secret, 'database-url');

        if ($url === null) {
            return null;
        }

        // Pattern: postgres://<tenant>:<password>@...
        if (preg_match('/^postgres:\/\/[^:]+:([^@]+)@/', $url, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Resolve the GlitchTip ingress host for this install.
     */
    protected function resolveEnvironment(): string
    {
        return $this->resolveToolEnvironment(ClusterTool::ERRORS);
    }

    /** Resolve the admin email for GlitchTip */
    protected function resolveAdminEmail(string $host): string
    {
        $parts = explode('.', $host);
        $default = 'admin@'.(count($parts) >= 2 ? implode('.', array_slice($parts, 1)) : $host);

        return $this->flagOrPrompt(
            flag: 'admin-email',
            prompt: fn () => \Laravel\Prompts\text(
                label: 'Primary administrator email for GlitchTip',
                default: $default,
                required: true,
            ),
            purpose: 'Primary administrator email for GlitchTip',
            example: "--admin-email={$default}",
        );
    }
}
