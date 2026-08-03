<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Laravel\Prompts\Prompt;

Prompt::interactive(false);

/** Fakes a fully-synced ExternalSecret poll: refreshTime differs the instant after "before" is read. */
function fakeSyncedExternalSecret(): array
{
    return [
        '*].status}*' => Process::result(output: 'True'),
        '*].reason}*' => Process::result(output: 'SecretSynced'),
        '*refreshTime}*' => Process::sequence([
            Process::result(output: ''),
            Process::result(output: '2026-07-31T00:00:00Z'),
        ]),
    ];
}

test('secrets:wire fails when OpenBao is not deployed', function () {
    Process::fake([
        '*get secret openbao-bootstrap*' => Process::result(output: '', exitCode: 1),
        '*' => Process::result(),
    ]);

    $this->artisan('secrets:wire local --tool=sign --force')
        ->assertExitCode(1)
        ->expectsOutputToContain('OpenBao is not deployed.');
});

test('secrets:wire fails when the database engine is not mounted', function () {
    Process::fake([
        '*get secret openbao-bootstrap*' => Process::result(output: base64_encode('hvs.token')),
        '*port-forward*' => Process::result(output: ''),
        '*' => Process::result(),
    ]);

    Http::fake([
        'localhost:*' => Http::response(['data' => ['secret/' => ['type' => 'kv']]]),
    ]);

    $this->artisan('secrets:wire local --tool=sign --force')
        ->assertExitCode(1)
        ->expectsOutputToContain('database secrets engine is not mounted');
});

test('secrets:wire fails when Vault Kubernetes auth is not configured', function () {
    Process::fake([
        '*get secret openbao-bootstrap*' => Process::result(output: base64_encode('hvs.token')),
        '*port-forward*' => Process::result(output: ''),
        '*' => Process::result(),
    ]);

    Http::fake([
        'localhost:*' => Http::sequence()
            // databaseEngineMounted()
            ->push(['data' => ['database/' => ['type' => 'database']]])
            // kubernetesAuthEnabled() -> no kubernetes/ key
            ->push(['data' => ['token/' => ['type' => 'token']]]),
    ]);

    $this->artisan('secrets:wire local --tool=sign --force')
        ->assertExitCode(1)
        ->expectsOutputToContain('Vault Kubernetes auth is not configured');
});

test('secrets:wire --tool=sign registers a static role, wires the ExternalSecret, waits for sync, and restarts the deployment', function () {
    // fakeSyncedExternalSecret()'s patterns must come BEFORE the catch-all
    // '*' below — Process::fake() matches in array declaration order, and a
    // '*' listed first would swallow the more specific refreshTime/status/
    // reason patterns before they ever get a chance to match.
    Process::fake(array_merge(fakeSyncedExternalSecret(), [
        '*get secret openbao-bootstrap*' => Process::result(output: base64_encode('hvs.token')),
        '*get secret sign-documenso-secrets*' => Process::result(output: base64_encode('db-pw')),
        '*get deployment sign-documenso*' => Process::result(output: 'sign-documenso'),
        '*port-forward*' => Process::result(output: ''),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout restart*' => Process::result(output: 'restarted'),
        '*' => Process::result(),
    ]));

    Http::fake([
        'localhost:*' => Http::sequence()
            // databaseEngineMounted()
            ->push(['data' => ['database/' => ['type' => 'database']]])
            // kubernetesAuthEnabled()
            ->push(['data' => ['kubernetes/' => ['type' => 'kubernetes']]])
            // registerStaticRole() -> POST /v1/database/static-roles/sign_documenso
            ->push([]),
    ]);

    $this->artisan('secrets:wire local --tool=sign --force')
        ->assertExitCode(0)
        ->expectsOutputToContain("Document Signing (Documenso)'s DB password is now rotated by OpenBao every 168h");

    Http::assertSent(fn ($request) => str_contains($request->url(), '/v1/database/static-roles/sign_documenso')
        && ($request['username'] ?? null) === 'sign_documenso'
        && ($request['db_name'] ?? null) === 'plex-postgres');

    Process::assertRan(fn ($process) => str_contains($process->command, 'apply -f'));
    Process::assertRan(fn ($process) => str_contains($process->command, 'externalsecret sign-documenso-secrets-db'));
    Process::assertRan(fn ($process) => str_contains($process->command, 'rollout restart deployment/sign-documenso'));
});

