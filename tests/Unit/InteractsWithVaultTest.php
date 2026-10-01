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

        public function installed(string $kubectl, string $ns): bool
        {
            return $this->isVaultInstalled($kubectl, $ns);
        }

        public function vaultToken(string $kubectl, string $ns, ?string $instance = null): ?string
        {
            return $this->readVaultAdminToken($kubectl, $ns, $instance);
        }

        public function access(string $env, ?ConfigData $config, ?string $context = null): ?array
        {
            return $this->vaultAccess($env, $config, $context);
        }
    };
}

test('local Vault host uses the vault subdomain on the dev TLD', function (): void {
    expect(vaultReader()->host('local', null))->toStartWith('vault.');
});

test('cloud Vault host returns the host persisted for that env', function (): void {
    $config = ConfigData::from(['name' => 'demo']);
    $config->environments['production'] = EnvironmentData::from(['hosts' => ['vault' => 'vault.example.com']]);

    expect(vaultReader()->host('production', $config))->toBe('vault.example.com');
});

test('cloud Vault host is null when none is configured for the env', function (): void {
    $config = ConfigData::from(['name' => 'demo']);
    $config->environments['production'] = EnvironmentData::from([]);

    expect(vaultReader()->host('production', $config))->toBeNull();
});

test('isVaultInstalled finds the Deployment by its identity label, whatever its instance name', function (): void {
    Process::fake(['*get deployment -l larakube.io/tool=passwords -n larakube-vault*' => 'vaultwarden-vault-example-com   1/1   1   1   5d']);
    expect(vaultReader()->installed('kubectl', 'larakube-vault'))->toBeTrue();

    Process::fake(['*get deployment -l larakube.io/tool=passwords -n larakube-vault*' => Process::result(output: '', exitCode: 1)]);
    expect(vaultReader()->installed('kubectl', 'larakube-vault'))->toBeFalse();
});

test('readVaultAdminToken reads the instance\'s credentials Secret', function (): void {
    Process::fake([
        "*get secret vaultwarden-secrets-vault-example-com -n larakube-vault -o jsonpath='{.data.plain-token}'*" => base64_encode('s3cr3t-adm1n'),
    ]);

    expect(vaultReader()->vaultToken('kubectl', 'larakube-vault', 'vault-example-com'))->toBe('s3cr3t-adm1n');
});

test('readVaultAdminToken is null when the Secret is absent', function (): void {
    Process::fake([
        '*get secret vaultwarden-secrets-vault-example-com*' => Process::result(output: '', exitCode: 1),
    ]);

    expect(vaultReader()->vaultToken('kubectl', 'larakube-vault', 'vault-example-com'))->toBeNull();
});

test('vaultAccess is null when vault is not installed, populated when it is', function (): void {
    $registry = base64_encode((string) json_encode([
        ['tool' => 'passwords', 'instance' => 'vault-example-com', 'host' => 'vault.example.com'],
    ]));

    Process::fake(['*get deployment -l larakube.io/tool=passwords*' => Process::result(output: '', exitCode: 1)]);
    expect(vaultReader()->access('local', null))->toBeNull();

    Process::fake([
        '*get deployment -l larakube.io/tool=passwords*' => 'vaultwarden-vault-example-com   1/1   1   1   5d',
        '*get secret larakube-tools-registry*' => $registry,
        "*get secret vaultwarden-secrets-vault-example-com -n larakube-vault -o jsonpath='{.data.plain-token}'*" => base64_encode('s3cr3t-adm1n'),
    ]);
    $access = vaultReader()->access('local', null);

    expect($access['host'])->toStartWith('vault.')
        ->and($access['token'])->toBe('s3cr3t-adm1n');
});
