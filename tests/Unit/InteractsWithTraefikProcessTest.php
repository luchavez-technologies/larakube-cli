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

test('isTraefikInstalled is true on the first, most specific label match', function (): void {
    Process::fake([
        'kubectl get svc -A -l app.kubernetes.io/name=traefik,app.kubernetes.io/component=ingress-controller -o name' => 'service/traefik',
    ]);

    expect(traefikDetector()->installed())->toBeTrue();
});

test('isTraefikInstalled falls back through nginx-ingress, name-based, then kube-system-wide', function (): void {
    Process::fake([
        'kubectl get svc -A -l app.kubernetes.io/name=traefik,app.kubernetes.io/component=ingress-controller -o name' => Process::result(output: '', exitCode: 1),
        'kubectl get svc -A -l app=ingress-nginx,app.kubernetes.io/name=ingress-nginx -o name' => Process::result(output: '', exitCode: 1),
        'kubectl get svc -A --field-selector metadata.name=traefik,spec.type=LoadBalancer -o name' => Process::result(output: '', exitCode: 1),
        'kubectl get svc -n kube-system --field-selector spec.type=LoadBalancer -o name' => 'service/some-lb',
    ]);

    expect(traefikDetector()->installed())->toBeTrue();
});

test('isTraefikInstalled is false when every detection strategy comes up empty', function (): void {
    Process::fake([
        'kubectl get svc -A -l app.kubernetes.io/name=traefik,app.kubernetes.io/component=ingress-controller -o name' => Process::result(output: '', exitCode: 1),
        'kubectl get svc -A -l app=ingress-nginx,app.kubernetes.io/name=ingress-nginx -o name' => Process::result(output: '', exitCode: 1),
        'kubectl get svc -A --field-selector metadata.name=traefik,spec.type=LoadBalancer -o name' => Process::result(output: '', exitCode: 1),
        'kubectl get svc -n kube-system --field-selector spec.type=LoadBalancer -o name' => Process::result(output: '', exitCode: 1),
    ]);

    expect(traefikDetector()->installed())->toBeFalse();
});

/** The trait's local-certificate step, with its warning captured instead of printed. */
function traefikCertApplier(): object
{
    return new class
    {
        use InteractsWithTraefik;

        /** @var list<string> */
        public array $warnings = [];

        public function laraKubeWarn(string $message): void
        {
            $this->warnings[] = $message;
        }

        public function apply(?App\Services\Kubectl $cluster): void
        {
            $this->applyTraefikCertResources('traefik', $cluster);
        }
    };
}

test('local certificates are never applied to a cloud cluster, named or merely selected', function (?string $context): void {
    Process::fake([
        '*config current-context*' => Process::result(output: "larakube-203.0.113.10\n"),
        '*' => Process::result(output: ''),
    ]);

    $applier = traefikCertApplier();
    $applier->apply($context === null ? null : App\Services\Kubectl::forContext($context));

    Process::assertNotRan(fn ($process) => str_contains((string) $process->command, 'traefik-config')
        || str_contains((string) $process->command, 'traefik-certificates')
        || str_contains((string) $process->command, 'rollout'));
    expect($applier->warnings)->toHaveCount(1)
        ->and($applier->warnings[0])->toContain('larakube-203.0.113.10');
})->with([
    'the install named the cloud cluster' => ['larakube-203.0.113.10'],
    'the cloud cluster was only the selected context' => [null],
]);
