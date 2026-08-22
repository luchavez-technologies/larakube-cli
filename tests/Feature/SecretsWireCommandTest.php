<?php

use App\Commands\Secrets\SecretsWireCommand;
use App\Exceptions\MissingFlagException;
use App\Http\Integrations\OpenBao\Requests\DynamicRequest;
use Illuminate\Support\Facades\Process;
use Laravel\Prompts\Prompt;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

Prompt::interactive(false);

afterEach(function (): void {
    MockClient::destroyGlobal();
});

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

test('secrets:wire fails when OpenBao is not deployed', function (): void {
    Process::fake([
        '*get secret openbao-bootstrap*' => Process::result(output: '', exitCode: 1),
        '*' => Process::result(),
    ]);

    $this->artisan('secrets:wire local --tool=sign --force')
        ->assertExitCode(1)
        ->expectsOutputToContain('OpenBao is not deployed.');
});

test('secrets:wire fails when the database engine is not mounted', function (): void {
    Process::fake([
        '*get secret openbao-bootstrap*' => Process::result(output: base64_encode('hvs.token')),
        '*port-forward*' => Process::result(output: ''),
        '*' => Process::result(),
    ]);

    Saloon::fake([
        MockResponse::make(['data' => ['secret/' => ['type' => 'kv']]]),
    ]);

    $this->artisan('secrets:wire local --tool=sign --force')
        ->assertExitCode(1)
        ->expectsOutputToContain('database secrets engine is not mounted');
});

test('secrets:wire fails when Vault Kubernetes auth is not configured', function (): void {
    Process::fake([
        '*get secret openbao-bootstrap*' => Process::result(output: base64_encode('hvs.token')),
        '*port-forward*' => Process::result(output: ''),
        '*' => Process::result(),
    ]);

    Saloon::fake([
        // databaseEngineMounted()
        MockResponse::make(['data' => ['database/' => ['type' => 'database']]]),
        // kubernetesAuthEnabled() -> no kubernetes/ key
        MockResponse::make(['data' => ['token/' => ['type' => 'token']]]),
    ]);

    $this->artisan('secrets:wire local --tool=sign --force')
        ->assertExitCode(1)
        ->expectsOutputToContain('Vault Kubernetes auth is not configured');
});

test('secrets:wire --tool=sign registers a static role, wires the ExternalSecret, waits for sync, and restarts the deployment', function (): void {
    // fakeSyncedExternalSecret()'s patterns must come BEFORE the catch-all
    // '*' below — Process::fake() matches in array declaration order, and a
    // '*' listed first would swallow the more specific refreshTime/status/
    // reason patterns before they ever get a chance to match.
    Process::fake(array_merge(fakeSyncedExternalSecret(), [
        '*get secret openbao-bootstrap*' => Process::result(output: base64_encode('hvs.token')),
        '*get secret sign-secrets*' => Process::result(output: base64_encode('db-pw')),
        '*get deployment sign-documenso*' => Process::result(output: 'sign-documenso'),
        '*port-forward*' => Process::result(output: ''),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout restart*' => Process::result(output: 'restarted'),
        '*' => Process::result(),
    ]));

    Saloon::fake([
        // databaseEngineMounted()
        MockResponse::make(['data' => ['database/' => ['type' => 'database']]]),
        // kubernetesAuthEnabled()
        MockResponse::make(['data' => ['kubernetes/' => ['type' => 'kubernetes']]]),
        // registerStaticRole() -> POST /v1/database/static-roles/sign_documenso
        MockResponse::make([]),
    ]);

    $this->artisan('secrets:wire local --tool=sign --force')
        ->assertExitCode(0)
        ->expectsOutputToContain("Document Signing (Documenso)'s DB password is now rotated by OpenBao every 168h");

    Saloon::assertSent(fn ($request) => $request instanceof DynamicRequest
        && str_contains($request->resolveEndpoint(), '/v1/database/static-roles/sign_documenso')
        && ($request->body()->get('username') ?? null) === 'sign_documenso'
        && ($request->body()->get('db_name') ?? null) === 'plex-postgres');

    Process::assertRan(fn ($process) => str_contains($process->command, 'apply -f'));
    Process::assertRan(fn ($process) => str_contains($process->command, 'externalsecret sign-secrets-db'));
    Process::assertRan(fn ($process) => str_contains($process->command, 'rollout restart deployment/sign-documenso'));
});

