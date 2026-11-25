<?php

namespace App\Traits;

use App\Contracts\HasEnvironmentVariables;
use App\Contracts\PlexProvisionable;
use App\Data\ConfigData;
use App\Data\EnvironmentData;
use App\Data\RegistryData;
use App\Enums\AppFramework;
use App\Enums\CacheDriver;
use App\Enums\CliTool;
use App\Enums\RegistryProvider;
use App\Enums\ServerVariation;
use App\Exceptions\MissingFlagException;
use App\Services\Kubectl;
use App\Services\Project\BackingServices;
use App\Support\ForgejoRegistryLogin;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\password;
use function Laravel\Prompts\table;
use function Laravel\Prompts\text;

/**
 * The individual cloud-setup steps behind the unified `cloud:configure`
 * command — the bare command chains all of them (configureAll); `--only=`
 * re-runs a single one. Keeping them here (rather than inline in the command)
 * means one implementation backs every entry point. Each step takes an
 * optional environment (falls back to a picker) and returns a command exit
 * code.
 *
 * Composing commands must also use InteractsWithEnvironments,
 * InteractsWithProjectConfig, GeneratesProjectInfrastructure (configureBase()
 * generates a brand-new environment's manifests) and LaraKubeOutput (which
 * provides getGhCommand() and withSpin()).
 */
trait ConfiguresCloudEnvironment
{
    use EnsuresNamespace;

    // The deploy-target picker (managed kube-context or VPS) lives here, so
    // configureBase can record a managed cluster without hand-edited config.
    // GathersEnvironmentData is the same ingress/managed-services/registry
    // wizard `larakube env` uses, so an environment created on demand here
    // (§ below) is indistinguishable from one created via `env`. EnsuresRealHosts
    // is the same local/placeholder-host guard `cloud:deploy` uses.
    use EnsuresRealHosts, GathersEnvironmentData, InteractsWithVpn, PushesBuildEnvToCi, ReadsEnvSources, ReconcilesPendingPlexCommons, ResolvesEnvironmentContext;
    use GeneratesProjectInfrastructure, InteractsWithScopedRbac;
    use StreamsProcessOutput;

    /**
     * The full guided setup: pick the environment once, then run every step in
     * the right order — server + web host (base), an optional Commons join, and
     * CI + secrets — so there's nothing to sequence or memorise. Stops at the
     * first failing step.
     */
    protected function configureAll(?string $environment = null): int
    {
        $environment ??= $this->askForCloudEnvironment(
            label: 'Which cloud environment are you setting up?',
        );

        // 1. Server connection details + the real web domain.
        if (($code = $this->configureBase($environment)) !== 0) {
            return $code;
        }

        // 2. Offer a shared Commons (runs BEFORE ci, so the rewritten .env ships).
        $this->maybeJoinCommons($environment);

        // 3. CI workflow + Git-forge secrets (GitHub Actions or GitLab CI,
        // auto-detected from the git remote — see detectCiPlatform()).
        return $this->configureCi($environment, false);
    }

    protected function configureBase(?string $environment = null): int
    {
        $environment ??= $this->askForCloudEnvironment(
            label: 'Which environment are you configuring?',
        );

        $projectPath = getcwd();
        $config = $this->getProjectConfigObject($projectPath);
        $isNewEnvironment = ! $config->hasEnvironment($environment);

        // Environments are opt-in — 'production' (or any other name) doesn't
        // exist until someone asks for it. First time this env is configured,
        // run the SAME ingress/managed-services wizard `larakube env` uses, and
        // mirror the rest of what `larakube env` does (seed .env.{env}, keep it
        // gitignored) — so an environment created entirely through
        // `cloud:configure` ends up a complete, deployable environment, not
        // just a blueprint entry with no manifests.
        if ($isNewEnvironment) {
            $this->laraKubeInfo("Environment '{$environment}' isn't in your blueprint yet — let's set it up.");
            $plex = $this->flag('plex') !== null ? $this->gatherEnvironmentPlex($config, $environment) : [];
            $managed = $this->gatherEnvironmentManaged($config, $environment);
            $this->rejectManagedAndPlexOverlap($managed, $plex);
            $config->addEnvironment($environment, new EnvironmentData(
                ingress: $this->gatherEnvironmentIngress($config, $environment),
                managed: $managed,
                plex: $plex,
                additionalWebHosts: $this->gatherAdditionalWebHosts($config, $environment),
            ));
            $this->saveProjectConfig($projectPath, $config);
            if ($this->seedEnvironmentEnvFile($config, $environment)) {
                $this->laraKubeInfo(".env.{$environment} created — fill in your app's own keys (left blank) before deploying.");
            }

            $newEnvFile = ".env.{$environment}";
            if (! file_exists("{$projectPath}/{$newEnvFile}") && file_exists("{$projectPath}/.env")) {
                copy("{$projectPath}/.env", "{$projectPath}/{$newEnvFile}");
                $this->laraKubeInfo("Created {$newEnvFile}");
            }

            $gitignorePath = "{$projectPath}/.gitignore";
            if (file_exists($gitignorePath)) {
                $gitignore = file_get_contents($gitignorePath);
                if (! str_contains($gitignore, '.env.*')) {
                    file_put_contents($gitignorePath, $gitignore."\n.env.*\n");
                    $this->laraKubeInfo('Updated .gitignore to exclude .env.* files');
                }
            }
        } elseif ($this->flag('plex') !== null) {
            $previousPlex = $config->getPlex($environment);
            $selectedPlex = $this->gatherEnvironmentPlex($config, $environment);
            $removedFromPlex = array_values(array_diff($previousPlex, $selectedPlex));
            $config->environments[$environment]->plex = $selectedPlex;
            if ($removedFromPlex !== []) {
                $config->environments[$environment]->managed = array_values(array_diff(
                    $config->environments[$environment]->managed,
                    $removedFromPlex,
                ));
            }
            $this->saveProjectConfig($projectPath, $config);
        }

        $this->laraKubeInfo("Configuring the deploy target for '{$environment}'...");

        // Pick a managed kube-context (DOKS/EKS/…) OR a VPS. This OVERWRITES any
        // existing cloud config, so a re-run on an already-configured env asks
        // for confirmation first — everything else here is idempotent (skips
        // re-prompting when a real value already exists), this is the one step
        // that isn't, so it needs its own guard against an accidental reset.
        //
        // Non-interactively, that confirm() used to silently default to "no"
        // and exit 0 — a caller passing --context=<new> on an already-bound
        // environment got told nothing happened, with no way to actually force
        // it short of hand-editing .larakube.local.json. --rebind is the
        // explicit, auditable way to say "yes, move it" without a human present
        // (this repo's own Strict Non-Interactive Flag Rule: never guess,
        // never silently no-op — fail loudly and name the flag instead).
        $existingCloud = $config->getCloud($environment);
        $hasExistingTarget = $existingCloud !== null && ($existingCloud->ip !== null || $existingCloud->context !== null);

        if (! $hasExistingTarget) {
            $config = $this->promptCloudTarget($config, $environment, $projectPath);
        } else {
            $currentTarget = $existingCloud->context ?? $this->environmentContextName((string) $existingCloud->ip);
            $requestedTarget = method_exists($this, 'hasOption') && $this->hasOption('context') ? trim((string) $this->option('context')) : '';
            $rebind = method_exists($this, 'hasOption') && $this->hasOption('rebind') && (bool) $this->option('rebind');
            $interactive = ! isset($this->input) || $this->input->isInteractive();

            if ($requestedTarget !== '' && $requestedTarget === $currentTarget) {
                // Already correct — idempotent no-op, no flag or prompt needed
                // just to re-assert the value that's already saved.
            } elseif ($rebind) {
                $config = $this->promptCloudTarget($config, $environment, $projectPath);
            } elseif ($interactive) {
                if (confirm("'{$environment}' already targets '{$currentTarget}' — reconfigure the deploy target?", false)) {
                    $config = $this->promptCloudTarget($config, $environment, $projectPath);
                }
            } elseif ($requestedTarget !== '') {
                throw new MissingFlagException(
                    'rebind',
                    "confirmation that '{$environment}' should move from '{$currentTarget}' to '{$requestedTarget}'",
                    "larakube cloud:configure {$environment} --context={$requestedTarget} --rebind",
                );
            }
            // else: non-interactive, no --rebind, no new --context given at
            // all — nothing was asked for, leave the existing target as-is.
        }

        // 🌐 Ensure a real web host + every client-facing non-web host (Reverb WS,
        // S3/CDN public endpoint, …) — the same guard `cloud:deploy` and the CI
        // steps use, so a placeholder or local value can't slip through whichever
        // entry point the user happens to use.
        $this->ensureHosts($config, $environment);

        $this->saveProjectConfig($projectPath, $config);

        // New environments (or updates passing --plex=) need their manifests
        // regenerated so overlays immediately reflect the backing-service choice.
        if ($isNewEnvironment || $this->flag('plex') !== null) {
            $this->withSpin("Generating manifests for '{$environment}'...", function () use ($config, $isNewEnvironment) {
                $this->orchestrateProjectScaffolding($config, false, false);
                if (! $isNewEnvironment) {
                    $this->pruneStaleManifests($config);
                }

                return true;
            });
        }

        $this->laraKubeInfo('✅ Cloud configuration saved to .larakube.json');

        return 0;
    }

