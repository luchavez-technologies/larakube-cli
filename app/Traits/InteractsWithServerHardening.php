<?php

namespace App\Traits;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\text;

/**
 * Basic, idempotent hardening for a freshly provisioned k3s node. Pure builders
 * (return the remote bash) so they're unit-testable and reusable by both
 * `cloud:init` and `cloud:harden`. The orchestration SSHes the script via the
 * same runner as the rest of provisioning.
 *
 * Two safety rules baked into the script:
 *   1. The SSH port is allowed BEFORE `ufw --force enable` (no lockout window).
 *   2. The k3s pod & service CIDRs are allowed, so enabling a host firewall on a
 *      running cluster doesn't sever intra-cluster networking (CoreDNS → API, etc).
 */
trait InteractsWithServerHardening
{
    use ReadsCommandOptions;

    /**
     * @param  int  $sshPort  the live SSH port — allowed first so we never lock out
     * @param  array<int,int>  $allowPorts  extra inbound TCP ports (default: HTTP/HTTPS/k3s-API)
     * @param  bool  $disablePasswordAuth  drop SSH password auth (safe: we connect by key)
     * @param  string|null  $adminCidr  restrict SSH + 6443 (k3s API) to this CIDR; null = open to
     *                                  everyone (matches the cloud firewall's own default). 80/443
     *                                  always stay open regardless — that's public web traffic.
     * @param  string|null  $vpnCidr  additionally allow SSH + 6443 from this CIDR — the NetBird
     *                                overlay range once `cloud:harden` has joined the host to the
     *                                project's VPN (see joinNetBirdScript() below). Combinable
     *                                with $adminCidr: either grants access.
     */
    public function hardenServerScript(
        int $sshPort,
        array $allowPorts = [80, 443, 6443],
        bool $disablePasswordAuth = true,
        string $podCidr = '10.42.0.0/16',
        string $serviceCidr = '10.43.0.0/16',
        ?string $adminCidr = null,
        ?string $vpnCidr = null,
    ): string {
        // SSH first — then the rest — so `ufw --force enable` can never strand us.
        // 6443 (k3s API) is admin-restrictable alongside SSH when given; 80/443
        // are never restricted here — they're the public web ports.
        $restrictedPorts = array_values(array_intersect($allowPorts, [6443]));
        $publicPorts = array_values(array_diff($allowPorts, [6443]));
        $trustedCidrs = array_filter([$adminCidr, $vpnCidr], fn (?string $cidr) => $cidr !== null);

        if ($trustedCidrs !== []) {
            $allows = '';
            foreach ($trustedCidrs as $cidr) {
                $allows .= "ufw allow from {$cidr} to any port {$sshPort} proto tcp\n";
                foreach ($restrictedPorts as $port) {
                    $allows .= "ufw allow from {$cidr} to any port {$port} proto tcp\n";
                }
            }
        } else {
            $allows = 'ufw allow '.$sshPort."/tcp\n";
            foreach ($restrictedPorts as $port) {
                $allows .= 'ufw allow '.((int) $port)."/tcp\n";
            }
        }
        foreach ($publicPorts as $port) {
            $allows .= 'ufw allow '.((int) $port)."/tcp\n";
        }
        // Keep k3s pod/service traffic flowing through the host firewall.
        $allows .= 'ufw allow from '.$podCidr." to any\n";
        $allows .= 'ufw allow from '.$serviceCidr.' to any';

        $ssh = $disablePasswordAuth
            ? <<<'BASH'
echo "Disabling SSH password auth (key-only)..."
sed -i 's/^#\?PasswordAuthentication .*/PasswordAuthentication no/' /etc/ssh/sshd_config
grep -qxF 'PasswordAuthentication no' /etc/ssh/sshd_config || echo 'PasswordAuthentication no' >> /etc/ssh/sshd_config
systemctl reload ssh 2>/dev/null || systemctl reload sshd 2>/dev/null || true
BASH
            : 'echo "Leaving SSH password auth unchanged."';

        // Always applied, regardless of password auth: cap brute-force attempts
        // per connection, and drop hung/idle sessions instead of leaving them
        // open indefinitely. Standard CIS-benchmark-level SSH hardening.
        $sshHardening = <<<'BASH'
echo "Hardening sshd_config (MaxAuthTries, idle timeout)..."
sed -i 's/^#\?MaxAuthTries .*/MaxAuthTries 3/' /etc/ssh/sshd_config
grep -qxF 'MaxAuthTries 3' /etc/ssh/sshd_config || echo 'MaxAuthTries 3' >> /etc/ssh/sshd_config
sed -i 's/^#\?ClientAliveInterval .*/ClientAliveInterval 300/' /etc/ssh/sshd_config
grep -qxF 'ClientAliveInterval 300' /etc/ssh/sshd_config || echo 'ClientAliveInterval 300' >> /etc/ssh/sshd_config
sed -i 's/^#\?ClientAliveCountMax .*/ClientAliveCountMax 2/' /etc/ssh/sshd_config
grep -qxF 'ClientAliveCountMax 2' /etc/ssh/sshd_config || echo 'ClientAliveCountMax 2' >> /etc/ssh/sshd_config
systemctl reload ssh 2>/dev/null || systemctl reload sshd 2>/dev/null || true
BASH;

        return <<<BASH
set -e
export DEBIAN_FRONTEND=noninteractive

echo "Waiting for cloud-init's own boot-time apt activity to finish..."
command -v cloud-init >/dev/null 2>&1 && cloud-init status --wait || true

echo "Updating system packages..."
apt-get update -y
apt-get upgrade -y

echo "Installing firewall, fail2ban, and unattended-upgrades..."
apt-get install -y ufw fail2ban unattended-upgrades

echo "Configuring UFW (default deny incoming)..."
ufw default deny incoming
ufw default allow outgoing
{$allows}
ufw --force enable

echo "Enabling fail2ban (SSH brute-force protection)..."
systemctl enable fail2ban
systemctl restart fail2ban

echo "Enabling automatic security updates..."
dpkg-reconfigure -f noninteractive unattended-upgrades || true
{
  echo 'Unattended-Upgrade::Automatic-Reboot "true";'
  echo 'Unattended-Upgrade::Automatic-Reboot-Time "03:00";'
} > /etc/apt/apt.conf.d/52larakube-auto-reboot
systemctl enable unattended-upgrades 2>/dev/null || true
systemctl restart unattended-upgrades 2>/dev/null || true

{$sshHardening}

{$ssh}

echo "Hardening complete."
BASH;
    }

