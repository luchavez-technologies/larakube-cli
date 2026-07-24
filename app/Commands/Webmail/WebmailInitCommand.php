<?php

namespace App\Commands\Webmail;

use App\Data\ConfigData;
use App\Enums\ClusterTool;
use App\Enums\SharedClusterService;
use App\Traits\ConfirmsDestructiveAction;
use App\Traits\DeploysClusterTool;
use App\Traits\InteractsWithBulwark;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithMail;
use App\Traits\InteractsWithStalwartApi;
use App\Traits\LaraKubeOutput;
use App\Traits\ResolvesToolEnvironment;
use App\Traits\StreamsProcessOutput;
use App\Traits\SyncsInfisicalSecrets;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;

class WebmailInitCommand extends Command
{
    use ConfirmsDestructiveAction, DeploysClusterTool, InteractsWithBulwark, InteractsWithClusterContext, InteractsWithMail, InteractsWithStalwartApi, LaraKubeOutput, ResolvesToolEnvironment, StreamsProcessOutput, SyncsInfisicalSecrets;

    protected $signature = 'webmail:init
        {environment? : Environment this install targets — "local" (default) or cloud.}
        {--context=   : Target a specific kube-context}
        {--domain=    : Base domain OR full host for Bulwark webmail (example.com → prefix.example.com)}
        {--app-name=  : Branding shown on the webmail login/app (default: "Webmail")}
        {--vpn-only   : Restrict access via NetBird VPN IP whitelisting}
        {--no-mail-restart : Skip the brief Stalwart restart that applies the CORS change}
        {--force           : Skip the confirmation prompt}';

    protected $description = 'Deploy Bulwark — a JMAP webmail UI for Stalwart — into larakube-shared';

    public function handle(): int
    {
        $this->renderHeader();

        return $this->deployWebmail();
    }

