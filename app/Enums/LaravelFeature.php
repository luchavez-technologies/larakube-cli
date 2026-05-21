<?php

namespace App\Enums;

use App\Contracts\HasArtisanCommands;
use App\Contracts\HasAutoUsedComponents;
use App\Contracts\HasCommandOptions;
use App\Contracts\HasComposerDependencies;
use App\Contracts\HasDependencies;
use App\Contracts\HasEnvironmentVariables;
use App\Contracts\HasHiddenComponents;
use App\Contracts\HasHosts;
use App\Contracts\HasJsDependencies;
use App\Contracts\HasKubernetesFiles;
use App\Contracts\HasLabel;
use App\Contracts\HasLifecycleHooks;
use App\Contracts\HasPodName;
use App\Contracts\HasSelectOptions;
use App\Contracts\RequiresPhpExtensions;
use App\Data\ConfigData;
use App\Traits\GeneratesProjectInfrastructure;
use App\Traits\ProvidesCommandOptions;
use App\Traits\ProvidesSelectOptions;

enum LaravelFeature: string implements HasArtisanCommands, HasAutoUsedComponents, HasCommandOptions, HasComposerDependencies, HasDependencies, HasEnvironmentVariables, HasHiddenComponents, HasHosts, HasJsDependencies, HasKubernetesFiles, HasLabel, HasLifecycleHooks, HasPodName, HasSelectOptions, RequiresPhpExtensions
{
    use GeneratesProjectInfrastructure, ProvidesCommandOptions, ProvidesSelectOptions;

    case TASK_SCHEDULING = 'scheduler';
    case HORIZON = 'horizon';
    case QUEUES = 'queues';
    case REVERB = 'reverb';
    case SCOUT = 'scout';
    case OCTANE = 'octane';
    case MONITORING = 'monitoring';
    case METALLB = 'metallb';
    case MAILPIT = 'mailpit';

    case AI = 'ai';
    case MCP = 'mcp';
    case BOOST = 'boost';

    public static function fromPodName(string $podName): ?self
    {
        return match ($podName) {
            'scheduler' => self::TASK_SCHEDULING,
            'horizon' => self::HORIZON,
            'queues' => self::QUEUES,
            'reverb' => self::REVERB,
            default => self::tryFrom($podName),
        };
    }

