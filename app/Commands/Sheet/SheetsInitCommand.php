<?php

namespace App\Commands\Sheet;

use App\Data\ConfigData;
use App\Enums\ClusterTool;
use App\Enums\SharedClusterService;
use App\Traits\ConfirmsDestructiveAction;
use App\Traits\DeploysClusterTool;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithPlex;
use App\Traits\InteractsWithSheet;
use App\Traits\LaraKubeOutput;
use App\Traits\ResolvesToolEnvironment;
use App\Traits\StreamsProcessOutput;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;

class SheetsInitCommand extends Command
{
    use ConfirmsDestructiveAction, DeploysClusterTool, InteractsWithClusterContext, InteractsWithPlex, InteractsWithSheet, LaraKubeOutput, ResolvesToolEnvironment, StreamsProcessOutput;

    protected $signature = 'sheets:init
        {environment? : Environment this install targets — "local" (default) or cloud.}
        {--context=  : Target a specific kube-context}
        {--engine=   : Sheet engine — "baserow" (default) or "nocodb"}
        {--domain=   : Base domain OR full host for Sheet (example.com → prefix.example.com)}
        {--no-plex   : Bypass Plex Commons and use local SQLite storage (nocodb only)}
        {--vpn-only  : Restrict access via NetBird VPN IP whitelisting}
        {--force     : Skip the confirmation prompt}';

    protected $description = 'Deploy the no-code database spreadsheet stack (Baserow or NocoDB) into larakube-shared';

    public function handle(): int
    {
        $this->renderHeader();

        return $this->deploySheet();
    }

    protected function deploySheet(): int
    {
        $engine = strtolower((string) ($this->option('engine') ?: 'baserow'));
        if (! in_array($engine, ['baserow', 'nocodb'], true)) {
            $this->laraKubeError("Unknown --engine '{$engine}'. Use 'baserow' (default) or 'nocodb'.");

            return 1;
        }

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

        if ($vpnOnly && ! $this->ensureVpnMiddleware(ClusterTool::SHEETS, $kubectl)) {
            $this->laraKubeError('Failed to create the VPN-only Middleware — check kubectl access to the cluster above and re-run.');

            return 1;
        }

        if ($engine === 'baserow' && $noPlex) {
            $this->laraKubeError('Baserow requires the Plex Commons (Postgres + Valkey). Drop --no-plex, or use --engine=nocodb for a standalone SQLite install.');

            return 1;
        }

        // Guard the toggle: if the other engine is already running, confirm the
        // switch (its pod is removed, its data kept) before deploying over it.
        $current = $this->deployedSheetEngine($kubectl, $ns);
        if ($current !== null && $current !== $engine) {
            $switch = (bool) $this->option('no-interaction') || confirm(
                label: "Sheet is currently running the '{$current}' engine. Switch to '{$engine}'?",
                default: false,
                hint: "The '{$current}' pod is removed; its data (Commons DB) is kept.",
            );

            if (! $switch) {
                $this->laraKubeInfo("Keeping the '{$current}' engine. Re-run with --engine={$current} to update it.");

                return 0;
            }

            $this->withSpin("Removing the '{$current}' engine pod...", fn () => Process::run(
                "{$kubectl} delete deployment/sheet-{$current} -n {$ns} --ignore-not-found",
            ));
        }

        return $engine === 'baserow'
            ? $this->deployBaserow($kubectl, $ns, $env, $host, $vpnOnly)
            : $this->deployNocodb($kubectl, $ns, $env, $host, $noPlex, $vpnOnly);
    }

    /** Baserow engine: Commons Postgres + a dedicated Commons Valkey DB index. */
    protected function deployBaserow(string $kubectl, string $ns, string $env, string $host, bool $vpnOnly): int
    {
        if (! $this->ensureCommons(['postgres', 'redis'])) {
            return 1;
        }

        // Keep secrets stable across re-runs: rotating SECRET_KEY/JWT would
        // invalidate every Baserow session and signed token.
        $dbPassword = $this->readSheetDbPassword($kubectl, $ns) ?? Str::random(24);
        $secretKey = $this->readSheetSecret($kubectl, $ns, 'secret-key') ?? Str::random(50);
        $jwtKey = $this->readSheetSecret($kubectl, $ns, 'jwt-key') ?? Str::random(50);

        if (! $this->allocateDatabase(\App\Enums\DatabaseDriver::POSTGRESQL, 'baserow', $dbPassword)) {
            return 1;
        }

        $redisIndex = $this->allocateCommonsRedisIndex('baserow');
        if ($redisIndex === null) {
            $this->laraKubeError('The Commons Valkey has no free logical DB index (all 16 in use).');

            return 1;
        }

        $this->withSpin("Ensuring namespace {$ns}...", fn () => Process::run(
            "{$kubectl} create namespace {$ns} --dry-run=client -o yaml | {$kubectl} apply -f -",
        ));

        $this->withSpin('Syncing secrets...', function () use ($kubectl, $ns, $dbPassword, $secretKey, $jwtKey) {
            Process::run(
                "{$kubectl} create secret generic sheet-secrets -n {$ns} "
                .'--from-literal=db-password='.escapeshellarg($dbPassword).' '
                .'--from-literal=secret-key='.escapeshellarg($secretKey).' '
                .'--from-literal=jwt-key='.escapeshellarg($jwtKey).' '
                ."--dry-run=client -o yaml | {$kubectl} apply -f -",
            );
        });

        $manifest = view('k8s.sheet.baserow', [
            'host' => $host,
            'plexNamespace' => $this->plexNamespace(),
            'redisIndex' => $redisIndex,
            'vpnOnly' => $vpnOnly,
            'isLocal' => $env === 'local',
        ])->render();

        $tmp = sys_get_temp_dir().'/larakube-sheet.yaml';
        file_put_contents($tmp, $manifest);

        $this->withSpin('Applying Sheet (Baserow) manifests...', fn () => $this->runStreaming("{$kubectl} apply -f {$tmp}"));
        @unlink($tmp);

        // Baserow runs its DB migrations on first boot — allow generous time.
        $this->withSpin('Waiting for Sheet (Baserow)...', fn () => $this->runStreaming(
            "{$kubectl} rollout status deploy/sheet-baserow -n {$ns} --timeout=300s",
            310,
        ));

        $this->laraKubeNewLine();
        $this->laraKubeInfo('✅ Sheet (Baserow) stack is live.');
        $this->newLine();
        $this->line("  <fg=gray>Access URL:</>              <fg=blue>https://{$host}</>");
        $this->line('  <fg=gray>Cache/queue:</>             <fg=blue>Commons Valkey</> · logical DB index <fg=blue>'.$redisIndex.'</>');
        $this->newLine();

        return 0;
    }

    /** NocoDB engine: Commons Postgres (or --no-plex SQLite on a PVC). */
    protected function deployNocodb(string $kubectl, string $ns, string $env, string $host, bool $noPlex, bool $vpnOnly): int
    {
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
        return $this->resolveToolEnvironment(ClusterTool::SHEETS);
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
