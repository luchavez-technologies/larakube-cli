<?php

namespace App\Traits;

use App\Data\ConfigData;
use App\Data\EnvironmentData;
use App\Data\RegistryData;
use App\Enums\IngressController;
use App\Enums\RegistryProvider;

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
    /**
     * Prompt for (or re-confirm) an env's ingress controller. Defaults to
     * whatever the env already has (Traefik for a brand-new one), so this
     * doubles as both the "create" and "update" prompt.
     */
    protected function gatherEnvironmentIngress(ConfigData $config, string $envName): IngressController
    {
        $selected = select(
            label: "Which Ingress Controller will {$envName} use?",
            options: IngressController::getSelectOptions($config),
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
     * The full new-environment wizard: ingress, managed services, client-facing
     * hosts, and (for non-local envs) a container registry. All optional —
     * defaults produce an env that uses Traefik, deploys everything locally,
     * and has no external web host.
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
        foreach ($this->promptForHosts($envName, $this->resolveEnvComponents($config, $envName, $envData)) as $service => $host) {
            $envData->hosts[$service] = $host;
        }

        $envData->additionalWebHosts = $this->gatherAdditionalWebHosts($config, $envName);

        // Container registry: optional. Only relevant for cloud environments.
        if ($envName !== 'local') {
            $configureRegistry = confirm(
                label: "Configure a container registry for {$envName}?",
                default: false,
                hint: 'Required for GitHub Actions CI/CD (push to GHCR or Docker Hub)',
            );

            if ($configureRegistry) {
                $envData->registry = $this->promptRegistry($config, $envName, required: false);
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

        $input = text(
            label: "Any additional hostnames for {$envName}'s web pod? (optional, comma-separated)",
            placeholder: 'admin.example.com, api.example.com',
            default: implode(', ', $current),
            hint: 'For a Laravel app using subdomain route groups, or just a second domain — all route to the same pod as the primary web host.',
            required: false,
        );

        return array_values(array_unique(array_filter(array_map('trim', explode(',', $input)))));
    }

    /**
     * Prompt for a container registry provider + image repository path, image
     * defaulted from the git remote (or the gh-authenticated user for GHCR).
     * Shared by the new-env wizard above and `cloud:configure --only=registry`
     * — the only difference between callers is
     * whether the image is required (registry setup you explicitly asked
     * for) or optional (one optional step among many in a new-env wizard).
     */
    protected function promptRegistry(ConfigData $config, string $envName, bool $required): RegistryData
    {
        $provider = select(
            label: "Which container registry for {$envName}?",
            options: [
                RegistryProvider::GHCR->value => RegistryProvider::GHCR->label(),
                RegistryProvider::DOCKERHUB->value => RegistryProvider::DOCKERHUB->label(),
            ],
        );
        $registryProvider = RegistryProvider::from($provider);

        // The image path MUST include the owner (ghcr.io/<owner>/<repo>,
        // docker.io/<owner>/<repo>) — a bare name pushes to a namespace you
        // can't write to ("denied"). Best default: the GitHub repo (owner/repo)
        // parsed straight from the git remote; fall back to the gh-detected
        // owner + app name.
        $default = $this->guessImageFromGitRemote(getcwd());
        if ($default === '' && $registryProvider === RegistryProvider::GHCR) {
            $owner = trim((string) shell_exec($this->getGhCommand().' api user -q .login 2>/dev/null'));
            if ($owner !== '') {
                $default = $owner.'/'.$config->getName();
            }
        }

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

        return new RegistryData(
            provider: $registryProvider,
            image: trim($image) !== '' ? trim($image) : null,
        );
    }

    /**
     * Parse `owner/repo` from the project's git `origin` remote — works for
     * both SSH (`git@github.com:owner/repo.git`) and HTTPS
     * (`https://github.com/owner/repo`) forms by taking the last two path
     * segments. Returns '' when there's no remote.
     */
    protected function guessImageFromGitRemote(string $projectPath): string
    {
        $remote = trim((string) shell_exec('git -C '.escapeshellarg($projectPath).' remote get-url origin 2>/dev/null'));
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
