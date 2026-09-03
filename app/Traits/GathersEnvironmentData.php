<?php

namespace App\Traits;

use App\Data\ConfigData;
use App\Data\EnvironmentData;
use App\Data\GlobalConfigData;
use App\Data\RegistryData;
use App\Enums\IngressController;
use App\Enums\RegistryProvider;
use App\Enums\SharedClusterService;
use Illuminate\Support\Facades\Process;
use InvalidArgumentException;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

/**
 * The single wizard for an environment's cloud-facing settings — ingress,
 * externally-managed services, client-facing hosts, and container registry.
 * `larakube env` (fresh environments) and `cloud:configure` (an environment
 * created on demand at configure-time) both drive through this trait so an
 * env created either way ends up identical. Requires PromptsForHosts +
 * InteractsWithProjectConfig on the composing class for gatherEnvironmentData(),
 * and LaraKubeOutput (for getGhCommand()) for promptRegistry().
 */
trait GathersEnvironmentData
{
    use ReadsCommandOptions;

    /**
     * Prompt for (or re-confirm) an env's ingress controller. Defaults to
     * whatever the env already has (Traefik for a brand-new one), so this
     * doubles as both the "create" and "update" prompt. `--ingress=` skips
     * the prompt; an unknown slug throws (caught at the command boundary).
     */
    protected function gatherEnvironmentIngress(ConfigData $config, string $envName): IngressController
    {
        $options = IngressController::getSelectOptions($config);

        if ($flag = $this->flag('ingress')) {
            if (! isset($options[$flag])) {
                throw new InvalidArgumentException("Invalid --ingress '{$flag}'. Use one of: ".implode(', ', array_keys($options)).'.');
            }

            return IngressController::from($flag);
        }

        $selected = select(
            label: "Which Ingress Controller will {$envName} use?",
            options: $options,
            default: $config->getIngress($envName)->value,
        );

        return IngressController::from($selected);
    }

    /**
     * Prompt for which backing services are externally managed in this env
     * (AWS RDS, ElastiCache, Meilisearch Cloud, S3, …). Returns [] with no
     * prompt when the project has nothing manageable to offer.
     *
     * @return array<int, string>
     */
    protected function gatherEnvironmentManaged(ConfigData $config, string $envName): array
    {
        $managedOptions = $config->getManageableServices();

        // `--managed=a,b` skips the prompt; `--managed=` (empty) means none.
        if (($flag = $this->flag('managed')) !== null) {
            $chosen = array_values(array_unique(array_filter(array_map('trim', explode(',', $flag)))));
            $invalid = array_diff($chosen, array_keys($managedOptions));
            if ($invalid !== []) {
                throw new InvalidArgumentException(
                    "Invalid --managed service(s): '".implode("', '", $invalid)."'."
                    .(empty($managedOptions) ? ' This project has no manageable services.' : ' Use any of: '.implode(', ', array_keys($managedOptions)).'.'),
                );
            }

            return $chosen;
        }

        if (empty($managedOptions)) {
            return [];
        }

        return multiselect(
            label: "Which services are managed externally in {$envName} (e.g. AWS RDS, ElastiCache, Meilisearch Cloud, S3)?",
            options: $managedOptions,
            default: $config->getManaged($envName),
            hint: 'Selected services will be skipped in this env\'s manifests.',
        );
    }

    /**
     * The full environment wizard: ingress, managed services, client-facing
     * hosts, and (for non-local envs) a container registry. All optional —
     * defaults produce an env that uses Traefik, deploys everything locally,
     * and has no external web host. Every sub-prompt defaults to the env's
     * CURRENT value when one already exists (via $config, already loaded with
     * it), so calling this again on an existing environment doubles as a
     * review/edit flow — `larakube env {name} --edit` does exactly this,
     * prefilled instead of blank.
     */
    protected function gatherEnvironmentData(ConfigData $config, string $envName): EnvironmentData
    {
        $envData = new EnvironmentData;
        $envData->ingress = $this->gatherEnvironmentIngress($config, $envName);
        $envData->managed = $this->gatherEnvironmentManaged($config, $envName);

        // Client-facing hosts — the optional web host plus any HasPromptableHosts
        // service overrides (Reverb WS, object-storage S3/CDN). Shared via
        // PromptsForHosts so the bundle installer and other flows reuse one wizard.
        // Admin consoles aren't prompted; they get a derived ingress host.
        $currentHosts = $config->getEnvironment($envName)?->hosts ?? [];
        foreach ($this->promptForHosts($envName, $this->resolveEnvComponents($config, $envName, $envData), $currentHosts) as $service => $host) {
            $envData->hosts[$service] = $host;
        }

        $envData->additionalWebHosts = $this->gatherAdditionalWebHosts($config, $envName);

        // Container registry: optional. Only relevant for cloud environments.
        // Defaults to true when one's already configured, so re-running this
        // (e.g. via --edit) naturally offers to review it rather than
        // requiring an extra opt-in on top of already having one.
        if ($envName !== 'local') {
            $hasRegistry = $config->getEnvironment($envName)?->registry !== null;
            $configureRegistry = confirm(
                label: $hasRegistry
                    ? "Review/update the container registry for {$envName}?"
                    : "Configure a container registry for {$envName}?",
                default: $hasRegistry,
                hint: 'Required for GitHub Actions CI/CD (push to GHCR or Docker Hub)',
            );

            if ($configureRegistry) {
                $envData->registry = $this->promptRegistry($config, $envName, required: false, envData: $envData);
            }
        }

        return $envData;
    }

