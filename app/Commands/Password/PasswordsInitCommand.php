<?php

namespace App\Commands\Password;

use App\Enums\ClusterTool;
use App\Enums\DatabaseDriver;
use App\Enums\SharedClusterService;
use App\Traits\ConfirmsDestructiveAction;
use App\Traits\DeploysClusterTool;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithIngressProxy;
use App\Traits\InteractsWithPlex;
use App\Traits\InteractsWithVault;
use App\Traits\LaraKubeOutput;
use App\Traits\RequiresFlagsWhenNonInteractive;
use App\Traits\ResolvesToolEnvironment;
use App\Traits\ResolvesToolHost;
use App\Traits\StreamsProcessOutput;
use App\Traits\SyncsClusterSecrets;
use App\Traits\VerifiesKubernetesRollout;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use LaravelZero\Framework\Commands\Command;

class PasswordsInitCommand extends Command
{
    use ConfirmsDestructiveAction, DeploysClusterTool, InteractsWithClusterContext, InteractsWithIngressProxy, InteractsWithPlex, InteractsWithVault, LaraKubeOutput, RequiresFlagsWhenNonInteractive, ResolvesToolEnvironment, ResolvesToolHost, StreamsProcessOutput, SyncsClusterSecrets, VerifiesKubernetesRollout;

    protected $signature = 'passwords:init
        {environment? : Environment this install targets — "local" (default) or a cloud env. Omit to be prompted. A non-local env prompts for + persists the Vaultwarden host.}
        {--context=  : Target a specific kube-context (defaults to current context)}
        {--domain=   : Base domain OR full host for Vaultwarden (example.com → vault.example.com; vault.example.com used as-is)}
        {--vpn-only  : Restrict access via NetBird VPN IP whitelisting}
        {--force     : Skip the confirmation prompt}'.self::PROXIED_FLAG;

    protected $description = 'Deploy the cluster-wide Vaultwarden team password manager into larakube-vault';

    public function handle(): int
    {
        $this->renderHeader();

        return $this->deployVault();
    }

    protected function deployVault(): int
    {
        $env = $this->resolveEnvironment();
        $context = $this->resolveToolContext($env, $this->option('context'));
        $this->plexContext = $context;
        $kubectl = $this->vaultKubectl($context);
        $host = $this->resolveToolHost(SharedClusterService::VAULT, ClusterTool::PASSWORDS, $env, $kubectl);
        $ns = $this->vaultNamespace();

        $this->withSpin("Ensuring namespace {$ns}...", fn () => Process::run(
            "{$kubectl} create namespace {$ns} --dry-run=client -o yaml | {$kubectl} apply -f -",
        ));

        $adminToken = $this->readVaultAdminToken($kubectl, $ns) ?? bin2hex(random_bytes(16));
        $hashedAdminToken = defined('PASSWORD_ARGON2ID')
            ? password_hash($adminToken, PASSWORD_ARGON2ID)
            : password_hash($adminToken, PASSWORD_DEFAULT);

        // Allocate Vaultwarden database in Plex Commons Postgres if available
        $dbPassword = Str::random(24);

        // passwords:init doesn't know or care whether OpenBao exists on this
        // cluster — only secrets:wire --tool=passwords may register the
        // 'vaultwarden' static role and hand rotation over to it. This is a
        // READ-only exception: it defers to OpenBao's current password when
        // a PAST secrets:wire run already made it the owner, so a re-run
        // here never clobbers it back to a fresh local one.
        $dbPassword = $this->resolveManagedDbPassword($kubectl, 'vaultwarden', $dbPassword);
        $databaseUrl = null;
        $plexNs = $this->plexNamespace();

        if ($this->ensureCommons(['postgres'])) {
            $driver = DatabaseDriver::POSTGRESQL;
            if ($this->allocateDatabase($driver, 'vaultwarden', $dbPassword)) {
                $databaseUrl = "postgresql://vaultwarden:{$dbPassword}@postgres.{$plexNs}.svc.cluster.local:5432/vaultwarden";
            }
        }

        // Written straight into vault-secrets by the manifest below (same
        // pattern as git-secrets/monitor-secrets) — no OpenBao involvement
        // unless/until secrets:wire merges a rotated value into this same
        // Secret via the 'vault-secrets-db' ExternalSecret.

        $vpnOnly = (bool) $this->option('vpn-only');

        if ($vpnOnly && ! $this->ensureVpnMiddleware(ClusterTool::PASSWORDS, $kubectl)) {
            $this->laraKubeError('Failed to create the VPN-only Middleware — check kubectl access to the cluster above and re-run.');

            return 1;
        }

        $manifest = view('k8s.vault.shared', [
            'host' => $host,
            'adminToken' => $adminToken,
            'hashedAdminToken' => $hashedAdminToken,
            'databaseUrl' => $databaseUrl,
            'isLocal' => $env === 'local',
            'proxied' => $this->resolveProxied($env === 'local'),
            'vpnOnly' => $vpnOnly,
        ])->render();

        $tmp = sys_get_temp_dir().'/larakube-vault.yaml';
        file_put_contents($tmp, $manifest);

        $rolledOut = $this->withSpin(
            'Applying Vaultwarden manifests...',
            fn () => $this->applyAndVerifyRollout($kubectl, $tmp, $ns, 'vaultwarden', 120),
        );
        @unlink($tmp);

        if (! $rolledOut) {
            return 1;
        }

        $this->registerDeployedTool(ClusterTool::PASSWORDS, $kubectl, $host);

        $this->laraKubeNewLine();
        $this->laraKubeInfo('✅ Vaultwarden stack is live.');
        $this->newLine();
        $this->line("  <fg=gray>Vaultwarden URL:</>            <fg=blue>https://{$host}</>");
        $this->line("  <fg=gray>Admin Token:</>                <fg=yellow>{$adminToken}</>");
        $this->line("  <fg=gray>Admin URL:</>                  <fg=blue>https://{$host}/admin</>");
        $this->newLine();

        return 0;
    }

    /**
     * Decide which environment this install targets.
     */
    protected function resolveEnvironment(): string
    {
        return $this->resolveToolEnvironment(ClusterTool::PASSWORDS);
    }
}
