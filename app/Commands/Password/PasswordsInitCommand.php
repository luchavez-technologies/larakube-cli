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
    use ConfirmsDestructiveAction, DeploysClusterTool, InteractsWithClusterContext, InteractsWithIngressProxy, InteractsWithPlex, InteractsWithVault, LaraKubeOutput, ResolvesToolEnvironment, ResolvesToolHost, StreamsProcessOutput, SyncsClusterSecrets, VerifiesKubernetesRollout;

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
        $databaseUrl = null;
        $plexNs = $this->plexNamespace();

        if ($this->ensureCommons(['postgres'])) {
            $driver = DatabaseDriver::POSTGRESQL;
            if ($this->allocateDatabase($driver, 'vaultwarden', $dbPassword)) {
                $databaseUrl = "postgresql://vaultwarden:{$dbPassword}@postgres.{$plexNs}.svc.cluster.local:5432/vaultwarden";
            }
        }

        // Secrets Backend Integration: Push secrets and sync to larakube-vault.
        // The manifest branches on this: when the sync lands, DATABASE_URL is
        // sourced from the synced Secret so plex:rotate can reach this pod;
        // otherwise it stays a literal. Only a sync that actually SUCCEEDED
        // counts — pointing the Deployment at a Secret that was never created
        // would leave DATABASE_URL unset, and Vaultwarden falls back to SQLite
        // when it is, quietly stranding every existing vault.
        $secretsSynced = false;

        if ($this->isOpenBaoBootstrapped($kubectl, $this->secretsNamespace())) {
            $this->withSpin('Syncing Vaultwarden secrets to the cluster...', function () use ($kubectl, $ns, $plexNs, $adminToken, $dbPassword, $databaseUrl, &$secretsSynced) {
                $this->pushClusterSecret($kubectl, 'VAULTWARDEN_ADMIN_TOKEN', $adminToken, 'production');
                if ($this->databaseEngineMounted($kubectl)) {
                    $this->registerStaticRole($kubectl, 'vaultwarden', 'plex-postgres', 'vaultwarden');

                    // registerStaticRole() rotates the password as a side
                    // effect the instant a role is FIRST created — $databaseUrl
                    // still has the pre-rotation $dbPassword baked in at that
                    // point. Rebuild it from what OpenBao actually set before
                    // pushing, or the KV value (and everything synced from
                    // it) is stale from the moment it's written. Confirmed
                    // live 2026-08-02 on Zitadel's identical registration.
                    $realPassword = $this->readStaticRolePassword($kubectl, 'vaultwarden');
                    if ($realPassword !== null && $databaseUrl !== null) {
                        $databaseUrl = "postgresql://vaultwarden:{$realPassword}@postgres.{$plexNs}.svc.cluster.local:5432/vaultwarden";
                    }
                } else {
                    $this->pushClusterSecret($kubectl, 'VAULTWARDEN_DB_PASSWORD', $dbPassword, 'production');
                }

                $urlPushed = $databaseUrl === null
                    || $this->pushClusterSecret($kubectl, 'VAULTWARDEN_DATABASE_URL', $databaseUrl, 'production');

                // NOT syncClusterSecretToNamespace() here — same bug that
                // took down Zitadel (confirmed live 2026-08-02): it extracts
                // KV path "production" as one object, but VAULTWARDEN_DATABASE_URL
                // above is written at the deeper "production/{KEY}" path, so
                // it always syncs empty and, as an Owner-mode ExternalSecret
                // with a 1m refresh, wipes vaultwarden-secrets on its next
                // reconcile — the manifest's DATABASE_URL isn't optional, so
                // that's a crash loop, not a quiet fallback. PASSWORDS is now
                // in ClusterTool::openbaoSyncConfig(), so secrets:init's own
                // sweep (tool-es.blade.php) maintains the real one; reconcile
                // that instead of creating a second, conflicting one.
                $refreshTimeBefore = $this->externalSecretRefreshTime($kubectl, $ns, 'vaultwarden-secrets');
                $this->forceExternalSecretReconcile($kubectl, $ns, 'vaultwarden-secrets');
                $synced = $this->waitForExternalSecretSynced($kubectl, $ns, 'vaultwarden-secrets', $refreshTimeBefore);
                $secretsSynced = $urlPushed && $synced && $databaseUrl !== null;

                return $synced;
            });
        }

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
            'secretsSynced' => $secretsSynced,
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
