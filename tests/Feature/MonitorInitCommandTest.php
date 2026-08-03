<?php

use Illuminate\Support\Facades\Process;

test('monitor:init --no-logs deploys metrics-only stack without loki and promtail', function () {
    Process::fake([
        '*create namespace*' => Process::result(output: 'namespace created'),
        '*get secret*' => Process::result(output: '', exitCode: 1),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout *' => Process::result(output: 'rollout success'),
    ]);

    $this->artisan('monitor:init local --no-logs')
        ->assertExitCode(0)
        ->expectsOutputToContain('Waiting for Prometheus...')
        ->doesntExpectOutputToContain('Waiting for Loki...')
        ->expectsOutputToContain('Waiting for kube-state-metrics...')
        ->expectsOutputToContain('Waiting for Grafana...')
        ->doesntExpectOutputToContain('Waiting for Promtail...')
        ->expectsOutputToContain('Log aggregation (Loki + Promtail) is disabled (~300MB RAM saved).')
        ->expectsOutputToContain('Run larakube monitor:init --with-logs anytime to enable log search in Grafana.');
});

test('monitor:init --with-logs deploys full stack including loki and promtail', function () {
    Process::fake([
        '*create namespace*' => Process::result(output: 'namespace created'),
        '*get secret*' => Process::result(output: '', exitCode: 1),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout *' => Process::result(output: 'rollout success'),
    ]);

    $this->artisan('monitor:init local --with-logs')
        ->assertExitCode(0)
        ->expectsOutputToContain('Waiting for Prometheus...')
        ->expectsOutputToContain('Waiting for Loki...')
        ->expectsOutputToContain('Waiting for kube-state-metrics...')
        ->expectsOutputToContain('Waiting for Grafana...')
        ->expectsOutputToContain('Waiting for Promtail...')
        ->expectsOutputToContain('Prometheus + Loki are pre-wired as Grafana data sources.');
});

test('monitor:init defaults to metrics-only in non-interactive mode', function () {
    Process::fake([
        '*create namespace*' => Process::result(output: 'namespace created'),
        '*get secret*' => Process::result(output: '', exitCode: 1),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout *' => Process::result(output: 'rollout success'),
    ]);

    $this->artisan('monitor:init local --no-interaction')
        ->assertExitCode(0)
        ->doesntExpectOutputToContain('Waiting for Loki...')
        ->doesntExpectOutputToContain('Waiting for Promtail...')
        ->expectsOutputToContain('Log aggregation (Loki + Promtail) is disabled (~300MB RAM saved).');
});

test('monitoring shared blade view conditionally renders loki and promtail based on withLogs', function () {
    $metricsOnlyManifest = view('k8s.monitoring.shared', [
        'host' => 'grafana.dev.test',
        'grafanaPassword' => 'secret123',
        'isLocal' => true,
        'vpnOnly' => false,
        'withLogs' => false,
    ])->render();

    expect($metricsOnlyManifest)->toContain('app: prometheus')
        ->toContain('app: kube-state-metrics')
        ->toContain('app: grafana')
        ->not->toContain('app: loki')
        ->not->toContain('app: promtail')
        ->not->toContain('name: Loki');

    $fullManifest = view('k8s.monitoring.shared', [
        'host' => 'grafana.dev.test',
        'grafanaPassword' => 'secret123',
        'isLocal' => true,
        'vpnOnly' => false,
        'withLogs' => true,
    ])->render();

    expect($fullManifest)->toContain('app: prometheus')
        ->toContain('app: kube-state-metrics')
        ->toContain('app: grafana')
        ->toContain('app: loki')
        ->toContain('app: promtail')
        ->toContain('name: Loki');
});
