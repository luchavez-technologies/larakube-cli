<?php

namespace App\Commands\Plex;

use App\Data\ConfigData;
use App\Enums\DatabaseDriver;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithPlex;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use App\Traits\ResolvesEnvironmentContext;
use App\Traits\StreamsProcessOutput;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;
use function Laravel\Prompts\table;
use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;

class PlexResourcesCommand extends Command
{
    use InteractsWithClusterContext, InteractsWithPlex, InteractsWithProjectConfig, LaraKubeOutput, ResolvesEnvironmentContext, StreamsProcessOutput;

    protected $signature = 'plex:resources
        {environment? : Environment whose Commons to configure — "local" (default) or a cloud environment. Omit to be prompted (used only inside a project).}
        {--service= : Target specific Commons service (e.g. postgres, redis, seaweedfs)}
        {--memory= : Memory limit (e.g. 512Mi, 1Gi, 2Gi)}
        {--cpu= : CPU limit (e.g. 500m, 1000m, 2000m)}
        {--storage= : Storage PVC size (e.g. 10Gi, 20Gi)}
        {--max-connections= : PostgreSQL max_connections limit}
        {--maxclients= : Redis maxclients limit}
        {--pooler= : Enable or disable PgBouncer pooler ("on" / "off")}
        {--pool-mode= : PgBouncer pool mode ("transaction" or "session")}
        {--pool-size= : PgBouncer default pool size}
        {--max-clients= : PgBouncer max client connections}
        {--reset : Reset target service to Commons defaults}
        {--json : Output result as JSON}
        {--context= : Target a specific kube-context (else: the project env context, or you are prompted)}';

    protected $description = 'Configure Kubernetes resource limits, connection tuning and storage for Commons services';

