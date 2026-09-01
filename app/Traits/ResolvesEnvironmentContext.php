<?php

namespace App\Traits;

use App\Data\ConfigData;
use App\Data\GlobalConfigData;
use App\Enums\DeploymentStrategy;
use App\Enums\ManagedProvider;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

/**
 * Resolve an environment's OWN kube-context (larakube-<ip>) from its saved cloud
 * target, so a command can run `kubectl --context <env>` and NEVER switch the
 * global context. Switching context as a step is discouraged — a command should
 * target the cluster its environment points at, regardless of what the user's
 * current context happens to be.
 *
 * The connection is prompted for + persisted once (environments.{env}.cloud), so
 * every later command (deploy, plex, …) reuses it. Shared by cloud:deploy and the
 * plex commands.
 *
 * The using class must also use LaraKubeOutput + InteractsWithProjectConfig.
 */
trait ResolvesEnvironmentContext
{
    /** The kube-context cloud:init creates for a host. Pure. */
    public function environmentContextName(string $ip): string
    {
        return 'larakube-'.$ip;
    }

    /** A `kubectl` prefix scoped to a context (or plain kubectl when null), pinned to ~/.kube/config. */
    public function contextKubectl(?string $context): string
    {
        $kubectl = 'KUBECONFIG='.escapeshellarg(home_path('.kube/config')).' kubectl';

        return $context !== null && $context !== ''
            ? $kubectl.' --context '.escapeshellarg($context)
            : $kubectl;
    }

    /**
     * Resolve [config, context] for an environment. If the env has no saved cloud
     * target yet, prompt for it once and persist it — so it's recorded for every
     * later command rather than relying on whatever context is currently active.
     *
     * @return array{0: ConfigData, 1: ?string}
     */
    protected function resolveEnvironmentContext(ConfigData $config, string $environment, string $projectPath): array
    {
        $cloud = $config->getCloud($environment);

        // A target is "saved" if it has either a VPS ip or a managed context.
        if (! $cloud || (! $cloud->ip && ! $cloud->context)) {
            $config = $this->captureCloudConnection($config, $environment, $projectPath);
        }

        return [$config, $this->environmentContextOrCurrent($config, $environment)];
    }

    /**
     * The env's context if it has a saved cloud target, else null (→ current
     * context). NON-prompting — for browse/run commands (logs, exec, shell, …)
     * that shouldn't interrupt to record a server. Local always → null, so local
     * behaviour is unchanged (plain kubectl against the current context).
     */
    protected function environmentContextOrCurrent(ConfigData $config, string $environment): ?string
    {
        if ($environment === 'local') {
            return null;
        }

        $cloud = $config->getCloud($environment);

        // Managed cluster → its stored kube-context wins. VPS → derive from ip.
        if ($cloud?->context) {
            return $cloud->context;
        }

        return $cloud?->ip ? $this->environmentContextName($cloud->ip) : null;
    }

    /**
     * environmentContextOrCurrent(), but an explicit --context option (when the
     * calling command defines one) always wins. For "local" this is the ONLY
     * way to avoid silently targeting kubectl's current context — which a
     * concurrently-running tool can flip out from under you. Found live
     * 2026-08-01: plex:init/plex:join for a "local" join landed on a
     * production droplet because another CLI tool switched the default
     * kubectl context mid-session; plex:leave/plex:destroy/plex:remove had no
     * override to route around it. The using command must declare `--context=`.
     */
    protected function contextOverrideOr(ConfigData $config, string $environment): ?string
    {
        $override = (string) ($this->option('context') ?: '');

        return $override !== '' ? $override : $this->environmentContextOrCurrent($config, $environment);
    }

    /** `kubectl` scoped to an env's context (plain kubectl for local / no target). */
    protected function environmentKubectl(ConfigData $config, string $environment): string
    {
        return $this->contextKubectl($this->environmentContextOrCurrent($config, $environment));
    }

    /** Is the env's context present + reachable, without touching the global one? */
    protected function environmentContextReachable(?string $context): bool
    {
        return Process::run($this->contextKubectl($context).' cluster-info --request-timeout=5s')->successful();
    }

