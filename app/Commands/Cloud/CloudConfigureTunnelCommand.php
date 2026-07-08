<?php

namespace App\Commands\Cloud;

use App\Data\TunnelData;
use App\Enums\TunnelProvider;
use App\Traits\ConfiguresCloudEnvironment;
use App\Traits\InteractsWithEnvironments;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use App\Traits\ResolvesEnvironmentContext;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\password;
use function Laravel\Prompts\select;

use LaravelZero\Framework\Commands\Command;

class CloudConfigureTunnelCommand extends Command
{
    use ConfiguresCloudEnvironment, InteractsWithEnvironments, InteractsWithProjectConfig, LaraKubeOutput, ResolvesEnvironmentContext;

    protected $signature = 'cloud:configure:tunnel
        {environment? : The environment to configure (staging, production, …)}
        {--provider= : Tunnel provider: cloudflare or localtonet}
        {--token= : Token (or set CLOUDFLARE_TUNNEL_TOKEN / LOCALTONET_AUTH_TOKEN env vars)}
        {--remove : Tear down the tunnel Deployment and its Secret}';

    protected $description = 'Deploy a persistent Cloudflare or Localtonet tunnel for a cloud environment';

    public function handle(): int
    {
        $this->renderHeader();

        if (! $this->isLaraKubeProject()) {
            return 1;
        }

        $environment = (string) ($this->argument('environment') ?? $this->askForCloudEnvironment(
            label: 'Which environment should the tunnel be added to?',
        ));

        if ($environment === 'local') {
            $this->laraKubeWarn('Persistent tunnels are for cloud environments. Use `larakube share` for local dev.');

            return 1;
        }

        $projectPath = getcwd();
        $config = $this->getProjectConfigObject($projectPath);

        if (! isset($config->environments[$environment])) {
            $this->laraKubeError("Environment '{$environment}' is not in your blueprint.");

            return 1;
        }

        [$config, $context] = $this->resolveEnvironmentContext($config, $environment, $projectPath);
        $kubectl = $this->contextKubectl($context);
        $namespace = $this->getNamespace($environment, $config->getName());

        if ($this->option('remove')) {
            return $this->removeTunnel($kubectl, $namespace, $environment, $config, $projectPath);
        }

        $current = $config->environments[$environment]->tunnel;
        if ($current !== null) {
            $this->line("  <fg=gray>Current tunnel:</> <fg=cyan>{$current->provider->getLabel()}</> <fg=gray>on</> <fg=cyan>{$namespace}</>");
            $this->line('  <fg=gray>Re-running will replace the token and restart the Deployment.</> ');
            $this->laraKubeNewLine();
        }

        $provider = $this->resolveProvider();
        $token = $this->resolveToken($provider);

        if ($token === '') {
            $this->laraKubeError('No token provided. Aborting.');

            return 1;
        }

        $this->laraKubeInfo("Configuring {$provider->getLabel()} tunnel for '{$environment}'...");
        $this->line("  <fg=gray>Namespace:</> <fg=cyan>{$namespace}</>");

        // 1. Idempotent K8s Secret
        $this->withSpin('Storing tunnel token as K8s Secret...', function () use ($kubectl, $namespace, $token) {
            Process::run(
                $kubectl.' create secret generic larakube-tunnel-secret'
                .' -n '.escapeshellarg($namespace)
                .' --from-literal=TOKEN='.escapeshellarg($token)
                .' --dry-run=client -o yaml'
                .' | '.$kubectl.' apply -f -',
            );

            return true;
        });

        // 2. Tunnel Deployment
        $this->withSpin("Deploying {$provider->getLabel()} Deployment...", function () use ($provider, $namespace, $kubectl) {
            $manifest = view('k8s.tunnel.deployment', [
                'provider' => $provider,
                'namespace' => $namespace,
            ])->render();

            $tmp = sys_get_temp_dir().'/larakube-tunnel.yaml';
            file_put_contents($tmp, $manifest);
            Process::run($kubectl.' apply -f '.escapeshellarg($tmp));
            @unlink($tmp);

            return true;
        });

        // 3. Persist to blueprint
        $config->environments[$environment]->tunnel = new TunnelData(provider: $provider);
        $this->saveProjectConfig($projectPath, $config);

        $this->laraKubeInfo("✅ {$provider->getLabel()} tunnel deployed to '{$namespace}'.");
        $this->laraKubeNewLine();
        $this->printNextSteps($provider);

        return 0;
    }

