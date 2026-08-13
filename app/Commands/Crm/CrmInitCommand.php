<?php

namespace App\Commands\Crm;

use App\Enums\ClusterTool;
use App\Enums\DatabaseDriver;
use App\Enums\SharedClusterService;
use App\Traits\ConfirmsDestructiveAction;
use App\Traits\DeploysClusterTool;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithCrm;
use App\Traits\InteractsWithIngressProxy;
use App\Traits\InteractsWithPlex;
use App\Traits\LaraKubeOutput;
use App\Traits\RequiresFlagsWhenNonInteractive;
use App\Traits\ResolvesToolEnvironment;
use App\Traits\ResolvesToolHost;
use App\Traits\StreamsProcessOutput;
use App\Traits\VerifiesKubernetesRollout;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use LaravelZero\Framework\Commands\Command;

class CrmInitCommand extends Command
{
    use ConfirmsDestructiveAction, DeploysClusterTool, InteractsWithClusterContext, InteractsWithCrm, InteractsWithIngressProxy, InteractsWithPlex, LaraKubeOutput, RequiresFlagsWhenNonInteractive, ResolvesToolEnvironment, ResolvesToolHost, StreamsProcessOutput, VerifiesKubernetesRollout;

    protected $signature = 'crm:init
        {environment? : Environment this install targets — "local" (default) or cloud.}
        {--context=  : Target a specific kube-context}
        {--domain=   : Base domain OR full host for CRM (example.com → prefix.example.com)}
        {--admin-email= : Primary admin email for Twenty CRM}
        {--vpn-only  : Restrict access via NetBird VPN IP whitelisting}
        {--force     : Skip the confirmation prompt}'.self::PROXIED_FLAG;

    protected $description = 'Deploy the Twenty CRM stack into larakube-shared';

    public function handle(): int
    {
        $this->renderHeader();

        return $this->deployCrm();
    }

    protected function deployCrm(): int
    {
        $env = $this->resolveEnvironment();
        $context = $this->resolveToolContext($env, $this->option('context'));
        $this->plexContext = $context;
        $kubectl = $this->crmKubectl($context);
        $host = $this->resolveToolHost(SharedClusterService::CRM, ClusterTool::CRM, $env, $kubectl);
        $ns = $this->crmNamespace();
        $vpnOnly = (bool) $this->option('vpn-only');

        if ($vpnOnly && ! $this->ensureVpnMiddleware(ClusterTool::CRM, $kubectl)) {
            $this->laraKubeError('Failed to create the VPN-only Middleware — check kubectl access to the cluster above and re-run.');

            return 1;
        }

        // Twenty CRM requires Postgres and Redis.
        if (! $this->ensureCommons(['postgres', 'redis'])) {
            return 1;
        }

        $adminEmail = $this->readCrmSecret($kubectl, $ns, 'admin-email') ?? $this->resolveAdminEmail($host);
        $dbPassword = $this->readCrmSecret($kubectl, $ns, 'db-password') ?? Str::random(24);
        $accessTokenSecret = $this->readCrmSecret($kubectl, $ns, 'access-token-secret') ?? bin2hex(random_bytes(32));
        $loginTokenSecret = $this->readCrmSecret($kubectl, $ns, 'login-token-secret') ?? bin2hex(random_bytes(32));
        $refreshTokenSecret = $this->readCrmSecret($kubectl, $ns, 'refresh-token-secret') ?? bin2hex(random_bytes(32));
        $fileTokenSecret = $this->readCrmSecret($kubectl, $ns, 'file-token-secret') ?? bin2hex(random_bytes(32));

        $dbName = 'crm_twenty';

        if (! $this->allocateDatabase(DatabaseDriver::POSTGRESQL, $dbName, $dbPassword)) {
            return 1;
        }

        $redisIndex = $this->allocateCommonsRedisIndex('crm_twenty');

        $this->withSpin("Ensuring namespace {$ns}...", fn () => Process::run(
            "{$kubectl} create namespace {$ns} --dry-run=client -o yaml | {$kubectl} apply -f -",
        ));

        $this->withSpin('Syncing secrets...', function () use ($kubectl, $ns, $dbPassword, $accessTokenSecret, $loginTokenSecret, $refreshTokenSecret, $fileTokenSecret, $adminEmail) {
            $cmd = "{$kubectl} create secret generic crm-twenty-secrets -n {$ns} "
                .'--from-literal=db-password='.escapeshellarg($dbPassword).' '
                .'--from-literal=access-token-secret='.escapeshellarg($accessTokenSecret).' '
                .'--from-literal=login-token-secret='.escapeshellarg($loginTokenSecret).' '
                .'--from-literal=refresh-token-secret='.escapeshellarg($refreshTokenSecret).' '
                .'--from-literal=file-token-secret='.escapeshellarg($fileTokenSecret).' '
                .'--from-literal=admin-email='.escapeshellarg($adminEmail).' '
                ."--dry-run=client -o yaml | {$kubectl} apply -f -";
            Process::run($cmd);
        });

        $manifest = view('k8s.crm.shared', [
            'host' => $host,
            'plexNamespace' => $this->plexNamespace(),
            'vpnOnly' => $vpnOnly,
            'isLocal' => $env === 'local',
            'proxied' => $this->resolveProxied($env === 'local'),
            'redisIndex' => $redisIndex,
        ])->render();

        $tmp = sys_get_temp_dir().'/larakube-crm-twenty.yaml';
        file_put_contents($tmp, $manifest);

        $rolledOut = $this->withSpin(
            'Applying Twenty CRM manifests...',
            fn () => $this->applyAndVerifyRollout($kubectl, $tmp, $ns, 'crm-twenty', 180),
        );
        @unlink($tmp);

        if (! $rolledOut) {
            return 1;
        }

        $this->registerDeployedTool(ClusterTool::CRM, $kubectl, $host, extra: ['adminEmail' => $adminEmail]);

        $this->laraKubeNewLine();
        $this->laraKubeInfo('✅ Twenty CRM stack is live.');
        $this->newLine();
        $this->line("  <fg=gray>Access URL:</>              <fg=blue>https://{$host}</>");
        $this->line("  <fg=gray>Admin Email:</>             {$adminEmail}");
        $this->line("  <fg=gray>Database:</>                <fg=blue>Commons Postgres</> · DB <fg=blue>{$dbName}</>");
        $this->line("  <fg=gray>Redis DB:</>                <fg=blue>{$redisIndex}</>");
        $this->newLine();

        return 0;
    }

    protected function resolveEnvironment(): string
    {
        return $this->resolveToolEnvironment(ClusterTool::CRM);
    }

    /** Resolve the admin email for Twenty CRM */
    protected function resolveAdminEmail(string $host): string
    {
        $parts = explode('.', $host);
        $default = 'admin@'.(count($parts) >= 2 ? implode('.', array_slice($parts, 1)) : $host);

        return $this->flagOrPrompt(
            flag: 'admin-email',
            prompt: fn () => \Laravel\Prompts\text(
                label: 'Primary admin email for Twenty CRM',
                default: $default,
                required: true,
            ),
            purpose: 'Primary admin email for Twenty CRM',
            example: "--admin-email={$default}",
        );
    }
}
