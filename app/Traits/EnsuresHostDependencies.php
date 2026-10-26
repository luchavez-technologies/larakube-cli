<?php

namespace App\Traits;

use App\Contracts\HasArtisanCommands;
use App\Contracts\HasComposerDependencies;
use App\Data\ConfigData;
use App\Enums\AppFramework;
use App\Enums\LaravelFeature;

trait EnsuresHostDependencies
{
    use InteractsWithDocker;

    /**
     * Populate the host's `vendor/`, `node_modules/`, and (for SSR projects)
     * `bootstrap/ssr/ssr.js` if they're missing. Pod start commands assume
     * these exist on the host (via the hostPath mount), so this runs before
     * manifests are applied.
     *
     * No-op for non-local environments — production images bake dependencies
     * in at build time and don't host-mount the project directory.
     */
    protected function ensureHostDependencies(ConfigData $config, string $environment): void
    {
        if ($environment !== 'local') {
            return;
        }

        $projectPath = $config->getPath();
        $framework = $config->framework ?? AppFramework::LARAVEL;

        if ($framework->isPhp() && ! is_dir("$projectPath/vendor")) {
            $this->laraKubeInfo('vendor/ not found — running composer install (first-time setup)...');
            $this->runInContainer('composer install --no-interaction --prefer-dist', $projectPath);
        }

        if ($framework->isPhp() && file_exists("$projectPath/composer.json")) {
            $missingPackages = [];
            $composerJson = json_decode((string) file_get_contents("$projectPath/composer.json"), true);
            $required = array_merge(
                array_keys($composerJson['require'] ?? []),
                array_keys($composerJson['require-dev'] ?? []),
            );

            foreach ($config->getFeatures() as $feature) {
                if ($feature instanceof HasComposerDependencies) {
                    foreach ($feature->getComposerDependencies($config) as $pkg) {
                        if (! in_array($pkg, $required, true)) {
                            $missingPackages[] = $pkg;
                        }
                    }
                }
            }

            if (! empty($missingPackages)) {
                $unique = array_unique($missingPackages);
                $this->laraKubeInfo('Installing missing composer package(s) for active features: '.implode(', ', $unique).'...');
                $phpCommands = [
                    'composer require '.implode(' ', $unique).' --with-all-dependencies --ignore-platform-reqs --no-scripts',
                    'php artisan package:discover --ansi',
                ];

                foreach ($config->getFeatures() as $feature) {
                    if ($feature instanceof HasArtisanCommands) {
                        foreach ($feature->getArtisanCommands($config) as $cmd) {
                            $phpCommands[] = "php artisan {$cmd}";
                        }
                    }
                }

                $safeEnv = '-e REDIS_CLIENT=null -e CACHE_STORE=array -e SESSION_DRIVER=array -e DB_CONNECTION=sqlite';
                $this->runInContainer(implode(' && ', $phpCommands), $projectPath, envs: $safeEnv);
            }
        }

        $needsNode = $config->getFrontend()?->requiresNodePod()
            || $config->hasFeature(LaravelFeature::SSR);

        if (! $needsNode) {
            return;
        }

        $pm = $config->getPackageManager();

        if ($pm && ! is_dir("$projectPath/node_modules")) {
            $this->laraKubeInfo("node_modules/ not found — running {$pm->installCommand()}...");
            $this->runInContainer($pm->installCommand(), $projectPath, 'node');
        }

        if ($pm && $config->hasFeature(LaravelFeature::SSR) && ! file_exists("$projectPath/bootstrap/ssr/ssr.js")) {
            $this->laraKubeInfo("bootstrap/ssr/ssr.js not found — running {$pm->buildSsrCommand()}...");
            $this->runInContainer($pm->buildSsrCommand(), $projectPath, 'node');
        }
    }
}
