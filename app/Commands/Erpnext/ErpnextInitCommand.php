<?php

namespace App\Commands\Erpnext;

use App\Commands\Tool\AbstractToolInitCommand;
use App\Data\ToolInstance;
use App\Enums\ClusterTool;
use App\Enums\DatabaseDriver;
use App\Enums\SharedClusterService;
use App\Services\Kubectl;
use App\Traits\ConfirmsDestructiveAction;
use App\Traits\DeploysClusterTool;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithIngressProxy;
use App\Traits\InteractsWithPlex;
use App\Traits\LaraKubeOutput;
use App\Traits\ResolvesToolEnvironment;
use App\Traits\ResolvesToolHost;
use App\Traits\StreamsProcessOutput;
use App\Traits\SyncsClusterSecrets;
use App\Traits\VerifiesKubernetesRollout;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Spatie\TemporaryDirectory\TemporaryDirectory;

abstract class ErpnextInitCommand extends AbstractToolInitCommand
{
    use ConfirmsDestructiveAction, DeploysClusterTool, InteractsWithClusterContext, InteractsWithIngressProxy, InteractsWithPlex, LaraKubeOutput, ResolvesToolEnvironment, ResolvesToolHost, StreamsProcessOutput, SyncsClusterSecrets, VerifiesKubernetesRollout;

    protected function runInit(): int
    {
        $env = $this->resolveEnvironment();
        $context = $this->resolveToolContext($env, $this->option('context'));
        $this->plexContext = $context;
        $kubectl = Kubectl::forContext($context)->prefix();
        $host = $this->resolveToolHost(SharedClusterService::ERPNEXT, ClusterTool::ERPNEXT, $env, $kubectl);
        $names = ToolInstance::forHost(ClusterTool::ERPNEXT, $host);

        $ns = $names->namespace();
        $vpnOnly = (bool) $this->option('vpn-only');

        if ($vpnOnly && ! $this->ensureVpnMiddleware(ClusterTool::ERPNEXT, $kubectl, $names->instance)) {
            $this->laraKubeError('Failed to create the VPN-only Middleware — check kubectl access to the cluster above and re-run.');

            return 1;
        }

        if (! $this->ensureCommons(['mariadb', 'redis'])) {
            return 1;
        }

        // Reuse existing passwords on re-run so re-applying never breaks DB
        // connectivity or rotates the Administrator login silently.
        $dbPassword = $this->readClusterSecretKey($kubectl, $ns, $names->secret(), 'db-password') ?? Str::random(24);
        $adminPassword = $this->readClusterSecretKey($kubectl, $ns, $names->secret(), 'admin-password') ?? Str::random(24);

        $dbName = $names->database();
        // Once secrets:wire has OpenBao rotating this role, its password is the real one.
        $dbPassword = $this->resolveManagedDbPassword($kubectl, $dbName, $dbPassword);

        if (! $this->allocateDatabase(DatabaseDriver::MARIADB, $dbName, $dbPassword)) {
            return 1;
        }

        $redisIndex = $this->allocateCommonsRedisIndex($names->redisTenant());
        if ($redisIndex === null) {
            $this->laraKubeError('The Commons Redis has no free logical DB index (all 16 in use).');

            return 1;
        }

        $this->withSpin("Ensuring namespace {$ns}...", fn () => Process::run(
            "{$kubectl} create namespace {$ns} --dry-run=client -o yaml | {$kubectl} apply -f -",
        ));

        $this->withSpin('Syncing secrets...', function () use ($kubectl, $ns, $names, $dbPassword, $adminPassword): void {
            Kubectl::fromPrefix($kubectl)->putSecret($ns, $names->secret(), [
                'db-password' => $dbPassword,
                'admin-password' => $adminPassword,
            ], $names->labels());
        });

        if ($this->secretsBackendAvailable($kubectl)) {
            $clusterEnv = $env === 'local' ? 'dev' : $env;
            // Only secrets:wire registers the rotating role, together with the ExternalSecret that
            // carries each new password back into this Secret; until then OpenBao keeps a copy.
            if (! $this->databaseEngineMounted($kubectl)) {
                $this->pushClusterSecret($kubectl, 'ERPNEXT_DB_PASSWORD', $dbPassword, $clusterEnv);
            }
            $this->pushClusterSecret($kubectl, 'ERPNEXT_ADMIN_PASSWORD', $adminPassword, $clusterEnv);
        }

        $manifest = view('k8s.erpnext.erpnext', [
            'host' => $host,
            'names' => $names,
            'dbName' => $dbName,
            'plexNamespace' => $this->plexNamespace(),
            'redisIndex' => $redisIndex,
            'vpnOnly' => $vpnOnly,
            'isLocal' => $env === 'local',
            'proxied' => $this->resolveProxied($env === 'local'),
        ])->render();

        $temporaryDirectory = TemporaryDirectory::make();
        $tmp = $temporaryDirectory->path('larakube-erpnext.yaml');
        file_put_contents($tmp, $manifest);

        $rolledOut = $this->withSpin(
            'Applying ERPNext manifests...',
            fn () => $this->applyAndVerifyRollout($kubectl, $tmp, $ns, $names->deployment(), 600, tool: ClusterTool::ERPNEXT),
        );
        $temporaryDirectory->delete();

        if (! $rolledOut) {
            return 1;
        }

        $this->registerDeployedTool(ClusterTool::ERPNEXT, $kubectl, $host);

        $this->laraKubeNewLine();
        $this->laraKubeInfo('✅ ERPNext is live.');
        $this->newLine();
        $this->line("  <fg=gray>Access URL:</>    <fg=blue>https://{$host}</>");
        $this->line('  <fg=gray>Username:</>      <fg=blue>Administrator</>');
        $this->line("  <fg=gray>Password:</>      <fg=blue>{$adminPassword}</>");
        $this->line("  <fg=gray>Database:</>      <fg=blue>Commons MariaDB</> · <fg=blue>{$dbName}</>");
        $this->line("  <fg=gray>Cache/queue:</>   <fg=blue>Commons Redis</> · DB index <fg=blue>{$redisIndex}</>");
        $this->newLine();
        $this->line('  <fg=gray>Next: log in as Administrator to pick your Company, Country, and Base Currency in the Setup Wizard.</>');
        $this->newLine();

        return 0;
    }

    protected function resolveEnvironment(): string
    {
        return $this->resolveToolEnvironment(ClusterTool::ERPNEXT);
    }
}