test('secrets:wire --tool=link registers a static role for link_kutt and restarts link-kutt', function () {
    // fakeSyncedExternalSecret()'s patterns must come BEFORE the catch-all
    // '*' below — Process::fake() matches in array declaration order, and a
    // '*' listed first would swallow the more specific refreshTime/status/
    // reason patterns before they ever get a chance to match.
    Process::fake(array_merge(fakeSyncedExternalSecret(), [
        '*get secret openbao-bootstrap*' => Process::result(output: base64_encode('hvs.token')),
        '*get secret link-kutt-secrets*' => Process::result(output: base64_encode('db-pw')),
        '*get deployment link-kutt*' => Process::result(output: 'link-kutt'),
        '*port-forward*' => Process::result(output: ''),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout restart*' => Process::result(output: 'restarted'),
        '*' => Process::result(),
    ]));

    Http::fake([
        'localhost:*' => Http::sequence()
            // databaseEngineMounted()
            ->push(['data' => ['database/' => ['type' => 'database']]])
            // kubernetesAuthEnabled()
            ->push(['data' => ['kubernetes/' => ['type' => 'kubernetes']]])
            // registerStaticRole() -> POST /v1/database/static-roles/link_kutt
            ->push([]),
    ]);

    $this->artisan('secrets:wire local --tool=link --force')
        ->assertExitCode(0)
        ->expectsOutputToContain("Link Management (Kutt)'s DB password is now rotated by OpenBao every 168h");

    Http::assertSent(fn ($request) => str_contains($request->url(), '/v1/database/static-roles/link_kutt')
        && ($request['username'] ?? null) === 'link_kutt'
        && ($request['db_name'] ?? null) === 'plex-postgres');

    Process::assertRan(fn ($process) => str_contains($process->command, 'apply -f'));
    Process::assertRan(fn ($process) => str_contains($process->command, 'externalsecret link-kutt-secrets-db'));
    Process::assertRan(fn ($process) => str_contains($process->command, 'rollout restart deployment/link-kutt'));
});

test('waitForExternalSecretSynced requires status=True, reason=SecretSynced, AND a fresh refreshTime', function () {
    $command = new class extends App\Commands\Secrets\SecretsWireCommand
    {
        public function wait(string $kubectl, string $ns, string $name, ?string $before, int $timeout): bool
        {
            return $this->waitForExternalSecretSynced($kubectl, $ns, $name, $before, $timeout);
        }
    };

    // Never reports Ready — simulates a stuck/failing sync. 2s timeout keeps this fast.
    Process::fake([
        '*].status}*' => Process::result(output: 'False'),
        '*].reason}*' => Process::result(output: 'SecretSyncedError'),
        '*refreshTime}*' => Process::result(output: ''),
        '*' => Process::result(),
    ]);
    expect($command->wait('kubectl', 'larakube-shared', 'x-db', null, 2))->toBeFalse();

    // Ready, but refreshTime is IDENTICAL to before — a stale status left
    // over from a prior sync of the OLD password, not proof of a fresh one.
    Process::fake([
        '*].status}*' => Process::result(output: 'True'),
        '*].reason}*' => Process::result(output: 'SecretSynced'),
        '*refreshTime}*' => Process::result(output: '2026-07-30T22:40:49Z'),
        '*' => Process::result(),
    ]);
    expect($command->wait('kubectl', 'larakube-shared', 'x-db', '2026-07-30T22:40:49Z', 2))->toBeFalse();

    // Ready AND refreshTime has moved on from "before" — genuinely fresh.
    Process::fake([
        '*].status}*' => Process::result(output: 'True'),
        '*].reason}*' => Process::result(output: 'SecretSynced'),
        '*refreshTime}*' => Process::result(output: '2026-07-30T22:45:49Z'),
        '*' => Process::result(),
    ]);
    expect($command->wait('kubectl', 'larakube-shared', 'x-db', '2026-07-30T22:40:49Z', 2))->toBeTrue();
});

test('secrets:wire rejects a tool with no wireable Commons database password', function () {
    Process::fake([
        '*get secret openbao-bootstrap*' => Process::result(output: base64_encode('hvs.token')),
        '*port-forward*' => Process::result(output: ''),
        '*' => Process::result(),
    ]);

    Http::fake([
        'localhost:*' => Http::sequence()
            ->push(['data' => ['database/' => ['type' => 'database']]])
            ->push(['data' => ['kubernetes/' => ['type' => 'kubernetes']]]),
    ]);

    $this->artisan('secrets:wire local --tool=monitor --force')
        ->assertExitCode(1)
        ->expectsOutputToContain('does not have a Commons database password OpenBao can rotate');
});

