<?php

namespace App\Traits;

use App\Contracts\HasKubernetesFiles;
use App\Contracts\RemovableWhenManaged;
use App\Data\ConfigData;
use App\Data\GlobalConfigData;
use App\Enums\AppFramework;
use App\Enums\Blueprint;
use App\Enums\DeploymentStrategy;
use App\Enums\LaravelFeature;
use Random\RandomException;
use Symfony\Component\Yaml\Yaml;

trait GeneratesProjectInfrastructure
{
    use InteractsWithHosts, InteractsWithProjectConfig, LaraKubeOutput;
    use ManagesLocalCa;

    /** Caddy is the origin behind Traefik; pinned like every other vendored image. */
    protected const CADDY_VERSION = '2.11.2';

    /**
     * Dev-server config for a STANDALONE Vite/Astro SPA, where the framework's
     * own dev server is the app rather than an asset pipeline beside Laravel.
     * Deliberately separate from hardenViteConfig(): that one rewrites
     * `inertia()` and targets the `vite.{app}` host, neither of which applies.
     */
    /**
     * Point a static framework's dev server at the host Traefik will proxy.
     *
     * Vite and Astro need it for different files: hardenStaticViteConfig() only
     * ever looked for vite.config.*, so an Astro project — whose config is
     * astro.config.mjs — was skipped silently and its dev server rejected every
     * proxied request. Docusaurus is webpack, not Vite, and takes its host and
     * polling from command flags instead (see devServerFlags()).
     */
    public function hardenStaticDevConfig(ConfigData $config): void
    {
        match ($config->framework) {
            AppFramework::ASTRO => $this->hardenAstroConfig($config),
            default => $this->hardenStaticViteConfig($config),
        };
    }

    /**
     * Inject the same server block into astro.config.mjs, nested under `vite`,
     * which is where Astro forwards Vite options.
     */
    public function hardenAstroConfig(ConfigData $config): void
    {
        $projectPath = $config->getPath();

        $configFile = collect(['astro.config.mjs', 'astro.config.ts', 'astro.config.js'])
            ->map(fn (string $name) => "{$projectPath}/{$name}")
            ->first(fn (string $path) => file_exists($path));

        if ($configFile === null) {
            return;
        }

        $content = (string) file_get_contents($configFile);
        $appHost = $config->getWebHost('local');
        $harden = view('k8s.static.astro-server', ['appHost' => $appHost])->render();

        // Recognise our own block structurally, so a re-run after `config:tld`
        // re-aligns the host instead of leaving HMR pointed at a dead name.
        if (str_contains($content, 'allowedHosts: [') && str_contains($content, 'usePolling: true')) {
            $content = preg_replace("/allowedHosts:\s*\['[^']*'\]/", "allowedHosts: ['{$appHost}']", $content, 1);
            $content = preg_replace("/(hmr:\s*\{(?:[^}]*?)host:\s*)'[^']*'/", "$1'{$appHost}'", $content, 1);
            file_put_contents($configFile, (string) $content);

            return;
        }

        // create-astro emits `defineConfig({});` — an empty object, so there is
        // no key to append after and a naive comma insert produces invalid JS.
        if (preg_match('/defineConfig\s*\(\s*\{\s*\}\s*\)/', $content)) {
            $content = preg_replace('/defineConfig\s*\(\s*\{\s*\}\s*\)/', "defineConfig({\n{$harden}})", $content, 1);
            file_put_contents($configFile, (string) $content);

            return;
        }

        if (preg_match('/(defineConfig\s*\(\s*\{)/', $content)) {
            $content = preg_replace('/(defineConfig\s*\(\s*\{)/', "$1\n{$harden}", $content, 1);
            file_put_contents($configFile, (string) $content);

            return;
        }

        // Hand-written config — advise, never rewrite.
        $this->laraKubeNewLine();
        $this->laraKubeWarn(" ⚠ ASTRO ADVISORY: Your {$configFile} looks custom.");
        $this->laraKubeLine('   For HMR through Traefik, your config needs:');
        $this->laraKubeNewLine();
        $this->laraKubeLine($harden);
        $this->laraKubeNewLine();
    }

    public function hardenStaticViteConfig(ConfigData $config): void
    {
        $projectPath = $config->getPath();
        $viteFile = file_exists("$projectPath/vite.config.ts")
            ? "$projectPath/vite.config.ts"
            : "$projectPath/vite.config.js";

        if (! file_exists($viteFile)) {
            return;
        }

        $content = (string) file_get_contents($viteFile);
        $appHost = $config->getWebHost('local');
        $harden = view('k8s.static.vite-server', [
            'appHost' => $appHost,
            'devPort' => $config->framework?->devServerPort() ?? 5173,
        ])->render();

        // Recognise our own managed block structurally rather than by hostname,
        // so a re-run after `config:tld` or a rename re-aligns the host instead
        // of leaving HMR pointed at a name that no longer resolves.
        $isManagedTemplate = str_contains($content, 'allowedHosts: [')
            && str_contains($content, 'strictPort: true')
            && str_contains($content, 'usePolling: true');

        if (! str_contains($content, 'server: {')) {
            // Blade strips the leading whitespace of a template's first line,
            // so re-indent it — this lands in a file the user reads and edits.
            $content = preg_replace('/(defineConfig\s*\(\s*\{)/', "$1\n    {$harden}", $content, 1);
            file_put_contents($viteFile, (string) $content);

            return;
        }

        if ($isManagedTemplate) {
            $content = preg_replace("/allowedHosts:\s*\['[^']*'\]/", "allowedHosts: ['{$appHost}']", $content, 1);
            $content = preg_replace("/(hmr:\s*\{\s*(?:\/\/[^\n]*\n\s*)*host:\s*)'[^']*'/", "$1'{$appHost}'", $content, 1);
            file_put_contents($viteFile, (string) $content);

            return;
        }

        // Custom config — advise, never rewrite.
        $this->laraKubeNewLine();
        $this->laraKubeWarn(" ⚠ VITE ADVISORY: Your {$viteFile} looks custom.");
        $this->laraKubeLine('   For HMR through Traefik, your `server` block needs:');
        $this->laraKubeNewLine();
        $this->laraKubeLine($harden);
        $this->laraKubeNewLine();
    }

