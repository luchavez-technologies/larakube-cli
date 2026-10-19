<?php

namespace App\Commands\Cloud;

use App\Data\StackData;
use App\Exceptions\MissingFlagException;
use App\Services\SshKeyInspector;
use App\Traits\EmitsJsonOutput;
use App\Traits\InteractsWithGlobalConfig;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use App\Traits\ProvisionsK3sNode;
use App\Traits\ReadsCommandOptions;
use App\Traits\RequiresFlagsWhenNonInteractive;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;
use Spatie\TemporaryDirectory\TemporaryDirectory;

class CloudProvisionCommand extends Command
{
    use EmitsJsonOutput, InteractsWithGlobalConfig, InteractsWithProjectConfig, LaraKubeOutput, ProvisionsK3sNode, ReadsCommandOptions, RequiresFlagsWhenNonInteractive;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'cloud:init
        {environment? : Inside a project, the environment to bind to this VPS.}
        {--ip= : Public IP address of the fresh VPS}
        {--ssh-user=root : SSH user with sudo privileges}
        {--ssh-key= : Path to SSH private key (default: ~/.ssh/id_rsa or ~/.ssh/id_ed25519)}
        {--ssh-port=22 : SSH port to connect to}
        {--ssh-passphrase= : Passphrase for encrypted private key (if needed)}
        {--stack-name= : Custom stack identifier (default: vps-<ip>)}
        {--admin-email= : Admin email address for Let\'s Encrypt / ACME}
        {--without-dns : Skip ExternalDNS setup}
        {--without-tls : Skip Cloudflare DNS challenge SSL setup}
        {--cloudflare : Automate DNS and TLS with Cloudflare}
        {--cloudflare-token= : Cloudflare API token}
        {--context= : Custom kube-context name}
        {--json : Emit one machine-readable JSON result on stdout}';

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
        if ($this->flag('json')) {
            $this->enableJsonMode();
        }

        $this->renderHeader();

        $environment = $this->argument('environment');

        $this->laraKubeInfo('LaraKube Cloud Pilot: VPS Provisioner');
        $this->laraKubeWarn('Recommended: 1GB RAM minimum for stable K3s deployments.');
        $this->newLine();

        $ip = $this->flagOrPrompt(
            'ip',
            fn () => text(
                label: 'What is the IP address of your fresh VPS?',
                required: true,
                placeholder: 'e.g. 123.45.67.89',
            ),
            'Public IP address of the fresh VPS',
            '--ip=123.45.67.89',
        );

        $user = (string) ($this->option('ssh-user') ?: ($this->cannotPrompt() ? 'root' : text(
            label: 'SSH User (must have sudo access)',
            default: 'root',
        )));

        $port = (string) ($this->option('ssh-port') ?: ($this->cannotPrompt() ? '22' : text(
            label: 'SSH Port',
            default: '22',
        )));

        $defaultKey = file_exists(home_path('.ssh/id_ed25519')) ? home_path('.ssh/id_ed25519') : home_path('.ssh/id_rsa');
        $keyPath = (string) ($this->option('ssh-key') ?: ($this->cannotPrompt() ? $defaultKey : text(
            label: 'Path to your SSH Private Key',
            default: $defaultKey,
        )));

        // Resolve ~ in keyPath
        $keyPath = str_replace('~', home_path(), $keyPath);

        if (! file_exists($keyPath)) {
            if ($this->cannotPrompt()) {
                throw new MissingFlagException('ssh-key', "SSH private key not found at {$keyPath}", "--ssh-key={$defaultKey}");
            }
            $this->laraKubeError("SSH key not found at: {$keyPath}");

            return 1;
        }

        // --- 🛡 GLOBAL SECURITY CONTEXT ---
        $email = (string) $this->option('admin-email');
        if ($email === '') {
            $email = (string) $this->validStoredEmail($this->getEmail());
        }

        if ($email === '') {
            if ($this->cannotPrompt()) {
                throw new MissingFlagException('admin-email', 'Admin email address for Let\'s Encrypt / ACME', '--admin-email=admin@example.com');
            }
            $email = text(
                label: 'What is your email address? (used for SSL/Let\'sEncrypt)',
                placeholder: 'you@yourdomain.com',
                required: true,
                validate: fn (string $value) => $this->acmeEmailError($value),
            );
            $this->setEmail($email);
        }

        // --- 🔑 ENCRYPTED SSH KEY HANDLING ---
        $inspector = new SshKeyInspector;
        $effectiveKeyPath = $keyPath;
        $tempDir = null;

        if ($inspector->isEncrypted($keyPath) && ! $inspector->isInAgent($keyPath)) {
            $passphrase = (string) ($this->option('ssh-passphrase') ?: getenv('LARAKUBE_SSH_PASSPHRASE'));
            if ($passphrase === '' && ! $this->cannotPrompt()) {
                $passphrase = (string) password(
                    label: 'Enter passphrase for your SSH private key:',
                    required: true,
                );
            }

            if ($passphrase === '') {
                $this->laraKubeError('SSH key is encrypted with a passphrase, but no passphrase was provided.');

                return 1;
            }

            $tempDir = TemporaryDirectory::make();
            $tempKey = $tempDir->path().'/id_unlocked';
            if (! $inspector->unlockToTemp($keyPath, $passphrase, $tempKey)) {
                $this->laraKubeError('Failed to decrypt SSH private key with the provided passphrase.');
                $tempDir->delete();

                return 1;
            }

            $effectiveKeyPath = $tempKey;
        }

        try {
            // Drop any stale host key for this IP from previous instances
            $this->forgetHostKey($ip);

            $this->laraKubeInfo("Testing SSH connection to {$user}@{$ip}...");

            if (! $this->testSsh($user, $ip, $port, $effectiveKeyPath)) {
                $this->laraKubeError('Could not connect to the server via SSH. Please check your credentials and try again.');

                return 1;
            }

            $this->laraKubeInfo('Connection successful!');

            $config = $this->getProjectConfigObject(getcwd());

            // The full single-node pipeline (k3s, larakube user, harden, lock root,
            // kubeconfig, Traefik) lives in ProvisionsK3sNode so cloud:create shares it.
            $customContext = (string) $this->option('context');
            $context = $this->provisionK3sNode(
                user: $user,
                ip: $ip,
                port: $port,
                keyPath: $effectiveKeyPath,
                config: $config,
                interactive: ! $this->cannotPrompt(),
            );

            if ($customContext !== '' && $customContext !== $context) {
                // Rename context if an explicit --context= was specified
                Process::run(['kubectl', 'config', 'rename-context', $context, $customContext]);
                $context = $customContext;
            }

            // Register stack in GlobalConfig
            $stackName = (string) ($this->option('stack-name') ?: 'vps-'.str_replace('.', '-', $ip));
            $stack = new StackData(
                name: $stackName,
                provider: 'custom',
                kind: 'vps',
                region: 'custom',
                context: $context,
                ip: $ip,
                account: null,
                projectId: null,
                createdAt: gmdate('c'),
                sshKey: $keyPath,
                role: 'deploy',
                tunnelPort: null,
                port: (int) $port,
            );

            if ($config && $environment) {
                $stack->bind($config->getName(), $environment);
            }

            $globalConfig = $this->getGlobalConfig();
            $globalConfig->putStack($stack);
            $globalConfig->save();

            $this->laraKubeInfo('✅ Provisioning complete!');
            $this->info('Your VPS is now a LaraKube-hardened K3s node (firewall, fail2ban, key-only SSH, encrypted Secrets, auto security updates).');
            $this->line('  <fg=gray>Recommended follow-up: add default-deny NetworkPolicies, and restrict the k3s API (6443) to your IP.</>');

            $this->newLine();

            if ($cfToken = $this->option('cloudflare-token')) {
                putenv("LARAKUBE_CLOUDFLARE_TOKEN={$cfToken}");
            }

            if (! $this->option('without-dns')) {
                if ($this->option('cloudflare') || (! $this->cannotPrompt() && confirm('Would you like to automate DNS records with Cloudflare for this cluster?'))) {
                    $this->call('tool:init', ['--tool' => 'external-dns', 'environment' => $environment ?: 'production', '--context' => $context]);
                }
            }

            if (! $this->option('without-tls')) {
                if ($this->option('cloudflare') || (! $this->cannotPrompt() && confirm('Would you like to enable the Cloudflare DNS challenge for SSL certificates (so proxied hosts keep renewing)?', default: true))) {
                    $this->call('tls:init', ['environment' => $environment ?: 'production', '--context' => $context]);
                }
            }

            if ($this->flag('json')) {
                $this->jsonOutput([
                    'success' => true,
                    'name' => $stackName,
                    'ip' => $ip,
                    'context' => $context,
                    'provider' => 'custom',
                    'kind' => 'vps',
                    'warnings' => array_filter([$this->traefikWarning]),
                ]);
            }

            return 0;
        } finally {
            $tempDir?->delete();
        }
    }
}