    /**
     * Install the NetBird client and join it to the project's VPN, making the
     * VPS HOST itself a peer — unlike the in-cluster `netbird-client` pod
     * `vpn:init` deploys (which only gets a pod-network IP and can never be
     * what a host firewall keys off of), this gets a real overlay IP that
     * `hardenServerScript()`'s $vpnCidr can restrict SSH/6443 to. Pure builder,
     * same shape as hardenServerScript()/disableRootLoginScript(); orchestrated
     * by `cloud:harden` via runRemoteCommand().
     */
    public function joinNetBirdScript(string $setupKey, string $managementHost): string
    {
        $setupKeyEscaped = escapeshellarg($setupKey);
        $managementUrl = escapeshellarg("https://{$managementHost}");

        return <<<BASH
set -e
if ! command -v netbird >/dev/null 2>&1; then
  echo "Installing NetBird client..."
  curl -fsSL https://pkgs.netbird.io/install.sh | sh
fi
echo "Joining NetBird VPN..."
netbird up --setup-key {$setupKeyEscaped} --management-url {$managementUrl}
BASH;
    }

    /**
     * Disable remote root SSH login. The root ACCOUNT stays (system processes,
     * sudo, and the provider's recovery console still need it) — only its network
     * login over SSH is closed, removing that attack surface. Pure.
     *
     * Apply this ONLY after confirming a non-root sudo user can log in and sudo,
     * so you never cut the last remote admin path. The orchestration does that
     * check (testSsh + canSudo as `larakube`) before running this.
     */
    public function disableRootLoginScript(): string
    {
        return <<<'BASH'
echo "Disabling remote root SSH login..."
sed -i 's/^#\?PermitRootLogin .*/PermitRootLogin no/' /etc/ssh/sshd_config
grep -qxF 'PermitRootLogin no' /etc/ssh/sshd_config || echo 'PermitRootLogin no' >> /etc/ssh/sshd_config
systemctl reload ssh 2>/dev/null || systemctl reload sshd 2>/dev/null || true
echo "Remote root login disabled (root still available via console/sudo)."
BASH;
    }

    /**
     * The admin CIDR to restrict SSH + the k3s API to: a CIDR string, null for
     * open (matches the confirm's "no" default — this stays opt-in, never
     * forced, since a fresh box's admin often doesn't have a stable IP/VPN
     * ready yet), or false when an invalid --admin-cidr was passed and the
     * caller should abort. Shared by `cloud:create` (bakes it into the cloud
     * provider's firewall at provision time) and `cloud:harden` (applies/
     * updates it on the OS firewall any time after — e.g. once a VPN is
     * finally set up), so the same prompt/validation can't drift between them.
     */
    protected function promptAdminCidr(bool $viaCreate = false): string|false|null
    {
        if ($flag = $this->flag('admin-cidr')) {
            [$ip] = explode('/', $flag, 2);
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $this->laraKubeError("Invalid --admin-cidr '{$flag}' — expected an IPv4 address or CIDR.");

                return false;
            }

            return str_contains($flag, '/') ? $flag : $flag.'/32';
        }

        // No flag headlessly = open, same as declining the confirm below.
        if ($this->flag('no-interaction')) {
            return null;
        }

        // Only relevant from cloud:create: that's the one caller where this
        // CIDR also gets baked into the cloud provider's firewall (Terraform),
        // not just UFW — reversing it later means a Tofu re-apply, not just
        // SSHing in and editing UFW like a later cloud:harden run.
        $hint = $viaCreate
            ? 'Also bakes into the cloud firewall, not just UFW — if your IP isn\'t stable, consider declining and restricting later via cloud:harden once your NetBird VPN is set up.'
            : '';

        if (! confirm('Restrict SSH + the k3s API (6443) to a single admin IP? (recommended)', false, hint: $hint)) {
            return null;
        }
        $ip = text(label: 'Admin IPv4 (your current public IP)', required: true, hint: 'A /32 is appended automatically.');

        return rtrim($ip).'/32';
    }
}
