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

test('secrets:rotate --tool=X with no --domain never resolves the instance against an unrelated SECRETS registry entry', function (): void {
    // Regression: resolveInstanceForDomain() was called with ClusterTool::
    // SECRETS regardless of which tool --tool= actually named, and
    // unconditionally even when --domain was empty (secrets:wire correctly
    // skips it entirely in that case). resolveInstanceTargetsForDomain()
    // filters the registry by the TOOL passed in — so this looked up
    // SECRETS' own registered instance ('secrets-own-instance' here) and
    // applied it to Mail/Stalwart's dbSecretRef()/deploymentName(), which
    // suffixes onto the secret/deployment name whenever the instance is
    // non-empty. Confirmed live 2026-08-23: real, installed, working
    // Stalwart was reported "not installed at this instance" as a direct
    // result. The registry below deliberately has SECRETS registered under
    // an instance and MAIL registered under none, so the old bug and the
    // fix produce different (wrong vs. correct) target names.
    Process::fake(array_merge(fakeSyncedRotateExternalSecret(), [
        '*get secret openbao-bootstrap*' => Process::result(output: base64_encode('hvs.token')),
        '*get secret larakube-tools-registry*' => Process::result(output: base64_encode(json_encode([
            ['tool' => 'secrets', 'host' => 'secrets.example.com', 'instance' => 'secrets-own-instance'],
        ]))),
        // Strict: only the BARE deployment name exists. Anything with an
        // instance suffix appended (the old code's behavior) must resolve
        // as "not installed" — that's what actually distinguishes the bug
        // from the fix here, not just whether a request was sent.
        '*get deployment stalwart -n*' => Process::result(output: 'stalwart'),
        '*get deployment stalwart-*' => Process::result(output: '', exitCode: 1),
        '*port-forward*' => Process::result(output: ''),
        '*annotate externalsecret*' => Process::result(output: 'annotated'),
        '*rollout restart*' => Process::result(output: 'restarted'),
        '*' => Process::result(),
    ]));

    Saloon::fake([
        MockResponse::make(['data' => ['database/' => ['type' => 'database']]]),
        // staticRoleExists('stalwart') — the BARE name, proving no instance
        // suffix leaked in from SECRETS' unrelated registry entry.
        MockResponse::make(['data' => ['username' => 'stalwart']]),
        MockResponse::make([]),
    ]);

    $this->artisan('secrets:rotate local --tool=mail --force')
        ->assertExitCode(0);

    Saloon::assertSent(fn ($request) => str_contains($request->resolveEndpoint(), '/v1/database/static-roles/stalwart')
        && ! str_contains($request->resolveEndpoint(), 'secrets-own-instance'));
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
