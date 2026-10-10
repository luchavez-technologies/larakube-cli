<?php

use App\Http\Integrations\OpenBao\Requests\DynamicNoBodyRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;
use Spatie\TemporaryDirectory\TemporaryDirectory;

/**
 * plex:show always prints human lines too, even under --json — only the
 * LAST line is the JSON result. Uses Artisan::call() (not $this->artisan(),
 * whose PendingCommand doesn't reliably feed Artisan::output()) — same
 * precedent as CloudProvidersCommandTest's cloudProvidersRunJson().
 */
function plexShowJsonReport(array $arguments = []): array
{
    expect(Artisan::call('plex:show', array_merge($arguments, ['--json' => true])))->toBe(0);

    $lines = preg_split('/\R/', trim(Artisan::output())) ?: [];

    return json_decode((string) end($lines), true);
}

afterEach(function (): void {
    MockClient::destroyGlobal();
});

/**
 * A Commons spec with Postgres enabled and one Application Tenant allocated.
 * The '*' catch-all MUST stay last in the resulting array — Process::fake()
 * matches in array order, and array_merge() appends new override keys AFTER
 * existing default keys, so putting '*' inside the defaults would let it
 * shadow any override (e.g. a specific 'openbao-secrets-secrets-example-com' pattern) before
 * that override is even reached.
 */
function plexShowFakes(array $overrides = []): array
{
    return array_merge([
        '*get configmap plex-commons*' => Process::result(
            output: (string) json_encode(['version' => 1, 'services' => ['postgres' => ['enabled' => true]]]),
        ),
        '*get configmap plex-registry*' => Process::result(
            output: (string) json_encode(['tenants' => ['demo_production' => ['db' => 'demo_production', 'db_service' => 'postgres']]]),
        ),
        '*get deployments -A -o json*' => Process::result(output: (string) json_encode(['items' => []])),
        '*cluster-info*' => Process::result(output: 'reachable'),
    ], $overrides, [
        '*' => Process::result(output: ''),
    ]);
}

test('plex:show surfaces an OpenBao-wired tenant\'s rotation schedule, never the password', function (): void {
    openBaoRegistered();
    // Regression guard: staticRoleRotationInfo() reads password+username off
    // the same API response too — this proves plex:show's output never
    // contains either, only the schedule.
    Process::fake(plexShowFakes([
        '*get secret openbao-secrets-secrets-example-com*' => base64_encode('s.test-token'),
        '*port-forward*' => Process::result(output: ''),
    ]));

    Saloon::fake([
        DynamicNoBodyRequest::class => openBaoFake([
            '*/database/static-roles/tenant-demo_production' => ['data' => ['db_name' => 'plex-postgres']],
            '*/database/static-creds/tenant-demo_production' => ['data' => [
                'password' => 'super-secret-should-never-print',
                'username' => 'demo_production',
                'rotation_period' => 604800,
                'ttl' => 604000,
                'last_vault_rotation' => '2026-07-31T23:16:38Z',
            ]],
        ]),
    ]);

    $this->artisan('plex:show local --context=test-ctx')
        ->assertExitCode(0)
        // ONE check for the whole line: expectsOutputToContain's Mockery
        // matching lets only the first-registered expectation claim a given
        // line, so a second, separate check against the SAME line (e.g.
        // "every") would never get a chance to match — see PlexJoinDbSecretTest's
        // "OpenBao:  https://" note.
        ->expectsOutputToContain('OpenBao-managed')
        ->doesntExpectOutputToContain('super-secret-should-never-print');
});

test('plex:show marks a tenant with no OpenBao static role as manual (.env)', function (): void {
    openBaoRegistered();
    Process::fake(plexShowFakes([
        '*get secret openbao-secrets-secrets-example-com*' => base64_encode('s.test-token'),
        '*port-forward*' => Process::result(output: ''),
    ]));

    Saloon::fake([
        DynamicNoBodyRequest::class => openBaoFake([
            '*/database/static-roles/tenant-demo_production' => MockResponse::make(['errors' => ['no role found']], 404),
        ]),
    ]);

    $this->artisan('plex:show local --context=test-ctx')
        ->assertExitCode(0)
        ->expectsOutputToContain('manual (.env) — run');
});

