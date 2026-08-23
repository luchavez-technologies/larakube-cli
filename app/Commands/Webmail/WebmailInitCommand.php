<?php

namespace App\Commands\Webmail;

use App\Data\ConfigData;
use App\Enums\ClusterTool;
use App\Enums\SharedClusterService;
use App\Traits\ConfirmsDestructiveAction;
use App\Traits\DeploysClusterTool;
use App\Traits\InteractsWithBulwark;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithIngressProxy;
use App\Traits\InteractsWithMail;
use App\Traits\InteractsWithStalwartApi;
use App\Traits\LaraKubeOutput;
use App\Traits\ResolvesToolEnvironment;
use App\Traits\ResolvesToolHost;
use App\Traits\StreamsProcessOutput;
use App\Traits\SyncsClusterSecrets;
use App\Traits\VerifiesKubernetesRollout;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use LaravelZero\Framework\Commands\Command;
use Spatie\TemporaryDirectory\TemporaryDirectory;

class WebmailInitCommand extends Command
{
    use ConfirmsDestructiveAction, DeploysClusterTool, InteractsWithBulwark, InteractsWithClusterContext, InteractsWithIngressProxy, InteractsWithMail, InteractsWithStalwartApi, LaraKubeOutput, ResolvesToolEnvironment, ResolvesToolHost, StreamsProcessOutput, SyncsClusterSecrets, VerifiesKubernetesRollout;

    protected $signature = 'webmail:init
        {environment? : Environment this install targets — "local" (default) or cloud.}
        {--context=   : Target a specific kube-context}
        {--domain=    : Base domain OR full host for Bulwark webmail (example.com → prefix.example.com)}
        {--app-name=  : Branding shown on the webmail login/app (default: "Webmail")}
        {--vpn-only   : Restrict access via NetBird VPN IP whitelisting}
        {--no-mail-restart : Skip the brief Stalwart restart that applies the CORS change}
        {--force           : Skip the confirmation prompt}'.self::PROXIED_FLAG;

    protected $description = 'Deploy Bulwark — a JMAP webmail UI for Stalwart — into larakube-shared';

    public function handle(): int
    {
        $this->renderHeader();

        return $this->deployWebmail();
    }

