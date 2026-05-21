<?php

namespace App\Traits;

use App\Contracts\HasArtisanCommands;
use App\Contracts\HasComposerDependencies;
use App\Contracts\HasJsDependencies;
use App\Contracts\HasLifecycleHooks;
use App\Data\ConfigData;

/**
 * Trait InteractsWithArchitecturalEngine
 *
 * This trait contains the "Heavy Logic" for resolving dependencies,
 * installing components, and hardening configurations.
 */
trait InteractsWithArchitecturalEngine
{
    /**
     * Surgically install a single component and its dependencies.
     */
    public function installComponent(ConfigData $config, object $component): void
    {
        $projectPath = $config->getPath();
        $composerPackages = [];
        $artisanCommands = [];
        $jsCommands = [];

        if ($component instanceof HasComposerDependencies) {
            $composerPackages = $component->getComposerDependencies($config);
        }

        if ($component instanceof HasArtisanCommands) {
            foreach ($component->getArtisanCommands($config) as $cmd) {
                $artisanCommands[] = "php artisan $cmd";
            }
        }

        if ($component instanceof HasJsDependencies) {
            $jsCommands = $component->getJsDependencies($config);
        }

        if ($component instanceof HasLifecycleHooks) {
            $component->onPostInstall($projectPath, $config);
        }

        // Execute PHP installation if needed
        if (! empty($composerPackages) || (! empty($artisanCommands) && $config->isScaffolding)) {
            $phpCommands = [];
            $noScripts = $config->isScaffolding ? '' : ' --no-scripts';

            if (! empty($composerPackages)) {
                $phpCommands[] = 'composer require '.implode(' ', array_unique($composerPackages)).' --with-all-dependencies --ignore-platform-reqs'.$noScripts;
            }

            if ($config->isScaffolding) {
                foreach ($artisanCommands as $cmd) {
                    $phpCommands[] = $cmd;
                }
            }

            // Inject a safe environment for Artisan commands to prevent connection errors on boot
            $safeEnv = '-e REDIS_CLIENT=null -e CACHE_STORE=array -e SESSION_DRIVER=array -e DB_CONNECTION=sqlite';
            $this->runInContainer(implode(' && ', $phpCommands), $projectPath, envs: $safeEnv);
        }

        // Execute JS installation if needed
        if (! empty($jsCommands)) {
            $js = [...$jsCommands, $config->packageManager->buildCommand()];
            $this->runInContainer(implode(' && ', $js), $projectPath, $config->frontend->getPodName($config));
        }
    }

    public function installComponents(ConfigData $config): void
    {
        $projectPath = $config->getPath();
        $pods = $config->getComponents();

        $composerPackages = [];
        $artisanCommands = [];
        $jsCommands = [];

        foreach ($pods as $pod) {
            if ($pod instanceof HasComposerDependencies) {
                $composerPackages = array_merge($composerPackages, $pod->getComposerDependencies($config));
            }

            if ($pod instanceof HasArtisanCommands) {
                foreach ($pod->getArtisanCommands($config) as $cmd) {
                    $artisanCommands[] = "php artisan $cmd";
                }
            }

            if ($pod instanceof HasJsDependencies) {
                $jsCommands = array_merge($jsCommands, $pod->getJsDependencies($config));
            }

            if ($pod instanceof HasLifecycleHooks) {
                $pod->onPostInstall($projectPath, $config);
            }
        }

        // PHP
        if (! empty($composerPackages) || (! empty($artisanCommands) && $config->isScaffolding)) {
            $this->laraKubeInfo('Installing PHP requirements...');

            $phpCommands = [];
            $noScripts = $config->isScaffolding ? '' : ' --no-scripts';

            if (! empty($composerPackages)) {
                $uniquePackages = array_unique($composerPackages);
                $phpCommands[] = 'composer require '.implode(' ', $uniquePackages).' --with-all-dependencies --ignore-platform-reqs'.$noScripts;
            }

            if ($config->isScaffolding) {
                foreach ($artisanCommands as $cmd) {
                    $phpCommands[] = $cmd;
                }
            }

            // Inject a safe environment for Artisan commands to prevent connection errors on boot
            $safeEnv = '-e REDIS_CLIENT=null -e CACHE_STORE=array -e SESSION_DRIVER=array -e DB_CONNECTION=sqlite';
            $this->runInContainer(implode(' && ', $phpCommands), $projectPath, envs: $safeEnv);
        }

        // JS
        if (! empty($jsCommands)) {
            $this->laraKubeInfo('Installing JS packages and building assets...');

            $js = [...$jsCommands, $config->packageManager->buildCommand()];

            $this->runInContainer(implode(' && ', $js), $projectPath, $config->frontend->getPodName($config));
        }
    }
}