test('secrets:wire --tool=link registers a static role for link_kutt and restarts link-kutt', function (): void {
    // fakeSyncedExternalSecret()'s patterns must come BEFORE the catch-all
    // '*' below — Process::fake() matches in array declaration order, and a
    // '*' listed first would swallow the more specific refreshTime/status/
    // reason patterns before they ever get a chance to match.
    Process::fake(array_merge(fakeSyncedExternalSecret(), [
        '*get secret openbao-bootstrap*' => Process::result(output: base64_encode('hvs.token')),
        '*get secret link-secrets*' => Process::result(output: base64_encode('db-pw')),
        '*get deployment link-kutt*' => Process::result(output: 'link-kutt'),
        '*port-forward*' => Process::result(output: ''),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout restart*' => Process::result(output: 'restarted'),
        '*' => Process::result(),
    ]));

    Saloon::fake([
        // databaseEngineMounted()
        MockResponse::make(['data' => ['database/' => ['type' => 'database']]]),
        // kubernetesAuthEnabled()
        MockResponse::make(['data' => ['kubernetes/' => ['type' => 'kubernetes']]]),
        // registerStaticRole() -> POST /v1/database/static-roles/link_kutt
        MockResponse::make([]),
    ]);

    $this->artisan('secrets:wire local --tool=link --force')
        ->assertExitCode(0)
        ->expectsOutputToContain("Link Management (Kutt)'s DB password is now rotated by OpenBao every 168h");

    Saloon::assertSent(fn ($request) => $request instanceof DynamicRequest
        && str_contains($request->resolveEndpoint(), '/v1/database/static-roles/link_kutt')
        && ($request->body()->get('username') ?? null) === 'link_kutt'
        && ($request->body()->get('db_name') ?? null) === 'plex-postgres');

    Process::assertRan(fn ($process) => str_contains($process->command, 'apply -f'));
    Process::assertRan(fn ($process) => str_contains($process->command, 'externalsecret link-secrets-db'));
    Process::assertRan(fn ($process) => str_contains($process->command, 'rollout restart deployment/link-kutt'));
});

test('secrets:wire --tool=support registers a static role for support_chatwoot and restarts support-chatwoot', function (): void {
    Process::fake(array_merge(fakeSyncedExternalSecret(), [
        '*get secret openbao-bootstrap*' => Process::result(output: base64_encode('hvs.token')),
        '*get secret support-secrets*' => Process::result(output: base64_encode('db-pw')),
        '*get deployment support-chatwoot*' => Process::result(output: 'support-chatwoot'),
        '*port-forward*' => Process::result(output: ''),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout restart*' => Process::result(output: 'restarted'),
        '*' => Process::result(),
    ]));

    Saloon::fake([
        MockResponse::make(['data' => ['database/' => ['type' => 'database']]]),
        MockResponse::make(['data' => ['kubernetes/' => ['type' => 'kubernetes']]]),
        MockResponse::make([]),
    ]);

    $this->artisan('secrets:wire local --tool=support --force')
        ->assertExitCode(0)
        ->expectsOutputToContain("Customer Support (Chatwoot)'s DB password is now rotated by OpenBao every 168h");

    Saloon::assertSent(fn ($request) => $request instanceof DynamicRequest
        && str_contains($request->resolveEndpoint(), '/v1/database/static-roles/support_chatwoot')
        && ($request->body()->get('username') ?? null) === 'support_chatwoot'
        && ($request->body()->get('db_name') ?? null) === 'plex-postgres');

    Process::assertRan(fn ($process) => str_contains($process->command, 'apply -f'));
    Process::assertRan(fn ($process) => str_contains($process->command, 'externalsecret support-secrets-db'));
    Process::assertRan(fn ($process) => str_contains($process->command, 'rollout restart deployment/support-chatwoot'));
});

