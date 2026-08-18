<?php

/**
 * ADR 0018: applyDesignPenpotFlags() must deliver a changed PENPOT_FLAGS
 * value via `kubectl rollout restart`, never a literal `kubectl set env
 * ... PENPOT_FLAGS=<value>` — the latter desyncs kubectl apply's
 * bookkeeping and permanently breaks the next `design:init` re-apply. It
 * must also stay a no-op (no restart) when the value hasn't actually
 * changed, preserving the original real-downtime-avoidance guarantee on
 * Penpot's Recreate-strategy Deployment.
 */

use App\Traits\ReadsClusterSecrets;
use App\Traits\ReconcilesPenpotFlags;
use Illuminate\Support\Facades\Process;

function penpotFlagsReconciler(): object
{
    return new class
    {
        use ReadsClusterSecrets, ReconcilesPenpotFlags;

        public function apply(string $kubectl, string $ns, string $secret, string $value, string ...$deployments): void
        {
            $this->applyDesignPenpotFlags($kubectl, $ns, $secret, $value, ...$deployments);
        }
    };
}

test('applyDesignPenpotFlags patches the Secret and rolls out a restart when the value changes — never a literal set env', function () {
    Process::fake([
        '*get secret design-oidc*' => Process::result(
            output: base64_encode('enable-access-tokens'),
        ),
        '*patch secret design-oidc*' => Process::result(output: 'secret/design-oidc patched'),
        '*get deployment design-penpot-backend*' => Process::result(output: 'design-penpot-backend   1/1   1   1   1d'),
        '*rollout restart*' => Process::result(output: 'deployment.apps/design-penpot-backend restarted'),
    ]);

    penpotFlagsReconciler()->apply(
        'kubectl',
        'larakube-shared',
        'design-oidc',
        'enable-access-tokens enable-login-with-oidc',
        'design-penpot-backend',
    );

    Process::assertRan(fn ($process) => str_contains($process->command, 'patch secret design-oidc')
        && str_contains($process->command, 'PENPOT_FLAGS'));
    Process::assertRan(fn ($process) => str_contains($process->command, 'rollout restart deployment/design-penpot-backend'));
    Process::assertNotRan(fn ($process) => str_contains($process->command, 'set env deployment/design-penpot-backend'));
});

test('applyDesignPenpotFlags is a no-op restart when the value is unchanged', function () {
    Process::fake([
        '*get secret design-oidc*' => Process::result(
            output: base64_encode('enable-access-tokens enable-login-with-oidc'),
        ),
        '*patch secret design-oidc*' => Process::result(output: 'secret/design-oidc patched'),
        '*get deployment design-penpot-backend*' => Process::result(output: 'design-penpot-backend   1/1   1   1   1d'),
    ]);

    penpotFlagsReconciler()->apply(
        'kubectl',
        'larakube-shared',
        'design-oidc',
        'enable-access-tokens enable-login-with-oidc',
        'design-penpot-backend',
    );

    // The Secret write is unconditional (idempotent PUT), but a Recreate
    // Deployment must not pay real downtime for a value that didn't change.
    Process::assertNotRan(fn ($process) => str_contains($process->command, 'rollout restart'));
});