    protected function deployWebmail(): int
    {
        $env = $this->resolveEnvironment();
        $host = $this->resolveWebmailHost($env);

        $projectPath = getcwd();
        $config = file_exists($projectPath.'/'.ConfigData::CONFIG_FILE)
            ? ConfigData::loadFromFile($projectPath)
            : null;

        $context = $this->resolveToolContext($env, $this->option('context'));
        $kubectl = $this->bulwarkKubectl($context);
        $ns = $this->bulwarkNamespace();
        $vpnOnly = (bool) $this->option('vpn-only');

        // Bulwark is a client for Stalwart — refuse if there's no Stalwart to
        // point it at, rather than deploy a webmail that can't reach a server.
        if (! $this->isMailInstalled($kubectl, $ns)) {
            $this->laraKubeError('Stalwart is not installed. Run `larakube mail:init` first — Bulwark is a webmail client for it.');

            return 1;
        }

        $mailHost = $this->resolveMailHostReadOnly($env, $config);
        if (! $mailHost) {
            $this->laraKubeError("No Stalwart host is configured for '{$env}'. Run `larakube mail:init {$env}` first.");

            return 1;
        }

        if ($vpnOnly && ! $this->ensureVpnMiddleware(ClusterTool::WEBMAIL, $kubectl)) {
            $this->laraKubeError('Failed to create the VPN-only Middleware — check kubectl access to the cluster above and re-run.');

            return 1;
        }

        $sessionSecret = $this->readBulwarkSecret($kubectl, $ns, 'WEBMAIL_SESSION_SECRET')
            ?? $this->readBulwarkSecret($kubectl, $ns, 'session-secret')
            ?? Str::random(48);
        $adminPassword = $this->readBulwarkSecret($kubectl, $ns, 'WEBMAIL_ADMIN_PASSWORD')
            ?? $this->readBulwarkSecret($kubectl, $ns, 'admin-password')
            ?? Str::random(24);
        $appName = (string) ($this->option('app-name') ?: 'Webmail');

        $this->withSpin("Ensuring namespace {$ns}...", fn () => Process::run(
            "{$kubectl} create namespace {$ns} --dry-run=client -o yaml | {$kubectl} apply -f -",
        ));

        $this->withSpin('Syncing secrets...', function () use ($kubectl, $ns, $sessionSecret, $adminPassword, $env) {
            Process::run(
                "{$kubectl} create secret generic webmail-secrets -n {$ns} "
                .'--from-literal=WEBMAIL_SESSION_SECRET='.escapeshellarg($sessionSecret).' '
                .'--from-literal=WEBMAIL_ADMIN_PASSWORD='.escapeshellarg($adminPassword).' '
                ."--dry-run=client -o yaml | {$kubectl} apply -f -",
            );

            if ($this->infisicalAvailable($kubectl)) {
                $infisicalEnv = $env === 'local' ? 'dev' : $env;
                $this->pushInfisicalSecret($kubectl, 'WEBMAIL_SESSION_SECRET', $sessionSecret, $infisicalEnv);
                $this->pushInfisicalSecret($kubectl, 'WEBMAIL_ADMIN_PASSWORD', $adminPassword, $infisicalEnv);
                $this->syncInfisicalToNamespace($kubectl, $ns, 'webmail-secrets', $infisicalEnv);
            }
        });

        $manifest = view('k8s.webmail.bulwark', [
            'host' => $host,
            'mailHost' => $mailHost,
            'appName' => $appName,
            'vpnOnly' => $vpnOnly,
            'isLocal' => $env === 'local',
        ])->render();

        $tmp = sys_get_temp_dir().'/larakube-webmail.yaml';
        file_put_contents($tmp, $manifest);

        $this->withSpin('Applying Bulwark manifests...', fn () => $this->runStreaming("{$kubectl} apply -f {$tmp}"));
        @unlink($tmp);

        $this->withSpin('Waiting for Bulwark...', fn () => $this->runStreaming(
            "{$kubectl} rollout status deploy/webmail-bulwark -n {$ns} --timeout=180s",
            190,
        ));

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
            $this->withSpin('Restarting Stalwart to apply CORS (brief mail blip)...', fn () => Process::run(
                "{$kubectl} rollout restart deployment/stalwart -n {$ns}",
            ));
            $this->withSpin('Waiting for Stalwart to come back...', fn () => $this->runStreaming(
                "{$kubectl} rollout status deployment/stalwart -n {$ns} --timeout=180s",
                190,
            ));
            $restarted = true;
        }

        $this->laraKubeNewLine();
        $this->laraKubeInfo('✅ Bulwark webmail is live.');
        $this->registerDeployedTool(ClusterTool::WEBMAIL, $kubectl, $host);
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

    protected function resolveWebmailHost(string $env): string
    {
        $service = SharedClusterService::WEBMAIL;

        $domain = (string) ($this->option('domain') ?? '');
        if ($domain !== '') {
            return $service->hostFor($domain);
        }

        if ($env === 'local') {
            return (string) $this->resolveBulwarkHostReadOnly('local', null);
        }

        return $this->promptForCloudWebmailHost($service, $env);
    }

    protected function resolveEnvironment(): string
    {
        return $this->resolveToolEnvironment(ClusterTool::WEBMAIL);
    }

    protected function promptForCloudWebmailHost(SharedClusterService $service, string $env): string
    {
        $projectPath = getcwd();
        $config = file_exists($projectPath.'/'.ConfigData::CONFIG_FILE)
            ? ConfigData::loadFromFile($projectPath)
            : null;

        $existing = $config?->getEnvironment($env)?->hosts[$service->value] ?? null;
        if ($existing) {
            return $existing;
        }

        $webHost = $config?->getEnvironment($env)?->hosts['web'] ?? null;
        $default = ($config && $webHost) ? $config->getSharedServiceHost($service, $env) : '';

        $host = text(
            label: "What host should {$service->label()} use in '{$env}'?",
            placeholder: $default !== '' ? $default : 'e.g. webmail.example.com',
            default: $default,
            required: true,
        );

        if ($config) {
            $config->setHost($env, $service->value, $host);
            $config->saveToFile($projectPath);
            $this->laraKubeInfo("Saved {$service->label()} host for '{$env}' to .larakube.json");
        }

        return $host;
    }
}
