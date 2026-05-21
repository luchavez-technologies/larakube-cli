<?php

namespace App\Commands\Cloud;

use App\Traits\GeneratesProjectInfrastructure;
use App\Traits\InteractsWithEnvironments;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\confirm;

class CloudDeployCommand extends Command
{
    use GeneratesProjectInfrastructure, InteractsWithEnvironments, InteractsWithProjectConfig, LaraKubeOutput;

    protected $signature = 'cloud:deploy {environment? : The environment to deploy to}';

    protected $description = 'Build and deploy the application to a remote environment';

    public function handle(): int
    {
        $this->renderHeader();

        $environment = $this->argument('environment') ?? 'production';

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

        if ($config->hasGithubActions() && ! $this->option('no-interaction')) {
            $this->info('💡 CI/CD DETECTED: You have GitHub Actions enabled for this project.');
            if (confirm('Would you prefer to trigger a deployment via Git push instead?', true)) {
                $this->laraKubeInfo('Action cancelled. Run "git push" to deploy via GitHub.');

                return 0;
            }
        }

        $appName = $config->getName() ?? basename($projectPath);

        // --- 🌐 PRODUCTION DOMAIN GUARD ---
        if ($environment === 'production') {
            $currentHost = $config->getRealProductionHost();
            $defaultHost = "{$appName}.com";

            if (empty($currentHost) || $currentHost === $defaultHost) {
                $this->newLine();
                $this->warn(' 🌐 PRODUCTION DOMAIN REQUIRED');
                $this->line("   You are about to deploy to production, but your domain is set to: <fg=yellow>{$currentHost}</>");
                $this->newLine();

                $newHost = \Laravel\Prompts\text(
                    label: 'What is your REAL production domain/subdomain?',
                    placeholder: 'myapp.com',
                    required: true
                );

                $config->setProductionHost($newHost);
                $this->saveProjectConfig($projectPath, $config);

                $this->withSpin('Updating architectural manifests with your domain...', function () use ($config) {
                    // Force a re-generation of manifests with the new domain
                    $this->orchestrateProjectScaffolding($config, installFeatures: false, buildImage: false, syncEnv: false);

                    return true;
                });
            }
        }

        $this->laraKubeInfo("Starting deployment to '{$environment}'...");

        // 1. Verify kubectl context
        $context = shell_exec('kubectl config current-context');
        $this->laraKubeInfo('Current Kubernetes Context: '.trim($context));

        if (! confirm('Are you sure you want to deploy to this cluster?')) {
            $this->laraKubeInfo('Deployment cancelled.');

            return 0;
        }

        // 2. Offer to build and push
        if (confirm('Would you like to build and push the production image now?')) {
            $appName = basename(getcwd());
            // In a real scenario, we'd ask for the registry URL (e.g., ghcr.io/user/repo)
            $this->laraKubeInfo("Note: This assumes you have 'docker login' configured for your registry.");

            // For now, we delegate to the 'up' logic but ensure it targets production
            $this->call('up', ['environment' => $environment]);
        } else {
            // Just apply manifests
            $this->call('up', ['environment' => $environment]);
        }

        return 0;
    }
}