    /**
     * Capture + persist the deploy target for an env when it isn't in the
     * blueprint yet. Saves environments.{env}.cloud and returns the reloaded config.
     */
    protected function captureCloudConnection(ConfigData $config, string $environment, string $projectPath): ConfigData
    {
        $this->laraKubeInfo("No deploy target saved for '{$environment}' yet — let's record it once.");

        return $this->promptCloudTarget($config, $environment, $projectPath);
    }

    /**
     * Prompt for + persist an environment's deploy target, OVERWRITING any
     * existing one. Pick an existing kube-context (a managed cluster, or a
     * provisioned `larakube-<ip>` VPS) or enter a new VPS by IP — so a managed
     * target can be recorded without hand-editing .larakube.json. Returns the
     * reloaded config. Used by both first-use auto-capture (captureCloudConnection)
     * and explicit reconfiguration (`cloud:configure`'s base step).
     */
    protected function promptCloudTarget(ConfigData $config, string $environment, string $projectPath): ConfigData
    {
        // Prefer picking an existing kube-context — that's the only way to record a
        // MANAGED cluster (DOKS/EKS/…, no IP), and it saves re-typing the IP of a
        // VPS you've already provisioned (its larakube-<ip> context is in kubeconfig).
        $contexts = $this->availableKubeContexts();
        if (! empty($contexts)) {
            $choice = select(
                label: "How is '{$environment}' reached?",
                options: array_merge(
                    array_combine($contexts, $contexts),
                    ['__new_vps__' => '➕ Enter a new server by IP (SSH)'],
                ),
            );

            if ($choice !== '__new_vps__') {
                $this->rememberGlobalEnvironmentContext($environment, $choice);

                return $this->recordContextTarget($config, $environment, $projectPath, $choice);
            }
        }

        // New VPS: capture the SSH target (needed for sideload deploy / provision).
        $ip = text(label: 'Server IP or host', required: true);
        $user = text(label: 'SSH user', default: 'larakube', required: true);
        $port = (int) text(label: 'SSH port', default: '22', required: true);
        $key = text(label: 'SSH private key path', default: home_path('.ssh/id_rsa'), required: true);
        $key = str_replace('~', home_path(), $key);

        $data = $config->toArray();
        $data['environments'][$environment]['cloud'] = [
            'ip' => trim($ip),
            'user' => trim($user),
            'port' => $port,
            'key' => $key,
        ];
        ConfigData::from($data)->saveToFile($projectPath);

        // A VPS is reached through its derived context, same as anywhere else.
        $this->rememberGlobalEnvironmentContext($environment, 'larakube-'.trim($ip));

        $this->laraKubeInfo("Saved to .larakube.local.json (environments.{$environment}.cloud) — future commands won't ask again.");

        return $this->getProjectConfig($projectPath);
    }

    /**
     * Mirror the answer into the machine-wide map.
     *
     * The project blueprint only helps a command run from inside that project.
     * Cluster tools are cluster-scoped and routinely run elsewhere — or nowhere
     * — so recording it globally is what lets `data:init production` resolve
     * the right cluster instead of silently using the current kube-context.
     */
    protected function rememberGlobalEnvironmentContext(string $environment, string $context): void
    {
        if ($environment === 'local' || $context === '' || $context === 'larakube-') {
            return;
        }

        $global = GlobalConfigData::load();

        if ($global->getEnvironmentContext($environment) !== $context) {
            $global->setEnvironmentContext($environment, $context)->save();
        }
    }

