<?php

use App\Http\Integrations\Zitadel\Requests\CreateProjectRequest;
use App\Http\Integrations\Zitadel\Requests\CreateUserGrantRequest;
use App\Http\Integrations\Zitadel\Requests\CreateUserRequest;
use App\Http\Integrations\Zitadel\Requests\SearchProjectRolesRequest;
use App\Http\Integrations\Zitadel\Requests\SearchProjectsRequest;
use App\Http\Integrations\Zitadel\Requests\SearchUserGrantsRequest;
use App\Http\Integrations\Zitadel\Requests\SearchUsersRequest;
use App\Http\Integrations\Zitadel\Requests\UpdateUserGrantRequest;
use Illuminate\Support\Facades\Process;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

afterEach(function (): void {
    MockClient::destroyGlobal();
});

test('sso:grant is registered', function (): void {
    $this->artisan('list --no-interaction')
        ->assertExitCode(0)
        ->expectsOutputToContain('sso:grant');
});

test('sso:grant rejects a tool with no role-gated access', function (): void {
    Process::fake([
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*get secret sso-secrets*' => Process::result(output: base64_encode('zitadel-pat')),
    ]);

    Saloon::fake([
        SearchProjectsRequest::class => MockResponse::make(['result' => [['id' => 'proj-1']]]),
    ]);

    // mail has neither rbacRoles() nor ssoAdminRoles() — genuinely open,
    // unlike passwords (gated 2026-08-20 after the Outline incident).
    $this->artisan('sso:grant', ['--tool' => 'mail', '--role' => 'whatever', '--email' => 'james@luchtech.dev', '--no-interaction' => true])
        ->assertExitCode(1)
        ->expectsOutputToContain('has no role-gated access to grant');
});

test('sso:grant auto-resolves --domain= when a multi-instance tool has exactly one registered instance', function (): void {
    // rbacProjectName() is per (tool, instance) now — Notes' only real
    // instance is already named ('notes-luchtech-dev'), not the default, so
    // omitting --domain= must not silently target a DIFFERENT (empty)
    // project. With exactly one registered instance there's nothing
    // genuinely ambiguous, so this resolves it automatically instead of
    // forcing the operator to already know and type the domain.
    Process::fake([
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*get secret sso-secrets*' => Process::result(output: base64_encode('zitadel-pat')),
        '*get secret larakube-tools-registry*' => Process::result(
            output: base64_encode((string) json_encode([
                ['tool' => 'notes', 'instance' => 'notes-luchtech-dev', 'installedAt' => '2026-08-01T00:00:00+00:00', 'host' => 'notes.luchtech.dev'],
            ])),
        ),
    ]);

    Saloon::fake([
        SearchUsersRequest::class => MockResponse::make(['result' => [['userId' => 'uid-1']]]),
        SearchProjectsRequest::class => MockResponse::make(['result' => []]),
        CreateProjectRequest::class => MockResponse::make(['id' => 'proj-notes-instance']),
        SearchUserGrantsRequest::class => MockResponse::make(['result' => [['id' => 'grant-1', 'roleKeys' => ['outline-user']]]]),
        CreateUserGrantRequest::class => MockResponse::make([]),
    ]);

    $this->artisan('sso:grant', ['--tool' => 'notes', '--role' => 'outline-user', '--email' => 'james@luchtech.dev', '--no-interaction' => true])
        ->assertExitCode(0);

    Saloon::assertSent(fn ($request) => $request instanceof CreateProjectRequest
        && $request->body()->get('name') === 'notes-outline-notes-luchtech-dev');
});

test('sso:grant refuses to guess when a multi-instance tool has more than one registered instance and no --domain', function (): void {
    Process::fake([
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*get secret sso-secrets*' => Process::result(output: base64_encode('zitadel-pat')),
        '*get secret larakube-tools-registry*' => Process::result(
            output: base64_encode((string) json_encode([
                ['tool' => 'notes', 'instance' => 'notes-luchtech-dev', 'installedAt' => '2026-08-01T00:00:00+00:00', 'host' => 'notes.luchtech.dev'],
                ['tool' => 'notes', 'instance' => 'blog-example-com', 'installedAt' => '2026-08-02T00:00:00+00:00', 'host' => 'blog.example.com'],
            ])),
        ),
    ]);

    $this->artisan('sso:grant', ['--tool' => 'notes', '--role' => 'outline-user', '--email' => 'james@luchtech.dev', '--no-interaction' => true])
        ->assertExitCode(1)
        ->expectsOutputToContain('has multiple instances — pass --domain=');
});