test('plex:show explains a missing DB password instead of leaving a silent gap, for an OpenBao-managed self tenant', function (): void {
    openBaoRegistered();
    // Regression guard for the confusion a user hit live 2026-08-02: a
    // password WAS showing here, but it was stale — writeTenantConfig now
    // strips it from .env once OpenBao owns it, so this asserts plex:show
    // explains the omission rather than either leaving a gap or (the old
    // bug) printing a value that no longer matches the real one.
    $temporaryDirectory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $dir = $temporaryDirectory->path();
    $cwd = getcwd();

    try {
        file_put_contents($dir.'/.larakube.json', json_encode([
            'name' => 'demo',
            'environments' => ['local' => ['plex' => ['postgres']]],
        ]));
        file_put_contents($dir.'/.env', "DB_HOST=postgres.larakube-plex.svc.cluster.local\nDB_DATABASE=demo_local\nDB_USERNAME=demo_local\n");

        chdir($dir);

        Process::fake(plexShowFakes([
            '*get configmap plex-registry*' => Process::result(
                output: (string) json_encode(['tenants' => ['demo_local' => ['db' => 'demo_local', 'db_service' => 'postgres']]]),
            ),
            '*get secret openbao-secrets-secrets-example-com*' => base64_encode('s.test-token'),
            '*port-forward*' => Process::result(output: ''),
        ]));

        Saloon::fake([
            DynamicNoBodyRequest::class => openBaoFake([
                '*/database/static-roles/tenant-demo_local' => ['data' => ['db_name' => 'plex-postgres']],
                '*/database/static-creds/tenant-demo_local' => ['data' => [
                    'password' => 'live-password-should-never-print',
                    'rotation_period' => 604800,
                    'ttl' => 604000,
                    'last_vault_rotation' => '2026-08-02T00:00:00Z',
                ]],
            ]),
        ]);

        $this->artisan('plex:show --context=test-ctx')
            ->assertExitCode(0)
            ->expectsOutputToContain('database Password: OpenBao-managed')
            ->doesntExpectOutputToContain('live-password-should-never-print');
    } finally {
        chdir($cwd);
        $temporaryDirectory->delete();
    }
});

test('plex:show never touches OpenBao when it is not installed', function (): void {
    openBaoRegistered();
    // Perf/correctness: no bootstrap secret means no port-forward should be
    // attempted at all for the rotation line — the readiness check happens
    // once, up front, not per tenant.
    Process::fake(plexShowFakes([
        '*get secret openbao-secrets-secrets-example-com*' => Process::result(output: '', exitCode: 1),
    ]));

    $this->artisan('plex:show local --context=test-ctx')
        ->assertExitCode(0)
        ->expectsOutputToContain('manual (.env) — OpenBao not installed');

    Process::assertNotRan(fn ($process) => str_contains($process->command, 'port-forward'));
});

// --- --json (Desktop's ClusterStatus::plex() contract) ----------------------

test('plex:show --json reports initialized: false when Commons has not been set up', function (): void {
    Process::fake(plexShowFakes([
        '*get configmap plex-commons*' => Process::result(output: ''),
    ]));

    expect(plexShowJsonReport(['environment' => 'local', '--context' => 'test-ctx']))->toBe([
        'initialized' => false,
        'context' => 'test-ctx',
        'services' => [],
        'tenants' => ['tool' => [], 'project' => [], 'custom' => []],
    ]);
});

test('plex:show --json groups tenants into tool, project, and custom buckets', function (): void {
    Process::fake(plexShowFakes([
        '*get configmap plex-registry*' => Process::result(output: (string) json_encode(['tenants' => [
            'forgejo' => ['db' => 'forgejo', 'db_service' => 'postgres'],
            'demo_production' => ['db' => 'demo_production', 'db_service' => 'postgres'],
            'my-side-project' => ['redis_index' => 3, 'kind' => 'custom'],
        ]])),
        '*get secret openbao-secrets-secrets-example-com*' => Process::result(output: '', exitCode: 1),
    ]));

    $report = plexShowJsonReport(['environment' => 'local', '--context' => 'test-ctx']);

    expect($report['initialized'])->toBeTrue()
        ->and($report['services']['postgres']['enabled'])->toBeTrue()
        ->and(collect($report['tenants']['tool'])->pluck('name')->all())->toBe(['forgejo'])
        ->and(collect($report['tenants']['project'])->pluck('name')->all())->toBe(['demo_production'])
        ->and(collect($report['tenants']['custom'])->pluck('name')->all())->toBe(['my-side-project'])
        ->and(collect($report['tenants']['custom'])->first()['redisIndex'])->toBe(3)
        ->and(collect($report['tenants']['custom'])->first()['rotation'])->toBeNull() // no database — nothing to rotate
        ->and(collect($report['tenants']['project'])->first()['rotation'])->toBe(['state' => 'manual', 'nextRotation' => null])
        ->and(collect($report['tenants']['tool'])->first()['clusterTool'])->toMatchArray([
            'tool' => 'git',
            // The short product name/tagline (tool:list --json's own pair), not
            // getLabel()'s verbose "Product (category tagline)" string — and
            // resolved from the legacy "git" category to the real "Forgejo" product.
            'name' => 'Forgejo',
            'tagline' => 'Self-Hosted Git & CI/CD',
        ])
        ->and(collect($report['tenants']['project'])->first()['clusterTool'])->toBeNull();
});

