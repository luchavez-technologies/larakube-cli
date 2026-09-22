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

class CloudProvisionGkeCommand extends Command
{
    use InteractsWithClusterContext, InteractsWithEnvironments, InteractsWithGlobalConfig, InteractsWithProjectConfig, LaraKubeOutput, PromotesIngressDns, ResolvesEnvironmentContext, VerifiesKubernetesRollout;

    protected $signature = 'cloud:init:gke
        {environment? : Inside a project, the environment to bind to this cluster.}
        {--context= : Target a specific kube-context}
        {--email= : Email for Let\'s Encrypt certificate notices}';

    /**
     * Backward-compatible alias for the provision shorthand.
     *
     * @var array<int, string>
     */
    protected $aliases = ['cloud:provision:gke'];

    protected $description = 'Provision a Google Kubernetes Engine (GKE) cluster with Traefik and Let\'s Encrypt TLS';

    public function handle(): int
    {
        $this->renderHeader();
        $this->laraKubeInfo('Provision Google Kubernetes Engine (GKE)');
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

            return $this->reportIpAndOffer($context, $this->waitForLoadBalancerIp($context), $projectConfig, $environment);
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

        if (! confirm('Install Traefik + Let\'s Encrypt (HTTP-01) on this GKE cluster?', true)) {
            $this->laraKubeInfo('Cancelled.');

            return 0;
        }

        $this->newLine();
        $this->laraKubeInfo('Installing Traefik with a Let\'s Encrypt (ACME) resolver...');
        if ($this->installTraefik($context, $email) !== 0) {
            $this->laraKubeError('Traefik installation failed.');

            return 1;
        }

        $this->laraKubeInfo('Waiting for the GCP LoadBalancer external IP...');

        return $this->reportIpAndOffer($context, $this->waitForLoadBalancerIp($context), $projectConfig, $environment);
    }

    private function reportIpAndOffer(string $context, ?string $ip, ?ConfigData $projectConfig, ?string $environment = null): int
    {
        if (! $ip) {
            $this->laraKubeWarn('No LoadBalancer IP assigned yet — GCP may still be provisioning the Network Load Balancer. Re-run this command in a minute (or check `kubectl get svc -n traefik traefik`).');

            return 0;
        }

        $this->laraKubeInfo("✅ LoadBalancer IP: <fg=cyan>{$ip}</>");
        $this->newLine();

        if ($projectConfig) {
            if ($environment) {
                $this->configureProjectEnvForCluster($projectConfig, $context, $ip, $environment);
            } elseif (! $this->option('no-interaction') && confirm('Configure an environment in this project to use this cluster now?', true)) {
                $this->configureProjectEnvForCluster($projectConfig, $context, $ip);
            } else {
                $this->displayNextSteps($ip);
            }
        } else {
            $this->displayNextSteps($ip);
        }

        if (! $this->option('no-interaction') && confirm('Would you like to automate DNS records with Cloudflare for this cluster?')) {
            $this->call('dns:init', ['environment' => $environment ?: 'production', '--context' => $context]);
        }

        return 0;
    }

    private function configureProjectEnvForCluster(ConfigData $config, string $context, string $ip, ?string $environment = null): void
    {
        $projectPath = getcwd();
        $environment ??= $this->askForCloudEnvironment(label: 'Which environment runs on this GKE cluster?');

        // Managed target + default standard-rwo storageClass for GKE
        $config = $this->recordManagedTarget($config, $environment, $projectPath, $context, ManagedProvider::GKE);

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
        $this->laraKubeInfo("✅ '{$environment}' will deploy to this GKE cluster.");
        $this->printIngressDnsGuidance($config->getWebHosts($environment), $ip);
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
            'storageClass' => ManagedProvider::GKE->defaultStorageClass(),
        ])->render();
        $temporaryDirectory = TemporaryDirectory::make();
        $tmp = $temporaryDirectory->path('larakube-traefik-managed.yaml');
        file_put_contents($tmp, $manifest);

        $kubectl = Kubectl::forContext(null)->prefix().' --context '.escapeshellarg($context);
        $ok = $this->applyAndVerifyRollout($kubectl, $tmp, 'traefik', 'traefik', extraApplyFlags: '--validate=false');

        $temporaryDirectory->delete();

        return $ok ? 0 : 1;
    }

    private function waitForLoadBalancerIp(string $context): ?string
    {
        $maxAttempts = 60; // 120s at 2s/attempt
        $attempt = 0;

        while ($attempt < $maxAttempts) {
            $ip = trim(Process::run(
                Kubectl::forContext($context)->prefix()
                .' get svc -n traefik traefik -o jsonpath=\'{.status.loadBalancer.ingress[0].ip}\'',
            )->output());
            if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }

            $attempt++;
            if ($attempt % 5 === 0) {
                $this->line("  ⏳ Waiting for GCP LoadBalancer IP... ({$attempt}s)");
            }
            Sleep::sleep(2);
        }

        return null;
    }

    private function displayNextSteps(string $ip): void
    {
        $this->line('  <fg=green>Next steps:</>');
        $this->newLine();
        $this->line('  1️⃣  <fg=yellow>Point your domain at the LoadBalancer IP</> (A record):');
        $this->line("       <fg=cyan>app.example.com  A  {$ip}</>");
        $this->newLine();
        $this->line('  2️⃣  <fg=yellow>From your project</>, record this cluster + a registry (no hand-editing):');
        $this->line('       <fg=yellow>larakube cloud:configure <env></>                  <fg=gray># pick this GKE context as the target</>');
        $this->line('       <fg=yellow>larakube cloud:configure <env> --only=registry</>  <fg=gray># container registry (e.g. GHCR)</>');
        $this->newLine();
        $this->line('  3️⃣  <fg=yellow>Deploy</> once DNS resolves:');
        $this->line('       <fg=yellow>larakube cloud:deploy <env></>');
        $this->newLine();
    }
}
