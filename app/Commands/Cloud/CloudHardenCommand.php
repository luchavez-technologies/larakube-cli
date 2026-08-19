<?php

namespace App\Commands\Cloud;

use App\Data\CloudData;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\InteractsWithRemoteSsh;
use App\Traits\InteractsWithServerHardening;
use App\Traits\InteractsWithVpn;
use App\Traits\LaraKubeOutput;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;

class CloudHardenCommand extends Command
{
    use InteractsWithProjectConfig, InteractsWithRemoteSsh, InteractsWithServerHardening, InteractsWithVpn, LaraKubeOutput;

    /** NetBird's overlay network range — stable regardless of a peer's public IP. */
    protected const VPN_CIDR = '100.64.0.0/10';

    protected $signature = 'cloud:harden
        {environment? : The project environment whose server to harden}
        {--admin-cidr= : Restrict SSH + the k3s API (6443) to this CIDR; omit to leave/keep open}';

    protected $description = 'Harden an already-provisioned server (UFW firewall, fail2ban, key-only SSH) — safe to re-run any time';

    public function handle(): int
    {
        $this->renderHeader();
        $this->laraKubeInfo('LaraKube Server Hardening');
        $this->line('  <fg=gray>(Re)applies firewall + SSH hardening to a server that is already provisioned. Safe to re-run</>');
        $this->line('  <fg=gray>any time — e.g. after a CLI update adds new hardening steps, or once you have a stable IP/VPN</>');
        $this->line('  <fg=gray>ready to restrict access to (--admin-cidr=) instead of leaving it open at provision time.</>');
        $this->newLine();

        $environment = (string) ($this->argument('environment') ?: '');

        // Pull the server from a project env when we can; otherwise ask.
        [$user, $ip, $port, $keyPath] = $this->resolveConnection();

        $keyPath = str_replace('~', home_path(), $keyPath);
        if (! file_exists($keyPath)) {
            $this->laraKubeError("SSH key not found at: {$keyPath}");

            return 1;
        }

        // $ip stays the box's stable, PUBLIC identity throughout this command
        // (the kube-context is deterministically "larakube-{$ip}" — it must
        // never drift). $sshIp is what actually gets dialed: once a prior
        // cloud:harden run joined this box to the VPN, its public IP is
        // VPN-restricted and unreachable for SSH regardless of who's asking,
        // so every real SSH call below prefers its recorded overlay IP.
        $sshIp = $this->preferredSshIp($environment, $ip);
        if ($sshIp !== $ip) {
            $this->line("  <fg=gray>Connecting via its VPN overlay IP:</> <fg=cyan>{$sshIp}</>");
        }

        $this->laraKubeInfo("Testing SSH connection to {$user}@{$sshIp}...");
        if (! $this->testSsh($user, $sshIp, $port, $keyPath)) {
            $this->laraKubeError('Could not connect via SSH. Check the IP, user, port and key.');

            return 1;
        }
        $this->laraKubeInfo('Connection successful!');
        $this->newLine();

        // Opt-in, never forced — a fresh box's admin often doesn't have a
        // stable IP/VPN ready yet; this is exactly the "come back and tighten
        // it once you do" path. Shared prompt/validation with cloud:create.
        $adminCidr = $this->promptAdminCidr();
        if ($adminCidr === false) {
            return 1;
        }

        // Context is deterministically "larakube-{ip}" (ProvisionsK3sNode names
        // every VPS that way), so no extra prompt is needed to find it.
        $vpnContext = "larakube-{$ip}";
        $vpnKubectl = $this->vpnKubectl($vpnContext);
        $vpnNamespace = $this->vpnNamespace();
        // The VPN host is looked up by environment name — can only offer the
        // join when one was given.
        $offerVpnJoin = $environment !== '' && $this->isVpnInstalled($vpnKubectl, $vpnNamespace);
        $joinVpn = false;

        if ($offerVpnJoin) {
            $joinVpn = confirm(
                "Join this server to your project's NetBird VPN? (lets you restrict SSH to your VPN instead of a single static IP)",
                false,
            );
        }

        $this->line("  This will apply on <fg=cyan>{$ip}</>:");
        $this->line('   • System packages — full upgrade, rebooting automatically if the kernel needs it');
        $this->line('   • UFW — default-deny inbound; allow SSH('.$port.')'
            .($adminCidr ? " + k3s API restricted to {$adminCidr}" : ', k3s API (6443)')
            .', 80, 443 open, + k3s pod/service CIDRs');
        if ($joinVpn) {
            $this->line('   • NetBird — join the VPN, then also allow SSH + k3s API from its overlay range ('.self::VPN_CIDR.')');
        }
        $this->line('   • fail2ban — SSH brute-force protection');
        $this->line('   • SSH — disable password auth (key-only), MaxAuthTries, idle timeout');
        $this->newLine();

        if (! confirm('Proceed with hardening?', true)) {
            $this->laraKubeInfo('Cancelled.');

            return 0;
        }

        $vpnCidr = null;
        if ($joinVpn) {
            $vpnCidr = $this->joinVpn($user, $ip, $sshIp, $port, $keyPath, $vpnKubectl, $vpnNamespace, $environment);
        }

        $this->laraKubeInfo('Hardening server...');
        if (! $this->runRemoteCommand($user, $sshIp, $port, $keyPath, $this->hardenServerScript((int) $port, adminCidr: $adminCidr, vpnCidr: $vpnCidr))) {
            $this->laraKubeError('Hardening failed partway through — see the remote output above. The firewall may NOT be enabled.');
            $this->line('  👉 Re-run once the box is stable: larakube cloud:harden'.($adminCidr ? " --admin-cidr={$adminCidr}" : ''));

            return 1;
        }

        $this->rebootIfRequired($user, $sshIp, $port, $keyPath);

        $accessNote = collect([
            $adminCidr ? "restricted to {$adminCidr}" : null,
            $vpnCidr ? "restricted to NetBird ({$vpnCidr})" : null,
        ])->filter()->implode(' + ');

        $this->laraKubeInfo('✅ Hardened: system packages updated, UFW (SSH/80/443/6443'.($accessNote !== '' ? " {$accessNote}" : '').' + pod & service CIDRs), fail2ban, auto-updates, key-only SSH.');
        if (! $adminCidr && ! $vpnCidr) {
            $this->info('   Note: k3s API (6443) is open to the internet — re-run with --admin-cidr= or join your NetBird VPN once you have one.');
        }

        // Closing remote root login is only safe when we have a proven non-root
        // sudo login. Connecting as a non-root user that just ran sudo IS that proof.
        if ($user !== 'root') {
            $this->newLine();
            if (confirm('Also disable remote root SSH login? (you are connected as a working sudo user)', false)) {
                if ($this->runRemoteCommand($user, $sshIp, $port, $keyPath, $this->disableRootLoginScript())) {
                    $this->laraKubeInfo('✅ Remote root login disabled.');
                } else {
                    $this->laraKubeError('Disabling root login failed — see the remote output above. Root login is still ENABLED.');
                }
            }
        } else {
            $this->newLine();
            $this->info('   Tip: run "cloud:harden" as the "larakube" user to also disable remote root login safely.');
        }

        return 0;
    }