    public function hardenViteConfig(ConfigData $config): void
    {
        $projectPath = $config->getPath();
        $viteFile = file_exists("$projectPath/vite.config.ts") ? "$projectPath/vite.config.ts" : "$projectPath/vite.config.js";

        if (! file_exists($viteFile)) {
            return;
        }

        $content = file_get_contents($viteFile);
        $viteHost = $config->getServiceHost('vite', 'local');

        // 1. Aggressive Cleanups (ONLY for new scaffolding)
        if ($config->isScaffolding) {
            $this->laraKubeInfo('Hardening Vite configuration for Kubernetes...');

            // Wayfinder is intentionally left in place. The asset build now runs
            // in the PHP image, so the Vite plugin can shell out to
            // `php artisan wayfinder:generate` itself — stripping it was only
            // necessary when the build ran in a pure-Node image with no PHP.

            // Disable Inertia SSR
            $content = preg_replace('/inertia\(\)/', 'inertia({ ssr: false })', $content);
        }

        // 2. Network Alignment. Recognise our own managed `server: {}` block by its
        // structural markers (not by hostname, which changes with the TLD/app name)
        // so re-running heal after `config:tld` or a rename re-aligns the host
        // instead of leaving it stale and merely advising.
        $isManagedTemplate = str_contains($content, 'cors: true')
            && str_contains($content, 'strictPort: true')
            && str_contains($content, "ignored: ['**/.infrastructure/volume_data/**']");

        if (! str_contains($content, 'server: {')) {
            $harden = view('k8s.viteserver', ['viteHost' => $viteHost])->render();
            $content = preg_replace('/(defineConfig\s*\(\s*\{)/', "$1\n{$harden}", $content);
            file_put_contents($viteFile, $content);
        } elseif ($isManagedTemplate) {
            $content = preg_replace("/origin:\s*['\"][^'\"]+['\"]/", "origin: 'https://{$viteHost}'", $content, 1);
            $content = preg_replace("/(hmr:\s*\{\s*host:\s*)['\"][^'\"]+['\"]/", "$1'{$viteHost}'", $content, 1);
            file_put_contents($viteFile, $content);
        } else {
            $harden = view('k8s.viteserver', ['viteHost' => $viteHost])->render();
            $this->laraKubeNewLine();
            $this->laraKubeWarn(" ⚠ VITE ADVISORY: Your {$viteFile} looks custom.");
            $this->laraKubeLine("   To ensure HMR works in Kubernetes, please ensure your 'server' block includes:");
            $this->laraKubeNewLine();
            $this->laraKubeLine($harden);
            $this->laraKubeNewLine();

            // The config is custom — stay hands-off (advise only, don't rewrite).
        }
    }

    /**
     * Pure: rewrite ASSET_URL to $target only when it's empty or a local
     * "*.dev.test" or "*.kube" value. Leaves a real asset host (CDN) or an absent
     * ASSET_URL untouched. Kept side-effect-free so the policy is unit-testable.
     */
    public function alignAssetUrlValue(string $content, string $target): string
    {
        if (! preg_match('/^#?\s*ASSET_URL=(.*)$/m', $content, $m)) {
            return $content; // not present — leave as-is
        }

        $current = trim($m[1]);
        $localTlds = array_map(fn ($t) => '.'.$t, GlobalConfigData::ALLOWED_TLDS);
        $isLocalValue = $current !== '' && (str_contains($current, '.dev.test') || collect($localTlds)->contains(fn ($t) => str_contains($current, $t)));
        if ($current !== '' && ! $isLocalValue) {
            return $content; // deliberate non-local value — don't clobber
        }

        return preg_replace('/^#?\s*ASSET_URL=.*$/m', 'ASSET_URL='.$target, $content, 1);
    }

    /** True when a tracked manifest's current content no longer matches its recorded hash. */
    public function manifestHandEdited(ConfigData $config, string $absPath, string $relPath): bool
    {
        $sigs = $this->loadManifestSigs($config);

        return is_file($absPath)
            && isset($sigs[$relPath])
            && hash('sha256', (string) file_get_contents($absPath)) !== $sigs[$relPath];
    }

    /**
     * Load the manifest-signature sidecar (relPath → sha256). Empty when absent.
     *
     * @return array<string, string>
     */
    public function loadManifestSigs(ConfigData $config): array
    {
        $path = $config->getK8sPath().'/.larakube-sigs.json';
        $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : [];

        return is_array($data) ? $data : [];
    }

