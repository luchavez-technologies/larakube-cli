<?php

namespace App\Commands\Cloud;

use App\Data\ConfigData;
use App\Data\GlobalConfigData;
use App\Enums\ManagedProvider;
use App\Services\Kubectl;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithEnvironments;
use App\Traits\InteractsWithGlobalConfig;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use App\Traits\PromotesIngressDns;
use App\Traits\ResolvesEnvironmentContext;
use App\Traits\VerifiesKubernetesRollout;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;
use Spatie\TemporaryDirectory\TemporaryDirectory;

class CloudProvisionEksCommand extends Command
{
    use InteractsWithClusterContext, InteractsWithEnvironments, InteractsWithGlobalConfig, InteractsWithProjectConfig, LaraKubeOutput, PromotesIngressDns, ResolvesEnvironmentContext, VerifiesKubernetesRollout;

    protected $signature = 'cloud:init:eks
        {environment? : Inside a project, the environment to bind to this cluster.}
        {--context= : Target a specific kube-context}
        {--email= : Email for Let\'s Encrypt certificate notices}';

    /**
     * Backward-compatible alias for the provision shorthand.
     *
     * @var array<int, string>
     */
    protected $aliases = ['cloud:provision:eks'];

    protected $description = 'Provision an AWS Elastic Kubernetes Service (EKS) cluster with Traefik and Let\'s Encrypt TLS';

    public function handle(): int
    {
        $this->renderHeader();
        $this->laraKubeInfo('Provision AWS Elastic Kubernetes Service (EKS)');
        $this->newLine();

        $context = $this->option('context') ?: $this->askForClusterContext();
        if (! $context) {
            $this->laraKubeError('No Kubernetes context selected.');

            return 1;
        }

        $this->line("  <fg=gray>Target context:</> <fg=cyan>{$context}</>");
        $this->newLine();

        $projectConfig = $this->getProjectConfig(getcwd());
        $environment = $this->argument('environment');

        // Idempotent rerun: if Traefik is already installed, skip install
        if ($this->traefikInstalled($context)) {
            $this->laraKubeInfo('ℹ️  Traefik is already installed on this cluster — skipping install.');

            return $this->reportEndpointAndOffer($context, $this->waitForLoadBalancerEndpoint($context), $projectConfig, $environment);
        }

        $projectEmail = $this->validStoredEmail($projectConfig?->email);
        $globalEmail = $this->validStoredEmail($this->getEmail());

        $email = $this->option('email');
        if ($email !== null && ($emailError = $this->acmeEmailError($email))) {
            $this->laraKubeError("Invalid --email '{$email}' — {$emailError}");

            return 1;
        }
        if ($email === null && $this->option('no-interaction')) {
            $email = $projectEmail ?: $globalEmail;
            if (! $email) {
                $this->laraKubeError('No valid email available for Let\'s Encrypt — pass --email= when running non-interactively.');

                return 1;
            }
        }
        $email ??= text(
            label: 'Email for Let\'s Encrypt certificate notices',
            placeholder: 'you@yourdomain.com',
            default: $projectEmail ?: ($globalEmail ?? ''),
            required: true,
            validate: fn (string $v) => $this->acmeEmailError($v),
        );

        if ($projectConfig && ! $projectEmail) {
            $projectConfig->setEmail($email);
            $this->saveProjectConfig(getcwd(), $projectConfig);
            $this->laraKubeInfo('Saved email to .larakube.json.');
        }
        if (! $globalEmail) {
            $this->setEmail($email);
            $this->laraKubeInfo('Saved email to your global LaraKube config.');
        }

        if (! confirm('Install Traefik + Let\'s Encrypt (HTTP-01) on this EKS cluster?', true)) {
            $this->laraKubeInfo('Cancelled.');

            return 0;
        }

        $this->newLine();
        $this->laraKubeInfo('Installing Traefik with a Let\'s Encrypt (ACME) resolver...');
        if ($this->installTraefik($context, $email) !== 0) {
            $this->laraKubeError('Traefik installation failed.');

            return 1;
        }

        $this->laraKubeInfo('Waiting for the AWS LoadBalancer endpoint...');

        return $this->reportEndpointAndOffer($context, $this->waitForLoadBalancerEndpoint($context), $projectConfig, $environment);
    }

    private function reportEndpointAndOffer(string $context, ?string $endpoint, ?ConfigData $projectConfig, ?string $environment = null): int
    {
        if (! $endpoint) {
            $this->laraKubeWarn('No LoadBalancer endpoint assigned yet — AWS may still be provisioning the ELB/NLB. Re-run this command in a minute (or check `kubectl get svc -n traefik traefik`).');

            return 0;
        }

        $this->laraKubeInfo("✅ LoadBalancer endpoint: <fg=cyan>{$endpoint}</>");
        $this->newLine();

        if ($projectConfig) {
            if ($environment) {
                $this->configureProjectEnvForCluster($projectConfig, $context, $endpoint, $environment);
            } elseif (! $this->option('no-interaction') && confirm('Configure an environment in this project to use this cluster now?', true)) {
                $this->configureProjectEnvForCluster($projectConfig, $context, $endpoint);
            } else {
                $this->displayNextSteps($endpoint);
            }
        } else {
            $this->displayNextSteps($endpoint);
        }

        if (! $this->option('no-interaction') && confirm('Would you like to automate DNS records with Cloudflare for this cluster?')) {
            $this->call('dns:init', ['environment' => $environment ?: 'production', '--context' => $context]);
        }

        return 0;
    }

    private function configureProjectEnvForCluster(ConfigData $config, string $context, string $endpoint, ?string $environment = null): void
    {
        $projectPath = getcwd();
        $environment ??= $this->askForCloudEnvironment(label: 'Which environment runs on this EKS cluster?');

        // Managed target + default gp3 storageClass for EKS
        $config = $this->recordManagedTarget($config, $environment, $projectPath, $context, ManagedProvider::EKS);

        $currentHost = $config->getHost($environment, 'web');
        $localTldPatterns = array_map(fn ($t) => '.'.$t, GlobalConfigData::ALLOWED_TLDS);
        $isLocalHost = str_contains((string) $currentHost, '.dev.test')
            || collect($localTldPatterns)->contains(fn ($p) => str_contains((string) $currentHost, $p));
        $isPlaceholder = ! $currentHost
            || $currentHost === "{$config->getName()}.com"
            || $isLocalHost;

        $host = text(
            label: "Web domain for '{$environment}'",
            placeholder: 'app.example.com',
            default: $isPlaceholder ? '' : (string) $currentHost,
            required: true,
            validate: fn (string $v) => str_contains($v, '.') ? null : 'Enter a domain like app.example.com.',
        );
        $config->setHost($environment, 'web', $host);
        $this->saveProjectConfig($projectPath, $config);

        $this->newLine();
        $this->laraKubeInfo("✅ '{$environment}' will deploy to this EKS cluster.");
        $this->printIngressDnsGuidance($config->getWebHosts($environment), $endpoint);
        $this->newLine();
        $this->line('  <fg=green>Then:</>');
        $this->line("    <fg=yellow>larakube cloud:configure {$environment} --only=registry</> <fg=gray># container registry (e.g. GHCR)</>");
        $this->line("    <fg=yellow>larakube cloud:deploy {$environment}</>           <fg=gray># once DNS resolves</>");
        $this->newLine();
    }

    private function loadBalancerNameFor(string $context): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9-]+/', '-', strtolower($context)), '-');

