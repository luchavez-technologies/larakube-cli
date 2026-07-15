<?php

namespace App\Commands\Flow;

use App\Data\ConfigData;
use App\Enums\SharedClusterService;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithFlow;
use App\Traits\InteractsWithPlex;
use App\Traits\LaraKubeOutput;
use App\Traits\StreamsProcessOutput;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;

class FlowInitCommand extends Command
{
    use InteractsWithClusterContext, InteractsWithFlow, InteractsWithPlex, LaraKubeOutput, StreamsProcessOutput;

    protected $signature = 'flow:init
        {environment? : Environment this install targets — "local" (default) or cloud.}
        {--context=  : Target a specific kube-context}
        {--env=      : Legacy alias for the environment}
        {--domain=   : Raw override for the Flow cluster domain}
        {--no-plex   : Bypass Plex Commons and use local SQLite storage}
        {--vpn-only  : Restrict access via NetBird VPN IP whitelisting}
        {--remove    : Tear down the Flow stack}';

    protected $description = 'Deploy the workflow automation stack (n8n) into larakube-shared';

    public function handle(): int
    {
        $this->renderHeader();

        return $this->option('remove')
            ? $this->removeFlow()
            : $this->deployFlow();
    }

    protected function deployFlow(): int
    {
        $env = $this->resolveEnvironment();
        $host = $this->resolveFlowHost($env);

        $projectPath = getcwd();
        $config = file_exists($projectPath.'/'.ConfigData::CONFIG_FILE)
            ? ConfigData::loadFromFile($projectPath)
            : null;

        $context = (string) $this->option('context') ?: null;
        if (! $context && $config && $env !== 'local') {
            $context = $this->environmentContextOrCurrent($config, $env);
        }

        $this->plexContext = $context;
        $kubectl = $this->flowKubectl($context);
        $ns = $this->flowNamespace();
        $noPlex = (bool) $this->option('no-plex');
        $vpnOnly = (bool) $this->option('vpn-only');

        if (! $noPlex) {
            if (! $this->ensureCommons(['postgres'])) {
                return 1;
            }
        }

        $dbPassword = $this->readFlowDbPassword($kubectl, $ns) ?? Str::random(24);
        $encryptionKey = $this->readFlowEncryptionKey($kubectl, $ns) ?? Str::random(32);

        if (! $noPlex) {
            if (! $this->allocateDatabase(\App\Enums\DatabaseDriver::POSTGRESQL, 'n8n', $dbPassword)) {
                return 1;
            }
        }

        $this->withSpin("Ensuring namespace {$ns}...", fn () => Process::run(
            "{$kubectl} create namespace {$ns} --dry-run=client -o yaml | {$kubectl} apply -f -",
        ));

        $this->withSpin('Syncing secrets...', function () use ($kubectl, $ns, $encryptionKey, $dbPassword) {
            Process::run(
                "{$kubectl} create secret generic flow-secrets -n {$ns} "
                .'--from-literal=encryption-key='.escapeshellarg($encryptionKey).' '
                .'--from-literal=db-password='.escapeshellarg($dbPassword).' '
                ."--dry-run=client -o yaml | {$kubectl} apply -f -",
            );
        });

        $manifest = view('k8s.flow.shared', [
            'host' => $host,
            'dbPassword' => $dbPassword,
            'plexNamespace' => $this->plexNamespace(),
            'noPlex' => $noPlex,
            'vpnOnly' => $vpnOnly,
            'isLocal' => $env === 'local',
        ])->render();

        $tmp = sys_get_temp_dir().'/larakube-flow.yaml';
        file_put_contents($tmp, $manifest);

        $this->withSpin('Applying Flow (n8n) manifests...', fn () => $this->runStreaming("{$kubectl} apply -f {$tmp}"));
        @unlink($tmp);

        $this->withSpin('Waiting for Flow (n8n)...', fn () => $this->runStreaming(
            "{$kubectl} rollout status deploy/flow-n8n -n {$ns} --timeout=120s",
            130,
        ));

        $this->laraKubeNewLine();
        $this->laraKubeInfo('✅ Flow (n8n) stack is live.');
        $this->newLine();
        $this->line("  <fg=gray>Access URL:</>              <fg=blue>https://{$host}</>");

        $this->newLine();

        return 0;
    }

    protected function removeFlow(): int
    {
        $kubectl = $this->flowKubectl($this->option('context'));
        $ns = $this->flowNamespace();
        $plexNs = $this->plexNamespace();

        $isLocal = trim(Process::run("{$kubectl} get secret flow-secrets -n {$ns}")->output()) === '';

        if (! $isLocal) {
            $sql = $this->buildDropTenantSql('n8n', 'n8n');
            $tmp = tempnam(sys_get_temp_dir(), 'larakube_plex_drop_n8n');
            file_put_contents($tmp, $sql);
            $client = \App\Enums\DatabaseDriver::POSTGRESQL->commonsAdminClient();

            $this->withSpin("Dropping database 'n8n' from Plex Commons...", function () use ($plexNs, $client, $tmp, $kubectl) {
                return Process::run(
                    "{$kubectl} exec -i -n {$plexNs} deploy/postgres -- sh -c ".escapeshellarg($client).' < '.escapeshellarg($tmp),
                )->successful();
            });
            @unlink($tmp);
        }

        $this->withSpin('Removing Flow (n8n) resources...', fn () => Process::run(
            "{$kubectl} delete deployment/flow-n8n service/flow-n8n ingress/flow-n8n pvc/flow-storage secret/flow-secrets middleware/flow-vpn-only -n {$ns} --ignore-not-found",
        ));

        $this->laraKubeInfo('Flow (n8n) removed from larakube-shared.');

        return 0;
    }

    protected function resolveFlowHost(string $env): string
    {
        $service = SharedClusterService::FLOW;

        $domain = (string) ($this->option('domain') ?? '');
        if ($domain !== '') {
            return $service->hostFor($domain);
        }

        if ($env === 'local') {
            return (string) $this->resolveFlowHostReadOnly('local', null);
        }

        return $this->promptForCloudFlowHost($service, $env);
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
            label: 'Which environment is this Flow install for?',
            options: array_combine($envs, $envs),
            default: 'local',
        );
    }

    protected function promptForCloudFlowHost(SharedClusterService $service, string $env): string
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
            placeholder: $default !== '' ? $default : 'e.g. flow.example.com',
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
