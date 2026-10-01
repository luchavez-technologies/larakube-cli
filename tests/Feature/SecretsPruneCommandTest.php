<?php

use App\Http\Integrations\OpenBao\Requests\DynamicNoBodyRequest;
use Illuminate\Support\Facades\Process;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

afterEach(function (): void {
    MockClient::destroyGlobal();
});

/** @param  list<string>  $pgRoles */
function pruneProcessFakes(array $pgRoles, int $psqlExit = 0): void
{
    Process::fake([
        '*get secret openbao-secrets-secrets-example-com*' => Process::result(output: base64_encode('hvs.token')),
        '*psql*' => Process::result(output: implode("\n", $pgRoles)."\n", exitCode: $psqlExit),
        '*port-forward*' => Process::result(output: ''),
        '*' => Process::result(),
    ]);
}

function staticRole(string $username, string $dbName = 'plex-postgres'): MockResponse
{
    return MockResponse::make(['data' => ['username' => $username, 'db_name' => $dbName]]);
}

function deleteSent(string $role): Closure
{
    return fn ($request): bool => $request instanceof DynamicNoBodyRequest
        && $request->getMethod()->value === 'DELETE'
        && str_ends_with($request->resolveEndpoint(), "/static-roles/{$role}");
}

test('secrets:prune fails when OpenBao is not deployed', function (): void {
    openBaoRegistered();
    Process::fake([
        '*get secret openbao-secrets-secrets-example-com*' => Process::result(output: '', exitCode: 1),
        '*' => Process::result(),
    ]);

    $this->artisan('secrets:prune local --force')
        ->assertExitCode(1)
        ->expectsOutputToContain('OpenBao is not deployed');
});

test('secrets:prune deletes only the static roles whose Postgres role is gone', function (): void {
    openBaoRegistered();
    pruneProcessFakes(['postgres', 'forgejo_git_luchtech_dev']);

    Saloon::fake([
        MockResponse::make(['data' => ['keys' => ['forgejo_git_luchtech_dev', 'grafana']]]),
        staticRole('forgejo_git_luchtech_dev'),
        staticRole('grafana'),
        MockResponse::make([]),
    ]);

    $this->artisan('secrets:prune local --force')->assertExitCode(0);

    Saloon::assertSent(deleteSent('grafana'));
    Saloon::assertNotSent(deleteSent('forgejo_git_luchtech_dev'));
});

test('secrets:prune --dry-run lists the dead roles and deletes nothing', function (): void {
    openBaoRegistered();
    pruneProcessFakes(['postgres']);

    Saloon::fake([
        MockResponse::make(['data' => ['keys' => ['grafana']]]),
        staticRole('grafana'),
    ]);

    $this->artisan('secrets:prune local --dry-run')
        ->assertExitCode(0)
        ->expectsOutputToContain('Dry run');

    Saloon::assertNotSent(deleteSent('grafana'));
});

test('secrets:prune deletes nothing when the Postgres roles cannot be listed', function (): void {
    openBaoRegistered();
    pruneProcessFakes([], psqlExit: 1);

    Saloon::fake([
        MockResponse::make(['data' => ['keys' => ['grafana']]]),
        staticRole('grafana'),
    ]);

    $this->artisan('secrets:prune local --force')
        ->assertExitCode(1)
        ->expectsOutputToContain('Nothing was changed');

    Saloon::assertNotSent(deleteSent('grafana'));
});

test('secrets:prune leaves roles that are not on the Commons Postgres alone', function (): void {
    openBaoRegistered();
    pruneProcessFakes(['postgres']);

    Saloon::fake([
        MockResponse::make(['data' => ['keys' => ['legacy_mysql_role']]]),
        staticRole('legacy_mysql_role', 'plex-mysql'),
    ]);

    $this->artisan('secrets:prune local --force')
        ->assertExitCode(0)
        ->expectsOutputToContain('Nothing to prune');

    Saloon::assertNotSent(deleteSent('legacy_mysql_role'));
});

test('secrets:prune reports success without deleting when every static role is alive', function (): void {
    openBaoRegistered();
    pruneProcessFakes(['postgres', 'zitadel']);

    Saloon::fake([
        MockResponse::make(['data' => ['keys' => ['zitadel']]]),
        staticRole('zitadel'),
    ]);

    $this->artisan('secrets:prune local --force')
        ->assertExitCode(0)
        ->expectsOutputToContain('Nothing to prune');

    Saloon::assertNotSent(deleteSent('zitadel'));
});
