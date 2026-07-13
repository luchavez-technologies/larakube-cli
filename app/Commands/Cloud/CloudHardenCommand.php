<?php

namespace App\Commands\Cloud;

use App\Traits\InteractsWithProjectConfig;
use App\Traits\InteractsWithRemoteSsh;
use App\Traits\InteractsWithServerHardening;
use App\Traits\InteractsWithVpn;
use App\Traits\LaraKubeOutput;

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

        $this->laraKubeInfo("Testing SSH connection to {$user}@{$ip}...");
        if (! $this->testSsh($user, $ip, $port, $keyPath)) {
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
            $vpnCidr = $this->joinVpn($user, $ip, $port, $keyPath, $vpnKubectl, $vpnNamespace, $environment);
        }

        $this->laraKubeInfo('Hardening server...');
        if (! $this->runRemoteCommand($user, $ip, $port, $keyPath, $this->hardenServerScript((int) $port, adminCidr: $adminCidr, vpnCidr: $vpnCidr))) {
            $this->laraKubeError('Hardening failed partway through — see the remote output above. The firewall may NOT be enabled.');
            $this->line('  👉 Re-run once the box is stable: larakube cloud:harden'.($adminCidr ? " --admin-cidr={$adminCidr}" : ''));

            return 1;
        }

        $this->rebootIfRequired($user, $ip, $port, $keyPath);

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
                if ($this->runRemoteCommand($user, $ip, $port, $keyPath, $this->disableRootLoginScript())) {
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
     */
    protected function joinVpn($user, $ip, $port, $keyPath, string $vpnKubectl, string $vpnNamespace, string $environment): ?string
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
        if (! $this->runRemoteCommand($user, $ip, $port, $keyPath, $this->joinNetBirdScript($setupKey, $host))) {
            $this->laraKubeWarn('NetBird join failed — see the remote output above. Skipping the VPN allow-rule.');

            return null;
        }

        return self::VPN_CIDR;
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