    /**
     * Prompt for extra hostnames that route to the SAME web pod as the
     * primary — a Laravel app using subdomain route groups
     * (https://laravel.com/docs/routing#route-group-subdomain-routing), or
     * just a second domain. Purely additive/optional, so this is never
     * called from EnsuresRealHosts::ensureHosts() (the deploy-time guard,
     * which stays fast and only re-prompts when the PRIMARY host is
     * missing/placeholder/local) — only from setup contexts (`larakube env`,
     * `cloud:configure`, `cloud:configure --only=hosts`). Pre-fills with
     * whatever's already configured, so re-running doesn't lose entries.
     *
     * @return array<int, string>
     */
    protected function gatherAdditionalWebHosts(ConfigData $config, string $envName): array
    {
        $current = $config->getEnvironment($envName)?->additionalWebHosts ?? [];

        // `--web-hosts=a,b` skips the prompt; `--web-hosts=` (empty) clears.
        $input = $this->flag('web-hosts') ?? text(
            label: "Any additional hostnames for {$envName}'s web pod? (optional, comma-separated)",
            placeholder: 'admin.example.com, api.example.com',
            default: implode(', ', $current),
            hint: 'For a Laravel app using subdomain route groups, or just a second domain — all route to the same pod as the primary web host.',
            required: false,
        );

        return array_values(array_unique(array_filter(array_map('trim', explode(',', $input)))));
    }

    /**
     * Prompt for a container registry provider + image repository path.
     * Defaults to whatever's already configured for this env when one
     * exists (so this doubles as a review/edit prompt, same as the other
     * gather* methods), falling back to the git remote (or the
     * gh-authenticated user for GHCR) for a brand-new registry. Shared by
     * the new-env wizard above and `cloud:configure --only=registry` — the
     * only difference between callers is whether the image is required
     * (registry setup you explicitly asked for) or optional (one optional
     * step among many in a new-env wizard).
     */
    protected function promptRegistry(ConfigData $config, string $envName, bool $required, ?EnvironmentData $envData = null): RegistryData
    {
        $currentRegistry = $config->getEnvironment($envName)?->registry;

        $providerOptions = [
            RegistryProvider::GHCR->value => RegistryProvider::GHCR->label(),
            RegistryProvider::DOCKERHUB->value => RegistryProvider::DOCKERHUB->label(),
            RegistryProvider::GITLAB->value => RegistryProvider::GITLAB->label(),
            RegistryProvider::FORGEJO->value => RegistryProvider::FORGEJO->label(),
        ];

        // Auto-detect CI platform to pre-select native registry
        $detectedPlatform = 'github';
        $remoteUrl = trim(Process::run('git remote get-url origin')->output());
        if (str_contains($remoteUrl, 'gitlab.com')) {
            $detectedPlatform = 'gitlab';
        } elseif (str_contains($remoteUrl, 'forgejo') || str_contains($remoteUrl, 'git.')) {
            $detectedPlatform = 'forgejo';
        }

        $defaultRegistry = match ($detectedPlatform) {
            'gitlab' => RegistryProvider::GITLAB->value,
            'forgejo' => RegistryProvider::FORGEJO->value,
            default => RegistryProvider::GHCR->value,
        };

        if ($flag = $this->flag('registry') ?: $this->flag('registry-provider')) {
            if (! isset($providerOptions[$flag])) {
                throw new InvalidArgumentException("Invalid --registry-provider '{$flag}'. Use one of: ".implode(', ', array_keys($providerOptions)).'.');
            }
            $provider = $flag;
        } else {
            $provider = select(
                label: "Which container registry for {$envName}?",
                options: $providerOptions,
                default: $currentRegistry?->provider->value ?? $defaultRegistry,
            );
        }
        $registryProvider = RegistryProvider::from($provider);

        // Docker Hub public check
        if ($registryProvider === RegistryProvider::DOCKERHUB) {
            $isPublic = confirm(
                label: 'Is this a public Docker Hub repository? (Allows anyone to pull, bypasses credentials on Kubernetes)',
                default: false,
                hint: 'If public, we skip creating Kubernetes pull secrets and prompting for login credentials.',
            );
            $env = $envData ?? $config->getEnvironment($envName);
            if ($env) {
                $env->omitImagePullSecret = $isPublic;
            }
        }

        // The image path MUST include the owner (ghcr.io/<owner>/<repo>,
        // docker.io/<owner>/<repo>) — a bare name pushes to a namespace you
        // can't write to ("denied"). Best default: whatever's already
        // configured; otherwise the GitHub repo (owner/repo) parsed straight
        // from the git remote; fall back to the gh-detected owner + app name.
        $default = '';
        if ($this->flag('image') === null) {
            $default = $currentRegistry?->image ?? $this->guessImageFromGitRemote(getcwd());
            if ($default === '' && $registryProvider === RegistryProvider::GHCR) {
                $owner = trim(Process::run($this->getGhCommand().' api user -q .login')->output());
                if ($owner !== '') {
                    $default = $owner.'/'.$config->getName();
                }
            }
        }

        if (($image = $this->flag('image')) !== null) {
            $image = trim($image);
            if ($required && ! str_contains($image, '/')) {
                throw new InvalidArgumentException("Invalid --image '{$image}' — include the owner: owner/repo (e.g. your-username/".$config->getName().').');
            }
        } elseif ($required && $default === '' && $this->flag('no-interaction')) {
            throw new InvalidArgumentException('No image path could be derived — pass --image=owner/repo when running non-interactively.');
        } else {
            $image = text(
                label: $required ? 'Image repository path (owner/repo)' : 'Image repository path (optional, e.g. owner/repo)',
                placeholder: $default !== '' ? $default : 'your-username/'.$config->getName(),
                default: $default,
                required: $required,
                hint: $required ? 'Must include the owner — e.g. '.($default !== '' ? $default : 'acme/'.$config->getName()) : '',
                validate: $required
                    ? fn (string $v) => str_contains(trim($v), '/') ? null : 'Include the owner: owner/repo (e.g. your-username/'.$config->getName().').'
                    : null,
            );
        }

        $forgejoHost = null;
        if ($registryProvider === RegistryProvider::FORGEJO) {
            $envObj = $envData ?? $config->getEnvironment($envName);
            $forgejoHost = $envObj?->hosts[SharedClusterService::FORGEJO->value] ?? null;
            if (! $forgejoHost) {
                if ($envName === 'local') {
                    $forgejoHost = SharedClusterService::FORGEJO->hostFor(GlobalConfigData::load()->getLocalTld());
                } else {
                    $forgejoHost = $config->getSharedServiceHost(SharedClusterService::FORGEJO, $envName);
                }
            }

            // getSharedServiceHost() falls back to the LOCAL tld when a cloud
            // env has no web host recorded yet, so a production registry could
            // silently end up as `git.test` — confirmed live: the deploy only
            // failed much later, at `docker login git.test`, after a full image
            // build. A registry a cloud cluster cannot reach is never right, so
            // ask rather than record a host that cannot work.
            if ($envName !== 'local' && $this->isLocalRegistryHost((string) $forgejoHost)) {
                $forgejoHost = trim(text(
                    label: "Forgejo registry host for '{$envName}'",
                    placeholder: 'git.example.com',
                    hint: "'{$forgejoHost}' is a local host — a remote cluster cannot pull from it.",
                    required: true,
                    validate: fn (string $v) => $this->isLocalRegistryHost(trim($v))
                        ? 'That is still a local host. Use the registry\'s real, publicly resolvable domain.'
                        : null,
                ));
            }
        }

        return new RegistryData(
            provider: $registryProvider,
            image: trim($image) !== '' ? trim($image) : null,
            host: $forgejoHost,
        );
    }

