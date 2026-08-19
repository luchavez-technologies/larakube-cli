<?php

namespace App\Commands\Dashboard;

use App\Data\ConfigData;
use App\Traits\DeploysClusterTool;
use App\Traits\InteractsWithRemoteSsh;
use App\Traits\InteractsWithSso;
use App\Traits\LaraKubeOutput;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use LaravelZero\Framework\Commands\Command;

class DashboardTrustCommand extends Command
{
    use DeploysClusterTool, InteractsWithRemoteSsh, InteractsWithSso, LaraKubeOutput;

    protected $signature = 'dashboard:trust
        {environment : Environment whose k3s API server to configure (not "local" — OrbStack has no SSH access and needs no OIDC trust)}
        {--context= : Kube-context to read Zitadel/Headlamp credentials from, if different from the environment default}';

    protected $description = 'Configure the k3s API server to trust Zitadel, so a Headlamp OIDC login actually carries real Kubernetes permissions';

    /**
     * Kubernetes' built-in OIDC authenticator supports exactly ONE
     * (issuer, client-id) pair per API server — there is no per-tool config
     * here, unlike everything else sso:wire touches. Headlamp is the only
     * tool that needs it today (it forwards its OIDC token straight through
     * to the API server — see the `groups` claim wiring in
     * zitadelEnsureRbacAction() and dashboard-oidc-admins in
     * headlamp.blade.php), so its Zitadel app is what gets trusted. A second
     * K8s-native tool would need to reuse the SAME client, not add a second
     * trust anchor — the API server can't hold two.
     */
    public function handle(): int
    {
        $this->renderHeader();

        $environment = (string) $this->argument('environment');
        if ($environment === 'local') {
            $this->laraKubeError("'local' runs on OrbStack, not k3s — there's no API server flag to set and no SSH access. This command only targets cloud environments.");

            return 1;
        }

        $projectPath = getcwd();
        $config = file_exists($projectPath.'/'.ConfigData::CONFIG_FILE)
            ? ConfigData::loadFromFile($projectPath)
            : null;

        $cloud = $config?->getCloud($environment);
        if ($cloud === null || $cloud->ip === null) {
            if ($cloud?->context !== null) {
                $this->laraKubeError(
                    "'{$environment}' is a managed Kubernetes cluster ({$cloud->provider}) — there's no node to SSH into. ".
                    'Configuring OIDC trust on a managed control plane is provider-specific and not something this command can do.',
                );

                return 1;
            }

            $this->laraKubeError("No server is recorded for '{$environment}' — run `cloud:provision` (or `cloud:init`) first.");

            return 1;
        }

        $context = $this->resolveToolContext($environment, $this->option('context'));
        $kubectl = $this->ssoKubectl($context);
        $ssoNs = $this->ssoNamespace();

        if (! $this->isSsoInstalled($kubectl, $ssoNs)) {
            $this->laraKubeError('Zitadel is not installed. Run `larakube sso:init` first.');

            return 1;
        }

        $ssoHost = $this->resolveSsoHostReadOnly($environment, $config, $kubectl);
        $clientId = $this->readClusterSecretKey($kubectl, $ssoNs, 'sso-app-dashboard', 'client-id');

        if ($ssoHost === null || $clientId === null) {
            $this->laraKubeError('Headlamp is not wired to Zitadel yet — run `larakube sso:wire '.$environment.' --tool=dashboard` first.');

            return 1;
        }

        $desired = $this->desiredK3sConfig($ssoHost, $clientId);

        $user = $cloud->user ?? 'larakube';
        $ip = $cloud->ip;
        $port = $cloud->port ?? 22;
        $keyPath = str_replace('~', home_path(), $cloud->key ?? home_path('.ssh/id_rsa'));

        if (! file_exists($keyPath)) {
            $this->laraKubeError("SSH key not found at: {$keyPath}");

            return 1;
        }

        $this->laraKubeInfo("Testing SSH connection to {$user}@{$ip}...");
        if (! $this->testSsh($user, $ip, $port, $keyPath)) {
            $this->laraKubeError('Could not connect via SSH. Check the IP, user, port and key.');

            return 1;
        }

        $this->laraKubeInfo('Checking the API server\'s current OIDC config...');
        $current = trim(Process::run(
            "ssh -o ConnectTimeout=5 -o BatchMode=yes -o StrictHostKeyChecking=no -i {$keyPath} -p {$port} {$user}@{$ip} "
            .escapeshellarg(($user !== 'root' ? 'sudo ' : '').'cat /etc/rancher/k3s/config.yaml 2>/dev/null || true'),
        )->output());

        if (trim($current) === trim($desired)) {
            $this->laraKubeInfo('✅ The API server already trusts Zitadel — nothing to change.');
            $this->newLine();
            $this->line("  <fg=gray>Issuer:</> <fg=blue>https://{$ssoHost}</>");
            $this->line("  <fg=gray>Client ID:</> <fg=blue>{$clientId}</>");
            $this->newLine();

            return 0;
        }

        $this->line('  This will apply on <fg=cyan>'.$ip.'</>:');
        $this->line('   • Write /etc/rancher/k3s/config.yaml with Zitadel as a trusted OIDC issuer');
        $this->line('   • Restart the k3s service — briefly interrupts the API server for the WHOLE cluster');
        $this->newLine();

        if (! $this->confirmRestart()) {
            $this->laraKubeInfo('Cancelled.');

            return 0;
        }

        $this->laraKubeInfo('Applying OIDC trust and restarting k3s...');
        if (! $this->runRemoteCommand($user, $ip, $port, $keyPath, $this->applyScript($desired))) {
            $this->laraKubeError('Failed to apply the config — see the remote output above. The API server may be mid-restart; check it manually before retrying.');

            return 1;
        }

        if (! $this->waitForApiServer($context)) {
            $this->laraKubeError('k3s restarted but the API server never came back healthy — check it on the server: `systemctl status k3s`.');

            return 1;
        }

        $this->laraKubeInfo('✅ The k3s API server now trusts Zitadel.');
        $this->newLine();
        $this->line("  <fg=gray>Issuer:</> <fg=blue>https://{$ssoHost}</>");
        $this->line("  <fg=gray>Client ID:</> <fg=blue>{$clientId}</>");
        $this->line('  <fg=gray>Username claim:</> <fg=blue>email</>  <fg=gray>Groups claim:</> <fg=blue>groups</> <fg=gray>(both unprefixed)</>');
        $this->newLine();
        $this->line('  <fg=gray>A team member granted a dashboard role (`sso:grant --tool=dashboard`) now gets real cluster');
        $this->line('  access through Headlamp\'s "Login with Zitadel" — no CLI or kubeconfig needed on their end.</>');
        $this->newLine();

        return 0;
    }