test('sso:grant --domain= resolves the exact named instance\'s project', function (): void {
    Process::fake([
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*get secret sso-secrets*' => Process::result(output: base64_encode('zitadel-pat')),
        '*get secret larakube-tools-registry*' => Process::result(
            output: base64_encode((string) json_encode([
                ['tool' => 'notes', 'instance' => 'notes-luchtech-dev', 'installedAt' => '2026-08-01T00:00:00+00:00', 'host' => 'notes.luchtech.dev'],
                ['tool' => 'notes', 'instance' => 'blog-example-com', 'installedAt' => '2026-08-02T00:00:00+00:00', 'host' => 'blog.example.com'],
            ])),
        ),
    ]);

    Saloon::fake([
        SearchUsersRequest::class => MockResponse::make(['result' => [['userId' => 'uid-1']]]),
        SearchProjectsRequest::class => MockResponse::make(['result' => []]),
        CreateProjectRequest::class => MockResponse::make(['id' => 'proj-blog-instance']),
        SearchUserGrantsRequest::class => MockResponse::make(['result' => [['id' => 'grant-1', 'roleKeys' => ['outline-user']]]]),
        CreateUserGrantRequest::class => MockResponse::make([]),
    ]);

    $this->artisan('sso:grant', ['--tool' => 'notes', '--domain' => 'blog.example.com', '--role' => 'outline-user', '--email' => 'james@luchtech.dev', '--no-interaction' => true])
        ->assertExitCode(0);

    Saloon::assertSent(fn ($request) => $request instanceof CreateProjectRequest
        && $request->body()->get('name') === 'notes-outline-blog-example-com');
});

test('sso:grant rejects an unknown tool', function (): void {
    Process::fake([
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*get secret sso-secrets*' => Process::result(output: base64_encode('zitadel-pat')),
    ]);

    Saloon::fake([
        SearchProjectsRequest::class => MockResponse::make(['result' => [['id' => 'proj-1']]]),
    ]);

    $this->artisan('sso:grant', ['--tool' => 'not-a-real-tool', '--no-interaction' => true])
        ->assertExitCode(1)
        ->expectsOutputToContain("Unknown tool 'not-a-real-tool'");
});

test('sso:grant\'s picker offers every role-bearing tool — Drive included — without a live-role probe', function (): void {
    Process::fake([
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*get secret sso-secrets*' => Process::result(output: base64_encode('zitadel-pat')),
    ]);

    $grantSearchCallCount = 0;
    Saloon::fake([
        SearchUsersRequest::class => MockResponse::make(['result' => [['userId' => 'uid-1']]]),
        SearchProjectsRequest::class => MockResponse::make(['result' => [['id' => 'drive-proj-1']]]),
        SearchUserGrantsRequest::class => function () use (&$grantSearchCallCount) {
            $grantSearchCallCount++;

            return $grantSearchCallCount === 1
                ? MockResponse::make(['result' => []])
                : MockResponse::make(['result' => [['id' => 'grant-1', 'roleKeys' => ['ocisAdmin']]]]);
        },
        CreateUserGrantRequest::class => MockResponse::make([]),
    ]);

    // No --tool: the picker resolves Drive (the first role-bearing tool in
    // case order) purely from the enum's role schema — the grant succeeds even
    // though Zitadel here knows nothing about any roles yet.
    $this->artisan('sso:grant', ['--role' => 'ocisAdmin', '--email' => 'admin@luchtech.dev', '--no-interaction' => true])
        // Order follows ClusterTool::cases() declaration order, filtered to
        // role-bearing tools — link/notes/passwords/record/sheets/sign
        // joined the list 2026-08-20, git and drive's rbacRoles() joined
        // the same day, design's joined 2026-08-21 (see ClusterTool::rbacRoles()).
        ->expectsChoice('Which tool?', 'drive', [
            'drive' => 'Cloud Storage & Sync (oCIS)',
            'git' => 'Git Forge & CI/CD (Forgejo)',
            'link' => 'Link Management (Kutt)',
            'monitor' => 'Monitoring Stack (Grafana + Loki + Prometheus)',
            'notes' => 'Team Wiki & Knowledge Base (Outline)',
            'passwords' => 'Password Manager (Vaultwarden)',
            'record' => 'Screen Recording & Sharing (Sendrec)',
            'secrets' => 'Secrets Manager (OpenBao)',
            'sheets' => 'Spreadsheet Database (Teable)',
            'sign' => 'Document Signing (Documenso)',
            'dashboard' => 'Kubernetes Control Plane (Headlamp)',
            'design' => 'Design & Prototyping (Penpot)',
            'resume' => 'Resume Builder (Reactive Resume)',
        ])
        ->assertExitCode(0)
        ->expectsOutputToContain("Granted 'ocisAdmin' to admin@luchtech.dev");

    // The grant targets Drive's own project (found by name, requiresRbacGating()
    // resolves it — no sso-app-drive secret read) — and the picker never
    // consulted the live role lists at all.
    Saloon::assertSent(fn ($request) => $request instanceof CreateUserGrantRequest
        && $request->body()->get('projectId') === 'drive-proj-1'
        && $request->body()->get('roleKeys') === ['ocisAdmin']);
    Saloon::assertNotSent(SearchProjectRolesRequest::class);
});