        return 'larakube-'.($slug !== '' ? $slug : 'traefik');
    }

    private function traefikInstalled(string $context): bool
    {
        return Process::run(Kubectl::forContext(null)->prefix().' --context '.escapeshellarg($context).' get deployment -n traefik traefik')->successful();
    }

    private function installTraefik(string $context, string $email): int
    {
        if ($this->traefikInstalled($context)) {
            $this->laraKubeInfo('ℹ️  Traefik is already installed — skipping. (Re-install to change ACME settings.)');

            return 0;
        }

        $manifest = view('k8s.traefik-managed', [
            'email' => $email,
            'loadBalancerName' => $this->loadBalancerNameFor($context),
            'storageClass' => ManagedProvider::EKS->defaultStorageClass(),
        ])->render();
        $temporaryDirectory = TemporaryDirectory::make();
        $tmp = $temporaryDirectory->path('larakube-traefik-managed.yaml');
        file_put_contents($tmp, $manifest);

        $kubectl = Kubectl::forContext(null)->prefix().' --context '.escapeshellarg($context);
        $ok = $this->applyAndVerifyRollout($kubectl, $tmp, 'traefik', 'traefik', extraApplyFlags: '--validate=false');

        $temporaryDirectory->delete();

        return $ok ? 0 : 1;
    }

    private function waitForLoadBalancerEndpoint(string $context): ?string
    {
        $maxAttempts = 60; // 120s at 2s/attempt
        $attempt = 0;

        while ($attempt < $maxAttempts) {
            $hostname = trim(Process::run(
                Kubectl::forContext($context)->prefix()
                .' get svc -n traefik traefik -o jsonpath=\'{.status.loadBalancer.ingress[0].hostname}\'',
            )->output());
            if ($hostname !== '') {
                return $hostname;
            }

            $ip = trim(Process::run(
                Kubectl::forContext($context)->prefix()
                .' get svc -n traefik traefik -o jsonpath=\'{.status.loadBalancer.ingress[0].ip}\'',
            )->output());
            if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }

            $attempt++;
            if ($attempt % 5 === 0) {
                $this->line("  ⏳ Waiting for AWS LoadBalancer endpoint... ({$attempt}s)");
            }
            Sleep::sleep(2);
        }

        return null;
    }

    private function displayNextSteps(string $endpoint): void
    {
        $recordType = filter_var($endpoint, FILTER_VALIDATE_IP) ? 'A' : 'CNAME';

        $this->line('  <fg=green>Next steps:</>');
        $this->newLine();
        $this->line("  1️⃣  <fg=yellow>Point your domain at the LoadBalancer endpoint</> ({$recordType} record):");
        $this->line("       <fg=cyan>app.example.com  {$recordType}  {$endpoint}</>");
        $this->newLine();
        $this->line('  2️⃣  <fg=yellow>From your project</>, record this cluster + a registry (no hand-editing):');
        $this->line('       <fg=yellow>larakube cloud:configure <env></>                  <fg=gray># pick this EKS context as the target</>');
        $this->line('       <fg=yellow>larakube cloud:configure <env> --only=registry</>  <fg=gray># container registry (e.g. GHCR)</>');
        $this->newLine();
        $this->line('  3️⃣  <fg=yellow>Deploy</> once DNS resolves:');
        $this->line('       <fg=yellow>larakube cloud:deploy <env></>');
        $this->newLine();
    }
}
