<?php

use Illuminate\Support\Facades\Process;

test('secrets:remove is registered', function () {
    $this->artisan('list --no-interaction')
        ->assertExitCode(0)
        ->expectsOutputToContain('secrets:remove');
});

test('secrets:remove tears down OpenBao, ESO, and ESO RBAC — but never the shared CRDs', function () {
    Process::fake([
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('secrets:remove', ['environment' => 'production', '--force' => true, '--no-interaction' => true])
        ->assertExitCode(0);

    // The real, single Deployment eso.blade.php actually creates.
    Process::assertRan(fn ($process) => str_contains($process->command, 'delete deployment external-secrets ')
        && ! str_contains($process->command, 'external-secrets-cert-controller')
        && ! str_contains($process->command, 'external-secrets-webhook'));

    Process::assertRan(fn ($process) => str_contains($process->command, 'delete clusterrole external-secrets-controller')
        && str_contains($process->command, 'clusterrolebinding external-secrets-controller'));

    Process::assertRan(fn ($process) => str_contains($process->command, 'delete clusterrolebinding openbao-auth-delegator'));
    Process::assertRan(fn ($process) => str_contains($process->command, 'delete deployment openbao-backend'));
    Process::assertNotRan(fn ($process) => str_contains($process->command, 'delete pvc openbao-data'));
    Process::assertNotRan(fn ($process) => str_contains($process->command, 'delete secret openbao-bootstrap'));
    Process::assertRan(fn ($process) => str_contains($process->command, 'delete namespace'));

    // The actual point of this test: deleting a CRD cascades to delete every
    // custom resource of that type cluster-wide — including OTHER apps'
    // ExternalSecrets (Forgejo, Stalwart), which have creationPolicy: Owner
    // and would cascade further into deleting those apps' live K8s Secrets.
    // secrets:remove's scope is "remove OpenBao from this environment," not
    // "remove the sync mechanism cluster-wide" — confirmed live 2026-07-31.
    Process::assertNotRan(fn ($process) => str_contains($process->command, 'crd')
        || str_contains($process->command, 'customresourcedefinition')
        || str_contains($process->command, 'external-secrets.io'));
});

test('secrets:remove --purge deletes the PVC and bootstrap secret', function () {
    Process::fake([
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('secrets:remove', ['environment' => 'production', '--force' => true, '--purge' => true, '--no-interaction' => true])
        ->assertExitCode(0);

    Process::assertRan(fn ($process) => str_contains($process->command, 'delete pvc openbao-data'));
    Process::assertRan(fn ($process) => str_contains($process->command, 'delete secret openbao-bootstrap'));
});
