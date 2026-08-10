<?php

use App\Data\ConfigData;
use App\Data\EnvironmentData;
use App\Traits\InteractsWithUptime;
use Illuminate\Support\Facades\Process;

function uptimeReader(): object
{
    return new class
    {
        use InteractsWithUptime;

        public function host(string $env, ?ConfigData $config): ?string
        {
            return $this->resolveUptimeHostReadOnly($env, $config);
        }

        public function kubectlFor(?string $context): string
        {
            return $this->uptimeKubectl($context);
        }

        public function installed(string $kubectl, string $ns): bool
        {
            return $this->isUptimeInstalled($kubectl, $ns);
        }

        public function access(string $env, ?ConfigData $config, ?string $context = null): ?array
        {
            return $this->uptimeAccess($env, $config, $context);
        }
    };
}

test('local Uptime host uses the status subdomain on the dev TLD', function () {
    expect(uptimeReader()->host('local', null))->toStartWith('status.');
});

test('cloud Uptime host returns the host persisted for that env', function () {
    $config = ConfigData::from(['name' => 'demo']);
    $config->environments['production'] = EnvironmentData::from(['hosts' => ['uptime' => 'status.example.com']]);

    expect(uptimeReader()->host('production', $config))->toBe('status.example.com');
});

test('cloud Uptime host is null when none is configured for the env', function () {
    $config = ConfigData::from(['name' => 'demo']);
    $config->environments['production'] = EnvironmentData::from([]);

    expect(uptimeReader()->host('production', $config))->toBeNull();
});

test('uptimeKubectl scopes to a context only when one is given', function () {
    $reader = uptimeReader();
    $kubectl = 'KUBECONFIG='.escapeshellarg(home_path('.kube/config')).' kubectl';

    expect($reader->kubectlFor('do-sfo3'))->toBe("{$kubectl} --context=do-sfo3")
        ->and($reader->kubectlFor(''))->toBe($kubectl)
        ->and($reader->kubectlFor(null))->toBe($kubectl);
});

test('isUptimeInstalled reflects whether the uptime-kuma Deployment exists', function () {
    Process::fake(['kubectl get deployment uptime-kuma -n larakube-shared --no-headers' => 'uptime-kuma   1/1   1   1   5d']);
    expect(uptimeReader()->installed('kubectl', 'larakube-shared'))->toBeTrue();

    Process::fake(['kubectl get deployment uptime-kuma -n larakube-shared --no-headers' => Process::result(output: '', exitCode: 1)]);
    expect(uptimeReader()->installed('kubectl', 'larakube-shared'))->toBeFalse();
});

test('uptimeAccess is null when uptime is not installed, populated when it is', function () {
    $kubectl = 'KUBECONFIG='.escapeshellarg(home_path('.kube/config')).' kubectl';

    Process::fake(["{$kubectl} get deployment uptime-kuma -n larakube-shared --no-headers" => Process::result(output: '', exitCode: 1)]);
    expect(uptimeReader()->access('local', null))->toBeNull();

    Process::fake([
        "{$kubectl} get deployment uptime-kuma -n larakube-shared --no-headers" => 'uptime-kuma   1/1   1   1   5d',
    ]);
    $access = uptimeReader()->access('local', null);

    expect($access['host'])->toStartWith('status.')
        ->and($access['label'])->toBe('Uptime Kuma');
});