test('secrets:wire --tool=tasks registers a static role for tasks_planka and restarts tasks-planka', function (): void {
    Process::fake(array_merge(fakeSyncedExternalSecret(), [
        '*get secret openbao-bootstrap*' => Process::result(output: base64_encode('hvs.token')),
        '*get secret tasks-planka-secrets*' => Process::result(output: base64_encode('db-pw')),
        '*get deployment tasks-planka*' => Process::result(output: 'tasks-planka'),
        '*port-forward*' => Process::result(output: ''),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout restart*' => Process::result(output: 'restarted'),
        '*' => Process::result(),
    ]));

    Saloon::fake([
        MockResponse::make(['data' => ['database/' => ['type' => 'database']]]),
        MockResponse::make(['data' => ['kubernetes/' => ['type' => 'kubernetes']]]),
        MockResponse::make([]),
    ]);

    $this->artisan('secrets:wire local --tool=tasks --force')
        ->assertExitCode(0)
        ->expectsOutputToContain("Project Management (Planka)'s DB password is now rotated by OpenBao every 168h");

    Saloon::assertSent(fn ($request) => $request instanceof DynamicRequest
        && str_contains($request->resolveEndpoint(), '/v1/database/static-roles/tasks_planka')
        && ($request->body()->get('username') ?? null) === 'tasks_planka'
        && ($request->body()->get('db_name') ?? null) === 'plex-postgres');

    Process::assertRan(fn ($process) => str_contains($process->command, 'apply -f'));
    Process::assertRan(fn ($process) => str_contains($process->command, 'externalsecret tasks-planka-secrets-db'));
    Process::assertRan(fn ($process) => str_contains($process->command, 'rollout restart deployment/tasks-planka'));
});

test('secrets:wire --tool=analytics refuses because Umami is not yet shipped', function (): void {
    Process::fake([
        '*get secret openbao-bootstrap*' => Process::result(output: base64_encode('hvs.token')),
        '*port-forward*' => Process::result(output: ''),
        '*' => Process::result(output: ''),
    ]);

    Saloon::fake([
        // databaseEngineMounted()
        MockResponse::make(['data' => ['database/' => ['type' => 'database']]]),
        // kubernetesAuthEnabled()
        MockResponse::make(['data' => ['kubernetes/' => ['type' => 'kubernetes']]]),
    ]);

    $this->artisan('secrets:wire local --tool=analytics --force')
        ->assertExitCode(1)
        ->expectsOutputToContain('Web Analytics (Umami) is not yet shipped');

    Process::assertNotRan(fn ($process) => str_contains($process->command, 'apply -f'));
});