    private function resolveProvider(): TunnelProvider
    {
        $opt = $this->option('provider');

        if ($opt !== null) {
            $p = TunnelProvider::tryFrom((string) $opt);
            if ($p === null) {
                $this->laraKubeWarn("Unknown provider '{$opt}' — defaulting to interactive picker.");
            } else {
                return $p;
            }
        }

        $chosen = select(
            label: 'Which tunnel provider?',
            options: [
                TunnelProvider::CLOUDFLARE->value => TunnelProvider::CLOUDFLARE->getLabel().' — '.TunnelProvider::CLOUDFLARE->getDescription(),
                TunnelProvider::LOCALTONET->value => TunnelProvider::LOCALTONET->getLabel().' — '.TunnelProvider::LOCALTONET->getDescription(),
            ],
            default: TunnelProvider::CLOUDFLARE->value,
        );

        return TunnelProvider::from($chosen);
    }

    private function resolveToken(TunnelProvider $provider): string
    {
        // CLI option wins
        if ($this->option('token')) {
            return (string) $this->option('token');
        }

        // Env var convenience (CI-friendly)
        $envVal = getenv($provider->envVarName());
        if ($envVal !== false && $envVal !== '') {
            $this->line("  <fg=gray>Using token from</> <fg=cyan>{$provider->envVarName()}</> <fg=gray>env var.</>");

            return $envVal;
        }

        return (string) password(
            label: $provider->tokenPromptLabel(),
            required: true,
        );
    }

    private function removeTunnel(string $kubectl, string $namespace, string $environment, mixed $config, string $projectPath): int
    {
        $this->laraKubeWarn("Removing tunnel from '{$namespace}'...");

        $this->withSpin('Deleting tunnel Deployment and Secret...', function () use ($kubectl, $namespace) {
            Process::run($kubectl.' -n '.escapeshellarg($namespace).' delete deployment larakube-tunnel --ignore-not-found');
            Process::run($kubectl.' -n '.escapeshellarg($namespace).' delete secret larakube-tunnel-secret --ignore-not-found');

            return true;
        });

        $config->environments[$environment]->tunnel = null;
        $this->saveProjectConfig($projectPath, $config);

        $this->laraKubeInfo("✅ Tunnel removed from '{$namespace}'.");

        return 0;
    }

    private function printNextSteps(TunnelProvider $provider): void
    {
        if ($provider === TunnelProvider::CLOUDFLARE) {
            $this->line('  <fg=gray>Next steps in the Cloudflare dashboard (Zero Trust → Networks → Tunnels):</>');
            $this->line('  1. Open your tunnel and go to the <fg=cyan>Public Hostname</> tab.');
            $this->line('  2. Add a route: <fg=cyan>your-domain.com</> → Service <fg=cyan>http://web.{namespace}.svc.cluster.local:80</>');
            $this->line('  3. For Reverb WebSockets, add: <fg=cyan>ws.your-domain.com</> → <fg=cyan>http://reverb.{namespace}.svc.cluster.local:8080</>');
            $this->line('  <fg=gray>(Replace {namespace} with your actual namespace, e.g. myapp-production)</>');
        } else {
            $this->line('  <fg=gray>Next steps in the Localtonet dashboard:</>');
            $this->line('  1. Open localtonet.com → <fg=cyan>Tunnels</> and add a TCP tunnel pointing at port 80.');
            $this->line('  2. Map your domain to the assigned public address.');
        }

        $this->laraKubeNewLine();
        $this->line('  <fg=gray>To remove the tunnel later:</> <fg=yellow>larakube cloud:configure:tunnel '.$this->argument('environment').' --remove</>');
        $this->line('  <fg=gray>Check tunnel pod status:</> <fg=yellow>larakube logs larakube-tunnel</>');
    }
}
