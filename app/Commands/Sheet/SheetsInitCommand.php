<?php

namespace App\Commands\Sheet;

use App\Data\ConfigData;
use App\Enums\SharedClusterService;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithPlex;
use App\Traits\InteractsWithSheet;
use App\Traits\LaraKubeOutput;
use App\Traits\StreamsProcessOutput;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;

class SheetsInitCommand extends Command
{
    use InteractsWithClusterContext, InteractsWithPlex, InteractsWithSheet, LaraKubeOutput, StreamsProcessOutput;

    protected $signature = 'sheets:init
        {environment? : Environment this install targets — "local" (default) or cloud.}
        {--context=  : Target a specific kube-context}
        {--env=      : Legacy alias for the environment}
        {--domain=   : Raw override for the Sheet cluster domain}
        {--no-plex   : Bypass Plex Commons and use local SQLite storage}
        {--vpn-only  : Restrict access via NetBird VPN IP whitelisting}
        {--remove    : Tear down the Sheet stack}';

    protected $description = 'Deploy the no-code database spreadsheet stack (NocoDB) into larakube-shared';

    public function handle(): int
    {
        $this->renderHeader();

        return $this->option('remove')
            ? $this->removeSheet()
            : $this->deploySheet();
    }

    protected function deploySheet(): int
    {
        $env = $this->resolveEnvironment();
        $host = $this->resolveSheetHost($env);

        $projectPath = getcwd();
        $config = file_exists($projectPath.'/'.ConfigData::CONFIG_FILE)
            ? ConfigData::loadFromFile($projectPath)
            : null;

        $context = (string) $this->option('context') ?: null;
        if (! $context && $config && $env !== 'local') {
            $context = $this->environmentContextOrCurrent($config, $env);
        }

        $this->plexContext = $context;
        $kubectl = $this->sheetKubectl($context);
        $ns = $this->sheetNamespace();
        $noPlex = (bool) $this->option('no-plex');
        $vpnOnly = (bool) $this->option('vpn-only');

        if (! $noPlex) {
            if (! $this->ensureCommons(['postgres'])) {
                return 1;
            }
        }

        $dbPassword = $this->readSheetDbPassword($kubectl, $ns) ?? Str::random(24);

        if (! $noPlex) {
            if (! $this->allocateDatabase(\App\Enums\DatabaseDriver::POSTGRESQL, 'nocodb', $dbPassword)) {
                return 1;
            }
        }

        $this->withSpin("Ensuring namespace {$ns}...", fn () => Process::run(
            "{$kubectl} create namespace {$ns} --dry-run=client -o yaml | {$kubectl} apply -f -",
        ));

        $this->withSpin('Syncing secrets...', function () use ($kubectl, $ns, $dbPassword) {
            Process::run(
                "{$kubectl} create secret generic sheet-secrets -n {$ns} "
                .'--from-literal=db-password='.escapeshellarg($dbPassword).' '
                ."--dry-run=client -o yaml | {$kubectl} apply -f -",
            );
        });

        $manifest = view('k8s.sheet.shared', [
            'host' => $host,
            'dbPassword' => $dbPassword,
            'plexNamespace' => $this->plexNamespace(),
            'noPlex' => $noPlex,
            'vpnOnly' => $vpnOnly,
            'isLocal' => $env === 'local',
        ])->render();

        $tmp = sys_get_temp_dir().'/larakube-sheet.yaml';
        file_put_contents($tmp, $manifest);

        $this->withSpin('Applying Sheet (NocoDB) manifests...', fn () => $this->runStreaming("{$kubectl} apply -f {$tmp}"));
        @unlink($tmp);

        $this->withSpin('Waiting for Sheet (NocoDB)...', fn () => $this->runStreaming(
            "{$kubectl} rollout status deploy/sheet-nocodb -n {$ns} --timeout=120s",
            130,
        ));

        $this->laraKubeNewLine();
        $this->laraKubeInfo('✅ Sheet (NocoDB) stack is live.');
        $this->newLine();
        $this->line("  <fg=gray>Access URL:</>              <fg=blue>https://{$host}</>");
        $this->newLine();

        return 0;
    }

    protected function removeSheet(): int
    {
        $kubectl = $this->sheetKubectl($this->option('context'));
        $ns = $this->sheetNamespace();
        $plexNs = $this->plexNamespace();

        $isLocal = trim(Process::run("{$kubectl} get secret sheet-secrets -n {$ns}")->output()) === '';

        if (! $isLocal) {
            $sql = $this->buildDropTenantSql('nocodb', 'nocodb');
            $tmp = tempnam(sys_get_temp_dir(), 'larakube_plex_drop_nocodb');
            file_put_contents($tmp, $sql);
            $client = \App\Enums\DatabaseDriver::POSTGRESQL->commonsAdminClient();

            $this->withSpin("Dropping database 'nocodb' from Plex Commons...", function () use ($plexNs, $client, $tmp, $kubectl) {
                return Process::run(
                    "{$kubectl} exec -i -n {$plexNs} deploy/postgres -- sh -c ".escapeshellarg($client).' < '.escapeshellarg($tmp),
                )->successful();
            });
            @unlink($tmp);
        }

        $this->withSpin('Removing Sheet (NocoDB) resources...', fn () => Process::run(
            "{$kubectl} delete deployment/sheet-nocodb service/sheet-nocodb ingress/sheet-nocodb pvc/sheet-storage secret/sheet-secrets -n {$ns} --ignore-not-found",
        ));

        $this->laraKubeInfo('Sheet (NocoDB) removed from larakube-shared.');

        return 0;
    }

    protected function resolveSheetHost(string $env): string
    {
        $service = SharedClusterService::SHEET;

        $domain = (string) ($this->option('domain') ?? '');
        if ($domain !== '') {
            return $service->hostFor($domain);
        }

        if ($env === 'local') {
            return (string) $this->resolveSheetHostReadOnly('local', null);
        }

        return $this->promptForCloudSheetHost($service, $env);
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
            label: 'Which environment is this Sheet install for?',
            options: array_combine($envs, $envs),
            default: 'local',
        );
    }

    protected function promptForCloudSheetHost(SharedClusterService $service, string $env): string
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
            placeholder: $default !== '' ? $default : 'e.g. sheet.example.com',
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