    /**
     * Re-run just the host guard for an environment — the `--only=hosts` step
     * of `cloud:configure`. Covers every client-facing host the project uses
     * (web, Reverb WS, object-storage S3/CDN), not just the web host the old
     * `cloud:configure:base` prompted for.
     */
    protected function configureHosts(?string $environment = null): int
    {
        $environment ??= $this->askForCloudEnvironment(
            label: 'Which environment are you updating hosts for?',
        );

        $projectPath = getcwd();
        $config = $this->getProjectConfigObject($projectPath);

        if (! $config->hasEnvironment($environment)) {
            $this->laraKubeError("Environment '{$environment}' is not in your blueprint. Run `larakube cloud:configure {$environment}` first.");

            return 1;
        }

        $this->ensureHosts($config, $environment);
        $config->environments[$environment]->additionalWebHosts = $this->gatherAdditionalWebHosts($config, $environment);
        $this->saveProjectConfig($projectPath, $config);

        // A host change must flow into the committed Ingress manifest, not
        // just the blueprint — CI applies whatever's already on disk, it
        // never reads .larakube.json, so the overlay has to be regenerated
        // right here or a deploy ships the previous host.
        $this->withSpin("Regenerating manifests for '{$environment}'...", function () use ($config) {
            $this->generateK8sManifests($config);

            return true;
        });

        $this->laraKubeInfo("✅ Hosts saved and manifests regenerated for '{$environment}'. Commit and push (or deploy) to apply this change.");

        return 0;
    }

    /**
     * Dispatch to the right CI workflow generator for the detected forge —
     * the `--only=ci` step of `cloud:configure`. GitLab has its own pipeline
     * format; GitHub and Forgejo share the GitHub Actions one.
     */
    protected function configureCi(?string $environment, bool $rotate): int
    {
        $config = $this->getProjectConfig(getcwd());

        return $this->detectCiPlatform($config, $environment) === 'gitlab'
            ? $this->configureGitlab($environment, $rotate)
            : $this->configureGha($environment, $rotate);
    }

    /**
     * Configure the container registry for an environment (GHCR / Docker Hub).
     * Drives both `cloud:deploy`'s registry push and the GitHub Actions workflow.
     */
    protected function configureRegistry(?string $environment = null): int
    {
        $environment ??= $this->askForCloudEnvironment(
            label: 'Which environment do you want to configure a registry for?',
        );

        $projectPath = getcwd();
        $config = $this->getProjectConfigObject($projectPath);

        if (! isset($config->environments[$environment])) {
            $this->laraKubeError("Environment '{$environment}' is not in your blueprint.");

            return 1;
        }

        $registry = $this->promptRegistry($config, $environment, required: true);

        $config->environments[$environment]->registry = $registry;
        $this->saveProjectConfig($projectPath, $config);

        $imageLabel = $registry->image ?? $config->getName();
        $this->laraKubeInfo("✅ Registry configured for {$environment}!");
        $this->info("Provider: {$registry->provider->label()}");
        $this->info("Registry host: {$registry->getRegistryHost()}");
        $this->info("Image: {$imageLabel}");

        return 0;
    }

    /**
     * Configure which eligible backing services in an environment should reuse
     * Plex Commons vs deploy their own pods inside the app namespace — the
     * `--only=plex` step of `cloud:configure`. Saves intent in `.larakube.json`
     * and regenerates/prunes manifests immediately; cluster provisioning runs
     * automatically on the next `cloud:deploy` or `cloud:configure --only=ci`.
     */
    protected function configurePlex(?string $environment = null): int
    {
        $environment ??= $this->askForCloudEnvironment(
            label: 'Which environment are you configuring backing services for?',
        );

        if ($environment === 'local') {
            $this->laraKubeError('Use `larakube plex:join local` or `larakube plex:leave local` for local development.');

            return 1;
        }

        $projectPath = getcwd();
        $config = $this->getProjectConfigObject($projectPath);

        if (! $config->hasEnvironment($environment)) {
            $this->laraKubeError("Environment '{$environment}' is not in your blueprint. Run `larakube cloud:configure {$environment}` first.");

            return 1;
        }

        $selected = $this->gatherEnvironmentPlex($config, $environment);
        $previousPlex = $config->getPlex($environment);
        $removedFromPlex = array_values(array_diff($previousPlex, $selected));

        $config->environments[$environment]->plex = $selected;

        if ($removedFromPlex !== []) {
            $config->environments[$environment]->managed = array_values(array_diff(
                $config->environments[$environment]->managed,
                $removedFromPlex,
            ));
        }

        // `--managed=` alongside `--only=plex` also sets which services are hosted outside the
        // cluster (e.g. AWS RDS). Plex services keep their own `managed` entries.
        if ($this->flag('managed') !== null) {
            $external = $this->gatherEnvironmentManaged($config, $environment);
            $this->rejectManagedAndPlexOverlap($external, $selected);
            $config->environments[$environment]->managed = array_values(array_unique([
                ...$external,
                ...array_intersect($config->environments[$environment]->managed, $selected),
            ]));
        }

        $this->saveProjectConfig($projectPath, $config);

        $this->withSpin("Regenerating manifests for '{$environment}'...", function () use ($config) {
            $this->generateK8sManifests($config);
            $this->pruneStaleManifests($config);

            return true;
        });

        $pending = $this->pendingPlexServices($config, $environment);

        if ($selected === []) {
            $this->laraKubeInfo("All backing services for '{$environment}' are configured to run as own pods in the app namespace.");
        } else {
            $this->laraKubeInfo("Plex Commons enabled in '{$environment}' blueprint for: ".implode(', ', $selected).'.');
        }

        if ($pending !== []) {
            $this->line('  <fg=gray>Pending Plex Commons services ('.implode(', ', $pending).') will be provisioned automatically on your next deploy or CI/CD configure.</>');
        }

        if ($removedFromPlex !== []) {
            $this->laraKubeWarn('Self-hosted pods will return on next deploy for: '.implode(', ', $removedFromPlex).'.');
            $this->line("  <fg=gray>Existing Commons data is kept untouched until you run</> <fg=yellow>larakube plex:leave {$environment} --restore</><fg=gray>.</>");
        }

        return 0;
    }