test('waitForExternalSecretSynced requires status=True, reason=SecretSynced, AND a fresh refreshTime', function (): void {
    $command = new class extends SecretsWireCommand
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

test('secrets:wire rejects a tool with no wireable Commons database password', function (): void {
    // Desk (FreeScout) has a Commons database (HasCommonsDatabases) but no
    // simple single-key password to hand OpenBao (no HasDbSecretRef) — the
    // other reason a tool can be rejected here, distinct from Drive's "no
    // Commons DB at all" case covered separately below. Monitor used to be
    // this test's example until it grew a real Commons Postgres tenant for
    // Grafana (2026-08-18) — see MonitorInitCommandTest's allocation test.
    Process::fake([
        '*get secret openbao-bootstrap*' => Process::result(output: base64_encode('hvs.token')),
        '*get deployment desk-freescout*' => Process::result(output: 'desk-freescout'),
        '*port-forward*' => Process::result(output: ''),
        '*' => Process::result(),
    ]);

    Saloon::fake([
        MockResponse::make(['data' => ['database/' => ['type' => 'database']]]),
        MockResponse::make(['data' => ['kubernetes/' => ['type' => 'kubernetes']]]),
    ]);

    $this->artisan('secrets:wire local --tool=desk --force')
        ->assertExitCode(1)
        ->expectsOutputToContain('does not have a Commons database password OpenBao can rotate');
});

test('secrets:wire rejects a tool that is not installed', function (): void {
    Process::fake([
        '*get secret openbao-bootstrap*' => Process::result(output: base64_encode('hvs.token')),
        '*get deployment sign-documenso*' => Process::result(output: '', exitCode: 1),
        '*port-forward*' => Process::result(output: ''),
        '*' => Process::result(),
    ]);

    Saloon::fake([
        MockResponse::make(['data' => ['database/' => ['type' => 'database']]]),
        MockResponse::make(['data' => ['kubernetes/' => ['type' => 'kubernetes']]]),
    ]);

    $this->artisan('secrets:wire local --tool=sign --force')
        ->assertExitCode(1)
        ->expectsOutputToContain('is not installed');
});

test('secrets:wire --all wires every installed DB-rotatable tool and skips uninstalled ones', function (): void {
    // fakeSyncedExternalSecret()'s patterns must come BEFORE the catch-all
    // '*' below — Process::fake() matches in array declaration order, and a
    // '*' listed first would swallow the more specific refreshTime/status/
    // reason patterns before they ever get a chance to match.
    Process::fake(array_merge(fakeSyncedExternalSecret(), [
        '*get secret openbao-bootstrap*' => Process::result(output: base64_encode('hvs.token')),
        '*get secret sign-secrets*' => Process::result(output: base64_encode('db-pw')),
        '*get deployment sign-documenso*' => Process::result(output: 'sign-documenso'),
        '*get deployment record-sendrec*' => Process::result(output: '', exitCode: 1),
        '*get deployment sso-zitadel*' => Process::result(output: '', exitCode: 1),
        '*port-forward*' => Process::result(output: ''),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout restart*' => Process::result(output: 'restarted'),
        '*' => Process::result(),
    ]));

    Saloon::fake([
        MockResponse::make(['data' => ['database/' => ['type' => 'database']]]),
        MockResponse::make(['data' => ['kubernetes/' => ['type' => 'kubernetes']]]),
        MockResponse::make([]),
    ]);

    $this->artisan('secrets:wire local --all --force')
        ->assertExitCode(0)
        ->expectsOutputToContain("Document Signing (Documenso)'s DB password is now rotated by OpenBao every 168h");

    Process::assertRan(fn ($process) => str_contains($process->command, 'rollout restart deployment/sign-documenso'));
    Process::assertNotRan(fn ($process) => str_contains($process->command, 'rollout restart deployment/record-sendrec'));
});

test('secrets:wire rejects Drive (oCIS has no Commons database password to rotate)', function (): void {
    Process::fake([
        '*get secret openbao-bootstrap*' => Process::result(output: base64_encode('hvs.token')),
        '*get deployment drive-ocis*' => Process::result(output: 'drive-ocis'),
        '*get secret drive-secrets*' => Process::result(output: '', exitCode: 1),
        '*port-forward*' => Process::result(output: ''),
        '*' => Process::result(),
    ]);

    Saloon::fake([
        MockResponse::make(['data' => ['database/' => ['type' => 'database']]]),
        MockResponse::make(['data' => ['kubernetes/' => ['type' => 'kubernetes']]]),
    ]);

    $this->artisan('secrets:wire local --tool=drive --force')
        ->assertExitCode(1)
        ->expectsOutputToContain("'drive' does not have a Commons database password OpenBao can rotate.");

    Saloon::assertNotSent(fn ($request) => str_contains($request->resolveEndpoint(), '/v1/database/static-roles/drive'));
});

test('secrets:wire --tool=data --engine=pocketbase reports no wireable database instead of grabbing Directus\'s secret ref', function (): void {
    // Regression test for the concrete bug this overhaul exists to fix:
    // dbSecretRef()/commonsDatabases() called with NO engine used to always
    // resolve to Directus's shape (the guard only fires for an EXPLICIT
    // 'pocketbase' engine) — so a PocketBase-only instance previously got
    // handed Directus's db-password secret ref/tenant name.
    Process::fake([
        '*get secret openbao-bootstrap*' => Process::result(output: base64_encode('hvs.token')),
        '*get deployment data-pocketbase*' => Process::result(output: 'data-pocketbase'),
        '*port-forward*' => Process::result(output: ''),
        '*' => Process::result(),
    ]);

    Saloon::fake([
        MockResponse::make(['data' => ['database/' => ['type' => 'database']]]),
        MockResponse::make(['data' => ['kubernetes/' => ['type' => 'kubernetes']]]),
    ]);

    $this->artisan('secrets:wire local --tool=data --engine=pocketbase --force')
        ->assertExitCode(1)
        ->expectsOutputToContain('is not installed (or has no wireable Commons database) at this instance');

    Saloon::assertNotSent(fn ($request) => str_contains($request->resolveEndpoint(), '/v1/database/static-roles/data_directus'));
});

test('secrets:wire --tool=data never trusts a stale registry engine hint over what is actually live', function (): void {
    // Same bug, from the other direction: with no --engine= flag, a stale
    // registry entry (still says 'directus' after a switch to pocketbase)
    // must NOT be trusted blindly — resolveInstanceEngine() confirms the
    // hint against a live Deployment first, and falls back to live-probing
    // every engine when it doesn't check out, exactly like
    // DataRemoveCommand's existing "registry is a hint, not authoritative"
    // discipline.
    Process::fake(array_merge(fakeSyncedExternalSecret(), [
        '*get secret openbao-bootstrap*' => Process::result(output: base64_encode('hvs.token')),
        '*get secret larakube-tools-registry*' => Process::result(output: base64_encode(json_encode([
            ['tool' => 'data', 'host' => 'data.example.com', 'instance' => 'main', 'engine' => 'directus'],
        ]))),
        '*get deployment data-pocketbase*' => Process::result(output: 'data-pocketbase'),
        '*get deployment data-directus*' => Process::result(output: '', exitCode: 1),
        '*get secret data-secrets*' => Process::result(output: base64_encode('db-pw')),
        '*port-forward*' => Process::result(output: ''),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout restart*' => Process::result(output: 'restarted'),
        '*' => Process::result(),
    ]));

    Saloon::fake([
        MockResponse::make(['data' => ['database/' => ['type' => 'database']]]),
        MockResponse::make(['data' => ['kubernetes/' => ['type' => 'kubernetes']]]),
    ]);

    // The registry says 'directus', but only data-pocketbase is actually
    // live — resolveInstanceEngine() must not trust the stale hint.
    $this->artisan('secrets:wire local --tool=data --force')
        ->assertExitCode(1)
        ->expectsOutputToContain('is not installed (or has no wireable Commons database) at this instance');
});

test('secrets:wire requires --tool or --all when it cannot prompt', function (): void {
    Process::fake([
        '*get secret openbao-bootstrap*' => Process::result(output: base64_encode('hvs.token')),
        '*get deployment sign-documenso*' => Process::result(output: 'sign-documenso'),
        '*get deployment record-sendrec*' => Process::result(output: '', exitCode: 1),
        '*get deployment sso-zitadel*' => Process::result(output: '', exitCode: 1),
        '*port-forward*' => Process::result(output: ''),
        '*' => Process::result(),
    ]);

    Saloon::fake([
        MockResponse::make(['data' => ['database/' => ['type' => 'database']]]),
        MockResponse::make(['data' => ['kubernetes/' => ['type' => 'kubernetes']]]),
    ]);

    $this->artisan('secrets:wire local --no-interaction')->run();
})->throws(MissingFlagException::class, 'Missing required --tool');

test('secrets:wire --tool=mail registers a static role for stalwart and restarts stalwart deployment', function (): void {
    Process::fake(array_merge(fakeSyncedExternalSecret(), [
        '*get secret openbao-bootstrap*' => Process::result(output: base64_encode('hvs.token')),
        '*get secret stalwart*' => Process::result(output: base64_encode('store-pw')),
        '*get deployment stalwart*' => Process::result(output: 'stalwart'),
        '*port-forward*' => Process::result(output: ''),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout restart*' => Process::result(output: 'restarted'),
        '*' => Process::result(),
    ]));

    Saloon::fake([
        MockResponse::make(['data' => ['database/' => ['type' => 'database']]]),
        MockResponse::make(['data' => ['kubernetes/' => ['type' => 'kubernetes']]]),
        MockResponse::make([]),
    ]);

    $this->artisan('secrets:wire local --tool=mail --force')
        ->assertExitCode(0)
        ->expectsOutputToContain("Mail Server (Stalwart)'s DB password is now rotated by OpenBao every 168h");

    Saloon::assertSent(fn ($request) => $request instanceof DynamicRequest
        && str_contains($request->resolveEndpoint(), '/v1/database/static-roles/stalwart')
        && ($request->body()->get('username') ?? null) === 'stalwart'
        && ($request->body()->get('db_name') ?? null) === 'plex-postgres');

    Process::assertRan(fn ($process) => str_contains($process->command, 'apply -f'));
    Process::assertRan(fn ($process) => str_contains($process->command, 'externalsecret stalwart-db'));
    Process::assertRan(fn ($process) => str_contains($process->command, 'rollout restart deployment/stalwart'));
});

test('secrets:wire --tool=passwords registers a static role for vaultwarden with templated database URL and restarts vaultwarden deployment', function (): void {
    Process::fake(array_merge(fakeSyncedExternalSecret(), [
        '*get secret openbao-bootstrap*' => Process::result(output: base64_encode('hvs.token')),
        '*get secret vault-secrets*' => Process::result(output: base64_encode('postgresql://vaultwarden:pw@postgres:5432/vaultwarden')),
        '*get deployment vaultwarden*' => Process::result(output: 'vaultwarden'),
        '*port-forward*' => Process::result(output: ''),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout restart*' => Process::result(output: 'restarted'),
        '*' => Process::result(),
    ]));

    Saloon::fake([
        MockResponse::make(['data' => ['database/' => ['type' => 'database']]]),
        MockResponse::make(['data' => ['kubernetes/' => ['type' => 'kubernetes']]]),
        MockResponse::make([]),
    ]);

    $this->artisan('secrets:wire local --tool=passwords --force')
        ->assertExitCode(0)
        ->expectsOutputToContain("Password Manager (Vaultwarden)'s DB password is now rotated by OpenBao every 168h");

    Saloon::assertSent(fn ($request) => $request instanceof DynamicRequest
        && str_contains($request->resolveEndpoint(), '/v1/database/static-roles/vaultwarden')
        && ($request->body()->get('username') ?? null) === 'vaultwarden'
        && ($request->body()->get('db_name') ?? null) === 'plex-postgres');

    Process::assertRan(fn ($process) => str_contains($process->command, 'apply -f'));
    Process::assertRan(fn ($process) => str_contains($process->command, 'externalsecret vault-secrets-db'));
    Process::assertRan(fn ($process) => str_contains($process->command, 'rollout restart deployment/vaultwarden'));
});

test('secrets:wire supports git, notes, sheets, and chat tools', function (): void {
    foreach (['git' => 'forgejo', 'notes' => 'notes-secrets', 'sheets' => 'sheet-secrets', 'chat' => 'chat-secrets'] as $toolSlug => $secretName) {
        expect(App\Enums\ClusterTool::from($toolSlug)->dbSecretRef())->not->toBeNull();
    }
});