    protected function confirmRestart(): bool
    {
        return \Laravel\Prompts\confirm('Proceed?', true);
    }

    /**
     * `-` is Kubernetes' documented sentinel for "don't prefix" on both of
     * these flags — but only oidc-username-prefix actually honors it on this
     * k3s/kube-apiserver version. oidc-groups-prefix=- still prepends a
     * literal "-" to every group in the token's `groups` claim instead of
     * disabling prefixing, confirmed live 2026-08-06 via SelfSubjectReview
     * (a real token resolved to groups ["-openbao-admin", "-dashboard-admin",
     * ...], not the bare role keys). dashboard-oidc-admins
     * (headlamp.blade.php) binds "-dashboard-admin" to match that reality,
     * not the docs — if this flag's behavior is ever fixed upstream, that
     * binding needs to drop the leading "-" in lockstep with whatever change
     * lands here.
     */
    protected function desiredK3sConfig(string $ssoHost, string $clientId): string
    {
        return <<<YAML
        kube-apiserver-arg:
          - "oidc-issuer-url=https://{$ssoHost}"
          - "oidc-client-id={$clientId}"
          - "oidc-username-claim=email"
          - "oidc-groups-claim=groups"
          - "oidc-username-prefix=-"
          - "oidc-groups-prefix=-"
        YAML;
    }

    protected function applyScript(string $desired): string
    {
        $encoded = base64_encode($desired);

        return <<<BASH
        mkdir -p /etc/rancher/k3s
        echo {$encoded} | base64 -d > /etc/rancher/k3s/config.yaml
        systemctl restart k3s
        BASH;
    }

    /**
     * Poll the API server via the SAME kube-context every other command in
     * this codebase already trusts, rather than re-deriving SSH-based
     * health from the node — a config.yaml change plus a k3s restart is only
     * actually "done" once the server accepts requests again.
     */
    protected function waitForApiServer(?string $context, int $maxAttempts = 24, int $delay = 5): bool
    {
        $kubectl = 'KUBECONFIG='.escapeshellarg(home_path('.kube/config')).' kubectl'.($context ? " --context={$context}" : '');

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            if (Process::timeout(10)->run("{$kubectl} get --raw=/livez")->successful()) {
                return true;
            }

            if ($attempt % 3 === 0) {
                $this->line('  ⏳ Still waiting for the API server... ('.($attempt * $delay).'s)');
            }
            Sleep::sleep($delay);
        }

        return false;
    }
}
