<?php

namespace App\Commands\Cloud;

use App\Enums\CloudProvider;
use App\Traits\InteractsWithClusterContext;
use App\Traits\LaraKubeOutput;

use function Laravel\Prompts\select;

use LaravelZero\Framework\Commands\Command;

class CloudProvisionManagedCommand extends Command
{
    use InteractsWithClusterContext, LaraKubeOutput;

    protected $signature = 'cloud:init:managed
        {environment? : Inside a project, the environment to bind to this cluster.}
        {--provider= : Managed provider (gcp, do, aws)}
        {--context= : Target a specific kube-context}
        {--email= : Email for Let\'s Encrypt certificate notices}';

    protected $description = 'Provision Traefik and Let\'s Encrypt TLS on a managed Kubernetes cluster (GKE, DOKS, EKS, etc.)';

    public function handle(): int
    {
        $this->renderHeader();

        $context = $this->option('context') ?: $this->askForClusterContext();
        if (! $context) {
            $this->laraKubeError('No Kubernetes context selected.');

            return 1;
        }

        $provider = $this->option('provider');
        if (! $provider) {
            // Auto-detect provider from context name if possible
            if (str_starts_with($context, 'gke_')) {
                $provider = 'gcp';
            } elseif (str_starts_with($context, 'do-')) {
                $provider = 'do';
            } elseif (str_starts_with($context, 'arn:aws:eks:')) {
                $provider = 'aws';
            } else {
                $provider = select(
                    label: 'Which managed Kubernetes provider is this cluster on?',
                    options: CloudProvider::activeProviders(),
                    default: 'do',
                );
            }
        }

        $command = match ($provider) {
            'gcp' => 'cloud:init:gke',
            'aws' => 'cloud:init:eks',
            default => 'cloud:init:doks',
        };

        $args = ['--context' => $context];
        if ($env = $this->argument('environment')) {
            $args['environment'] = $env;
        }
        if ($email = $this->option('email')) {
            $args['--email'] = $email;
        }

        return $this->call($command, $args);
    }
}