    /**
     * Install NetBird on the VPS host and join it to the project's VPN,
     * fetching the setup key `vpn:init` already bootstrapped. Returns the
     * NetBird overlay CIDR to allowlist on success, null on any failure (the
     * caller falls back to whatever $adminCidr already covers — a failed VPN
     * join never blocks hardening, it only means the VPN allow-rule is skipped).
     * $ip is the box's stable PUBLIC identity (the kube-context is
     * deterministically "larakube-{$ip}"); $sshIp is what's actually dialed —
     * they differ once a prior run already repointed this env at the overlay IP.
     */
    protected function joinVpn($user, $ip, $sshIp, $port, $keyPath, string $vpnKubectl, string $vpnNamespace, string $environment): ?string
    {
        $config = $this->isLaraKubeProject(false) ? $this->getProjectConfigObject(getcwd()) : null;
        $host = $this->resolveVpnHostReadOnly($environment, $config);
        $setupKey = $this->fetchVpnSetupKey($vpnKubectl, $vpnNamespace);

        if (! $host || ! $setupKey) {
            $this->laraKubeWarn('Could not fetch NetBird connection details — skipping the VPN join.');

            return null;
        }
        $this->registerSecret($setupKey);

        $this->laraKubeInfo('Joining NetBird VPN...');
        if (! $this->runRemoteCommand($user, $sshIp, $port, $keyPath, $this->joinNetBirdScript($setupKey, $host))) {
            $this->laraKubeWarn('NetBird join failed — see the remote output above. Skipping the VPN allow-rule.');

            return null;
        }

        // This join is what leads hardenServerScript() (below, via the returned
        // VPN_CIDR) to restrict 6443 to the VPN overlay — but the kube-context's
        // server URL (and everything minted from it: teammate grants, CI
        // secrets) still points at the PUBLIC ip:6443. Traffic to a peer's
        // public IP never routes through the NetBird tunnel — only traffic to
        // its OWN overlay IP does — so that public-IP server URL is about to
        // become permanently unreachable, even from a machine that's on the
        // VPN. Repoint it at the VPS's own overlay IP now, before that happens.
        // Already-joined (a re-run): the peer's IP never changes, so this is a
        // harmless no-op re-detect + re-apply.
        $hostname = trim(Process::run("ssh -o BatchMode=yes -o StrictHostKeyChecking=no -i {$keyPath} -p {$port} {$user}@{$sshIp} hostname")->output());
        $overlayIp = $hostname !== '' ? $this->pollVpnPeerIp($vpnKubectl, $vpnNamespace, $host, $hostname) : null;

        if ($overlayIp) {
            $this->rewriteClusterServer("larakube-{$ip}", $overlayIp, 6443);
            $this->persistVpnIp($environment, $overlayIp);
            $this->laraKubeInfo("Repointed kube-context 'larakube-{$ip}' to the VPN overlay IP ({$overlayIp}) — the public IP is about to stop accepting 6443 connections.");
        } else {
            $this->laraKubeWarn("Could not determine the VPS's own NetBird overlay IP — its kube-context still points at the public IP, which will stop accepting connections once 6443 is VPN-restricted below.");
            $this->line("  Fix it once you find the IP (`larakube vpn:users {$environment}`): <fg=yellow>kubectl config set-cluster larakube-{$ip} --server=https://<overlay-ip>:6443</>");
        }

        return self::VPN_CIDR;
    }

