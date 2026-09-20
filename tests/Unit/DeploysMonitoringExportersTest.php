<?php

/**
 * ensureMonitoringExporters()/applyExporterManifest() render real Blade
 * manifests and shell out to kubectl apply — an integration concern (needs a
 * real ConfigData with drivers + a real cluster) left to a smoke test.
 * isMonitoringActive() is the one plain kubectl probe, covered here.
 */

use App\Traits\DeploysMonitoringExporters;
use Illuminate\Support\Facades\Process;

function monitoringExporters(): object
{
    return new class
    {
        use DeploysMonitoringExporters;

        public function active(string $kubectl = 'kubectl'): bool
        {
            return $this->isMonitoringActive($kubectl);
        }
    };
}

function monitoringExportersRegistry(string $instance = 'monitor-example-com'): string
{
    return base64_encode((string) json_encode([['tool' => 'monitor', 'instance' => $instance, 'host' => 'monitor.example.com']]));
}

test('isMonitoringActive finds the registered instance\'s Prometheus by its ToolInstance name', function (): void {
    // The bare `prometheus` name it used to probe no longer exists (ADR 0021),
    // so it answered "not installed" and a Commons re-apply stripped the
    // Postgres/Redis exporters, restarting both.
    Process::fake([
        '*get secret larakube-tools-registry*' => Process::result(output: monitoringExportersRegistry()),
        '*get deployment prometheus-monitor-example-com *' => Process::result(output: 'deployment.apps/prometheus-monitor-example-com'),
        '*' => Process::result(output: ''),
    ]);
    expect(monitoringExporters()->active())->toBeTrue();
});

test('isMonitoringActive is false when the registered instance\'s Prometheus is gone', function (): void {
    Process::fake([
        '*get secret larakube-tools-registry*' => Process::result(output: monitoringExportersRegistry()),
        '*' => Process::result(output: ''),
    ]);

    expect(monitoringExporters()->active())->toBeFalse();
});

test('isMonitoringActive is false when no Monitor instance is registered', function (): void {
    Process::fake(['*' => Process::result(output: '')]);

    expect(monitoringExporters()->active())->toBeFalse();
});

test('isMonitoringActive scopes to the given kubectl prefix', function (): void {
    Process::fake([
        'kubectl --context=do-sfo3 get secret larakube-tools-registry*' => Process::result(output: monitoringExportersRegistry()),
        'kubectl --context=do-sfo3 get deployment prometheus-monitor-example-com *' => Process::result(output: 'deployment.apps/x'),
        '*' => Process::result(output: ''),
    ]);

    expect(monitoringExporters()->active('kubectl --context=do-sfo3'))->toBeTrue();
});
