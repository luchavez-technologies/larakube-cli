<?php

namespace App\Traits;

use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\confirm;

/**
 * The shared single-node k3s provisioning pipeline: install k3s, create the
 * `larakube` sudo user, harden the OS, lock down root SSH, sync the kubeconfig,
 * and deploy Traefik. Extracted from CloudProvisionCommand so BOTH the
 * "bring-your-own-IP" flow (`cloud:init`) and the OpenTofu-driven flow
 * (`cloud:create`, which provisions the droplet first) drive ONE code path and
 * never drift apart.
 *
 * Pulls in the lower-level building blocks it orchestrates (k3s install command,
 * SSH runner, hardening scripts). The using command provides LaraKubeOutput
 * (and therefore InteractsWithGlobalConfig::getEmail) and InteractsWithProjectConfig.
 */
trait ProvisionsK3sNode
{
    use InstallsK3s, InteractsWithRemoteSsh, InteractsWithServerHardening;

    /**
     * Poll the SSH endpoint until it answers (or we give up). A freshly created
     * droplet needs ~30–60s before sshd accepts connections, so the OpenTofu
     * flow calls this between `tofu apply` and the provisioning steps. The
     * bring-your-own-IP flow doesn't need it (the box is already up) but may use
     * it defensively.
     *
     * @param  int  $maxAttempts  attempts at $delay-second intervals (default ~2.5min)
     */
    protected function waitForSsh($user, $ip, $port, $keyPath, int $maxAttempts = 30, int $delay = 5): bool
    {
        $this->laraKubeInfo("Waiting for SSH on {$user}@{$ip}:{$port}...");

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            if ($this->testSsh($user, $ip, $port, $keyPath)) {
                $this->laraKubeInfo('SSH is up.');

                return true;
            }

            if ($attempt % 3 === 0) {
                $this->line('  ⏳ Still waiting for sshd... ('.($attempt * $delay).'s)');
            }
            sleep($delay);
        }

