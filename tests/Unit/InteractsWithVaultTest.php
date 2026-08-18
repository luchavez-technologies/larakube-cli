<?php

use App\Data\ConfigData;
use App\Data\EnvironmentData;
use App\Traits\InteractsWithVault;
use Illuminate\Support\Facades\Process;

function vaultReader(): object
{
    return new class
    {
        use InteractsWithVault;

        public function host(string $env, ?ConfigData $config): ?string
        {
            return $this->resolveVaultHostReadOnly($env, $config);
        }

        public function kubectlFor(?string $context): string
        {
            return $this->vaultKubectl($context);
        }

        public function installed(string $kubectl, string $ns): bool
        {
            return $this->isVaultInstalled($kubectl, $ns);
        }

        public function vaultToken(string $kubectl, string $ns): ?string
        {
            return $this->readVaultAdminToken($kubectl, $ns);
        }

        public function access(string $env, ?ConfigData $config, ?string $context = null): ?array
        {
            return $this->vaultAccess($env, $config, $context);
        }
    };
}

test('local Vault host uses the vault subdomain on the dev TLD', function () {
    expect(vaultReader()->host('local', null))->toStartWith('vault.');
});

test('cloud Vault host returns the host persisted for that env', function () {
    $config = ConfigData::from(['name' => 'demo']);
    $config->environments['production'] = EnvironmentData::from(['hosts' => ['vault' => 'vault.example.com']]);

    expect(vaultReader()->host('production', $config))->toBe('vault.example.com');
});

test('cloud Vault host is null when none is configured for the env', function () {
    $config = ConfigData::from(['name' => 'demo']);
    $config->environments['production'] = EnvironmentData::from([]);

    expect(vaultReader()->host('production', $config))->toBeNull();
});

test('vaultKubectl scopes to a context only when one is given', function () {
    $reader = vaultReader();
    $kubectl = 'KUBECONFIG='.escapeshellarg(home_path('.kube/config')).' kubectl';

    expect($reader->kubectlFor('do-sfo3'))->toBe("{$kubectl} --context=do-sfo3")
        ->and($reader->kubectlFor(''))->toBe($kubectl)
        ->and($reader->kubectlFor(null))->toBe($kubectl);
});

test('isVaultInstalled reflects whether the vaultwarden Deployment exists', function () {
    Process::fake(['kubectl get deployment vaultwarden -n larakube-vault --no-headers' => 'vaultwarden   1/1   1   1   5d']);
    expect(vaultReader()->installed('kubectl', 'larakube-vault'))->toBeTrue();

    Process::fake(['kubectl get deployment vaultwarden -n larakube-vault --no-headers' => Process::result(output: '', exitCode: 1)]);
    expect(vaultReader()->installed('kubectl', 'larakube-vault'))->toBeFalse();
});

test('readVaultAdminToken decodes the admin secret, null when absent', function () {
    Process::fake([
        "kubectl get secret vault-secrets -n larakube-vault -o jsonpath='{.data.admin-token}'" => base64_encode('s3cr3t-adm1n'),
    ]);
    expect(vaultReader()->vaultToken('kubectl', 'larakube-vault'))->toBe('s3cr3t-adm1n');

    Process::fake([
        "kubectl get secret vault-secrets -n larakube-vault -o jsonpath='{.data.admin-token}'" => Process::result(output: '', exitCode: 1),
    ]);
    expect(vaultReader()->vaultToken('kubectl', 'larakube-vault'))->toBeNull();
});

test('vaultAccess is null when vault is not installed, populated when it is', function () {
    $kubectl = 'KUBECONFIG='.escapeshellarg(home_path('.kube/config')).' kubectl';

    Process::fake(["{$kubectl} get deployment vaultwarden -n larakube-vault --no-headers" => Process::result(output: '', exitCode: 1)]);
    expect(vaultReader()->access('local', null))->toBeNull();

    Process::fake([
        "{$kubectl} get deployment vaultwarden -n larakube-vault --no-headers" => 'vaultwarden   1/1   1   1   5d',
        "{$kubectl} get secret vault-secrets -n larakube-vault -o jsonpath='{.data.admin-token}'" => base64_encode('s3cr3t-adm1n'),
    ]);
    $access = vaultReader()->access('local', null);

    expect($access['host'])->toStartWith('vault.')
        ->and($access['token'])->toBe('s3cr3t-adm1n');
});