    protected function deployWebmail(): int
    {
        $env = $this->resolveEnvironment();

        $projectPath = getcwd();
        $config = file_exists($projectPath.'/'.ConfigData::CONFIG_FILE)
            ? ConfigData::loadFromFile($projectPath)
            : null;

        $context = $this->resolveToolContext($env, $this->option('context'));
        $kubectl = $this->bulwarkKubectl($context);
        $ns = $this->bulwarkNamespace();
        $host = $this->resolveToolHost(SharedClusterService::WEBMAIL, ClusterTool::WEBMAIL, $env, $kubectl);
        // Every tool's instance identifier is a real, host-derived slug now
        // — Webmail included, even though it's 1:1 bound to the one Stalwart
        // and will never have a second instance.
        $instance = ClusterTool::WEBMAIL->instanceSlugFromHost($host);
        $vpnOnly = (bool) $this->option('vpn-only');

        // Bulwark is a client for Stalwart — refuse if there's no Stalwart to
        // point it at, rather than deploy a webmail that can't reach a server.
        if (! $this->isMailInstalled($kubectl, $ns)) {
            $this->laraKubeError('Stalwart is not installed. Run `larakube mail:init` first — Bulwark is a webmail client for it.');

            return 1;
        }

        $mailHost = $this->resolveMailHostReadOnly($env, $config, $kubectl);
        if (! $mailHost) {
            $this->laraKubeError("No Stalwart host is configured for '{$env}'. Run `larakube mail:init {$env}` first.");

            return 1;
        }

        if ($vpnOnly && ! $this->ensureVpnMiddleware(ClusterTool::WEBMAIL, $kubectl)) {
            $this->laraKubeError('Failed to create the VPN-only Middleware — check kubectl access to the cluster above and re-run.');

            return 1;
        }

        $sessionSecret = $this->readBulwarkSecret($kubectl, $ns, 'WEBMAIL_SESSION_SECRET', $instance)
            ?? $this->readBulwarkSecret($kubectl, $ns, 'session-secret', $instance)
            ?? Str::random(48);
        $adminPassword = $this->readBulwarkSecret($kubectl, $ns, 'WEBMAIL_ADMIN_PASSWORD', $instance)
            ?? $this->readBulwarkSecret($kubectl, $ns, 'admin-password', $instance)
            ?? Str::random(24);
        $appName = (string) ($this->option('app-name') ?: 'Webmail');
        $secretName = "webmail-secrets-{$instance}";

        $this->withSpin("Ensuring namespace {$ns}...", fn () => Process::run(
            "{$kubectl} create namespace {$ns} --dry-run=client -o yaml | {$kubectl} apply -f -",
        ));

        $this->withSpin('Syncing secrets...', function () use ($kubectl, $ns, $secretName, $sessionSecret, $adminPassword, $env): void {
            Process::run(
                "{$kubectl} create secret generic {$secretName} -n {$ns} "
                .'--from-literal=WEBMAIL_SESSION_SECRET='.escapeshellarg($sessionSecret).' '
                .'--from-literal=WEBMAIL_ADMIN_PASSWORD='.escapeshellarg($adminPassword).' '
                ."--dry-run=client -o yaml | {$kubectl} apply -f -",
            );

            if ($this->secretsBackendAvailable($kubectl)) {
                $clusterEnv = $env === 'local' ? 'dev' : $env;
                $this->pushClusterSecret($kubectl, 'WEBMAIL_SESSION_SECRET', $sessionSecret, $clusterEnv);
                $this->pushClusterSecret($kubectl, 'WEBMAIL_ADMIN_PASSWORD', $adminPassword, $clusterEnv);
                // NOT syncClusterSecretToNamespace() here — same bug that
                // took down Zitadel (confirmed live 2026-08-02): it extracts
                // KV path "{env}" as one object, but every value above is at
                // the deeper "{env}/{KEY}" path, so it always syncs empty
                // and, as an Owner-mode ExternalSecret with a 1m refresh,
                // wipes the `create secret` above on its next reconcile.
                // secrets:init's own sweep (tool-es.blade.php) is the
                // correct, working path.
            }
        });

        $manifest = view('k8s.webmail.bulwark', [
            'host' => $host,
            'instance' => $instance,
            'mailHost' => $mailHost,
            'appName' => $appName,
            'vpnOnly' => $vpnOnly,
            'isLocal' => $env === 'local',
            'proxied' => $this->resolveProxied($env === 'local'),
        ])->render();

        $temporaryDirectory = TemporaryDirectory::make();
        $tmp = $temporaryDirectory->path('larakube-webmail.yaml');
        file_put_contents($tmp, $manifest);

        $deploymentName = ClusterTool::WEBMAIL->deploymentName($instance);

        $rolledOut = $this->withSpin(
            'Applying Bulwark manifests...',
            fn () => $this->applyAndVerifyRollout($kubectl, $tmp, $ns, $deploymentName, 180),
        );
        $temporaryDirectory->delete();

        if (! $rolledOut) {
            return 1;
        }

        // The browser talks to Stalwart's JMAP endpoint directly (cross-origin
        // from the webmail host), so Stalwart must CORS-allow it. Best-effort:
        // a miss here is a recoverable, clearly-diagnosable login error, not a
        // broken deploy — print the manual step instead of failing.
        $corsOk = $this->stalwartSetPermissiveCors($kubectl, $ns);

        // Writing the setting isn't enough: Stalwart loads config from its store
        // at boot, so the CORS change only takes effect after a restart (exactly
        // like the setup wizard). Without this the setting is written but webmail
        // login keeps failing with a CORS error — the trap the first cut hit.
        $restarted = false;
        if ($corsOk && ! $this->option('no-mail-restart')) {
            $mailDeployment = ClusterTool::MAIL->deploymentName($this->resolveMailInstance($kubectl));
            $this->withSpin('Restarting Stalwart to apply CORS (brief mail blip)...', fn () => Process::run(
                "{$kubectl} rollout restart deployment/{$mailDeployment} -n {$ns}",
            ));
            $this->withSpin('Waiting for Stalwart to come back...', fn () => Process::timeout(190)->run(
                "{$kubectl} rollout status deployment/{$mailDeployment} -n {$ns} --timeout=180s",
            )->successful());
            $restarted = true;
        }

        $this->laraKubeNewLine();
        $this->laraKubeInfo('✅ Bulwark webmail is live.');
        $this->registerDeployedTool(ClusterTool::WEBMAIL, $kubectl, $host, $instance);
        $this->newLine();
        $this->line("  <fg=gray>Webmail URL:</>        <fg=blue>https://{$host}</>");
        $this->line("  <fg=gray>Webmail Admin URL:</>  <fg=blue>https://{$host}/admin</>");
        $this->line("  <fg=gray>Webmail Admin pass:</> <fg=yellow>{$adminPassword}</>");
        $this->line("  <fg=gray>Mail server:</>        <fg=blue>https://{$mailHost}</> (JMAP)");
        $this->newLine();
        $this->line('  Your team logs in with their mailbox address + password');
        $this->line('  (the ones <fg=blue>larakube mail:create</> hands out).');
        $this->newLine();
        if (! $corsOk) {
            $this->line('  <fg=yellow>⚠ Could not auto-enable CORS on Stalwart.</> The browser talks to JMAP');
            $this->line('  directly, so without it webmail login fails with a CORS error. Enable it');
            $this->line('  manually in Stalwart admin: <fg=blue>Network → HTTP → Security</> →');
            $this->line("  turn on <fg=blue>Permissive CORS policy</>, then apply it with <fg=blue>larakube mail:restart {$env}</>.");
            $this->newLine();
        } else {
            $this->line('  <fg=yellow>⚠ Enable Permissive CORS Policy for Webmail:</>');
            $this->line('  In Stalwart Admin: <fg=blue>Network → HTTP → Security</>');
            $this->line('  → Toggle <fg=blue>Permissive CORS policy</> to ON, click <fg=blue>Save</>, then run: <fg=blue>larakube mail:restart '.$env.'</>');
            $this->newLine();
        }

        return 0;
    }

    protected function resolveEnvironment(): string
    {
        return $this->resolveToolEnvironment(ClusterTool::WEBMAIL);
    }
}
