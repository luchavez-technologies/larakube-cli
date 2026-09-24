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
 * Five projects mirroring a real incident: renaming git's project orphaned
 * the original, which nothing referenced and nothing cleaned up. `forgejo` is
 * what the tool's project is called now; `git-forgejo` is the leftover.
 */
function ssoPruneProjects(): array
{
    return [
        ['id' => 'p-zit', 'name' => 'ZITADEL'],
        ['id' => 'p-shared', 'name' => 'LaraKube Shared Tools'],
        ['id' => 'p-live', 'name' => 'forgejo'],
        // Registered multi-instance project — unreferenced by any sso-app
        // secret YET, but its instance sits in the tools registry, so the
        // per-instance rbacProjectName() must protect it.
        ['id' => 'p-notes', 'name' => 'outline-notes-luchtech-dev'],
        ['id' => 'p-stale', 'name' => 'git-forgejo'],
    ];
}

function ssoPruneRegistryJson(): string
{
    return (string) json_encode([
        ['tool' => 'notes', 'instance' => 'notes-luchtech-dev', 'installedAt' => '2026-08-01T00:00:00+00:00', 'host' => 'notes.luchtech.dev'],
    ]);
}

/**
 * Only ONE wire-tracked reference exists: git's. A Secret carrying a
 * project-id but no app-id is not an app record and must NOT count.
 */
function ssoPruneSecretsJson(): string
{
    return (string) json_encode(['items' => [
        ['metadata' => ['name' => 'forgejo-sso-git-example-com'], 'data' => [
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

    $this->artisan('sso:prune', ['--context' => 'ctx', '--project' => ['git-forgejo'], '--force' => true])
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

    // The live project, the one git's wiring points at.
    $this->artisan('sso:prune', ['--context' => 'ctx', '--project' => ['forgejo'], '--force' => true])
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
            ['metadata' => ['name' => 'forgejo-sso-git-example-com'], 'data' => [
                'project-id' => base64_encode('p-live'),
                'app-id' => base64_encode('app-live'),
            ]],
            ['metadata' => ['name' => 'forgejo-sso-forge-example-com'], 'data' => [
                'project-id' => base64_encode('p-stale'),
                'app-id' => base64_encode('app-stale'),
            ]],
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
    $this->artisan('sso:prune', ['--context' => 'ctx', '--project' => ['git-forgejo'], '--force' => true])
        ->assertExitCode(1)
        ->expectsOutputToContain('refusing to prune');

    Saloon::assertNotSent(ListProjectsRequest::class);
});

test('--project accepts a Zitadel id, not just a name', function (): void {
    // Zitadel ids are numeric strings, so they become INTEGER array keys — and
    // Collection::flatMap collapses with array_merge(), which renumbers integer
    // keys. The id entry was replaced by 0, so every --project=<id> was refused
    // as "not a prunable project" even when it was one. Only a numeric id
    // reproduces it; the other fixtures here use p-* ids that stay strings.
    Process::fake([
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*get secret sso-secrets*' => Process::result(output: base64_encode('zitadel-pat')),
        '*get secret larakube-tools-registry*' => Process::result(output: base64_encode(ssoPruneRegistryJson())),
        '*get secrets -n larakube-sso -o json*' => Process::result(output: ssoPruneSecretsJson()),
    ]);

    Saloon::fake([
        ListProjectsRequest::class => MockResponse::make(['result' => [
            ['id' => '384565131515920554', 'name' => 'ZITADEL'],
            ['id' => '387711458479177871', 'name' => 'git-forgejo'],
        ]]),
        DeleteProjectRequest::class => MockResponse::make([]),
    ]);

    $this->artisan('sso:prune', ['--context' => 'ctx', '--project' => ['387711458479177871'], '--force' => true])
        ->assertExitCode(0);

    Saloon::assertSent(fn ($request) => $request instanceof DeleteProjectRequest
        && $request->resolveEndpoint() === 'management/v1/projects/387711458479177871');
});

test('a project named after a tool nobody installed is prunable', function (): void {
    // Protection keys on the tools registry, not on every name the CLI could
    // emit. Protecting shippedCases() wholesale meant a project left behind by
    // an uninstalled tool could never be pruned — the one case prune exists for.
    Process::fake([
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*get secret sso-secrets*' => Process::result(output: base64_encode('zitadel-pat')),
        // Only notes is registered; resume is not installed at all.
        '*get secret larakube-tools-registry*' => Process::result(output: base64_encode(ssoPruneRegistryJson())),
        '*get secrets -n larakube-sso -o json*' => Process::result(output: ssoPruneSecretsJson()),
    ]);

    Saloon::fake([
        ListProjectsRequest::class => MockResponse::make(['result' => [
            ['id' => '111', 'name' => 'resume-reactive'],
            ['id' => '222', 'name' => 'outline-notes-luchtech-dev'],
        ]]),
        DeleteProjectRequest::class => MockResponse::make([]),
    ]);

    $this->artisan('sso:prune', ['--context' => 'ctx', '--project' => ['resume-reactive'], '--force' => true])
        ->assertExitCode(0);

    Saloon::assertSent(fn ($request) => $request instanceof DeleteProjectRequest
        && $request->resolveEndpoint() === 'management/v1/projects/111');
});

test('a registered tool keeps its project even with no sso-app secret yet', function (): void {
    // The other half: notes IS in the registry, so both its unnamed and
    // per-instance project names stay protected whether or not it is wired.
    ssoPruneFakes();

    $this->artisan('sso:prune', ['--context' => 'ctx', '--project' => ['outline-notes-luchtech-dev'], '--force' => true])
        ->assertExitCode(1)
        ->expectsOutputToContain('not a prunable project');
});
