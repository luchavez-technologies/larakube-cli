<?php

namespace App\Commands\Errors;

use App\Enums\ClusterTool;
use App\Enums\SharedClusterService;
use App\Traits\ConfirmsDestructiveAction;
use App\Traits\DeploysClusterTool;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithErrors;
use App\Traits\InteractsWithIngressProxy;
use App\Traits\InteractsWithPlex;
use App\Traits\LaraKubeOutput;
use App\Traits\ResolvesToolBranding;
use App\Traits\ResolvesToolEnvironment;
use App\Traits\ResolvesToolHost;
use App\Traits\StreamsProcessOutput;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use LaravelZero\Framework\Commands\Command;

class ErrorsInitCommand extends Command
{
    use ConfirmsDestructiveAction, DeploysClusterTool, InteractsWithClusterContext, InteractsWithErrors, InteractsWithIngressProxy, InteractsWithPlex, LaraKubeOutput, ResolvesToolBranding, ResolvesToolEnvironment, ResolvesToolHost, StreamsProcessOutput;

    protected $signature = 'errors:init
        {environment? : Environment this install targets — "local" (default) or a cloud env. Omit to be prompted. A non-local env prompts for + persists the GlitchTip host.}
        {--context=  : Target a specific kube-context (defaults to current context)}
        {--domain=   : Base domain OR full host for GlitchTip (example.com → errors.example.com; errors.example.com used as-is)}
        {--app-name= : Custom branding name for GlitchTip (defaults to Error Tracking)}
        {--logo-url= : Custom logo URL for GlitchTip}
        {--no-plex   : Bypass Plex Commons and deploy dedicated database/cache pods instead}
        {--vpn-only  : Restrict access via NetBird VPN IP whitelisting}
        {--force     : Skip the confirmation prompt}'.self::PROXIED_FLAG;

    protected $description = 'Deploy the cluster-wide GlitchTip error tracking stack into larakube-shared';

    public function handle(): int
    {
        $this->renderHeader();

        return $this->deployErrors();
    }

    protected function deployErrors(): int
    {
        $env = $this->resolveEnvironment();
        $context = $this->resolveToolContext($env, $this->option('context'));
        $this->plexContext = $context;
        $kubectl = $this->errorsKubectl($context);
        $host = $this->resolveToolHost(SharedClusterService::ERRORS, ClusterTool::ERRORS, $env, $kubectl);
        $ns = $this->errorsNamespace();

        $noPlex = (bool) $this->option('no-plex');

        if (! $noPlex) {
            if (! $this->ensureCommons(['postgres', 'redis'])) {
                return 1;
            }
        }

        // Read or generate database credentials
        $dbPassword = $this->readExistingDbPassword($kubectl, $ns);
        if ($dbPassword === null) {
            $dbPassword = Str::random(24);
        }

        // Allocate database and user on Plex PostgreSQL
        if (! $noPlex) {
            if (! $this->allocateDatabase(\App\Enums\DatabaseDriver::POSTGRESQL, 'glitchtip', $dbPassword)) {
                return 1;
            }
        }

        // Read or generate admin credentials
        $adminPassword = $this->readErrorsAdminPassword($kubectl, $ns);
        if ($adminPassword === null) {
            $adminPassword = Str::random(16);
        }

        // Ensure target namespace exists
        $this->withSpin("Ensuring namespace {$ns}...", fn () => Process::run(
            "{$kubectl} create namespace {$ns} --dry-run=client -o yaml | {$kubectl} apply -f -",
        ));

        // Delete any existing migrations job first because Job specs are immutable
        Process::run("{$kubectl} delete job glitchtip-db-migrations -n {$ns} --ignore-not-found");

        $vpnOnly = (bool) $this->option('vpn-only');

        if ($vpnOnly && ! $this->ensureVpnMiddleware(ClusterTool::ERRORS, $kubectl)) {
            $this->laraKubeError('Failed to create the VPN-only Middleware — check kubectl access to the cluster above and re-run.');

            return 1;
        }

        $branding = $this->resolveToolBranding($kubectl, ClusterTool::ERRORS);

        $manifest = view('k8s.errors.shared', [
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

        $tmp = sys_get_temp_dir().'/larakube-errors.yaml';
        file_put_contents($tmp, $manifest);

        $this->withSpin('Applying GlitchTip manifests...', fn () => $this->runStreaming("{$kubectl} apply -f {$tmp}"));
        @unlink($tmp);

        if ($noPlex) {
            $this->withSpin('Waiting for local database...', fn () => $this->runStreaming(
                "{$kubectl} rollout status deploy/glitchtip-db -n {$ns} --timeout=120s",
                130,
            ));
            $this->withSpin('Waiting for local cache...', fn () => $this->runStreaming(
                "{$kubectl} rollout status deploy/glitchtip-cache -n {$ns} --timeout=120s",
                130,
            ));
        }

        $this->withSpin('Waiting for database migrations...', fn () => $this->runStreaming(
            "{$kubectl} wait --for=condition=complete job/glitchtip-db-migrations -n {$ns} --timeout=120s",
            130,
        ));

        $this->withSpin('Waiting for GlitchTip Web...', fn () => $this->runStreaming(
            "{$kubectl} rollout status deploy/glitchtip-web -n {$ns} --timeout=120s",
            130,
        ));

        $this->withSpin('Waiting for GlitchTip Worker...', fn () => $this->runStreaming(
            "{$kubectl} rollout status deploy/glitchtip-worker -n {$ns} --timeout=120s",
            130,
        ));

        $this->registerDeployedTool(ClusterTool::ERRORS, $kubectl, $host);

        $this->laraKubeNewLine();
        $this->laraKubeInfo('✅ GlitchTip stack is live.');
        $this->newLine();
        $this->line("  <fg=gray>Access URL:</>              <fg=blue>https://{$host}</>");
        $this->line('  <fg=gray>Admin Email:</>             admin@larakube.local');
        $this->line("  <fg=gray>Admin Password:</>          {$adminPassword}");
        $this->newLine();

        return 0;
    }

    /** Check if database-url points locally or to Plex Commons */
    protected function isErrorsDatabaseLocal(string $kubectl, string $ns): bool
    {
        $url = $this->readClusterSecretKey($kubectl, $ns, 'glitchtip-admin', 'database-url');

        return $url !== null && str_contains($url, 'glitchtip-db');
    }

    /**
     * Parse database user password from existing glitchtip-admin Secret.
     */
    protected function readExistingDbPassword(string $kubectl, string $ns): ?string
    {
        $url = $this->readClusterSecretKey($kubectl, $ns, 'glitchtip-admin', 'database-url');

        if ($url === null) {
            return null;
        }

        // Pattern: postgres://glitchtip:<password>@...
        if (preg_match('/^postgres:\/\/glitchtip:([^@]+)@/', $url, $matches)) {
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
}
