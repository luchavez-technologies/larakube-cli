<?php

namespace App\Commands;

use App\Data\ConfigData;
use App\Traits\InteractsWithEnvironments;
use App\Traits\InteractsWithMonitoring;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use App\Traits\ManagesCompanions;
use App\Traits\ReadsPlexCredentials;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\table;

use LaravelZero\Framework\Commands\Command;

class AboutCommand extends Command
{
    use InteractsWithEnvironments, InteractsWithMonitoring, InteractsWithProjectConfig, LaraKubeOutput, ManagesCompanions, ReadsPlexCredentials;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'about {environment=local : The environment to check}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Display a unified architectural and health overview of the project';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->renderHeader();

        if (! $this->isLaraKubeProject()) {
            return 1;
        }

        $environment = $this->argument('environment');
        $config = $this->getProjectConfig(getcwd());
        $namespace = $this->getNamespace($environment, $config->getName());

        // 1. Architectural DNA
        $this->laraKubeInfo('Architectural DNA');
        $blueprintLabels = array_map(fn ($b) => $b->getLabel(), $config->getBlueprints());

        $dnaRows = [
            ['<fg=gray>Name</>', $config->getName()],
            ['<fg=gray>UUID</>', $config->getId()],
            ['<fg=gray>Blueprints</>', implode(', ', $blueprintLabels) ?: 'Standard Laravel'],
            ['<fg=gray>Runtime</>', $config->getServerVariation()?->getLabel()],
            ['<fg=gray>PHP / OS</>', "{$config->getPhpVersion()?->value} ({$config->getOs()?->getLabel()})"],
            ['<fg=gray>Primary DB</>', $config->getDatabase()?->getLabel()],
            ['<fg=gray>Primary Cache</>', $config->getCacheDriver()?->getLabel()],
            ['<fg=gray>Primary Storage</>', $config->getObjectStorage()?->getLabel() ?? 'None'],
            ['<fg=gray>Primary Search</>', $config->getScoutDriver()?->getLabel() ?? 'None'],
            ['<fg=gray>Features</>', implode(', ', array_map(fn ($f) => $f->getLabel(), $config->getFeatures())) ?: 'None'],
        ];

        table(['Property', 'Configuration'], $dnaRows);

        // 2. Environments Overview
        $this->newLine();
        $this->laraKubeInfo('Environments');

        $envRows = [];
        foreach ($config->getEnvironments() as $env) {
            $envData = $config->getEnvironment($env);
            $cloud = $envData?->cloud;

            if ($env === 'local') {
                $type = 'Local';
            } elseif ($cloud?->isManaged()) {
                $provider = strtoupper($cloud->provider ?? 'Managed');
                $type = $cloud->context ? "{$provider} ({$cloud->context})" : $provider;
            } elseif ($cloud?->ip) {
                $type = "VPS ({$cloud->ip})";
            } else {
                $type = '<fg=gray>—</>';
            }

            $strategy = $config->getStrategy($env);
            $strategyLabel = match ($strategy->value) {
                'single-node' => 'Single-Node',
                'multi-node-ha' => 'Multi-Node HA',
                default => $strategy->value,
            };

            $ingress = $config->getIngress($env);

            $registry = $envData?->registry;
            $registryLabel = $registry
                ? match ($registry->provider->value) {
                    'ghcr' => 'GHCR',
                    'dockerhub' => 'Docker Hub',
                    default => $registry->provider->value,
                }
            : '<fg=gray>—</>';

            $hostCount = count($envData?->hosts ?? []);
            $hostsLabel = $hostCount > 0 ? (string) $hostCount : '<fg=gray>—</>';

            $envRows[] = [
                $env === $environment ? "<options=bold>{$env}</>" : $env,
                $type,
                $strategyLabel,
                $ingress->getLabel(),
                $registryLabel,
                $hostsLabel,
            ];
        }

        table(['Env', 'Type', 'Strategy', 'Ingress', 'Registry', 'Hosts'], $envRows);

        // 3. Live Cluster Status
        $this->newLine();
        $this->laraKubeInfo("Live Cluster Status ($environment)");

        $output = Process::run("kubectl get pods -n {$namespace} -o json")->output();
        $pods = $output !== '' ? (json_decode($output, true)['items'] ?? []) : [];