    public function getPodName(?ConfigData $config = null): string
    {
        return match ($this) {
            self::TASK_SCHEDULING => 'scheduler',
            self::QUEUES => 'queues',
            default => $this->value,
        };
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::QUEUES => 'Queues (without Redis)',
            self::TASK_SCHEDULING => 'Task Scheduling',
            self::HORIZON => 'Horizon (with Redis)',
            self::REVERB => 'Reverb',
            self::SCOUT => 'Scout',
            self::OCTANE => 'Octane (requires FrankenPHP)',
            self::AI => 'Laravel AI',
            self::MCP => 'Laravel MCP',
            self::BOOST => 'Laravel Boost',
            self::MONITORING => 'Monitoring (Prometheus & Grafana)',
            self::METALLB => 'MetalLB (LoadBalancer Provider)',
            self::MAILPIT => 'Mailpit (Local SMTP)',
        };
    }

    public function isHidden(?ConfigData $config = null): bool
    {
        // Octane is hidden if the user already chose FrankenPHP (it's mandatory)
        if ($this === self::OCTANE) {
            return $config?->getServerVariation() === ServerVariation::FRANKENPHP;
        }

        // Scout is hidden if the user already chose a search driver via flags
        if ($this === self::SCOUT) {
            return ! is_null($config?->getScoutDriver());
        }

        return match ($this) {
            self::MONITORING, self::METALLB, self::MAILPIT => true,
            default => false,
        };
    }

    public static function getAutoUsedComponents(): array
    {
        return [
            self::MAILPIT,
        ];
    }

    public function getEnvironmentVariables(?ConfigData $config = null, string $environment = 'local'): array
    {
        return array_merge(
            $this->getPublicEnvironmentVariables($config, $environment),
            $this->getSecretEnvironmentVariables($config, $environment)
        );
    }

    public function getPublicEnvironmentVariables(?ConfigData $config = null, string $environment = 'local'): array
    {
        return match ($this) {
            self::REVERB => [
                'REVERB_APP_ID' => 'larakube',
                'REVERB_APP_KEY' => 'larakubekey',
                'REVERB_HOST' => $config ? $config->getInternalFqdn($this, $environment) : 'reverb',
                'REVERB_PORT' => $environment === 'production' ? '443' : '8080',
                'REVERB_SCHEME' => $environment === 'production' ? 'https' : 'http',
            ],
            self::MAILPIT => $environment !== 'production' ? [
                'MAIL_MAILER' => 'smtp',
                'MAIL_HOST' => $config ? $config->getInternalFqdn($this, $environment) : $this->getPodName(),
                'MAIL_PORT' => '1025',
                'MAIL_USERNAME' => 'null',
                'MAIL_ENCRYPTION' => 'null',
            ] : [],
            self::QUEUES => [
                'QUEUE_CONNECTION' => 'database',
            ],
            self::BOOST => [
                'BOOST_PHP_EXECUTABLE_PATH' => '"larakube php"',
                'BOOST_COMPOSER_EXECUTABLE_PATH' => '"larakube composer"',
                'BOOST_NPM_EXECUTABLE_PATH' => '"larakube npm"',
                'BOOST_VENDOR_BIN_EXECUTABLE_PATH' => '"larakube art"',
            ],
            default => [],
        };
    }

    public function getSecretEnvironmentVariables(?ConfigData $config = null, string $environment = 'local'): array
    {
        return match ($this) {
            self::REVERB => [
                'REVERB_APP_SECRET' => 'larakubesecret',
            ],
            self::MAILPIT => $environment !== 'production' ? [
                'MAIL_PASSWORD' => 'null',
            ] : [],
            default => [],
        };
    }

    public function getHosts(ConfigData $config, string $environment = 'local'): array
    {
        $appName = $config->getName();

        return match ($this) {
            self::REVERB => [$config->getServiceHost('reverb', $environment) => 'Reverb Console'],
            self::MAILPIT => $environment !== 'production' ? [$config->getServiceHost('mailpit', $environment) => 'Mailpit Dashboard'] : [],
            self::MONITORING => [
                $config->getServiceHost('grafana', $environment) => 'Grafana Dashboard',
                $config->getServiceHost('prometheus', $environment) => 'Prometheus Dashboard',
            ],
            default => [],
        };
    }

    public function getDependencies(ConfigData $config): array
    {
        return match ($this) {
            self::HORIZON => array_merge($config->getCoreDependencies(), [$config->getServerVariation(), CacheDriver::REDIS]),
            self::OCTANE => [ServerVariation::FRANKENPHP],
            self::QUEUES, self::TASK_SCHEDULING, self::REVERB => array_merge($config->getCoreDependencies(), [$config->getServerVariation()]),
            default => [],
        };
    }

    public function k8sDeploymentArgs(): string
    {
        return match ($this) {
            self::HORIZON => '["php", "artisan", "horizon"]',
            self::TASK_SCHEDULING => '["php", "artisan", "schedule:run"]',
            self::QUEUES => '["php", "artisan", "queue:work"]',
            self::REVERB => '["php", "artisan", "reverb:start", "--host=0.0.0.0", "--port=8081"]',
            default => '[]',
        };
    }

    public function getComposerDependencies(?ConfigData $context = null): array
    {
        return match ($this) {
            self::HORIZON => [
                'laravel/horizon',
            ],
            self::SCOUT => [
                'laravel/scout',
            ],
            self::OCTANE => [
                'laravel/octane',
            ],
            self::REVERB => [
                'laravel/reverb',
            ],
            self::MCP => [
                'laravel/mcp',
            ],
            self::BOOST => [
                'laravel/boost',
            ],
            default => [],
        };
    }

    public function getArtisanCommands(?ConfigData $context = null): array
    {
        return match ($this) {
            self::HORIZON => [
                'horizon:install',
            ],
            self::OCTANE => [
                'octane:install --server=frankenphp',
            ],
            self::QUEUES => [
                'make:queue-batches-table',
                'make:queue-failed-table',
                'make:queue-table',
            ],
            self::REVERB => [
                'install:broadcasting --reverb --without-node',
            ],
            self::SCOUT => [
                'vendor:publish --provider="Laravel\Scout\ScoutServiceProvider"',
            ],
            self::AI => [
                'vendor:publish --provider="Laravel\Ai\AiServiceProvider"',
            ],
            self::MCP => [
                'vendor:publish --tag=ai-routes',
            ],
            self::BOOST => [
                'boost:install --guidelines --skills --mcp',
            ],
            default => [],
        };
    }

    public function getJsDependencies(?ConfigData $context = null): array
    {
        return match ($this) {
            self::REVERB => $this->getReverbJsCommands($context),
            default => [],
        };
    }

    public function getPhpExtensions(): array
    {
        return match ($this) {
            default => [],
        };
    }

    public function onPostInstall(string $projectPath, ?ConfigData $context = null): void
    {
        $this->syncEnvFile($projectPath, $this->getEnvironmentVariables($context));
    }

    public function getPostInstallInstructions(?ConfigData $config = null): array
    {
        if (! $config) {
            return [];
        }

        return match ($this) {
            self::AI => $this->getAiInstructions($config),
            default => [],
        };
    }

    protected function getAiInstructions(ConfigData $config): array
    {
        $hasPostgres = $config->getDatabase() === DatabaseDriver::POSTGRESQL ||
                       in_array(DatabaseDriver::POSTGRESQL, $config->getDatabases(), true);

        if ($hasPostgres) {
            return [];
        }

        return [
            '<fg=yellow;options=bold>💡 RECOMMENDATION:</> The Laravel AI SDK works best with PostgreSQL and <fg=cyan;options=bold>pgvector</>.',
            '   Consider adding it to your project: <fg=blue>larakube add postgres</>',
        ];
    }

    public function updateK8s(ConfigData $config): void
    {
        $k8sPath = $config->getK8sPath();
        $binaryPath = realpath($_SERVER['argv'][0]) ?: '/usr/local/bin/larakube';
        $workspacePath = dirname($config->getPath());

        if ($viewName = $this->getWorkloadViewName()) {
            $dest = $this->getWorkloadYamlDestination();
            if (! $config->isLocked(".infrastructure/k8s/{$dest}")) {
                $content = view($viewName, [
                    'config' => $config,
                    'feature' => $this,
                    'binaryPath' => $binaryPath,
                    'workspacePath' => $workspacePath,
                ])->render();
                file_put_contents("$k8sPath/{$dest}", $content);
            }
        }

        if ($viewName = $this->getNetworkViewName()) {
            $dest = $this->getNetworkYamlDestination();
            if (! $config->isLocked(".infrastructure/k8s/{$dest}")) {
                $network = view($viewName, [
                    'config' => $config,
                    'feature' => $this,
                    'binaryPath' => $binaryPath,
                    'workspacePath' => $workspacePath,
                ])->render();
                file_put_contents("$k8sPath/{$dest}", $network);
            }
        }

        if ($viewName = $this->getPatchViewName()) {
            $dest = $this->getPatchYamlDestination();
            if (! $config->isLocked(".infrastructure/k8s/{$dest}")) {
                $patch = view($viewName, [
                    'config' => $config,
                    'feature' => $this,
                    'binaryPath' => $binaryPath,
                    'workspacePath' => $workspacePath,
                ])->render();
                file_put_contents("$k8sPath/{$dest}", $patch);
            }
        }
    }

    public function getWorkloadViewName(): ?string
    {
        return match ($this) {
            self::TASK_SCHEDULING => 'k8s.scheduler.cronjob',
            self::HORIZON => 'k8s.horizon.deployment',
            self::QUEUES => 'k8s.queues.deployment',
            self::REVERB => 'k8s.reverb.deployment',
            self::MAILPIT => 'k8s.mailpit.deployment',
            self::MONITORING => 'k8s.monitoring.prometheus',
            default => null,
        };
    }

    public function getWorkloadYamlDestination(): ?string
    {
        return match ($this) {
            self::TASK_SCHEDULING => 'base/scheduler-cronjob.yaml',
            self::HORIZON => 'base/horizon-deployment.yaml',
            self::QUEUES => 'base/queues-deployment.yaml',
            self::REVERB => 'base/reverb-deployment.yaml',
            self::MAILPIT => 'overlays/local/mailpit.yaml',
            self::MONITORING => 'base/prometheus.yaml',
            default => null,
        };
    }

    public function getNetworkViewName(): ?string
    {
        return match ($this) {
            self::MONITORING => 'k8s.monitoring.grafana',
            default => null,
        };
    }

    public function getNetworkYamlDestination(): ?string
    {
        return match ($this) {
            self::MONITORING => 'base/grafana.yaml',
            default => null,
        };
    }

    public function getStorageViewName(): ?string
    {
        return null;
    }

    public function getStorageYamlDestination(): ?string
    {
        return null;
    }

    public function getPatchViewName(): ?string
    {
        return match ($this) {
            default => null,
        };
    }

    public function getPatchYamlDestination(): ?string
    {
        return match ($this) {
            default => null,
        };
    }

    public function getK8sDeploymentArgs(): string
    {
        return match ($this) {
            self::TASK_SCHEDULING => '["php", "artisan", "schedule:run"]',
            self::HORIZON => '["php", "artisan", "horizon"]',
            self::QUEUES => '["php", "artisan", "queue:work"]',
            self::REVERB => '["php", "artisan", "reverb:start", "--host=0.0.0.0", "--port=8081"]',
            default => '[]',
        };
    }

    public function getManifestFiles(): array
    {
        return match ($this) {
            self::TASK_SCHEDULING => [
                'base' => ['scheduler-cronjob.yaml'],
            ],
            self::HORIZON => [
                'base' => ['horizon-deployment.yaml'],
            ],
            self::QUEUES => [
                'base' => ['queues-deployment.yaml'],
            ],
            self::REVERB => [
                'base' => ['reverb-deployment.yaml'],
            ],
            self::MAILPIT => [
                'local' => ['mailpit.yaml'],
            ],
            self::MONITORING => [
                'base' => ['prometheus.yaml', 'grafana.yaml'],
            ],
            default => [],
        };
    }

    private function getReverbJsCommands(?ConfigData $context): array
    {
        $projectPath = $context?->getName() ? getcwd().'/'.$context->getName() : null;
        if (! $projectPath || ! file_exists($projectPath.'/package.json')) {
            return [];
        }

        $packageJson = json_decode(file_get_contents($projectPath.'/package.json'), true);
        $dependencies = array_merge($packageJson['dependencies'] ?? [], $packageJson['devDependencies'] ?? []);

        if (isset($dependencies['laravel-echo'])) {
            return [];
        }

        $jsPackages = ['laravel-echo', 'pusher-js'];
        $frontend = $context?->getFrontend();

        if ($frontend && $echoPkg = $frontend->echoPackage()) {
            $jsPackages[] = $echoPkg;
        } elseif (! $frontend) {
            // Fallback to legacy detection if frontend is none but packages are present
            if (isset($dependencies['react'])) {
                $jsPackages[] = '@laravel/echo-react';
            } elseif (isset($dependencies['vue'])) {
                $jsPackages[] = '@laravel/echo-vue';
            }
        }

        $pm = $context?->getPackageManager() ?? PackageManager::NPM;

        return [$pm->addDevCommand($jsPackages).' --ignore-scripts'];
    }
}
