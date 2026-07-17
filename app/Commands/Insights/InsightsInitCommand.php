<?php

namespace App\Commands\Insights;

use App\Data\ConfigData;
use App\Enums\SharedClusterService;
use App\Traits\DeploysClusterTool;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithInsights;
use App\Traits\InteractsWithPlex;
use App\Traits\LaraKubeOutput;
use App\Traits\StreamsProcessOutput;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;

class InsightsInitCommand extends Command
{
    use DeploysClusterTool, InteractsWithClusterContext, InteractsWithInsights, InteractsWithPlex, LaraKubeOutput, StreamsProcessOutput;

    protected $signature = 'insights:init
        {environment? : Environment this install targets — "local" (default) or cloud.}
        {--context=  : Target a specific kube-context}
        {--env=      : Legacy alias for the environment}
        {--domain=   : Raw override for the Insights cluster domain}
        {--no-plex   : Bypass Plex Commons and deploy a dedicated database}
        {--vpn-only  : Restrict access via NetBird VPN IP whitelisting}
        {--remove    : Tear down the Insights stack}';

    protected $description = 'Deploy the Metabase BI stack into larakube-shared';

    public function handle(): int
    {
        $this->renderHeader();

        return $this->option('remove')
            ? $this->removeInsights()
            : $this->deployInsights();
    }

    protected function deployInsights(): int
    {
        $env = $this->resolveEnvironment();
        $host = $this->resolveInsightsHost($env);

        $projectPath = getcwd();
        $config = file_exists($projectPath.'/'.ConfigData::CONFIG_FILE)
            ? ConfigData::loadFromFile($projectPath)
            : null;

        $context = (string) $this->option('context') ?: null;
        if (! $context && $config && $env !== 'local') {
            $context = $this->environmentContextOrCurrent($config, $env);
        }

        $this->plexContext = $context;
        $kubectl = $this->insightsKubectl($context);
        $ns = $this->insightsNamespace();
        $noPlex = (bool) $this->option('no-plex');
        $vpnOnly = (bool) $this->option('vpn-only');

        if (! $noPlex) {
            if (! $this->ensureCommons(['postgres'])) {
                return 1;
            }
        }

        $dbPassword = $this->readInsightsDbPassword($kubectl, $ns) ?? Str::random(24);
        $encryptionKey = $this->readInsightsEncryptionKey($kubectl, $ns) ?? Str::random(64);

        if (! $noPlex) {
            if (! $this->allocateDatabase(\App\Enums\DatabaseDriver::POSTGRESQL, 'metabase', $dbPassword)) {
                return 1;
            }
        }

        $this->withSpin("Ensuring namespace {$ns}...", fn () => Process::run(
            "{$kubectl} create namespace {$ns} --dry-run=client -o yaml | {$kubectl} apply -f -",
        ));

        $this->withSpin('Syncing secrets...', function () use ($kubectl, $ns, $dbPassword, $encryptionKey) {
            Process::run(
                "{$kubectl} create secret generic insights-secrets -n {$ns} "
                .'--from-literal=db-password='.escapeshellarg($dbPassword).' '
                .'--from-literal=encryption-key='.escapeshellarg($encryptionKey).' '
                ."--dry-run=client -o yaml | {$kubectl} apply -f -",
            );
        });

        $manifest = view('k8s.insights.shared', [
            'host' => $host,
            'dbPassword' => $dbPassword,
            'plexNamespace' => $this->plexNamespace(),
            'noPlex' => $noPlex,
            'vpnOnly' => $vpnOnly,
            'isLocal' => $env === 'local',
        ])->render();

        $tmp = sys_get_temp_dir().'/larakube-insights.yaml';
        file_put_contents($tmp, $manifest);

        $this->withSpin('Applying Insights (Metabase) manifests...', fn () => $this->runStreaming("{$kubectl} apply -f {$tmp}"));
        @unlink($tmp);

        $this->withSpin('Waiting for Insights (Metabase)...', fn () => $this->runStreaming(
            "{$kubectl} rollout status deploy/insights-metabase -n {$ns} --timeout=120s",
            130,
        ));

        $this->laraKubeNewLine();
        $this->laraKubeInfo('✅ Insights (Metabase) stack is live.');
        $this->newLine();
        $this->line("  <fg=gray>Access URL:</>              <fg=blue>https://{$host}</>");
        $this->newLine();

        return 0;
    }

    protected function removeInsights(): int
    {
        $env = $this->resolveEnvironment();
        $context = $this->resolveToolContext($env, $this->option('context'));
        $this->plexContext = $context;
        $kubectl = $this->insightsKubectl($context);
        $ns = $this->insightsNamespace();
        $plexNs = $this->plexNamespace();

        $ok = true;
        $isLocal = trim(Process::run("{$kubectl} get secret insights-secrets -n {$ns}")->output()) === '';

        if (! $isLocal) {
            $sql = $this->buildDropTenantSql('metabase', 'metabase');
            $tmp = tempnam(sys_get_temp_dir(), 'larakube_plex_drop_metabase');
            file_put_contents($tmp, $sql);
            $client = \App\Enums\DatabaseDriver::POSTGRESQL->commonsAdminClient();

            $ok = $this->removeResources(
                "Dropping database 'metabase' from Plex Commons...",
                "{$kubectl} exec -i -n {$plexNs} deploy/postgres -- sh -c ".escapeshellarg($client).' < '.escapeshellarg($tmp),
            );
            @unlink($tmp);
        }

        $ok = $this->removeResources(
            'Removing Insights (Metabase) resources...',
            "{$kubectl} delete deployment/insights-metabase service/insights-metabase ingress/insights-metabase secret/insights-secrets pvc/insights-storage -n {$ns} --ignore-not-found",
        ) && $ok;

        if (! $ok) {
            $this->laraKubeError('One or more Insights resources failed to remove — check kubectl access to the cluster above and re-run.');

            return 1;
        }

        $this->laraKubeInfo('Insights (Metabase) removed from larakube-shared.');

        return 0;
    }

    protected function resolveInsightsHost(string $env): string
    {
        $service = SharedClusterService::INSIGHTS;

        $domain = (string) ($this->option('domain') ?? '');
        if ($domain !== '') {
            return $service->hostFor($domain);
        }

        if ($env === 'local') {
            return (string) $this->resolveInsightsHostReadOnly('local', null);
        }

        return $this->promptForCloudInsightsHost($service, $env);
    }

    protected function resolveEnvironment(): string
    {
        $explicit = (string) ($this->argument('environment') ?: $this->option('env') ?: '');
        if ($explicit !== '') {
            return $explicit;
        }

        if ($this->option('no-interaction') || $this->option('domain')) {
            return 'local';
        }

        $projectPath = getcwd();
        $config = file_exists($projectPath.'/'.ConfigData::CONFIG_FILE)
            ? ConfigData::loadFromFile($projectPath)
            : null;

        $envs = $config ? array_merge(['local'], $config->getCloudEnvironments()) : ['local'];

        return select(
            label: 'Which environment is this Insights install for?',
            options: array_combine($envs, $envs),
            default: 'local',
        );
    }

    protected function promptForCloudInsightsHost(SharedClusterService $service, string $env): string
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
            placeholder: $default !== '' ? $default : 'e.g. insights.example.com',
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

    protected function readInsightsEncryptionKey(string $kubectl, string $ns): ?string
    {
        $out = trim(Process::run(
            "{$kubectl} get secret insights-secrets -n {$ns} -o jsonpath='{.data.encryption-key}'",
        )->output());

        return $out !== '' ? (string) base64_decode($out) : null;
    }
}