    public function handle(): int
    {
        $isJson = (bool) $this->option('json');

        if (! $isJson) {
            $this->renderHeader();
            $this->laraKubeInfo('LaraKube Plex — Commons Resource Configuration');
        }

        $config = $this->isLaraKubeProject(false) ? $this->getProjectConfig(getcwd()) : null;

        if ($this->option('context')) {
            $this->plexContext = (string) $this->option('context');
        } elseif ($config !== null) {
            $env = $this->resolvePlexEnvironment($config);
            $this->plexContext = $this->environmentContextOrCurrent($config, $env);
        } else {
            $target = $this->askForClusterContext();
            if (! $target) {
                if ($isJson) {
                    $this->line((string) json_encode(['success' => false, 'error' => 'No Kubernetes context selected.']));
                } else {
                    $this->laraKubeError('No Kubernetes context selected.');
                }

                return 1;
            }
            $this->plexContext = $target;
        }

        if (! $this->plexContextReachable()) {
            if ($isJson) {
                $this->line((string) json_encode(['success' => false, 'error' => 'The selected cluster is not reachable.']));
            } else {
                $this->laraKubeError('The selected cluster is not reachable.');
            }

            return 1;
        }

        $spec = $this->getCommonsSpec();
        if ($spec === null) {
            if ($isJson) {
                $this->line((string) json_encode(['success' => false, 'error' => 'No Commons found on this cluster. Run `larakube plex:init` first.']));
            } else {
                $this->laraKubeError('No Commons found on this cluster. Run `larakube plex:init` first.');
            }

            return 1;
        }

        $spec = $this->normalizeCommonsSpec($spec);

        if (! $isJson) {
            $this->showResourceTable($spec);
        }

        $enabled = $this->enabledCommonsServices($spec);
        if (empty($enabled)) {
            if ($isJson) {
                $this->line((string) json_encode(['success' => false, 'error' => 'No services are enabled on this Commons.']));
            } else {
                $this->laraKubeError('No services are enabled on this Commons.');
            }

            return 1;
        }

        $service = (string) ($this->option('service') ?: '');
        if ($service !== '') {
            if (! in_array($service, $enabled, true)) {
                if ($isJson) {
                    $this->line((string) json_encode(['success' => false, 'error' => "Service '{$service}' is not enabled on this Commons."]));
                } else {
                    $this->laraKubeError("Service '{$service}' is not enabled on this Commons.");
                }

                return 1;
            }
        } else {
            $service = select(
                label: 'Which Commons service do you want to configure?',
                options: array_combine($enabled, $enabled),
            );
        }

        $driver = DatabaseDriver::tryFrom($service);
        $poolable = $driver?->supportsPooling() ?? false;

        $hasFlags = $this->option('reset')
            || $this->option('cpu') !== null
            || $this->option('memory') !== null
            || $this->option('storage') !== null
            || $this->option('max-connections') !== null
            || $this->option('maxclients') !== null
            || $this->option('pooler') !== null;

        $action = 'set';

        if ($hasFlags) {
            if ($this->option('reset')) {
                $action = 'reset';
                $normalized = $this->normalizeCommonsSpec(['services' => []]);
                $defaults = $normalized['services'][$service] ?? [];
                foreach (['memory', 'storage', 'cpu', 'max_connections', 'shared_buffers', 'maxclients', 'maxmemory_policy', 'timeout'] as $k) {
                    if (isset($defaults[$k])) {
                        $spec['services'][$service][$k] = $defaults[$k];
                    } else {
                        unset($spec['services'][$service][$k]);
                    }
                }
                if (isset($defaults['pooler'])) {
                    $spec['services'][$service]['pooler'] = $defaults['pooler'];
                }
            } else {
                if ($this->option('cpu') !== null) {
                    $cpu = (string) $this->option('cpu');
                    if (! ConfigData::isValidQuantity($cpu)) {
                        $this->laraKubeError("Invalid Kubernetes quantity for CPU: {$cpu}");

                        return 1;
                    }
                    $spec['services'][$service]['cpu'] = $cpu;
                }

                if ($this->option('memory') !== null) {
                    $memory = (string) $this->option('memory');
                    if (! ConfigData::isValidQuantity($memory)) {
                        $this->laraKubeError("Invalid Kubernetes quantity for Memory: {$memory}");

                        return 1;
                    }
                    $spec['services'][$service]['memory'] = $memory;
                }

                if ($this->option('storage') !== null && isset($spec['services'][$service]['storage'])) {
                    $storage = (string) $this->option('storage');
                    if (! ConfigData::isValidQuantity($storage)) {
                        $this->laraKubeError("Invalid Kubernetes quantity for Storage: {$storage}");

                        return 1;
                    }
                    $spec['services'][$service]['storage'] = $storage;
                }

                if ($this->option('max-connections') !== null && $service === 'postgres') {
                    $spec['services'][$service]['max_connections'] = (int) $this->option('max-connections');
                }

                if ($this->option('maxclients') !== null && $service === 'redis') {
                    $spec['services'][$service]['maxclients'] = (int) $this->option('maxclients');
                }

                if ($this->option('pooler') !== null && $poolable) {
                    $action = 'pooler';
                    $val = strtolower((string) $this->option('pooler'));
                    $poolerEnabled = in_array($val, ['on', 'true', '1', 'enable', 'yes'], true);

                    $currentPooler = $spec['services'][$service]['pooler'] ?? ['enabled' => false, 'mode' => 'transaction', 'poolSize' => 20, 'maxClients' => 400];
                    $currentPooler['enabled'] = $poolerEnabled;

                    if ($this->option('pool-mode') !== null) {
                        $currentPooler['mode'] = (string) $this->option('pool-mode');
                    }
                    if ($this->option('pool-size') !== null) {
                        $currentPooler['poolSize'] = (int) $this->option('pool-size');
                    }
                    if ($this->option('max-clients') !== null) {
                        $currentPooler['maxClients'] = (int) $this->option('max-clients');
                    }

                    $spec['services'][$service]['pooler'] = $currentPooler;
                }
            }
        } else {
            $actionOptions = [
                'set' => 'Set or update resource limits (CPU, Memory, Storage)',
                'reset' => 'Reset to Commons defaults',
            ];
            if ($poolable) {
                $actionOptions['pooler'] = 'Configure connection pooler (PgBouncer)';
            }
            if ($service === 'postgres') {
                $actionOptions['connections'] = 'Tune engine max_connections';
            } elseif ($service === 'redis') {
                $actionOptions['tuning'] = 'Tune Redis clients & eviction';
            }

            $action = select(
                label: "What do you want to do with '{$service}'?",
                options: $actionOptions,
                default: 'set',
            );

            if ($action === 'pooler') {
                $spec['services'][$service] = $this->promptPoolerConfig($service, $spec['services'][$service]);
            } elseif ($action === 'connections') {
                $currentConn = $spec['services'][$service]['max_connections'] ?? 200;
                $maxConn = text(
                    label: 'PostgreSQL max_connections',
                    placeholder: (string) $currentConn,
                    default: '',
                    required: false,
                    hint: "Current: {$currentConn}. Raise to allow more concurrent tenant connections.",
                );
                if ($maxConn !== '' && ctype_digit($maxConn)) {
                    $spec['services'][$service]['max_connections'] = (int) $maxConn;
                }
            } elseif ($action === 'tuning') {
                $currentClients = $spec['services'][$service]['maxclients'] ?? 10000;
                $maxClients = text(
                    label: 'Redis maxclients limit',
                    placeholder: (string) $currentClients,
                    default: '',
                    required: false,
                    hint: "Current: {$currentClients}.",
                );
                if ($maxClients !== '' && ctype_digit($maxClients)) {
                    $spec['services'][$service]['maxclients'] = (int) $maxClients;
                }

                $spec['services'][$service]['maxmemory_policy'] = select(
                    label: 'Eviction policy',
                    options: [
                        'allkeys-lru' => 'allkeys-lru (Evict least recently used keys — recommended)',
                        'volatile-lru' => 'volatile-lru (Evict keys with expire set)',
                        'noeviction' => 'noeviction (Reject writes when full)',
                    ],
                    default: $spec['services'][$service]['maxmemory_policy'] ?? 'allkeys-lru',
                );
            } elseif ($action === 'reset') {
                $normalized = $this->normalizeCommonsSpec(['services' => []]);
                $defaults = $normalized['services'][$service] ?? [];
                foreach (['memory', 'storage', 'cpu', 'max_connections', 'shared_buffers', 'maxclients', 'maxmemory_policy', 'timeout'] as $k) {
                    if (isset($defaults[$k])) {
                        $spec['services'][$service][$k] = $defaults[$k];
                    } else {
                        unset($spec['services'][$service][$k]);
                    }
                }
                if (isset($defaults['pooler'])) {
                    $spec['services'][$service]['pooler'] = $defaults['pooler'];
                }
            } else {
                $current = $spec['services'][$service];

                $cpu = $this->promptQuantity(
                    label: 'CPU Limit',
                    current: $current['cpu'] ?? '500m',
                    hint: 'e.g. 500m, 1000m, 2000m',
                );
                if ($cpu !== '') {
                    $spec['services'][$service]['cpu'] = $cpu;
                }

                $memory = $this->promptQuantity(
                    label: 'Memory Limit',
                    current: $current['memory'] ?? '—',
                    hint: 'e.g. 512Mi, 1Gi, 2Gi',
                );
                if ($memory !== '') {
                    $spec['services'][$service]['memory'] = $memory;
                }

                if (isset($current['storage'])) {
                    $storage = $this->promptQuantity(
                        label: 'Storage Size (PVC)',
                        current: $current['storage'],
                        hint: 'e.g. 10Gi, 20Gi — shrinking requires manual PVC resize',
                    );
                    if ($storage !== '') {
                        $spec['services'][$service]['storage'] = $storage;
                    }
                }
            }
        }

        if (! $this->applyCommons($spec, "Applying updated Commons manifests for '{$service}'...")) {
            if ($isJson) {
                $this->line((string) json_encode(['success' => false, 'error' => "Failed to apply updated manifests for '{$service}'."]));
            }

            return 1;
        }

        $ns = $this->plexNamespace();
        $kubectl = $this->plexKubectl();

        $poolerNowOff = $action === 'pooler' && ! ($spec['services'][$service]['pooler']['enabled'] ?? false);
        if ($poolerNowOff) {
            $this->withSpin('Removing PgBouncer (pooler disabled)...', function () use ($kubectl, $ns) {
                $this->runStreaming("{$kubectl} delete deploy/pgbouncer svc/pgbouncer svc/".DatabaseDriver::POSTGRESQL->poolerPrimaryServiceName()." -n {$ns} --ignore-not-found");

                return true;
            });
        }

        $rolloutTarget = ($action === 'pooler' && ! $poolerNowOff) ? 'pgbouncer' : $service;
        if (! $poolerNowOff) {
            $this->withSpin("Waiting for {$rolloutTarget} to roll out...", fn () => $this->runStreaming(
                "{$kubectl} rollout status deploy/{$rolloutTarget} -n {$ns} --timeout=120s",
                130,
            ));
        }

        if ($isJson) {
            $this->line((string) json_encode([
                'success' => true,
                'service' => $service,
                'spec' => $spec['services'][$service],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return 0;
        }

        $this->laraKubeInfo("✅ Commons '{$service}' updated successfully.");
        $this->newLine();
        $this->showResourceTable($spec);

        return 0;
    }

    protected function showResourceTable(array $spec): void
    {
        $rows = [];
        foreach ($spec['services'] as $name => $cfg) {
            if (! ($cfg['enabled'] ?? false)) {
                continue;
            }
            $maxConn = '—';
            if ($name === 'postgres' && isset($cfg['max_connections'])) {
                $maxConn = "{$cfg['max_connections']} conn";
            } elseif ($name === 'redis' && isset($cfg['maxclients'])) {
                $maxConn = "{$cfg['maxclients']} clients";
            }

            $rows[] = [
                $name,
                $cfg['cpu'] ?? '500m',
                $cfg['memory'] ?? '—',
                isset($cfg['storage']) ? $cfg['storage'] : '—',
                isset($cfg['pooler']) ? ($cfg['pooler']['enabled'] ? "on ({$cfg['pooler']['mode']})" : 'off') : '—',
                $maxConn,
            ];
        }

        table(['Service', 'CPU Limit', 'Memory Limit', 'Storage', 'Pooler', 'Max Conn/Clients'], $rows);
    }

    /**
     * Toggle/tune the PgBouncer sub-key for a poolable service. Enabling is a
     * real cutover, not a resource tweak — plans/active/commons-connection-pooling.md
     * flags transaction mode (the only mode wired here) as breaking session
     * state (SET, LISTEN/NOTIFY, temp tables, session-level prepared
     * statements) for anything that relies on it, so this asks explicitly
     * rather than treating it like a memory-limit bump.
     */
    protected function promptPoolerConfig(string $service, array $current): array
    {
        $pooler = $current['pooler'] ?? ['enabled' => false, 'mode' => 'transaction', 'poolSize' => 20, 'maxClients' => 400];

        if (! $pooler['enabled']) {
            $this->laraKubeWarn('Transaction-mode pooling breaks session state for anything that relies on it: SET, advisory locks, LISTEN/NOTIFY, temp tables, session-level prepared statements.');
            $this->line('  Audit every tenant on this Commons for LISTEN/NOTIFY use before enabling — Windmill is the most likely one to rely on it.');
            $this->newLine();

            if (! confirm("Enable the connection pooler for '{$service}'?", default: false)) {
                return $current;
            }
            $pooler['enabled'] = true;
        } elseif (! confirm("Pooler is on for '{$service}'. Keep it enabled?", default: true)) {
            $pooler['enabled'] = false;

            return [...$current, 'pooler' => $pooler];
        }

        $pooler['mode'] = select(
            label: 'Pool mode',
            options: ['transaction' => 'Transaction (default — most connection savings)', 'session' => 'Session (safer for LISTEN/NOTIFY, temp tables — pools far less)'],
            default: $pooler['mode'],
        );

        $poolSize = text(label: 'Default pool size (server connections per tenant)', placeholder: (string) $pooler['poolSize'], default: '', required: false);
        if ($poolSize !== '' && ctype_digit($poolSize)) {
            $pooler['poolSize'] = (int) $poolSize;
        }

        $maxClients = text(label: 'Max client connections', placeholder: (string) $pooler['maxClients'], default: '', required: false);
        if ($maxClients !== '' && ctype_digit($maxClients)) {
            $pooler['maxClients'] = (int) $maxClients;
        }

        return [...$current, 'pooler' => $pooler];
    }

    protected function promptQuantity(string $label, string $current, string $hint): string
    {
        while (true) {
            $val = text(
                label: $label,
                placeholder: 'leave blank to keep current ('.$current.')',
                default: '',
                required: false,
                hint: $hint,
            );

            if ($val === '') {
                return '';
            }

            if (ConfigData::isValidQuantity($val)) {
                return $val;
            }

            $this->laraKubeError("Invalid Kubernetes quantity: {$val}. Use formats like 512Mi, 1Gi, 10Gi.");
        }
    }
}
