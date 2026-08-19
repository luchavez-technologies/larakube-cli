<?php

use App\Data\ConfigData;
use App\Data\EnvironmentData;
use App\Traits\InteractsWithErrors;
use Illuminate\Support\Facades\Process;

function errorsReader(): object
{
    return new class
    {
        use InteractsWithErrors;

        public function host(string $env, ?ConfigData $config): ?string
        {
            return $this->resolveErrorsHostReadOnly($env, $config);
        }

        public function kubectlFor(?string $context): string
        {
            return $this->errorsKubectl($context);
        }

        public function installed(string $kubectl, string $ns): bool
        {
            return $this->isErrorsInstalled($kubectl, $ns);
        }

        public function adminPassword(string $kubectl, string $ns): ?string
        {
            return $this->readErrorsAdminPassword($kubectl, $ns);
        }

        public function access(string $env, ?ConfigData $config, ?string $context = null): ?array
        {
            return $this->errorsAccess($env, $config, $context);
        }
    };
}

test('local Errors host uses the errors subdomain on the dev TLD', function (): void {
    expect(errorsReader()->host('local', null))->toStartWith('errors.');
});

test('cloud Errors host returns the host persisted for that env', function (): void {
    $config = ConfigData::from(['name' => 'demo']);
    $config->environments['production'] = EnvironmentData::from(['hosts' => ['errors' => 'errors.example.com']]);

    expect(errorsReader()->host('production', $config))->toBe('errors.example.com');
});

test('cloud Errors host is null when none is configured for the env', function (): void {
    $config = ConfigData::from(['name' => 'demo']);
    $config->environments['production'] = EnvironmentData::from([]);

    expect(errorsReader()->host('production', $config))->toBeNull();
});

test('errorsKubectl scopes to a context only when one is given', function (): void {
    $reader = errorsReader();
    $kubectl = 'KUBECONFIG='.escapeshellarg(home_path('.kube/config')).' kubectl';

    expect($reader->kubectlFor('do-sfo3'))->toBe("{$kubectl} --context=do-sfo3")
        ->and($reader->kubectlFor(''))->toBe($kubectl)
        ->and($reader->kubectlFor(null))->toBe($kubectl);
});

test('isErrorsInstalled reflects whether the glitchtip-web Deployment exists', function (): void {
    Process::fake(['kubectl get deployment glitchtip-web -n larakube-shared --no-headers' => 'glitchtip-web   1/1   1   1   5d']);
    expect(errorsReader()->installed('kubectl', 'larakube-shared'))->toBeTrue();

    Process::fake(['kubectl get deployment glitchtip-web -n larakube-shared --no-headers' => Process::result(output: '', exitCode: 1)]);
    expect(errorsReader()->installed('kubectl', 'larakube-shared'))->toBeFalse();
});

test('readErrorsAdminPassword decodes the admin secret, null when absent', function (): void {
    Process::fake([
        "kubectl get secret errors-secrets -n larakube-shared -o jsonpath='{.data.password}'" => base64_encode('s3cr3t-adm1n'),
    ]);
    expect(errorsReader()->adminPassword('kubectl', 'larakube-shared'))->toBe('s3cr3t-adm1n');

    Process::fake([
        "kubectl get secret errors-secrets -n larakube-shared -o jsonpath='{.data.password}'" => Process::result(output: '', exitCode: 1),
    ]);
    expect(errorsReader()->adminPassword('kubectl', 'larakube-shared'))->toBeNull();
});

test('errorsAccess is null when glitchtip is not installed, populated when it is', function (): void {
    $kubectl = 'KUBECONFIG='.escapeshellarg(home_path('.kube/config')).' kubectl';

    Process::fake(["{$kubectl} get deployment glitchtip-web -n larakube-shared --no-headers" => Process::result(output: '', exitCode: 1)]);
    expect(errorsReader()->access('local', null))->toBeNull();

    Process::fake([
        "{$kubectl} get deployment glitchtip-web -n larakube-shared --no-headers" => 'glitchtip-web   1/1   1   1   5d',
        "{$kubectl} get secret errors-secrets -n larakube-shared -o jsonpath='{.data.password}'" => base64_encode('s3cr3t-adm1n'),
    ]);
    $access = errorsReader()->access('local', null);

    expect($access['host'])->toStartWith('errors.')
        ->and($access['password'])->toBe('s3cr3t-adm1n')
        ->and($access['label'])->toBe('GlitchTip');
});
