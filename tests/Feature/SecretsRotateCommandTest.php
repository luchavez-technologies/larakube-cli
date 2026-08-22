<?php

use Illuminate\Support\Facades\Process;
use Laravel\Prompts\Prompt;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

Prompt::interactive(false);

afterEach(function (): void {
    MockClient::destroyGlobal();
});

function fakeSyncedRotateExternalSecret(): array
{
    return [
        '*].status}*' => Process::result(output: 'True'),
        '*].reason}*' => Process::result(output: 'SecretSynced'),
        '*refreshTime}*' => Process::sequence([
            Process::result(output: ''),
            Process::result(output: '2026-08-12T00:00:00Z'),
        ]),
    ];
}

test('secrets:rotate fails when OpenBao is not deployed', function (): void {
    Process::fake([
        '*get secret openbao-bootstrap*' => Process::result(output: '', exitCode: 1),
        '*' => Process::result(),
    ]);

    $this->artisan('secrets:rotate local --tool=git --force')
        ->assertExitCode(1)
        ->expectsOutputToContain('OpenBao is not deployed.');
});

test('secrets:rotate fails when the database engine is not mounted', function (): void {
    Process::fake([
        '*get secret openbao-bootstrap*' => Process::result(output: base64_encode('hvs.token')),
        '*port-forward*' => Process::result(output: ''),
        '*' => Process::result(),
    ]);

    Saloon::fake([MockResponse::make(['data' => ['secret/' => ['type' => 'kv']]])]);

    $this->artisan('secrets:rotate local --tool=git --force')
        ->assertExitCode(1)
        ->expectsOutputToContain('database secrets engine is not mounted');
});

test('secrets:rotate rotates an OpenBao-wired tool immediately', function (): void {
    Process::fake(array_merge(fakeSyncedRotateExternalSecret(), [
        '*get secret openbao-bootstrap*' => Process::result(output: base64_encode('hvs.token')),
        '*get deployment*' => Process::result(output: 'forgejo'),
        '*port-forward*' => Process::result(output: ''),
        '*annotate externalsecret*' => Process::result(output: 'annotated'),
        '*rollout restart*' => Process::result(output: 'restarted'),
        '*' => Process::result(),
    ]));

    Saloon::fake([
        // databaseEngineMounted()
        MockResponse::make(['data' => ['database/' => ['type' => 'database']]]),
        // staticRoleExists('forgejo') -> GET /v1/database/static-roles/forgejo
        MockResponse::make(['data' => ['username' => 'forgejo']]),
        // rotateStaticRole('forgejo') -> POST /v1/database/rotate-role/forgejo
        MockResponse::make([]),
    ]);

    $this->artisan('secrets:rotate local --tool=git --force')
        ->assertExitCode(0);

    Saloon::assertSent(fn ($request) => str_contains($request->resolveEndpoint(), '/v1/database/rotate-role/forgejo'));
});

test('secrets:rotate rejects an un-wired tool', function (): void {
    Process::fake([
        '*get secret openbao-bootstrap*' => Process::result(output: base64_encode('hvs.token')),
        '*get deployment*' => Process::result(output: 'forgejo'),
        '*port-forward*' => Process::result(output: ''),
        '*' => Process::result(),
    ]);

    Saloon::fake([
        // databaseEngineMounted()
        MockResponse::make(['data' => ['database/' => ['type' => 'database']]]),
        // staticRoleExists('forgejo') -> GET /v1/database/static-roles/forgejo (404)
        MockResponse::make([], 404),
        // staticRoleExists('tenant-forgejo') -> GET /v1/database/static-roles/tenant-forgejo (404)
        MockResponse::make([], 404),
    ]);

    $this->artisan('secrets:rotate local --tool=git --force')
        ->assertExitCode(1);
});
