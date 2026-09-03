<?php

namespace App\Commands\Cloud;

use App\Data\ConfigData;
use App\Data\RegistryData;
use App\Enums\RegistryProvider;
use App\Traits\EnsuresRealHosts;
use App\Traits\GeneratesProjectInfrastructure;
use App\Traits\GuardsSharedStorage;
use App\Traits\InteractsWithEnvironments;
use App\Traits\InteractsWithKustomize;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\InteractsWithRemoteDeploy;
use App\Traits\InteractsWithScopedRbac;
use App\Traits\LaraKubeOutput;
use App\Traits\PromotesIngressDns;
use App\Traits\ResolvesEnvironmentContext;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\confirm;

use LaravelZero\Framework\Commands\Command;

class CloudDeployCommand extends Command
{
    // getGhCommand() comes via LaraKubeOutput → InteractsWithGlobalConfig.
    // EnsuresRealHosts is the same local/placeholder-host guard cloud:configure uses.
    use EnsuresRealHosts, GeneratesProjectInfrastructure, GuardsSharedStorage, InteractsWithEnvironments, InteractsWithKustomize, InteractsWithProjectConfig, InteractsWithRemoteDeploy, InteractsWithScopedRbac, LaraKubeOutput, PromotesIngressDns, ResolvesEnvironmentContext;

    protected $signature = 'cloud:deploy
        {environment? : The environment to deploy to}
        {--force : Skip the multi-node shared-storage safety check}';

    protected $description = 'Build and deploy the application to a remote environment';

