<?php

use App\Data\ConfigData;
use App\Data\EnvironmentData;
use App\Traits\InteractsWithMonitoring;
use Illuminate\Support\Facades\Process;

function monitoringReader(): object
{
    return new class
    {
        use InteractsWithMonitoring;

        public function host(string $env, ?ConfigData $config): ?string
        {
            return $this->resolveGrafanaHostReadOnly($env, $config);
        }

        public function kubectlFor(?string $context): string
        {
            return $this->monitoringKubectl($context);
        }

        public function installed(string $kubectl, string $ns): bool
        {
            return $this->isMonitoringInstalled($kubectl, $ns);
        }

        public function grafanaPassword(string $kubectl, string $ns): ?string
        {
            return $this->readGrafanaPassword($kubectl, $ns);
        }

        public function access(string $env, ?ConfigData $config, ?string $context = null): ?array
        {
            return $this->monitoringAccess($env, $config, $context);
        }
    };
}

test('local Grafana host uses the grafana subdomain on the dev TLD', function (): void {
    expect(monitoringReader()->host('local', null))->toStartWith('grafana.');
});

test('cloud Grafana host returns the host persisted for that env', function (): void {
    $config = ConfigData::from(['name' => 'demo']);
    $config->environments['production'] = EnvironmentData::from(['hosts' => ['grafana' => 'grafana.example.com']]);

    expect(monitoringReader()->host('production', $config))->toBe('grafana.example.com');
});

test('cloud Grafana host is null when none is configured for the env', function (): void {
    $config = ConfigData::from(['name' => 'demo']);
    $config->environments['production'] = EnvironmentData::from([]);

    expect(monitoringReader()->host('production', $config))->toBeNull();
});

test('monitoringKubectl scopes to a context only when one is given', function (): void {
    $reader = monitoringReader();
    $kubectl = 'KUBECONFIG='.escapeshellarg(home_path('.kube/config')).' kubectl';

    expect($reader->kubectlFor('do-sfo3'))->toBe("{$kubectl} --context=do-sfo3")
        ->and($reader->kubectlFor(''))->toBe($kubectl)
        ->and($reader->kubectlFor(null))->toBe($kubectl);
});

test('isMonitoringInstalled reflects whether the grafana Deployment exists', function (): void {
    Process::fake(['kubectl get deployment grafana -n larakube-shared --no-headers' => 'grafana   1/1   1   1   5d']);
    expect(monitoringReader()->installed('kubectl', 'larakube-shared'))->toBeTrue();

    Process::fake(['kubectl get deployment grafana -n larakube-shared --no-headers' => Process::result(output: '', exitCode: 1)]);
    expect(monitoringReader()->installed('kubectl', 'larakube-shared'))->toBeFalse();
});

test('readGrafanaPassword decodes the admin secret, null when absent', function (): void {
    Process::fake([
        "kubectl get secret monitor-secrets -n larakube-shared -o jsonpath='{.data.password}'" => base64_encode('s3cr3t'),
    ]);
    expect(monitoringReader()->grafanaPassword('kubectl', 'larakube-shared'))->toBe('s3cr3t');

    Process::fake([
        "kubectl get secret monitor-secrets -n larakube-shared -o jsonpath='{.data.password}'" => Process::result(output: '', exitCode: 1),
    ]);
    expect(monitoringReader()->grafanaPassword('kubectl', 'larakube-shared'))->toBeNull();
});

test('monitoringAccess is null when monitoring is not installed, populated when it is', function (): void {
    $kubectl = 'KUBECONFIG='.escapeshellarg(home_path('.kube/config')).' kubectl';

    Process::fake(["{$kubectl} get deployment grafana -n larakube-shared --no-headers" => Process::result(output: '', exitCode: 1)]);
    expect(monitoringReader()->access('local', null))->toBeNull();

    Process::fake([
        "{$kubectl} get deployment grafana -n larakube-shared --no-headers" => 'grafana   1/1   1   1   5d',
        "{$kubectl} get secret monitor-secrets -n larakube-shared -o jsonpath='{.data.password}'" => base64_encode('s3cr3t'),
    ]);
    $access = monitoringReader()->access('local', null);

    expect($access['password'])->toBe('s3cr3t')
        ->and($access['host'])->toStartWith('grafana.')
        ->and($access['prometheus'])->toEndWith('.larakube-shared.svc.cluster.local:9090');
});
