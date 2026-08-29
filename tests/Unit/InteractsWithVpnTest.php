<?php

use App\Data\ConfigData;
use App\Data\EnvironmentData;
use App\Traits\InteractsWithVpn;
use Illuminate\Support\Facades\Process;

function vpnReader(): object
{
    return new class
    {
        use InteractsWithVpn;

        public function host(string $env, ?ConfigData $config): ?string
        {
            return $this->resolveVpnHostReadOnly($env, $config);
        }

        public function kubectlFor(?string $context): string
        {
            return $this->vpnKubectl($context);
        }

        public function installed(string $kubectl, string $ns): bool
        {
            return $this->isVpnInstalled($kubectl, $ns);
        }

        public function access(string $env, ?ConfigData $config, ?string $context = null): ?array
        {
            return $this->vpnAccess($env, $config, $context);
        }

        public function setupKey(string $kubectl, string $ns): ?string
        {
            return $this->fetchVpnSetupKey($kubectl, $ns);
        }
    };
}

test('local VPN host uses the vpn subdomain on the dev TLD', function (): void {
    expect(vpnReader()->host('local', null))->toStartWith('vpn.');
});

test('cloud VPN host returns the host persisted for that env', function (): void {
    $config = ConfigData::from(['name' => 'demo']);
    $config->environments['production'] = EnvironmentData::from(['hosts' => ['vpn' => 'vpn.example.com']]);

    expect(vpnReader()->host('production', $config))->toBe('vpn.example.com');
});

test('cloud VPN host is null when none is configured for the env', function (): void {
    $config = ConfigData::from(['name' => 'demo']);
    $config->environments['production'] = EnvironmentData::from([]);

    expect(vpnReader()->host('production', $config))->toBeNull();
});

test('vpnKubectl scopes to a context only when one is given', function (): void {
    $reader = vpnReader();
    $kubectl = 'KUBECONFIG='.escapeshellarg(home_path('.kube/config')).' kubectl';

    expect($reader->kubectlFor('do-sfo3'))->toBe("{$kubectl} --context=do-sfo3")
        ->and($reader->kubectlFor(''))->toBe($kubectl)
        ->and($reader->kubectlFor(null))->toBe($kubectl);
});

test('isVpnInstalled reflects whether the vpn-management Deployment exists', function (): void {
    Process::fake(['kubectl get deployment vpn-management -n larakube-vpn --no-headers' => 'vpn-management   1/1   1   1   5d']);
    expect(vpnReader()->installed('kubectl', 'larakube-vpn'))->toBeTrue();

    Process::fake(['kubectl get deployment vpn-management -n larakube-vpn --no-headers' => Process::result(output: '', exitCode: 1)]);
    expect(vpnReader()->installed('kubectl', 'larakube-vpn'))->toBeFalse();
});

test('vpnAccess is null when vpn is not installed, populated when it is', function (): void {
    $kubectl = 'KUBECONFIG='.escapeshellarg(home_path('.kube/config')).' kubectl';

    Process::fake(["{$kubectl} get deployment vpn-management -n larakube-vpn --no-headers" => Process::result(output: '', exitCode: 1)]);
    expect(vpnReader()->access('local', null))->toBeNull();

    Process::fake([
        "{$kubectl} get deployment vpn-management -n larakube-vpn --no-headers" => 'vpn-management   1/1   1   1   5d',
    ]);
    $access = vpnReader()->access('local', null);

    expect($access['host'])->toStartWith('vpn.')
        ->and($access['label'])->toBe('NetBird VPN');
});

test('fetchVpnSetupKey decodes the key from the Secret, null when missing', function (): void {
    $encoded = base64_encode('nb_setup_key_test');
    Process::fake([
        "kubectl get secret vpn-management-secrets -n larakube-vpn -o jsonpath='{.data.setup-key}'" => $encoded,
    ]);
    expect(vpnReader()->setupKey('kubectl', 'larakube-vpn'))->toBe('nb_setup_key_test');

    Process::fake([
        "kubectl get secret vpn-management-secrets -n larakube-vpn -o jsonpath='{.data.setup-key}'" => Process::result(output: '', exitCode: 1),
    ]);
    expect(vpnReader()->setupKey('kubectl', 'larakube-vpn'))->toBeNull();
});