    /**
     * Prompt to join a shared Commons when the project's drivers map to a
     * plex-ready Commons service (a Postgres/MySQL/MariaDB database, Redis,
     * Meilisearch, or S3 via SeaweedFS/Garage). plex:join rewrites .env and
     * regenerates manifests, so it must run before the CI secret upload reads
     * the .env.
     */
    protected function maybeJoinCommons(string $environment): void
    {
        $config = $this->getProjectConfigObject(getcwd());

        $pending = $this->pendingPlexServices($config, $environment);
        if ($pending !== []) {
            $this->newLine();
            $this->line('  This environment is configured to use Plex Commons for: <fg=cyan>'.implode(', ', $pending).'</>');
            if (confirm('Provision and join these Plex Commons services now?', true)) {
                $context = $this->environmentContextOrCurrent($config, $environment);
                $this->call('plex:join', array_filter([
                    'environment' => $environment,
                    '--context' => $context,
                    '--service' => $pending,
                ], fn (mixed $v): bool => $v !== null && $v !== ''));
            }

            return;
        }

        $shareable = [];
        foreach (array_filter([
            $config->getDatabase(),
            $config->getCacheDriver(),
            $config->getScoutDriver(),
            $config->getObjectStorage(),
        ]) as $driver) {
            if ($driver instanceof PlexProvisionable && $driver->isPlexReady() && $driver->commonsServiceName() !== null) {
                $shareable[] = $driver->commonsServiceName();
            }
        }

        if (empty($shareable)) {
            return;
        }

        $this->newLine();
        $this->line('  This app can share these on a Commons: <fg=cyan>'.implode(', ', array_unique($shareable)).'</>');
        if (confirm('Join a shared Commons for these (instead of self-hosting them)?', false)) {
            $this->call('plex:join', ['environment' => $environment]);
        }
    }

