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
use App\Contracts\HasPromptableHosts;
use App\Contracts\HasReloadCommand;
use App\Contracts\HasSelectOptions;
use App\Contracts\RequiresPhpExtensions;
use App\Data\ConfigData;
use App\Data\GlobalConfigData;
use App\Traits\DerivesHostsFromServices;
use App\Traits\GeneratesProjectInfrastructure;
use App\Traits\InteractsWithMeet;
use App\Traits\ProvidesCommandOptions;
use App\Traits\ProvidesSelectOptions;
use BackedEnum;
use Illuminate\Support\Facades\Process;

enum LaravelFeature: string implements HasArtisanCommands, HasAutoUsedComponents, HasCommandOptions, HasComposerDependencies, HasDependencies, HasEnvironmentVariables, HasHiddenComponents, HasHosts, HasJsDependencies, HasKubernetesFiles, HasLabel, HasLifecycleHooks, HasPodName, HasPromptableHosts, HasReloadCommand, HasSelectOptions, RequiresPhpExtensions
{
    use DerivesHostsFromServices, GeneratesProjectInfrastructure, InteractsWithMeet, ProvidesCommandOptions, ProvidesSelectOptions;

    public static function fromPodName(string $podName): ?self
    {
        return match ($podName) {
            'scheduler' => self::TASK_SCHEDULING,
            'horizon' => self::HORIZON,
            'queues' => self::QUEUES,
            'reverb' => self::REVERB,
            'node-ssr' => self::SSR,
            default => self::tryFrom($podName),
        };
    }

    public function getPodName(?ConfigData $config = null): string
    {
        return match ($this) {
            self::TASK_SCHEDULING => 'scheduler',
            self::QUEUES => 'queues',
            self::SSR => 'node-ssr',
            default => $this->value,
        };
    }

    public function getReloadCommand(): ?string
    {
        return match ($this) {
            self::HORIZON => 'php artisan horizon:terminate',
            self::QUEUES => 'php artisan queue:restart',
            default => null,
        };
    }

