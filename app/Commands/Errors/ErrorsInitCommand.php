<?php

namespace App\Commands\Errors;

use App\Enums\ClusterTool;
use App\Enums\DatabaseDriver;
use App\Enums\SharedClusterService;
use App\Traits\ConfirmsDestructiveAction;
use App\Traits\DeploysClusterTool;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithErrors;
use App\Traits\InteractsWithIngressProxy;
use App\Traits\InteractsWithPlex;
use App\Traits\LaraKubeOutput;
use App\Traits\RequiresFlagsWhenNonInteractive;
use App\Traits\ResolvesToolBranding;
use App\Traits\ResolvesToolEnvironment;
use App\Traits\ResolvesToolHost;
use App\Traits\StreamsProcessOutput;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use LaravelZero\Framework\Commands\Command;

class ErrorsInitCommand extends Command
{
    use ConfirmsDestructiveAction, DeploysClusterTool, InteractsWithClusterContext, InteractsWithErrors, InteractsWithIngressProxy, InteractsWithPlex, LaraKubeOutput, RequiresFlagsWhenNonInteractive, ResolvesToolBranding, ResolvesToolEnvironment, ResolvesToolHost, StreamsProcessOutput;

    protected $signature = 'errors:init
        {environment? : Environment this install targets — "local" (default) or a cloud env. Omit to be prompted. A non-local env prompts for + persists the GlitchTip host.}
        {--context=  : Target a specific kube-context (defaults to current context)}
        {--domain=   : Base domain OR full host for GlitchTip (example.com → errors.example.com; errors.example.com used as-is)}
        {--app-name= : Custom branding name for GlitchTip (defaults to Error Tracking)}
        {--logo-url= : Custom logo URL for GlitchTip}
        {--admin-email= : Primary administrator email for GlitchTip}
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
            if (! $this->allocateDatabase(DatabaseDriver::POSTGRESQL, 'glitchtip', $dbPassword)) {
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
        @unlink($tmp);

        if (! $applied) {
            $this->laraKubeError('Could not apply the GlitchTip manifest — see the output above.');

            return 1;
        }

        if ($noPlex) {
            if (! $this->withSpin('Waiting for local database...', fn () => Process::timeout(130)->run("{$kubectl} rollout status deploy/glitchtip-db -n {$ns} --timeout=120s")->successful())) {
                $this->laraKubeError('glitchtip-db never became Ready.');

                return 1;
            }
            if (! $this->withSpin('Waiting for local cache...', fn () => Process::timeout(130)->run("{$kubectl} rollout status deploy/glitchtip-cache -n {$ns} --timeout=120s")->successful())) {
                $this->laraKubeError('glitchtip-cache never became Ready.');

                return 1;
            }
        }

        if (! $this->withSpin('Waiting for database migrations...', fn () => Process::timeout(130)->run("{$kubectl} wait --for=condition=complete job/glitchtip-db-migrations -n {$ns} --timeout=120s")->successful())) {
            $this->laraKubeError('glitchtip-db-migrations never completed.');

            return 1;
        }

        if (! $this->withSpin('Waiting for GlitchTip Web...', fn () => Process::timeout(130)->run("{$kubectl} rollout status deploy/glitchtip-web -n {$ns} --timeout=120s")->successful())) {
            $this->laraKubeError('glitchtip-web never became Ready.');

            return 1;
        }

        if (! $this->withSpin('Waiting for GlitchTip Worker...', fn () => Process::timeout(130)->run("{$kubectl} rollout status deploy/glitchtip-worker -n {$ns} --timeout=120s")->successful())) {
            $this->laraKubeError('glitchtip-worker never became Ready.');

            return 1;
        }

        $adminEmail = $this->readClusterSecretKey($kubectl, $ns, 'glitchtip-admin', 'admin-email') ?? $this->resolveAdminEmail($host);

        $this->registerDeployedTool(ClusterTool::ERRORS, $kubectl, $host, extra: ['adminEmail' => $adminEmail]);

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