    /**
     * Record the VPS's own overlay IP on its environment's cloud config —
     * $ip itself stays untouched (the kube-context name is derived from it,
     * and it survives as the identity for a box that ISN'T VPN-restricted).
     * SSH-consuming code (resolveConnection() below) prefers $vpnIp once set.
     * A no-op outside a project or for an environment with no recorded cloud
     * target — nothing to update.
     */
    protected function persistVpnIp(string $environment, string $overlayIp): void
    {
        if (! $this->isLaraKubeProject(false)) {
            return;
        }

        $projectPath = getcwd();
        $config = $this->getProjectConfigObject($projectPath);
        $cloud = $config->getCloud($environment);
        if ($cloud === null) {
            return;
        }

        $config->setCloud($environment, new CloudData(
            ip: $cloud->ip,
            user: $cloud->user,
            port: $cloud->port,
            key: $cloud->key,
            context: $cloud->context,
            provider: $cloud->provider,
            arch: $cloud->arch,
            rbacGrantedAt: $cloud->rbacGrantedAt,
            vpnIp: $overlayIp,
        ));
        $config->saveToFile($projectPath);
    }

    /**
     * Poll the NetBird peer list for the just-joined host's own overlay IP,
     * matched by hostname (NetBird registers peers under the system hostname
     * by default). `netbird up` can return before the peer is fully
     * registered/visible via the API, so this retries briefly rather than
     * checking once.
     */
    protected function pollVpnPeerIp(string $vpnKubectl, string $vpnNamespace, string $vpnHost, string $hostname): ?string
    {
        $pat = $this->fetchVpnPat($vpnKubectl, $vpnNamespace);
        if ($pat === null) {
            return null;
        }

        for ($i = 0; $i < 10; $i++) {
            $peers = $this->listVpnPeers($vpnHost, $pat) ?? [];
            foreach ($peers as $peer) {
                if (($peer['hostname'] ?? '') === $hostname) {
                    return $peer['ip'] ?? null;
                }
            }
            $this->pollDelay();
        }

        return null;
    }

