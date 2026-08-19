<?php

use App\Data\ConfigData;
use App\Traits\InstallsK3s;

function k3sInstaller(): object
{
    return new class
    {
        use InstallsK3s;

        public function version(?ConfigData $config = null): string
        {
            return $this->k3sVersion($config);
        }

        public function command(string $version, array $flags = [], array $env = [], bool $sudo = false): string
        {
            return $this->k3sInstallCommand($version, $flags, $env, $sudo);
        }
    };
}

test('k3sVersion falls back to the single ConfigData default', function (): void {
    expect(k3sInstaller()->version())->toBe(ConfigData::DEFAULT_K3S_VERSION);
});

test('k3sVersion honors a project override', function (): void {
    $config = ConfigData::from(['name' => 'demo', 'k3sVersion' => 'v1.31.0+k3s1']);

    expect(k3sInstaller()->version($config))->toBe('v1.31.0+k3s1');
});

test('k3sInstallCommand builds the local form: env var, no install flags', function (): void {
    $cmd = k3sInstaller()->command('v1.30.4+k3s1', env: ['K3S_KUBECONFIG_MODE' => '644']);

    expect($cmd)->toBe(
        'curl -sfL https://get.k3s.io | '
        .'INSTALL_K3S_VERSION='.escapeshellarg('v1.30.4+k3s1').' '
        .'K3S_KUBECONFIG_MODE='.escapeshellarg('644').' sh -',
    );
});

test('k3sInstallCommand builds the remote form: install flags via sh -s -', function (): void {
    $cmd = k3sInstaller()->command('v1.30.4+k3s1', [
        '--disable=traefik',
        '--write-kubeconfig-mode 644',
        '--kubelet-arg=fail-swap-on=false',
    ]);

    expect($cmd)
        ->toContain('curl -sfL https://get.k3s.io | INSTALL_K3S_VERSION='.escapeshellarg('v1.30.4+k3s1').' sh -s -')
        ->toContain('--disable=traefik')
        ->toContain('--write-kubeconfig-mode 644')
        ->toContain('--kubelet-arg=fail-swap-on=false');
});

test('k3sInstallCommand routes env assignments through `env` when sudo, so sudo\'s env_reset does not strip them', function (): void {
    $cmd = k3sInstaller()->command(
        'v1.30.4+k3s1',
        ['--disable=traefik', '--write-kubeconfig-mode=644'],
        ['K3S_KUBECONFIG_MODE' => '644'],
        sudo: true,
    );

    // The env assignments must come AFTER `sudo env`, not before `sudo` —
    // otherwise they'd only set sudo's own environment and never reach `sh`.
    expect($cmd)->toBe(
        'curl -sfL https://get.k3s.io | sudo env '
        .'INSTALL_K3S_VERSION='.escapeshellarg('v1.30.4+k3s1').' '
        .'K3S_KUBECONFIG_MODE='.escapeshellarg('644').' '
        .'sh -s - --disable=traefik --write-kubeconfig-mode=644',
    );
});