    protected function configureGha(?string $environment = null, bool $rotate = false): int
    {
        $projectPath = getcwd();
        $initialConfig = $this->getProjectConfig($projectPath);
        $initialPlatform = $this->detectCiPlatform($initialConfig, $environment);
        $initialForgeLabel = $initialPlatform === 'forgejo' ? 'Forgejo' : 'GitHub';

        $environment ??= $this->askForCloudEnvironment(
            label: "Which environment are you configuring for {$initialForgeLabel} Actions?",
        );

        $resolvedPlatform = $this->detectCiPlatform($initialConfig, $environment);
        $isForgejo = $resolvedPlatform === 'forgejo';
        $forgeLabel = $isForgejo ? 'Forgejo' : 'GitHub';

        // Repo guard — fail fast before any prompts.
        $remote = $this->resolveEnvironmentRemoteUrl($initialConfig, $environment);
        if (empty($remote)) {
            $this->laraKubeError("No git remote found. Create a {$forgeLabel} repository and add it as origin first:");
            $this->line('    git remote add origin git@'.($isForgejo ? 'git.example.com' : 'github.com').':<owner>/<repo>.git');

            return 1;
        }

        $repository = $this->parseGitRemote($remote);

        if ($isForgejo) {
            if ($repository === null) {
                $this->laraKubeError("Could not parse a Forgejo repository from remote URL: {$remote}");

                return 1;
            }

            if (! CliTool::TEA->isAvailable()) {
                $this->laraKubeError('Tea CLI (tea) is required to configure Forgejo repository secrets.');
                $this->line('  Install it by running: <fg=yellow>larakube setup --tools=tea</>');

                return 1;
            }

            $tea = $this->getTeaCommand();
            $preferredLogin = $initialConfig->getEnvironment($environment)?->teaLogin;
            $teaLogin = $this->resolveTeaLoginName($tea, $repository['host'], $preferredLogin);
            if ($teaLogin === null) {
                $this->laraKubeError("tea is not logged in to {$repository['host']}.");
                $this->line("   <fg=gray>Run</> <fg=yellow>larakube git:login {$environment}</> <fg=gray>first, then re-run this command.</>");

                return 1;
            }

            $this->info("  🛰 Targeting repository: {$repository['host']}/{$repository['slug']}");
            $setSecret = fn (string $name, string $value) => $this->setTeaSecret($tea, $name, $value, $repository['slug'], $teaLogin);
        } else {
            $repoFlag = '';
            if ($repository !== null && str_contains($repository['host'], 'github')) {
                $repoFlag = '-R '.$repository['slug'];
                $this->info("  🛰 Targeting repository: {$repository['slug']}");
            } else {
                $this->laraKubeWarn("Could not parse GitHub repository from remote URL: {$remote}");
            }

            if (! CliTool::GH->isAvailable()) {
                $this->laraKubeError('GitHub CLI (gh) is required to configure GitHub repository secrets.');
                $this->line('  Install it by running: <fg=yellow>larakube setup --tools=gh</>');

                return 1;
            }

            $gh = $this->getGhCommand();
            $setSecret = fn (string $name, string $value) => $this->setGithubSecret($gh, $name, $value, $repoFlag);
        }

        // Domain guard — same check as cloud:deploy and configureBase. Fires when
        // the web host is missing or still a local .kube/.dev.test value.
        $config = $this->getProjectConfigObject($projectPath);
        $isStatic = (bool) $config->framework?->isStaticSpa();
        // Neither PHP nor static: Next.js, and the server frameworks as they
        // become deployable. A project with no framework recorded is Laravel.
        $isServerApp = $config->framework !== null && ! $isStatic && ! $config->framework->isPhp();
        $previousHost = $config->getHost($environment, 'web');

        $host = $this->ensureHosts($config, $environment);

        // APP_URL/ASSET_URL are Laravel's; a static site's bundle reads neither.
        if ($host !== $previousHost && ! $isStatic) {
            $this->syncEnvFile($projectPath, ['APP_URL' => 'https://'.$host], false, $environment);
            $this->alignEnvironmentAssetUrl($projectPath, $environment, $host);
            $this->laraKubeInfo("Updated APP_URL and ASSET_URL to https://{$host}");
        }

        // A Forgejo remote publishes to that same forge's registry unless the
        // environment already names one.
        if ($isForgejo && $config->getRegistry($environment) === null) {
            $config->environments[$environment]->registry = new RegistryData(
                provider: RegistryProvider::FORGEJO,
                image: strtolower($repository['slug']),
                host: $repository['host'],
            );
            $this->laraKubeInfo("Images will be pushed to {$repository['host']}/".strtolower($repository['slug']).'.');
            $this->saveProjectConfig($projectPath, $config);

            // The overlay CI applies has to name the registry's pull secret.
            $this->withSpin("Regenerating manifests for '{$environment}'...", function () use ($config) {
                $this->orchestrateProjectScaffolding($config, installFeatures: false, buildImage: false, syncEnv: false);

                return true;
            });
        }

        $config->environments[$environment]->ciPlatform = $isForgejo ? 'forgejo' : 'github';
        $this->saveProjectConfig($projectPath, $config);

        if ($this->pendingPlexServices($config, $environment) !== []) {
            $plexContext = $this->environmentContextOrCurrent($config, $environment);
            if (! $plexContext) {
                $this->laraKubeError("No cluster target for '{$environment}' — link a server before provisioning Plex Commons.");
                $this->line('  Run <fg=yellow;options=bold>larakube cloud:configure '.$environment.' --only=target</> first.');

                return 1;
            }
            if (! $this->reconcilePendingPlexCommons($config, $environment, $plexContext, $projectPath)) {
                return 1;
            }
        }

        if (! confirm("Upload the deploy credentials for '{$environment}' to {$forgeLabel}?", true)) {
            $this->laraKubeInfo('Action cancelled.');

            return 0;
        }

        // 1. Upload secrets (env file + scoped kubeconfig). Abort if this fails —
        //    there's no point generating a workflow with no/broken credentials.
        $this->laraKubeInfo("Step 1: Configuring {$forgeLabel} Secrets for '{$environment}'...");
        if ($this->uploadGhaSecrets($projectPath, $environment, $setSecret, $rotate) !== 0) {
            $this->laraKubeError("{$forgeLabel} secret configuration failed — aborting before generating the workflow.");

            return 1;
        }

        // 2. Pull secret for a private registry — created via the cluster
        //    API (works on VPS and managed clusters; self-skips for public registries).
        $this->laraKubeInfo('Step 2: Ensuring private-registry pull access...');
        $this->setupRegistrySecret($environment);

        // 2.5. Offer to connect CI to the environment's NetBird VPN — a no-op
        //      unless one is actually installed (self-skips silently).
        //      The Forgejo runner already runs inside a cluster, so it skips this.
        $vpnHost = $isForgejo ? null : $this->ensureCiVpnSecret(
            $this->getProjectConfigObject($projectPath),
            $environment,
            $projectPath,
            $setSecret,
        );

        // 3. Generate Workflow
        $config = $this->getProjectConfigObject(getcwd());
        $this->laraKubeInfo('Step 3: Generating Cloud Pilot workflow...');

        $branch = $this->flag('branch') ?: text(
            label: "Which git branch should trigger the {$environment} deployment?",
            default: 'main',
            required: true,
        );

        $appName = $config->getName();
        $namespace = $appName.'-'.$environment;
        $podName = $this->deploymentPodName($config);
        $upperEnv = strtoupper($environment);

        $workflowPath = getcwd()."/.github/workflows/larakube-deploy-{$environment}.yml";
        if (! is_dir(dirname($workflowPath))) {
            mkdir(dirname($workflowPath), 0755, true);
        }

        // Determine registry configuration
        $registry = $config->getRegistry($environment);
        $registryProvider = $registry ? $registry->provider->value : 'ghcr';
        $registryHost = $registry ? $registry->getRegistryHost() : 'ghcr.io';
        $imageName = $registry ? ($registry->image ?? '${{ github.repository }}') : '${{ github.repository }}';

        if (($isStatic || $isServerApp) && $this->flag('with-tests')) {
            $this->laraKubeWarn("--with-tests has no effect on a {$config->framework->getLabel()} project — there is no test suite convention to run.");
        }

        // Resolve security audit configuration — CLI flags override persisted
        // config when explicitly passed. Persist back so heal / re-runs stay
        // consistent.
        $auditConfig = $config->getSecurityAudit($environment);

        if ($this->flag('skip-audit')) {
            $auditConfig->skip = true;
        }
        if ($this->flag('strict')) {
            $auditConfig->strict = true;
        }
        if ($this->flag('with-tests')) {
            $auditConfig->withTests = true;
        }

        // Per-gate opt-outs. Only applied when the flag is actually passed, so
        // a re-run without them keeps whatever the blueprint already persisted.
        foreach ([
            'no-gitleaks' => 'gitleaks',
            'no-semgrep' => 'semgrep',
            'no-dependency-audit' => 'dependencyAudit',
            'no-trivy' => 'trivy',
        ] as $flag => $property) {
            if ($this->flag($flag)) {
                $auditConfig->{$property} = false;
            }
        }

        $config->environments[$environment]->securityAudit = $auditConfig;
        $this->saveProjectConfig($projectPath, $config);

        table(
            headers: ['Security Gate', 'Status'],
            rows: array_values(array_filter([
                ['Gitleaks (secret scan)', $auditConfig->runsGitleaks() ? '✅ Enabled' : '⏭ Skipped'],
                ['Semgrep (SAST)', $auditConfig->runsSemgrep() ? '✅ ERROR-only' : '⏭ Skipped'],
                [$isStatic || $isServerApp ? 'NPM audit' : 'Composer / NPM audit', $auditConfig->runsDependencyAudit() ? '✅ '.$auditConfig->auditLevel() : '⏭ Skipped'],
                ['Trivy filesystem scan', $auditConfig->runsTrivy() ? '📊 Report-only' : '⏭ Skipped'],
                ['Trivy image scan', $auditConfig->runsTrivy() ? '🚦 '.$auditConfig->failOnSeverity() : '⏭ Skipped'],
                $isStatic || $isServerApp ? null : ['Application tests', $auditConfig->runsTests() ? '✅ Enabled' : '⏭ Skipped'],
                ['Severity policy', $auditConfig->strict ? '🔒 Strict (HIGH+CRITICAL)' : '🛡 Default (CRITICAL)'],
            ])),
        );

        $workflowContent = view(match (true) {
            $isStatic => 'k8s.cloud-pilot-deploy-static',
            $isServerApp => 'k8s.cloud-pilot-deploy-server',
            default => 'k8s.cloud-pilot-deploy',
        }, [
            'config' => $config,
            'environment' => $environment,
            'branch' => $branch,
            'appName' => $appName,
            'namespace' => $namespace,
            'podName' => $podName,
            'upperEnv' => $upperEnv,
            'vpnHost' => $vpnHost,
            'publicEnvScript' => $isStatic || $isServerApp
                ? $this->buildStaticPublicEnvScript($config, $environment)
                : $this->buildPublicEnvScript($config, $environment),
            'secrets' => [
                'k_env' => '${{ secrets.'.$upperEnv.'_KUBECONFIG }}',
                'k_base' => '${{ secrets.KUBECONFIG }}',
                'vpn_key' => '${{ secrets.'.$upperEnv.'_NETBIRD_SETUP_KEY }}',
                'build_env' => '${{ secrets.'.$upperEnv.'_BUILD_ENV }}',
            ],
            'gha' => [
                'repository' => '${{ github.repository }}',
                'actor' => '${{ github.actor }}',
                'token' => '${{ secrets.GITHUB_TOKEN }}',
                'sha' => '${{ github.sha }}',
                'registry_provider' => $registryProvider,
                'registry_host' => $registryHost,
                'image_name' => $imageName,
                'k_data' => '${{ env.K_DATA }}',
                // GitHub expressions must be passed as values (not hardcoded as
                // literal text in the Blade), or Blade mangles the inner {{ }}.
                'image_latest' => '${{ env.REGISTRY_HOST }}/${{ env.IMAGE_NAME }}:latest',
                'image_sha' => '${{ env.REGISTRY_HOST }}/${{ env.IMAGE_NAME }}:${{ github.sha }}',
                'forge' => $isForgejo ? 'forgejo' : 'github',
                'push_ref_output' => '${{ steps.push.outputs.ref }}',
                'image_ref' => '${{ needs.build.outputs.image_ref }}',
                'composer_cache_key' => "composer-\${{ hashFiles('composer.lock') }}",
                'registry_user' => '${{ secrets.'.$upperEnv.'_REGISTRY_USERNAME }}',
                'registry_password' => '${{ secrets.'.$upperEnv.'_REGISTRY_PASSWORD }}',
                'trivy_cache_key' => '${{ runner.os }}-trivy-db',
                'trivy_restore_key' => '${{ runner.os }}-trivy-',
            ],
            'audit' => [
                'skip' => $auditConfig->skip,
                'strict' => $auditConfig->strict,
                // Per-gate, with the master `skip` already folded in, so the
                // template never has to combine the two itself.
                'gitleaks' => $auditConfig->runsGitleaks(),
                'semgrep' => $auditConfig->runsSemgrep(),
                'dependencyAudit' => $auditConfig->runsDependencyAudit(),
                'trivy' => $auditConfig->runsTrivy(),
                'withTests' => ! $isStatic && ! $isServerApp && $auditConfig->runsTests(),
                'failOn' => $auditConfig->failOnSeverity(),
                'auditLevel' => $auditConfig->auditLevel(),
            ],
        ])->render();

        file_put_contents($workflowPath, $workflowContent);

        $this->laraKubeInfo("✅ {$forgeLabel} Actions configured successfully for '{$environment}'!");
        $this->info("Workflow saved to: .github/workflows/larakube-deploy-{$environment}.yml");
        $this->line("Push to '{$branch}' to trigger your Cloud Pilot deployment.");

        return 0;
    }