    /** Overridable so tests don't burn 20s waiting out the real retry loop. */
    protected function pollDelay(): void
    {
        Sleep::sleep(2);
    }

    /** Update a kube-context's cluster server URL — the official kubectl-native way to edit it. */
    protected function rewriteClusterServer(string $clusterName, string $newIp, int $port): bool
    {
        $kubectl = 'KUBECONFIG='.escapeshellarg(home_path('.kube/config')).' kubectl';

        return Process::run("{$kubectl} config set-cluster ".escapeshellarg($clusterName).' --server='.escapeshellarg("https://{$newIp}:{$port}"))->successful();
    }

    /**
     * The IP to actually dial for SSH — $cloud->vpnIp when a prior
     * cloud:harden run recorded one for this environment (its public IP is
     * then VPN-restricted and unreachable regardless of who's asking), else
     * $ip unchanged. Standalone (no environment/project) always uses $ip —
     * nowhere to have recorded a vpnIp in the first place.
     */
    protected function preferredSshIp(string $environment, string $ip): string
    {
        if ($environment === '' || ! $this->isLaraKubeProject(false)) {
            return $ip;
        }

        $cloud = $this->getProjectConfigObject(getcwd())->getCloud($environment);

        return $cloud?->vpnIp ?: $ip;
    }

    /**
     * SSH connection details — from a project env's cloud config when present,
     * else prompted (so the command also works standalone, like cloud:init).
     *
     * @return array{0:string,1:string,2:int,3:string}
     */
    protected function resolveConnection(): array
    {
        $environment = $this->argument('environment');

        if ($environment && $this->isLaraKubeProject(false)) {
            $config = $this->getProjectConfigObject(getcwd());
            $cloud = $config->getCloud($environment);

            if ($cloud && $cloud->ip) {
                $this->line("  <fg=gray>Using</> <fg=cyan>{$environment}</> <fg=gray>server from your blueprint:</> <fg=cyan>{$cloud->ip}</>");

                return [
                    $cloud->user ?? 'root',
                    $cloud->ip,
                    (int) ($cloud->port ?? 22),
                    $cloud->key ?? home_path('.ssh/id_rsa'),
                ];
            }

            $this->laraKubeWarn("No server recorded for '{$environment}' — enter the details manually.");
        }

        return $this->promptConnection();
    }

    /**
     * @return array{0:string,1:string,2:int,3:string}
     */
    protected function promptConnection(): array
    {
        $ip = text(label: 'Server IP address', required: true, placeholder: 'e.g. 123.45.67.89');
        $user = text(label: 'SSH User (must have sudo access)', default: 'root');
        $port = text(label: 'SSH Port', default: '22');
        $keyPath = text(label: 'Path to your SSH Private Key', default: home_path('.ssh/id_rsa'));

        return [$user, $ip, (int) $port, $keyPath];
    }
}
