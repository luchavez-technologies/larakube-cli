<?php

use Illuminate\Support\Facades\Process;

test('monitor:init --no-logs deploys metrics-only stack without loki, promtail and tempo', function () {
    Process::fake([
        '*create namespace*' => Process::result(output: 'namespace created'),
        '*create configmap*' => Process::result(output: 'configmap created'),
        '*get secret*' => Process::result(output: '', exitCode: 1),
        '*get deployment*' => Process::result(output: '', exitCode: 1),
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
        ->doesntExpectOutputToContain('Waiting for Tempo...')
        ->expectsOutputToContain('Log aggregation (Loki + Promtail) is disabled (~300MB RAM saved).')
        ->expectsOutputToContain('Run larakube monitor:init --with-logs anytime to enable log search in Grafana.')
        ->expectsOutputToContain('Distributed tracing (Tempo) is disabled (~450MB RAM saved).')
        ->expectsOutputToContain('Run larakube monitor:init --with-traces anytime to enable trace search in Grafana.')
        ->expectsOutputToContain('Dashboards: Cluster Overview, Nodes, Pods.');

    Process::assertRan(fn ($p) => str_contains($p->command, 'create configmap grafana-dashboards'));
    Process::assertNotRan('*rollout restart*');
    Process::assertNotRan('*delete *');
});

test('monitor:init --with-logs deploys full stack including loki and promtail', function () {
    Process::fake([
        '*create namespace*' => Process::result(output: 'namespace created'),
        '*create configmap*' => Process::result(output: 'configmap created'),
        '*get secret*' => Process::result(output: '', exitCode: 1),
        '*get deployment*' => Process::result(output: '', exitCode: 1),
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
        ->doesntExpectOutputToContain('Waiting for Tempo...')
        ->expectsOutputToContain('Prometheus + Loki are pre-wired as Grafana data sources.')
        ->expectsOutputToContain('Dashboards: Cluster Overview, Nodes, Pods, Loki Logs.');

    Process::assertNotRan('*rollout restart*');
});

test('monitor:init --with-traces --with-logs deploys the full stack including tempo', function () {
    Process::fake([
        '*create namespace*' => Process::result(output: 'namespace created'),
        '*create configmap*' => Process::result(output: 'configmap created'),
        '*get secret*' => Process::result(output: '', exitCode: 1),
        '*get deployment*' => Process::result(output: '', exitCode: 1),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout *' => Process::result(output: 'rollout success'),
    ]);

    $this->artisan('monitor:init local --with-traces --with-logs')
        ->assertExitCode(0)
        ->expectsOutputToContain('Waiting for Loki...')
        ->expectsOutputToContain('Waiting for Promtail...')
        ->expectsOutputToContain('Waiting for Tempo...')
        ->expectsOutputToContain('Prometheus + Loki + Tempo are pre-wired as Grafana data sources.')
        ->expectsOutputToContain('Dashboards: Cluster Overview, Nodes, Pods, Loki Logs, Tempo Service Graph.');

    Process::assertNotRan('*rollout restart*');
    Process::assertNotRan('*delete *');
});

test('monitor:init defaults to metrics-only in non-interactive mode', function () {
    Process::fake([
        '*create namespace*' => Process::result(output: 'namespace created'),
        '*create configmap*' => Process::result(output: 'configmap created'),
        '*get secret*' => Process::result(output: '', exitCode: 1),
        '*get deployment*' => Process::result(output: '', exitCode: 1),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout *' => Process::result(output: 'rollout success'),
    ]);

    $this->artisan('monitor:init local --no-interaction')
        ->assertExitCode(0)
        ->doesntExpectOutputToContain('Waiting for Loki...')
        ->doesntExpectOutputToContain('Waiting for Promtail...')
        ->doesntExpectOutputToContain('Waiting for Tempo...')
        ->expectsOutputToContain('Log aggregation (Loki + Promtail) is disabled (~300MB RAM saved).')
        ->expectsOutputToContain('Distributed tracing (Tempo) is disabled (~450MB RAM saved).');
});

test('monitor:init --no-logs removes a previously deployed log aggregation stack and restarts grafana', function () {
    Process::fake([
        '*create namespace*' => Process::result(output: 'namespace created'),
        '*create configmap*' => Process::result(output: 'configmap created'),
        '*get secret*' => Process::result(output: '', exitCode: 1),
        '*get deployment/grafana*' => Process::result(output: 'grafana 1/1', exitCode: 0),
        '*get deployment/loki*' => Process::result(output: 'loki 1/1', exitCode: 0),
        '*get deployment/tempo*' => Process::result(output: '', exitCode: 1),
        '*get deployment*' => Process::result(output: '', exitCode: 1),
        '*delete deployment,svc,configmap,pvc loki*' => Process::result(output: 'deleted'),
        '*delete daemonset,configmap,serviceaccount promtail*' => Process::result(output: 'deleted'),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout restart*' => Process::result(output: 'restarted'),
        '*rollout *' => Process::result(output: 'rollout success'),
    ]);

    $this->artisan('monitor:init local --no-logs')
        ->assertExitCode(0)
        ->expectsOutputToContain('Removing Loki...')
        ->expectsOutputToContain('Removing Promtail...')
        ->expectsOutputToContain('Restarting Grafana to load the updated data sources...')
        ->expectsOutputToContain('Waiting for Grafana after restart...')
        ->expectsOutputToContain('Log aggregation (Loki + Promtail) removed');

    Process::assertRan(fn ($p) => str_contains($p->command, 'delete deployment,svc,configmap,pvc loki'));
    Process::assertRan(fn ($p) => str_contains($p->command, 'delete daemonset,configmap,serviceaccount promtail'));
    Process::assertRan(fn ($p) => str_contains($p->command, 'rollout restart'));
    Process::assertNotRan('*delete deployment,svc,configmap,pvc tempo*');
});

test('monitor:init --no-traces removes a previously deployed tempo stack and restarts grafana', function () {
    Process::fake([
        '*create namespace*' => Process::result(output: 'namespace created'),
        '*create configmap*' => Process::result(output: 'configmap created'),
        '*get secret*' => Process::result(output: '', exitCode: 1),
        '*get deployment/grafana*' => Process::result(output: 'grafana 1/1', exitCode: 0),
        '*get deployment/loki*' => Process::result(output: '', exitCode: 1),
        '*get deployment/tempo*' => Process::result(output: 'tempo 1/1', exitCode: 0),
        '*get deployment*' => Process::result(output: '', exitCode: 1),
        '*delete deployment,svc,configmap,pvc tempo*' => Process::result(output: 'deleted'),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout restart*' => Process::result(output: 'restarted'),
        '*rollout *' => Process::result(output: 'rollout success'),
    ]);

    $this->artisan('monitor:init local --no-traces')
        ->assertExitCode(0)
        ->expectsOutputToContain('Removing Tempo...')
        ->expectsOutputToContain('Restarting Grafana to load the updated data sources...')
        ->expectsOutputToContain('Tempo removed');

    Process::assertRan(fn ($p) => str_contains($p->command, 'delete deployment,svc,configmap,pvc tempo'));
    Process::assertRan(fn ($p) => str_contains($p->command, 'rollout restart'));
    Process::assertNotRan('*delete deployment,svc,configmap,pvc loki*');
});

test('monitor:init re-running with matching flags is a no-op — no deletions, no grafana restart', function () {
    Process::fake([
        '*create namespace*' => Process::result(output: 'namespace created'),
        '*create configmap*' => Process::result(output: 'configmap created'),
        '*get secret*' => Process::result(output: '', exitCode: 1),
        '*get deployment/grafana*' => Process::result(output: 'grafana 1/1', exitCode: 0),
        '*get deployment/loki*' => Process::result(output: 'loki 1/1', exitCode: 0),
        '*get deployment/tempo*' => Process::result(output: 'tempo 1/1', exitCode: 0),
        '*get deployment*' => Process::result(output: '', exitCode: 1),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout *' => Process::result(output: 'rollout success'),
    ]);

    $this->artisan('monitor:init local --with-logs --with-traces')
        ->assertExitCode(0)
        ->doesntExpectOutputToContain('Removing Loki...')
        ->doesntExpectOutputToContain('Removing Tempo...')
        ->doesntExpectOutputToContain('Restarting Grafana');

    Process::assertNotRan('*delete *');
    Process::assertNotRan('*rollout restart*');
});

test('monitoring shared blade view conditionally renders optional components based on withLogs and withTraces', function () {
    $metricsOnlyManifest = view('k8s.monitoring.shared', [
        'host' => 'grafana.dev.test',
        'grafanaPassword' => 'secret123',
        'isLocal' => true,
        'vpnOnly' => false,
        'withLogs' => false,
        'withTraces' => false,
    ])->render();

    expect($metricsOnlyManifest)->toContain('app: prometheus')
        ->toContain('app: kube-state-metrics')
        ->toContain('app: grafana')
        ->toContain('name: grafana-dashboard-provider')
        ->not->toContain('app: loki')
        ->not->toContain('app: promtail')
        ->not->toContain('app: tempo')
        ->not->toContain('name: Loki')
        ->not->toContain('name: Tempo')
        ->not->toContain('metrics_generator');

    $fullManifest = view('k8s.monitoring.shared', [
        'host' => 'grafana.dev.test',
        'grafanaPassword' => 'secret123',
        'isLocal' => true,
        'vpnOnly' => false,
        'withLogs' => true,
        'withTraces' => true,
    ])->render();

    expect($fullManifest)->toContain('app: prometheus')
        ->toContain('app: kube-state-metrics')
        ->toContain('app: grafana')
        ->toContain('app: loki')
        ->toContain('app: promtail')
        ->toContain('app: tempo')
        ->toContain('name: Loki')
        ->toContain('name: Tempo')
        ->toContain('uid: loki-ds')
        ->toContain('uid: tempo-ds')
        ->toContain('metrics_generator')
        ->toContain('tempo-storage')
        ->toContain('grafana/tempo:2.10.7')
        ->toContain('mountPath: /var/lib/grafana/dashboards/');
});
