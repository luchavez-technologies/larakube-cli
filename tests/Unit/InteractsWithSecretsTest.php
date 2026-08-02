<?php

use App\Data\ConfigData;
use App\Data\EnvironmentData;
use Illuminate\Support\Facades\Process;

function secretsReader(): object
{
    return new class
    {
        use App\Traits\InteractsWithSecrets;

        public function host(string $env, ?ConfigData $config): ?string
        {
            return $this->resolveSecretsHostReadOnly($env, $config);
        }

        public function kubectlFor(?string $context): string
        {
            return $this->secretsKubectl($context);
        }

        public function installed(string $kubectl, string $ns): bool
        {
            return $this->isSecretsInstalled($kubectl, $ns);
        }

        public function access(string $env, ?ConfigData $config, ?string $context = null): ?array
        {
            return $this->secretsAccess($env, $config, $context);
        }
    };
}

test('local Secrets host uses the secrets subdomain on the dev TLD', function () {
    expect(secretsReader()->host('local', null))->toStartWith('secrets.');
});

test('cloud Secrets host returns the host persisted for that env', function () {
    $config = ConfigData::from(['name' => 'demo']);
    $config->environments['production'] = EnvironmentData::from(['hosts' => ['secrets' => 'secrets.example.com']]);

    expect(secretsReader()->host('production', $config))->toBe('secrets.example.com');
});

test('cloud Secrets host is null when none is configured for the env', function () {
    $config = ConfigData::from(['name' => 'demo']);
    $config->environments['production'] = EnvironmentData::from([]);

    expect(secretsReader()->host('production', $config))->toBeNull();
});

test('secretsKubectl scopes to a context only when one is given', function () {
    $reader = secretsReader();
    $kubectl = 'KUBECONFIG='.escapeshellarg(home_path('.kube/config')).' kubectl';

    expect($reader->kubectlFor('do-sfo3'))->toBe("{$kubectl} --context=do-sfo3")
        ->and($reader->kubectlFor(''))->toBe($kubectl)
        ->and($reader->kubectlFor(null))->toBe($kubectl);
});

test('isSecretsInstalled reflects whether the openbao-backend Deployment exists', function () {
    Process::fake(['kubectl get deployment openbao-backend -n larakube-secrets --no-headers' => 'openbao-backend   1/1   1   1   5d']);
    expect(secretsReader()->installed('kubectl', 'larakube-secrets'))->toBeTrue();

    Process::fake(['kubectl get deployment openbao-backend -n larakube-secrets --no-headers' => Process::result(output: '', exitCode: 1)]);
    expect(secretsReader()->installed('kubectl', 'larakube-secrets'))->toBeFalse();
});

test('secretsAccess is null when openbao is not installed, populated when it is', function () {
    $kubectl = 'KUBECONFIG='.escapeshellarg(home_path('.kube/config')).' kubectl';

    Process::fake(["{$kubectl} get deployment openbao-backend -n larakube-secrets --no-headers" => Process::result(output: '', exitCode: 1)]);
    expect(secretsReader()->access('local', null))->toBeNull();

    Process::fake([
        "{$kubectl} get deployment openbao-backend -n larakube-secrets --no-headers" => 'openbao-backend   1/1   1   1   5d',
    ]);
    $access = secretsReader()->access('local', null);

    expect($access['host'])->toStartWith('secrets.')
        ->and($access['label'])->toBe('OpenBao');
});