        return false;
    }

    /**
     * Install K3s on the remote server.
     */
    protected function installK3s($user, $ip, $port, $keyPath, $config): void
    {
        $this->laraKubeInfo('Hardening OS and Installing K3s on remote server...');

        $installK3s = $this->k3sInstallCommand($this->k3sVersion($config), [
            '--disable=traefik',
            '--write-kubeconfig-mode 644',
            '--kubelet-arg=fail-swap-on=false',
        ]);

        // 1. Create Swap (Crucial for 512MB droplets)
        // 2. Enable IP Forwarding
        // 3. Install K3s (optimized for single-node)
        $remoteCommand = <<<BASH
    if [ ! -f /swapfile ]; then
    echo "Creating 1GB Swap file for stability..."
    fallocate -l 1G /swapfile
    chmod 600 /swapfile
    mkswap /swapfile
    swapon /swapfile
    echo '/swapfile none swap sw 0 0' | tee -a /etc/fstab
    fi

    echo "Enabling IP Forwarding..."
    sysctl -w net.ipv4.ip_forward=1
    grep -qxF 'net.ipv4.ip_forward=1' /etc/sysctl.conf || echo 'net.ipv4.ip_forward=1' | tee -a /etc/sysctl.conf

    echo "Installing K3s..."
    {$installK3s}
    BASH;

        $this->runRemoteCommand($user, $ip, $port, $keyPath, $remoteCommand);

        $this->laraKubeInfo('K3s installed and OS hardened.');
    }

    /**
     * Create a dedicated LaraKube user with sudo access.
     */
    protected function createLaraKubeUser($user, $ip, $port, $keyPath): void
    {
        $this->laraKubeInfo('Creating "larakube" user...');

        $pubKeyPath = $keyPath.'.pub';
        if (! file_exists($pubKeyPath)) {
            $this->laraKubeWarn("Public key not found at {$pubKeyPath}. Skipping user creation.");

            return;
        }

        $pubKey = trim(file_get_contents($pubKeyPath));

        $remoteCommand = <<<BASH
if ! id "larakube" &>/dev/null; then
    useradd -m -s /bin/bash larakube
    usermod -aG sudo larakube
    mkdir -p /home/larakube/.ssh
    echo "{$pubKey}" > /home/larakube/.ssh/authorized_keys
    chown -R larakube:larakube /home/larakube/.ssh
    chmod 700 /home/larakube/.ssh
    chmod 600 /home/larakube/.ssh/authorized_keys
    echo "larakube ALL=(ALL) NOPASSWD:ALL" > /etc/sudoers.d/larakube
fi
BASH;

        $this->runRemoteCommand($user, $ip, $port, $keyPath, $remoteCommand);
        $this->laraKubeInfo('User "larakube" created and configured.');
    }

    /**
     * Harden the freshly provisioned node: UFW (SSH/HTTP/HTTPS/k3s-API + cluster
     * CIDRs), fail2ban, and key-only SSH. The script (built by
     * InteractsWithServerHardening) allows the SSH port before enabling UFW, so
     * this never strands the in-flight connection.
     */
    protected function hardenServer($user, $ip, int $port, $keyPath): void
    {
        $this->laraKubeInfo('Hardening server (firewall, fail2ban, SSH)...');

        $this->runRemoteCommand($user, $ip, $port, $keyPath, $this->hardenServerScript($port));

        $this->laraKubeInfo('✅ Hardened: UFW (SSH/80/443/6443 + pod & service CIDRs), fail2ban, auto-updates, key-only SSH.');
        $this->info('   Note: k3s API (6443) is open to the internet — restricting it to your IP is a recommended follow-up.');
    }

    /**
     * Close remote root SSH login — but ONLY after proving the "larakube" user
     * can both log in (same key) and run sudo, so we never cut the last remote
     * admin path. If either check fails, we leave root login enabled and warn.
     */
    protected function lockDownRootLogin($user, $ip, int $port, $keyPath): bool
    {
        $this->laraKubeInfo('Verifying the "larakube" login works before disabling root...');

        if (! $this->testSsh('larakube', $ip, $port, $keyPath)) {
            $this->laraKubeWarn('Could not SSH as "larakube" — leaving root login ENABLED to avoid lockout.');
            $this->info('   (Did you create the larakube user, and does your key have a .pub sibling?)');

            return false;
        }

        if (! $this->canSudo('larakube', $ip, $port, $keyPath)) {
            $this->laraKubeWarn('"larakube" cannot passwordless-sudo — leaving root login ENABLED to avoid lockout.');

            return false;
        }

        // Safe: we're still connected as root here, so this only affects FUTURE logins.
        $this->runRemoteCommand($user, $ip, $port, $keyPath, $this->disableRootLoginScript());

        $this->laraKubeInfo('✅ Remote root login disabled. Using the "larakube" user from now on.');

        return true;
    }

    /**
     * Sync remote Kubeconfig to local machine.
     */
    protected function syncKubeconfig($user, $ip, $port, $keyPath, $contextName): void
    {
        $this->laraKubeInfo('Syncing Kubeconfig...');

        $localKubeConfig = home_path('.kube/config');
        $backupPath = home_path('.kube/config.bak.'.time());

        if (file_exists($localKubeConfig)) {
            copy($localKubeConfig, $backupPath);
            $this->info("  🛡 Local kubeconfig backed up to {$backupPath}");
        } else {
            if (! is_dir(home_path('.kube'))) {
                mkdir(home_path('.kube'), 0700, true);
            }
        }

        // Fetch remote config
        $tmpRemoteConfig = tempnam(sys_get_temp_dir(), 'k3s_remote');
        Process::run("scp -i {$keyPath} -P {$port} {$user}@{$ip}:/etc/rancher/k3s/k3s.yaml {$tmpRemoteConfig}");

        if (! file_exists($tmpRemoteConfig) || filesize($tmpRemoteConfig) === 0) {
            $this->laraKubeError('Failed to fetch remote kubeconfig.');

            return;
        }

        $configContent = file_get_contents($tmpRemoteConfig);

        // Update 127.0.0.1 to server IP
        $configContent = str_replace('127.0.0.1', $ip, $configContent);

        // Change context name to larakube-{ip}
        $configContent = str_replace('default', $contextName, $configContent);

        file_put_contents($tmpRemoteConfig, $configContent);

        // --- 🛡 SECURE MERGE ENGINE ---
        // We use the KUBECONFIG env var trick to let kubectl handle the YAML merging logic safely
        if (file_exists($localKubeConfig)) {
            $mergeCmd = "KUBECONFIG={$localKubeConfig}:{$tmpRemoteConfig} kubectl config view --flatten";
            $mergedContent = Process::run($mergeCmd)->output();

            if ($mergedContent !== '') {
                file_put_contents($localKubeConfig, $mergedContent);
            } else {
                $this->laraKubeError('Failed to merge Kubeconfig. Manual intervention required.');
            }
        } else {
            copy($tmpRemoteConfig, $localKubeConfig);
        }

        unlink($tmpRemoteConfig);

        $this->laraKubeInfo("Kubeconfig synced and merged. Context: {$contextName}");
        $this->info("You can now run: kubectl config use-context {$contextName}");
    }

    /**
     * Is Traefik already installed on this kube-context? Lets a SECOND environment
     * attaching to the same VPS/cluster skip the install instead of clobbering the
     * running ingress (mirrors the DOKS flow's guard).
     */
    protected function traefikInstalledOnContext(string $contextName): bool
    {
        return Process::run('kubectl --context '.escapeshellarg($contextName).' get deployment -n traefik traefik')->successful();
    }

    /**
     * Deploy Traefik to the remote cluster. Skips when Traefik is already present
     * (e.g. a second env sharing the same single-node VPS).
     */
    protected function deployTraefik($contextName): void
    {
        if ($this->traefikInstalledOnContext($contextName)) {
            $this->laraKubeInfo('ℹ️  Traefik is already installed on this cluster — skipping deploy.');

            return;
        }

        $this->laraKubeInfo('Deploying Traefik (Single-Node Hero) to remote cluster...');

        $kubectl = 'kubectl --context '.escapeshellarg($contextName);
        $namespace = 'traefik';

        Process::run("{$kubectl} create namespace {$namespace} --dry-run=client -o yaml | {$kubectl} apply -f -");

        // 1. Create ConfigMap for Traefik dynamic configuration
        $tmpCertsYml = sys_get_temp_dir().'/traefik-certs.yml';
        file_put_contents($tmpCertsYml, view('traefik.dev-certs')->render());
        Process::run("{$kubectl} create configmap traefik-config -n {$namespace} --from-file=traefik-certs.yml={$tmpCertsYml} --dry-run=client -o yaml | {$kubectl} apply -f -");

        // 2. Create Secret for SSL certificates
        $certDir = base_path('resources/views/traefik/certificates');
        $tmpDevPem = sys_get_temp_dir().'/local-dev.pem';
        $tmpDevKeyPem = sys_get_temp_dir().'/local-dev-key.pem';

        // Ensure paths work in PHAR or Dev
        $devPemContent = @file_get_contents("{$certDir}/local-dev.pem");
        $devKeyPemContent = @file_get_contents("{$certDir}/local-dev-key.pem");

        if ($devPemContent && $devKeyPemContent) {
            file_put_contents($tmpDevPem, $devPemContent);
            file_put_contents($tmpDevKeyPem, $devKeyPemContent);
            Process::run("{$kubectl} create secret generic traefik-certificates -n {$namespace} --from-file=local-dev.pem={$tmpDevPem} --from-file=local-dev-key.pem={$tmpDevKeyPem} --dry-run=client -o yaml | {$kubectl} apply -f -");
        } else {
            $this->laraKubeWarn('Could not find local dev certificates. Skipping SSL secret creation.');
        }

        // 3. Apply Traefik Cloud manifest
        $tmpInstall = sys_get_temp_dir().'/traefik-cloud.yaml';
        file_put_contents($tmpInstall, view('k8s.traefik-cloud', ['email' => $this->getEmail()])->render());
        Process::run("{$kubectl} apply -f {$tmpInstall} --request-timeout=60s --validate=false");

        $this->laraKubeInfo('Traefik deployed and configured with HostPort and ACME (Let\'sEncrypt).');

        @unlink($tmpCertsYml);
        @unlink($tmpDevPem);
        @unlink($tmpDevKeyPem);
        @unlink($tmpInstall);
    }

    /**
     * The full single-node provisioning pipeline against an already-reachable host.
     * Shared by `cloud:init` and `cloud:create` (post-droplet). Returns the kube
     * context name. `$user` may be promoted to `larakube` when root login is closed.
     */
    protected function provisionK3sNode(string $user, string $ip, string $port, string $keyPath, $config, bool $interactive = true): string
    {
        // 1. Install K3s
        if (! $interactive || confirm('Install K3s on the remote server?', true)) {
            $this->installK3s($user, $ip, $port, $keyPath, $config);
        }

        // 2. Create larakube user if it's root
        if ($user === 'root' && (! $interactive || confirm('Create a dedicated "larakube" user with sudo access?', true))) {
            $this->createLaraKubeUser($user, $ip, $port, $keyPath);
        }

        // 3. Harden the server (firewall + fail2ban + auto-updates + key-only SSH)
        if (! $interactive || confirm('Harden the server now (UFW firewall, fail2ban, auto-updates, key-only SSH)?', true)) {
            $this->hardenServer($user, $ip, (int) $port, $keyPath);
        }

        // 4. Optionally close remote root login (only once "larakube" is a working
        // sudo login, so we never strand the box).
        if ($user === 'root' && (! $interactive || confirm('Disable remote root SSH login? ("larakube" becomes your login — recommended)', true))) {
            if ($this->lockDownRootLogin($user, $ip, (int) $port, $keyPath)) {
                $user = 'larakube';
            }
        }

        // 5. Sync Kubeconfig (as $user — now "larakube" if root login was closed)
        $contextName = "larakube-{$ip}";
        if (! $interactive || confirm('Sync remote Kubeconfig to your local machine?', true)) {
            $this->syncKubeconfig($user, $ip, $port, $keyPath, $contextName);
        }

        // 6. Deploy Traefik
        if (! $interactive || confirm('Deploy Traefik (Single-Node Hero)?', true)) {
            $this->deployTraefik($contextName);
        }

        return $contextName;
    }
}