    /**
     * Whether this feature is enabled by default in the given environment
     * when listed in ConfigData::$features. Env-name-agnostic so it works
     * for any environment the user creates (staging, qa, …), not just the
     * conventional local/production pair:
     *
     *   - local-only tooling (boost, ai, mcp) → only 'local'
     *   - ssr → every cloud (non-local) env
     *   - everything else (horizon, queues, reverb, scheduler, …) → all envs
     *
     * Per-environment addFeatures/excludeFeatures on EnvironmentData override
     * this for unusual setups.
     */
    public function appliesToEnvironment(string $environment): bool
    {
        return match ($this) {
            self::BOOST, self::AI, self::MCP => $environment === 'local',
            self::SSR => $environment !== 'local',
            default => true,
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
            self::SSR => 'Inertia SSR (Server-Side Rendering)',
            self::AI => 'Laravel AI',
            self::MCP => 'Laravel MCP',
            self::BOOST => 'Laravel Boost',
            self::MEET => 'Video Meetings (LiveKit)',
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

        return false;
    }

    public static function getAutoUsedComponents(): array
    {
        return [];
    }

    public function getEnvironmentVariables(?ConfigData $config = null, string $environment = 'local'): array
    {
        return array_merge(
            $this->getPublicEnvironmentVariables($config, $environment),
            $this->getSecretEnvironmentVariables($config, $environment),
        );
    }

    public function getPublicEnvironmentVariables(?ConfigData $config = null, string $environment = 'local'): array
    {
        return match ($this) {
            self::REVERB => array_merge([
                'REVERB_APP_ID' => 'larakube',
                'REVERB_APP_KEY' => 'larakubekey',
                'REVERB_HOST' => $config ? $config->getInternalFqdn($this, $environment) : 'reverb',
                // These three describe the SERVER-side hop only: Laravel
                // publishing events to Reverb (config/broadcasting.php feeds
                // them to the client as host/port/scheme + useTLS). REVERB_HOST
                // above is the in-cluster FQDN in EVERY environment, and the
                // Service listens on plain HTTP :8080 — so the port and scheme
                // must match that, cloud included. TLS at 443 belongs to the
                // BROWSER hop, which is configured by VITE_REVERB_* instead;
                // pairing an internal host with 443/https made Laravel dial
                // https://reverb.<ns>.svc.cluster.local:443, where nothing
                // listens and no certificate exists.
                'REVERB_PORT' => '8080',
                'REVERB_SCHEME' => 'http',
            ], [
                // Browser → Reverb. The server-side REVERB_* above point Laravel
                // at the in-cluster ClusterIP; the browser can't reach that, so
                // Echo (import.meta.env.VITE_REVERB_*, read by @laravel/echo-*)
                // connects through the reverb ingress over WSS on 443 instead —
                // same shape in every env, since local and cloud both terminate
                // TLS at their ingress.
                //
                // Emitted for cloud too, not just local. The deploy paths append
                // these as Vite build args (InteractsWithRemoteDeploy), and a
                // duplicate key later in the file does win — but relying on that
                // left .env.{env} carrying a *.test host for a cloud env, which
                // tripped cloud:configure's own local-URL warning and would be
                // the value used by any build that skips those paths. The env
                // file should state the truth on its own.
                'VITE_REVERB_APP_KEY' => 'larakubekey',
                'VITE_REVERB_HOST' => $config ? $config->getServiceHost('reverb', $environment) : 'reverb',
                'VITE_REVERB_PORT' => '443',
                'VITE_REVERB_SCHEME' => 'https',
            ]),
            self::QUEUES => [
                'QUEUE_CONNECTION' => 'database',
            ],
            // Any non-local env runs SSR (production by default; users can
            // opt staging/qa in via addFeatures on EnvironmentData).
            self::SSR => $environment !== 'local' ? [
                'INERTIA_SSR_ENABLED' => 'true',
                'INERTIA_SSR_URL' => 'http://'.($config ? $config->getInternalFqdn($this, $environment) : 'node-ssr').':13714',
            ] : [],
            // Meet is a SHARED cluster tool, so the host is meet.<global tld>,
            // never meet.<project>.<tld> — getServiceHost() would wrongly scope
            // it to the project. The real deployed host is read from the tool
            // registry in onPostInstall(); this is the local-dev default.
            self::MEET => [
                'LIVEKIT_URL' => 'wss://meet.'.GlobalConfigData::load()->getLocalTld(),
                // OSS LiveKit cannot restrict a key to a room, so isolation
                // between apps sharing the SFU is this prefix and nothing else.
                // Mint tokens only for rooms under it, and drop webhook events
                // for rooms outside it. See docs/decisions/0009-*.md.
                'LIVEKIT_ROOM_PREFIX' => ($config?->getName() ?? 'app').'-',
            ],
            self::BOOST => [
                'BOOST_PHP_EXECUTABLE_PATH' => '"larakube php"',
                'BOOST_COMPOSER_EXECUTABLE_PATH' => '"larakube composer"',
                'BOOST_NPM_EXECUTABLE_PATH' => '"larakube npm"',
                // Boost concatenates this prefix directly onto the tool name with
                // no separator (binCommand: "{prefix}{tool}"), so it must be a
                // path prefix, not a command word — "larakube art" produced the
                // bogus `larakube artpint`. Mirror the default vendor/bin/ shape.
                'BOOST_VENDOR_BIN_EXECUTABLE_PATH' => '"larakube php vendor/bin/"',
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
            // Placeholders only. The real pair is allocated per project in the
            // meet-keys registry by onPostInstall(), which can reach the
            // cluster; this method is called from render and test paths that
            // must never do I/O.
            self::MEET => [
                'LIVEKIT_API_KEY' => '',
                'LIVEKIT_API_SECRET' => '',
            ],
            default => [],
        };
    }

    /**
     * Declarative list of ingress-exposed services per feature, used by the
     * env wizard to ask "do you want a custom subdomain for {label}?". Local
     * env-gating (e.g. Mailpit, Boost) is handled at the
     * appliesToEnvironment() layer so non-applicable features never get here.
     *
     * @return array<string, string>
     */
    public function getHostServices(): array
    {
        return match ($this) {
            self::REVERB => ['reverb' => 'Reverb WebSocket'],
            default => [],
        };
    }

    /**
     * Only Reverb is a client-facing endpoint worth a vanity subdomain
     * (ws.example.com).
     *
     * @return array<string, string>
     */
    public function getPromptableHostServices(): array
    {
        return match ($this) {
            self::REVERB => ['reverb' => 'Reverb WebSocket'],
            default => [],
        };
    }

    public function getDependencies(ConfigData $config, string $environment = 'local'): array
    {
        $deps = match ($this) {
            self::HORIZON => array_merge($config->getCoreDependencies($environment), [$config->getServerVariation(), CacheDriver::REDIS]),
            self::OCTANE => [ServerVariation::FRANKENPHP],
            self::QUEUES, self::TASK_SCHEDULING, self::REVERB => array_merge($config->getCoreDependencies($environment), [$config->getServerVariation()]),
            self::SSR => [$config->getServerVariation()],
            default => [],
        };

        // Drop services external to THIS env (managed / Plex Commons) — they don't
        // live in the app's namespace, so an in-namespace `nc <pod>` would never
        // resolve and the init container would wait forever (the app connects to
        // them directly on boot via .env).
        $managed = $config->getManaged($environment);

        return array_values(array_filter(
            $deps,
            fn ($dep) => $dep !== null && (! ($dep instanceof BackedEnum) || ! in_array($dep->value, $managed, true)),
        ));
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
            self::MEET => [
                'agence104/livekit-server-sdk',
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
        $values = $this->getEnvironmentVariables($context);

        if ($this === self::MEET) {
            $values = array_merge($values, $this->resolveMeetCredentials($context));
        }

        $this->syncEnvFile($projectPath, $values);
    }

    public function getPostInstallInstructions(?ConfigData $config = null): array
    {
        if (! $config) {
            return [];
        }

        return match ($this) {
            self::AI => $this->getAiInstructions($config),
            self::MEET => $this->getMeetInstructions($config),
            default => [],
        };
    }

    public function updateK8s(ConfigData $config): void
    {

        $k8sPath = $config->getK8sPath();
        $binaryPath = realpath($_SERVER['argv'][0]) ?: '/usr/local/bin/larakube';
        $workspacePath = dirname($config->getPath());

        if ($viewName = $this->getWorkloadViewName()) {
            // SSR is the one overlay-bound workload (it must run in cloud envs
            // but never locally), so it's written once per cloud env it
            // applies to. Every other workload lives in base/ and is shared.
            if ($this === self::SSR) {
                foreach ($config->getCloudEnvironments() as $env) {
                    if (! $this->appliesToEnvironment($env)) {
                        continue;
                    }
                    $dest = "overlays/{$env}/ssr-deployment.yaml";
                    if ($config->isLocked(".infrastructure/k8s/{$dest}")) {
                        continue;
                    }
                    @mkdir("$k8sPath/overlays/{$env}", 0755, true);
                    $content = view($viewName, [
                        'config' => $config,
                        'feature' => $this,
                        'environment' => $env,
                        'binaryPath' => $binaryPath,
                        'workspacePath' => $workspacePath,
                    ])->render();
                    file_put_contents("$k8sPath/{$dest}", $content);
                }
            } else {
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
            self::SSR => 'k8s.ssr.deployment',
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
            self::SSR => 'overlays/production/ssr-deployment.yaml',
            default => null,
        };
    }

    public function getNetworkViewName(): ?string
    {
        return match ($this) {
            // Reverb is the one client-facing workload: the browser needs a host
            // to dial for the WebSocket, so it gets a local ingress (cloud envs
            // expose it via tunnel / a hand-configured host instead).
            self::REVERB => 'k8s.reverb.ingress',
            default => null,
        };
    }

    public function getNetworkYamlDestination(): ?string
    {
        return match ($this) {
            self::REVERB => 'overlays/local/reverb-ingress.yaml',
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

    public function getManifestFiles(?ConfigData $config = null): array
    {
        $manifests = match ($this) {
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
                'local' => ['reverb-ingress.yaml'],
            ],
            self::SSR => [
                'cloud' => ['ssr-deployment.yaml'],
            ],
            default => [],
        };

        return $manifests;
    }

    /**
     * Allocate this project's own LiveKit key pair from the shared meet-keys
     * registry, and read the real deployed Meet host from the tool registry.
     *
     * Runs only from onPostInstall(), which is the single install-time hook —
     * the env accessors themselves must stay I/O-free because they are called
     * from render and test paths.
     *
     * Degrades to the declared placeholders when Meet is not installed: adding
     * the feature to a project should never fail because a cluster is absent.
     *
     * @return array<string, string>
     */
    protected function resolveMeetCredentials(?ConfigData $context): array
    {
        $kubectl = $this->meetKubectl();
        $ns = $this->meetNamespace();

        if (! $this->isMeetInstalled($kubectl, $ns)) {
            return [];
        }

        $consumer = 'app-'.($context?->getName() ?? 'app');
        $registry = $this->readMeetKeys($kubectl, $ns);
        $registry = $this->allocateMeetKey($registry, $consumer, ($context?->getName() ?? 'app').'-');
        $registry = $this->writeMeetKeys($kubectl, $ns, $registry);

        $values = [
            'LIVEKIT_API_KEY' => $registry[$consumer]['key'],
            'LIVEKIT_API_SECRET' => $registry[$consumer]['secret'],
        ];

        // Prefer the host Meet is actually serving over the local-dev guess.
        $host = trim(Process::run(
            "{$kubectl} get secret larakube-tools-registry -n {$ns} -o jsonpath=".escapeshellarg('{.data.registry\.json}'),
        )->output());

        if ($host !== '') {
            $decoded = json_decode((string) base64_decode($host), true);
            $meetHost = null;

            if (is_array($decoded)) {
                foreach ($decoded as $entry) {
                    if (($entry['tool'] ?? null) === 'meet' && ($entry['instance'] ?? 'main') === 'main') {
                        $meetHost = $entry['host'] ?? null;
                        break;
                    }
                }
            }

            if (is_string($meetHost) && $meetHost !== '') {
                $values['LIVEKIT_URL'] = "wss://{$meetHost}";
            }
        }

        return $values;
    }

    /**
     * @return array<int, string>
     */
    protected function getMeetInstructions(ConfigData $config): array
    {
        $prefix = $config->getName().'-';

        return [
            'Mint a join token server-side with Agence104\LiveKit\AccessToken —',
            "  scope every room name to \"{$prefix}…\" and refuse anything else.",
            '',
            'Two limits of the shared SFU, both by design in OSS LiveKit:',
            '  · An API key is NOT restricted to a room. Any valid key can mint a',
            "    token for any room, so \"{$prefix}\" is a convention your app must",
            '    enforce — it is not enforced by the server.',
            '  · Webhooks are signed with a single key and every event is sent to',
            '    every registered URL. Drop events whose room falls outside your',
            '    prefix, and expect to see other apps\' rooms if any are wired.',
        ];
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

    case TASK_SCHEDULING = 'scheduler';
    case HORIZON = 'horizon';
    case QUEUES = 'queues';
    case REVERB = 'reverb';
    case SCOUT = 'scout';
    case OCTANE = 'octane';
    case SSR = 'ssr';
    case AI = 'ai';
    case MCP = 'mcp';
    case BOOST = 'boost';
    case MEET = 'meet';
}
