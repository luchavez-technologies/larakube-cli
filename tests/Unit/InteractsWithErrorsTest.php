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

        public function installed(string $kubectl, string $ns): bool
        {
            return $this->isErrorsInstalled($kubectl, $ns);
        }

        public function adminPassword(string $kubectl, string $ns): ?string
        {
            return $this->readErrorsAdminPassword($kubectl, $ns, 'glitchtip-secrets-errors-example-com');
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

test('isErrorsInstalled reflects whether a GlitchTip web Deployment exists', function (): void {
    Process::fake(['kubectl get deployment -n larakube-shared -l larakube.io/tool=errors,larakube.io/component=glitchtip --no-headers --ignore-not-found' => 'glitchtip-errors-example-com   1/1   1   1   5d']);
    expect(errorsReader()->installed('kubectl', 'larakube-shared'))->toBeTrue();

    Process::fake(['kubectl get deployment -n larakube-shared -l larakube.io/tool=errors,larakube.io/component=glitchtip --no-headers --ignore-not-found' => Process::result(output: '', exitCode: 1)]);
    expect(errorsReader()->installed('kubectl', 'larakube-shared'))->toBeFalse();
});

test('readErrorsAdminPassword decodes the admin secret, null when absent', function (): void {
    Process::fake([
        "kubectl get secret glitchtip-secrets-errors-example-com -n larakube-shared -o jsonpath='{.data.password}'" => base64_encode('s3cr3t-adm1n'),
    ]);
    expect(errorsReader()->adminPassword('kubectl', 'larakube-shared'))->toBe('s3cr3t-adm1n');

    Process::fake([
        "kubectl get secret glitchtip-secrets-errors-example-com -n larakube-shared -o jsonpath='{.data.password}'" => Process::result(output: '', exitCode: 1),
    ]);
    expect(errorsReader()->adminPassword('kubectl', 'larakube-shared'))->toBeNull();
});

test('errorsAccess is null when glitchtip is not installed, populated when it is', function (): void {
    $kubectl = 'KUBECONFIG='.escapeshellarg(home_path('.kube/config')).' kubectl';
    Tests\Support\FakeToolRegistry::install([['tool' => 'errors', 'instance' => 'errors-example-com', 'host' => 'errors.example.com']]);

    Process::fake(["{$kubectl} get deployment -n larakube-shared -l larakube.io/tool=errors,larakube.io/component=glitchtip --no-headers --ignore-not-found" => Process::result(output: '', exitCode: 1)]);
    expect(errorsReader()->access('local', null))->toBeNull();

    Process::fake([
        "{$kubectl} get deployment -n larakube-shared -l larakube.io/tool=errors,larakube.io/component=glitchtip --no-headers --ignore-not-found" => 'glitchtip-errors-example-com   1/1   1   1   5d',
        "{$kubectl} get secret glitchtip-secrets-errors-example-com -n larakube-shared -o jsonpath='{.data.password}'" => base64_encode('s3cr3t-adm1n'),
    ]);
    $access = errorsReader()->access('local', null);

    expect($access['host'])->toStartWith('errors.')
        ->and($access['password'])->toBe('s3cr3t-adm1n')
        ->and($access['label'])->toBe('GlitchTip');
});