    /**
     * Offer to connect CI to this environment's NetBird VPN, when one is
     * installed — for a cloud:harden'd server whose k3s API (6443) is
     * VPN-only, a GitHub-hosted runner can't reach it at all otherwise.
     * Mints an ephemeral+reusable setup key (one CI runner "identity" that
     * every job reuses; NetBird auto-removes each throwaway peer once its
     * ephemeral runner is destroyed) and hands it to $uploadSecret to store
     * however the calling forge does (gh secret set / glab variable set).
     *
     * Non-interactive/declined/no-VPN-installed all return null, meaning
     * "the workflow should dial the cluster directly" — the existing,
     * unaffected default. Once accepted, ciVpn is persisted on the
     * environment so a later re-run doesn't re-prompt.
     *
     * @param  callable(string, string): void  $uploadSecret
     */
    protected function ensureCiVpnSecret(ConfigData $config, string $environment, string $projectPath, callable $uploadSecret): ?string
    {
        $context = $this->environmentContextOrCurrent($config, $environment);
        if (! $context) {
            return null;
        }

        $vpnKubectl = Kubectl::forContext($context)->prefix();
        $vpnNamespace = $this->vpnNamespace();
        if (! $this->isVpnInstalled($vpnKubectl, $vpnNamespace)) {
            return null;
        }

        $host = $this->resolveVpnHostReadOnly($environment, $config);
        if (! $host) {
            return null;
        }

        $alreadyEnabled = $config->getEnvironment($environment)?->ciVpn ?? false;
        if (! $alreadyEnabled) {
            if ($this->flag('no-interaction')) {
                return null;
            }
            if (! confirm(
                "This environment's cluster has a NetBird VPN — connect CI to it before deploying? (needed if cloud:harden restricted the k3s API to the VPN)",
                true,
            )) {
                return null;
            }
        }

        $pat = $this->fetchVpnPat($vpnKubectl, $vpnNamespace);
        if ($pat === null) {
            $this->laraKubeWarn('Could not fetch the NetBird admin token — skipping CI VPN setup.');

            return null;
        }

        $minted = $this->mintVpnSetupKey($host, $pat, "ci-{$environment}", reusable: true, days: 365, ephemeral: true);
        if ($minted === null) {
            $this->laraKubeWarn('Could not mint a NetBird setup key for CI — skipping.');

            return null;
        }

        $key = (string) $minted['key'];
        $this->registerSecret($key);
        $uploadSecret(strtoupper($environment).'_NETBIRD_SETUP_KEY', $key);

        if (! $alreadyEnabled) {
            $data = $config->toArray();
            $data['environments'][$environment]['ciVpn'] = true;
            ConfigData::from($data)->saveToFile($projectPath);
        }

        return $host;
    }

    protected function setupRegistrySecret(string $environment): void
    {
        $config = $this->getProjectConfigObject(getcwd());

        $envObj = $config->getEnvironment($environment);
        if ($envObj?->omitImagePullSecret) {
            return;
        }

        $registry = $config->getRegistry($environment);
        $provider = $registry ? $registry->provider : RegistryProvider::GHCR;

        // Create it through the env's cluster CONTEXT
        $cloud = $config->getCloud($environment);
        $context = $cloud?->context ?? ($cloud?->ip ? 'larakube-'.$cloud->ip : '');
        if ($context === '') {
            $this->laraKubeWarn("No cluster context for '{$environment}' — skipping image pull-secret setup.");

            return;
        }

        $username = null;
        $token = null;
        $server = null;
        $secretName = null;

        if ($provider === RegistryProvider::GHCR) {
            $gh = $this->getGhCommand();
            $username = trim(Process::run("{$gh} api user -q .login")->output());
            $token = trim(Process::run("{$gh} auth token")->output());

            if (! $username) {
                $username = text(label: 'GitHub Username', required: true);
            } else {
                $this->info("  👤 Using detected GitHub user: {$username}");
            }

            if (! $token) {
                $token = password(label: 'GitHub Personal Access Token (PAT) with read:packages scope', required: true);
            } else {
                $this->info('  🔑 Using existing GitHub authentication token.');
            }
            $server = 'https://ghcr.io';
            $secretName = 'ghcr-login';
        } elseif ($provider === RegistryProvider::DOCKERHUB) {
            $username = text(label: 'Docker Hub Username', required: true);
            $token = password(label: 'Docker Hub Personal Access Token (or password)', required: true);
            $server = 'https://index.docker.io/v1/';
            $secretName = 'dockerhub-login';
        } elseif ($provider === RegistryProvider::GITLAB) {
            $username = text(label: 'GitLab Username (or deploy token name)', required: true);
            $token = password(label: 'GitLab Password (or deploy token)', required: true);
            $server = 'https://registry.gitlab.com';
            $secretName = 'gitlab-login';
        } elseif ($provider === RegistryProvider::FORGEJO) {
            if (! $registry?->host) {
                $this->laraKubeError("The Forgejo registry for '{$environment}' has no host — run `larakube cloud:configure {$environment} --only=registry`.");

                return;
            }

            $credentials = $this->forgejoRegistryCredentials($config, $environment, $registry->host);
            $username = $credentials['username'] ?? text(label: 'Forgejo Username', required: true);
            $token = $credentials['token'] ?? password(label: 'Forgejo Password (or Personal Access Token)', required: true);
            $server = 'https://'.$registry->host;
            $secretName = 'forgejo-login';
        } elseif ($provider === RegistryProvider::GAR) {
            $username = '_json_key';
            $keyPath = $this->getGcpCredentials() ?? text(label: 'Path to GCP Service Account JSON key', required: true);
            $keyPathResolved = str_replace('~', home_path(), trim($keyPath));
            $token = file_exists($keyPathResolved) ? trim((string) file_get_contents($keyPathResolved)) : $keyPath;
            $garHost = $registry?->getRegistryHost() ?? 'us-central1-docker.pkg.dev';
            $server = 'https://'.$garHost;
            $secretName = 'gar-login';
        }

        if (! $username || ! $token || ! $server || ! $secretName) {
            return;
        }

        $namespace = $config->getName().'-'.$environment;
        $kubectl = Kubectl::forContext($context)->prefix();
        $ns = escapeshellarg($namespace);

        $this->ensureNamespace($kubectl, $namespace);
        Process::run(
            "{$kubectl} create secret docker-registry ".escapeshellarg($secretName)." -n {$ns}"
            .' --docker-server='.escapeshellarg($server)
            .' --docker-username='.escapeshellarg($username)
            .' --docker-password='.escapeshellarg($token)
            ." --dry-run=client -o yaml | {$kubectl} apply -f -",
        );

        $this->laraKubeInfo("✅ Image pull secret '{$secretName}' created in '{$namespace}' (context: {$context}).");
    }

