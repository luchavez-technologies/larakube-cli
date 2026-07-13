<?php

use App\Traits\InteractsWithServerHardening;

function hardening(): object
{
    return new class
    {
        use InteractsWithServerHardening;
    };
}

test('the hardening script sets a default-deny firewall and installs fail2ban', function () {
    $script = hardening()->hardenServerScript(22);

    expect($script)
        ->toContain('ufw default deny incoming')
        ->toContain('ufw default allow outgoing')
        ->toContain('apt-get install -y ufw fail2ban')
        ->toContain('systemctl enable fail2ban');
});

test('the SSH port is allowed BEFORE ufw is enabled (no lockout window)', function () {
    $script = hardening()->hardenServerScript(2222);

    $allowPos = strpos($script, 'ufw allow 2222/tcp');
    $enablePos = strpos($script, 'ufw --force enable');

    expect($allowPos)->not->toBeFalse();
    expect($enablePos)->not->toBeFalse();
    expect($allowPos)->toBeLessThan($enablePos);
});

test('the firewall opens HTTP/HTTPS/k3s-API and keeps cluster CIDRs flowing', function () {
    $script = hardening()->hardenServerScript(22);

    expect($script)
        ->toContain('ufw allow 80/tcp')
        ->toContain('ufw allow 443/tcp')
        ->toContain('ufw allow 6443/tcp')
        // Enabling UFW on a running k3s node must not sever intra-cluster traffic.
        ->toContain('ufw allow from 10.42.0.0/16 to any')
        ->toContain('ufw allow from 10.43.0.0/16 to any');
});

test('SSH password auth is disabled by default but can be opted out', function () {
    expect(hardening()->hardenServerScript(22))
        ->toContain('PasswordAuthentication no');

    expect(hardening()->hardenServerScript(22, disablePasswordAuth: false))
        ->not->toContain('PasswordAuthentication no')
        ->toContain('Leaving SSH password auth unchanged');
});

test('the hardening script enables automatic security updates', function () {
    expect(hardening()->hardenServerScript(22))
        ->toContain('unattended-upgrades')
        ->toContain('systemctl enable unattended-upgrades');
});

test('the root-login script closes SSH root login without deleting the account', function () {
    $script = hardening()->disableRootLoginScript();

    expect($script)
        ->toContain('PermitRootLogin no')
        ->toContain('reload ssh')
        // It must NOT remove the root user — root stays for console/sudo/recovery.
        ->not->toContain('userdel')
        ->not->toContain('deluser');
});

test('adminCidr restricts SSH + k3s API to that CIDR, but never 80/443', function () {
    $script = hardening()->hardenServerScript(22, adminCidr: '1.2.3.4/32');

    expect($script)
        ->toContain('ufw allow from 1.2.3.4/32 to any port 22 proto tcp')
        ->toContain('ufw allow from 1.2.3.4/32 to any port 6443 proto tcp')
        ->not->toContain('ufw allow 22/tcp')
        ->not->toContain('ufw allow 6443/tcp')
        ->toContain('ufw allow 80/tcp')
        ->toContain('ufw allow 443/tcp');
});

test('vpnCidr restricts SSH + k3s API the same way adminCidr does, and both can combine', function () {
    $vpnOnly = hardening()->hardenServerScript(22, vpnCidr: '100.64.0.0/10');

    expect($vpnOnly)
        ->toContain('ufw allow from 100.64.0.0/10 to any port 22 proto tcp')
        ->toContain('ufw allow from 100.64.0.0/10 to any port 6443 proto tcp')
        ->not->toContain('ufw allow 22/tcp');

    $both = hardening()->hardenServerScript(22, adminCidr: '1.2.3.4/32', vpnCidr: '100.64.0.0/10');

    expect($both)
        ->toContain('ufw allow from 1.2.3.4/32 to any port 22 proto tcp')
        ->toContain('ufw allow from 100.64.0.0/10 to any port 22 proto tcp')
        ->toContain('ufw allow from 1.2.3.4/32 to any port 6443 proto tcp')
        ->toContain('ufw allow from 100.64.0.0/10 to any port 6443 proto tcp');
});

test('joinNetBirdScript installs NetBird only if missing and joins with the given setup key', function () {
    $script = hardening()->joinNetBirdScript('nb_setup_key_test', 'vpn.example.com');

    expect($script)
        ->toContain('if ! command -v netbird')
        ->toContain('curl -fsSL https://pkgs.netbird.io/install.sh | sh')
        ->toContain("netbird up --setup-key 'nb_setup_key_test' --management-url 'https://vpn.example.com'");
});
