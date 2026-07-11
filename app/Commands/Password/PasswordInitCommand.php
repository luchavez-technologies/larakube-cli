<?php

namespace App\Commands\Password;

use App\Data\ConfigData;
use App\Enums\SharedClusterService;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithVault;
use App\Traits\LaraKubeOutput;
use App\Traits\StreamsProcessOutput;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;

class PasswordInitCommand extends Command
{
    use InteractsWithClusterContext, InteractsWithVault, LaraKubeOutput, StreamsProcessOutput;

    protected $signature = 'password:init
        {environment? : Environment this install targets — "local" (default) or a cloud env. Omit to be prompted. A non-local env prompts for + persists the Vaultwarden host.}
        {--context=  : Target a specific kube-context (defaults to current context)}
        {--env=      : Legacy alias for the environment argument}
        {--domain=   : Raw override for the Vaultwarden cluster domain (e.g. example.com → vault.example.com); skips the prompt}
        {--remove    : Tear down the Vaultwarden stack from larakube-vault}';

    protected $description = 'Deploy the cluster-wide Vaultwarden team password manager into larakube-vault';

    public function handle(): int
    {
        $this->renderHeader();

        return $this->option('remove')
            ? $this->removeVault()
            : $this->deployVault();
    }

    protected function deployVault(): int
    {
        $kubectl = $this->vaultKubectl($this->option('context'));
        $ns = $this->vaultNamespace();

        $host = $this->resolveVaultHost();

        $this->withSpin("Ensuring namespace {$ns}...", fn () => Process::run(
            "{$kubectl} create namespace {$ns} --dry-run=client -o yaml | {$kubectl} apply -f -",
        ));

        $adminToken = $this->readVaultAdminToken($kubectl, $ns) ?? bin2hex(random_bytes(16));

        $manifest = view('k8s.vault.shared', [
            'host' => $host,
            'adminToken' => $adminToken,
        ])->render();

        $tmp = sys_get_temp_dir().'/larakube-vault.yaml';
        file_put_contents($tmp, $manifest);

        $this->withSpin('Applying Vaultwarden manifests...', fn () => $this->runStreaming("{$kubectl} apply -f {$tmp}"));
        @unlink($tmp);

        $this->withSpin('Waiting for Vaultwarden...', fn () => $this->runStreaming(
            "{$kubectl} rollout status deploy/vaultwarden -n {$ns} --timeout=120s",
            130,
        ));

        $this->laraKubeNewLine();
        $this->laraKubeInfo('✅ Vaultwarden stack is live.');
        $this->newLine();
        $this->line("  <fg=gray>Vaultwarden URL:</>            <fg=blue>https://{$host}</>");
        $this->line("  <fg=gray>Admin Token:</>                <fg=yellow>{$adminToken}</>");
        $this->line("  <fg=gray>Admin URL:</>                  <fg=blue>https://{$host}/admin</>");
        $this->newLine();

        return 0;
    }

    protected function removeVault(): int
    {
        $kubectl = $this->vaultKubectl($this->option('context'));
        $ns = $this->vaultNamespace();

        $this->withSpin('Removing Vaultwarden namespace...', fn () => Process::run(
            "{$kubectl} delete namespace {$ns} --ignore-not-found",
        ));

        $this->laraKubeInfo('Vaultwarden removed from larakube-vault.');

        return 0;
    }

    /**
     * Resolve the Vaultwarden ingress host for this install.
     */
    protected function resolveVaultHost(): string
    {
        $service = SharedClusterService::VAULT;

        $domain = (string) ($this->option('domain') ?? '');
        if ($domain !== '') {
            return $service->hostFor($domain);
        }

        $env = $this->resolveEnvironment();

        if ($env === 'local') {
            return (string) $this->resolveVaultHostReadOnly('local', null);
        }

        return $this->promptForCloudVaultHost($service, $env);
    }

    /**
     * Decide which environment this install targets.
     */
    protected function resolveEnvironment(): string
    {
        $explicit = (string) ($this->argument('environment') ?: $this->option('env') ?: '');
        if ($explicit !== '') {
            return $explicit;
        }

        if ($this->option('no-interaction') || $this->option('domain')) {
            return 'local';
        }

        $projectPath = getcwd();
        $config = file_exists($projectPath.'/'.ConfigData::CONFIG_FILE)
            ? ConfigData::loadFromFile($projectPath)
            : null;

        $envs = $config ? array_merge(['local'], $config->getCloudEnvironments()) : ['local'];

        return select(
            label: 'Which environment is this Vaultwarden install for?',
            options: array_combine($envs, $envs),
            default: 'local',
            hint: 'Local uses your dev TLD; a cloud env asks for + persists the Vaultwarden host.',
        );
    }

    /**
     * Prompt for (and persist) a non-local Vaultwarden host.
     */
    protected function promptForCloudVaultHost(SharedClusterService $service, string $env): string
    {
        $projectPath = getcwd();
        $config = file_exists($projectPath.'/'.ConfigData::CONFIG_FILE)
            ? ConfigData::loadFromFile($projectPath)
            : null;

        $existing = $config?->getEnvironment($env)?->hosts[$service->value] ?? null;
        if ($existing) {
            return $existing;
        }

        $webHost = $config?->getEnvironment($env)?->hosts['web'] ?? null;
        $default = ($config && $webHost) ? $config->getSharedServiceHost($service, $env) : '';

        $host = text(
            label: "What host should {$service->label()} use in '{$env}'?",
            placeholder: $default !== '' ? $default : 'e.g. vault.example.com',
            default: $default,
            required: true,
            hint: 'Point this DNS at the cluster and add TLS like any other ingress host.',
        );

        if ($config) {
            $config->setHost($env, $service->value, $host);
            $config->saveToFile($projectPath);
            $this->laraKubeInfo("Saved {$service->label()} host for '{$env}' to .larakube.json");
        }

        return $host;
    }
}