test('sso:grant rejects a role the tool does not define', function (): void {
    Process::fake([
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*get secret sso-secrets*' => Process::result(output: base64_encode('zitadel-pat')),
    ]);

    Saloon::fake([
        SearchProjectsRequest::class => MockResponse::make(['result' => [['id' => 'proj-1']]]),
    ]);

    $this->artisan('sso:grant', ['--tool' => 'secrets', '--role' => 'openbao-superuser', '--email' => 'james@luchtech.dev', '--no-interaction' => true])
        ->assertExitCode(1)
        ->expectsOutputToContain("isn't a role");
});

test('sso:grant errors when Zitadel is not installed', function (): void {
    Process::fake([
        '*get deployment sso-zitadel*' => Process::result(output: ''),
    ]);

    $this->artisan('sso:grant', ['--tool' => 'secrets', '--role' => 'openbao-admin', '--email' => 'james@luchtech.dev', '--no-interaction' => true])
        ->assertExitCode(1)
        ->expectsOutputToContain('Zitadel is not installed');
});

test('sso:grant errors when Zitadel user cannot be resolved or created', function (): void {
    Process::fake([
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*get secret sso-secrets*' => Process::result(output: base64_encode('zitadel-pat')),
    ]);

    Saloon::fake([
        SearchProjectsRequest::class => MockResponse::make(['result' => [['id' => 'proj-1']]]),
        SearchUsersRequest::class => MockResponse::make(['result' => []]),
        CreateUserRequest::class => MockResponse::make([], 500),
    ]);

    $this->artisan('sso:grant', ['--tool' => 'secrets', '--role' => 'openbao-admin', '--email' => 'ghost@luchtech.dev', '--no-interaction' => true])
        ->assertExitCode(1)
        ->expectsOutputToContain('Failed to resolve or create Zitadel user');
});

