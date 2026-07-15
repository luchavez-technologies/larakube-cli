<?php

namespace App\Commands\Cloud;

use App\Data\ConfigData;
use App\Data\GlobalConfigData;
use App\Enums\ManagedProvider;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithEnvironments;
use App\Traits\InteractsWithGlobalConfig;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use App\Traits\PromotesIngressDns;
use App\Traits\ResolvesEnvironmentContext;
use App\Traits\VerifiesKubernetesRollout;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;

class CloudProvisionDoksCommand extends Command
{
    use InteractsWithClusterContext, InteractsWithEnvironments, InteractsWithGlobalConfig, InteractsWithProjectConfig, LaraKubeOutput, PromotesIngressDns, ResolvesEnvironmentContext, VerifiesKubernetesRollout;

    protected $signature = 'cloud:init:doks
        {--context= : Target a specific kube-context}
        {--email= : Email for Let\'s Encrypt certificate notices}';

    /**
     * Backward-compatible alias for the pre-rename command name.
     *
     * @var array<int, string>
     */
    protected $aliases = ['cloud:provision:doks'];

    protected $description = 'Provision a DigitalOcean Kubernetes (DOKS) cluster with Traefik and Let\'s Encrypt TLS';

    public function handle(): int
    {
        $this->renderHeader();
        $this->laraKubeInfo('Provision DigitalOcean Kubernetes (DOKS)');
        $this->newLine();

        $context = $this->option('context') ?: $this->askForClusterContext();
        if (! $context) {
            $this->laraKubeError('No Kubernetes context selected.');

            return 1;
        }

        $this->line("  <fg=gray>Target context:</> <fg=cyan>{$context}</>");
        $this->newLine();

        // Load the project config once (null when run outside a project) — drives
        // both the email prefill and the optional auto-configure offer below.
        $projectConfig = $this->getProjectConfig(getcwd());

        // Idempotent rerun: if Traefik is already installed, don't re-prompt for an
        // email or reinstall — just re-surface the IP (and still offer to wire up
        // the project). Guards against the common "ran it twice" case.
        if ($this->traefikInstalled($context)) {
            $this->laraKubeInfo('ℹ️  Traefik is already installed on this cluster — skipping install.');

            return $this->reportIpAndOffer($context, $this->waitForLoadBalancerIp($context), $projectConfig);
        }

        // Collected up front — the ACME resolver is configured at install time.
        // Prefill from the project's .larakube.json email, else the global config
        // email, so the common case is just Enter.
        $projectEmail = $this->validStoredEmail($projectConfig?->email);
        $globalEmail = $this->validStoredEmail($this->getEmail());

        $email = $this->option('email');
        if ($email !== null && ($emailError = $this->acmeEmailError($email))) {
            $this->laraKubeError("Invalid --email '{$email}' — {$emailError}");

            return 1;
        }
        if ($email === null && $this->option('no-interaction')) {
            // Headless without the flag: fall back to a stored email, never prompt.
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

        // Remember the email wherever it wasn't set yet, so future runs prefill it
        // (when both were empty, this backfills both project + global).
        if ($projectConfig && ! $projectEmail) {
            $projectConfig->setEmail($email);
            $this->saveProjectConfig(getcwd(), $projectConfig);
            $this->laraKubeInfo('Saved email to .larakube.json.');
        }
        if (! $globalEmail) {
            $this->setEmail($email);
            $this->laraKubeInfo('Saved email to your global LaraKube config.');
        }

        if (! confirm('Install Traefik + Let\'s Encrypt (HTTP-01) on this cluster?', true)) {
            $this->laraKubeInfo('Cancelled.');

            return 0;
        }

        $this->newLine();
        $this->laraKubeInfo('Installing Traefik with a Let\'s Encrypt (ACME) resolver...');
        if ($this->installTraefik($context, $email) !== 0) {
            $this->laraKubeError('Traefik installation failed.');

            return 1;
        }

        $this->laraKubeInfo('Waiting for the LoadBalancer IP...');

        return $this->reportIpAndOffer($context, $this->waitForLoadBalancerIp($context), $projectConfig);
    }

    /**
     * Report the LoadBalancer IP and, when run inside a project, offer to wire an
     * environment to this cluster (managed target + web host) — no hand-editing.
     * Otherwise print the manual next steps.
     */
    private function reportIpAndOffer(string $context, ?string $ip, ?ConfigData $projectConfig): int
    {
        if (! $ip) {
            $this->laraKubeWarn('No LoadBalancer IP assigned yet — DigitalOcean may still be provisioning it. Re-run this command in a minute (or check the `traefik` service in the `traefik` namespace).');

            return 0;
        }

        $this->laraKubeInfo("✅ LoadBalancer IP: <fg=cyan>{$ip}</>");
        $this->newLine();

        // The project-wiring branch stays interactive-only: headless it would
        // auto-confirm (default true) straight into required prompts with no
        // flag equivalents and throw instead of finishing.
        if ($projectConfig && ! $this->option('no-interaction') && confirm('Configure an environment in this project to use this cluster now?', true)) {
            $this->configureProjectEnvForCluster($projectConfig, $context, $ip);
        } else {
            $this->displayNextSteps($ip);
        }

        return 0;
    }

    /**
     * Record THIS project's chosen env to deploy to the just-provisioned DOKS
     * cluster — managed target (context + DOKS provider) + default storageClass +
     * web host — reusing the shared recorder so there's one source of truth.
     */
    private function configureProjectEnvForCluster(ConfigData $config, string $context, string $ip): void
    {
        $projectPath = getcwd();
        $environment = $this->askForCloudEnvironment(label: 'Which environment runs on this DOKS cluster?');

        // Managed target + storageClass — no provider prompt, we know it's DOKS.
        $config = $this->recordManagedTarget($config, $environment, $projectPath, $context, ManagedProvider::DOKS);

        // Web domain — skip the {name}.com placeholder and any local .kube host.
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
        $this->laraKubeInfo("✅ '{$environment}' will deploy to this DOKS cluster.");
        $this->printIngressDnsGuidance($config->getWebHosts($environment), $ip);
        $this->newLine();
        $this->line('  <fg=green>Then:</>');
        $this->line("    <fg=yellow>larakube cloud:configure {$environment} --only=registry</> <fg=gray># container registry (e.g. GHCR)</>");
        $this->line("    <fg=yellow>larakube cloud:deploy {$environment}</>           <fg=gray># once DNS resolves</>");
        $this->newLine();
    }

    /**
     * A stable, per-cluster DO LoadBalancer name (from the context), so reinstalls
     * reuse the same LB + IP. DO names allow lowercase alphanumerics + hyphens.
     */
    private function loadBalancerNameFor(string $context): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9-]+/', '-', strtolower($context)), '-');

        return 'larakube-'.($slug !== '' ? $slug : 'traefik');
    }