    /**
     * Persist the manifest-signature sidecar (sorted, for a stable file).
     *
     * @param  array<string, string>  $sigs
     */
    public function saveManifestSigs(ConfigData $config, array $sigs): void
    {
        ksort($sigs);
        file_put_contents(
            $config->getK8sPath().'/.larakube-sigs.json',
            json_encode($sigs, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
        );
    }

    /**
     * Align ASSET_URL in a cloud environment's env file (.env.<environment> —
     * production, staging, …) with that environment's web domain. Laravel's
     *
     * @vite prefixes asset URLs with ASSET_URL, so a leaked local "*.dev.test"
     * or local TLD value sends the deployed assets to the dev host (404 / unstyled).
     * Rewrites ONLY an empty or local value — never a deliberate CDN/asset host —
     * and is a no-op when ASSET_URL is absent (assets then resolve relative to
     * APP_URL, which is fine). Skips local. Narrow on purpose, like the APP_URL sync.
     */
    protected function alignEnvironmentAssetUrl(string $projectPath, string $environment, ?string $webHost): void
    {
        if (empty($webHost) || $environment === 'local') {
            return;
        }

        $envPath = $projectPath.'/.env.'.$environment;
        if (! file_exists($envPath)) {
            return;
        }

        $content = (string) file_get_contents($envPath);
        $aligned = $this->alignAssetUrlValue($content, 'https://'.$webHost);

        if ($aligned !== $content) {
            file_put_contents($envPath, $aligned);
        }
    }

    /**
     * Sync values into an environment's env file: `.env` for local, otherwise
     * `.env.<environment>` (seeded from the base .env if it doesn't exist yet).
     * Works for ANY environment — production, staging, preview, … — not just
     * production, so multi-environment projects are first-class.
     */
    protected function syncEnvFile(string $projectPath, array $values, bool $commented = false, string $environment = 'local'): void
    {
        $envFile = $environment === 'local' ? '.env' : '.env.'.$environment;
        $envPath = $projectPath.'/'.$envFile;

        // --- 🛡️ SECURITY: Locked File Protection ---
        // If the user has manually locked this file in .larakube.json, we stay hands-off.
        $config = method_exists($this, 'getProjectConfig') ? $this->getProjectConfig($projectPath) : null;
        if ($config?->isLocked($envFile)) {
            return;
        }

        if (! file_exists($envPath)) {
            // Seed a cloud env file from the base .env so it starts with a baseline.
            if ($environment !== 'local' && file_exists($projectPath.'/.env')) {
                copy($projectPath.'/.env', $envPath);
            } else {
                return;
            }
        }

        $lines = explode("\n", file_get_contents($envPath));
        $newLines = [];
        $processedKeys = [];

        foreach ($lines as $line) {
            $matched = false;
            foreach ($values as $key => $value) {
                if (preg_match("/^#?\s*{$key}=.*/", $line)) {
                    $prefix = $commented ? '# ' : '';
                    $newLines[] = "{$prefix}{$key}={$value}";
                    $processedKeys[] = $key;
                    $matched = true;
                    break;
                }
            }

            if (! $matched) {
                $newLines[] = $line;
            }
        }

        // Add keys that didn't exist in the original file
        foreach ($values as $key => $value) {
            if (! in_array($key, $processedKeys)) {
                $prefix = $commented ? '# ' : '';
                $newLines[] = "{$prefix}{$key}={$value}";
            }
        }

        $content = implode("\n", $newLines);
        file_put_contents($envPath, $content);

        // When updating the base .env, seed every configured cloud environment's
        // own env file from it (if missing) so later per-env syncs have a
        // baseline. Generalised from the old production-only auto-create.
        if ($environment === 'local' && $config) {
            foreach ($config->getCloudEnvironments() as $cloudEnv) {
                $cloudEnvPath = $projectPath.'/.env.'.$cloudEnv;
                if (! file_exists($cloudEnvPath)) {
                    file_put_contents($cloudEnvPath, $content);
                }
            }
        }
    }

    /**
     * Dockerfile.static + its Caddyfile.
     *
     * The build lives in the image rather than on the host for the same reason
     * Laravel's does (docker/php.blade.php's `assets` stage): the host has no
     * node_modules and is not meant to — local dev installs into a PVC mounted
     * over /app/node_modules, because Vite ships Rolldown, a native binary that
     * must match the container's platform. Building on the host failed with
     * `sh: vite: command not found` even with npm installed.
     */
    protected function generateStaticDockerfiles(ConfigData $config): void
    {
        $projectPath = $config->getPath();
        $framework = $config->framework;

        if (! $config->isLocked('Dockerfile.static')) {
            file_put_contents("{$projectPath}/Dockerfile.static", view('docker.static', [
                'buildCommand' => $framework?->staticBuildCommand($config->getPackageManager()) ?? 'npm run build',
                'outputDir' => $framework?->staticOutputDir() ?? 'dist',
                'caddyVersion' => self::CADDY_VERSION,
                'environment' => 'production',
            ])->render());
        }

        if (! $config->isLocked('Caddyfile')) {
            file_put_contents("{$projectPath}/Caddyfile", view('docker.caddyfile')->render());
        }
    }

    protected function generateDockerfiles(ConfigData $config): void
    {
        $projectPath = $config->getPath();

        // A static site gets its own image: the bundle is built in Node and
        // served by Caddy. docker.php would fatal here anyway — it dereferences
        // getServerVariation()->value and getPhpVersion()->value, both null.
        if ($config->framework?->isStaticSpa()) {
            $this->generateStaticDockerfiles($config);
        }

        if (! $config->framework?->isStaticSpa()) {
            if (! $config->isLocked('Dockerfile.php')) {
                $phpDockerfile = view('docker.php', ['config' => $config])->render();
                file_put_contents("$projectPath/Dockerfile.php", $phpDockerfile);
            }

            $this->generateDockerIgnore($config);
        }

        // Always, framework or not: .infrastructure/ holds the local TLS
        // PRIVATE KEY, so skipping this would let it be committed.
        $this->hardenGitIgnore($config);
    }

    protected function hardenGitIgnore(ConfigData $config): void
    {
        $projectPath = $config->getPath();
        $gitIgnorePath = "$projectPath/.gitignore";

        if (! file_exists($gitIgnorePath)) {
            return;
        }

        $content = file_get_contents($gitIgnorePath);
        $rules = [
            '# LaraKube Local Infrastructure (Dynamic paths)',
            '.infrastructure/k8s/overlays/local',
            '.infrastructure/volume_data',
            '# Operator-specific cloud connection (server IP/SSH/key path) — never commit',
            '.larakube.local.json',
            '# LaraKube manifest fingerprints (reproducible — detects hand-edits, not for sharing)',
            '.infrastructure/k8s/.larakube-sigs.json',
            '# Local TLS leaf cert + PRIVATE KEY, reissued per machine — never commit',
            '.infrastructure/traefik/certificates',
            // `larakube env` adds `.env.*`, which ignores .env.production but
            // NOT the base .env — so a file this CLI creates (data:wire) was
            // committable while its per-env siblings were not. Vite's own
            // convention is to commit .env because VITE_ vars are public, but
            // data:wire also writes an UNPREFIXED var that never reaches the
            // bundle, and every other LaraKube project ignores .env. One rule.
            '# Environment files — data:wire writes here; keep them local',
            '.env',
        ];

        // Line-exact, not str_contains(): a substring test sees an existing
        // `.env.*` and concludes `.env` is already covered, silently skipping
        // the narrower rule. Same trap for any rule that prefixes another.
        $existing = array_map('trim', explode("\n", $content));

        $toAdd = [];
        foreach ($rules as $rule) {
            if (! in_array(trim($rule), $existing, true)) {
                $toAdd[] = $rule;
            }
        }

        if (! empty($toAdd)) {
            $this->laraKubeInfo('Hardening .gitignore to exclude local infrastructure paths...');
            $newContent = trim($content)."\n\n".implode("\n", $toAdd)."\n";
            file_put_contents($gitIgnorePath, $newContent);
        }
    }

    protected function generateDockerIgnore(ConfigData $config): void
    {
        $projectPath = $config->getPath();
        $ignoreFile = view('docker.ignore', ['config' => $config])->render();
        file_put_contents("$projectPath/.dockerignore", $ignoreFile);
    }

    /**
     * Self-contained overlays for a static site. Deliberately no shared `base/`:
     * local runs the framework's own dev server for HMR while cloud runs Caddy
     * over an S3-published bundle, so the two environments have no workload in
     * common and a shared base would only exist to be patched away.
     */
    protected function generateStaticSiteManifests(ConfigData $config): void
    {
        $k8sPath = $config->getK8sPath();

        $render = function (string $stub, string $view, array $data) use ($config, $k8sPath): void {
            @mkdir(dirname("$k8sPath/$stub"), 0755, true);
            $this->writeManagedManifest(
                $config,
                "$k8sPath/$stub",
                ".infrastructure/k8s/{$stub}",
                view($view, $data)->render(),
            );
        };

        // --- local: the dev server itself. No S3, no Caddy, no build step. ---
        $localData = [
            'config' => $config,
            'namespace' => $config->getNamespace('local'),
            'host' => $config->getWebHost('local'),
            'devPort' => $config->framework->devServerPort() ?? 5173,
        ];
        $render('overlays/local/kustomization.yaml', 'k8s.static.local-kustomization', $localData);
        $render('overlays/local/dev-server.yaml', 'k8s.static.dev-server', $localData);

        // --- cloud: Caddy fronting the published bundle. ---
        foreach ($config->getCloudEnvironments() as $env) {
            $cloudData = [
                'config' => $config,
                'namespace' => $config->getNamespace($env),
                'environment' => $env,
                'hosts' => $config->getWebHosts($env),
                // Off unless the env opts in via ingressAnnotations. On breaks
                // ACME HTTP-01, leaving the host on Traefik's dev cert and
                // every request a Cloudflare 526.
                'proxied' => false,
            ];
            $render("overlays/$env/kustomization.yaml", 'k8s.static.cloud-kustomization', $cloudData);
            $render("overlays/$env/namespace.yaml", 'k8s.overlays.production.namespace', $cloudData);
            $render("overlays/$env/caddy.yaml", 'k8s.static.caddy', $cloudData);
        }
    }

    protected function generateK8sManifests(ConfigData $config): void
    {
        $config->resolveDependencies();

        $appName = $config->getName();
        $k8sPath = $config->getK8sPath();
        @mkdir($config->getInfrastructurePath(), 0755, true);
        @mkdir("$k8sPath/base", 0755, true);
        @mkdir("$k8sPath/overlays/local", 0755, true);
        @mkdir("$k8sPath/overlays/production", 0755, true);

        // 0. Copy certificates for local development (e.g. for Vite HTTPS)
        $projectCertsPath = $config->getPath().'/.infrastructure/traefik/certificates';
        @mkdir($projectCertsPath, 0755, true);
        $this->ensureAppCertExists($config->getName(), $config->getLocalTld(), $config->getEnvironment('local')?->additionalWebHosts ?? []);
        @copy($this->getAppCertPath($config->getName()), "$projectCertsPath/local-dev.pem");
        @copy($this->getAppKeyPath($config->getName()), "$projectCertsPath/local-dev-key.pem");
        @copy($this->getLocalCaCertPath(), "$projectCertsPath/local-ca.pem");

        $this->laraKubeInfo('Generating Kubernetes manifests...');

        // Static sites share none of the Laravel stack's stubs — no PHP
        // Deployment, no config/secret split, no image to roll. They get their
        // own self-contained overlays instead (no shared base), so `larakube up`
        // and applyScopedDeploy() can `kustomize build overlays/{env}` directly.
        if ($config->framework?->isStaticSpa()) {
            $this->generateStaticSiteManifests($config);

            return;
        }

        // 1. Generate consolidated core stubs (Stacked Architecture).
        // Cloud overlays (production, staging, qa, …) all share the same
        // environment-parameterized templates that live under
        // resources/views/k8s/overlays/production — they're rendered once per
        // cloud environment with that env's namespace + environment context.
        $cloudEnvs = $config->getCloudEnvironments();

        $baseStubs = [
            'base/kustomization.yaml',
            'base/laravel.yaml',
            'base/config.yaml',
        ];

        $localStubs = [
            'overlays/local/kustomization.yaml',
            'overlays/local/infrastructure.yaml',
            'overlays/local/patches.yaml',
            'overlays/local/config-patch.yaml',
        ];
        if ($config->getFrontend()?->requiresNodePod()) {
            $localStubs[] = 'overlays/local/node-deployment.yaml';
        }

        $cloudStubFiles = [
            'kustomization.yaml',
            'namespace.yaml',
            'deployment-patch.yaml',
            'ingress-patch.yaml',
            'config-patch.yaml',
        ];

        $binaryPath = realpath($_SERVER['argv'][0]) ?: '/usr/local/bin/larakube';
        $workspacePath = dirname($config->getPath());

        $renderStub = function (string $stub, string $environment, string $namespace, string $viewName) use ($config, $k8sPath, $binaryPath, $workspacePath): void {
            $command = $config->getServerVariation()?->getStartCommand($environment === 'local') ?? '[]';
            $content = view($viewName, [
                'config' => $config,
                'namespace' => $namespace,
                'environment' => $environment,
                'command' => $command,
                'binaryPath' => $binaryPath,
                'workspacePath' => $workspacePath,
            ])->render();

            $this->writeManagedManifest($config, "$k8sPath/$stub", ".infrastructure/k8s/{$stub}", $content);
        };

        // Base layer (environment-agnostic; rendered with the local command
        // context and the bare app namespace, matching prior behaviour).
        foreach ($baseStubs as $stub) {
            $renderStub($stub, 'local', $appName, 'k8s.'.str_replace(['/', '.yaml'], ['.', ''], $stub));
        }

        // Local overlay.
        foreach ($localStubs as $stub) {
            $renderStub($stub, 'local', $config->getNamespace('local'), 'k8s.'.str_replace(['/', '.yaml'], ['.', ''], $stub));
        }

        // Cloud overlays — one directory per non-local environment. Namespace
        // is resolved per env (getNamespace), so a managed-cluster env can
        // land in an existing namespace instead of the derived {name}-{env}.
        foreach ($cloudEnvs as $env) {
            @mkdir("$k8sPath/overlays/$env", 0755, true);
            foreach ($cloudStubFiles as $file) {
                $stub = "overlays/$env/$file";
                $viewName = 'k8s.overlays.production.'.str_replace('.yaml', '', $file);
                $renderStub($stub, $env, $config->getNamespace($env), $viewName);
            }
        }

        // Per-env ServiceAccount (e.g. IRSA on EKS) — only when the env opts
        // in. User app pods have no SA by default, so this is purely additive.
        foreach ($cloudEnvs as $env) {
            if ($config->getServiceAccount($env)) {
                $renderStub("overlays/$env/serviceaccount.yaml", $env, $config->getNamespace($env), 'k8s.overlays.production.serviceaccount');
                $this->appendToKustomization($k8sPath, "overlays/$env", 'serviceaccount.yaml');
            }
        }

        // Public websocket route. Echo connects the BROWSER straight to Reverb
        // (VITE_REVERB_HOST), so the ClusterIP alone leaves the configured
        // reverb host resolving to nothing. Local gets its ingress from
        // LaravelFeature's `local` bucket; cloud is per-env because the host is
        // per-env — and is skipped when the env has no reverb host configured,
        // since an Ingress with an empty host would swallow all traffic.
        foreach ($cloudEnvs as $env) {
            if (! $config->hasFeature(LaravelFeature::REVERB, $env)) {
                continue;
            }

            if (! $config->getHost($env, 'reverb')) {
                continue;
            }

            $renderStub("overlays/$env/reverb-ingress.yaml", $env, $config->getNamespace($env), 'k8s.overlays.production.reverb-ingress');
            $this->appendToKustomization($k8sPath, "overlays/$env", 'reverb-ingress.yaml');
        }

        // App storage PVCs live in each environment's overlay (not base), so
        // their accessMode can follow that env's deployment strategy
        // (ReadWriteOnce for single-node, ReadWriteMany for multi-node-HA).
        foreach (array_merge(['local'], $cloudEnvs) as $env) {
            // Multi-node WITHOUT sharedStorage uses a per-pod emptyDir (no shared
            // PVC), so skip app-volumes there. Single-node, or multi-node+shared
            // (RWX on NFS), still gets the PVC.
            if ($config->getStrategy($env) === DeploymentStrategy::MULTI_NODE_HA && ! $config->usesSharedStorage($env)) {
                continue;
            }
            @mkdir("$k8sPath/overlays/$env", 0755, true);
            $renderStub("overlays/$env/app-volumes.yaml", $env, $config->getNamespace($env), 'k8s.base.volumes');
        }

        // 2. APPLY ACTIONS (write component-owned manifest files to disk)
        foreach ($config->getComponents() as $pod) {
            if ($pod instanceof HasKubernetesFiles) {
                $pod->updateK8s($config);
            }
        }

        // 3. SYNCHRONIZED REGISTRATION (Explicit Deduplication)
        // Base is environment-agnostic — collected from the full component set.
        // Local honours `managed` exactly like the cloud overlays below (skip +
        // emit a delete-patch) — a Plex-joined service must actually stop
        // deploying locally on `up`, not just skip volumes while the base
        // Deployment keeps coming back regardless of managed status.
        $managedLocal = $config->getManaged('local');
        $base = $local = $localPatches = [];
        foreach ($config->getComponents() as $pod) {
            if (! $pod instanceof HasKubernetesFiles) {
                continue;
            }

            $files = $pod->getManifestFiles($config);
            $base = array_merge($base, $files['base'] ?? []);

            if (in_array($pod->value, $managedLocal, true)) {
                if ($pod instanceof RemovableWhenManaged) {
                    $this->writeManagedDeletePatch($k8sPath, 'local', $pod, $config);
                }

                continue;
            }

            $local = array_merge($local, $files['local'] ?? []);
            $localPatches = array_merge($localPatches, $files['patches'] ?? []);
        }

        foreach (array_unique($base) as $file) {
            $this->appendToKustomization($k8sPath, 'base', $file);
        }
        foreach (array_unique($local) as $file) {
            $this->appendToKustomization($k8sPath, 'overlays/local', $file);
        }
        foreach (array_unique($localPatches) as $file) {
            $this->appendToKustomization($k8sPath, 'overlays/local', $file, 'patches');
        }

        // Cloud overlays — registered per environment, honouring per-env
        // feature filtering (getComponents($env)) and skipping services that
        // are externally managed in that env.
        foreach ($cloudEnvs as $env) {
            $managed = $config->getManaged($env);
            $cloudFiles = [];

            foreach ($config->getComponents($env) as $pod) {
                if (! $pod instanceof HasKubernetesFiles) {
                    continue;
                }

                // Externally managed in this env: don't register its cloud
                // volumes, and emit a delete-patch so its base Deployment/
                // Service is removed from this overlay too (local gets the
                // same treatment above, keyed off managed('local') instead).
                if (in_array($pod->value, $managed, true)) {
                    if ($pod instanceof RemovableWhenManaged) {
                        $this->writeManagedDeletePatch($k8sPath, $env, $pod, $config);
                    }

                    continue;
                }

                $files = $pod->getManifestFiles($config);
                $cloudFiles = array_merge($cloudFiles, $files['cloud'] ?? []);
            }

            foreach (array_unique($cloudFiles) as $file) {
                $this->appendToKustomization($k8sPath, "overlays/$env", $file);
            }
            $this->appendToKustomization($k8sPath, "overlays/$env", 'ingress-patch.yaml', 'patches');
        }

        // Register the per-env app storage PVCs (single-node, or multi-node+shared;
        // multi-node default has no shared PVC).
        foreach (array_merge(['local'], $cloudEnvs) as $env) {
            if ($config->getStrategy($env) === DeploymentStrategy::MULTI_NODE_HA && ! $config->usesSharedStorage($env)) {
                continue;
            }
            $this->appendToKustomization($k8sPath, "overlays/$env", 'app-volumes.yaml');
        }

        // Multi-node: app pods can't share a ReadWriteOnce volume across nodes, so
        // swap the shared storage PVC for a per-pod emptyDir (state externalizes —
        // see GuardsSharedStorage). Targeted by pod NAME so service pods
        // (postgres/redis/minio) keep their own data PVCs untouched.
        foreach ($cloudEnvs as $env) {
            // Only the default multi-node path (no sharedStorage) swaps to emptyDir;
            // sharedStorage envs keep the shared RWX PVC instead.
            if ($config->getStrategy($env) !== DeploymentStrategy::MULTI_NODE_HA || $config->usesSharedStorage($env)) {
                continue;
            }

            $features = $config->getFeatures($env);
            $deploymentPods = ['web'];
            foreach ([[LaravelFeature::HORIZON, 'horizon'], [LaravelFeature::QUEUES, 'queues'], [LaravelFeature::REVERB, 'reverb']] as [$feature, $pod]) {
                if (in_array($feature, $features, true)) {
                    $deploymentPods[] = $pod;
                }
            }

            $renderStub("overlays/$env/storage-emptydir.yaml", $env, $config->getNamespace($env), 'k8s.overlays.production.storage-emptydir');
            $this->appendTargetedPatch($k8sPath, "overlays/$env", 'storage-emptydir.yaml', 'Deployment', '^('.implode('|', $deploymentPods).')$');

            if (in_array(LaravelFeature::TASK_SCHEDULING, $features, true)) {
                $renderStub("overlays/$env/storage-emptydir-cronjob.yaml", $env, $config->getNamespace($env), 'k8s.overlays.production.storage-emptydir-cronjob');
                $this->appendTargetedPatch($k8sPath, "overlays/$env", 'storage-emptydir-cronjob.yaml', 'CronJob', '^scheduler$');
            }
        }
    }

    /**
     * Write (and register) a kustomize delete-patch that removes an externally
     * managed service's base resources from an environment's overlay — cloud
     * or local, whichever $env names.
     */
    protected function writeManagedDeletePatch(string $k8sPath, string $env, RemovableWhenManaged $pod, ConfigData $config): void
    {
        $resources = $pod->getManagedResources($config);
        if (empty($resources)) {
            return;
        }

        $apiVersionFor = fn (string $kind): string => match ($kind) {
            'Service' => 'v1',
            default => 'apps/v1',
        };

        // One SINGLE-document delete file per resource. kustomize/kyaml panics on a
        // multi-document `$patch: delete` file referenced from the modern `patches:`
        // field, so we never bundle multiple docs into one patch (and we avoid the
        // deprecated `patchesStrategicMerge`).
        foreach ($resources as $resource) {
            $doc = implode("\n", [
                'apiVersion: '.$apiVersionFor($resource['kind']),
                'kind: '.$resource['kind'],
                'metadata:',
                '  name: '.$resource['name'],
                '$patch: delete',
            ])."\n";

            $filename = "{$pod->value}-managed-delete-".strtolower($resource['kind']).'.yaml';
            $dest = "overlays/$env/$filename";

            $this->writeManagedManifest($config, "$k8sPath/$dest", ".infrastructure/k8s/{$dest}", $doc);
            $this->appendToKustomization($k8sPath, "overlays/$env", $filename, 'patches');
        }
    }

    /**
     * Remove generated manifest files that the current blueprint no longer
     * produces — stale volumes from a now-managed service, leftovers from a
     * removed feature, and whole overlay directories for environments that
     * have been dropped from the blueprint.
     *
     * Safe by construction: heal re-renders each kustomization.yaml from its
     * template before appending, so its resources/patches lists are the
     * authoritative "keep" set. Anything in a managed dir that isn't
     * referenced there (and isn't locked) is stale. Locked files are always
     * preserved; a stale env dir that still holds locked files is left in
     * place rather than removed.
     *
     * @return array<int, string> relative paths that were pruned
     */
    protected function pruneStaleManifests(ConfigData $config): array
    {
        $k8sPath = $config->getK8sPath();
        $overlaysPath = "$k8sPath/overlays";
        $knownEnvs = $config->getEnvironments();
        $pruned = [];

        // 1. Overlay directories for environments no longer in the blueprint.
        if (is_dir($overlaysPath)) {
            foreach (array_diff(scandir($overlaysPath), ['.', '..']) as $entry) {
                $dir = "$overlaysPath/$entry";
                if (is_dir($dir) && ! in_array($entry, $knownEnvs, true)) {
                    $pruned = array_merge($pruned, $this->pruneManifestDir($config, $dir, [], true));
                }
            }
        }

        // 2. Unreferenced files within base + each known overlay.
        $dirs = ["$k8sPath/base"];
        foreach ($knownEnvs as $env) {
            $dirs[] = "$overlaysPath/$env";
        }
        foreach ($dirs as $dir) {
            if (is_dir($dir)) {
                $pruned = array_merge(
                    $pruned,
                    $this->pruneManifestDir($config, $dir, $this->referencedManifestFiles($dir)),
                );
            }
        }

        return $pruned;
    }

    /**
     * Local manifest filenames referenced by a directory's kustomization.yaml
     * (resources + patch paths). Cross-directory references like ../../base
     * are ignored.
     *
     * @return array<int, string>
     */
    protected function referencedManifestFiles(string $dir): array
    {
        $kustomizationFile = "$dir/kustomization.yaml";
        if (! file_exists($kustomizationFile)) {
            return [];
        }

        $parsed = Yaml::parse(file_get_contents($kustomizationFile)) ?: [];
        $referenced = [];

        foreach (($parsed['resources'] ?? []) as $resource) {
            if (is_string($resource) && ! str_contains($resource, '/')) {
                $referenced[] = $resource;
            }
        }
        foreach (($parsed['patches'] ?? []) as $patch) {
            $path = is_array($patch) ? ($patch['path'] ?? null) : $patch;
            if (is_string($path) && ! str_contains($path, '/')) {
                $referenced[] = $path;
            }
        }

        return $referenced;
    }

    /**
     * Write a generated manifest without ever silently clobbering a hand-edit.
     * LaraKube records a hash of each file it writes in a gitignored sidecar
     * (.larakube-sigs.json); if a file's current content no longer matches that
     * hash, the user changed it by hand — so we keep their version and advise
     * locking it instead of overwriting. Locked files are always left alone, and
     * kustomization.yaml is machine-managed (rewritten by appendToKustomization),
     * so it's written plain and not tracked.
     */
    protected function writeManagedManifest(ConfigData $config, string $absPath, string $relPath, string $content): void
    {
        if ($config->isLocked($relPath)) {
            return;
        }

        if (str_ends_with($relPath, 'kustomization.yaml')) {
            file_put_contents($absPath, $content);

            return;
        }

        if ($this->manifestHandEdited($config, $absPath, $relPath)) {
            $this->laraKubeWarn("⚠ {$relPath} looks hand-edited — keeping your version, not regenerating it.");
            $this->laraKubeLine("   Run `larakube lock {$relPath}` to keep it for good, or revert/delete it to let LaraKube manage it again.");

            return;
        }

        file_put_contents($absPath, $content);

        $sigs = $this->loadManifestSigs($config);
        $sigs[$relPath] = hash('sha256', $content);
        $this->saveManifestSigs($config, $sigs);
    }

    /**
     * Delete *.yaml files in $dir that aren't in $referenced and aren't locked.
     * When $removeAll is true the kustomization itself is eligible and an
     * emptied directory is removed (used for dropped environments).
     *
     * @param  array<int, string>  $referenced
     * @return array<int, string> relative paths pruned
     */
    protected function pruneManifestDir(ConfigData $config, string $dir, array $referenced, bool $removeAll = false): array
    {
        $k8sPath = $config->getK8sPath();
        $pruned = [];

        foreach (glob("$dir/*.yaml") ?: [] as $file) {
            $name = basename($file);
            if ($name === 'kustomization.yaml' && ! $removeAll) {
                continue;
            }
            if (in_array($name, $referenced, true)) {
                continue;
            }

            $stub = str_replace($k8sPath.'/', '', $file);
            if ($config->isLocked(".infrastructure/k8s/{$stub}")) {
                continue;
            }

            @unlink($file);
            $pruned[] = $stub;
        }

        // Drop a now-empty dropped-environment directory.
        if ($removeAll && empty(glob("$dir/*"))) {
            @rmdir($dir);
        }

        return $pruned;
    }

    protected function appendToKustomization(string $k8sPath, string $folder, string $filename, string $type = 'resources'): void
    {
        $kustomizationFile = "$k8sPath/$folder/kustomization.yaml";
        if (! file_exists($kustomizationFile)) {
            return;
        }

        $content = file_get_contents($kustomizationFile);

        if ($type === 'resources') {
            if (! str_contains($content, "  - $filename") && ! str_contains($content, "- $filename")) {
                $oldContent = $content;
                $content = preg_replace('/resources:\s*\n/', "resources:\n  - $filename\n", $content, 1);
                if ($oldContent === $content) {
                    // This means it didn't match.
                    $this->laraKubeError("Failed to append $filename to $kustomizationFile. Regex mismatch.");
                }
            }
        } elseif ($type === 'patches') {
            if (! str_contains($content, "path: $filename")) {
                if (! str_contains($content, 'patches:')) {
                    $content .= "\npatches:\n";
                }
                $content = preg_replace('/patches:\s*\n/', "patches:\n  - path: $filename\n", $content, 1);
            }
        }

        file_put_contents($kustomizationFile, $content);
    }

    /**
     * Append a JSON6902 patch targeted by kind + name regex to an overlay's
     * kustomization. Used for the multi-node storage emptyDir swap, which must hit
     * only the named app pods — never the service pods' data volumes.
     */
    protected function appendTargetedPatch(string $k8sPath, string $folder, string $filename, string $kind, string $nameRegex): void
    {
        $kustomizationFile = "$k8sPath/$folder/kustomization.yaml";
        if (! file_exists($kustomizationFile)) {
            return;
        }

        $content = file_get_contents($kustomizationFile);
        if ($content === false || str_contains($content, "path: $filename")) {
            return;
        }

        if (! str_contains($content, 'patches:')) {
            $content .= "\npatches:\n";
        }

        $entry = "  - path: $filename\n    target:\n      kind: $kind\n      name: \"$nameRegex\"\n";
        $content = (string) preg_replace('/patches:\s*\n/', "patches:\n".$entry, $content, 1);

        file_put_contents($kustomizationFile, $content);
    }

    protected function removeResourceFromKustomization(string $k8sPath, string $folder, string $filename): void
    {
        $kustomizationFile = "$k8sPath/$folder/kustomization.yaml";
        if (! file_exists($kustomizationFile)) {
            return;
        }

        $content = file_get_contents($kustomizationFile);

        // Remove from resources list
        $newContent = preg_replace("/^\s*-\s*".preg_quote($filename, '/')."\s*$/m", '', $content);

        if ($newContent !== $content) {
            file_put_contents($kustomizationFile, $newContent);
        }
    }

    protected function setLaravelStoragePermissions(string $projectPath): void
    {
        $this->laraKubeInfo('Fixing storage permissions...');
        $this->runInContainer('chown -R www-data:www-data storage bootstrap/cache && chmod -R 775 storage bootstrap/cache', $projectPath);
    }

    protected function ensureHttpsCompatibility(ConfigData $config): void
    {
        $projectPath = $config->getPath();
        $providerPath = $projectPath.'/app/Providers/AppServiceProvider.php';

        if (! file_exists($providerPath)) {
            return;
        }

        $content = file_get_contents($providerPath);

        // Check if already applied
        if (str_contains($content, 'URL::forceScheme')) {
            return;
        }

        $this->laraKubeInfo('Surgically applying HTTPS compatibility to AppServiceProvider...');

        $injection = "        if (str_starts_with(config('app.url'), 'https://')) {\n            \Illuminate\Support\Facades\URL::forceScheme('https');\n        }\n\n";

        // Find the boot() method
        $pattern = "/public function boot\(\): void\n    \{/";
        $replacement = "public function boot(): void\n    {\n".$injection;

        if (preg_match($pattern, $content)) {
            $newContent = preg_replace($pattern, $replacement, $content);
            file_put_contents($providerPath, $newContent);
        }
    }

    /**
     * Orchestrate the entire project infrastructure generation and installation.
     *
     * @throws RandomException
     */
    protected function orchestrateProjectScaffolding(ConfigData $config, bool $installFeatures = true, bool $buildImage = true, bool $dryRun = false, bool $syncK8s = true, bool $syncEnv = true): void
    {
        if ($dryRun) {
            $this->laraKubeInfo("Architectural Preview for '{$config->getName()}':");
        }

        // --- 🛡 SECURITY GUARD: SYSTEM PROJECTS ---
        if ($config->isSystem()) {
            $globalConfigPath = $_SERVER['HOME'].'/.larakube/config.json';
            $globalConfig = file_exists($globalConfigPath) ? json_decode(file_get_contents($globalConfigPath), true) : [];
            $trusted = $globalConfig['trusted_system_uuids'] ?? [];

            if (! in_array($config->id, $trusted)) {
                $shouldTrust = false;

                if ($this->hasOption('force') && $this->option('force')) {
                    $shouldTrust = true;
                } else {
                    $this->newLine();
                    $this->warn(' ⚠ SECURITY WARNING: This project is requesting "System" status.');
                    $this->warn('   It will have READ/WRITE access to your global LaraKube project registry and logs.');
                    $this->newLine();

                    if (\Laravel\Prompts\confirm("Do you trust this project ({$config->getName()}) and want to grant system access?", false)) {
                        $shouldTrust = true;
                    }
                }

                if ($shouldTrust) {
                    $trusted[] = $config->id;
                    $globalConfig['trusted_system_uuids'] = array_unique($trusted);
                    @mkdir($_SERVER['HOME'].'/.larakube', 0755, true);
                    file_put_contents($globalConfigPath, json_encode($globalConfig, JSON_PRETTY_PRINT));
                    $this->laraKubeInfo('Project UUID added to global trusted list.');
                } else {
                    $this->laraKubeError('Access Denied. Disabling system status for this run.');
                    $config->setIsSystem(false);
                }
            }
        }

        // 0. Ensure architectural dependencies are resolved
        $config->resolveDependencies();

        // 0. Persist configuration for self-healing
        if ($dryRun) {
            $this->line('  <fg=gray>[FILE]</> Would create .larakube.json with project blueprint.');
            $this->line("  <fg=gray>[DB]</> Would register project '{$config->getName()}' in internal database.");
        } else {
            $this->saveProjectConfig($config->getPath(), $config);
        }

        // 1. Handle Blueprint extensions (Laravel is the implicit base)
        if (! $config->hasBlueprints()) {
            // Optional: You can keep it empty or add LARAVEL as a marker
            // $config->addBlueprint(Blueprint::LARAVEL);
        }

        // 2. Sync .env (Source of Truth)
        if ($syncEnv) {
            $envChanges = $config->getAllEnvironmentVariables();

            if ($dryRun) {
                $this->line('  <fg=gray>[.ENV]</> Would sync the following variables to local .env:');
                foreach ($envChanges as $k => $v) {
                    $this->line("         $k=$v");
                }

                foreach ($config->getCloudEnvironments() as $cloudEnv) {
                    $cloudEnvChanges = $config->getAllEnvironmentVariables($cloudEnv);
                    $this->line('  <fg=gray>[.ENV.'.strtoupper($cloudEnv)."]</> Would sync environment-specific variables to .env.{$cloudEnv}:");
                    foreach ($cloudEnvChanges as $k => $v) {
                        if ($v !== ($envChanges[$k] ?? null)) {
                            $this->line("         $k=$v");
                        }
                    }
                }
            } else {
                $this->syncEnvFile($config->getPath(), $envChanges);

                // Sync every configured cloud environment's own .env.<environment>
                // with that environment's computed values (per-env APP_URL /
                // ASSET_URL / hosts). Generalised from the old production-only sync
                // so staging/preview/etc. are first-class, not just production.
                foreach ($config->getCloudEnvironments() as $cloudEnv) {
                    $this->syncEnvFile(
                        $config->getPath(),
                        $config->getAllEnvironmentVariables($cloudEnv),
                        environment: $cloudEnv,
                    );
                }
            }
        }

        if (! $dryRun) {
            // ensureHttpsCompatibility patches app/Providers/AppServiceProvider.php
            // and hardenViteConfig rewrites `inertia()` — both Laravel-only. A
            // standalone SPA gets its own dev-server config instead.
            if ($config->framework?->isStaticSpa()) {
                $this->hardenStaticDevConfig($config);
            } else {
                $this->ensureHttpsCompatibility($config);
                $this->hardenViteConfig($config);
            }
        }

        // 4. Generate Dockerfiles
        if ($syncK8s) {
            if ($dryRun) {
                $this->line("  <fg=gray>[FILE]</> Would generate Dockerfile.php ({$config->getServerVariation()?->value}).");
            } else {
                $this->generateDockerfiles($config);
            }
        }

        // 4. Build the local image if requested
        if ($buildImage) {
            if ($dryRun) {
                $this->line("  <fg=gray>[DOCKER]</> Would build image '{$config->getName()}:latest'.");
            } else {
                $this->buildImage($config);
            }
        }

        // 5. Generate Manifests
        if ($syncK8s) {
            if ($dryRun) {
                $this->line('  <fg=gray>[K8S]</> Would generate base and overlay manifests in .infrastructure/k8s/');
            } else {
                $this->generateK8sManifests($config);
            }
        }

        // 6. Install features
        if ($installFeatures) {
            if ($dryRun) {
                $featuresList = array_map(fn ($f) => $f->value, $config->getFeatures());
                $this->line('  <fg=gray>[PHP]</> Would install features: '.implode(', ', $featuresList));
            } else {
                $this->installComponents($config);
            }
        }

        if ($dryRun) {
            $this->line('');
            $this->line('  <fg=yellow;options=bold>⚠ No changes have been applied yet.</>');
        }
    }
}