    public function handle(): int
    {
        $this->renderHeader();

        $environment = $this->argument('environment') ?: $this->askForCloudEnvironment(
            label: 'Which environment are you deploying to?',
        );

        if ($environment === 'local') {
            $this->laraKubeInfo("For local development, please use 'larakube up'.");

            return 0;
        }

        $this->laraKubeWarn('🚀 MANUAL DEPLOYMENT');
        $this->line('   This command will deploy directly from your local machine to the remote cluster.');
        $this->line('   <fg=gray>Note: GitHub Actions (CI/CD) is the recommended path for professional teams.</>');
        $this->newLine();

        $projectPath = getcwd();
        $config = $this->getProjectConfig($projectPath);

        // A static site has no image, no Dockerfile.php and no PHP pod, so it
        // shares none of the path below: it builds a bundle, publishes it to the
        // Commons bucket, and points the Caddy Deployment at the new release.
        if ($config->framework?->isStaticSpa()) {
            return $this->deployStaticSite($config, $environment, $projectPath);
        }

        if ($config->hasGithubActions() && ! $this->option('no-interaction')) {
            $this->info('💡 CI/CD DETECTED: You have GitHub Actions enabled for this project.');
            if (confirm('Would you prefer to trigger a deployment via Git push instead?', true)) {
                $this->laraKubeInfo('Action cancelled. Run "git push" to deploy via GitHub.');

                return 0;
            }
        }

        $appName = $config->getName() ?? basename($projectPath);

        // --- 🌐 WEB + CLIENT-FACING HOST GUARD (any cloud env) ---
        // Same guard `cloud:configure`'s base/ci steps use — fires for every
        // non-local env if the web host (or a Reverb/S3/CDN host) is missing, still
        // the `{name}.com` placeholder, or a local .kube/.dev.test value that must
        // never ship to a remote environment.
        $previousHost = $config->getHost($environment, 'web');
        $host = $this->ensureHosts($config, $environment);

        if ($host !== $previousHost) {
            $this->saveProjectConfig($projectPath, $config);

            // Reflect the domain in the env file's APP_URL. syncEnvFile targets
            // this env's own .env.<environment> (creating it from .env if needed),
            // so it's uniform across production, staging, etc. Narrow on purpose
            // (only APP_URL) rather than a full syncEnv, so we never clobber other
            // env values — e.g. a Plex-managed DB_HOST. (The manifest regen below
            // uses syncEnv:false for the same reason.)
            $this->syncEnvFile($projectPath, ['APP_URL' => 'https://'.$host], false, $environment);
        }

        // First-deploy safety net: if `larakube env {$environment}` was never
        // run, .env.{$environment} doesn't exist yet, and the narrow APP_URL
        // patch above is the only per-env value ever written — APP_ENV,
        // APP_DEBUG, DB_HOST, REDIS_HOST, VITE_URL, etc. would otherwise stay
        // whatever a lazy copy-from-local seeded them as. Seed the full
        // computed set for THIS environment only (never
        // orchestrateProjectScaffolding's syncEnv:true below, which loops
        // over every configured cloud environment and would risk clobbering
        // already-deployed envs' Plex-managed values).
        $envFilePath = $projectPath.'/.env.'.$environment;
        if (! file_exists($envFilePath)) {
            $this->laraKubeInfo("No .env.{$environment} found — seeding it with '{$environment}'-scoped values...");
            $this->syncEnvFile($projectPath, $config->getAllEnvironmentVariables($environment), false, $environment);
        }

        // Keep ASSET_URL aligned with this environment's web domain. @vite
        // prefixes asset URLs with ASSET_URL, so a leaked local "*.dev.test" or
        // "*.kube" value sends deployed assets to the dev host (404 / unstyled). Runs
        // for every cloud env on every deploy and only rewrites an empty or local
        // value, never a real CDN/asset host.
        $this->alignEnvironmentAssetUrl($projectPath, $environment, $host);

        // Always regenerate manifests from the blueprint, so a CLI upgrade or a
        // blueprint change is reflected on every deploy — not only when the domain
        // was just set above.
        $this->withSpin('Regenerating manifests from your blueprint...', function () use ($config) {
            $this->orchestrateProjectScaffolding($config, installFeatures: false, buildImage: false, syncEnv: false);

            return true;
        });

        // Resolve the env's deploy target + its OWN kube-context. It lives in
        // .larakube.json (environments.{env}.cloud); if it's not recorded yet
        // (e.g. the server was provisioned before this was persisted), ask once
        // and save it — so the target is in the blueprint and future deploys are
        // zero-prompt. Never the global current-context, so local dev pointed
        // elsewhere is undisturbed.
        [$config, $context] = $this->resolveEnvironmentContext($config, $environment, $projectPath);
        $cloud = $config->getCloud($environment);

        // A managed cluster (context, no IP) can't be SSH-sideloaded, so it needs
        // a registry to push to. Fail clearly instead of falling into the SSH path.
        $registry = $config->getRegistry($environment);
        if ($cloud && $cloud->isManaged() && ! $registry) {
            $this->laraKubeError("'{$environment}' targets a managed cluster ('{$cloud->context}') but has no registry configured.");
            $this->line("   <fg=gray>Managed clusters can't be SSH-sideloaded. Run</> <fg=yellow>larakube cloud:configure {$environment} --only=registry</> <fg=gray>first.</>");

            return 1;
        }

        // Preflight: block the silent multi-node shared-storage trap before the
        // (slow) build — fires on a multi-node cluster with SQLite or worker pods
        // that share the RWO app-storage PVC.
        if (! $this->guardSharedStorage($config, $environment, $context)) {
            return 1;
        }

        $this->laraKubeInfo("Deploying '{$appName}' to '{$environment}' on context '{$context}'.");
        if ($registry) {
            $this->line('   <fg=gray>Builds locally, pushes to '.$registry->getRegistryHost().', applies manifests.</>');
        } else {
            $this->line('   <fg=gray>Builds locally, sideloads the image into the remote node (no registry), applies manifests.</>');
        }
        $this->newLine();

        if (! confirm('Proceed?', true)) {
            $this->laraKubeInfo('Deployment cancelled.');

            return 0;
        }

        // Authenticate to the registry BEFORE the (slow) build, so a missing login
        // fails fast with a fix instead of a cryptic "denied" after the build.
        if ($registry && ! $this->ensureRegistryLogin($registry)) {
            return 1;
        }

        $result = $registry
            ? $this->deployViaRegistry($config, $environment)
            : $this->deployViaSshSideload($config, $environment);

        // After a successful MANAGED deploy, remind where to point DNS — every host
        // on the cluster shares the ingress LoadBalancer IP, so promote CNAMEs.
        if ($result === 0 && $cloud && $cloud->isManaged()) {
            $hosts = $config->getWebHosts($environment);
            if ($hosts !== []) {
                $this->printIngressDnsGuidance($hosts, $this->traefikLoadBalancerIp($context));
            }
        }

        return $result;
    }