test('sso:grant creates a fresh UserGrant when the user holds none on the RBAC project yet', function (): void {
    Process::fake([
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*get secret sso-secrets*' => Process::result(output: base64_encode('zitadel-pat')),
    ]);

    $grantSearchCallCount = 0;
    Saloon::fake([
        SearchUsersRequest::class => MockResponse::make(['result' => [['userId' => 'uid-1']]]),
        SearchProjectsRequest::class => MockResponse::make(['result' => [['id' => 'proj-1']]]),
        SearchUserGrantsRequest::class => function () use (&$grantSearchCallCount) {
            $grantSearchCallCount++;

            // no existing grant (call 1) → create path, then the
            // post-write readback (call 2).
            return $grantSearchCallCount === 1
                ? MockResponse::make(['result' => []])
                : MockResponse::make(['result' => [['id' => 'grant-1', 'roleKeys' => ['openbao-admin']]]]);
        },
        CreateUserGrantRequest::class => MockResponse::make([]),
    ]);

    $this->artisan('sso:grant', ['--tool' => 'secrets', '--role' => 'openbao-admin', '--email' => 'james@luchtech.dev', '--no-interaction' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain("Granted 'openbao-admin' to james@luchtech.dev")
        ->expectsOutputToContain('openbao-admin');

    Saloon::assertSent(fn ($request) => $request instanceof CreateUserGrantRequest
        && $request->body()->get('roleKeys') === ['openbao-admin']);
});

test('sso:grant merges a new role into an existing UserGrant instead of clobbering it', function (): void {
    Process::fake([
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*get secret sso-secrets*' => Process::result(output: base64_encode('zitadel-pat')),
    ]);

    $grantSearchCallCount = 0;
    Saloon::fake([
        SearchUsersRequest::class => MockResponse::make(['result' => [['userId' => 'uid-1']]]),
        SearchProjectsRequest::class => MockResponse::make(['result' => [['id' => 'proj-1']]]),
        SearchUserGrantsRequest::class => function () use (&$grantSearchCallCount) {
            $grantSearchCallCount++;

            return $grantSearchCallCount === 1
                ? MockResponse::make(['result' => [['id' => 'grant-1', 'roleKeys' => ['openbao-admin']]]])
                : MockResponse::make(['result' => [['id' => 'grant-1', 'roleKeys' => ['openbao-admin', 'grafana-user']]]]);
        },
        UpdateUserGrantRequest::class => MockResponse::make([]),
    ]);

    $this->artisan('sso:grant', ['--tool' => 'monitor', '--role' => 'grafana-user', '--email' => 'james@luchtech.dev', '--no-interaction' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain("Granted 'grafana-user' to james@luchtech.dev");

    Saloon::assertSent(fn ($request) => $request instanceof UpdateUserGrantRequest
        && $request->body()->get('roleKeys') === ['openbao-admin', 'grafana-user']);
});

test('sso:grant grants Drive\'s ocisAdmin on Drive\'s own project, found by name', function (): void {
    // Drive moved to rbacRoles() alongside ssoAdminRoles() 2026-08-20 (at the
    // user's explicit request) — requiresRbacGating() is now checked FIRST
    // in resolveSsoProject(), so every Drive grant resolves via
    // zitadelEnsureProject(rbacProjectName()) (search-or-create by name),
    // never the sso-app-drive secret's cached project-id (that path is only
    // reachable for a tool with ssoAdminRoles() and no rbacRoles() at all —
    // Drive no longer qualifies).
    Process::fake([
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*get secret sso-secrets*' => Process::result(output: base64_encode('zitadel-pat')),
    ]);

    $grantSearchCallCount = 0;
    Saloon::fake([
        SearchUsersRequest::class => MockResponse::make(['result' => [['userId' => 'uid-1']]]),
        SearchProjectsRequest::class => MockResponse::make(['result' => [['id' => 'drive-proj-1']]]),
        SearchUserGrantsRequest::class => function () use (&$grantSearchCallCount) {
            $grantSearchCallCount++;

            return $grantSearchCallCount === 1
                ? MockResponse::make(['result' => []])
                : MockResponse::make(['result' => [['id' => 'grant-1', 'roleKeys' => ['ocisAdmin']]]]);
        },
        CreateUserGrantRequest::class => MockResponse::make([]),
    ]);

    $this->artisan('sso:grant', ['--tool' => 'drive', '--role' => 'ocisAdmin', '--email' => 'admin@luchtech.dev', '--no-interaction' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain("Granted 'ocisAdmin' to admin@luchtech.dev")
        ->expectsOutputToContain('drive-ocis');

    Saloon::assertSent(fn ($request) => $request instanceof SearchProjectsRequest
        && $request->body()->get('queries')[0]['nameQuery']['name'] === 'drive-ocis');
    Saloon::assertSent(fn ($request) => $request instanceof CreateUserGrantRequest
        && $request->body()->get('projectId') === 'drive-proj-1'
        && $request->body()->get('roleKeys') === ['ocisAdmin']);
});

test('sso:grant for Drive creates its own project when none exists yet', function (): void {
    Process::fake([
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*get secret sso-secrets*' => Process::result(output: base64_encode('zitadel-pat')),
    ]);

    $grantSearchCallCount = 0;
    Saloon::fake([
        SearchUsersRequest::class => MockResponse::make(['result' => [['userId' => 'uid-1']]]),
        SearchProjectsRequest::class => MockResponse::make(['result' => []]),
        CreateProjectRequest::class => MockResponse::make(['id' => 'drive-proj-1']),
        SearchUserGrantsRequest::class => function () use (&$grantSearchCallCount) {
            $grantSearchCallCount++;

            return $grantSearchCallCount === 1
                ? MockResponse::make(['result' => []])
                : MockResponse::make(['result' => [['id' => 'grant-1', 'roleKeys' => ['ocisAdmin']]]]);
        },
        CreateUserGrantRequest::class => MockResponse::make([]),
    ]);

    $this->artisan('sso:grant', ['--tool' => 'drive', '--role' => 'ocisAdmin', '--email' => 'admin@luchtech.dev', '--no-interaction' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain("Granted 'ocisAdmin' to admin@luchtech.dev");

    // The fallback created Drive's own project by name before granting —
    // not the shared LaraKube Shared Tools project.
    Saloon::assertSent(fn ($request) => $request instanceof CreateProjectRequest
        && $request->body()->get('name') === 'drive-ocis');
});
