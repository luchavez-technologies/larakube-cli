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

test('isMonitoringActive reflects whether the prometheus Deployment exists', function (): void {
    Process::fake(['kubectl get deployment prometheus -n larakube-shared --no-headers' => 'prometheus   1/1   1   1   5d']);
    expect(monitoringExporters()->active())->toBeTrue();

    Process::fake(['kubectl get deployment prometheus -n larakube-shared --no-headers' => Process::result(output: '', exitCode: 1)]);
    expect(monitoringExporters()->active())->toBeFalse();
});

test('isMonitoringActive scopes to the given kubectl prefix', function (): void {
    Process::fake(['kubectl --context=do-sfo3 get deployment prometheus -n larakube-shared --no-headers' => 'prometheus   1/1   1   1   5d']);

    expect(monitoringExporters()->active('kubectl --context=do-sfo3'))->toBeTrue();
    Process::assertRan('kubectl --context=do-sfo3 get deployment prometheus -n larakube-shared --no-headers');
});