        if (empty($pods)) {
            $this->warn('  No active pods found in this environment. Run "larakube up" to deploy.');
        } else {
            $statusRows = [];
            foreach ($pods as $pod) {
                $name = $pod['metadata']['labels']['app'] ?? $pod['metadata']['name'];
                $status = $this->getPodStatus($pod);
                $restarts = $this->getPodRestarts($pod);
                $age = $this->getPodAge($pod);

                $statusLabel = $status === 'Running' ? 'Ready 🟢' : "{$status} 🔴";

                $statusRows[] = [
                    $name,
                    $statusLabel,
                    (string) $restarts,
                    $age,
                ];
            }

            table(['Service', 'Status', 'Restarts', 'Age'], $statusRows);
        }

        // 4. Project URLs
        $this->newLine();
        if (! $this->showServiceLinks($config, $environment)) {
            $this->laraKubeInfo('Active Service Links');
            $this->line('  <fg=gray>No external hosts configured.</>');
        }

        // 5. Companion apps (shared, larakube-companions) — the real DB/cache/search
        // consoles, with per-project connection details. Local only; no-ops otherwise.
        $this->showCompanionAccess($config, $config->getName(), $environment);

        // 6. Monitoring stack (cluster-wide, larakube-shared) — shown only when
        // installed, so projects without monitoring don't see an empty section.
        $this->showMonitoringAccess($environment, $config);

        // 7. Plex Commons credentials this project joined (read from the env's
        // .env file). Only shown when the project is a tenant for this env.
        $this->showPlexCredentials($config, $environment);

        // 8. One-time architectural steps (e.g. MinIO's bucket-creation walkthrough)
        // — the same ones `new`/`up` print, resurfaced here so they're not lost.
        $this->showArchitecturalInstructions($config);

        return 0;
    }

    /**
     * Render the cluster-wide monitoring stack's URLs + Grafana credentials when
     * monitoring is installed. Silent no-op otherwise, so it only appears when
     * there's something to show — another place to recover the Grafana password.
     */
    protected function showMonitoringAccess(string $environment, ConfigData $config): void
    {
        $access = $this->monitoringAccess($environment, $config);

        if ($access === null) {
            return;
        }

        $this->newLine();
        $this->laraKubeInfo('Monitoring');

        $grafana = $access['host'] ? "<fg=blue>https://{$access['host']}</>" : '<fg=gray>host not configured</>';
        $login = $access['password'] !== null ? "admin / {$access['password']}" : '<fg=gray>unknown</>';

        table(['Component', 'Access'], [
            ['Grafana', $grafana],
            ['Grafana login', $login],
            ['Prometheus', $access['prometheus'].' <fg=gray>(in-cluster)</>'],
            ['Loki', $access['loki'].' <fg=gray>(in-cluster)</>'],
        ]);
    }

    /**
     * Render the Plex Commons credentials this project joined for the env (DB,
     * Redis, S3) — read from .env / .env.{env}. Silent no-op when the project
     * isn't a Plex tenant for this env, so it only appears when relevant. This is
     * another place to recover the joined Commons secrets, alongside plex:status.
     */
    protected function showPlexCredentials(ConfigData $config, string $environment): void
    {
        $creds = $this->plexTenantCredentials($config, getcwd(), $environment);

        if ($creds === []) {
            return;
        }

        $this->newLine();
        $this->laraKubeInfo("Plex Commons ({$environment})");

        $sectionLabels = ['database' => 'Database', 'redis' => 'Redis', 's3' => 'S3'];
        $rows = [];
        foreach ($creds as $section => $pairs) {
            foreach ($pairs as $key => $value) {
                $rows[] = [($sectionLabels[$section] ?? ucfirst($section)).' '.$key, $value];
            }
        }

        table(['Commons resource', 'Detail'], $rows);
    }

    protected function getPodStatus(array $pod): string
    {
        $phase = $pod['status']['phase'];

        if ($phase === 'Running') {
            $containerStatuses = $pod['status']['containerStatuses'] ?? [];
            foreach ($containerStatuses as $cs) {
                if (! $cs['ready']) {
                    return 'Initializing';
                }
            }
        }

        return $phase;
    }

    protected function getPodRestarts(array $pod): int
    {
        $restarts = 0;
        $containerStatuses = $pod['status']['containerStatuses'] ?? [];
        foreach ($containerStatuses as $cs) {
            $restarts += $cs['restartCount'];
        }

        return $restarts;
    }

    protected function getPodAge(array $pod): string
    {
        $startTime = strtotime($pod['metadata']['creationTimestamp']);
        $diff = time() - $startTime;

        if ($diff < 60) {
            return $diff.'s';
        }
        if ($diff < 3600) {
            return round($diff / 60).'m';
        }
        if ($diff < 86400) {
            return round($diff / 3600).'h';
        }

        return round($diff / 86400).'d';
    }
}
