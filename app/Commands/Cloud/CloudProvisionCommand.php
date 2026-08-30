<?php

namespace App\Commands\Cloud;

use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use App\Traits\ProvisionsK3sNode;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;

class CloudProvisionCommand extends Command
{
    use InteractsWithProjectConfig, LaraKubeOutput, ProvisionsK3sNode;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'cloud:init
        {environment? : Inside a project, the environment to bind to this VPS.}
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

        // One command, one job: this provisions a VPS. DOKS has its own command,
        // cloud:init:doks, so there is nothing left to choose between.
        //
        // A `target` positional used to sit beside `environment`, which meant
        // two optional positionals of different kinds and no way to tell them
        // apart -- the command sniffed the first word for the literals "vps"
        // and "doks" to guess which was which, so an environment legitimately
        // named "vps" was read as a target.
        $environment = $this->argument('environment');

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
        $email = $this->validStoredEmail($this->getEmail());
        if (! $email) {
            $email = text(
                label: 'What is your email address? (used for SSL/Let\'sEncrypt)',
                placeholder: 'you@yourdomain.com',
                required: true,
                validate: fn (string $value) => $this->acmeEmailError($value),
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

        $this->newLine();
        if (confirm('Would you like to automate DNS records with Cloudflare for this cluster?')) {
            $this->call('dns:init', ['environment' => $environment ?: 'production']);
        }

        return 0;
    }
}
