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
     * Install K3s on the remote server.
     */
    protected function installK3s($user, $ip, $port, $keyPath, $config): void
    {
        $this->laraKubeInfo('Hardening OS and Installing K3s on remote server...');

        $installK3s = $this->k3sInstallCommand($this->k3sVersion($config), [
            '--disable=traefik',
            '--write-kubeconfig-mode 644',
            '--kubelet-arg=fail-swap-on=false',
            // Encrypts Secret data at rest in k3s's datastore (AES-CBC) — otherwise
            // it's only base64, not actually encrypted. Transparent to every
            // client here: the API server decrypts on the fly for any `kubectl get
            // secret`, so dotenv/dotenv:audit (and everything else) need no changes.
            '--secrets-encryption',
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
     * Poll until the remote k3s kubeconfig is actually written and the node is
     * Ready. installK3s()'s SSH command returns as soon as the k3s SYSTEMD
     * SERVICE is active — but /etc/rancher/k3s/k3s.yaml (and node readiness)
     * can lag several seconds behind that, especially on the 1GB-RAM droplets
     * this flow explicitly targets (see the RAM warning in cloud:create).
     * Without this wait, syncKubeconfig() (called after 3 more SSH round-trips
     * for user creation/hardening/root lockdown — often, but not reliably,
     * enough buffer on its own) can SCP a still-empty or partially-written
     * file, producing a sync that looks like a random one-off failure rather
     * than the timing race it actually is.
     */
    protected function waitForK3sReady($user, $ip, $port, $keyPath, int $maxAttempts = 24, int $delay = 5): bool
    {
        $this->laraKubeInfo('Waiting for k3s to report ready...');

        $sudo = $user !== 'root' ? 'sudo ' : '';
        $check = "{$sudo}test -s /etc/rancher/k3s/k3s.yaml && {$sudo}k3s kubectl get nodes 2>/dev/null | grep -q ' Ready'";
        $command = "ssh -o ConnectTimeout=5 -o BatchMode=yes -o StrictHostKeyChecking=no -i {$keyPath} -p {$port} {$user}@{$ip} ".escapeshellarg($check);

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            if (Process::run($command)->successful()) {
                $this->laraKubeInfo('k3s is ready.');

                return true;
            }

            if ($attempt % 3 === 0) {
                $this->line('  ⏳ Still waiting for k3s... ('.($attempt * $delay).'s)');
            }
            sleep($delay);
        }

        return false;
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
    protected function hardenServer($user, $ip, int $port, $keyPath, ?string $adminCidr = null): void
    {
        $this->laraKubeInfo('Updating packages and hardening server (firewall, fail2ban, SSH)...');
        $this->line('  <fg=gray>This includes a full system upgrade before k3s is installed — can take a few minutes on a fresh droplet.</>');

        $this->runRemoteCommand($user, $ip, $port, $keyPath, $this->hardenServerScript($port, adminCidr: $adminCidr));

        $this->rebootIfRequired($user, $ip, $port, $keyPath);

        $this->laraKubeInfo('✅ Hardened: system packages updated, UFW (SSH/80/443'.($adminCidr ? "/6443 restricted to {$adminCidr}" : '/6443').' + pod & service CIDRs), fail2ban, auto-updates, key-only SSH.');
        if (! $adminCidr) {
            $this->info('   Note: k3s API (6443) is open to the internet — restricting it to your IP is a recommended follow-up (larakube cloud:harden --admin-cidr=).');
        }
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
    /**
     * Fetch, merge, and write the remote node's kubeconfig — returning whether
     * it actually worked. Every step is verified rather than trusted: a
     * silently-failed write or an incomplete merge used to leave the caller
     * printing "synced!" while the local kubeconfig never actually gained the
     * new context, which is exactly what "syncing consistently fails" turned
     * out to mean in practice.
     */
    protected function syncKubeconfig($user, $ip, $port, $keyPath, $contextName): bool
    {
        $this->laraKubeInfo('Syncing Kubeconfig...');

        $localKubeConfig = home_path('.kube/config');
        $backupPath = home_path('.kube/config.bak.'.time());

        if (file_exists($localKubeConfig)) {
            copy($localKubeConfig, $backupPath);
            $this->info("  🛡 Local kubeconfig backed up to {$backupPath}");
        } elseif (! is_dir(home_path('.kube'))) {
            mkdir(home_path('.kube'), 0700, true);
        }

        // Fetch remote config
        $tmpRemoteConfig = tempnam(sys_get_temp_dir(), 'k3s_remote');
        Process::run("scp -i {$keyPath} -P {$port} {$user}@{$ip}:/etc/rancher/k3s/k3s.yaml {$tmpRemoteConfig}");

        if (! file_exists($tmpRemoteConfig) || filesize($tmpRemoteConfig) === 0) {
            $this->laraKubeError('Failed to fetch remote kubeconfig — k3s may not be fully ready yet. Re-run once it is.');
            @unlink($tmpRemoteConfig);

            return false;
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

            // A bare "not empty" check isn't enough — kubectl can still emit a
            // valid-looking (but incomplete) flatten from a truncated remote
            // file. Confirm the new context name actually made it in.
            if ($mergedContent === '' || ! str_contains($mergedContent, $contextName)) {
                $this->laraKubeError("Failed to merge kubeconfig — '{$contextName}' is missing from the merged result. Manual intervention required.");
                unlink($tmpRemoteConfig);

                return false;
            }

            // Atomic write: stage in the same directory, then rename — a plain
            // overwrite that gets interrupted (Ctrl+C, a dropped SSH session,
            // disk full) partway through would otherwise corrupt the ENTIRE
            // kubeconfig, not just the new entry.
            $staged = $localKubeConfig.'.tmp.'.getmypid();
            if (file_put_contents($staged, $mergedContent) === false || ! rename($staged, $localKubeConfig)) {
                $this->laraKubeError("Failed to write the merged kubeconfig to {$localKubeConfig} — check file permissions/ownership.");
                @unlink($staged);
                unlink($tmpRemoteConfig);

                return false;
            }
        } elseif (! copy($tmpRemoteConfig, $localKubeConfig)) {
            $this->laraKubeError("Failed to write {$localKubeConfig} — check that ~/.kube is writable.");
            unlink($tmpRemoteConfig);

            return false;
        }

        unlink($tmpRemoteConfig);

        // Final read-back: confirm the context that's supposed to be there
        // actually is, catching any write-succeeded-but-wrong-content case the
        // checks above missed.
        if (! str_contains((string) @file_get_contents($localKubeConfig), $contextName)) {
            $this->laraKubeError("Kubeconfig write completed, but '{$contextName}' isn't in the final file. Manual intervention required.");

            return false;
        }

        $this->laraKubeInfo("Kubeconfig synced and merged. Context: {$contextName}");
        $this->info("You can now run: kubectl config use-context {$contextName}");

        return true;
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
    protected function provisionK3sNode(string $user, string $ip, string $port, string $keyPath, $config, bool $interactive = true, ?string $adminCidr = null): string
    {
        // 1. Harden the server FIRST (system update + firewall + fail2ban +
        // auto-updates + key-only SSH) — deliberately before k3s exists. UFW's
        // `--force enable` sets a default-DROP forward policy (Ubuntu's stock
        // /etc/default/ufw), and k3s's CNI (flannel) relies on FORWARD traffic
        // for pod-to-pod/pod-to-apiserver routing. Enabling UFW AFTER k3s is
        // already running can sever that already-established networking out
        // from under it — a well-known UFW+Kubernetes footgun, and the most
        // likely real cause behind kubeconfig syncing "randomly" failing: k3s
        // wasn't actually stable/reachable anymore by the time step 5 ran.
        // Doing the full system upgrade here too, instead of leaving it to a
        // separate step, means k3s installs onto an already-current, settled
        // base rather than racing a background apt/cloud-init process from
        // the droplet's own first boot.
        if (! $interactive || confirm('Harden the server now (system update, UFW firewall, fail2ban, auto-updates, key-only SSH)?', true)) {
            $this->hardenServer($user, $ip, (int) $port, $keyPath, $adminCidr);
        }

        // 2. Create larakube user if it's root
        if ($user === 'root' && (! $interactive || confirm('Create a dedicated "larakube" user with sudo access?', true))) {
            $this->createLaraKubeUser($user, $ip, $port, $keyPath);
        }

        // 3. Install K3s — now onto an already-hardened, already-updated base.
        if (! $interactive || confirm('Install K3s on the remote server?', true)) {
            $this->installK3s($user, $ip, $port, $keyPath, $config);

            if (! $this->waitForK3sReady($user, $ip, $port, $keyPath)) {
                $this->laraKubeWarn('k3s did not report ready in time — continuing anyway, but the kubeconfig sync in step 5 may need a retry.');
            }
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
        $kubeconfigSynced = true;
        if (! $interactive || confirm('Sync remote Kubeconfig to your local machine?', true)) {
            $kubeconfigSynced = $this->syncKubeconfig($user, $ip, $port, $keyPath, $contextName);
        }

        // 6. Deploy Traefik — skipped when the sync above failed: `kubectl
        // --context $contextName` would just fail deep inside deployTraefik()
        // with a generic, confusing error, when the real cause is the missing
        // context from step 5. Surfacing that here instead of masking it.
        if (! $kubeconfigSynced) {
            $this->laraKubeWarn("Skipping Traefik deploy — kubectl can't reach '{$contextName}' until the kubeconfig sync above is fixed.");
            $this->line('  👉 Fix the kubeconfig issue above, then re-run this command to pick up from here.');
        } elseif (! $interactive || confirm('Deploy Traefik (Single-Node Hero)?', true)) {
            $this->deployTraefik($contextName);
        }

        return $contextName;
    }
}