    /**
     * Deploy a static site.
     *
     * Goes through the SAME image path as every other framework — build, then
     * either SSH-sideload into the node or push to a registry — rather than the
     * bespoke S3 publish this used to have. The S3 design existed to avoid
     * needing a registry, but LaraKube already deploys registry-less to a VPS
     * via `docker save | ssh … ctr images import`, so it was solving a problem
     * the codebase had solved more simply. Dropping it also removes the Commons
     * dependency and makes `kubectl rollout undo` a real rollback.
     */
    protected function deployStaticSite(ConfigData $config, string $environment, string $projectPath): int
    {
        $host = $this->ensureHosts($config, $environment);
        $this->saveProjectConfig($projectPath, $config);

        // Vite compiles VITE_* values INTO the bundle at build time, so this
        // file has to be right before the image is built — afterwards the bytes
        // are fixed. Dockerfile.static fails the build if it finds local hosts.
        $envFilePath = $projectPath.'/.env.'.$environment;
        if (! file_exists($envFilePath) && file_exists($projectPath.'/.env')) {
            copy($projectPath.'/.env', $envFilePath);
            $this->laraKubeWarn("Created .env.{$environment} from .env — it still points at LOCAL hosts.");
            $this->line("   <fg=gray>Run</> <fg=yellow>larakube data:wire {$environment}</> <fg=gray>before deploying.</>");
        }

        [$config, $context] = $this->resolveEnvironmentContext($config, $environment, $projectPath);
        $cloud = $config->getCloud($environment);
        $registry = $config->getRegistry($environment);

        if ($cloud && $cloud->isManaged() && ! $registry) {
            $this->laraKubeError("'{$environment}' targets a managed cluster ('{$cloud->context}') but has no registry configured.");
            $this->line("   <fg=gray>Managed clusters can't be SSH-sideloaded. Run</> <fg=yellow>larakube cloud:configure {$environment} --only=registry</> <fg=gray>first.</>");

            return 1;
        }

        $this->laraKubeInfo("Deploying static site '{$config->getName()}' to '{$environment}' ({$host}).");
        $this->line($registry
            ? '   <fg=gray>Builds the bundle in Node, serves it from Caddy, pushes to '.$registry->getRegistryHost().'.</>'
            : '   <fg=gray>Builds the bundle in Node, serves it from Caddy, sideloads into the node — no registry needed.</>');
        $this->newLine();

        if (! $this->option('no-interaction') && ! confirm('Proceed?', true)) {
            $this->laraKubeInfo('Deployment cancelled.');

            return 0;
        }

        $this->withSpin('Regenerating manifests from your blueprint...', function () use ($config) {
            $this->orchestrateProjectScaffolding($config, installFeatures: false, buildImage: false, syncEnv: false);

            return true;
        });

        if ($registry && ! $this->ensureRegistryLogin($registry)) {
            return 1;
        }

        return $registry
            ? $this->deployViaRegistry($config, $environment)
            : $this->deployViaSshSideload($config, $environment);
    }

    /**
     * Make sure docker can push to the env's registry before we build. For GHCR we
     * can log in with the GitHub CLI token; otherwise (or if that token lacks the
     * write:packages scope) we stop with a copy-pasteable fix rather than letting
     * the push fail with a cryptic "denied" after a long build.
     */
    private function ensureRegistryLogin(RegistryData $registry): bool
    {
        $host = $registry->getRegistryHost();

        if ($registry->provider === RegistryProvider::GHCR) {
            $gh = $this->getGhCommand();
            $user = trim(Process::run("{$gh} api user -q .login")->output());
            $token = trim(Process::run("{$gh} auth token")->output());

            if ($user !== '' && $token !== '') {
                $this->laraKubeInfo("Logging in to {$host} as {$user} (via GitHub CLI)...");
                if (Process::run($this->dockerLoginCommand($host, $user, $token))->successful()) {
                    return true;
                }
                $this->laraKubeWarn('Logged in, but the token lacks the write:packages scope GHCR push needs.');
            }

            // Stay zero-dependency: everything goes through `larakube gha:login`,
            // which runs gh in Docker and (now) requests write:packages. No raw
            // `gh`/`docker login` for the user to type.
            $this->laraKubeError("Not authenticated to push to {$host}.");
            $this->line('   <fg=gray>Run</> <fg=yellow>larakube gha:login</> <fg=gray>(grants the write:packages scope), then re-run the deploy.</>');
            $this->line('   <fg=gray>(The private-image pull secret is created automatically during deploy.)</>');

            return false;
        }

        // Docker Hub / others: we can't mint a token — just verify a session exists.
        // </dev/null keeps a missing session from hanging on a credential prompt.
        if (! Process::run('docker login '.escapeshellarg($host).' </dev/null')->successful()) {
            $this->laraKubeError("Not authenticated to {$host}.");
            $this->line("   <fg=gray>Run</> <fg=yellow>docker login {$host}</> <fg=gray>then re-run the deploy.</>");

            return false;
        }

        return true;
    }
}