test('plex:show --json reports the service catalog grouped by category, with only the live engine marked active', function (): void {
    Process::fake(plexShowFakes());

    $report = plexShowJsonReport(['environment' => 'local', '--context' => 'test-ctx']);
    $catalog = $report['serviceCatalog'];

    expect($catalog['database']['active'])->toBe('postgres')
        ->and($catalog['database']['options'])->toEqual([
            'mysql' => ['label' => 'MySQL', 'enabled' => false, 'ready' => true],
            'mariadb' => ['label' => 'MariaDB', 'enabled' => false, 'ready' => true],
            'postgres' => ['label' => 'PostgreSQL', 'enabled' => true, 'ready' => true],
            'mongodb' => ['label' => 'MongoDB', 'enabled' => false, 'ready' => false],
        ])
        ->and($catalog['cache']['active'])->toBeNull()
        ->and($catalog['cache']['options'])->toEqual([
            'redis' => ['label' => 'Redis', 'enabled' => false, 'ready' => true],
            'memcached' => ['label' => 'Memcached', 'enabled' => false, 'ready' => false],
        ])
        ->and($catalog['storage']['active'])->toBeNull()
        ->and($catalog['storage']['options'])->toEqual([
            'seaweedfs' => ['label' => 'SeaweedFS', 'enabled' => false, 'ready' => true],
            'minio' => ['label' => 'MinIO', 'enabled' => false, 'ready' => true],
            'garage' => ['label' => 'Garage', 'enabled' => false, 'ready' => true],
        ])
        ->and($catalog['search']['active'])->toBeNull()
        ->and($catalog['search']['options'])->toEqual([
            'meilisearch' => ['label' => 'Meilisearch', 'enabled' => false, 'ready' => true],
            'typesense' => ['label' => 'Typesense', 'enabled' => false, 'ready' => false],
        ])
        // The verbose qualifier in a few enums' own getLabel() ("MinIO (Legacy / AGPL)")
        // is stripped for the compact pill UI — proven here, not just assumed.
        ->and($catalog['render']['options'])->toHaveKey('headless-shell');
});

test('plex:show --json never leaks credentials, even with a matching project checked out locally', function (): void {
    $temporaryDirectory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $dir = $temporaryDirectory->path();
    $cwd = getcwd();

    try {
        file_put_contents($dir.'/.larakube.json', json_encode([
            'name' => 'demo',
            'environments' => ['local' => ['plex' => ['postgres']]],
        ]));
        file_put_contents($dir.'/.env', "DB_HOST=postgres.larakube-plex.svc.cluster.local\nDB_DATABASE=demo_local\nDB_USERNAME=demo_local\nDB_PASSWORD=super-secret-should-never-print\n");

        chdir($dir);

        Process::fake(plexShowFakes([
            '*get configmap plex-registry*' => Process::result(
                output: (string) json_encode(['tenants' => ['demo_local' => ['db' => 'demo_local', 'db_service' => 'postgres']]]),
            ),
            '*get secret openbao-secrets-secrets-example-com*' => Process::result(output: '', exitCode: 1),
        ]));

        // No --context= here on purpose — this is the one path where a
        // project config IS present, exactly the case showSelfCredentials()
        // guards against leaking into --json. 'local' is explicit so
        // resolvePlexEnvironment() doesn't prompt.
        plexShowJsonReport(['environment' => 'local']);

        expect(trim(Artisan::output()))->not->toContain('super-secret-should-never-print');
    } finally {
        chdir($cwd);
        $temporaryDirectory->delete();
    }
});
