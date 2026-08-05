<?php

use App\Traits\InteractsWithToolRegistry;
use Illuminate\Support\Facades\Process;

uses(InteractsWithToolRegistry::class);

test('secrets:unwire is registered and unwires OpenBao DB rotation for a tool', function () {
    Process::fake([
        '*get secret openbao-bootstrap*' => Process::result(output: base64_encode('root-token')),
        '*get secret sign-documenso-secrets*' => Process::result(output: 'found'),
        '*exec deploy/openbao-backend*' => Process::result(output: '{"data":{"rotation_period":"86400s"}}'),
        '*delete externalsecret*' => Process::result(output: 'deleted'),
        '*delete vaultdynamicsecret*' => Process::result(output: 'deleted'),
        '*bao delete database/static-roles*' => Process::result(output: 'deleted'),
    ]);

    $this->artisan('secrets:unwire local --tool=sign --force')
        ->assertExitCode(0)
        ->expectsOutputToContain('DB password is now static');
});

test('secrets:unwire errors when OpenBao is not deployed', function () {
    Process::fake([
        '*get secret openbao-bootstrap*' => Process::result(output: '', exitCode: 1),
    ]);

    $this->artisan('secrets:unwire local --tool=sign --force')
        ->assertExitCode(1)
        ->expectsOutputToContain('OpenBao is not deployed on this cluster');
});