    /**
     * Persist a target chosen from an existing kube-context. A `larakube-<ip>`
     * context is a VPS we provisioned — derive the ip and capture SSH so sideload
     * deploys work. Anything else is a managed cluster — store the context name
     * only (no SSH).
     */
    protected function recordContextTarget(ConfigData $config, string $environment, string $projectPath, string $context): ConfigData
    {
        $data = $config->toArray();

        if (! preg_match('/^larakube-(.+)$/', $context, $m)) {
            // Managed cluster — identified by the context name; no SSH. Ask which
            // provider, then delegate to the shared managed writer.
            $provider = select(
                label: 'Which managed Kubernetes provider is this?',
                options: collect(ManagedProvider::cases())
                    ->mapWithKeys(fn (ManagedProvider $p) => [$p->value => $p->label()])
                    ->all(),
                default: ManagedProvider::DOKS->value,
            );

            return $this->recordManagedTarget($config, $environment, $projectPath, $context, ManagedProvider::from($provider));
        }

        // A LaraKube VPS context — derive the ip and capture SSH so sideload deploys work.
        $this->laraKubeInfo("Detected a LaraKube VPS context ({$m[1]}). Confirm its SSH details:");
        $user = text(label: 'SSH user', default: 'larakube', required: true);
        $port = (int) text(label: 'SSH port', default: '22', required: true);
        $key = text(label: 'SSH private key path', default: home_path('.ssh/id_rsa'), required: true);
        $key = str_replace('~', home_path(), $key);

        $data['environments'][$environment]['cloud'] = [
            'ip' => $m[1],
            'user' => trim($user),
            'port' => $port,
            'key' => $key,
        ];

        ConfigData::from($data)->saveToFile($projectPath);
        $this->laraKubeInfo("Saved to .larakube.local.json (environments.{$environment}.cloud) — future commands won't ask again.");

        return $this->getProjectConfig($projectPath);
    }

    /**
     * Persist a MANAGED deploy target ({context, provider}) for an environment and
     * default its storageClass from the provider — no provider prompt, for callers
     * that already know it (e.g. `cloud:init:doks`). Returns the reloaded config.
     */
    protected function recordManagedTarget(ConfigData $config, string $environment, string $projectPath, string $context, ManagedProvider $provider): ConfigData
    {
        $data = $config->toArray();
        $data['environments'][$environment]['cloud'] = ['context' => $context, 'provider' => $provider->value];

        $storageClass = $provider->defaultStorageClass();
        if ($storageClass !== null && empty($data['environments'][$environment]['storageClass'] ?? null)) {
            $data['environments'][$environment]['storageClass'] = $storageClass;
            $this->laraKubeInfo("Defaulted storageClass to '{$storageClass}' for {$provider->value}.");
        }

        // Derive the deployment strategy from the cluster's actual node count, so
        // multi-node manifests follow reality instead of a hand-edited guess (a
        // single-node strategy on a 2-node cluster is the shared-storage trap). Only
        // when the env hasn't pinned one explicitly; unreachable cluster → leave it.
        if (empty($data['environments'][$environment]['strategy'] ?? null)) {
            $nodes = $this->clusterNodeCount($context);
            if ($nodes >= 1) {
                $strategy = $nodes > 1 ? DeploymentStrategy::MULTI_NODE_HA->value : DeploymentStrategy::SINGLE_NODE->value;
                $data['environments'][$environment]['strategy'] = $strategy;
                $this->laraKubeInfo("Detected {$nodes} node(s) → set strategy to '{$strategy}'.");
            }
        }

        ConfigData::from($data)->saveToFile($projectPath);
        $this->laraKubeInfo("Saved to .larakube.local.json (environments.{$environment}.cloud).");

        return $this->getProjectConfig($projectPath);
    }

    /**
     * Let the user choose one of the project's cloud environments (production,
     * staging, …) by name — the env-first DX the cluster:* commands share so
     * nobody has to memorize namespaces or contexts. Auto-selects a lone env;
     * null when the project has none.
     */
    protected function pickEnvironment(ConfigData $config): ?string
    {
        $envs = $config->getCloudEnvironments();
        if (empty($envs)) {
            return null;
        }
        if (count($envs) === 1) {
            return $envs[0];
        }

        $options = [];
        foreach ($envs as $env) {
            $context = $this->environmentContextOrCurrent($config, $env);
            $options[$env] = $env.'  →  '.$config->getNamespace($env).'  ('.($context ?? 'current context').')';
        }

        return select(label: 'Which environment?', options: $options);
    }

