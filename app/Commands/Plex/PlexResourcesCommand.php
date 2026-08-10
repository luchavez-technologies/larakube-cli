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
        {--context= : Target a specific kube-context (else: the project env context, or you are prompted)}';

    protected $description = 'Configure Kubernetes resource limits and storage for Commons services';

    public function handle(): int
    {
        $this->renderHeader();
        $this->laraKubeInfo('LaraKube Plex — Commons Resource Configuration');

        $config = $this->isLaraKubeProject(false) ? $this->getProjectConfig(getcwd()) : null;

        if ($this->option('context')) {
            $this->plexContext = (string) $this->option('context');
        } elseif ($config !== null) {
            $env = $this->resolvePlexEnvironment($config);
            $this->plexContext = $this->environmentContextOrCurrent($config, $env);
        } else {
            $target = $this->askForClusterContext();
            if (! $target) {
                $this->laraKubeError('No Kubernetes context selected.');

                return 1;
            }
            $this->plexContext = $target;
        }

        if (! $this->plexContextReachable()) {
            $this->laraKubeError('The selected cluster is not reachable.');

            return 1;
        }

        $spec = $this->getCommonsSpec();
        if ($spec === null) {
            $this->laraKubeError('No Commons found on this cluster. Run `larakube plex:init` first.');

            return 1;
        }

        $this->showResourceTable($spec);

        $enabled = $this->enabledCommonsServices($spec);
        if (empty($enabled)) {
            $this->laraKubeError('No services are enabled on this Commons.');

            return 1;
        }

        $service = select(
            label: 'Which Commons service do you want to configure?',
            options: array_combine($enabled, $enabled),
        );

        $driver = DatabaseDriver::tryFrom($service);
        $poolable = $driver?->supportsPooling() ?? false;

        $actionOptions = [
            'set' => 'Set or update resources',
            'reset' => 'Reset to Commons defaults',
        ];
        if ($poolable) {
            $actionOptions['pooler'] = 'Configure connection pooler (PgBouncer)';
        }

        $action = select(
            label: "What do you want to do with '{$service}'?",
            options: $actionOptions,
            default: 'set',
        );

        if ($action === 'pooler') {
            $spec['services'][$service] = $this->promptPoolerConfig($service, $spec['services'][$service]);
        } elseif ($action === 'reset') {
            $normalized = $this->normalizeCommonsSpec(['services' => []]);
            $defaults = $normalized['services'][$service] ?? [];
            if (isset($defaults['memory'])) {
                $spec['services'][$service]['memory'] = $defaults['memory'];
            }
            if (isset($defaults['storage'])) {
                $spec['services'][$service]['storage'] = $defaults['storage'];
            }
        } else {
            $current = $spec['services'][$service];

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

        $manifest = view('k8s.plex.commons', [
            'spec' => $spec,
            'specJsonIndented' => $this->indentedSpecJson($spec),
            'isLocal' => $this->targetsLocalCluster(),
        ])->render();

        $ns = $this->plexNamespace();
        $kubectl = $this->plexKubectl();

        $this->withSpin("Applying updated Commons manifests for '{$service}'...", function () use ($manifest, $ns, $kubectl) {
            $tmp = sys_get_temp_dir().'/larakube-plex-commons.yaml';
            file_put_contents($tmp, $manifest);
            $this->runStreaming("{$kubectl} apply -n {$ns} -f {$tmp}");
            @unlink($tmp);

            return true;
        });

        // Plain `apply` never prunes — disabling the pooler drops it from the
        // rendered manifest, but its Deployment/Services would otherwise sit
        // there running, unmanaged, until someone notices. The `postgres`
        // Service already stopped pointing at them the moment this applied;
        // this just stops them existing at all.
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
            $rows[] = [
                $name,
                $cfg['memory'] ?? '—',
                isset($cfg['storage']) ? $cfg['storage'] : '—',
                isset($cfg['pooler']) ? ($cfg['pooler']['enabled'] ? "on ({$cfg['pooler']['mode']})" : 'off') : '—',
            ];
        }

        table(['Service', 'Memory Limit', 'Storage', 'Pooler'], $rows);
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

    protected function indentedSpecJson(array $spec): string
    {
        $json = (string) json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return preg_replace('/^/m', '    ', $json);
    }
}
