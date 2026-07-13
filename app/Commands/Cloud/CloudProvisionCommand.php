<?php

namespace App\Commands\Cloud;

use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use App\Traits\ProvisionsK3sNode;

use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;

class CloudProvisionCommand extends Command
{
    use InteractsWithProjectConfig, LaraKubeOutput, ProvisionsK3sNode;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'cloud:init
        {target? : What to provision — "vps" (default) or "doks". Omit to be asked.}
        {--context= : (DOKS only) target a specific kube-context}';

    /**
     * Backward-compatible alias for the pre-rename command name.
     *
     * @var array<int, string>
     */
    protected $aliases = ['cloud:provision'];

    /**
     * The console command description.
     */
    protected $description = 'Secures and prepares a fresh VPS for LaraKube (K3s Single-Node)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->renderHeader();

        // Which target? Explicit arg ("vps"/"doks") wins; otherwise ask.
        $target = $this->argument('target') ?: select(
            label: 'What are you provisioning?',
            options: [
                'vps' => 'VPS / bare server (SSH + k3s, single-node)',
                'doks' => 'DigitalOcean Kubernetes (managed, multi-node)',
            ],
            default: 'vps',
        );

        // DOKS is a separate flow — delegate to its dedicated command.
        if ($target === 'doks') {
            return (int) $this->call('cloud:init:doks', array_filter([
                '--context' => $this->option('context'),
            ]));
        }

        if ($target !== 'vps') {
            $this->laraKubeError("Unknown provisioning target: '{$target}'. Use 'vps' or 'doks'.");

            return 1;
        }

        $this->laraKubeInfo('LaraKube Cloud Pilot: VPS Provisioner');
        $this->laraKubeWarn('Recommended: 1GB RAM minimum for stable K3s deployments.');
        $this->newLine();

        $ip = text(
            label: 'What is the IP address of your fresh VPS?',
            required: true,
            placeholder: 'e.g. 123.45.67.89',
        );

        $user = text(
            label: 'SSH User (must have sudo access)',
            default: 'root',
        );

        $port = text(
            label: 'SSH Port',
            default: '22',
        );

        $keyPath = text(
            label: 'Path to your SSH Private Key',
            default: home_path('.ssh/id_rsa'),
        );

        // Resolve ~ in keyPath
        $keyPath = str_replace('~', home_path(), $keyPath);

        if (! file_exists($keyPath)) {
            $this->laraKubeError("SSH key not found at: {$keyPath}");

            return 1;
        }

        // --- 🛡 GLOBAL SECURITY CONTEXT ---
        $email = $this->getEmail();
        if (! $email) {
            $email = text(
                label: 'What is your email address? (used for SSL/Let\'sEncrypt)',
                placeholder: 'admin@example.com',
                required: true,
                validate: fn (string $value) => filter_var($value, FILTER_VALIDATE_EMAIL) ? null : 'Please enter a valid email address.',
            );
            $this->setEmail($email);
        }

        $this->laraKubeInfo("Testing SSH connection to {$user}@{$ip}...");

        if (! $this->testSsh($user, $ip, $port, $keyPath)) {
            $this->laraKubeError('Could not connect to the server via SSH. Please check your credentials and try again.');

            return 1;
        }

        $this->laraKubeInfo('Connection successful!');

        $config = $this->getProjectConfigObject(getcwd());

        // The full single-node pipeline (k3s, larakube user, harden, lock root,
        // kubeconfig, Traefik) lives in ProvisionsK3sNode so cloud:create shares it.
        $this->provisionK3sNode($user, $ip, $port, $keyPath, $config);

        $this->laraKubeInfo('✅ Provisioning complete!');
        $this->info('Your VPS is now a LaraKube-hardened K3s node (firewall, fail2ban, key-only SSH, encrypted Secrets, auto security updates).');
        $this->line('  <fg=gray>Recommended follow-up: add default-deny NetworkPolicies, and restrict the k3s API (6443) to your IP.</>');

        return 0;
    }
}
