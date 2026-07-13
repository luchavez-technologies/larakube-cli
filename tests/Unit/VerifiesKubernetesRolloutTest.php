<?php

/**
 * applyAndVerifyRollout() is the shared mechanic extracted after the same
 * false-success bug turned up independently in all three of LaraKube's
 * Traefik installers (local, VPS, DOKS) — a successful `kubectl apply` only
 * means the API server accepted the manifest, not that the pod came up.
 */

use App\State;
use App\Traits\VerifiesKubernetesRollout;
use Illuminate\Support\Facades\Process;

function rolloutVerifier(): object
{
    return new class
    {
        use VerifiesKubernetesRollout;

        public function apply(string $kubectl, string $manifestPath, string $namespace, string $deployment, int $timeoutSeconds = 120, string $extraApplyFlags = ''): bool
        {
            return $this->applyAndVerifyRollout($kubectl, $manifestPath, $namespace, $deployment, $timeoutSeconds, $extraApplyFlags);
        }
    };
}

test('applyAndVerifyRollout returns true only once both apply and rollout succeed', function () {
    Process::fake([
        "kubectl apply -f '/tmp/manifest.yaml' --request-timeout=60s" => Process::result(exitCode: 0),
        "kubectl rollout status deployment/traefik -n 'traefik' --timeout=120s" => Process::result(exitCode: 0),
    ]);

    expect(rolloutVerifier()->apply('kubectl', '/tmp/manifest.yaml', 'traefik', 'traefik'))->toBeTrue();
});

test('applyAndVerifyRollout fails fast when the apply itself fails, without checking rollout', function () {
    Process::fake([
        "kubectl apply -f '/tmp/manifest.yaml' --request-timeout=60s" => Process::result(output: '', errorOutput: 'error: unable to apply', exitCode: 1),
    ]);

    expect(rolloutVerifier()->apply('kubectl', '/tmp/manifest.yaml', 'traefik', 'traefik'))->toBeFalse();
    expect(State::$lastError)->toContain('Could not apply the traefik manifest');

    Process::assertNotRan(fn ($process) => str_contains($process->command, 'rollout status'));
});

test('applyAndVerifyRollout fails when apply succeeds but the Deployment never becomes Ready', function () {
    Process::fake([
        "kubectl apply -f '/tmp/manifest.yaml' --request-timeout=60s" => Process::result(exitCode: 0),
        "kubectl rollout status deployment/traefik -n 'traefik' --timeout=120s" => Process::result(exitCode: 1),
    ]);

    expect(rolloutVerifier()->apply('kubectl', '/tmp/manifest.yaml', 'traefik', 'traefik'))->toBeFalse();
    expect(State::$lastError)->toContain('never became Ready');
});

test('applyAndVerifyRollout appends extra apply flags verbatim', function () {
    Process::fake([
        "kubectl apply -f '/tmp/manifest.yaml' --request-timeout=60s --validate=false" => Process::result(exitCode: 0),
        "kubectl rollout status deployment/traefik -n 'traefik' --timeout=120s" => Process::result(exitCode: 0),
    ]);

    expect(rolloutVerifier()->apply('kubectl', '/tmp/manifest.yaml', 'traefik', 'traefik', extraApplyFlags: '--validate=false'))->toBeTrue();
});
