<?php

use App\Exceptions\MissingFlagException;
use App\Http\Integrations\Zitadel\Requests\DeleteProjectRequest;
use App\Http\Integrations\Zitadel\Requests\ListProjectsRequest;
use Illuminate\Support\Facades\Process;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

afterEach(function (): void {
    MockClient::destroyGlobal();
});

/**
 * Five projects mirroring the live 2026-08-25 incident state on
 * larakube-159.89.205.239: the RBAC redesign renamed git's project
 * (`forgejo` → `git-forgejo`) and silently orphaned the original.
 */
function ssoPruneProjects(): array
{
    return [
        ['id' => 'p-zit', 'name' => 'ZITADEL'],
        ['id' => 'p-shared', 'name' => 'LaraKube Shared Tools'],
        ['id' => 'p-live', 'name' => 'git-forgejo'],
        // Registered multi-instance project — unreferenced by any sso-app
        // secret YET, but its instance sits in the tools registry, so the
        // per-instance rbacProjectName() must protect it.
        ['id' => 'p-notes', 'name' => 'notes-outline-notes-luchtech-dev'],
        ['id' => 'p-stale', 'name' => 'forgejo'],
    ];
}

function ssoPruneRegistryJson(): string
{
    return (string) json_encode([
        ['tool' => 'notes', 'instance' => 'notes-luchtech-dev', 'installedAt' => '2026-08-01T00:00:00+00:00', 'host' => 'notes.luchtech.dev'],
    ]);
}

/**
 * Only ONE wire-tracked reference exists: git's. A non-sso-app secret
 * carrying a project-id key must NOT count as a reference.
 */
function ssoPruneSecretsJson(): string
{
    return (string) json_encode(['items' => [
        ['metadata' => ['name' => 'sso-app-git'], 'data' => [
            'project-id' => base64_encode('p-live'),
            'app-id' => base64_encode('app-x'),
        ]],
        ['metadata' => ['name' => 'unrelated-secret'], 'data' => [
            'project-id' => base64_encode('p-stale'),
        ]],
    ]]);
}

function ssoPruneFakes(): void
{
    Process::fake([
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*get secret sso-secrets*' => Process::result(output: base64_encode('zitadel-pat')),
        '*get secret larakube-tools-registry*' => Process::result(output: base64_encode(ssoPruneRegistryJson())),
        '*get secrets -n larakube-sso -o json*' => Process::result(output: ssoPruneSecretsJson()),
    ]);

    Saloon::fake([
        ListProjectsRequest::class => MockResponse::make(['result' => ssoPruneProjects()]),
        DeleteProjectRequest::class => MockResponse::make([]),
    ]);
}

test('sso:prune deletes exactly the orphaned project when forced non-interactively', function (): void {
    ssoPruneFakes();

    $this->artisan('sso:prune', ['--context' => 'ctx', '--project' => ['forgejo'], '--force' => true])
        ->assertExitCode(0);

    // Exactly one deletion — the stale project, not anything protected or
    // wire-referenced.
    Saloon::assertSent(
        fn ($request) => $request instanceof DeleteProjectRequest
            && $request->resolveEndpoint() === 'management/v1/projects/p-stale',
    );
    Saloon::assertNotSent(
        fn ($request) => $request instanceof DeleteProjectRequest
            && $request->resolveEndpoint() !== 'management/v1/projects/p-stale',
    );
});

test('sso:prune accepts a project id as well as its name for --project=', function (): void {
    ssoPruneFakes();

    $this->artisan('sso:prune', ['--context' => 'ctx', '--project' => ['p-stale'], '--force' => true])
        ->assertExitCode(0);

    Saloon::assertSent(
        fn ($request) => $request instanceof DeleteProjectRequest
            && $request->resolveEndpoint() === 'management/v1/projects/p-stale',
    );
});

test('sso:prune refuses --project= naming a wire-referenced or unknown project', function (): void {
    ssoPruneFakes();

    $this->artisan('sso:prune', ['--context' => 'ctx', '--project' => ['git-forgejo'], '--force' => true])
        ->assertExitCode(1)
        ->expectsOutputToContain('is not a prunable project');

    $this->artisan('sso:prune', ['--context' => 'ctx', '--project' => ['no-such-project'], '--force' => true])
        ->assertExitCode(1);

    Saloon::assertNotSent(DeleteProjectRequest::class);
});

test('sso:prune is a clean no-op when every project is protected or referenced', function (): void {
    Process::fake([
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*get secret sso-secrets*' => Process::result(output: base64_encode('zitadel-pat')),
        '*get secret larakube-tools-registry*' => Process::result(output: base64_encode(ssoPruneRegistryJson())),
        // forgejo now ALSO tracked — nothing is orphaned anymore (the
        // idempotent second-run case after a successful prune).
        '*get secrets -n larakube-sso -o json*' => Process::result(output: (string) json_encode(['items' => [
            ['metadata' => ['name' => 'sso-app-git'], 'data' => ['project-id' => base64_encode('p-live')]],
            ['metadata' => ['name' => 'sso-app-forgejo'], 'data' => ['project-id' => base64_encode('p-stale')]],
        ]])),
    ]);

    Saloon::fake([
        ListProjectsRequest::class => MockResponse::make(['result' => ssoPruneProjects()]),
        DeleteProjectRequest::class => MockResponse::make([]),
    ]);

    $this->artisan('sso:prune', ['--context' => 'ctx'])
        ->assertExitCode(0)
        ->expectsOutputToContain('No orphaned Zitadel projects');

    Saloon::assertNotSent(DeleteProjectRequest::class);
});

test('sso:prune refuses to mass-delete non-interactively without an explicit --project= list', function (): void {
    ssoPruneFakes();

    // Orphan exists, no --project= given: hard fail naming the flag, never
    // guess what to delete.
    $this->artisan('sso:prune', ['--context' => 'ctx']);
})->throws(MissingFlagException::class, 'Missing required --project');

test('sso:prune --force alone is still insufficient without --project=', function (): void {
    ssoPruneFakes();

    // --force narrows the prompt away, so the exact target list becomes
    // mandatory — force must never widen what a bare run deletes.
    $this->artisan('sso:prune', ['--context' => 'ctx', '--force' => true]);
})->throws(MissingFlagException::class, 'Missing required --project');

test('sso:prune refuses to run when the reference-set sweep itself fails', function (): void {
    Process::fake([
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*get secret sso-secrets*' => Process::result(output: base64_encode('zitadel-pat')),
        '*get secret larakube-tools-registry*' => Process::result(output: base64_encode(ssoPruneRegistryJson())),
        '*get secrets -n larakube-sso -o json*' => Process::result(output: '', exitCode: 1),
    ]);

    Saloon::fake([
        ListProjectsRequest::class => MockResponse::make(['result' => ssoPruneProjects()]),
        DeleteProjectRequest::class => MockResponse::make([]),
    ]);

    // An unreadable reference set is NOT an empty one — pruning blind could
    // delete a live project, so this must fail loudly before listing.
    $this->artisan('sso:prune', ['--context' => 'ctx', '--project' => ['forgejo'], '--force' => true])
        ->assertExitCode(1)
        ->expectsOutputToContain('refusing to prune');

    Saloon::assertNotSent(ListProjectsRequest::class);
});