    /**
     * Standalone (outside a project) kube-context selection — never silently
     * default to whatever kubectl happens to point at. An explicit --context
     * wins; otherwise let the user pick from the kubeconfig (current context
     * pre-selected). Auto-selects a lone context; null when there are none.
     */
    protected function pickContext(?string $explicit = null): ?string
    {
        if ($explicit !== null && $explicit !== '') {
            return $explicit;
        }

        $contexts = $this->availableKubeContexts();
        if (empty($contexts)) {
            return null;
        }
        if (count($contexts) === 1) {
            return $contexts[0];
        }

        $current = $this->currentKubeContext();

        return select(
            label: 'Which cluster (kube-context)?',
            options: array_combine($contexts, $contexts),
            default: in_array($current, $contexts, true) ? $current : $contexts[0],
        );
    }

    /**
     * Resolve [namespace, context] for a cluster:* command from its target arg.
     * In a project: environment-first (pick or name an env) → that env's namespace
     * and its OWN context, so the command acts on the right cluster regardless of
     * the current one. Standalone: a literal namespace + a context picker (never a
     * silent default). Emits its own errors and returns [null, null] when it can't
     * resolve, so callers just `if ($ns === null) return 1;`.
     *
     * @return array{0: ?string, 1: ?string}
     */
    protected function resolveClusterTarget(string $arg, ?string $explicitContext): array
    {
        $config = $this->getProjectConfig(getcwd());

        // Env-first inside a project (bare → pick; a known env name → use it).
        if ($config !== null && ($arg === '' || $config->getEnvironment($arg) !== null)) {
            $env = $arg !== '' ? $arg : $this->pickEnvironment($config);
            if ($env === null) {
                $this->laraKubeError('This project has no cloud environments yet — add one with `larakube env <name>`.');

                return [null, null];
            }

            $context = ($explicitContext !== null && $explicitContext !== '')
                ? $explicitContext
                : ($this->environmentContextOrCurrent($config, $env) ?? $this->currentKubeContext());

            return [$config->getNamespace($env), $context !== '' ? $context : null];
        }

        // Standalone, or an explicit literal namespace.
        if ($arg === '') {
            $this->laraKubeError('Provide a namespace, or run inside a project to pick an environment.');

            return [null, null];
        }

        $context = $this->pickContext($explicitContext);
        if ($context === null) {
            $this->laraKubeError('No kube-contexts found — is kubectl configured?');

            return [null, null];
        }

        return [$arg, $context];
    }

    /**
     * Resolve just a CONTEXT (no namespace) for cluster-wide actions like a full
     * teammate off-board. Env-first in a project (pick an env → its cluster);
     * standalone, a context picker. Null when nothing can be chosen.
     */
    protected function resolveClusterContext(?string $explicit): ?string
    {
        if ($explicit !== null && $explicit !== '') {
            return $explicit;
        }

        $config = $this->getProjectConfig(getcwd());
        if ($config !== null && ! empty($config->getCloudEnvironments())) {
            $env = $this->pickEnvironment($config);
            if ($env !== null) {
                $context = $this->environmentContextOrCurrent($config, $env)
                    ?? $this->currentKubeContext();

                return $context !== '' ? $context : null;
            }
        }

        return $this->pickContext(null);
    }

    /** Node count for a kube-context (0 when unreachable / unknown). */
    protected function clusterNodeCount(string $context): int
    {
        $out = trim(Process::run(
            $this->contextKubectl($context).' get nodes -o jsonpath='.escapeshellarg('{.items[*].metadata.name}'),
        )->output());

        return $out === '' ? 0 : count(preg_split('/\s+/', $out) ?: []);
    }

    /**
     * Kube-context names from the local kubeconfig (managed clusters + provisioned
     * VPSes). Empty when kubectl isn't installed / has no contexts.
     *
     * @return array<int, string>
     */
    protected function availableKubeContexts(): array
    {
        $lines = explode("\n", Process::run($this->contextKubectl(null).' config get-contexts -o name')->output());

        return array_values(array_filter(array_map('trim', $lines)));
    }

    /** The kubeconfig's currently active context, or '' when there isn't one. */
    protected function currentKubeContext(): string
    {
        return trim(Process::run($this->contextKubectl(null).' config current-context')->output());
    }
}
