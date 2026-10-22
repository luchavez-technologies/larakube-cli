<?php

namespace App\Traits;

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