    /** Is Traefik already installed on this cluster? Keeps provision reruns safe. */
    private function traefikInstalled(string $context): bool
    {
        return Process::run($this->kubectl().' --context '.escapeshellarg($context).' get deployment -n traefik traefik')->successful();
    }

    /**
     * Install Traefik with a persistent ACME (Let's Encrypt, HTTP-01) resolver by
     * rendering the managed-cluster manifest and `kubectl apply`-ing it — same
     * approach as the VPS path, no Helm dependency. The manifest exposes Traefik
     * via a cloud LoadBalancer and stores acme.json on a PVC (cluster default
     * StorageClass, e.g. do-block-storage on DOKS).
     */
    private function installTraefik(string $context, string $email): int
    {
        // Safety net — handle() already short-circuits on an existing install, but
        // guard here too in case this is ever called directly.
        if ($this->traefikInstalled($context)) {
            $this->laraKubeInfo('ℹ️  Traefik is already installed — skipping. (Re-install to change ACME settings.)');

            return 0;
        }

        $manifest = view('k8s.traefik-managed', [
            'email' => $email,
            'loadBalancerName' => $this->loadBalancerNameFor($context),
        ])->render();
        $tmp = sys_get_temp_dir().'/larakube-traefik-managed.yaml';
        file_put_contents($tmp, $manifest);

        $kubectl = $this->kubectl().' --context '.escapeshellarg($context);
        $ok = $this->applyAndVerifyRollout($kubectl, $tmp, 'traefik', 'traefik');
        @unlink($tmp);

        return $ok ? 0 : 1;
    }

    private function waitForLoadBalancerIp(string $context): ?string
    {
        $maxAttempts = 60; // 120s at 2s/attempt
        $attempt = 0;

        while ($attempt < $maxAttempts) {
            $ip = trim(Process::run(
                'kubectl --context '.escapeshellarg($context)
                .' get svc -n traefik traefik -o jsonpath=\'{.status.loadBalancer.ingress[0].ip}\'',
            )->output());
            if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }

            $attempt++;
            if ($attempt % 5 === 0) {
                $this->line("  ⏳ Waiting... ({$attempt}s)");
            }
            sleep(2);
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
        $this->line('       <fg=yellow>larakube cloud:configure <env></>                  <fg=gray># pick this DOKS context as the target</>');
        $this->line('       <fg=yellow>larakube cloud:configure <env> --only=registry</>  <fg=gray># container registry (e.g. GHCR)</>');
        $this->newLine();
        $this->line('  3️⃣  <fg=yellow>Deploy</> once DNS resolves:');
        $this->line('       <fg=yellow>larakube cloud:deploy <env></>');
        $this->newLine();
        $this->line('  <fg=gray>HTTPS later: add to the env\'s ingressAnnotations —</>');
        $this->line('  <fg=gray>  traefik.ingress.kubernetes.io/router.entrypoints: websecure</>');
        $this->line('  <fg=gray>  traefik.ingress.kubernetes.io/router.tls.certresolver: letsencrypt</>');
        $this->newLine();
    }
}