test('secrets:wire rejects a tool that is not installed', function () {
    Process::fake([
        '*get secret openbao-bootstrap*' => Process::result(output: base64_encode('hvs.token')),
        '*get deployment sign-documenso*' => Process::result(output: '', exitCode: 1),
        '*port-forward*' => Process::result(output: ''),
        '*' => Process::result(),
    ]);

    Http::fake([
        'localhost:*' => Http::sequence()
            ->push(['data' => ['database/' => ['type' => 'database']]])
            ->push(['data' => ['kubernetes/' => ['type' => 'kubernetes']]]),
    ]);

    $this->artisan('secrets:wire local --tool=sign --force')
        ->assertExitCode(1)
        ->expectsOutputToContain('is not installed');
});

test('secrets:wire --all wires every installed DB-rotatable tool and skips uninstalled ones', function () {
    // fakeSyncedExternalSecret()'s patterns must come BEFORE the catch-all
    // '*' below — Process::fake() matches in array declaration order, and a
    // '*' listed first would swallow the more specific refreshTime/status/
    // reason patterns before they ever get a chance to match.
    Process::fake(array_merge(fakeSyncedExternalSecret(), [
        '*get secret openbao-bootstrap*' => Process::result(output: base64_encode('hvs.token')),
        '*get secret sign-documenso-secrets*' => Process::result(output: base64_encode('db-pw')),
        '*get deployment sign-documenso*' => Process::result(output: 'sign-documenso'),
        '*get deployment record-sendrec*' => Process::result(output: '', exitCode: 1),
        '*get deployment sso-zitadel*' => Process::result(output: '', exitCode: 1),
        '*port-forward*' => Process::result(output: ''),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout restart*' => Process::result(output: 'restarted'),
        '*' => Process::result(),
    ]));

    Http::fake([
        'localhost:*' => Http::sequence()
            ->push(['data' => ['database/' => ['type' => 'database']]])
            ->push(['data' => ['kubernetes/' => ['type' => 'kubernetes']]])
            ->push([]),
    ]);

    $this->artisan('secrets:wire local --all --force')
        ->assertExitCode(0)
        ->expectsOutputToContain("Document Signing (Documenso)'s DB password is now rotated by OpenBao every 168h");

    Process::assertRan(fn ($process) => str_contains($process->command, 'rollout restart deployment/sign-documenso'));
    Process::assertNotRan(fn ($process) => str_contains($process->command, 'rollout restart deployment/record-sendrec'));
});

test('secrets:wire rejects Drive (oCIS has no Commons database password to rotate)', function () {
    Process::fake([
        '*get secret openbao-bootstrap*' => Process::result(output: base64_encode('hvs.token')),
        '*get deployment drive-ocis*' => Process::result(output: 'drive-ocis'),
        '*get secret drive-secrets*' => Process::result(output: '', exitCode: 1),
        '*port-forward*' => Process::result(output: ''),
        '*' => Process::result(),
    ]);

    Http::fake([
        'localhost:*' => Http::sequence()
            ->push(['data' => ['database/' => ['type' => 'database']]])
            ->push(['data' => ['kubernetes/' => ['type' => 'kubernetes']]]),
    ]);

    $this->artisan('secrets:wire local --tool=drive --force')
        ->assertExitCode(1)
        ->expectsOutputToContain("'drive' does not have a Commons database password OpenBao can rotate.");

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/v1/database/static-roles/drive'));
});

test('secrets:wire requires --tool or --all when it cannot prompt', function () {
    Process::fake([
        '*get secret openbao-bootstrap*' => Process::result(output: base64_encode('hvs.token')),
        '*get deployment sign-documenso*' => Process::result(output: 'sign-documenso'),
        '*get deployment record-sendrec*' => Process::result(output: '', exitCode: 1),
        '*get deployment sso-zitadel*' => Process::result(output: '', exitCode: 1),
        '*port-forward*' => Process::result(output: ''),
        '*' => Process::result(),
    ]);

    Http::fake([
        'localhost:*' => Http::sequence()
            ->push(['data' => ['database/' => ['type' => 'database']]])
            ->push(['data' => ['kubernetes/' => ['type' => 'kubernetes']]]),
    ]);

    $this->artisan('secrets:wire local --no-interaction')->run();
})->throws(App\Exceptions\MissingFlagException::class, 'Missing required --tool');