    /**
     * Upload the env-file secret + mint and upload a namespace-scoped kubeconfig.
     * Backs `cloud:configure --only=ci`'s GitHub Actions path end-to-end with no
     * sub-command indirection.
     */
    /**
     * @param  callable(string, string): void  $setSecret  Stores one repository secret on the forge.
     */
    protected function uploadGhaSecrets(string $projectPath, string $environment, callable $setSecret, bool $rotate = false): int
    {
        $upperEnv = strtoupper($environment);

        // No .env content of any kind is uploaded to GitHub — not even a
        // public-only subset. The generated workflow bakes public/build
        // values as literal `echo` lines (see configureGha()'s $publicEnv),
        // computed straight from the blueprint at generation time; runtime
        // credentials only ever reach the cluster via `dotenv:push`, run
        // from this machine. See plans/active/secrets-out-of-ci.md.
        $config = $this->getProjectConfigObject($projectPath);

        $registry = $config->getRegistry($environment);
        if ($registry && $registry->provider !== RegistryProvider::GHCR) {
            // A LaraKube-managed Forgejo already holds a push token; anything
            // else is the operator's own account.
            $forgejoCredentials = $registry->provider === RegistryProvider::FORGEJO
                ? $this->forgejoRegistryCredentials($config, $environment, $registry->getRegistryHost())
                : null;

            $regUser = $forgejoCredentials['username'] ?? $this->registryCredential('USERNAME', 'Registry Username for '.$registry->provider->label(), secret: false);
            $regPass = $forgejoCredentials['token'] ?? $this->registryCredential('TOKEN', 'Registry Password/Token for '.$registry->provider->label(), secret: true);

            if ($regUser === null || $regPass === null) {
                $this->laraKubeError("No registry credentials for {$registry->getRegistryHost()}, and this run can't ask for them.");
                $this->line('  Set <fg=yellow>LARAKUBE_REGISTRY_USERNAME</> and <fg=yellow>LARAKUBE_REGISTRY_TOKEN</> for this run, or run the command in a terminal.');

                return 1;
            }

            $setSecret("{$upperEnv}_REGISTRY_USERNAME", $regUser);
            $setSecret("{$upperEnv}_REGISTRY_PASSWORD", $regPass);
        }

        // Mint + upload a namespace-scoped kubeconfig.
        $this->laraKubeInfo("Minting a namespace-scoped KUBECONFIG for {$environment}...");
        $adminContext = $this->environmentContextOrCurrent($config, $environment);

        if (! $adminContext) {
            $this->laraKubeError("No cluster target for '{$environment}'.");
            $this->line('  Run <fg=yellow;options=bold>larakube cloud:configure '.$environment.'</> (or cloud:init) first.');

            return 1;
        }

        if (! $this->kubectlSupportsTokens()) {
            $this->laraKubeError('kubectl >= 1.24 is required to mint a scoped token. Please upgrade kubectl.');

            return 1;
        }

        $namespace = $config->getName().'-'.$environment;
        $kubectl = Kubectl::forContext($adminContext)->prefix();
        $ns = escapeshellarg($namespace);

        $this->ensureNamespace($kubectl, $namespace);

        if (! $this->ensureScopedRbac($adminContext, $namespace, $config->getName(), $environment)) {
            $this->laraKubeError('Failed to create the namespace-scoped ServiceAccount/Role in the cluster.');

            return 1;
        }

        if ($rotate) {
            $this->laraKubeInfo('Rotating: revoking the current deploy token before minting a fresh one...');
            Process::run("{$kubectl} -n {$ns} delete secret ".escapeshellarg($this->deployerName().'-token').' --ignore-not-found');
        }

        $kubeConfigContent = $this->mintScopedKubeconfig($adminContext, $namespace);
        if ($kubeConfigContent === null) {
            $this->laraKubeError('Failed to mint the scoped token (the bound-token Secret was never populated).');

            return 1;
        }

        $this->info('  🔒 Scoped to namespace: <fg=cyan>'.$namespace.'</> (a leaked secret can touch nothing else)');
        $this->info('  📦 Kubeconfig size: '.strlen($kubeConfigContent).' bytes');
        if (preg_match('/server: (https:\/\/.*)/', $kubeConfigContent, $matches)) {
            $this->info("  🔗 Server Target: <fg=cyan>{$matches[1]}</>");
        }

        $setSecret("{$upperEnv}_KUBECONFIG", $kubeConfigContent);

        if (($buildEnv = $this->buildEnvPayload($config, $environment, $projectPath)) !== '') {
            $setSecret("{$upperEnv}_BUILD_ENV", $buildEnv);
        }

        // Stamp when the scoped CI credential was minted.
        $data = $config->toArray();
        $data['environments'][$environment]['cloud']['rbacGrantedAt'] = gmdate('c');
        ConfigData::from($data)->saveToFile($projectPath);

        $this->laraKubeInfo("Secrets configured successfully for '{$environment}' (namespace-scoped).");

        return 0;
    }

    /**
     * The package-registry credentials `tool:init --tool=forgejo` minted for a LaraKube-managed
     * Forgejo, read from the env's cluster. Null when that forge isn't one.
     *
     * @return array{username: string, token: string}|null
     */
    protected function forgejoRegistryCredentials(ConfigData $config, string $environment, string $registryHost): ?array
    {
        $login = ForgejoRegistryLogin::find(
            $registryHost,
            $this->environmentContextOrCurrent($config, $environment),
            fn (string $kubectl, string $namespace, string $secret, string $key): ?string => $this->readClusterSecretKey($kubectl, $namespace, $secret, $key),
        );

        if ($login === null) {
            return null;
        }

        $this->registerSecret($login['token']);
        $this->info("  🔑 Using the registry token LaraKube created for {$registryHost}.");

        return ['username' => $login['username'], 'token' => $login['token']];
    }

    /**
     * A registry credential LaraKube didn't create: from LARAKUBE_REGISTRY_USERNAME/TOKEN, else
     * asked for in a terminal. Null when neither is possible, e.g. when LaraKube Desktop runs this.
     */
    protected function registryCredential(string $which, string $label, bool $secret): ?string
    {
        $value = trim((string) (getenv('LARAKUBE_REGISTRY_'.$which) ?: ''));
        if ($value !== '') {
            if ($secret) {
                $this->registerSecret($value);
            }

            return $value;
        }

        if ($this->option('no-interaction') || app()->runningUnitTests() || ! stream_isatty(STDIN)) {
            return null;
        }

        return $secret ? password(label: $label, required: true) : text(label: $label, required: true);
    }

