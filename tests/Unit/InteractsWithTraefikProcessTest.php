<?php

/**
 * Tests for InteractsWithTraefik's read-only isTraefikInstalled() detection
 * cascade — the rest of this trait (setupTraefik/applySharedService/
 * applyTraefikCertResources/destroyTraefik) mixes kubectl-apply with real
 * Blade view rendering and ManagesLocalCa's cert filesystem state, and is
 * left to a real-cluster smoke test.
 */

use App\Traits\InteractsWithTraefik;
use Illuminate\Support\Facades\Process;

function traefikDetector(): object
{
    return new class
    {
        use InteractsWithTraefik;

        public function installed(): bool
        {
            return $this->isTraefikInstalled();
        }
    };
}

test('isTraefikInstalled is true on the first, most specific label match', function () {
    Process::fake([
        'kubectl get svc -A -l app.kubernetes.io/name=traefik,app.kubernetes.io/component=ingress-controller -o name' => 'service/traefik',
    ]);

    expect(traefikDetector()->installed())->toBeTrue();
});

test('isTraefikInstalled falls back through nginx-ingress, name-based, then kube-system-wide', function () {
    Process::fake([
        'kubectl get svc -A -l app.kubernetes.io/name=traefik,app.kubernetes.io/component=ingress-controller -o name' => Process::result(output: '', exitCode: 1),
        'kubectl get svc -A -l app=ingress-nginx,app.kubernetes.io/name=ingress-nginx -o name' => Process::result(output: '', exitCode: 1),
        'kubectl get svc -A --field-selector metadata.name=traefik,spec.type=LoadBalancer -o name' => Process::result(output: '', exitCode: 1),
        'kubectl get svc -n kube-system --field-selector spec.type=LoadBalancer -o name' => 'service/some-lb',
    ]);

    expect(traefikDetector()->installed())->toBeTrue();
});

test('isTraefikInstalled is false when every detection strategy comes up empty', function () {
    Process::fake([
        'kubectl get svc -A -l app.kubernetes.io/name=traefik,app.kubernetes.io/component=ingress-controller -o name' => Process::result(output: '', exitCode: 1),
        'kubectl get svc -A -l app=ingress-nginx,app.kubernetes.io/name=ingress-nginx -o name' => Process::result(output: '', exitCode: 1),
        'kubectl get svc -A --field-selector metadata.name=traefik,spec.type=LoadBalancer -o name' => Process::result(output: '', exitCode: 1),
        'kubectl get svc -n kube-system --field-selector spec.type=LoadBalancer -o name' => Process::result(output: '', exitCode: 1),
    ]);

    expect(traefikDetector()->installed())->toBeFalse();
});