    /**
     * A registry host on a local TLD. Deliberately its own method rather than
     * reusing EnsuresRealHosts::isLocalDomain(): this trait does not compose
     * that one, and a registry host is a different question from a web host.
     */
    protected function isLocalRegistryHost(string $host): bool
    {
        $host = trim($host);

        if ($host === '' || str_contains($host, '.dev.test')) {
            return true;
        }

        foreach (GlobalConfigData::ALLOWED_TLDS as $tld) {
            if (str_ends_with($host, '.'.$tld)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Parse `owner/repo` from the project's git `origin` remote — works for
     * both SSH (`git@github.com:owner/repo.git`) and HTTPS
     * (`https://github.com/owner/repo`) forms by taking the last two path
     * segments. Returns '' when there's no remote.
     */
    protected function guessImageFromGitRemote(string $projectPath): string
    {
        $remote = trim(Process::run('git -C '.escapeshellarg($projectPath).' remote get-url origin')->output());
        if ($remote === '') {
            return '';
        }

        $remote = (string) preg_replace('/\.git$/', '', $remote);
        $parts = array_values(array_filter(preg_split('#[/:]#', $remote) ?: []));

        return count($parts) >= 2
            ? $parts[count($parts) - 2].'/'.$parts[count($parts) - 1]
            : '';
    }

    /**
     * Project components that would be active in the new env, evaluated
     * with the freshly-gathered EnvironmentData so per-env feature filters
     * (addFeatures/excludeFeatures) are respected even before the env is
     * persisted to the config.
     */
    protected function resolveEnvComponents(ConfigData $config, string $envName, EnvironmentData $envData): array
    {
        // Briefly install the in-progress EnvironmentData so getFeatures()
        // sees the right addFeatures/excludeFeatures for this env, then
        // restore the prior map.
        $previous = $config->environments[$envName] ?? null;
        $config->environments[$envName] = $envData;

        try {
            return $config->getComponents($envName);
        } finally {
            if ($previous === null) {
                unset($config->environments[$envName]);
            } else {
                $config->environments[$envName] = $previous;
            }
        }
    }
}
