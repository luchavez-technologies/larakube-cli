<?php

namespace App\Traits;

use App\Contracts\PlexProvisionable;
use App\Data\ConfigData;
use App\Data\EnvironmentData;
use App\Data\GlobalConfigData;
use App\Enums\RegistryProvider;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\password;
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
    // The deploy-target picker (managed kube-context or VPS) lives here, so
    // configureBase can record a managed cluster without hand-edited config.
    // GathersEnvironmentData is the same ingress/managed-services/registry
    // wizard `larakube env` uses, so an environment created on demand here
    // (§ below) is indistinguishable from one created via `env`. EnsuresRealHosts
    // is the same local/placeholder-host guard `cloud:deploy` uses.
    use EnsuresRealHosts, GathersEnvironmentData, InteractsWithVpn, ResolvesEnvironmentContext;
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
            $config->addEnvironment($environment, new EnvironmentData(
                ingress: $this->gatherEnvironmentIngress($config, $environment),
                managed: $this->gatherEnvironmentManaged($config, $environment),
                additionalWebHosts: $this->gatherAdditionalWebHosts($config, $environment),
            ));
            $this->saveProjectConfig($projectPath, $config);

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
        }

        $this->laraKubeInfo("Configuring the deploy target for '{$environment}'...");

        // Pick a managed kube-context (DOKS/EKS/…) OR a VPS. This OVERWRITES any
        // existing cloud config, so a re-run on an already-configured env asks
        // for confirmation first — everything else here is idempotent (skips
        // re-prompting when a real value already exists), this is the one step
        // that isn't, so it needs its own guard against an accidental reset.
        $existingCloud = $config->getCloud($environment);
        $hasExistingTarget = $existingCloud !== null && ($existingCloud->ip !== null || $existingCloud->context !== null);

        if (! $hasExistingTarget || confirm(
            "'{$environment}' already targets '".($existingCloud->context ?? $existingCloud->ip)."' — reconfigure the deploy target?",
            false,
        )) {
            $config = $this->promptCloudTarget($config, $environment, $projectPath);
        }

        // 🌐 Ensure a real web host + every client-facing non-web host (Reverb WS,
        // S3/CDN public endpoint, …) — the same guard `cloud:deploy` and the CI
        // steps use, so a placeholder or local value can't slip through whichever
        // entry point the user happens to use.
        $this->ensureHosts($config, $environment);

        $this->saveProjectConfig($projectPath, $config);

        // New environments need their manifests generated at least once — an
        // existing env's overlays are already current (every `up`/`env`/`heal`
        // regenerates them), so this only fires when there's actually something
        // missing on disk.
        if ($isNewEnvironment) {
            $this->withSpin("Generating manifests for '{$environment}'...", function () use ($config) {
                $this->orchestrateProjectScaffolding($config, false, false);

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
        $this->laraKubeInfo("✅ Hosts saved for '{$environment}'.");

        return 0;
    }

    /**
     * Detect which CI forge this project's git remote points at, so the guided
     * flow and `--only=ci` call the right existing workflow generator without
     * a `--platform` flag — that's the bigger forge/registry axis from
     * paas-core-expansion.md §7.1, deferred. Defaults to GitHub when the
     * remote is missing/unrecognized, matching today's behavior.
     */
    protected function detectCiPlatform(): string
    {
        $platform = $this->flag('platform');
        if ($platform) {
            return $platform;
        }

        $remote = $this->gitRemoteUrl();
        if (str_contains($remote, 'gitlab.com')) {
            return 'gitlab';
        }

        if (str_contains($remote, 'gitea') || str_contains($remote, 'git.')) {
            return 'gitea';
        }

        return 'github';
    }

    /**
     * The project's `origin` remote URL, or '' if there's none (or no git
     * repo at all). Split out from detectCiPlatform() so tests can simulate
     * a remote by overriding this one method — no real git binary/repo
     * needed to exercise the actual GitHub/GitLab dispatch logic.
     */
    protected function gitRemoteUrl(): string
    {
        return trim(Process::run('git remote get-url origin')->output());
    }

    /**
     * Dispatch to the right CI workflow generator for the detected forge —
     * the `--only=ci` step of `cloud:configure` (replaces the old
     * `cloud:configure:gha` / `:gitlab`).
     */
    protected function configureCi(?string $environment, bool $rotate): int
    {
        return $this->detectCiPlatform() === 'gitlab'
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
     * Prompt to join a shared Commons when the project's drivers map to a
     * plex-ready Commons service (a Postgres/MySQL/MariaDB database, Redis,
     * Meilisearch, or S3 via SeaweedFS/MinIO). plex:join rewrites .env and
     * regenerates manifests, so it must run before the CI secret upload reads
     * the .env.
     */
    protected function maybeJoinCommons(string $environment): void
    {
        $config = $this->getProjectConfigObject(getcwd());

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
        $environment ??= $this->askForCloudEnvironment(
            label: 'Which environment are you configuring for GitHub Actions?',
        );

        $projectPath = getcwd();
        $envFile = ".env.{$environment}";

        // GitHub repo guard — fail fast before any prompts.
        $remote = $this->gitRemoteUrl();
        if (empty($remote)) {
            $this->laraKubeError('No GitHub remote found. Create a GitHub repository and add it as origin first:');
            $this->line('    git remote add origin git@github.com:<owner>/<repo>.git');

            return 1;
        }

        $repoFlag = '';
        if (preg_match('/(?:github\.com|github)[:\/](.*?)(?:\.git)?$/', $remote, $m)) {
            $repoFlag = '-R '.$m[1];
            $this->info("  🛰 Targeting repository: {$m[1]}");
        } else {
            $this->laraKubeWarn("Could not parse GitHub repository from remote URL: {$remote}");
        }

        $this->laraKubeWarn("🛡 SECURITY CHECK: GitHub Actions will use your local '{$envFile}' as the source of truth.");

        // Domain guard — same check as cloud:deploy and configureBase. Fires when
        // the web host is missing or still a local .kube/.dev.test value. Rewrites
        // APP_URL + ASSET_URL in the env file before uploading, so the secret
        // never ships a local URL.
        $config = $this->getProjectConfigObject($projectPath);
        $previousHost = $config->getHost($environment, 'web');

        $host = $this->ensureHosts($config, $environment);

        if ($host !== $previousHost) {
            $this->syncEnvFile($projectPath, ['APP_URL' => 'https://'.$host], false, $environment);
            $this->alignEnvironmentAssetUrl($projectPath, $environment, $host);
            $this->laraKubeInfo("Updated APP_URL and ASSET_URL to https://{$host}");
        } else {
            $this->line('  Please ensure you have set your production-ready values (APP_KEY, APP_URL, etc.) in this file.');
        }

        $this->saveProjectConfig($projectPath, $config);

        if (! confirm("Upload '{$envFile}' and your Kubeconfig to GitHub?", true)) {
            $this->laraKubeInfo('Action cancelled.');

            return 0;
        }

        // 1. Upload secrets (env file + scoped kubeconfig). Abort if this fails —
        //    there's no point generating a workflow with no/broken credentials.
        $this->laraKubeInfo("Step 1: Configuring GitHub Secrets for '{$environment}'...");
        if ($this->uploadGhaSecrets($projectPath, $environment, $repoFlag, $rotate) !== 0) {
            $this->laraKubeError('GitHub secret configuration failed — aborting before generating the workflow.');

            return 1;
        }

        // 2. Pull secret for a private registry — created via the cluster
        //    API (works on VPS and managed clusters; self-skips for public registries).
        $this->laraKubeInfo('Step 2: Ensuring private-registry pull access...');
        $this->setupRegistrySecret($environment);

        // 2.5. Offer to connect CI to the environment's NetBird VPN — a no-op
        //      unless one is actually installed (self-skips silently).
        $gh = $this->getGhCommand();
        $vpnHost = $this->ensureCiVpnSecret(
            $this->getProjectConfigObject($projectPath),
            $environment,
            $projectPath,
            fn (string $name, string $value) => $this->setGithubSecret($gh, $name, $value, $repoFlag),
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
        $podName = $config->getServerVariation()->getPodName($config);
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

        $workflowContent = view('k8s.cloud-pilot-deploy', [
            'config' => $config,
            'environment' => $environment,
            'branch' => $branch,
            'appName' => $appName,
            'namespace' => $namespace,
            'podName' => $podName,
            'upperEnv' => $upperEnv,
            'vpnHost' => $vpnHost,
            'secrets' => [
                'k_env' => '${{ secrets.'.$upperEnv.'_KUBECONFIG }}',
                'k_base' => '${{ secrets.KUBECONFIG }}',
                'e_env' => '${{ secrets.'.$upperEnv.'_ENV_FILE_BASE64 }}',
                'e_base' => '${{ secrets.ENV_FILE_BASE64 }}',
                'vpn_key' => '${{ secrets.'.$upperEnv.'_NETBIRD_SETUP_KEY }}',
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
                'e_data' => '${{ env.E_DATA }}',
                // GitHub expressions must be passed as values (not hardcoded as
                // literal text in the Blade), or Blade mangles the inner {{ }}.
                'image_latest' => '${{ env.REGISTRY_HOST }}/${{ env.IMAGE_NAME }}:latest',
                'image_sha' => '${{ env.REGISTRY_HOST }}/${{ env.IMAGE_NAME }}:${{ github.sha }}',
                'composer_cache_key' => "composer-\${{ hashFiles('composer.lock') }}",
                'registry_user' => '${{ secrets.'.$upperEnv.'_REGISTRY_USERNAME }}',
                'registry_password' => '${{ secrets.'.$upperEnv.'_REGISTRY_PASSWORD }}',
            ],
        ])->render();

        file_put_contents($workflowPath, $workflowContent);

        $this->laraKubeInfo("✅ GitHub Actions configured successfully for '{$environment}'!");
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

        $vpnKubectl = $this->contextKubectl($context);
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
        } elseif ($provider === RegistryProvider::GITEA) {
            $username = text(label: 'Gitea Username', required: true);
            $token = password(label: 'Gitea Password (or Personal Access Token)', required: true);
            $server = 'https://'.($registry->host ?? 'git.dev.test');
            $secretName = 'gitea-login';
        }

        if (! $username || ! $token || ! $server || ! $secretName) {
            return;
        }

        $namespace = $config->getName().'-'.$environment;
        $kubectl = $this->contextKubectl($context);
        $ns = escapeshellarg($namespace);

        Process::run("{$kubectl} create namespace {$ns} --dry-run=client -o yaml | {$kubectl} apply -f -");
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
    protected function uploadGhaSecrets(string $projectPath, string $environment, string $repoFlag, bool $rotate = false): int
    {
        $gh = $this->getGhCommand();
        $upperEnv = strtoupper($environment);
        $envFile = ".env.{$environment}";

        // Env file check.
        if (! file_exists($projectPath.'/'.$envFile)) {
            $this->laraKubeError("Crucial file '{$envFile}' is missing!");

            return 1;
        }

        $envContent = (string) file_get_contents($projectPath.'/'.$envFile);
        if (empty(trim($envContent))) {
            $this->laraKubeError("File '{$envFile}' is empty! Cannot upload an empty environment.");

            return 1;
        }

        // Local-URL scan — catches any remaining local values (localhost, 127.0.0.1)
        // that the domain guard above didn't fix. Local TLD domains are already
        // resolved by the domain guard that runs before this point.
        if (! $this->confirmNoLocalUrls($envFile, $envContent)) {
            return 1;
        }

        $base64Env = base64_encode($envContent);
        $this->info('  📦 Env size: '.strlen($base64Env).' bytes (base64)');
        $this->setGithubSecret($gh, "{$upperEnv}_ENV_FILE_BASE64", $base64Env, $repoFlag);

        $config = $this->getProjectConfigObject($projectPath);
        $registry = $config->getRegistry($environment);
        if ($registry && $registry->provider !== RegistryProvider::GHCR) {
            $regUser = text(label: 'Registry Username for '.$registry->provider->label(), required: true);
            $regPass = password(label: 'Registry Password/Token for '.$registry->provider->label(), required: true);

            $this->setGithubSecret($gh, "{$upperEnv}_REGISTRY_USERNAME", $regUser, $repoFlag);
            $this->setGithubSecret($gh, "{$upperEnv}_REGISTRY_PASSWORD", $regPass, $repoFlag);
        }

        // Mint + upload a namespace-scoped kubeconfig.
        $this->laraKubeInfo("Minting a namespace-scoped KUBECONFIG for {$environment}...");
        $config = $this->getProjectConfigObject($projectPath);
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
        $kubectl = $this->contextKubectl($adminContext);
        $ns = escapeshellarg($namespace);

        Process::run("{$kubectl} create namespace {$ns} --dry-run=client -o yaml | {$kubectl} apply -f -");

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

        $this->setGithubSecret($gh, "{$upperEnv}_KUBECONFIG", $kubeConfigContent, $repoFlag);

        // Stamp when the scoped CI credential was minted.
        $data = $config->toArray();
        $data['environments'][$environment]['cloud']['rbacGrantedAt'] = gmdate('c');
        ConfigData::from($data)->saveToFile($projectPath);

        $this->laraKubeInfo("GitHub Secrets configured successfully for '{$environment}' (namespace-scoped).");

        return 0;
    }

    protected function setGithubSecret(string $gh, string $name, string $value, string $repoFlag): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'gh_secret');
        file_put_contents($tmpFile, $value);

        $command = 'cat '.escapeshellarg($tmpFile)." | {$gh} secret set ".escapeshellarg($name)." {$repoFlag}";
        $result = Process::run($command);
        @unlink($tmpFile);

        if (! $result->successful()) {
            $this->laraKubeError("Failed to set GitHub secret: {$name}");
            foreach (explode("\n", trim($result->output().$result->errorOutput())) as $line) {
                $this->line("  <fg=red>{$line}</>");
            }
            exit(1);
        }

        $this->info("  ✅ Secret '{$name}' uploaded successfully.");
    }

    protected function confirmNoLocalUrls(string $envFile, string $envContent): bool
    {
        $localTldPatterns = [];
        foreach (GlobalConfigData::ALLOWED_TLDS as $tld) {
            $escaped = preg_quote($tld, '/');
            $localTldPatterns["/\\.{$escaped}(\\/|:|$)/"] = ".{$tld} (local dev domain)";
        }

        $localPatterns = array_merge($localTldPatterns, [
            '/\blocalhost\b/' => 'localhost',
            '/\b127\.0\.0\.1\b/' => '127.0.0.1',
        ]);

        $hits = [];
        foreach (explode("\n", $envContent) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
            foreach ($localPatterns as $pattern => $label) {
                if (preg_match($pattern, $value)) {
                    $hits[] = [$key, $value, $label];
                    break;
                }
            }
        }

        if (empty($hits)) {
            return true;
        }

        $this->laraKubeWarn("{$envFile} contains local-only URLs — these won't work in production:");
        foreach ($hits as [$key, $value, $label]) {
            $this->line("    <fg=yellow>{$key}</>=<fg=red>{$value}</> <fg=gray>({$label})</>");
        }
        $this->newLine();

        return confirm('Upload anyway?', false);
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

        // GitLab remote guard
        $remote = $this->gitRemoteUrl();
        if (empty($remote)) {
            $this->laraKubeError('No git remote found. Add your GitLab repo as origin first:');
            $this->line('    git remote add origin git@gitlab.com:<group>/<repo>.git');

            return 1;
        }

        $projectGitlabPath = '';
        if (preg_match('/gitlab\.com[:\\/]([^:]+?)(?:\.git)?$/', $remote, $m)) {
            $projectGitlabPath = $m[1];
            $this->line("  <fg=gray>Targeting GitLab project:</> <fg=cyan>{$projectGitlabPath}</>");
        } else {
            $this->laraKubeWarn("Could not parse a GitLab project path from remote: {$remote}");
        }

        // Domain guard (same check as GHA/cloud:deploy) — also sweeps every
        // client-facing non-web host (Reverb, S3/CDN, …), which this GitLab
        // path used to skip.
        $config = $this->getProjectConfigObject($projectPath);
        $previousHost = $config->getHost($environment, 'web');

        $host = $this->ensureHosts($config, $environment);

        if ($host !== $previousHost) {
            $this->syncEnvFile($projectPath, ['APP_URL' => 'https://'.$host], false, $environment);
            $this->alignEnvironmentAssetUrl($projectPath, $environment, $host);
            $this->laraKubeInfo("Updated APP_URL and ASSET_URL to https://{$host}");
        }

        $this->saveProjectConfig($projectPath, $config);

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
        $envFile = ".env.{$environment}";

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
                $this->contextKubectl($context).' config view --minify --raw',
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

        // Env file secret
        $envFilePath = $projectPath.'/'.$envFile;
        if (file_exists($envFilePath)) {
            $envB64 = base64_encode((string) file_get_contents($envFilePath));

            if ($glabPath) {
                Process::run("{$glabPath} variable set {$upperEnv}_ENV_FILE_BASE64 ".escapeshellarg($envB64).' --masked --protected');
                $this->line("  <fg=gray>Uploaded</> {$upperEnv}_ENV_FILE_BASE64");
            } else {
                $this->line("  <fg=yellow>{$upperEnv}_ENV_FILE_BASE64</> = <fg=gray>(base64 of {$envFile} — run: base64 {$envFile})</>");
            }
        } else {
            $this->laraKubeWarn("{$envFile} not found — create it before uploading.");
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

    protected function generateGitlabPipeline(string $projectPath, ConfigData $config): int
    {
        $appName = $config->getName();
        $podName = $config->getServerVariation()->getPodName($config);
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

            $webHost = (string) $config->getWebHost($envName);

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
                'webHost' => $webHost,
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

    protected function resolveGlabCommand(): ?string
    {
        $candidates = array_filter([
            trim(Process::run('command -v glab')->output()),
            '/usr/local/bin/glab',
            '/opt/homebrew/bin/glab',
            '/home/linuxbrew/.linuxbrew/bin/glab',
        ]);

        foreach ($candidates as $path) {
            if ($path !== '' && @is_executable($path)) {
                return $path;
            }
        }

        return null;
    }
}