    /**
     * Build-time variables for a static site's bundle: the `.env.{env}` keys
     * the framework compiles into browser code (VITE_*, PUBLIC_*). They end up
     * public in the bundle anyway; nothing else from the file reaches CI.
     */
    protected function buildStaticPublicEnvScript(ConfigData $config, string $environment, string $indent = '          '): string
    {
        $prefixes = $config->framework?->publicEnvPrefixes() ?? [];
        $envPath = $config->getPath().'/.env.'.$environment;

        if ($prefixes === [] || ! file_exists($envPath)) {
            return '';
        }

        $lines = [];
        foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if (! preg_match('/^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/', $line, $m)) {
                continue;
            }

            foreach ($prefixes as $prefix) {
                if (str_starts_with($m[1], $prefix)) {
                    $value = trim($m[2]);
                    if (strlen($value) >= 2 && in_array($value[0], ['"', "'"], true) && $value[-1] === $value[0]) {
                        $value = substr($value, 1, -1);
                    }
                    $lines[] = 'echo '.escapeshellarg("{$m[1]}={$value}").' >> .env';

                    break;
                }
            }
        }

        return implode("\n{$indent}", $lines);
    }

    /**
     * A shell script fragment — one `echo 'KEY=VALUE' >> .env` line per
     * public/build variable, computed straight from the blueprint at
     * generation time. Baked as literal text into the generated CI
     * workflow (GHA + GitLab), same as today's hand-written VITE_APP_URL
     * line — no secret, no CI variable, nothing "uploaded" at all, because
     * none of these values were ever secret to begin with (VITE_* compiles
     * into a public JS bundle regardless). Runtime credentials never take
     * this path; only `dotenv:push` writes those, straight to the cluster.
     */
    protected function buildPublicEnvScript(ConfigData $config, string $environment, string $indent = '          '): string
    {
        $vars = $config->getAllPublicEnvironmentVariables($environment);

        if ($config->getPlex($environment) !== []) {
            $envPath = $config->getPath().'/'.($environment === 'local' ? '.env' : ".env.{$environment}");
            $fileVars = is_file($envPath) ? BackingServices::readEnv($envPath) : [];
            $plexVars = [];

            foreach ($config->getComponents($environment) as $component) {
                if (! $component instanceof HasEnvironmentVariables
                    || $component instanceof ServerVariation
                    || ! $config->isPlexBacked($component, $environment)) {
                    continue;
                }

                $secretKeys = array_keys($component->getSecretEnvironmentVariables($config, $environment));

                foreach ($component->getPublicEnvironmentVariables($config, $environment) as $key => $fallback) {
                    if ($this->isSecretKey($key, $secretKeys)) {
                        continue;
                    }

                    $fromFile = $fileVars[$key] ?? null;
                    $value = ($fromFile !== null && $fromFile !== '') ? $fromFile : (string) $fallback;

                    if (($fromFile === null || $fromFile === '')
                        && str_contains($value, '.svc.cluster.local')
                        && ! str_contains($value, '.larakube-plex.svc.cluster.local')) {
                        continue;
                    }

                    if ($this->hasEmbeddedCredentials($value)) {
                        continue;
                    }

                    $plexVars[$key] = $value;
                }

                if ($component === CacheDriver::REDIS && isset($fileVars['REDIS_DB']) && $fileVars['REDIS_DB'] !== '') {
                    $plexVars['REDIS_DB'] = $fileVars['REDIS_DB'];
                }
            }

            $vars = array_merge($plexVars, $vars);
        }

        $lines = [];
        foreach ($vars as $key => $value) {
            $lines[] = 'echo '.escapeshellarg("{$key}={$value}").' >> .env';
        }

        return implode("\n{$indent}", $lines);
    }

    protected function runRemoteCommand(array $cloud, string $remoteCommand): int
    {
        $sshCommand = "ssh -i {$cloud['key']} -p {$cloud['port']} {$cloud['user']}@{$cloud['ip']} ".escapeshellarg($remoteCommand);

        return $this->runStreaming($sshCommand);
    }

    // ── GitLab CI ────────────────────────────────────────────────────────────

    /**
     * Configure GitLab CI/CD for a single environment: upload secrets + regenerate
     * the `.gitlab-ci.yml` that covers ALL cloud environments in this project.
     */
    protected function configureGitlab(?string $environment = null, bool $rotate = false): int
    {
        $environment ??= $this->askForCloudEnvironment(
            label: 'Which environment are you configuring for GitLab CI?',
        );

        $projectPath = getcwd();
        $envFile = ".env.{$environment}";
        $initialConfig = $this->getProjectConfig($projectPath);

        // GitLab remote guard
        $remote = $this->resolveEnvironmentRemoteUrl($initialConfig, $environment);
        if (empty($remote)) {
            $this->laraKubeError('No git remote found. Add your GitLab repo as origin first:');
            $this->line('    git remote add origin git@gitlab.com:<group>/<repo>.git');

            return 1;
        }

        $projectGitlabPath = '';
        $parsedRemote = $this->parseGitRemote($remote);
        if (preg_match('/gitlab\.com[:\\/]([^:]+?)(?:\.git)?$/', $remote, $m)) {
            $projectGitlabPath = $m[1];
            $this->line("  <fg=gray>Targeting GitLab project:</> <fg=cyan>{$projectGitlabPath}</>");
        } elseif ($parsedRemote !== null) {
            $projectGitlabPath = $parsedRemote['slug'];
            $this->line("  <fg=gray>Targeting GitLab project:</> <fg=cyan>{$projectGitlabPath}</>");
        } else {
            $this->laraKubeWarn("Could not parse a GitLab project path from remote: {$remote}");
        }

        // Domain guard (same check as GHA/cloud:deploy) — also sweeps every
        // client-facing non-web host (Reverb, S3/CDN, …), which this GitLab
        // path used to skip.
        $config = $this->getProjectConfigObject($projectPath);
        $config->environments[$environment]->ciPlatform = 'gitlab';
        $previousHost = $config->getHost($environment, 'web');

        $host = $this->ensureHosts($config, $environment);

        if ($host !== $previousHost) {
            $this->syncEnvFile($projectPath, ['APP_URL' => 'https://'.$host], false, $environment);
            $this->alignEnvironmentAssetUrl($projectPath, $environment, $host);
            $this->laraKubeInfo("Updated APP_URL and ASSET_URL to https://{$host}");
        }

        $this->saveProjectConfig($projectPath, $config);

        if ($this->pendingPlexServices($config, $environment) !== []) {
            $plexContext = $this->environmentContextOrCurrent($config, $environment);
            if (! $plexContext) {
                $this->laraKubeError("No cluster target for '{$environment}' — link a server before provisioning Plex Commons.");
                $this->line('  Run <fg=yellow;options=bold>larakube cloud:configure '.$environment.' --only=target</> first.');

                return 1;
            }
            if (! $this->reconcilePendingPlexCommons($config, $environment, $plexContext, $projectPath)) {
                return 1;
            }
        }

        // Upload secrets via glab (if available) or print manual instructions
        $this->laraKubeInfo("Step 1: Uploading CI/CD variables for '{$environment}'...");
        $this->uploadGitlabVariables($projectPath, $environment, $projectGitlabPath, $rotate);

        // Ensured private-registry pull access
        $this->laraKubeInfo('Ensuring private-registry pull access in the cluster...');
        $this->setupRegistrySecret($environment);

        // Regenerate .gitlab-ci.yml covering ALL cloud environments
        $this->laraKubeInfo('Step 2: Generating GitLab CI pipeline...');
        $code = $this->generateGitlabPipeline($projectPath, $config);
        if ($code !== 0) {
            return $code;
        }

        $this->laraKubeInfo("✅ GitLab CI configured for '{$environment}'!");
        $this->line('  Push to the configured branch to trigger your deploy.');

        return 0;
    }

    protected function uploadGitlabVariables(string $projectPath, string $environment, string $projectPath2, bool $rotate): void
    {
        $upperEnv = strtoupper($environment);

        // Try glab CLI first
        $glabPath = $this->resolveGlabCommand();

        if (! $glabPath) {
            $this->laraKubeWarn('`glab` CLI not found — print the variables to set manually in GitLab → Settings → CI/CD → Variables.');
            $this->newLine();
        }

        // Build kubeconfig secret (scoped, same logic as GHA)
        $config = $this->getProjectConfigObject($projectPath);
        $cloud = $config->getCloud($environment);
        $context = $cloud?->context ?? ($cloud?->ip ? 'larakube-'.$cloud->ip : '');

        if ($context !== '') {
            $kubeconfig = trim(Process::run(
                Kubectl::forContext($context)->prefix().' config view --minify --raw',
            )->output());
            $kubeconfigB64 = base64_encode($kubeconfig);

            if ($glabPath) {
                Process::run("{$glabPath} variable set {$upperEnv}_KUBECONFIG ".escapeshellarg($kubeconfigB64).' --masked --protected');
                $this->line("  <fg=gray>Uploaded</> {$upperEnv}_KUBECONFIG");
            } else {
                $this->line("  <fg=yellow>{$upperEnv}_KUBECONFIG</> = <fg=gray>(base64 kubeconfig — run: kubectl config view --context {$context} --minify --raw | base64)</>");
            }
        } else {
            $this->laraKubeWarn("No cluster context for '{$environment}' — skipping kubeconfig upload. Run `larakube cloud:init` first.");
        }

        // Runtime credentials never enter CI; only build-time variables
        // (.env.{env} for static sites, or VITE_*/PUBLIC_*/NEXT_PUBLIC_* for
        // hybrid apps) are uploaded as {ENV}_BUILD_ENV when present.
        if (($buildEnv = $this->buildEnvPayload($config, $environment, $projectPath)) !== '') {
            if ($glabPath) {
                Process::run("{$glabPath} variable set {$upperEnv}_BUILD_ENV ".escapeshellarg($buildEnv));
                $this->line("  <fg=gray>Uploaded</> {$upperEnv}_BUILD_ENV");
            } else {
                $this->line("  <fg=yellow>{$upperEnv}_BUILD_ENV</> = <fg=gray>(build-time variables from .env.{$environment})</>");
            }
        }

        // Upload registry secrets if using a non-native (non-GitLab) private registry
        $registry = $config->getRegistry($environment);
        if ($registry && $registry->provider !== RegistryProvider::GITLAB) {
            $regUser = text(label: 'Registry Username for '.$registry->provider->label(), required: true);
            $regPass = password(label: 'Registry Password/Token for '.$registry->provider->label(), required: true);

            if ($glabPath) {
                Process::run("{$glabPath} variable set {$upperEnv}_REGISTRY_USERNAME ".escapeshellarg($regUser).' --masked --protected');
                Process::run("{$glabPath} variable set {$upperEnv}_REGISTRY_PASSWORD ".escapeshellarg($regPass).' --masked --protected');
                $this->line("  <fg=gray>Uploaded</> {$upperEnv}_REGISTRY_USERNAME & {$upperEnv}_REGISTRY_PASSWORD");
            } else {
                $this->line("  <fg=yellow>{$upperEnv}_REGISTRY_USERNAME</> = {$regUser}");
                $this->line("  <fg=yellow>{$upperEnv}_REGISTRY_PASSWORD</> = (password)");
            }
        }
    }

    /**
     * Deployment name CI waits on. getServerVariation() is null for every
     * static-SPA project — those deploy a Caddy Deployment named `web`, not a
     * PHP pod — so dereferencing it unguarded fataled before any workflow was
     * written.
     */
    protected function deploymentPodName(ConfigData $config): string
    {
        return $config->getServerVariation()?->getPodName($config)
            ?? $config->framework?->workloadName((string) $config->getName())
            ?? 'web';
    }

    protected function generateGitlabPipeline(string $projectPath, ConfigData $config): int
    {
        $appName = $config->getName();
        $podName = $this->deploymentPodName($config);
        $cloudEnvs = [];

        foreach ($config->getCloudEnvironments() as $envName) {
            $env = $config->getEnvironmentData($envName);
            if (! $env?->cloud) {
                continue;
            }

            $registry = $config->getRegistry($envName);
            $registryProvider = $registry?->provider->value ?? 'gitlab';
            $registryHost = $registry?->getRegistryHost() ?? '$CI_REGISTRY';
            $imagePath = $registry?->image ?? '$CI_PROJECT_PATH';

            $cloudEnvs[$envName] = [
                'branch' => 'main',
                'upperName' => strtoupper($envName),
                'namespace' => $appName.'-'.$envName,
                'registry' => $registryProvider,
                'registry_host' => $registryHost,
                'imageLatest' => $registryProvider === 'gitlab'
                    ? '$CI_REGISTRY/$CI_PROJECT_PATH:latest'
                    : "{$registryHost}/{$imagePath}:latest",
                'imageSha' => $registryProvider === 'gitlab'
                    ? '$CI_REGISTRY/$CI_PROJECT_PATH:$CI_COMMIT_SHA'
                    : "{$registryHost}/{$imagePath}:\$CI_COMMIT_SHA",
                'static' => (bool) $config->framework?->isStaticSpa(),
                'php' => $config->framework === null || $config->framework->isPhp(),
                'dockerfile' => ($config->framework ?? AppFramework::LARAVEL)->dockerfile(),
                'target' => ($config->framework ?? AppFramework::LARAVEL)->buildTarget(),
                'publicEnvScript' => $config->framework !== null && ! $config->framework->isPhp()
                    ? $this->buildStaticPublicEnvScript($config, $envName, '      ')
                    : $this->buildPublicEnvScript($config, $envName, '      '),
            ];
        }

        if (empty($cloudEnvs)) {
            $this->laraKubeWarn('No cloud environments with a deploy target found — run `larakube cloud:init` or `cloud:configure` first.');

            return 1;
        }

        // Ask for deploy branch per environment. A --branch flag applies to
        // every env in this run — headless callers configure one env at a time.
        foreach ($cloudEnvs as $envName => &$meta) {
            $branch = $this->flag('branch') ?: text(
                label: "Which branch triggers the {$envName} deployment?",
                default: $envName === 'production' ? 'main' : $envName,
                required: true,
            );
            $meta['branch'] = $branch;
        }
        unset($meta);

        $pipelineContent = view('k8s.cloud-pilot-deploy-gitlab', [
            'config' => $config,
            'appName' => $appName,
            'podName' => $podName,
            'cloudEnvs' => $cloudEnvs,
        ])->render();

        file_put_contents($projectPath.'/.gitlab-ci.yml', $pipelineContent);
        $this->line('  <fg=gray>Pipeline written to:</> .gitlab-ci.yml');

        return 0;
    }
}
